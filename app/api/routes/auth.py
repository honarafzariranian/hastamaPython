# app/api/routes/auth.py — Security-Hardened Authentication Routes

import os
import secrets

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse, StreamingResponse
import logging
import time
import threading
from collections import defaultdict

from core.password_utils import (
    fetch_user_for_login, verify_password, hash_password,
    validate_password_policy, validate_username_input,
    validate_request_id, validate_recovery_code,
    MAX_USERNAME_LENGTH, MAX_PASSWORD_LENGTH,
)

from app.services.captcha import (
    generate_captcha_image, store_captcha_in_session,
    validate_captcha, captcha_remaining_seconds,
)

from app.core.net import client_ip as _trusted_client_ip
from app.core.net import user_agent as _safe_user_agent
from app.core import sessions as session_registry

router = APIRouter()
logger = logging.getLogger("hastama.auth")

# Master admin usernames — loaded from env var (comma-separated)
_master_admin_raw = os.environ.get("MASTER_ADMIN_USERNAMES", "ali")
MASTER_ADMIN_USERNAMES = {u.strip().lower() for u in _master_admin_raw.split(",") if u.strip()}

# ── Rate Limiting ────────────────────────────────────────────

class RateLimiter:
    """Simple in-memory rate limiter per IP and per username."""

    def __init__(self):
        self._ip_requests = defaultdict(list)      # ip -> [timestamp, ...]
        self._username_requests = defaultdict(list)  # username -> [timestamp, ...]
        self._failures = defaultdict(list)         # ip/username -> [failed login timestamps]
        self._lock = threading.Lock()

    def _clean_old(self, store, key, window_seconds):
        now = time.time()
        cutoff = now - window_seconds
        with self._lock:
            if key in store:
                store[key] = [t for t in store[key] if t > cutoff]
                if not store[key]:
                    del store[key]

    def check_ip(self, ip: str, max_requests: int = 10, window: int = 600) -> bool:
        """Check if IP is within rate limit. Returns True if allowed."""
        self._clean_old(self._ip_requests, ip, window)
        with self._lock:
            if len(self._ip_requests[ip]) >= max_requests:
                return False
            self._ip_requests[ip].append(time.time())
            return True

    def check_username(self, username: str, max_requests: int = 3, window: int = 3600) -> bool:
        """Check if username recovery requests are within rate limit."""
        self._clean_old(self._username_requests, username, window)
        with self._lock:
            if len(self._username_requests[username]) >= max_requests:
                return False
            self._username_requests[username].append(time.time())
            return True

    # ── Failure counters (login brute-force protection) ──

    def failure_count(self, key: str, window: int) -> int:
        with self._lock:
            cutoff = time.time() - window
            return len([t for t in self._failures.get(key, []) if t > cutoff])

    def register_failure(self, key: str, window: int) -> int:
        with self._lock:
            cutoff = time.time() - window
            entries = [t for t in self._failures.get(key, []) if t > cutoff]
            entries.append(time.time())
            self._failures[key] = entries
            if len(self._failures) > 20000:  # defensive bound
                self._failures = defaultdict(list, {k: v for k, v in self._failures.items() if v})
            return len(entries)

    def clear_failures(self, key: str) -> None:
        with self._lock:
            self._failures.pop(key, None)

    def retry_after(self, key: str, window: int) -> int:
        with self._lock:
            entries = self._failures.get(key) or []
            if not entries:
                return 0
            remaining = int(window - (time.time() - min(entries)))
            return max(1, remaining)


_rate_limiter = RateLimiter()

# Login throttling policy.  The IP limit is the primary brute-force control;
# the per-account limit is intentionally higher so that an attacker cannot lock
# a known username out with a handful of requests (denial of service).
LOGIN_FAILURE_WINDOW = 600
LOGIN_MAX_FAILURES_PER_IP = 15
LOGIN_MAX_FAILURES_PER_USER = 30

