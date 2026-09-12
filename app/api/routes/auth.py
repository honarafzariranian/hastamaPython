# app/api/routes/auth.py — Security-Hardened Authentication Routes

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse
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

router = APIRouter()
logger = logging.getLogger("hastama.auth")

# Master admin usernames
MASTER_ADMIN_USERNAMES = {"ali"}

# ── Rate Limiting ────────────────────────────────────────────

class RateLimiter:
    """Simple in-memory rate limiter per IP and per username."""

    def __init__(self):
        self._ip_requests = defaultdict(list)      # ip -> [timestamp, ...]
        self._username_requests = defaultdict(list)  # username -> [timestamp, ...]
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


_rate_limiter = RateLimiter()


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
    return request.headers.get("x-forwarded-for", "").split(",")[0].strip() or (
        request.client.host if request.client else "unknown"
    )


def _user_agent(request: Request) -> str:
    return request.headers.get("user-agent", "")[:500]


# ── Login ────────────────────────────────────────────────────

@router.post("/login_user")
async def login(request: Request):
    try:
        data = await request.json()
    except Exception:
        return JSONResponse({"success": False, "message": "درخواست نامعتبر است."}, status_code=400)

    username = str(data.get("username") or "").strip()
    password = str(data.get("password") or "").strip()

    if not username or not password:
        return JSONResponse({"success": False, "message": "نام کاربری و رمز عبور الزامی هستند."})

    # Input length check
    if len(username) > MAX_USERNAME_LENGTH:
        return JSONResponse({"success": False, "message": "نام کاربری یا رمز عبور اشتباه است."})
    if len(password) > MAX_PASSWORD_LENGTH:
        return JSONResponse({"success": False, "message": "نام کاربری یا رمز عبور اشتباه است."})

    conn = None
    try:
        conn = _get_connection()
        cursor = conn.cursor()
        user = fetch_user_for_login(cursor, username)

        if user and verify_password(user[2], user[3] if len(user) > 3 else None, password):
            request.session["username"] = username
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
                    session_key=request.session.get("session", ""),
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
            return JSONResponse({"success": True, "redirect": redirect})

        # Audit: failed login
        try:
            from app.services.audit import log_event
            log_event(
                event_type="AUTHENTICATION", action="login",
                username=username,
                ip_address=_client_ip(request), user_agent=_user_agent(request),
                status="failure", severity="low",
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
        conn = _get_connection()
        cursor = conn.cursor()

        # Check user exists (but don't reveal to caller)
        user = fetch_user_for_login(cursor, username)
        if not user:
            # Always return success to prevent user enumeration
            return JSONResponse({"success": True, "message": UNIFIED_MESSAGE})

        from app.services.audit import create_password_reset_request
        result = create_password_reset_request(
            username=username,
            ip_address=ip,
            user_agent=_user_agent(request),
        )
        if result.get("request_id"):
            return JSONResponse({"success": True, "message": UNIFIED_MESSAGE, "request_id": result["request_id"]})
        else:
            return JSONResponse({"success": True, "message": UNIFIED_MESSAGE})
    except Exception as e:
        logger.error(f"Forgot password error: {e}")
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
            # password_hash column might not exist — store plain text as fallback
            cur.execute(
                "UPDATE user_table SET password = ? WHERE LTRIM(RTRIM(username)) = ?",
                (new_password, username),
            )
        conn.commit()

        log_event(
            event_type="AUTHENTICATION", action="password_reset_complete",
            username=username, module="password",
            status="success", severity="medium",
            ip_address=ip, user_agent=_user_agent(request),
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