# Lifetime of the readable CSRF cookie — mirrors SESSION_MAX_AGE_SECONDS.
CSRF_COOKIE_MAX_AGE = int(os.environ.get("SESSION_MAX_AGE_SECONDS", "28800") or 28800)


# ── Safe DB Connection ───────────────────────────────────────

def _get_connection():
    """Create a fresh database connection. Never use global connections."""
    import pyodbc
    return pyodbc.connect(
        'DRIVER={ODBC Driver 17 for SQL Server};'
        r'SERVER=localhost\SQLEXPRESS;'
        'DATABASE=userDB;'
        'Trusted_Connection=yes;'
    )


def _client_ip(request: Request) -> str:
    """Trustworthy client address (see ``app.core.net.client_ip``).

    ``X-Forwarded-For`` is only honoured when it contains a syntactically valid
    address; the previous implementation trusted the first, client supplied,
    entry which made every IP based control bypassable.
    """
    return _trusted_client_ip(request)


def _user_agent(request: Request) -> str:
    return _safe_user_agent(request)


def _set_csrf_cookie(response, token: str, request: Request) -> None:
    """Publish the session CSRF token in the readable ``csrf_token`` cookie.

    The cookie is intentionally *not* HttpOnly: the front-end must read it to
    echo the value in the ``X-CSRF-Token`` header.  Confidentiality does not
    depend on it — the authoritative copy lives inside the signed session, and
    the middleware requires the header, the cookie and the session value to be
    identical.
    """
    from app.core.net import is_https as _is_https

    response.set_cookie(
        "csrf_token",
        token,
        max_age=CSRF_COOKIE_MAX_AGE,
        httponly=False,
        samesite="lax",
        secure=_is_https(request),
        path="/",
    )


@router.get("/api/csrf-token")
async def csrf_token_bootstrap(request: Request):
    """Return the token the front-end must echo in ``X-CSRF-Token``.

    Safe method (no state change) and therefore not CSRF protected.  The value
    is only useful together with the matching signed session cookie, which the
    browser refuses to hand to a third-party origin — the endpoint gives the
    SPA a way to recover when the readable cookie was cleared.
    """
    token = str(request.session.get("csrf_token") or "")
    if not token:
        token = secrets.token_hex(32)
        request.session["csrf_token"] = token
    response = JSONResponse({"success": True, "csrf_token": token})
    _set_csrf_cookie(response, token, request)
    return response


# ── CAPTCHA ─────────────────────────────────────────────────

@router.get("/captcha")
async def get_captcha(request: Request):
    """Generate a new CAPTCHA image and store the code in the session."""
    code, image_bytes = generate_captcha_image()
    store_captcha_in_session(request, code)

    log_event_safe(
        event_type="CAPTCHA", action="generated",
        ip_address=_client_ip(request), user_agent=_user_agent(request),
    )

    return StreamingResponse(
        iter([image_bytes]),
        media_type="image/png",
        headers={
            "Cache-Control": "no-store, no-cache, must-revalidate, max-age=0",
            "Pragma": "no-cache",
            "X-Content-Type-Options": "nosniff",
        },
    )


@router.post("/captcha/refresh")
async def refresh_captcha(request: Request):
    """Generate a new CAPTCHA and return it as base64."""
    code, image_bytes = generate_captcha_image()
    store_captcha_in_session(request, code)

    import base64
    b64 = base64.b64encode(image_bytes).decode("ascii")

    log_event_safe(
        event_type="CAPTCHA", action="refreshed",
        ip_address=_client_ip(request), user_agent=_user_agent(request),
    )

    return JSONResponse({
        "success": True,
        "image": f"data:image/png;base64,{b64}",
    })


@router.get("/captcha/status")
async def captcha_status(request: Request):
    """Check if current CAPTCHA is still valid."""
    remaining = captcha_remaining_seconds(request)
    has_captcha = request.session.get("captcha_code") is not None
    return JSONResponse({
        "success": True,
        "valid": has_captcha and remaining > 0,
        "remaining_seconds": remaining,
    })


def log_event_safe(**kwargs):
    """Log event without crashing if audit module fails."""
    try:
        from app.services.audit import log_event
        log_event(**kwargs)
    except Exception:
        pass


def _account_is_active(user) -> bool:
    """Return ``True`` unless the account row is explicitly disabled.

    ``fetch_user_for_login`` returns ``(username, role, password, password_hash,
    is_active)`` when the ``is_active`` column exists.  A disabled account must
    not be able to authenticate even when the password is correct.
    """
    if user is None:
        return False
    if len(user) <= 4:
        return True  # column not migrated yet — keep legacy behaviour
    status = str(user[4] or "active").strip().lower()
    return status not in {"disabled", "inactive", "locked", "0", "false"}


def _record_login_result(username: str, *, success: bool) -> None:
    """Best-effort bookkeeping for the master-admin dashboard."""
    conn = None
    try:
        conn = _get_connection()
        cur = conn.cursor()
        if success:
            cur.execute(
                "UPDATE user_table SET last_login = SYSUTCDATETIME(), failed_login_count = 0 "
                "WHERE LTRIM(RTRIM(username)) = ?",
                (username.strip(),),
            )
        else:
            cur.execute(
                "UPDATE user_table SET failed_login_count = ISNULL(failed_login_count, 0) + 1 "
                "WHERE LTRIM(RTRIM(username)) = ?",
                (username.strip(),),
            )
        conn.commit()
    except Exception:
        pass
    finally:
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass


# ── Login ────────────────────────────────────────────────────

@router.post("/login_user")
async def login(request: Request):
    try:
        data = await request.json()
    except Exception:
        return JSONResponse({"success": False, "message": "درخواست نامعتبر است."}, status_code=400)

    username = str(data.get("username") or "").strip()
    password = str(data.get("password") or "").strip()
    captcha_code = str(data.get("captcha") or "").strip()

    if not username or not password:
        return JSONResponse({"success": False, "message": "نام کاربری و رمز عبور الزامی هستند."})

    # ── Check if CAPTCHA is enabled in system config ──
    captcha_enabled = True
    try:
        _conn_cfg = _get_connection()
        _cur_cfg = _conn_cfg.cursor()
        _cur_cfg.execute("SELECT config_value FROM system_config WHERE config_key = 'captcha_enabled'")
        _row_cfg = _cur_cfg.fetchone()
        if _row_cfg and str(_row_cfg[0]).strip() in ('0', 'false', 'False'):
            captcha_enabled = False
        _conn_cfg.close()
    except Exception:
        pass

    # ── Validate CAPTCHA (only if enabled) ──
    if captcha_enabled:
        if not captcha_code:
            return JSONResponse({"success": False, "message": "کد امنیتی الزامی است."})

        captcha_valid, captcha_msg = validate_captcha(request, captcha_code)
        if not captcha_valid:
            log_event_safe(
                event_type="CAPTCHA", action="validation_failed",
                ip_address=_client_ip(request), user_agent=_user_agent(request),
                status="failure", severity="low",
            )
            return JSONResponse({"success": False, "message": captcha_msg, "captcha_error": True})

    # Input length check
    if len(username) > MAX_USERNAME_LENGTH:
        return JSONResponse({"success": False, "message": "نام کاربری یا رمز عبور اشتباه است."})
    if len(password) > MAX_PASSWORD_LENGTH:
        return JSONResponse({"success": False, "message": "نام کاربری یا رمز عبور اشتباه است."})

    # ── Brute-force throttling ──────────────────────────────
    ip = _client_ip(request)
    ip_key = f"ip:{ip}"
    user_key = f"user:{username.strip().lower()}"
    if _rate_limiter.failure_count(ip_key, LOGIN_FAILURE_WINDOW) >= LOGIN_MAX_FAILURES_PER_IP:
        log_event_safe(
            event_type="AUTHENTICATION", action="login_throttled",
            username=username, ip_address=ip, user_agent=_user_agent(request),
            status="failure", severity="high",
        )
        return JSONResponse(
            {"success": False, "message": "تعداد تلاش‌های ناموفق زیاد است. لطفاً چند دقیقه بعد تلاش کنید."},
            status_code=429,
        )

    conn = None
    try:
        conn = _get_connection()
        cursor = conn.cursor()
        user = fetch_user_for_login(cursor, username)
        account_active = _account_is_active(user)

        if user and account_active and verify_password(user[2], user[3] if len(user) > 3 else None, password):
            # Session rotation: clear old session data, set new to prevent session fixation
            request.session.clear()
            request.session["username"] = username
            # Server-side session id — enables revocation (password reset,
            # account disable, administrator "terminate session").
            session_token = session_registry.new_session_token()
            request.session[session_registry.SESSION_TOKEN_KEY] = session_token
            request.session[session_registry.SESSION_ISSUED_KEY] = int(time.time())
            # Session-bound CSRF token: minted here so that every authenticated
            # state-changing request can be verified against the signed session
            # (see _CSRFMiddleware in app.main).  The same value is written to
            # the readable csrf_token cookie on the way out so the front-end
            # fetch interceptor can echo it back in the X-CSRF-Token header.
            csrf_token = secrets.token_hex(32)
            request.session["csrf_token"] = csrf_token
            session_registry.register_session(
                session_token, username, ip, _user_agent(request)
            )
            _rate_limiter.clear_failures(ip_key)
            _rate_limiter.clear_failures(user_key)
            _record_login_result(username, success=True)
            role = user[1].strip().lower()

            if role == "admin":
                request.session["is_admin"] = True
                request.session["is_master_admin"] = username.strip().lower() in MASTER_ADMIN_USERNAMES
            else:
                request.session["is_admin"] = False
                request.session["is_master_admin"] = False

            # Audit: successful login
            try:
                from app.services.audit import log_event, track_session_login
                log_event(
                    event_type="AUTHENTICATION", action="login",
                    username=username, role=role,
                    ip_address=_client_ip(request), user_agent=_user_agent(request),
                    status="success",
                )
                track_session_login(
                    session_key=session_token,
                    username=username,
                    ip_address=_client_ip(request),
                    user_agent=_user_agent(request),
                )
            except Exception:
                pass

            # Master admin goes directly to control center
            if request.session.get("is_master_admin"):
                redirect = "/master-admin"
            elif role == "admin":
                redirect = "/admin/dashboard"
            else:
                redirect = "/user_panel"
            response = JSONResponse({"success": True, "redirect": redirect})
            _set_csrf_cookie(response, csrf_token, request)
            return response

        # Failed authentication — count against both the source address and the
        # account, then answer with a single generic message (no user
        # enumeration, no distinction between unknown user / wrong password /
        # disabled account).
        failures = _rate_limiter.register_failure(ip_key, LOGIN_FAILURE_WINDOW)
        account_failures = _rate_limiter.register_failure(user_key, LOGIN_FAILURE_WINDOW)
        _record_login_result(username, success=False)

        # Audit: failed login (severity escalates when throttling triggers)
        try:
            from app.services.audit import log_event
            log_event(
                event_type="AUTHENTICATION", action="login",
                username=username,
                ip_address=ip, user_agent=_user_agent(request),
                status="failure",
                severity="high" if failures >= LOGIN_MAX_FAILURES_PER_IP else "low",
                metadata={
                    "failures_from_ip": failures,
                    "failures_for_account": account_failures,
                    "account_known": bool(user),
                },
            )
        except Exception:
            pass

        return JSONResponse({"success": False, "message": "نام کاربری یا رمز عبور اشتباه است"})
    except Exception as e:
        logger.error(f"Login error: {e}")
        return JSONResponse({"success": False, "message": "خطایی رخ داد. لطفاً دوباره تلاش کنید."}, status_code=500)
    finally:
        if conn:
            try:
                conn.close()
            except Exception:
                pass


# ── Forgot Password ──────────────────────────────────────────

UNIFIED_MESSAGE = "اگر این حساب وجود داشته باشد، درخواست بازیابی رمز ثبت خواهد شد. منتظر تأیید مدیر سامانه باشید."


@router.post("/forgot_password")
async def forgot_password(request: Request):
    """Public endpoint — submit a password reset request (no auth required)."""
    # Rate limit check
    ip = _client_ip(request)
    if not _rate_limiter.check_ip(ip, max_requests=5, window=600):
        return JSONResponse({"success": False, "message": "تعداد درخواست‌ها بیش از حد مجاز است. لطفاً چند دقیقه صبر کنید."}, status_code=429)

    # Parse JSON safely
    try:
        data = await request.json()
    except Exception:
        return JSONResponse({"success": False, "message": "درخواست نامعتبر است."}, status_code=400)

    username = str(data.get("username") or "").strip()

    # Validate username input
    validation = validate_username_input(username)
    if not validation["valid"]:
        # Still return success to prevent enumeration
        return JSONResponse({"success": True, "message": UNIFIED_MESSAGE})

    # Rate limit per username
    if not _rate_limiter.check_username(username, max_requests=3, window=3600):
        return JSONResponse({"success": True, "message": UNIFIED_MESSAGE})

    conn = None
    try:
        from app.services.audit import (
            create_password_reset_request, generate_request_id, recovery_codes_available,
        )

        if not recovery_codes_available():
            # Fail closed: without the HMAC key the issued codes would have no
            # integrity protection, so recovery is disabled until it is set.
            logger.error("password recovery requested but HASTAMA_HMAC_SECRET is not configured")
            return JSONResponse({"success": False, "message": "بازیابی رمز عبور در این نصب غیرفعال است. با مدیر سامانه تماس بگیرید."})

        conn = _get_connection()
        cursor = conn.cursor()

        # Does the account exist?  The answer must not be observable: both
        # branches return the same body shape and the same headers, and the
        # request id returned for an unknown account is an unused decoy value.
        user = fetch_user_for_login(cursor, username)
        if not user:
            decoy = generate_request_id()
            log_event_safe(
                event_type="AUTHENTICATION", action="password_reset_requested",
                username=username, module="password",
                ip_address=ip, user_agent=_user_agent(request),
                status="failure", severity="low",
            )
            return JSONResponse({"success": True, "message": UNIFIED_MESSAGE, "request_id": decoy})

        result = create_password_reset_request(
            username=username,
            ip_address=ip,
            user_agent=_user_agent(request),
        )
        request_id = result.get("request_id") or generate_request_id()
        if not result.get("request_id"):
            logger.warning("password reset request could not be persisted for %s", username)
        return JSONResponse({"success": True, "message": UNIFIED_MESSAGE, "request_id": request_id})
    except Exception as e:
        logger.error(f"Forgot password error: {type(e).__name__}: {e}")
        return JSONResponse({"success": True, "message": UNIFIED_MESSAGE})
    finally:
        if conn:
            try:
                conn.close()
            except Exception:
                pass


# ── Reset Password ───────────────────────────────────────────

@router.post("/reset_password")
async def reset_password(request: Request):
    """Public endpoint — verify recovery code and set new password (no auth required)."""
    # Rate limit check
    ip = _client_ip(request)
    if not _rate_limiter.check_ip(ip, max_requests=5, window=600):
        return JSONResponse({"success": False, "message": "تعداد درخواست‌ها بیش از حد مجاز است. لطفاً چند دقیقه صبر کنید."}, status_code=429)

    # Parse JSON safely
    try:
        data = await request.json()
    except Exception:
        return JSONResponse({"success": False, "message": "درخواست نامعتبر است."}, status_code=400)

    request_id = str(data.get("request_id") or "").strip()
    code = str(data.get("code") or "").strip()
    new_password = str(data.get("new_password") or "").strip()

    # Validate all inputs
    req_validation = validate_request_id(request_id)
    if not req_validation["valid"]:
        return JSONResponse({"success": False, "message": req_validation["error"]})

    code_validation = validate_recovery_code(code)
    if not code_validation["valid"]:
        return JSONResponse({"success": False, "message": code_validation["error"]})

    if not new_password:
        return JSONResponse({"success": False, "message": "رمز عبور جدید الزامی است."})

    if len(new_password) > MAX_PASSWORD_LENGTH:
        return JSONResponse({"success": False, "message": "رمز عبور نباید بیش از ۱۲۸ کاراکتر باشد."})

    # Password policy check
    pw_policy = validate_password_policy(new_password)
    if not pw_policy["valid"]:
        return JSONResponse({"success": False, "message": "رمز عبور ضعیف است: " + ", ".join(pw_policy["errors"])})

    conn = None
    try:
        from app.services.audit import verify_recovery_code, log_event

        result = verify_recovery_code(request_id=request_id, code=code)
        if not result.get("success"):
            return JSONResponse({"success": False, "message": result.get("message", "خطا")})

        username = result["username"]
        new_hash = hash_password(new_password)

        conn = _get_connection()
        cur = conn.cursor()

        # Update password — store bcrypt hash only, clear plain text
        try:
            cur.execute(
                "UPDATE user_table SET password = '', password_hash = ? WHERE LTRIM(RTRIM(username)) = ?",
                (new_hash, username),
            )
        except Exception:
            # password_hash column might not exist — add it, then migrate
            try:
                cur.execute("ALTER TABLE user_table ADD password_hash VARBINARY(MAX) NULL")
                conn.commit()
                cur.execute(
                    "UPDATE user_table SET password = '', password_hash = ? WHERE LTRIM(RTRIM(username)) = ?",
                    (new_hash, username),
                )
            except Exception:
                # Column already exists or migration failed — store hash in password column as last resort
                cur.execute(
                    "UPDATE user_table SET password = ? WHERE LTRIM(RTRIM(username)) = ?",
                    (new_hash.decode("utf-8") if isinstance(new_hash, bytes) else str(new_hash), username),
                )
        conn.commit()

        # Any session that existed before the reset must be invalidated, not just
        # the browser the user happens to be sitting in front of.
        revoked = session_registry.revoke_user_sessions(username, "password_reset")

        log_event(
            event_type="AUTHENTICATION", action="password_reset_complete",
            username=username, module="password",
            status="success", severity="medium",
            ip_address=ip, user_agent=_user_agent(request),
            metadata={"sessions_revoked": revoked},
        )

        return JSONResponse({"success": True, "message": "رمز عبور با موفقیت تغییر کرد. حالا می‌توانید وارد شوید."})
    except Exception as e:
        logger.error(f"Reset password error: {e}")
        return JSONResponse({"success": False, "message": "خطا در تغییر رمز عبور. لطفاً دوباره تلاش کنید."}, status_code=500)
    finally:
        if conn:
            try:
                conn.close()
            except Exception:
                pass


# ── Public Support Ticket (Anonymous) ────────────────────────

@router.post("/public/support-ticket")
async def public_support_ticket(request: Request):
    """
    Allow anonymous users (not logged in) to send a support ticket
    to the master admin requesting account recovery (forgot username+password).
    """
    try:
        body = await request.json()
    except Exception:
        return JSONResponse({"success": False, "message": "داده نامعتبر."}, status_code=400)

    full_name = (body.get("full_name") or "").strip()
    phone = (body.get("phone") or "").strip()
    description = (body.get("description") or "").strip()

    if not full_name:
        return JSONResponse({"success": False, "message": "لطفاً نام و نام خانوادگی را وارد کنید."}, status_code=400)
    if not phone:
        return JSONResponse({"success": False, "message": "لطفاً شماره تماس را وارد کنید."}, status_code=400)
    if len(full_name) > 200:
        return JSONResponse({"success": False, "message": "نام بیش از حد طولانی است."}, status_code=400)
    if len(phone) > 20:
        return JSONResponse({"success": False, "message": "شماره تماس نامعتبر است."}, status_code=400)
    if len(description) > 2000:
        return JSONResponse({"success": False, "message": "توضیحات بیش از حد طولانی است."}, status_code=400)

    # Rate limit: max 3 tickets per IP per hour
    ip = request.client.host if request.client else "unknown"
    try:
        from app.services.ticketing import get_connection
        conn = get_connection()
        cur = conn.cursor()
        cur.execute("""
            SELECT COUNT(*) FROM ticket_messages m
            JOIN tickets t ON t.id = m.ticket_id
            WHERE m.body LIKE ? AND t.requester_username = '__anonymous__'
              AND t.created_at > DATEADD(HOUR, -1, GETDATE())
        """, (f"%[{ip}]%",))
        count = cur.fetchone()[0]
        conn.close()
        if count >= 3:
            return JSONResponse({"success": False, "message": "تعداد درخواست‌ها بیش از حد مجاز است. لطفاً بعداً تلاش کنید."}, status_code=429)
    except Exception:
        # Fail closed: if the rate-limit store is unavailable, refuse the
        # anonymous ticket rather than allowing unthrottled abuse.
        return JSONResponse(
            {"success": False, "message": "سرویس موقتاً در دسترس نیست. لطفاً کمی بعد تلاش کنید."},
            status_code=503,
        )

    subject = f"درخواست بازیابی اطلاعات ورود - {full_name}"
    ticket_body = (
        f"کاربر به صورت ناشناس درخواست ایجاد نام کاربری و رمز عبور جدید ارسال کرده است.\n\n"
        f"**نام و نام خانوادگی:** {full_name}\n"
        f"**شماره تماس:** {phone}\n"
    )
    if description:
        ticket_body += f"**توضیحات کاربر:**\n{description}\n"
    ticket_body += (
        f"\n---\n"
        f"📍 **آی‌پی درخواست‌دهنده:** {ip}\n"
        f"🕐 **زمان درخواست:** {time.strftime('%Y-%m-%d %H:%M:%S')}\n\n"
        f"⚠️ این کاربر نام کاربری و رمز عبور خود را فراموش کرده و امکان استفاده از بازیابی رمز عبور را ندارد. "
        f"لطفاً پس از بر هویت، نام کاربری و رمز عبور جدیدی برای ایشان ایجاد کنید."
    )

    try:
        from app.services.ticketing import TicketService
        with TicketService() as svc:
            result = svc.create_ticket(
                actor="__anonymous__",
                is_admin=False,
                recipient_username="ali",
                subject=subject,
                body=ticket_body,
                priority="high",
            )
            ticket_id = result.get("id")

        from app.services.audit import log_event
        log_event(
            event_type="SUPPORT", action="anonymous_ticket_created",
            username="__anonymous__", module="support",
            status="success", severity="low",
            ip_address=ip, user_agent=_user_agent(request),
        )

        return JSONResponse({
            "success": True,
            "message": "درخواست شما با موفقیت ثبت شد. مدیر سامانه در اسرع وقت با شما تماس خواهد گرفت.",
            "ticket_id": ticket_id,
        })
    except Exception as e:
        logger.error(f"Public support ticket error: {e}")
        return JSONResponse({"success": False, "message": "خطا در ثبت درخواست. لطفاً دوباره تلاش کنید."}, status_code=500)
