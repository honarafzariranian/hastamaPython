import sys
import os
import re
import pyodbc
import jdatetime
import random
import pdfkit
import shutil
import json
from datetime import datetime, date, time, timedelta
from collections import Counter
from persiantools.jdatetime import JalaliDate
from typing import List
from io import BytesIO

sys.path.append(os.path.abspath(os.path.dirname(__file__)))
sys.path.append(os.path.abspath(os.path.join(os.path.dirname(__file__), "..")))

from app.api.routes.auth import router as auth_router
from app.api.routes.notifications import (
    router as notifications_router,
    publish_system_notification,
    publish_system_notification_to_admins,
)
from app.api.routes.ticketing import router as ticketing_router
from app.api.routes.automation import router as automation_router
from app.api.routes.health import router as health_router
from app.api.routes.call_system import router as call_system_router
from app.api.routes.araz_api import router as araz_router
from app.api.routes.master_admin import router as master_admin_router
from app.api.routes.registration import router as registration_router
from app.services.background_tasks import start_background_tasks, stop_background_tasks
from app.services.presence_summary import build_presence_summary, time_is_inside_range
from app.services.attendance import compute_attendance_status, format_time_value
from core.config import DEBUG, SECRET_KEY, config
from app.core.database import connection_string as _db_connection_string
from core.number_format import convert_to_persian_numbers
from core.password_utils import (
    get_user_table_columns, hash_password, insert_user_with_optional_hash,
    validate_username_input,
)

from fastapi import FastAPI, HTTPException, Request, Form, Query, Response, Body, UploadFile, File
from fastapi.responses import FileResponse, HTMLResponse, RedirectResponse, JSONResponse, StreamingResponse
from fastapi.requests import Request
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.middleware.sessions import SessionMiddleware
from pydantic import BaseModel

import logging

# Application logger.  ``_safe_error_message`` and several security helpers
# report failures through it; without the definition the handler raised
# NameError while trying to build its error response.
logger = logging.getLogger("hastama.main")

# ایجاد اپلیکیشن FastAPI
# Interactive API documentation enumerates every route and schema, which is
# useful on a developer machine and an unnecessary disclosure on the LAN.
# It is therefore opt-in: set HASTAMA_ENABLE_DOCS=true (or DEBUG=true) to
# expose /docs, /redoc and /openapi.json.
_ENABLE_DOCS = DEBUG or os.getenv("HASTAMA_ENABLE_DOCS", "").strip().lower() in {"1", "true", "yes", "on"}
app = FastAPI(
    debug=DEBUG,
    docs_url="/docs" if _ENABLE_DOCS else None,
    redoc_url="/redoc" if _ENABLE_DOCS else None,
    openapi_url="/openapi.json" if _ENABLE_DOCS else None,
)

# Production requires an explicitly configured stable signing key.
_session_secret = os.getenv("SESSION_SECRET_KEY") or str(SECRET_KEY or "")
if not _session_secret and not DEBUG:
    raise RuntimeError("SESSION_SECRET_KEY or SECRET_KEY must be configured when DEBUG=false")
if not _session_secret:
    _session_secret = os.urandom(32).hex()

SESSION_MAX_AGE_SECONDS = int(os.getenv("SESSION_MAX_AGE_SECONDS", "28800") or 28800)

from starlette.types import ASGIApp, Receive, Scope, Send
import secrets as _secrets

from app.core.db_context import RequestConnectionMiddleware, connection_proxy, cursor_proxy
from app.core.session_cookie import parse_session_cookie, expire_cookie_header
from app.core import sessions as _session_registry


def _request_cookies(scope: Scope) -> dict:
    headers = dict(scope.get("headers", []))
    raw_cookie = headers.get(b"cookie", b"").decode("latin-1", errors="ignore")
    cookies = {}
    for part in raw_cookie.split(";"):
        part = part.strip()
        if "=" in part:
            k, v = part.split("=", 1)
            cookies[k.strip()] = v.strip()
    return cookies


# CSRF exemptions cover two groups, both enforced with an Origin/Referer
# same-site check instead of the session bound token (see
# _CSRFMiddleware._origin_allowed):
#
#   1. kiosk-style call endpoints that are intentionally usable without a login
#      (the reception desk has no session);
#   2. pre-authentication state changes (login, password reset, registration).
#      These requests have no signed session yet, so a session-bound token
#      cannot exist; without the exemption a correct login could never pass.
CSRF_EXEMPT_PREFIXES = (
    "/api/calls",
    "/api/call-display",
    "/api/display-queue",
    "/api/waiting-queue",
    "/api/queue/take",
    "/api/slides",
    "/api/ws/",
    "/login_user",
    "/forgot_password",
    "/reset_password",
    "/verify_recovery_code",
    "/registration/",
    "/captcha/",
    # Machine-to-machine device bridge: authenticated with the shared
    # ARAZ_BRIDGE_SECRET (constant-time compare), never with a session cookie.
    # The bridge agent runs as a service and sends no Origin header.
    "/api/araz/",
    # Public pre-authentication support form; same-origin is still enforced.
    "/public/",
)

CSRF_TOKEN_SESSION_KEY = "csrf_token"
CSRF_TOKEN_COOKIE = "csrf_token"

# Endpoints that must never gain a CSRF cookie (nothing to protect, avoids
# useless Set-Cookie churn on health checks and static files).
_CSRF_NO_COOKIE_PATHS = ("/static/", "/health", "/favicon.ico")


class _CSRFMiddleware:
    """Session-bound double-submit CSRF protection.

    The client sends the token twice: in the readable ``csrf_token`` cookie and
    in the ``X-CSRF-Token`` header.  Both must match **and** must equal the token
    stored inside the signed session, which binds the token to the authenticated
    session instead of merely to a cookie an attacker might be able to set.

    Requests that change state and are not covered by the kiosk exemptions must
    present a matching token; failures are audited.
    """

    _STATE_METHODS = {"POST", "PUT", "DELETE", "PATCH"}
    COOKIE_NAME = "csrf_token"
    HEADER_NAME = b"x-csrf-token"
    COOKIE_MAX_AGE = SESSION_MAX_AGE_SECONDS

    def __init__(self, app: ASGIApp, secret: str = "", session_max_age: int = 0) -> None:
        self.app = app
        self._secret = secret
        self._session_max_age = session_max_age

    def _session_token(self, scope: Scope) -> str:
        cookies = _request_cookies(scope)
        session = parse_session_cookie(
            cookies.get("session"), self._secret, self._session_max_age
        )
        return str(session.get("csrf_token") or "")

    @staticmethod
    def _exempt(path: str) -> bool:
        return any(path.startswith(prefix) for prefix in CSRF_EXEMPT_PREFIXES)

    @staticmethod
    def _origin_allowed(scope: Scope) -> bool:
        """Origin check used for the CSRF-exempt kiosk endpoints.

        Browsers always send ``Origin`` on cross-site POST/PUT/DELETE.  Requests
        without an ``Origin`` header (scripts, the bridge agent, curl) are
        allowed, which keeps the documented LAN integrations working.
        """
        headers = dict(scope.get("headers", []))
        origin = headers.get(b"origin", b"").decode("latin-1", errors="ignore")
        if not origin:
            return True
        if origin == "null":
            return False
        host = headers.get(b"host", b"").decode("latin-1", errors="ignore")
        try:
            from urllib.parse import urlparse

            netloc = urlparse(origin).netloc or urlparse(origin).path
        except Exception:
            return False
        return netloc.split(":")[0].lower() == host.split(":")[0].lower()

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        method = scope.get("method", "GET").upper()
        headers = dict(scope.get("headers", []))
        path = scope.get("path", "")
        cookies = _request_cookies(scope)
        csrf_token = cookies.get(self.COOKIE_NAME, "")
        session_token = self._session_token(scope)

        if method in self._STATE_METHODS:
            if self._exempt(path):
                if not self._origin_allowed(scope):
                    await self._reject(scope, receive, send, path, "origin")
                    return
            else:
                header_token = headers.get(self.HEADER_NAME, b"").decode("latin-1", errors="ignore")
                if not self._token_valid(cookie_token=csrf_token, header_token=header_token, session_token=session_token):
                    await self._reject(scope, receive, send, path, "token")
                    return

        # Decide which cookie value this response must carry.
        #
        #  * authenticated session with a token  -> the cookie must equal the
        #    session token (re-synchronise if the browser lost the cookie).
        #  * anonymous visitor without a cookie  -> mint a fresh random token
        #    (it becomes the session token when the user logs in).
        #  * anonymous visitor with a cookie     -> leave it alone: the token is
        #    bound to the session at login time.
        def _cookie_needed(resp_headers) -> str:
            """Decide the readable cookie value for this response.

            The session may have *changed* while handling the request (login
            mints ``session["csrf_token"]``), so the outgoing signed cookie —
            written by the inner SessionMiddleware — is authoritative:
            whatever token ends up in the session is the token the browser must
            send back.  Reading it here keeps the two copies in sync even
            though this middleware runs outside the session layer.
            """
            if any(k.lower() == b"set-cookie" and v.startswith(b"csrf_token=")
                   for k, v in resp_headers):
                return ""  # the endpoint published its own cookie already
            new_session_token = ""
            for key, value in resp_headers:
                if key.lower() == b"set-cookie" and value.startswith(b"session="):
                    raw = value.split(b";", 1)[0][len(b"session="):].decode("latin-1")
                    new_session_token = str(
                        parse_session_cookie(
                            raw if raw != "null" else "", self._secret, self._session_max_age
                        ).get(CSRF_TOKEN_SESSION_KEY) or ""
                    )
                    break
            effective = new_session_token or session_token
            if effective:
                return "" if csrf_token == effective else effective
            if csrf_token or path.startswith(_CSRF_NO_COOKIE_PATHS):
                return ""  # anonymous visitor already holds a token
            return _secrets.token_hex(32)

        async def _send(message):
            try:
                if message["type"] == "http.response.start":
                    resp_headers = list(message.get("headers", []))
                    value = _cookie_needed(resp_headers)
                    if value:
                        flags = f"Path=/; SameSite=Lax; Max-Age={self.COOKIE_MAX_AGE}"
                        if _request_is_https_scope(scope):
                            flags += "; Secure"
                        resp_headers.append(
                            (b"set-cookie",
                             f"{self.COOKIE_NAME}={value}; {flags}".encode("latin-1"))
                        )
                        message["headers"] = resp_headers
            except Exception:
                pass
            await send(message)

        await self.app(scope, receive, _send)

    @staticmethod
    def _token_valid(*, cookie_token: str, header_token: str, session_token: str) -> bool:
        if not cookie_token or not header_token or not session_token:
            return False
        # Constant-time comparison (tokens are equal-length hex strings).
        return _secrets.compare_digest(cookie_token, header_token) and _secrets.compare_digest(
            cookie_token, session_token
        )

    async def _reject(self, scope, receive, send, path: str, reason: str) -> None:
        _audit_csrf_failure(scope, path, reason)
        from starlette.responses import JSONResponse

        response = JSONResponse(
            status_code=403,
            content={"success": False, "error": "CSRF token mismatch."},
        )
        await response(scope, receive, send)


def _scope_peer(scope: Scope) -> str:
    client = scope.get("client") or ()
    try:
        return str(client[0]) if client else ""
    except Exception:  # pragma: no cover - defensive
        return ""


def _scope_peer_trusted(scope: Scope) -> bool:
    """Whether forwarding headers from this peer may be believed."""
    from app.core.net import _parse_ip, trusted_proxies

    peer = _parse_ip(_scope_peer(scope))
    return bool(peer) and peer in trusted_proxies()


def _request_is_https_scope(scope: Scope) -> bool:
    """HTTPS detection that cannot be forced by an untrusted client.

    ``X-Forwarded-Proto`` decides cookie ``Secure`` flags, HSTS and several
    audit fields, so it is only honored when the immediate peer is a configured
    trusted proxy (Caddy on loopback by default).
    """
    if _scope_peer_trusted(scope):
        headers = dict(scope.get("headers", []))
        proto = headers.get(b"x-forwarded-proto", b"").decode("latin-1", errors="ignore")
        if proto:
            return proto.split(",")[-1].strip().lower() == "https"
    return str(scope.get("scheme", "")).lower() == "https"


def _audit_csrf_failure(scope: Scope, path: str, reason: str) -> None:
    """Record CSRF/Origin rejections so abuse is visible in the audit log."""
    try:
        from app.services.audit import log_event

        headers = dict(scope.get("headers", []))
        cookies = _request_cookies(scope)
        session = parse_session_cookie(
            cookies.get("session"), _session_secret, SESSION_MAX_AGE_SECONDS
        )
        log_event(
            event_type="SECURITY",
            action="csrf_rejected" if reason == "token" else "origin_rejected",
            username=str(session.get("username") or "") or None,
            module="csrf",
            resource_type="endpoint",
            resource_id=path[:200],
            ip_address=_scope_client_ip(scope),
            user_agent=headers.get(b"user-agent", b"").decode("latin-1", errors="ignore")[:500],
            status="failure",
            severity="medium",
        )
    except Exception:
        pass


def _scope_client_ip(scope: Scope) -> str:
    """IP extraction for middleware (no Request object available).

    Forwarding headers are only read from a trusted proxy — see
    ``app.core.net``.  The result is always a valid IP literal or ``unknown``.
    """
    from app.core.net import _parse_ip  # local import: private helper reuse

    if _scope_peer_trusted(scope):
        headers = dict(scope.get("headers", []))
        xff = headers.get(b"x-forwarded-for", b"").decode("latin-1", errors="ignore")
        if xff and len(xff) <= 512:
            for part in reversed(xff.split(",")[-20:]):
                parsed = _parse_ip(part)
                if parsed:
                    return parsed
    return _parse_ip(_scope_peer(scope)) or "unknown"


class _SessionRegistryMiddleware:
    """Enforce server-side session validity (revocation + idle timeout).

    A signed cookie cannot be revoked, so password resets, account disabling,
    role changes and administrator "terminate session" actions were ineffective.
    Every authenticated request must now correspond to an active
    ``user_sessions`` row; failures clear the cookie and answer with a redirect
    (HTML) or ``401`` (API/JSON).
    """

    _API_PREFIXES = ("/api/", "/master-admin/api/", "/registration/", "/ticketing/", "/notifications")

    def __init__(self, app: ASGIApp, secret: str = "", session_max_age: int = 0) -> None:
        self.app = app
        self._secret = secret
        self._session_max_age = session_max_age

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            await self.app(scope, receive, send)
            return

        path = scope.get("path", "")
        if path.startswith("/static/"):
            await self.app(scope, receive, send)
            return

        cookies = _request_cookies(scope)
        session = parse_session_cookie(
            cookies.get("session"), self._secret, self._session_max_age
        )
        username = str(session.get("username") or "").strip()
        if not username:
            await self.app(scope, receive, send)
            return

        sid = str(session.get(_session_registry.SESSION_TOKEN_KEY) or "").strip()
        if sid and _session_registry.validate_session(sid, username):
            await self.app(scope, receive, send)
            return

        # Session is not (or no longer) valid — drop the cookie and reject.
        _audit_session_rejected(scope, path, username, bool(sid))
        headers = dict(scope.get("headers", []))
        accept = headers.get(b"accept", b"").decode("latin-1", errors="ignore").lower()
        wants_json = (
            path.startswith(self._API_PREFIXES)
            or "application/json" in accept
            or headers.get(b"x-requested-with", b"").lower() == b"xmlhttprequest"
        )
        secure = _request_is_https_scope(scope)

        async def _send(message):
            if message["type"] == "http.response.start":
                resp_headers = list(message.get("headers", []))
                resp_headers.append((b"set-cookie", expire_cookie_header(secure=secure)))
                message["headers"] = resp_headers
            await send(message)

        if wants_json:
            from starlette.responses import JSONResponse

            response = JSONResponse(
                status_code=401,
                content={"success": False, "error": "نشست شما منقضی یا باطل شده است. دوباره وارد شوید."},
            )
            await response(scope, receive, _send)
        else:
            from starlette.responses import RedirectResponse

            response = RedirectResponse(url="/login", status_code=303)
            await response(scope, receive, _send)


def _audit_session_rejected(scope: Scope, path: str, username: str, had_token: bool) -> None:
    try:
        from app.services.audit import log_event

        log_event(
            event_type="SECURITY",
            action="session_rejected",
            username=username or None,
            module="session",
            resource_type="endpoint",
            resource_id=path[:200],
            ip_address=_scope_client_ip(scope),
            status="failure",
            severity="medium",
            metadata={"reason": "revoked_or_unknown" if had_token else "legacy_session_without_token"},
        )
    except Exception:
        pass


app.add_middleware(RequestConnectionMiddleware)
app.add_middleware(
    SessionMiddleware,
    secret_key=_session_secret,
    same_site="lax",
    max_age=SESSION_MAX_AGE_SECONDS,
)
app.add_middleware(
    _CSRFMiddleware, secret=_session_secret, session_max_age=SESSION_MAX_AGE_SECONDS
)
app.add_middleware(
    _SessionRegistryMiddleware, secret=_session_secret, session_max_age=SESSION_MAX_AGE_SECONDS
)


class _SecurityHeadersMiddleware:
    """Raw ASGI middleware — does NOT wrap via BaseHTTPMiddleware,
    so WebSocket upgrades pass through untouched."""

    _HEADERS = [
        (b"x-content-type-options", b"nosniff"),
        (b"x-frame-options", b"SAMEORIGIN"),
        (b"referrer-policy", b"strict-origin-when-cross-origin"),
        (b"permissions-policy", b"camera=(), microphone=(), geolocation=()"),
        (b"cross-origin-opener-policy", b"same-origin"),
        (b"cross-origin-resource-policy", b"same-origin"),
        (b"x-permitted-cross-domain-policies", b"none"),
        (b"content-security-policy",
         b"default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' ws: wss:; worker-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'"),
    ]
    _HSTS = (b"strict-transport-security", b"max-age=31536000; includeSubDomains")

    def __init__(self, app: ASGIApp) -> None:
        self.app = app

    async def __call__(self, scope: Scope, receive: Receive, send: Send) -> None:
        if scope["type"] != "http":
            # WebSocket / lifespan — pass straight through
            await self.app(scope, receive, send)
            return

        https = _request_is_https_scope(scope)

        async def _send(message):
            if message["type"] == "http.response.start":
                headers = list(message.get("headers", []))
                existing = {k for k, _ in headers}
                # Browsers ignore COOP on an untrusted HTTP origin (for
                # example an internal IP address). Emit it only when the
                # response is delivered over HTTPS, where it is effective.
                extra = [
                    (key, value)
                    for key, value in self._HEADERS
                    if key != b"cross-origin-opener-policy" or https
                ]
                if https:
                    extra.append(self._HSTS)
                for k, v in extra:
                    if k not in existing:
                        headers.append((k, v))
                if https:
                    headers = _harden_cookies(headers)
                message["headers"] = headers
            await send(message)

        await self.app(scope, receive, _send)


def _harden_cookies(headers: list) -> list:
    """Add ``Secure`` to every ``Set-Cookie`` on an HTTPS response."""
    hardened = []
    for key, value in headers:
        if key.lower() == b"set-cookie" and b"secure" not in value.lower():
            value = value + b"; Secure"
        hardened.append((key, value))
    return hardened


app.add_middleware(_SecurityHeadersMiddleware)

# Browsers request this conventional root URL even when a page does not
# declare an explicit favicon link.
@app.get("/favicon.ico", include_in_schema=False)
def favicon():
    return FileResponse("app/static/favicon.ico", media_type="image/x-icon")

# ثبت مسیر استاتیک برای فایل‌های CSS و JavaScript
app.mount("/static", StaticFiles(directory="app/static"), name="static")

app.include_router(auth_router)
app.include_router(notifications_router)
app.include_router(ticketing_router)
app.include_router(automation_router)
app.include_router(health_router)
app.include_router(call_system_router)
app.include_router(araz_router)
app.include_router(master_admin_router)
app.include_router(registration_router)

@app.on_event("startup")
def start_notification_background_tasks():
    start_background_tasks()


@app.on_event("shutdown")
def stop_notification_background_tasks():
    stop_background_tasks()

# تنظیمات برای HTML Templates
templates = Jinja2Templates(directory="app/templates")

PASS_TITLE_LABELS = {
    "avalpss": "پاس اول وقت",
    "beynpss": "پاس بین وقت",
    "akhrpss": "پاس آخر وقت",
}


def format_pass_title(value):
    normalized = str(value or "").strip().lower()
    return PASS_TITLE_LABELS.get(normalized, value or "پاس ساعتی")

# اتصال به دیتابیس SQL Server
#
# `conn` / `cursor` are proxies (app/core/db_context.py).  Each HTTP request is
# bound to its own connection, so concurrent requests can no longer interleave
# statements on one shared pyodbc cursor (which allowed one request to read
# another request's result set).  Existing call sites are unchanged.
conn = connection_proxy()
cursor = cursor_proxy()

# تبدیل اعداد به اعداد فارسی
# این تابع در فایل core/number_format.py تعریف شده و در این ماژول استفاده می‌شود.

# تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود
# تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود
# تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود # تابع صفحه ورود

@app.get("/login")
async def home(request: Request):
    return templates.TemplateResponse(request, "login.html", {"request": request})

@app.get("/register", response_class=HTMLResponse)
async def register_page(request: Request):
    return templates.TemplateResponse(request, "register.html", {"request": request})

@app.get("/rules", response_class=HTMLResponse)
async def rules(request: Request):
    return templates.TemplateResponse(request, "rules.html", {"request": request})

# Training system routes
import json as _json
from pathlib import Path as _Path

_TRAINING_DATA_PATH = _Path(__file__).parent / "data" / "training_content.json"

def _load_training_data():
    with open(_TRAINING_DATA_PATH, "r", encoding="utf-8") as f:
        return _json.load(f)

def _get_user_role(request: Request):
    """Return the user's role for training access control.
    Returns: None (not logged in), 'user' (regular user), or 'admin' (admin/master admin).
    """
    username = request.session.get("username")
    if not username:
        return None
    if request.session.get("is_admin") is True or request.session.get("is_master_admin") is True:
        return "admin"
    return "user"

def _get_back_url(user_role):
    """Return the URL to go back to based on user role."""
    if user_role == "admin":
        return "/admin"
    if user_role == "user":
        return "/user_panel"
    return "/login"

def _category_accessible(cat_slug: str, user_role) -> bool:
    """Check if a user can access a training category based on their role."""
    if user_role is None:
        return cat_slug == "general"
    if user_role == "user":
        return cat_slug in ("general", "user")
    if user_role == "admin":
        return True
    return False

@app.get("/training", response_class=HTMLResponse)
async def training_hub(request: Request):
    data = _load_training_data()
    user_role = _get_user_role(request)
    from_login = request.query_params.get("from_login") == "true"
    return templates.TemplateResponse(request, "training.html", {
        "request": request,
        "training_data": data,
        "active_category": None,
        "user_role": user_role,
        "back_url": _get_back_url(user_role),
        "from_login": from_login,
    })

@app.get("/training/{category}", response_class=HTMLResponse)
async def training_category(request: Request, category: str):
    data = _load_training_data()
    user_role = _get_user_role(request)
    if category not in data.get("categories", {}):
        return templates.TemplateResponse(request, "training.html", {
            "request": request,
            "training_data": data,
            "active_category": None,
            "user_role": user_role,
            "back_url": _get_back_url(user_role),
            "error": "دسته‌بندی مورد نظر پیدا نشد.",
        })
    if not _category_accessible(category, user_role):
        return templates.TemplateResponse(request, "training.html", {
            "request": request,
            "training_data": data,
            "active_category": None,
            "user_role": user_role,
            "back_url": _get_back_url(user_role),
            "error": "شما به این بخش آموزش دسترسی ندارید. لطفاً ابتدا وارد پنل مربوطه شوید.",
        })
    return templates.TemplateResponse(request, "training.html", {
        "request": request,
        "training_data": data,
        "active_category": category,
        "user_role": user_role,
        "back_url": _get_back_url(user_role),
    })

@app.get("/training/lesson/{lesson_id}", response_class=HTMLResponse)
async def training_lesson(request: Request, lesson_id: str):
    data = _load_training_data()
    user_role = _get_user_role(request)
    lesson = data.get("lessons", {}).get(lesson_id)
    if not lesson:
        return templates.TemplateResponse(request, "training-lesson.html", {
            "request": request,
            "lesson": None,
            "training_data": data,
            "user_role": user_role,
            "back_url": _get_back_url(user_role),
            "error": "آموزش مورد نظر پیدا نشد.",
        })
    lesson_cat = lesson.get("category", "")
    if not _category_accessible(lesson_cat, user_role):
        return templates.TemplateResponse(request, "training-lesson.html", {
            "request": request,
            "lesson": None,
            "training_data": data,
            "user_role": user_role,
            "back_url": _get_back_url(user_role),
            "error": "شما به این آموزش دسترسی ندارید. لطفاً ابتدا وارد پنل مربوطه شوید.",
        })
    return templates.TemplateResponse(request, "training-lesson.html", {
        "request": request,
        "lesson": lesson,
        "training_data": data,
        "user_role": user_role,
        "back_url": _get_back_url(user_role),
        "error": None,
    })

@app.get("/api/training/search")
async def training_search(request: Request, q: str = Query("")):
    data = _load_training_data()
    user_role = _get_user_role(request)
    results = []
    query = q.strip().lower()
    if not query:
        return JSONResponse(content={"success": True, "results": []})
    for lid, lesson in data.get("lessons", {}).items():
        lesson_cat = lesson.get("category", "")
        if not _category_accessible(lesson_cat, user_role):
            continue
        searchable = " ".join([
            lesson.get("title", ""),
            lesson.get("description", ""),
            lesson_cat,
        ]).lower()
        if query in searchable:
            cat_info = data.get("categories", {}).get(lesson_cat, {})
            results.append({
                "id": lid,
                "title": lesson.get("title", ""),
                "description": lesson.get("description", ""),
                "category": cat_info.get("title", lesson_cat),
                "role": lesson.get("role", "general"),
                "icon": lesson.get("icon", "📖"),
            })
    return JSONResponse(content={"success": True, "results": results})

# Master Admin Panel — page routes
@app.get("/master-admin", response_class=HTMLResponse)
async def master_admin_root(request: Request):
    return RedirectResponse(url="/master-admin/dashboard", status_code=303)

@app.get("/master-admin/{section}", response_class=HTMLResponse)
async def master_admin_page(request: Request, section: str = "dashboard"):
    username = request.session.get('username')
    is_ma = request.session.get('is_master_admin') is True
    is_admin = request.session.get('is_admin') is True
    if not username:
        return RedirectResponse(url="/login", status_code=303)
    if not (is_ma or is_admin):
        return RedirectResponse(url="/admin", status_code=303)
    return templates.TemplateResponse(request, "master-admin.html", {
        "request": request,
        "username": username,
        "active_section": section,
    })

@app.get("/call-display", response_class=HTMLResponse)
async def call_display(request: Request):
    """TV display page for the sample collection call system."""
    return templates.TemplateResponse(request, "call-display.html", {"request": request})

@app.get("/call-management", response_class=HTMLResponse)
async def call_management(request: Request):
    """Standalone call management page — no authentication required."""
    return templates.TemplateResponse(request, "call-management.html", {"request": request})

@app.get("/ticket-kiosk", response_class=HTMLResponse)
async def ticket_kiosk(request: Request):
    """Touch-screen kiosk for visitors to take a ticket."""
    return templates.TemplateResponse(request, "ticket-kiosk.html", {"request": request})

@app.get("/ticket-print", response_class=HTMLResponse)
async def ticket_print_page(request: Request):
    """Page for label printing after ticket is issued."""
    return templates.TemplateResponse(request, "ticket-print.html", {"request": request})

# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری
# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری
# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری# روت مربوط به پنل کاربری

@app.get("/user_panel")
async def user_panel(request: Request):
    username = request.session.get('username')
    if not username:
        return RedirectResponse(url="/login", status_code=303)

    today = datetime.now()
    current_year = today.year
    current_month = today.month

    # دریافت گزارش مرخصی
    cursor.execute("""
        SELECT total_days, remaining_days FROM leave_report WHERE username = ?
    """, (username,))
    report = cursor.fetchone()

    approved_count = report[0] if report else 0
    remaining_count = report[1] if report else 0

    approved_count_farsi = convert_to_persian_numbers(approved_count)
    remaining_count_farsi = convert_to_persian_numbers(remaining_count)

    # دریافت اطلاعات اضافه‌کاری
    cursor.execute("""
        SELECT overtime_date, daily_overtime, status, description FROM ezafe_table WHERE username = ?
    """, (username,))
    all_overtime_records = cursor.fetchall()

    overtime_records = []
    for idx, record in enumerate(all_overtime_records, start=1):
        overtime_date_shamsi = jdatetime.date.fromgregorian(date=record[0]).strftime('%Y/%m/%d')
        overtime_time = record[1].strftime('%H:%M')
        overtime_records.append({
            'index': convert_to_persian_numbers(idx),
            'date': convert_to_persian_numbers(overtime_date_shamsi),
            'time': convert_to_persian_numbers(overtime_time),
            'status': record[2],
            'description': record[3]
        })

    # تیکت‌ها در مرکز جدید از API نرمال‌شده بارگذاری می‌شوند؛ این صفحه نباید
    # جدول قدیمی را دوباره از ticket_table بخواند یا داده را در HTML تکرار کند.
    ticket_records = []

    # اطلاعات مرخصی‌های کاربر
    cursor.execute("""
        SELECT start_date, end_date, days, status
        FROM mrkhc_table
        WHERE username = ?
        ORDER BY id DESC
    """, (username,))
    all_leave_records = cursor.fetchall()

    leave_details = []
    for record in all_leave_records:
        start_date = record[0]
        end_date = record[1]

        formatted_start = 'نامعتبر'
        if start_date:
            try:
                parsed_start = datetime.strptime(start_date, '%Y-%m-%d') if isinstance(start_date, str) else start_date
                formatted_start = jdatetime.date.fromgregorian(date=parsed_start).strftime('%Y/%m/%d')
            except Exception:
                formatted_start = 'نامعتبر'

        formatted_end = 'نامعتبر'
        if end_date:
            try:
                parsed_end = datetime.strptime(end_date, '%Y-%m-%d') if isinstance(end_date, str) else end_date
                formatted_end = jdatetime.date.fromgregorian(date=parsed_end).strftime('%Y/%m/%d')
            except Exception:
                formatted_end = 'نامعتبر'

        leave_details.append({
            'start_date': convert_to_persian_numbers(formatted_start),
            'end_date': convert_to_persian_numbers(formatted_end),
            'days': record[2],
            'status': record[3] if record[3] else 'انتظار تایید'
        })

    # اطلاعات پاس‌ها
    cursor.execute("""
        SELECT request_date, pass_title, pass_duration, status FROM totalpass_table WHERE username = ?
    """, (username,))
    all_pass_records = cursor.fetchall()

    pass_records = []
    for idx, record in enumerate(all_pass_records, start=1):
        duration = record[2]
        total_seconds = duration.hour * 3600 + duration.minute * 60 + duration.second
        hours, minutes = total_seconds // 3600, (total_seconds % 3600) // 60
        formatted_duration = f"{hours:02}:{minutes:02}"
        pass_records.append({
            'index': convert_to_persian_numbers(idx),
            'date': convert_to_persian_numbers(jdatetime.date.fromgregorian(date=record[0]).strftime('%Y/%m/%d')),
            'title': format_pass_title(record[1]),
            'duration': convert_to_persian_numbers(formatted_duration),
            'status': record[3]
        })

    # مجموع زمان پاس‌های تایید شده
    cursor.execute("""
        SELECT pass_duration FROM totalpass_table WHERE username = ? AND status = 'تایید شده'
    """, (username,))
    approved_pass_records = cursor.fetchall()
    total_seconds = sum(r[0].hour * 3600 + r[0].minute * 60 + r[0].second for r in approved_pass_records)
    total_approved_hours = total_seconds // 3600
    total_approved_minutes = (total_seconds % 3600) // 60
    total_approved_duration = f"{total_approved_hours:02}:{total_approved_minutes:02}"
    
    # محاسبه مجموع زمان‌های daily_overtime تایید شده
    cursor.execute("""
        SELECT daily_overtime FROM ezafe_table 
        WHERE username = ? AND status = N'تایید شده'
    """, (username,))
    records = cursor.fetchall()

    total_minutes = 0
    total_seconds = 0

    for record in records:
        overtime = record[0]
        total_minutes += overtime.hour * 60 + overtime.minute
        total_seconds += overtime.second

    total_minutes += total_seconds // 60
    total_seconds %= 60
    total_hours = total_minutes // 60
    remaining_minutes = total_minutes % 60

    # ساخت رشته نهایی برای نمایش
    formatted_time = f"{total_hours:02}:{remaining_minutes:02}"  # اگر نمی‌خوای ثانیه‌ها نمایش داده بشن

    # تبدیل به فارسی در صورت نیاز
    formatted_time_farsi = convert_to_persian_numbers(formatted_time)

    cursor.execute("""
    SELECT pass_duration FROM totalpass_table 
    WHERE username = ? AND status = N'تاييد شده'
    """, (username,))
    records = cursor.fetchall()

    total_seconds = 0
    for row in records:
        duration_str = str(row[0])  # مثلاً '00:15:00'
        h, m, s = map(int, duration_str.split(':'))
        total_seconds += h * 3600 + m * 60 + s

    total_pass_duration = str(timedelta(seconds=total_seconds))

    cursor.execute("SELECT profile_image FROM user_table WHERE username = ?", (username,))
    profile_image_row = cursor.fetchone()
    user_image_url = f"/static/uploads/{profile_image_row[0]}" if profile_image_row and profile_image_row[0] else None

    # محاسبه وضعیت امروز برای کارت رویداد امروز
    presence_summary = {
        "check_in_time": "--:--",
        "check_out_time": "--:--",
        "today_work_hours": "۰۰:۰۰",
        "overtime_hours": "۰۰:۰۰",
        "ring_green_percent": 0,
        "ring_blue_percent": 0,
        "ring_mode": "green",
        "entry_time": None,
        "work_start_time": None,
        "work_end_time": None,
        "server_now_time": None,
    }

    try:
        cursor.execute("""
            SELECT hozoor_num, work_hours, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh
            FROM user_table
            WHERE username = ?
        """, (username,))
        user_row = cursor.fetchone()
        if user_row and isinstance(user_row, (tuple, list)) and len(user_row) >= 8:
            hozoor_num, default_work_hours, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh = user_row
            weekday_map = {0: shanbeh, 1: yekshanbeh, 2: doshanbeh, 3: seshanbeh, 4: chrshanbeh, 5: panjshanbeh}

            cursor.execute("""
                SELECT jalali_year, jalali_month, start_day, end_day,
                       shanbeh, yekshanbeh, doshanbeh, seshanbeh, chaharshanbeh, panjshanbeh, jomeh
                FROM shiftha
                WHERE REPLACE(REPLACE(LTRIM(RTRIM(username)), N'ي', N'ی'), N'ك', N'ک') = ?
                ORDER BY jalali_year, jalali_month, start_day
            """, (_normalize_fa_username(username),))
            shift_rows = [{
                'jalali_year': r[0], 'jalali_month': r[1], 'start_day': r[2], 'end_day': r[3],
                'shanbeh': r[4], 'yekshanbeh': r[5], 'doshanbeh': r[6], 'seshanbeh': r[7],
                'chaharshanbeh': r[8], 'panjshanbeh': r[9], 'jomeh': r[10]
            } for r in cursor.fetchall()]

            today_jalali = JalaliDate.today()
            sh_year, sh_month, sh_day = today_jalali.year, today_jalali.month, today_jalali.day
            weekday = jdatetime.date(sh_year, sh_month, sh_day).weekday()

            def resolve_work_hours(sh_year, sh_month, sh_day, wd):
                for row in shift_rows:
                    if row['jalali_year'] == sh_year and row['jalali_month'] == sh_month and row['start_day'] <= sh_day <= row['end_day']:
                        val = row.get(SHIFT_DAY_COLUMNS[wd])
                        if val:
                            return val
                        break
                return weekday_map.get(wd, default_work_hours)

            work_hours = resolve_work_hours(sh_year, sh_month, sh_day, weekday).replace(" ", "")
            work_start, work_end = work_hours.split("-") if "-" in work_hours else ("00:00", "00:00")

            entry_time = None
            try:
                mdb_path = config("ARAZ_ACCESS_PATH", default=r"E:\Hastama\database\Arazdb.mdb")
                password = config("ARAZ_ACCESS_PASSWORD", default="")
                if not password:
                    raise RuntimeError("ARAZ_ACCESS_PASSWORD environment variable not configured")
                conn_str = (r"DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};"
                            rf"DBQ={mdb_path};"
                            rf"PWD={password};")
                conn_access = pyodbc.connect(conn_str)
                cursor_access = conn_access.cursor()
                cursor_access.execute("""
                    SELECT TOP 1 Time
                    FROM TPrsInOut
                    WHERE CardNo = ? AND Date = ?
                    ORDER BY Time ASC
                """, (hozoor_num, today_jalali.strftime('%Y/%m/%d')))
                access_row = cursor_access.fetchone()
                conn_access.close()
                if access_row:
                    entry_time = str(access_row[0]).zfill(4) if access_row[0] is not None else None
                    if len(entry_time) == 4 and entry_time.isdigit():
                        entry_time = f"{entry_time[:2]}:{entry_time[2:]}"
            except Exception:
                entry_time = None

            if not entry_time:
                cursor.execute("""
                    SELECT TOP 1 vrood
                    FROM hozoor
                    WHERE username = ? AND [date] = ?
                    ORDER BY [date]
                """, (username, today_jalali.to_gregorian()))
                row_sql = cursor.fetchone()
                if row_sql and row_sql[0]:
                    entry_time = row_sql[0].strftime('%H:%M') if isinstance(row_sql[0], time) else str(row_sql[0])

            if entry_time:
                default_today_work_hours = (weekday_map.get(weekday) or default_work_hours or "").replace(" ", "")
                if (
                    default_today_work_hours
                    and default_today_work_hours != work_hours
                    and not time_is_inside_range(entry_time, work_hours)
                    and time_is_inside_range(entry_time, default_today_work_hours)
                ):
                    work_hours = default_today_work_hours
                    work_start, work_end = work_hours.split("-", 1)

                presence_summary = build_presence_summary(
                    entry_time=entry_time,
                    work_start=work_start,
                    work_end=work_end,
                    now_time=datetime.now().strftime('%H:%M'),
                )
                presence_summary['entry_time'] = entry_time
                presence_summary['work_start_time'] = presence_summary.get('normalized_work_start', work_start)
                presence_summary['work_end_time'] = presence_summary.get('normalized_work_end', work_end)
                presence_summary['server_now_time'] = datetime.now().strftime('%H:%M:%S')
                presence_summary['today_work_hours'] = convert_to_persian_numbers(presence_summary['today_work_hours'])
                presence_summary['overtime_hours'] = convert_to_persian_numbers(presence_summary['overtime_hours'])
                presence_summary['check_in_time'] = convert_to_persian_numbers(presence_summary['check_in_time'])
                presence_summary['check_out_time'] = convert_to_persian_numbers(presence_summary['check_out_time'])
    except Exception as exc:
        print("presence summary error:", exc)

    template_context = {
        "request": request,
        "approved_count": approved_count_farsi,
        "remaining_count": remaining_count_farsi,
        "overtime_records": overtime_records,
        "formatted_overtime": formatted_time_farsi,
        "ticket_records": ticket_records,
        "pass_records": pass_records,
        "leave_details": leave_details,
        "total_approved_duration": convert_to_persian_numbers(total_approved_duration),
        "total_overtime": formatted_time_farsi,
        "total_approved_pass_duration": convert_to_persian_numbers(total_pass_duration),
        "user_image_url": user_image_url,
        "username": username,
        "today_work_hours": presence_summary.get("today_work_hours", "۰۰:۰۰"),
        "check_in_time": presence_summary.get("check_in_time", "--:--"),
        "check_out_time": presence_summary.get("check_out_time", "--:--"),
        "overtime_hours": presence_summary.get("overtime_hours", "۰۰:۰۰"),
        "ring_green_percent": presence_summary.get("ring_green_percent", 0),
        "ring_blue_percent": presence_summary.get("ring_blue_percent", 0),
        "ring_mode": presence_summary.get("ring_mode", "green"),
        "entry_time": presence_summary.get("entry_time"),
        "work_start_time": presence_summary.get("work_start_time"),
        "work_end_time": presence_summary.get("work_end_time"),
        "server_now_time": presence_summary.get("server_now_time"),
    }

    try:
        return templates.TemplateResponse(request, "user-panel.html", template_context)
    except TypeError:
        return templates.TemplateResponse("user-panel.html", template_context)

# تابع مربوط به نمایش تقویم در پنل کاربری# تابع مربوط به نمایش تقویم در پنل کاربری# تابع مربوط به نمایش تقویم در پنل کاربری
# تابع مربوط به نمایش تقویم در پنل کاربری# تابع مربوط به نمایش تقویم در پنل کاربری# تابع مربوط به نمایش تقویم در پنل کاربری
# تابع مربوط به نمایش تقویم در پنل کاربری# تابع مربوط به نمایش تقویم در پنل کاربری# تابع مربوط به نمایش تقویم در پنل کاربری

class DateResponse(BaseModel):
    year: int
    month: int
    day: int

@app.get("/api/date", response_model=DateResponse)
async def get_date():
    now = jdatetime.datetime.now()  # تاریخ شمسی جاری
    return DateResponse(year=now.year, month=now.month, day=now.day)

# تابع مربوط به دریافت اطلاعات کاربر# تابع مربوط به دریافت اطلاعات کاربر# تابع مربوط به دریافت اطلاعات کاربر# تابع مربوط به دریافت اطلاعات کاربر
# تابع مربوط به دریافت اطلاعات کاربر# تابع مربوط به دریافت اطلاعات کاربر# تابع مربوط به دریافت اطلاعات کاربر# تابع مربوط به دریافت اطلاعات کاربر
# تابع مربوط به دریافت اطلاعات کاربر# تابع مربوط به دریافت اطلاعات کاربر# تابع مربوط به دریافت اطلاعات کاربر# تابع مربوط به دریافت اطلاعات کاربر

@app.get("/get_users")
async def get_users(request: Request):
    actor, auth_error = _ticket_actor(request)
    if auth_error:
        return auth_error

    conn = None
    cursor = None
    try:
        # اتصال به دیتابیس SQL Server
        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()

        # بازیابی اطلاعات کاربران
        cursor.execute("""
            SELECT LTRIM(RTRIM(username)), name
            FROM user_table
            WHERE LTRIM(RTRIM(username)) <> ?
            ORDER BY LTRIM(RTRIM(username))
        """, (actor,))
        users = cursor.fetchall()

        # قالب‌بندی داده‌ها برای ارسال به کلاینت
        user_list = [
            {'value': str(user[0]).strip(), 'label': user[1]}
            for user in users if user[0]
        ]

        return JSONResponse(content={'success': True, 'users': user_list})

    except Exception:
        return JSONResponse(content={'success': False, 'message': 'خطا در دریافت کاربران.'}, status_code=500)

    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()

# تابع مربوط به دریافت اطلاعات کاربر از سشن# تابع مربوط به دریافت اطلاعات کاربر از سشن# تابع مربوط به دریافت اطلاعات کاربر از سشن
# تابع مربوط به دریافت اطلاعات کاربر از سشن# تابع مربوط به دریافت اطلاعات کاربر از سشن# تابع مربوط به دریافت اطلاعات کاربر از سشن
# تابع مربوط به دریافت اطلاعات کاربر از سشن# تابع مربوط به دریافت اطلاعات کاربر از سشن# تابع مربوط به دریافت اطلاعات کاربر از سشن

@app.get("/get_user_info")
async def get_user_info(request: Request):
    # دریافت نام کاربری از سشن
    username = request.session.get('username')

    if not username:
        return JSONResponse(content={'success': False, 'message': 'نام کاربری پیدا نشد'})

    try:
        # اتصال به دیتابیس SQL Server
        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()

        # جلب اطلاعات فردی از جدول user_table
        cursor.execute("""
            SELECT name, last_name, department, work_hours, substitute
            FROM user_table
            WHERE username = ?
        """, (username,))

        user_info = cursor.fetchone()

        if user_info:
            return JSONResponse(content={
                'success': True,
                'data': {
                    'name': user_info[0],
                    'last_name': user_info[1],
                    'department': user_info[2],
                    'work_hours': user_info[3],
                    'substitute': user_info[4]
                }
            })
        else:
            return JSONResponse(content={'success': False, 'message': 'اطلاعات کاربر پیدا نشد'})

    except Exception as e:
        return JSONResponse(content={'success': False, 'message': _safe_error_message(e)})

    finally:
        cursor.close()
        conn.close()

# تابع دریافت اطلاعات کاربر برای صفحه های گزارش انفرادی مرخصی و اضافه کاری
@app.get("/get_user_info_report")
async def get_user_info_report(request: Request, username: str = Query(...)):
    auth_err = _require_auth(request)
    if auth_err:
        return auth_err
    if not username:
        return JSONResponse(content={'success': False, 'message': 'نام کاربری ارائه نشده است'})

    # IDOR protection: users can only access their own data; admins can access any
    session_user = request.session.get("username", "")
    is_admin = request.session.get("is_admin") is True
    if not is_admin and session_user.strip().lower() != username.strip().lower():
        return JSONResponse(status_code=403, content={'success': False, 'message': 'دسترسی غیرمجاز'})

    try:
        # اتصال به دیتابیس SQL Server
        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()

        # جلب اطلاعات فردی از جدول user_table
        cursor.execute("""
            SELECT name, last_name, department, work_hours, substitute
            FROM user_table
            WHERE username = ?
        """, (username,))

        user_info = cursor.fetchone()

        if user_info:
            return JSONResponse(content={
                'success': True,
                'data': {
                    'name': user_info[0],
                    'last_name': user_info[1],
                    'department': user_info[2],
                    'work_hours': user_info[3],
                    'substitute': user_info[4]
                }
            })
        else:
            return JSONResponse(content={'success': False, 'message': 'اطلاعات کاربر پیدا نشد'})

    except Exception as e:
        return JSONResponse(content={'success': False, 'message': _safe_error_message(e)})

    finally:
        cursor.close()
        conn.close()

# روت مربوط به دریافت اطلاعات مرخصی# روت مربوط به دریافت اطلاعات مرخصی# روت مربوط به دریافت اطلاعات مرخصی# روت مربوط به دریافت اطلاعات مرخصی
# روت مربوط به دریافت اطلاعات مرخصی# روت مربوط به دریافت اطلاعات مرخصی# روت مربوط به دریافت اطلاعات مرخصی# روت مربوط به دریافت اطلاعات مرخصی
# روت مربوط به دریافت اطلاعات مرخصی# روت مربوط به دریافت اطلاعات مرخصی# روت مربوط به دریافت اطلاعات مرخصی# روت مربوط به دریافت اطلاعات مرخصی

@app.get("/get_leave_info")
async def get_leave_info(request: Request):
    username = request.session.get('username')  # دریافت نام کاربری از سشن

    if not username:
        print("نام کاربری پیدا نشد")  # چاپ برای بررسی
        raise HTTPException(status_code=400, detail="نام کاربری پیدا نشد")

    try:
        # اتصال به دیتابیس SQL Server
        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()

        # جلب اطلاعات مرخصی‌های کاربر از جدول mrkhc_table
        cursor.execute("""
            SELECT start_date, end_date, days, status
            FROM mrkhc_table
            WHERE username = ?
        """, (username,))

        leave_info = cursor.fetchall()

        # اگر اطلاعات مرخصی وجود داشته باشد
        if leave_info:
            leaves = []
            for row in leave_info:
                
                # تبدیل تاریخ شروع
                start_date_shamsi = 'نامعتبر'
                if row[0]:
                    try:
                        # بررسی فرمت تاریخ میلادی
                        start_date = datetime.strptime(row[0], '%Y-%m-%d') if isinstance(row[0], str) else row[0]
                        # تبدیل تاریخ میلادی به شمسی
                        start_date_shamsi = jdatetime.date.fromgregorian(date=start_date).strftime('%Y/%m/%d')  
                    except Exception as e:
                        start_date_shamsi = 'نامعتبر'

                # تبدیل تاریخ پایان
                end_date_shamsi = 'نامعتبر'
                if row[1]:
                    try:
                        # بررسی فرمت تاریخ میلادی
                        end_date = datetime.strptime(row[1], '%Y-%m-%d') if isinstance(row[1], str) else row[1]
                        # تبدیل تاریخ میلادی به شمسی
                        end_date_shamsi = jdatetime.date.fromgregorian(date=end_date).strftime('%Y/%m/%d')  
                    except Exception as e:
                        end_date_shamsi = 'نامعتبر'

                # اضافه کردن اطلاعات به لیست
                leaves.append({
                    'start_date': start_date_shamsi,
                    'end_date': end_date_shamsi,
                    'days': row[2],
                    'status': row[3] if row[3] else 'انتظار تایید'
                })

            return JSONResponse(content={
                'success': True,
                'data': leaves
            })
        else:
            # اگر هیچ اطلاعاتی یافت نشد، پیام را به صورت خالی ارسال می‌کنیم
            return JSONResponse(content={
                'success': True,
                'data': []
            })

    except Exception as e:
        raise HTTPException(status_code=500, detail="خطای داخلی سرور.")

    finally:
        cursor.close()
        conn.close()

# تابع مربوط به دریافت لیست دریافت کنندگان# تابع مربوط به دریافت لیست دریافت کنندگان# تابع مربوط به دریافت لیست دریافت کنندگان
# تابع مربوط به دریافت لیست دریافت کنندگان# تابع مربوط به دریافت لیست دریافت کنندگان# تابع مربوط به دریافت لیست دریافت کنندگان
# تابع مربوط به دریافت لیست دریافت کنندگان# تابع مربوط به دریافت لیست دریافت کنندگان# تابع مربوط به دریافت لیست دریافت کنندگان

@app.get("/get_receivers")
async def get_receivers(request: Request):
    actor, auth_error = _ticket_actor(request)
    if auth_error:
        return auth_error

    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        master_usernames = {
            item.strip().casefold()
            for item in os.getenv("MASTER_ADMIN_USERNAMES", "ali").split(",")
            if item.strip()
        }
        cursor.execute("""
            SELECT LTRIM(RTRIM(username))
            FROM user_table
            WHERE LTRIM(RTRIM(username)) <> ?
            ORDER BY LTRIM(RTRIM(username))
        """, (actor,))
        receiver_list = [
            str(row[0]).strip()
            for row in cursor.fetchall()
            if row[0] and str(row[0]).strip().casefold() in master_usernames
        ]
        return JSONResponse(content=receiver_list)
    except Exception:
        return JSONResponse(content={"success": False, "message": "خطا در دریافت کاربران."}, status_code=500)
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()

# تابع ثبت درخواست مرخصی کاربر # تابع ثبت درخواست مرخصی کاربر # تابع ثبت درخواست مرخصی کاربر # تابع ثبت درخواست مرخصی کاربر
# تابع ثبت درخواست مرخصی کاربر # تابع ثبت درخواست مرخصی کاربر # تابع ثبت درخواست مرخصی کاربر # تابع ثبت درخواست مرخصی کاربر
# تابع ثبت درخواست مرخصی کاربر # تابع ثبت درخواست مرخصی کاربر # تابع ثبت درخواست مرخصی کاربر # تابع ثبت درخواست مرخصی کاربر

def persian_to_english_digits(text):
    persian_digits = '۰۱۲۳۴۵۶۷۸۹'
    english_digits = '0123456789'
    translation_table = str.maketrans(persian_digits, english_digits)
    return text.translate(translation_table)


def _notify_admins_new_request(kind, username, details):
    # درخواست‌های عملیاتی فقط برای حساب‌های دارای نقش admin ارسال می‌شوند.
    labels = {
        'leave': 'مرخصی',
        'overtime': 'اضافه‌کاری',
        'hourly_pass': 'پاس ساعتی',
    }
    label = labels.get(kind, 'جدید')
    publish_system_notification_to_admins(
        conn,
        f'درخواست جدید {label}',
        f'کاربر «{str(username).strip()}» یک درخواست {label} ثبت کرد. {details}'.strip(),
        notification_type='information',
        priority='important',
        action_url='/admin',
    )


# Tables this helper is allowed to look up.  The table name is part of the SQL
# text (identifiers cannot be parameterised), so it is restricted to a fixed
# allow-list instead of being interpolated blindly.
_NOTIFY_STATUS_TABLES = frozenset({"mrkhc_table", "totalpass_table", "ezafe_table"})


def _notify_requester_status(table, request_id, new_status, label):
    if table not in _NOTIFY_STATUS_TABLES:
        logger.error("refusing to look up request owner in unexpected table %r", table)
        return
    notification_cursor = conn.cursor()
    notification_cursor.execute(
        f'SELECT TOP 1 LTRIM(RTRIM(username)) FROM {table} WHERE id = ?',
        (request_id,),
    )
    row = notification_cursor.fetchone()
    username = str(row[0]).strip() if row and row[0] else ''
    if not username:
        return
    status = str(new_status or 'انتظار تایید').strip()
    kind = 'success' if status == 'تایید شده' else ('warning' if status == 'رد شده' else 'information')
    publish_system_notification(
        conn,
        f'تغییر وضعیت درخواست {label}',
        f'وضعیت درخواست {label} شما به «{status}» تغییر کرد.',
        [username],
        notification_type=kind,
        action_url='/user_panel',
    )


def _pass_duration(start_value, end_value):
    start = datetime.strptime(str(start_value), '%H:%M')
    end = datetime.strptime(str(end_value), '%H:%M')
    if end < start:
        end += timedelta(days=1)
    seconds = int((end - start).total_seconds())
    return f'{seconds // 3600:02d}:{(seconds % 3600) // 60:02d}:00'


@app.post("/submit_leave")
async def submit_leave(
    request: Request,
    startDate: str = Form(...),
    endDate: str = Form(...),
    days: str = Form(...),
    substitute: str = Form(...)
):
    session = request.session
    username = session.get("username")
    if not username:
        return JSONResponse(content={"success": False, "message": "User not logged in"})

    # The substitute name is shown in the leave reports, so markup is refused at
    # the source as well as escaped at the render layer.  Validated before the
    # generic handler below so the operator sees the real reason.
    try:
        substitute = _reject_markup_field(substitute, max_length=100, field="جانشین")
    except ValueError as exc:
        return JSONResponse(content={"success": False, "message": str(exc)})

    try:
        if startDate:
            start_date_persian = jdatetime.date(*map(int, startDate.split('/')))
            start_date_gregorian = start_date_persian.togregorian()

        if endDate:
            end_date_persian = jdatetime.date(*map(int, endDate.split('/')))
            end_date_gregorian = end_date_persian.togregorian()

        # تبدیل عدد فارسی به انگلیسی و سپس تبدیل به int
        days_english_str = persian_to_english_digits(days)
        days_int = int(days_english_str)

        try:
            cursor.execute("""
                INSERT INTO mrkhc_table (start_date, end_date, days, substitute, username)
                VALUES (?, ?, ?, ?, ?)
            """, (start_date_gregorian, end_date_gregorian, days_int, substitute, username))
            _notify_admins_new_request('leave', username, f'تعداد روز: {days_int}')
            conn.commit()
            return JSONResponse(content={"success": True, "message": "مرخصی با موفقیت ثبت شد!"})
        except Exception as e:
            print(f"SQL Error: {e}")
            conn.rollback()
            return JSONResponse(content={"success": False, "message": _safe_error_message(e)})

    except ValueError as e:
        print(f"Date or Days Conversion Error: {e}")
        return JSONResponse(content={"success": False, "message": "تاریخ یا تعداد روزها معتبر نیست."})

# تابع ثبت درخواست اضافه کاری کاربر # تابع ثبت درخواست اضافه کاری کاربر # تابع ثبت درخواست اضافه کاری کاربر # تابع ثبت درخواست اضافه کاری کاربر
# تابع ثبت درخواست اضافه کاری کاربر # تابع ثبت درخواست اضافه کاری کاربر # تابع ثبت درخواست اضافه کاری کاربر # تابع ثبت درخواست اضافه کاری کاربر
# تابع ثبت درخواست اضافه کاری کاربر # تابع ثبت درخواست اضافه کاری کاربر # تابع ثبت درخواست اضافه کاری کاربر # تابع ثبت درخواست اضافه کاری کاربر

@app.post("/submit_overtime")
async def submit_overtime(
    request: Request,
    overtimeDate: str = Form(...),
    fromTime: str = Form(...),
    toTime: str = Form(...),
    description: str = Form(...)
):
    session = request.session
    username = session.get("username")
    if not username:
        return JSONResponse(content={"success": False, "message": "User not logged in"})

    # These three fields are typed by the employee and later rendered in the
    # overtime / final reports, so they must not carry markup.
    try:
        description = _reject_markup_field(description, max_length=500, field="شرح اضافه‌کاری", required=True)
        fromTime = _reject_markup_field(fromTime, max_length=20, field="ساعت شروع", required=True)
        toTime = _reject_markup_field(toTime, max_length=20, field="ساعت پایان", required=True)
    except ValueError as exc:
        return JSONResponse(content={"success": False, "message": str(exc)})

    try:
        shamsi_parts = overtimeDate.split('/')
        if len(shamsi_parts) != 3:
            return JSONResponse(content={"success": False, "message": "فرمت تاریخ نامعتبر است"})

        year, month, day = map(int, shamsi_parts)
        gregorian_date = jdatetime.date(year, month, day).togregorian()

        query = """
            INSERT INTO ezafe_table (overtime_date, from_time, to_time, description, username, status)
            VALUES (?, ?, ?, ?, ?, ?)
        """
        cursor.execute(query, (gregorian_date, fromTime, toTime, description, username, 'انتظار تایید'))
        _notify_admins_new_request('overtime', username, f'شرح: {description}')
        conn.commit()

        return JSONResponse(content={"success": True, "message": "اضافه‌کار با موفقیت ثبت شد!"})

    except Exception as e:
        conn.rollback()
        return JSONResponse(content={"success": False, "message": _safe_error_message(e)})

# تابع ثبت درخواست پاس ساعتی کاربر # تابع ثبت درخواست پاس ساعتی کاربر # تابع ثبت درخواست پاس ساعتی کاربر # تابع ثبت درخواست پاس ساعتی کاربر
# تابع ثبت درخواست پاس ساعتی کاربر # تابع ثبت درخواست پاس ساعتی کاربر # تابع ثبت درخواست پاس ساعتی کاربر # تابع ثبت درخواست پاس ساعتی کاربر
# تابع ثبت درخواست پاس ساعتی کاربر # تابع ثبت درخواست پاس ساعتی کاربر # تابع ثبت درخواست پاس ساعتی کاربر # تابع ثبت درخواست پاس ساعتی کاربر

def persian_to_english_numbers(persian_str):
    persian_digits = '۰۱۲۳۴۵۶۷۸۹'
    english_digits = '0123456789'
    translation_table = str.maketrans(''.join(persian_digits), ''.join(english_digits))
    return persian_str.translate(translation_table)

@app.post("/submit_hourly_pass")
async def submit_hourly_pass(request: Request):
    session = request.session
    username = session.get("username")
    if not username:
        return JSONResponse(content={"success": False, "message": "User not logged in"})

    data = await request.json()
    officialTime = data.get('officialTime')
    entryTime = data.get('entryTime')
    exitTime = data.get('exitTime')
    date = data.get('date')  # تاریخ شمسی

    try:
        # تبدیل تاریخ
        if date:
            date_persian = jdatetime.date(*map(int, date.split('/')))
            date_gregorian = date_persian.togregorian()
            date = date_gregorian.strftime('%Y-%m-%d')

        # تبدیل اعداد فارسی به انگلیسی
        if officialTime:
            officialTime = persian_to_english_numbers(officialTime)
        if entryTime:
            entryTime = persian_to_english_numbers(entryTime)
        if exitTime:
            exitTime = persian_to_english_numbers(exitTime)

        pass_requests = []

        # پاس اول وقت
        if officialTime and entryTime:
            cursor.execute("""
                INSERT INTO avalpss_table (officialTime, entryTime, date, username)
                VALUES (?, ?, ?, ?)
            """, (officialTime, entryTime, date, username))
            pass_requests.append(('avalpss', _pass_duration(officialTime, entryTime)))

        # پاس بین وقت
        if entryTime and exitTime:
            cursor.execute("""
                INSERT INTO beynpss_table (entryTime, exitTime, date, username)
                VALUES (?, ?, ?, ?)
            """, (entryTime, exitTime, date, username))
            pass_requests.append(('beynpss', _pass_duration(entryTime, exitTime)))

        # پاس آخر وقت
        if officialTime and exitTime:
            cursor.execute("""
                INSERT INTO akhrpss_table (officialTime, exitTime, date, username)
                VALUES (?, ?, ?, ?)
            """, (officialTime, exitTime, date, username))
            pass_requests.append(('akhrpss', _pass_duration(officialTime, exitTime)))

        # این جدول صف تأیید مدیریت است؛ بدون آن، درخواست تازه در پنل مدیر دیده نمی‌شود.
        for pass_title, pass_duration in pass_requests:
            cursor.execute("""
                INSERT INTO totalpass_table (username, request_date, pass_title, pass_duration, status)
                VALUES (?, ?, ?, ?, N'انتظار تایید')
            """, (username, date, pass_title, pass_duration))

        if pass_requests:
            _notify_admins_new_request(
                'hourly_pass',
                username,
                'نوع پاس: ' + '، '.join(pass_title for pass_title, _ in pass_requests),
            )
        conn.commit()
        return JSONResponse(content={"success": True})

    except Exception as e:
        conn.rollback()
        return JSONResponse(content={"success": False, "message": _safe_error_message(e)})

# ابزارهای مشترک تیکتینگ
TICKET_STATUSES = frozenset({"ارسال شده", "در حال پیگیری", "خوانده شده", "پاسخ داده شده"})
TICKET_TITLE_MAX_LENGTH = 180
TICKET_DESCRIPTION_MAX_LENGTH = 4000


def _ticket_actor(request: Request, admin_only: bool = False):
    """هویت امضاشده‌ی session را برای APIهای تیکت برمی‌گرداند."""
    actor = str(request.session.get("username") or "").strip()
    is_admin = request.session.get("is_admin") is True
    if not actor or (admin_only and not is_admin):
        return None, JSONResponse(status_code=403, content={"success": False, "error": "دسترسی غیرمجاز"})
    return actor, None


def _ticket_text(value, max_length: int, field_name: str):
    value = str(value or "").replace("\x00", "").strip()
    if not value:
        raise ValueError(f"{field_name} الزامی است.")
    if len(value) > max_length:
        raise ValueError(f"طول {field_name} بیشتر از حد مجاز است.")
    return value


def _ticket_date_text(value):
    if not value:
        return "تاریخ نامشخص"
    if isinstance(value, str):
        try:
            value = datetime.fromisoformat(value.replace("Z", "+00:00"))
        except ValueError:
            return value
    return JalaliDate(value).strftime("%Y/%m/%d")


def _ticket_accessible_row(cursor, ticket_id, actor, is_admin):
    cursor.execute("""
        SELECT id, ticketTitle, ticketDescription, username, ticket_date,
               ticket_status, Parent_id, target_username
        FROM ticket_table
        WHERE id = ?
    """, (ticket_id,))
    ticket = cursor.fetchone()
    if not ticket:
        return None

    if not is_admin:
        cursor.execute("""
            SELECT 1 FROM ticket_table
            WHERE Parent_id = ?
              AND (LTRIM(RTRIM(username)) = ? OR LTRIM(RTRIM(target_username)) = ?)
        """, (ticket[6], actor, actor))
        if cursor.fetchone() is None:
            return None
    return ticket


async def _create_ticket_response(request: Request):
    return JSONResponse(status_code=410, content={"success": False, "error": "این مسیر قدیمی تیکت منسوخ شده است."})
    actor, auth_error = _ticket_actor(request)
    if auth_error:
        return auth_error

    try:
        data = await request.json()
        parent_id = int(data.get("parent_id"))
        description = _ticket_text(data.get("ticketDescription"), TICKET_DESCRIPTION_MAX_LENGTH, "توضیحات")
    except (TypeError, ValueError):
        return JSONResponse(status_code=400, content={"success": False, "error": "اطلاعات پاسخ تیکت معتبر نیست."})
    except Exception:
        return JSONResponse(status_code=400, content={"success": False, "error": "درخواست نامعتبر است."})

    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        is_admin = request.session.get("is_admin") is True
        cursor.execute("""
            SELECT TOP 1 ticketTitle, username, target_username
            FROM ticket_table
            WHERE Parent_id = ?
            ORDER BY ticket_date ASC, id ASC
        """, (parent_id,))
        original = cursor.fetchone()
        if not original:
            return JSONResponse(status_code=404, content={"success": False, "error": "مکالمه تیکت یافت نشد."})

        original_sender = str(original[1] or "").strip()
        original_target = str(original[2] or "").strip()
        if is_admin and actor not in {original_sender, original_target}:
            return JSONResponse(status_code=403, content={"success": False, "error": "دسترسی به این مکالمه مجاز نیست."})
        if not is_admin and actor not in {original_sender, original_target}:
            return JSONResponse(status_code=403, content={"success": False, "error": "دسترسی به این مکالمه مجاز نیست."})

        recipient = original_target if actor == original_sender else original_sender
        if not recipient:
            return JSONResponse(status_code=400, content={"success": False, "error": "گیرنده پاسخ مشخص نیست."})

        cursor.execute("""
            INSERT INTO ticket_table
                (ticketTitle, ticketDescription, username, target_username,
                 parent_id, ticket_date, ticket_status, is_read)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        """, (original[0], description, actor, recipient, parent_id,
              datetime.now(), "پاسخ داده شده", 0))
        conn.commit()
        return JSONResponse(content={"success": True, "message": "پاسخ با موفقیت ارسال شد."})
    except Exception as exc:
        if conn is not None:
            conn.rollback()
        return JSONResponse(status_code=500, content={"success": False, "error": "خطا در ارسال پاسخ تیکت."})
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()


# تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر
# تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر
# تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر # تابع ثبت تیکت کاربر

@app.post("/submit_ticket")
async def submit_ticket(request: Request):
    return JSONResponse(status_code=410, content={"success": False, "error": "این مسیر قدیمی تیکت منسوخ شده است."})
    actor, auth_error = _ticket_actor(request)
    if auth_error:
        return auth_error

    try:
        data = await request.json()
        title = _ticket_text(data.get("ticketTitle"), TICKET_TITLE_MAX_LENGTH, "عنوان تیکت")
        description = _ticket_text(data.get("ticketDescription"), TICKET_DESCRIPTION_MAX_LENGTH, "توضیحات")
        receiver = str(data.get("ticketReceiver") or "").strip()
        if not receiver:
            raise ValueError("دریافت‌کننده تیکت الزامی است.")
        if receiver.casefold() == actor.casefold():
            raise ValueError("ارسال تیکت برای خودتان مجاز نیست.")
    except ValueError as exc:
        return JSONResponse(status_code=400, content={"success": False, "message": str(exc)})
    except Exception:
        return JSONResponse(status_code=400, content={"success": False, "message": "درخواست ثبت تیکت نامعتبر است."})

    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("SELECT TOP 1 username FROM user_table WHERE LTRIM(RTRIM(username)) = ?", (receiver,))
        receiver_row = cursor.fetchone()
        if receiver_row is None:
            return JSONResponse(status_code=404, content={"success": False, "message": "دریافت‌کننده پیدا نشد."})

        # قفل کوتاه‌مدت جدول از تولید Parent_id تکراری در ثبت هم‌زمان جلوگیری می‌کند.
        cursor.execute("SELECT ISNULL(MAX(Parent_id), 0) + 1 FROM ticket_table WITH (TABLOCKX, HOLDLOCK)")
        new_parent_id = int(cursor.fetchone()[0])
        cursor.execute("""
            INSERT INTO ticket_table
                (Parent_id, ticketTitle, ticketDescription, username, target_username,
                 ticket_date, ticket_status, is_read)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        """, (new_parent_id, title, description, actor, receiver,
              datetime.now(), "ارسال شده", 0))
        conn.commit()
        return JSONResponse(status_code=201, content={"success": True, "message": "تیکت با موفقیت ثبت شد."})
    except Exception:
        if conn is not None:
            conn.rollback()
        return JSONResponse(status_code=500, content={"success": False, "message": "خطا در ثبت تیکت."})
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()

# تابع دریافت اطلاعات کاربر برای صفحه گزارش جامع# تابع دریافت اطلاعات کاربر برای صفحه گزارش جامع# تابع دریافت اطلاعات کاربر برای صفحه گزارش جامع
# تابع دریافت اطلاعات کاربر برای صفحه گزارش جامع# تابع دریافت اطلاعات کاربر برای صفحه گزارش جامع# تابع دریافت اطلاعات کاربر برای صفحه گزارش جامع
# تابع دریافت اطلاعات کاربر برای صفحه گزارش جامع# تابع دریافت اطلاعات کاربر برای صفحه گزارش جامع# تابع دریافت اطلاعات کاربر برای صفحه گزارش جامع

@app.get("/get_user_info_final_report_page/{username}")
def get_user_info_final_report_page(request: Request, username: str):
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    try:
        username = username.strip()
        cursor.execute("""
            SELECT name, last_name, department
            FROM user_table
            WHERE RTRIM(username) = ?
        """, username)
        row = cursor.fetchone()

        if row:
            return {
                "name": row.name,
                "last_name": row.last_name,
                "department": row.department
            }
        else:
            raise HTTPException(status_code=404, detail="کاربر یافت نشد")

    except Exception as e:
        raise HTTPException(status_code=500, detail="خطای سرور")

# تابع ثبت دستی حضور و غیاب کاربران# تابع ثبت دستی حضور و غیاب کاربران# تابع ثبت دستی حضور و غیاب کاربران
# تابع ثبت دستی حضور و غیاب کاربران# تابع ثبت دستی حضور و غیاب کاربران# تابع ثبت دستی حضور و غیاب کاربران
# تابع ثبت دستی حضور و غیاب کاربران# تابع ثبت دستی حضور و غیاب کاربران# تابع ثبت دستی حضور و غیاب کاربران

@app.post("/get_hozoor_filtered")
async def get_hozoor_filtered(request: Request, data: dict = Body(...)):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    try:
        username = data.get("username")
        from_date = data.get("from_date")
        to_date = data.get("to_date")

        if not username or not from_date or not to_date:
            return JSONResponse(status_code=400, content={"error": "اطلاعات ناقص است."})

        from_g = JalaliDate.strptime(from_date, '%Y/%m/%d').to_gregorian()
        to_g = JalaliDate.strptime(to_date, '%Y/%m/%d').to_gregorian()

        # فرض بر اینکه نام ستون تاریخ 'date' است
        cursor.execute("""
            SELECT [date], vrood, khoroj FROM hozoor
            WHERE username = ? AND [date] BETWEEN ? AND ?
            ORDER BY [date]
        """, (username, from_g, to_g))

        rows = cursor.fetchall()

        results = []
        for row in rows:
            shamsi = JalaliDate(row[0]).strftime('%Y/%m/%d')
            vrood_str = row[1].strftime('%H:%M') if isinstance(row[1], time) else str(row[1])
            khorooj_str = row[2].strftime('%H:%M') if isinstance(row[2], time) else str(row[2])

            results.append({
                "tarikh": shamsi,
                "vorood": vrood_str,
                "khorooj": khorooj_str
            })

        return JSONResponse(content=results)

    except Exception as e:
        import traceback
        traceback.print_exc()
        return JSONResponse(status_code=500, content={"error": _safe_error_message(e)})

# تابع بارگزاری عکس پروفایل کاربران# تابع بارگزاری عکس پروفایل کاربران# تابع بارگزاری عکس پروفایل کاربران
# تابع بارگزاری عکس پروفایل کاربران# تابع بارگزاری عکس پروفایل کاربران# تابع بارگزاری عکس پروفایل کاربران
# تابع بارگزاری عکس پروفایل کاربران# تابع بارگزاری عکس پروفایل کاربران# تابع بارگزاری عکس پروفایل کاربران

@app.post("/upload-profile-image")
async def upload_profile_image(request: Request, file: UploadFile = File(...)):
    username = request.session.get('username')
    if not username:
        return RedirectResponse(url="/login", status_code=303)

    # ── File type allow-list ──
    ALLOWED_EXTENSIONS = {'.jpg', '.jpeg', '.png', '.gif', '.webp'}
    MAX_FILE_SIZE = 5 * 1024 * 1024  # 5 MB

    original_name = os.path.basename(file.filename or "")  # strip path components
    file_ext = os.path.splitext(original_name)[1].lower()

    if file_ext not in ALLOWED_EXTENSIONS:
        return RedirectResponse(url="/user_panel", status_code=303)

    # Read and enforce size limit
    contents = await file.read()
    if len(contents) > MAX_FILE_SIZE:
        return RedirectResponse(url="/user_panel", status_code=303)

    # ── Magic byte validation ──
    MAGIC_SIGNATURES = {
        '.jpg':  b'\xff\xd8\xff',
        '.jpeg': b'\xff\xd8\xff',
        '.png':  b'\x89PNG',
        '.gif':  b'GIF8',
        '.webp': b'RIFF',
    }
    detected_ext = None
    if contents[:3] == b'\xff\xd8\xff':
        detected_ext = '.jpg'
    elif contents[:4] == b'\x89PNG':
        detected_ext = '.png'
    elif contents[:4] == b'GIF8':
        detected_ext = '.gif'
    elif contents[:4] == b'RIFF' and len(contents) >= 12 and contents[8:12] == b'WEBP':
        detected_ext = '.webp'

    if detected_ext is None or detected_ext != file_ext:
        return RedirectResponse(url="/user_panel", status_code=303)

    # Server-generated filename — never use the user supplied name.  The
    # username is part of the name, so it is reduced to a conservative charset
    # (legacy rows may contain characters the current validators reject) and the
    # final path is verified to stay inside the upload directory.
    safe_username = re.sub(r"[^A-Za-z0-9_.-]", "_", str(username))[:64] or "user"
    safe_filename = f"{safe_username}{file_ext}"
    upload_folder = os.path.join("app", "static", "uploads")
    os.makedirs(upload_folder, exist_ok=True)
    file_path = os.path.abspath(os.path.join(upload_folder, safe_filename))
    if os.path.dirname(file_path) != os.path.abspath(upload_folder):
        return RedirectResponse(url="/user_panel", status_code=303)

    with open(file_path, "wb") as buffer:
        buffer.write(contents)

    # ذخیره مسیر فایل در دیتابیس (فقط نام فایل امن)
    cursor.execute("""
        UPDATE user_table SET profile_image = ? WHERE username = ?
    """, (safe_filename, username))
    conn.commit()

    return RedirectResponse(url="/user_panel", status_code=303)

# تابع حذف عکس پروفایل کاربران# تابع حذف عکس پروفایل کاربران# تابع حذف عکس پروفایل کاربران# تابع حذف عکس پروفایل کاربران
# تابع حذف عکس پروفایل کاربران# تابع حذف عکس پروفایل کاربران# تابع حذف عکس پروفایل کاربران# تابع حذف عکس پروفایل کاربران
# تابع حذف عکس پروفایل کاربران# تابع حذف عکس پروفایل کاربران# تابع حذف عکس پروفایل کاربران# تابع حذف عکس پروفایل کاربران

@app.post("/delete-profile-image")
async def delete_profile_image(request: Request):
    username = request.session.get('username')
    if not username:
        return RedirectResponse(url="/login", status_code=303)

    # دریافت مسیر فعلی عکس
    cursor.execute("SELECT profile_image FROM user_table WHERE username = ?", (username,))
    row = cursor.fetchone()

    if row and row[0]:
        # Path traversal protection: normalize and verify the path is within uploads/
        safe_name = os.path.basename(row[0])
        file_path = os.path.join("app", "static", "uploads", safe_name)
        real_uploads = os.path.realpath(os.path.join("app", "static", "uploads"))
        if os.path.realpath(file_path).startswith(real_uploads + os.sep) and os.path.exists(file_path):
            os.remove(file_path)

    # حذف از دیتابیس
    cursor.execute("UPDATE user_table SET profile_image = NULL WHERE username = ?", (username,))
    conn.commit()

    return RedirectResponse(url="/user_panel", status_code=303)

# تابع مربوط به صفحه مدیریت# تابع مربوط به صفحه مدیریت# تابع مربوط به صفحه مدیریت# تابع مربوط به صفحه مدیریت
# تابع مربوط به صفحه مدیریت# تابع مربوط به صفحه مدیریت# تابع مربوط به صفحه مدیریت# تابع مربوط به صفحه مدیریت
# تابع مربوط به صفحه مدیریت# تابع مربوط به صفحه مدیریت# تابع مربوط به صفحه مدیریت# تابع مربوط به صفحه مدیریت

# مدل‌های داده برای استفاده در API
class ReportData:
    def __init__(self, username, total_used, total_remaining):
        self.username = username
        self.total_used = total_used
        self.total_remaining = total_remaining

class UserData:
    def __init__(self, username, department, work_hours, substitute, name, employment_status="official", is_active="active", password="", last_name=""):
        self.username = username
        self.department = department
        self.work_hours = work_hours
        self.substitute = substitute
        self.name = name
        self.last_name = last_name or ""
        self.employment_status = employment_status or "official"
        self.is_active = (is_active or "active").strip().lower()
        self.password = password or ""

class PassData:
    def __init__(self, row_number, username, total_pass_time):
        self.row_number = row_number
        self.username = username
        self.total_pass_time = total_pass_time

class OvertimeData:
    def __init__(self, row_number, username, total_ezafe_time):
        self.row_number = row_number
        self.username = username
        self.total_ezafe_time = total_ezafe_time

# اتصال به دیتابیس
def get_db_connection():
    """New database connection using the centralised connection string.

    The ODBC string is configurable through ``DATABASE_URL``
    (``app.core.database.connection_string``); the legacy
    ``localhost\\SQLEXPRESS``/``userDB``/``Trusted_Connection=yes`` default is
    kept so existing installations keep working unchanged.
    """
    from app.core.database import connect as _db_connect

    return _db_connect()

# Admin access is resolved from the signed session created by /login_user.
def get_user_from_session(request: Request):
    return str(request.session.get("username") or "").strip() or None

def get_is_admin_from_session(request: Request):
    return request.session.get("is_admin") is True


def _require_admin(request: Request):
    """Return a 403 JSONResponse if the session user is not an admin."""
    if not get_user_from_session(request):
        return JSONResponse(status_code=401, content={"success": False, "error": "لاگین نکرده‌اید."})
    if not get_is_admin_from_session(request):
        return JSONResponse(status_code=403, content={"success": False, "error": "دسترسی مدیریتی ندارید."})
    return None  # None means OK


def _safe_error_message(e: Exception) -> str:
    """Return a generic error message without leaking internal details."""
    logger.error(f"Unhandled error: {type(e).__name__}: {e}")
    return "خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید."


def _require_auth(request: Request):
    """Return a 401 JSONResponse if the session user is not authenticated."""
    if not get_user_from_session(request):
        return JSONResponse(status_code=401, content={"success": False, "error": "لاگین نکرده‌اید."})
    return None


EMPLOYMENT_STATUS_VALUES = frozenset({"official", "unofficial"})


def ensure_employment_status_column(cursor):
    """ستون وضعیت استخدام را برای نصب‌های قدیمی، بدون نیاز به مهاجرت دستی، ایجاد می‌کند."""
    cursor.execute("""
        IF COL_LENGTH(N'dbo.user_table', N'employment_status') IS NULL
        BEGIN
            ALTER TABLE dbo.user_table ADD employment_status NVARCHAR(20) NULL;
        END
    """)


def ensure_is_active_column(cursor):
    """ستون فعال/غیرفعال بودن کاربر را اضافه می‌کند (پیش‌فرض: 'active')."""
    cursor.execute("""
        IF COL_LENGTH(N'dbo.user_table', N'is_active') IS NULL
        BEGIN
            ALTER TABLE dbo.user_table ADD is_active NVARCHAR(10) NULL;
            UPDATE dbo.user_table SET is_active = 'active' WHERE is_active IS NULL;
        END
    """)


@app.post("/api/admin/employment-status")
async def update_employment_status(request: Request):
    if not get_is_admin_from_session(request):
        return JSONResponse(status_code=403, content={"success": False, "error": "دسترسی مجاز نیست."})

    conn = None
    cursor = None
    try:
        data = await request.json()
        username = str(data.get("username") or "").strip()
        employment_status = str(data.get("employment_status") or "").strip().lower()
        if not username or employment_status not in EMPLOYMENT_STATUS_VALUES:
            return JSONResponse(status_code=400, content={"success": False, "error": "وضعیت استخدام معتبر نیست."})

        conn = get_db_connection()
        cursor = conn.cursor()
        ensure_employment_status_column(cursor)
        cursor.execute(
            "UPDATE user_table SET employment_status = ? WHERE LTRIM(RTRIM(username)) = LTRIM(RTRIM(?))",
            (employment_status, username),
        )
        if cursor.rowcount == 0:
            return JSONResponse(status_code=404, content={"success": False, "error": "کاربر پیدا نشد."})
        conn.commit()
        return JSONResponse(content={"success": True, "employment_status": employment_status})
    except Exception as exc:
        if conn is not None:
            conn.rollback()
        return JSONResponse(status_code=500, content={"success": False, "error": "خطا در ذخیره وضعیت استخدام."})
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()


# تبدیل زمان به ثانیه
def parse_seconds(duration):
    if duration is None:
        return 0
    if isinstance(duration, timedelta):
        return int(duration.total_seconds())
    if isinstance(duration, int):
        return duration
    if isinstance(duration, float):
        return int(duration)
    if isinstance(duration, time):
        return duration.hour * 3600 + duration.minute * 60 + duration.second
    if isinstance(duration, str):
        duration = duration.strip()
        if duration.isdigit():
            return int(duration)
        parts = duration.split(':')
        if len(parts) == 2:
            try:
                return int(parts[0]) * 3600 + int(parts[1]) * 60
            except ValueError:
                return 0
        if len(parts) == 3:
            try:
                return int(parts[0]) * 3600 + int(parts[1]) * 60 + int(parts[2])
            except ValueError:
                return 0
    return 0


def safe_int(value, default=0):
    if isinstance(value, int):
        return value
    if isinstance(value, float):
        return int(value)
    if isinstance(value, timedelta):
        return int(value.total_seconds())
    if isinstance(value, time):
        return value.hour * 3600 + value.minute * 60 + value.second
    if isinstance(value, str):
        value = value.strip()
        try:
            return int(value)
        except ValueError:
            return parse_seconds(value)
    return default

# تبدیل ثانیه به فرمت hh:mm
def format_time(seconds):
    seconds = safe_int(seconds)
    hours = seconds // 3600
    minutes = (seconds % 3600) // 60
    return f"{hours:02}:{minutes:02}"

# تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت
# تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت
# تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت # تابع صفحه مدیریت

@app.get("/admin")
async def admin(request: Request):
    username = get_user_from_session(request)
    is_admin = get_is_admin_from_session(request)

    if not username or not is_admin:
        return RedirectResponse(url="/login", status_code=303)

    return RedirectResponse(url="/admin/dashboard", status_code=303)


async def _render_admin_page(request: Request):
    """Shared template-rendering logic for every /admin/* route."""
    username = get_user_from_session(request)
    is_admin = get_is_admin_from_session(request)

    if not username or not is_admin:
        return RedirectResponse(url="/login", status_code=303)

    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        ensure_employment_status_column(cursor)
        ensure_is_active_column(cursor)
        conn.commit()

        # دریافت اطلاعات از جدول leave_report
        cursor.execute("""
            SELECT username, total_days, remaining_days
            FROM leave_report
        """)
        reports = cursor.fetchall()

        # دریافت اطلاعات از جدول user_table — رمز عبور هرگز خوانده نمی‌شود.
        cursor.execute("""
            SELECT username, department, work_hours, substitute, name, last_name, employment_status, is_active
            FROM user_table
        """)
        users_data = cursor.fetchall()

        # پردازش گزارش‌های مرخصی
        report_data = [ReportData(username=report[0], total_used=report[1], total_remaining=report[2]) for report in reports]

        # پردازش اطلاعات کاربران — رمز عبور هرگز به فرانت‌اند ارسال نمی‌شود.
        users = [UserData(
                username=user[0], department=user[1], work_hours=user[2], substitute=user[3],
                name=user[4], last_name=user[5], employment_status=str(user[6] or "official").strip().lower()
                if str(user[6] or "official").strip().lower() in EMPLOYMENT_STATUS_VALUES else "official",
                is_active=user[7], password=""
            )
            for user in users_data
        ]

        # دریافت مجموع زمان پاس برای هر کاربر از جدول totalpass_table
        cursor.execute("""
            SELECT username, SUM(DATEDIFF(SECOND, 0, pass_duration)) AS total_pass_seconds
            FROM totalpass_table
            WHERE status = 'تایید شده'
            GROUP BY username
        """)
        pass_data = cursor.fetchall()

        # پردازش داده‌های پاس
        pass_data_sorted = sorted(pass_data, key=lambda row: safe_int(row[1]), reverse=True)
        pass_reports = [
            PassData(
                row_number=idx + 1,
                username=pass_record[0],
                total_pass_time=convert_to_persian_numbers(f"{safe_int(pass_record[1]) // 3600:02}:{(safe_int(pass_record[1]) % 3600) // 60:02}")
            )
            for idx, pass_record in enumerate(pass_data_sorted)
        ]

        total_pass_seconds = sum(safe_int(record[1]) for record in pass_data)
        total_pass_time = convert_to_persian_numbers(f"{total_pass_seconds // 3600}:{(total_pass_seconds % 3600) // 60:02}")

        pass_chart_data = []
        max_pass_seconds = safe_int(pass_data_sorted[0][1]) if pass_data_sorted else 1
        if max_pass_seconds == 0:
            max_pass_seconds = 1
        for pass_record in pass_data_sorted[:5]:
            pass_seconds = safe_int(pass_record[1])
            pass_chart_data.append({
                'username': pass_record[0],
                'display': convert_to_persian_numbers(f"{pass_seconds // 3600:02}:{(pass_seconds % 3600) // 60:02}"),
                'percent': min(100, int((pass_seconds / max_pass_seconds) * 100))
            })

        # دریافت اطلاعات از جدول overtime (اضافه‌کاری)
        cursor.execute("""
            SELECT username, total_ezafe_time
            FROM ezafe_total_table
        """)
        overtime_data = cursor.fetchall()

        overtime_data_sorted = sorted(overtime_data, key=lambda row: safe_int(row[1]), reverse=True)
        overtime_reports = [
            OvertimeData(row_number=idx + 1, username=overtime[0], total_ezafe_time=format_time(overtime[1]))
            for idx, overtime in enumerate(overtime_data_sorted)
        ]

        total_overtime_seconds = sum(safe_int(overtime[1]) for overtime in overtime_data)
        total_overtime_time = convert_to_persian_numbers(format_time(total_overtime_seconds))

        top_overtime_row = max(overtime_data, key=lambda row: safe_int(row[1]), default=None)
        top_overtime_user = None
        if top_overtime_row:
            top_overtime_user = type('TopUser', (), {
                'username': top_overtime_row[0],
                'total_ezafe_time': convert_to_persian_numbers(format_time(top_overtime_row[1]))
            })

        overtime_chart_data = []
        max_overtime_seconds = safe_int(overtime_data_sorted[0][1]) if overtime_data_sorted else 1
        if max_overtime_seconds == 0:
            max_overtime_seconds = 1
        for overtime in overtime_data_sorted[:5]:
            overtime_seconds = safe_int(overtime[1])
            overtime_chart_data.append({
                'username': overtime[0],
                'display': convert_to_persian_numbers(format_time(overtime_seconds)),
                'percent': min(100, int((overtime_seconds / max_overtime_seconds) * 100))
            })

        total_leave_taken = sum(safe_int(report[1]) for report in reports)
        total_leave_requests = len(reports)
        unique_departments = len({user.department for user in users if user.department})

        average_overtime_per_user = convert_to_persian_numbers(format_time(total_overtime_seconds // max(1, len(users))))
        average_pass_per_user = convert_to_persian_numbers(format_time(total_pass_seconds // max(1, len(users))))

        overtime_user_count = len({row[0] for row in overtime_data})
        no_overtime_users = max(0, len(users) - overtime_user_count)

        department_counter = Counter(user.department for user in users if user.department)
        top_department_name, top_department_count = ('-', 0)
        if department_counter:
            top_department_name, top_department_count = department_counter.most_common(1)[0]

        top_pass_row = max(pass_data, key=lambda row: row[1], default=None)
        top_pass_user = None
        if top_pass_row:
            top_pass_user = type('TopUser', (), {
                'username': top_pass_row[0],
                'total_pass_time': convert_to_persian_numbers(f"{top_pass_row[1] // 3600:02}:{(top_pass_row[1] % 3600) // 60:02}")
            })

        total_users = len(users)
        total_seconds_capacity = max(1, total_users * 3600)
        pass_percent = min(100, int((total_pass_seconds / total_seconds_capacity) * 100))
        overtime_percent = min(100, int((total_overtime_seconds / total_seconds_capacity) * 100))

        # ساخت لیست کاربران برای منوی کشویی
        user_options = [{'value': user[0], 'label': user[4]} for user in users_data]

        # حذف کاربر در صورت ارسال درخواست POST
        if request.method == 'POST':
            user_to_delete = request.form['username']
            cursor.execute("DELETE FROM user_table WHERE username = ?", (user_to_delete,))
            conn.commit()
            return RedirectResponse(url="/admin/dashboard")  # پس از حذف، دوباره صفحه را لود می‌کند

        return templates.TemplateResponse(request, "admin.html", {
            "request": request,
            "users": users,
            "reports": report_data,
            "overtime_reports": overtime_reports,
            "pass_reports": pass_reports,
            "user_options": user_options,
            "total_users": convert_to_persian_numbers(total_users),
            "total_pass_time": total_pass_time,
            "total_overtime_time": convert_to_persian_numbers(total_overtime_time),
            "total_leave_taken": convert_to_persian_numbers(total_leave_taken),
            "total_leave_requests": convert_to_persian_numbers(total_leave_requests),
            "unique_departments": convert_to_persian_numbers(unique_departments),
            "average_overtime_per_user": convert_to_persian_numbers(average_overtime_per_user),
            "average_pass_per_user": convert_to_persian_numbers(average_pass_per_user),
            "overtime_user_count": convert_to_persian_numbers(overtime_user_count),
            "no_overtime_users": convert_to_persian_numbers(no_overtime_users),
            "top_department_name": top_department_name,
            "top_department_count": convert_to_persian_numbers(top_department_count),
            "top_overtime_user": top_overtime_user,
            "top_pass_user": top_pass_user,
            "pass_percent": convert_to_persian_numbers(pass_percent),
            "overtime_percent": convert_to_persian_numbers(overtime_percent),
            "pass_chart_data": pass_chart_data,
            "overtime_chart_data": overtime_chart_data
        })

    except Exception as e:
        raise HTTPException(status_code=500, detail="خطای داخلی سرور.")

# ─── Admin Section Routes (SPA) ─────────────────────────────────────────────
ADMIN_SECTIONS = {'dashboard', 'coworkers', 'vacation', 'overtime', 'hourly-pass', 'tickets', 'shifts', 'attendance', 'payroll'}

@app.get("/admin/dashboard", response_class=HTMLResponse)
async def admin_dashboard(request: Request):
    return await _render_admin_page(request)

@app.get("/admin/{section}", response_class=HTMLResponse)
async def admin_section(request: Request, section: str):
    return await _render_admin_page(request)

# ذخیره و بازیابی محاسبات حقوق و دستمزد ادمین
PAYROLL_CALCULATION_TYPES = {"overtime", "comprehensive", "hourly", "summary"}


def _payroll_ascii_digits(value):
    return str(value or "").translate(str.maketrans("۰۱۲۳۴۵۶۷۸۹", "0123456789"))


def _ensure_payroll_calculations_table(cursor):
    cursor.execute("""
        IF OBJECT_ID(N'dbo.admin_payroll_calculations', N'U') IS NULL
        BEGIN
            CREATE TABLE dbo.admin_payroll_calculations (
                id INT IDENTITY(1,1) NOT NULL PRIMARY KEY,
                calculation_type NVARCHAR(32) NOT NULL,
                period_year INT NOT NULL,
                period_month NVARCHAR(40) NOT NULL,
                username NVARCHAR(255) NOT NULL,
                payload_json NVARCHAR(MAX) NOT NULL,
                saved_by NVARCHAR(255) NOT NULL,
                created_at DATETIME2(0) NOT NULL CONSTRAINT DF_admin_payroll_created DEFAULT SYSUTCDATETIME(),
                updated_at DATETIME2(0) NOT NULL CONSTRAINT DF_admin_payroll_updated DEFAULT SYSUTCDATETIME(),
                CONSTRAINT UQ_admin_payroll_row UNIQUE (calculation_type, period_year, period_month, username)
            );
        END
    """)


@app.post("/api/admin/payroll/save")
async def save_admin_payroll(request: Request):
    if not get_is_admin_from_session(request):
        return JSONResponse(status_code=403, content={"success": False, "error": "دسترسی مجاز نیست."})
    conn = None
    cursor = None
    try:
        data = await request.json()
        calculation_type = str(data.get("calculation_type") or "").strip()
        if calculation_type not in PAYROLL_CALCULATION_TYPES:
            return JSONResponse(status_code=400, content={"success": False, "error": "نوع محاسبه معتبر نیست."})
        period_year = int(_payroll_ascii_digits(data.get("period_year")))
        period_month = str(data.get("period_month") or "").strip()
        rows = data.get("rows")
        if period_year < 1300 or period_year > 1600 or not period_month or not isinstance(rows, list):
            return JSONResponse(status_code=400, content={"success": False, "error": "اطلاعات دوره یا ردیف‌ها معتبر نیست."})
        if len(rows) > 500:
            return JSONResponse(status_code=400, content={"success": False, "error": "تعداد ردیف‌ها بیش از حد مجاز است."})
        actor = str(get_user_from_session(request) or "admin").strip()
        conn = get_db_connection()
        cursor = conn.cursor()
        _ensure_payroll_calculations_table(cursor)
        saved = 0
        for row in rows:
            if not isinstance(row, dict):
                continue
            username = str(row.get("username") or "").strip()
            payload = row.get("payload")
            if not username or not isinstance(payload, dict):
                continue
            if calculation_type != "summary":
                cursor.execute("SELECT 1 FROM user_table WHERE LTRIM(RTRIM(username)) = ?", (username,))
                if cursor.fetchone() is None:
                    continue
            payload_json = json.dumps(payload, ensure_ascii=False, separators=(",", ":"))
            cursor.execute("""
                UPDATE dbo.admin_payroll_calculations
                SET payload_json = ?, saved_by = ?, updated_at = SYSUTCDATETIME()
                WHERE calculation_type = ? AND period_year = ? AND period_month = ? AND username = ?
            """, (payload_json, actor, calculation_type, period_year, period_month, username))
            if cursor.rowcount == 0:
                cursor.execute("""
                    INSERT INTO dbo.admin_payroll_calculations
                        (calculation_type, period_year, period_month, username, payload_json, saved_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                """, (calculation_type, period_year, period_month, username, payload_json, actor))
            saved += 1
        conn.commit()
        return JSONResponse(content={"success": True, "saved": saved, "message": "تغییرات با موفقیت ذخیره شد."})
    except (TypeError, ValueError):
        if conn is not None:
            conn.rollback()
        return JSONResponse(status_code=400, content={"success": False, "error": "اطلاعات ذخیره‌سازی معتبر نیست."})
    except Exception as exc:
        if conn is not None:
            conn.rollback()
        return JSONResponse(status_code=500, content={"success": False, "error": "خطا در ذخیره تغییرات.", "detail": str(exc) if DEBUG else None})
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()


@app.get("/api/admin/payroll/load")
async def load_admin_payroll(request: Request, calculation_type: str, period_year: str, period_month: str):
    if not get_is_admin_from_session(request):
        return JSONResponse(status_code=403, content={"success": False, "error": "دسترسی مجاز نیست."})
    if calculation_type not in PAYROLL_CALCULATION_TYPES:
        return JSONResponse(status_code=400, content={"success": False, "error": "نوع محاسبه معتبر نیست."})
    conn = None
    cursor = None
    try:
        year = int(_payroll_ascii_digits(period_year))
        month = str(period_month or "").strip()
        if year < 1300 or year > 1600 or not month:
            return JSONResponse(status_code=400, content={"success": False, "error": "دوره معتبر نیست."})
        conn = get_db_connection()
        cursor = conn.cursor()
        _ensure_payroll_calculations_table(cursor)
        cursor.execute("""
            SELECT username, payload_json, updated_at
            FROM dbo.admin_payroll_calculations
            WHERE calculation_type = ? AND period_year = ? AND period_month = ?
            ORDER BY username
        """, (calculation_type, year, month))
        items = []
        for username, payload_json, updated_at in cursor.fetchall():
            try:
                payload = json.loads(payload_json or "{}")
            except (TypeError, ValueError):
                payload = {}
            items.append({"username": str(username).strip(), "payload": payload, "updated_at": str(updated_at) if updated_at else None})
        return JSONResponse(content={"success": True, "items": items})
    except (TypeError, ValueError):
        return JSONResponse(status_code=400, content={"success": False, "error": "دوره معتبر نیست."})
    except Exception as exc:
        return JSONResponse(status_code=500, content={"success": False, "error": "خطا در بازیابی تغییرات.", "detail": str(exc) if DEBUG else None})
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()

# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر
# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر
# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر# تابع اضافه کردن کاربر

@app.post("/add_user")
async def add_user(
    request: Request,
    name: str = Form(...),
    last_name: str = Form(...),
    department: str = Form(...),
    work_hours: str = Form(...),
    substitute: str = Form(...),
    username: str = Form(...),
    password: str = Form(...),
    role: str = Form(...),
    hozoorNum: str = Form(...),  # دقت کن اینجا از hozoorNum استفاده کردم
    shanbeh: str = Form(...),
    yekshanbeh: str = Form(...),
    doshanbeh: str = Form(...),
    seshanbeh: str = Form(...),
    chrshanbeh: str = Form(...),
    panjshanbeh: str = Form(...),
    employment_status: str = Form("official"),
):
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err

    # Validate the free-text fields before they reach the database: names and
    # departments are rendered by the admin UI, and markup must not be stored.
    try:
        name = _clean_display_field(name, max_length=100, field="نام")
        last_name = _clean_display_field(last_name, max_length=100, field="نام خانوادگی")
        department = _clean_display_field(department, max_length=100, field="بخش")
        substitute = _clean_display_field(substitute, max_length=100, field="جانشین")
        work_hours = _clean_display_field(work_hours, max_length=50, field="ساعت کاری")
        hozoorNum = _clean_display_field(hozoorNum, max_length=50, field="شماره حضور")
    except ValueError as exc:
        return JSONResponse(status_code=400, content={"success": False, "error": str(exc)})

    username_check = validate_username_input(username)
    if not username_check["valid"]:
        return JSONResponse(status_code=400, content={"success": False, "error": username_check["error"]})
    if role not in ("user", "admin"):
        return JSONResponse(status_code=400, content={"success": False, "error": "نقش معتبر نیست."})
    if not str(password or ""):
        return JSONResponse(status_code=400, content={"success": False, "error": "رمز عبور الزامی است."})

    try:
        # IDs come from a CSPRNG and are checked for collisions — the previous
        # `random.randint(100, 999)` could silently clash with an existing id.
        user_id = _next_available_user_id()
        if user_id is None:
            return JSONResponse(status_code=500, content={"success": False, "error": "خطا در تخصیص شناسه کاربر."})

        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()
        ensure_employment_status_column(cursor)
        ensure_is_active_column(cursor)
        employment_status = str(employment_status or "official").strip().lower()
        if employment_status not in EMPLOYMENT_STATUS_VALUES:
            employment_status = "official"

        password_hash = hash_password(password)
        insert_user_with_optional_hash(
            cursor,
            user_id,
            username,
            password,
            password_hash,
            name,
            last_name,
            department,
            substitute,
            work_hours,
            role,
            hozoorNum,
            shanbeh,
            yekshanbeh,
            doshanbeh,
            seshanbeh,
            chrshanbeh,
            panjshanbeh,
        )
        cursor.execute(
            "UPDATE user_table SET employment_status = ? WHERE LTRIM(RTRIM(username)) = LTRIM(RTRIM(?))",
            (employment_status, username),
        )

        conn.commit()
        cursor.close()
        conn.close()

        # ذخیره پیام در session نیاز به starlette-session دارد، یا حذفش کن
        # request.session["message"] = "کاربر با موفقیت ذخیره شد."return RedirectResponse(url="/admin/dashboard", status_code=303)

    except Exception as e:


        return {"error": "خطا در ذخیره کاربر"}


def _clean_display_field(value, *, max_length: int, field: str) -> str:
    """Validate a human readable field (see app/core/validation.py)."""
    from app.core.validation import clean_display_text

    return clean_display_text(value, max_length=max_length, field=field)


def _reject_markup_field(value, *, max_length: int, field: str, required: bool = False) -> str:
    """Reject markup in free text stored from employee forms (see validation.py)."""
    from app.core.validation import reject_markup

    return reject_markup(value, max_length=max_length, field=field, required=required)


def _next_available_user_id(attempts: int = 20):
    """Allocate an unused numeric user id.

    ``random.randint`` (the previous implementation) is not a CSPRNG and could
    produce an id that already exists.  Ids are now drawn from :mod:`secrets`
    and verified against the table.
    """
    import secrets as _secrets

    conn = None
    cursor = None
    try:
        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()
        for _ in range(attempts):
            candidate = _secrets.randbelow(900) + 100
            cursor.execute("SELECT 1 FROM user_table WHERE id = ?", (candidate,))
            if cursor.fetchone() is None:
                return candidate
        cursor.execute("SELECT ISNULL(MAX(id), 0) + 1 FROM user_table")
        row = cursor.fetchone()
        return int(row[0]) if row else None
    except Exception:
        return None
    finally:
        if cursor is not None:
            try:
                cursor.close()
            except Exception:
                pass
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass


# تابع بروزرسانی اطلاعات کاربر# تابع بروزرسانی اطلاعات کاربر# تابع بروزرسانی اطلاعات کاربر# تابع بروزرسانی اطلاعات کاربر
# تابع بروزرسانی اطلاعات کاربر# تابع بروزرسانی اطلاعات کاربر# تابع بروزرسانی اطلاعات کاربر# تابع بروزرسانی اطلاعات کاربر
# تابع بروزرسانی اطلاعات کاربر# تابع بروزرسانی اطلاعات کاربر# تابع بروزرسانی اطلاعات کاربر# تابع بروزرسانی اطلاعات کاربر

@app.post("/update_user")
async def update_user(request: Request):
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    conn = None
    cursor = None
    try:
        data = await request.json()

        current_username = str(data.get("current_username") or data.get("username") or "").strip()
        username = str(data.get("username") or "").strip()
        password = data.get("password")
        # Free-text fields are validated before they are stored: they are
        # rendered by the admin UI and must not carry markup.
        try:
            substitute = _clean_display_field(data.get("substitute"), max_length=100, field="جانشین")
            work_hours = _clean_display_field(data.get("work_hours"), max_length=50, field="ساعت ساعت کاری")
            department = _clean_display_field(data.get("department"), max_length=100, field="بخش")
        except ValueError as exc:
            return JSONResponse(status_code=400, content={"success": False, "error": str(exc)})
        employment_status = str(data.get("employment_status") or "").strip().lower()
        is_active = str(data.get("is_active") or "active").strip().lower()
        if is_active not in ('active', 'inactive'):
            is_active = 'active'

        if not current_username or not username:
            return {"success": False, "error": "نام کاربری الزامی است."}
        username_check = validate_username_input(username)
        if not username_check["valid"]:
            return JSONResponse(status_code=400, content={"success": False, "error": username_check["error"]})

        conn = get_db_connection()
        cursor = conn.cursor()

        # رمز عبور خالی یعنی رمز فعلی حفظ شود؛ رمز جدید با همان سازوکار ورود ذخیره می‌شود.
        password_value = str(password).strip() if password is not None else ""
        ensure_employment_status_column(cursor)
        ensure_is_active_column(cursor)
        columns = get_user_table_columns(cursor)
        set_clauses = ["username = ?", "substitute = ?", "work_hours = ?", "department = ?"]
        params = [username, substitute, work_hours, department]
        if employment_status in EMPLOYMENT_STATUS_VALUES:
            set_clauses.append("employment_status = ?")
            params.append(employment_status)

        if 'is_active' in columns:
            set_clauses.append("is_active = ?")
            params.append(is_active)

        if password_value:
            new_hash = hash_password(password_value)
            # Never store plaintext — store only bcrypt hash
            if "password_hash" in columns:
                set_clauses.append("password = ''")
                set_clauses.append("password_hash = ?")
                params.append(new_hash)
            else:
                # Fallback: store bcrypt hash string in password column (not plaintext)
                set_clauses.append("password = ?")
                params.append(new_hash.decode("utf-8") if isinstance(new_hash, bytes) else str(new_hash))

        params.append(current_username)
        cursor.execute(
            f"UPDATE user_table SET {', '.join(set_clauses)} "
            "WHERE LTRIM(RTRIM(username)) = ?",
            params,
        )

        conn.commit()

        # Credential / privilege / account-status changes invalidate existing
        # sessions (a signed cookie cannot be revoked otherwise).
        revoked = 0
        from app.core.sessions import revoke_user_sessions

        if password_value or is_active != "active" or username != current_username:
            revoked = revoke_user_sessions(current_username, "user_update")
        return {"success": True, "sessions_revoked": revoked}

    except Exception as e:
        if conn is not None:
            try:
                conn.rollback()
            except Exception:
                pass
        return {"success": False, "error": "خطای داخلی سرور"}

    finally:
        if cursor is not None:
            try:
                cursor.close()
            except Exception:
                pass
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass

# تابع مدیریت شیفت‌های ماهانه پرسنل # تابع مدیریت شیفت‌های ماهانه پرسنل # تابع مدیریت شیفت‌های ماهانه پرسنل
# تابع مدیریت شیفت‌های ماهانه پرسنل # تابع مدیریت شیفت‌های ماهانه پرسنل # تابع مدیریت شیفت‌های ماهانه پرسنل
# تابع مدیریت شیفت‌های ماهانه پرسنل # تابع مدیریت شیفت‌های ماهانه پرسنل # تابع مدیریت شیفت‌های ماهانه پرسنل

# نگاشت شماره‌ی روز هفته (weekday شمسی: 0=شنبه ... 6=جمعه) به نام ستون جدول shiftha
SHIFT_DAY_COLUMNS = {
    0: 'shanbeh', 1: 'yekshanbeh', 2: 'doshanbeh', 3: 'seshanbeh',
    4: 'chaharshanbeh', 5: 'panjshanbeh', 6: 'jomeh'
}


def _normalize_fa_username(value) -> str:
    """Normalize Persian/Arabic look-alike letters and padding in usernames.

    Users type ی/ک (Persian) while some inputs/DB rows carry ي/ك (Arabic).
    Shifts are matched by username, so both sides must be normalized or
    'آی تی' never equals stored 'آي تي'.
    """
    s = str(value or "")
    s = s.replace("\u064a", "\u06cc")  # Arabic Yeh ي -> Persian Yeh ی
    s = s.replace("\u0643", "\u06a9")  # Arabic Kaf ك -> Persian Kaf ک
    s = s.replace("\u200c", " ")       # ZWNJ -> space
    return " ".join(s.split())


# SQL fragment that normalizes a username column/parameter the same way.
# Used in WHERE clauses so legacy rows with Arabic letters still match.
_FA_USERNAME_SQL = "REPLACE(REPLACE(LTRIM(RTRIM({expr})), N'ي', N'ی'), N'ك', N'ک')"


@app.get("/get_shifts/{username}/{year}/{month}")
async def get_shifts(request: Request, username: str, year: int, month: int):
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    try:
        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()

        username = _normalize_fa_username(username)

        cursor.execute("""
            SELECT id, start_day, end_day, shanbeh, yekshanbeh, doshanbeh, seshanbeh,
                   chaharshanbeh, panjshanbeh, jomeh, title
            FROM shiftha
            WHERE REPLACE(REPLACE(LTRIM(RTRIM(username)), N'ي', N'ی'), N'ك', N'ک') = ?
              AND jalali_year = ? AND jalali_month = ?
            ORDER BY start_day
        """, (username, year, month))
        rows = cursor.fetchall()

        shifts = [{
            'id': r[0], 'start_day': r[1], 'end_day': r[2],
            'shanbeh': r[3] or '', 'yekshanbeh': r[4] or '', 'doshanbeh': r[5] or '',
            'seshanbeh': r[6] or '', 'chaharshanbeh': r[7] or '', 'panjshanbeh': r[8] or '',
            'jomeh': r[9] or '', 'title': r[10] or ''
        } for r in rows]

        return JSONResponse(content={'success': True, 'shifts': shifts})

    except Exception as e:
        return JSONResponse(content={'success': False, 'message': _safe_error_message(e)})

    finally:
        try:
            cursor.close()
            conn.close()
        except:
            pass


@app.post("/add_shift")
async def add_shift(request: Request):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    try:
        data = await request.json()

        username = data.get("username")
        year = int(data.get("jalali_year"))
        month = int(data.get("jalali_month"))
        start_day = int(data.get("start_day"))
        end_day = int(data.get("end_day"))
        title = data.get("title") or None

        if not username or not (1 <= month <= 12) or not (1 <= start_day <= 31) or end_day < start_day:
            return JSONResponse(content={'success': False, 'message': 'اطلاعات ارسالی نامعتبر است'})

        days = {col: (data.get(col) or None) for col in SHIFT_DAY_COLUMNS.values()}
        username = _normalize_fa_username(username)

        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()

        # جلوگیری از همپوشانی بازه‌ها برای همین کاربر و همین ماه شمسی
        cursor.execute("""
            SELECT COUNT(*) FROM shiftha
            WHERE REPLACE(REPLACE(LTRIM(RTRIM(username)), N'ي', N'ی'), N'ك', N'ک') = ?
              AND jalali_year = ? AND jalali_month = ?
              AND start_day <= ? AND end_day >= ?
        """, (username, year, month, end_day, start_day))
        if cursor.fetchone()[0] > 0:
            return JSONResponse(content={'success': False, 'message': 'این بازه با یک بازه‌ی تعریف‌شده‌ی دیگر برای همین ماه همپوشانی دارد'})

        cursor.execute("""
            INSERT INTO shiftha (username, jalali_year, jalali_month, start_day, end_day,
                                  shanbeh, yekshanbeh, doshanbeh, seshanbeh, chaharshanbeh, panjshanbeh, jomeh, title)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (username, year, month, start_day, end_day,
              days['shanbeh'], days['yekshanbeh'], days['doshanbeh'], days['seshanbeh'],
              days['chaharshanbeh'], days['panjshanbeh'], days['jomeh'], title))
        conn.commit()

        return JSONResponse(content={'success': True, 'message': 'شیفت با موفقیت ثبت شد'})

    except Exception as e:
        return JSONResponse(content={'success': False, 'message': _safe_error_message(e)})

    finally:
        try:
            cursor.close()
            conn.close()
        except:
            pass


@app.post("/update_shift")
async def update_shift(request: Request):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    try:
        data = await request.json()

        shift_id = int(data.get("id"))
        start_day = int(data.get("start_day"))
        end_day = int(data.get("end_day"))
        title = data.get("title") or None

        if not (1 <= start_day <= 31) or end_day < start_day:
            return JSONResponse(content={'success': False, 'message': 'بازه‌ی روز نامعتبر است'})

        days = {col: (data.get(col) or None) for col in SHIFT_DAY_COLUMNS.values()}

        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()

        cursor.execute("SELECT username, jalali_year, jalali_month FROM shiftha WHERE id = ?", (shift_id,))
        row = cursor.fetchone()
        if not row:
            return JSONResponse(content={'success': False, 'message': 'شیفت مورد نظر پیدا نشد'})
        username, year, month = row

        cursor.execute("""
            SELECT COUNT(*) FROM shiftha
            WHERE REPLACE(REPLACE(LTRIM(RTRIM(username)), N'ي', N'ی'), N'ك', N'ک') = ?
              AND jalali_year = ? AND jalali_month = ?
              AND id <> ? AND start_day <= ? AND end_day >= ?
        """, (username, year, month, shift_id, end_day, start_day))
        if cursor.fetchone()[0] > 0:
            return JSONResponse(content={'success': False, 'message': 'این بازه با یک بازه‌ی تعریف‌شده‌ی دیگر برای همین ماه همپوشانی دارد'})

        cursor.execute("""
            UPDATE shiftha
            SET start_day = ?, end_day = ?, shanbeh = ?, yekshanbeh = ?, doshanbeh = ?,
                seshanbeh = ?, chaharshanbeh = ?, panjshanbeh = ?, jomeh = ?, title = ?
            WHERE id = ?
        """, (start_day, end_day, days['shanbeh'], days['yekshanbeh'], days['doshanbeh'],
              days['seshanbeh'], days['chaharshanbeh'], days['panjshanbeh'], days['jomeh'], title, shift_id))
        conn.commit()

        return JSONResponse(content={'success': True, 'message': 'شیفت با موفقیت به‌روزرسانی شد'})

    except Exception as e:
        return JSONResponse(content={'success': False, 'message': _safe_error_message(e)})

    finally:
        try:
            cursor.close()
            conn.close()
        except:
            pass


@app.post("/delete_shift/{shift_id}")
async def delete_shift(shift_id: int, request: Request):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    try:
        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()
        cursor.execute("DELETE FROM shiftha WHERE id = ?", (shift_id,))
        conn.commit()
        return JSONResponse(content={'success': True, 'message': 'شیفت حذف شد'})

    except Exception as e:
        return JSONResponse(content={'success': False, 'message': _safe_error_message(e)})

    finally:
        try:
            cursor.close()
            conn.close()
        except:
            pass


@app.get("/get_active_shifts")
async def get_active_shifts(request: Request):
    """Return all shifts where today's Jalali day falls within [start_day, end_day]
    for the current Jalali year/month — i.e. active shifts right now."""
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    try:
        import jdatetime
        today = jdatetime.date.today()
        year = today.year
        month = today.month
        day = today.day

        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()
        cursor.execute("""
            SELECT id, username, jalali_year, jalali_month, start_day, end_day, title,
                   shanbeh, yekshanbeh, doshanbeh, seshanbeh,
                   chaharshanbeh, panjshanbeh, jomeh
            FROM shiftha
            WHERE jalali_year = ? AND jalali_month = ?
              AND start_day <= ? AND end_day >= ?
            ORDER BY username, start_day
        """, (year, month, day, day))
        rows = cursor.fetchall()

        shifts = [{
            'id': r[0], 'username': r[1], 'jalali_year': r[2], 'jalali_month': r[3],
            'start_day': r[4], 'end_day': r[5],
            'title': r[6] or '',
            'shanbeh': r[7] or '', 'yekshanbeh': r[8] or '', 'doshanbeh': r[9] or '',
            'seshanbeh': r[10] or '', 'chaharshanbeh': r[11] or '',
            'panjshanbeh': r[12] or '', 'jomeh': r[13] or ''
        } for r in rows]

        return JSONResponse(content={'success': True, 'shifts': shifts, 'today': str(day), 'year': year, 'month': month})
    except Exception as e:
        return JSONResponse(content={'success': False, 'message': _safe_error_message(e)})
    finally:
        try:
            cursor.close()
            conn.close()
        except:
            pass


# تابع دریافت مرخصی های کاربران # تابع دریافت مرخصی های کاربران # تابع دریافت مرخصی های کاربران # تابع دریافت مرخصی های کاربران
# تابع دریافت مرخصی های کاربران # تابع دریافت مرخصی های کاربران # تابع دریافت مرخصی های کاربران # تابع دریافت مرخصی های کاربران
# تابع دریافت مرخصی های کاربران # تابع دریافت مرخصی های کاربران # تابع دریافت مرخصی های کاربران # تابع دریافت مرخصی های کاربران

@app.get("/get_leave_requests")
async def get_leave_requests(request: Request):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    try:
        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()
        cursor.execute("SELECT id, start_date, end_date, days, substitute, username, status FROM mrkhc_table")
        requests = cursor.fetchall()

        requests_data = []
        for request in requests:
            start_date = request[1]
            end_date = request[2]

            # تبدیل به datetime.date اگر نوعش str بود
            try:
                if isinstance(start_date, str):
                    start_date = datetime.strptime(start_date, '%Y-%m-%d').date()
                start_date_shamsi = jdatetime.date.fromgregorian(date=start_date).strftime('%Y-%m-%d')
            except Exception:
                start_date_shamsi = 'تاریخ ناموجود'

            try:
                if isinstance(end_date, str):
                    end_date = datetime.strptime(end_date, '%Y-%m-%d').date()
                end_date_shamsi = jdatetime.date.fromgregorian(date=end_date).strftime('%Y-%m-%d')
            except Exception:
                end_date_shamsi = 'تاریخ ناموجود'

            requests_data.append({
                'id': request[0],
                'start_date': start_date_shamsi,
                'end_date': end_date_shamsi,
                'days': request[3],
                'substitute': request[4],
                'username': request[5],
                'status': request[6] if request[6] else 'انتظار تایید'
            })

        cursor.close()
        conn.close()
        return JSONResponse(content=requests_data)

    except Exception as e:
        return JSONResponse(            content={"error": "خطا در دریافت داده‌ها", "message": _safe_error_message(e)})


# تابع بروزرسانی وضعیت مرخصی کاربر # تابع بروزرسانی وضعیت مرخصی کاربر # تابع بروزرسانی وضعیت مرخصی کاربر # تابع بروزرسانی وضعیت مرخصی کاربر
# تابع بروزرسانی وضعیت مرخصی کاربر # تابع بروزرسانی وضعیت مرخصی کاربر # تابع بروزرسانی وضعیت مرخصی کاربر # تابع بروزرسانی وضعیت مرخصی کاربر
# تابع بروزرسانی وضعیت مرخصی کاربر # تابع بروزرسانی وضعیت مرخصی کاربر # تابع بروزرسانی وضعیت مرخصی کاربر # تابع بروزرسانی وضعیت مرخصی کاربر

@app.post("/update_leave_status")
async def update_leave_status(request: Request):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    data = await request.json()
    request_id = data.get('requestId')
    new_status = data.get('status')

    if not request_id or not new_status:
        return JSONResponse(content={'success': False, 'message': 'اطلاعات ناقص ارسال شده است.'})

    try:
        query = """
            UPDATE mrkhc_table
            SET status = ?
            WHERE id = ?
        """
        cursor.execute(query, (new_status, request_id))
        if cursor.rowcount:
            _notify_requester_status('mrkhc_table', request_id, new_status, 'مرخصی')
        conn.commit()
        return JSONResponse(content={'success': True, 'message': 'وضعیت با موفقیت به‌روزرسانی شد!'})
    except Exception as e:
        print(f"Error updating status: {e}")
        return JSONResponse(content={'success': False, 'message': 'خطا در به‌روزرسانی وضعیت.'})

# تابع تولید گزارش انفرادی مرخصی کاربر # تابع تولید گزارش انفرادی مرخصی کاربر # تابع تولید گزارش انفرادی مرخصی کاربر
# تابع تولید گزارش انفرادی مرخصی کاربر # تابع تولید گزارش انفرادی مرخصی کاربر # تابع تولید گزارش انفرادی مرخصی کاربر
# تابع تولید گزارش انفرادی مرخصی کاربر # تابع تولید گزارش انفرادی مرخصی کاربر # تابع تولید گزارش انفرادی مرخصی کاربر

@app.post("/generate_individual_report")
async def generate_individual_report(request: Request):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    data = await request.json()
    username = data.get('user')         # نام کاربر
    from_date = data.get('fromDate')    # تاریخ شروع (شمسی)
    to_date = data.get('toDate')        # تاریخ پایان (شمسی)

    try:
        # تبدیل تاریخ شمسی به میلادی
        from_date_gregorian = jdatetime.date.fromisoformat(from_date.replace('/', '-')).togregorian()
        to_date_gregorian = jdatetime.date.fromisoformat(to_date.replace('/', '-')).togregorian()

        # تبدیل به رشته مناسب برای SQL
        from_date_str = from_date_gregorian.strftime('%Y-%m-%d')
        to_date_str = to_date_gregorian.strftime('%Y-%m-%d')

        if not username:
            query = """
                SELECT start_date, end_date, days, id, substitute, status, username
                FROM mrkhc_table
                WHERE CONVERT(date, start_date, 120) >= ? 
                AND CONVERT(date, end_date, 120) <= ?
                AND status IN (N'تایید شده', N'رد شده', N'انصراف')
            """
            cursor.execute(query, (from_date_str, to_date_str))
        else:
            query = """
                SELECT start_date, end_date, days, id, substitute, status, username
                FROM mrkhc_table
                WHERE username = ?
                AND CONVERT(date, start_date, 120) >= ? 
                AND CONVERT(date, end_date, 120) <= ?
                AND status IN (N'تایید شده', N'رد شده', N'انصراف')
            """
            cursor.execute(query, (username, from_date_str, to_date_str))

        reports = cursor.fetchall()

        report_data = []
        for report in reports:
            start_date_shamsi = jdatetime.date.fromgregorian(date=report[0]).strftime('%Y/%m/%d')
            end_date_shamsi = jdatetime.date.fromgregorian(date=report[1]).strftime('%Y/%m/%d')
            report_data.append({
                'start_date': start_date_shamsi,
                'end_date': end_date_shamsi,
                'days': report[2],
                'id': report[3],
                'substitute': report[4],
                'status': report[5],
                'username': report[6] if len(report) > 6 else username
            })

        return JSONResponse(content={'success': True, 'reports': report_data})
    
    except Exception as e:
        print("Error:", e)
        return JSONResponse(content={'success': False, 'message': 'خطا در دریافت اطلاعات'})
    
# تابع صفحه گزارش انفرادی مرخصی کاربران # تابع صفحه گزارش انفرادی مرخصی کاربران # تابع صفحه گزارش انفرادی مرخصی کاربران
# تابع صفحه گزارش انفرادی مرخصی کاربران # تابع صفحه گزارش انفرادی مرخصی کاربران # تابع صفحه گزارش انفرادی مرخصی کاربران
# تابع صفحه گزارش انفرادی مرخصی کاربران # تابع صفحه گزارش انفرادی مرخصی کاربران # تابع صفحه گزارش انفرادی مرخصی کاربران

@app.get("/leave_report_page", response_class=HTMLResponse)
async def report_page(request: Request):
    return templates.TemplateResponse(request, "leave_report_page.html", {"request": request})

@app.get("/fetch_user_data")
async def fetch_user_data(request: Request, username: str = Query(...)):
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    try:
        # جستجوی اطلاعات کاربر
        query = """
            SELECT name, last_name, department
            FROM user_table
            WHERE username = ?
        """
        cursor.execute(query, (username,))
        user_info = cursor.fetchone()

        if user_info:
            return {
                "name": user_info[0],
                "last_name": user_info[1],
                "department": user_info[2]
            }
        else:
            return JSONResponse(status_code=404, content={"error": "User not found"})

    except Exception as e:
        print("Error:", e)
        return JSONResponse(status_code=500, content={"error": "Error retrieving user info"})

# تابع دریافت اضافه کاری های کاربران # تابع دریافت اضافه کاری های کاربران # تابع دریافت اضافه کاری های کاربران
# تابع دریافت اضافه کاری های کاربران # تابع دریافت اضافه کاری های کاربران # تابع دریافت اضافه کاری های کاربران
# تابع دریافت اضافه کاری های کاربران # تابع دریافت اضافه کاری های کاربران # تابع دریافت اضافه کاری های کاربران

@app.get("/get_hourly_pass_requests")
async def get_hourly_pass_requests(request: Request):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    try:
        # اتصال به دیتابیس
        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()

        # فقط درخواست‌های "در انتظار تایید"
        query = """
            SELECT id, request_date, pass_title, pass_duration, username, status
            FROM totalpass_table
            WHERE status = 'انتظار تایید'
        """
        cursor.execute(query)
        hourly_pass_requests = cursor.fetchall()

        requests_data = []
        for request in hourly_pass_requests:
            request_id = request[0]
            request_date_shamsi = jdatetime.date.fromgregorian(date=request[1]).strftime('%Y/%m/%d') if request[1] else 'تاریخ ناموجود'
            pass_duration_str = str(request[3])  # مدت زمان پاس به صورت رشته

            requests_data.append({
                'id': request_id,
                'request_date': request_date_shamsi,
                'pass_title': request[2],
                'pass_duration': pass_duration_str,
                'username': request[4],
                'status': request[5] if request[5] else 'انتظار تایید'
            })

        cursor.close()
        conn.close()

        return JSONResponse(content=requests_data)

    except Exception as e:
        return JSONResponse(
            content={"error": "خطا در دریافت داده‌ها", "message": _safe_error_message(e)},
            status_code=500
        )

# تابع تغییر وضعیت درخواست پاس ساعتی کاربران # تابع تغییر وضعیت درخواست پاس ساعتی کاربران # تابع تغییر وضعیت درخواست پاس ساعتی کاربران
# تابع تغییر وضعیت درخواست پاس ساعتی کاربران # تابع تغییر وضعیت درخواست پاس ساعتی کاربران # تابع تغییر وضعیت درخواست پاس ساعتی کاربران
# تابع تغییر وضعیت درخواست پاس ساعتی کاربران # تابع تغییر وضعیت درخواست پاس ساعتی کاربران # تابع تغییر وضعیت درخواست پاس ساعتی کاربران

@app.post("/change_hourly_pass_status")
async def change_hourly_pass_status(request: Request):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    try:
        data = await request.json()
        request_id = data.get("id")
        new_status = data.get("status")

        # بروزرسانی وضعیت در جدول totalpass_table
        update_query = """
            UPDATE totalpass_table
            SET status = ?
            WHERE id = ?
        """
        cursor.execute(update_query, (new_status, request_id))
        if cursor.rowcount:
            _notify_requester_status('totalpass_table', request_id, new_status, 'پاس ساعتی')
        conn.commit()

        return JSONResponse(content={"success": True})

    except Exception as e:
        return JSONResponse(
            content={"success": False, "message": "خطای داخلی سرور"},
            status_code=500
        )

# تابع صفحه گزارش پاس ساعتی انفرادی # تابع صفحه گزارش پاس ساعتی انفرادی # تابع صفحه گزارش پاس ساعتی انفرادی
# تابع صفحه گزارش پاس ساعتی انفرادی # تابع صفحه گزارش پاس ساعتی انفرادی # تابع صفحه گزارش پاس ساعتی انفرادی
# تابع صفحه گزارش پاس ساعتی انفرادی # تابع صفحه گزارش پاس ساعتی انفرادی # تابع صفحه گزارش پاس ساعتی انفرادی

@app.get("/hourlypass_Report_page", response_class=HTMLResponse)
async def hourly_pass_report_page(request: Request):
    return templates.TemplateResponse(request, "hourlypass_Report_page.html", {"request": request})

@app.post("/get_hourly_pass_report")
async def get_hourly_pass_report(request: Request):
    # The report screen is part of the admin panel and accepts
    # ``username = "all_users"``, so it must never be reachable anonymously
    # (it exposed every employee's pass records to the LAN).
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    try:
        data = await request.json()
        username = data.get('username')
        start_date_str = convert_farsi_to_english(str(data.get('start_date', '')))
        end_date_str = convert_farsi_to_english(str(data.get('end_date', '')))

        # تبدیل تاریخ شمسی به میلادی
        start_date_jalali = JalaliDate(*map(int, start_date_str.split('/')))
        end_date_jalali = JalaliDate(*map(int, end_date_str.split('/')))
        start_date = start_date_jalali.to_gregorian()
        end_date = end_date_jalali.to_gregorian()

        if username == 'all_users':
            query = '''
                SELECT id, request_date, pass_title, pass_duration, status, username
                FROM totalpass_table
                WHERE request_date BETWEEN ? AND ? AND status != 'انتظار تایید'
            '''
            cursor.execute(query, (start_date, end_date))
        else:
            query = '''
                SELECT id, request_date, pass_title, pass_duration, status, username
                FROM totalpass_table
                WHERE username = ? AND request_date BETWEEN ? AND ? AND status != 'انتظار تایید'
            '''
            cursor.execute(query, (username, start_date, end_date))

        rows = cursor.fetchall()

        result = []
        for row in rows:
            request_date_shamsi = JalaliDate(row.request_date).strftime('%Y/%m/%d')
            pass_duration_str = row.pass_duration.strftime('%H:%M') if row.pass_duration else None

            result.append({
                'id': row.id,
                'username': row.username,
                'request_date': request_date_shamsi,
                'pass_title': row.pass_title,
                'pass_duration': pass_duration_str,
                'status': row.status
            })

        return JSONResponse(content=result)

    except ValueError:
        return JSONResponse(content={'error': 'تاریخ وارد شده صحیح نیست. لطفاً فرمت صحیح را وارد کنید.'}, status_code=400)

    except Exception as e:
        return JSONResponse(content={"error": "خطای داخلی سرور"}, status_code=500)

# تابع بروزرسانی وضعیت پاس ساعتی # تابع بروزرسانی وضعیت پاس ساعتی # تابع بروزرسانی وضعیت پاس ساعتی
# تابع بروزرسانی وضعیت پاس ساعتی # تابع بروزرسانی وضعیت پاس ساعتی # تابع بروزرسانی وضعیت پاس ساعتی
# تابع بروزرسانی وضعیت پاس ساعتی # تابع بروزرسانی وضعیت پاس ساعتی # تابع بروزرسانی وضعیت پاس ساعتی

@app.post("/update_hourly_pass_status")
async def update_hourly_pass_status(request: Request):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    try:
        data = await request.json()
        row_id = data.get("id")
        new_status = data.get("status")

        cursor.execute("UPDATE totalpass_table SET status = ? WHERE id = ?", new_status, row_id)
        if cursor.rowcount:
            _notify_requester_status('totalpass_table', row_id, new_status, 'پاس ساعتی')
        conn.commit()

        return JSONResponse(content={"success": True})

    except Exception as e:
        return JSONResponse(content={"success": False, "message": _safe_error_message(e)}, status_code=500)

# تابع دریافت اضافه کاری کاربران # تابع دریافت اضافه کاری کاربران # تابع دریافت اضافه کاری کاربران # تابع دریافت اضافه کاری کاربران
# تابع دریافت اضافه کاری کاربران # تابع دریافت اضافه کاری کاربران # تابع دریافت اضافه کاری کاربران # تابع دریافت اضافه کاری کاربران
# تابع دریافت اضافه کاری کاربران # تابع دریافت اضافه کاری کاربران # تابع دریافت اضافه کاری کاربران # تابع دریافت اضافه کاری کاربران

@app.get("/get_overtime_requests")
async def get_overtime_requests(request: Request):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    try:
        # اتصال به دیتابیس
        conn = pyodbc.connect(_db_connection_string())
        cursor = conn.cursor()

        # دریافت اطلاعات اضافه‌کاری
        query = "SELECT id, overtime_date, daily_overtime, description, username, status FROM ezafe_table"
        cursor.execute(query)
        overtime_requests = cursor.fetchall()

        # اگر داده‌ای موجود نبود
        if not overtime_requests:
            return JSONResponse(content=[])

        requests_data = []
        for request in overtime_requests:
            request_id = request[0]
            overtime_date = request[1]
            daily_overtime = request[2]

            # تبدیل تاریخ میلادی به شمسی
            if isinstance(overtime_date, (datetime, date)):
                overtime_date_shamsi = jdatetime.date.fromgregorian(date=overtime_date).strftime('%Y/%m/%d')
            else:
                overtime_date_shamsi = 'تاریخ ناموجود'

            # تبدیل daily_overtime به رشته ساعت و دقیقه
            if isinstance(daily_overtime, (time, datetime)):
                daily_overtime_str = daily_overtime.strftime('%H:%M')
            else:
                daily_overtime_str = '00:00'

            requests_data.append({
                'id': request_id,
                'overtime_date': overtime_date_shamsi,
                'daily_overtime': daily_overtime_str,
                'description': request[3],
                'username': request[4],
                'status': request[5] if request[5] else 'انتظار تایید'
            })

        cursor.close()
        conn.close()

        return JSONResponse(content=requests_data)

    except Exception as e:
        return JSONResponse(
            content={"error": "خطا در دریافت داده‌ها", "message": _safe_error_message(e)}
        )

# تابع بروزرسانی وضعیت اضافه کاری کاربران # تابع بروزرسانی وضعیت اضافه کاری کاربران # تابع بروزرسانی وضعیت اضافه کاری کاربران
# تابع بروزرسانی وضعیت اضافه کاری کاربران # تابع بروزرسانی وضعیت اضافه کاری کاربران # تابع بروزرسانی وضعیت اضافه کاری کاربران
# تابع بروزرسانی وضعیت اضافه کاری کاربران # تابع بروزرسانی وضعیت اضافه کاری کاربران # تابع بروزرسانی وضعیت اضافه کاری کاربران

# تعریف ساختار ورودی با Pydantic
class OvertimeUpdateRequest(BaseModel):
    requestId: int
    status: str

@app.post("/update_overtime_status")
async def update_overtime_status(request: Request, data: OvertimeUpdateRequest):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    try:
        cursor.execute("""
            UPDATE ezafe_table
            SET status = ?
            WHERE id = ?
        """, (data.status, data.requestId))
        
        if cursor.rowcount:
            _notify_requester_status('ezafe_table', data.requestId, data.status, 'اضافه‌کاری')
        conn.commit()

        if cursor.rowcount == 0:
            return JSONResponse(status_code=404, content={"success": False, "message": "هیچ رکوردی برای بروزرسانی پیدا نشد"})
        
        return {"success": True, "message": "وضعیت با موفقیت تغییر کرد!"}
    
    except Exception as e:
        print("Error updating overtime status:", e)
        return JSONResponse(status_code=500, content={"success": False, "message": "خطا در بروزرسانی وضعیت"})

# تابع به‌روزرسانی وضعیت اضافه‌کاری در دیتابیس # تابع به‌روزرسانی وضعیت اضافه‌کاری در دیتابیس # تابع به‌روزرسانی وضعیت اضافه‌کاری در دیتابیس
# تابع به‌روزرسانی وضعیت اضافه‌کاری در دیتابیس # تابع به‌روزرسانی وضعیت اضافه‌کاری در دیتابیس # تابع به‌روزرسانی وضعیت اضافه‌کاری در دیتابیس
# تابع به‌روزرسانی وضعیت اضافه‌کاری در دیتابیس # تابع به‌روزرسانی وضعیت اضافه‌کاری در دیتابیس # تابع به‌روزرسانی وضعیت اضافه‌کاری در دیتابیس

# تعریف مدل ورودی با Pydantic
class OvertimeStatusUpdate(BaseModel):
    id: int
    status: str

@app.post("/update_overtime_Indivisual_status")
async def update_overtime_indivisual_status(request: Request, data: OvertimeStatusUpdate):
    admin_err = _require_admin(request)
    if admin_err:
        return admin_err
    try:
        query = '''
            UPDATE ezafe_table
            SET status = ?
            WHERE id = ?
        '''
        cursor.execute(query, (data.status, data.id))
        if cursor.rowcount:
            _notify_requester_status('ezafe_table', data.id, data.status, 'اضافه‌کاری')
        conn.commit()

        if cursor.rowcount == 0:
            return JSONResponse(status_code=404, content={'success': False, 'message': 'رکوردی برای به‌روزرسانی پیدا نشد.'})

        return {'success': True}

    except Exception as e:
        print("Error updating individual overtime status:", e)
        return JSONResponse(status_code=500, content={'success': False, 'message': 'خطا در به‌روزرسانی وضعیت.'})

# تابع گزارش انفرادی اضافه کاری # تابع گزارش انفرادی اضافه کاری # تابع گزارش انفرادی اضافه کاری
# تابع گزارش انفرادی اضافه کاری # تابع گزارش انفرادی اضافه کاری # تابع گزارش انفرادی اضافه کاری
# تابع گزارش انفرادی اضافه کاری # تابع گزارش انفرادی اضافه کاری # تابع گزارش انفرادی اضافه کاری

# رندر کردن صفحه گزارش اضافه‌کاری
@app.get("/overtime_report_page", response_class=HTMLResponse)
async def overtime_report_page(request: Request):
    return templates.TemplateResponse(request, "overtime_report_page.html", {"request": request})

@app.get("/overtime_report", response_class=HTMLResponse)
async def overtime_report(request: Request):
    auth_err = _require_auth(request)
    if auth_err:
        return auth_err
    try:
        query = "SELECT username, total_ezafe_time FROM ezafe_total_table"
        cursor.execute(query)
        results = cursor.fetchall()

        reports = [
            {
                "username": row[0],
                "total_ezafe_time": row[1],
                "row_number": idx + 1
            }
            for idx, row in enumerate(results)
        ]

        return templates.TemplateResponse(request, "overtime_report.html", {"request": request, "reports": reports})
    except Exception as e:
        print("Error:", e)
        return templates.TemplateResponse(request, "overtime_report.html", {"request": request, "reports": []})

@app.post("/get_overtime_report")
async def get_overtime_report(data: dict, request: Request):
    # See /get_hourly_pass_report: unauthenticated callers could request
    # ``all_users`` and read every employee's overtime record.
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    username = data.get('username')
    start_date_str = convert_farsi_to_english(str(data.get('start_date', '')))
    end_date_str = convert_farsi_to_english(str(data.get('end_date', '')))

    try:
        start_date_jalali = JalaliDate(*map(int, start_date_str.split('/')))
        end_date_jalali = JalaliDate(*map(int, end_date_str.split('/')))
        start_date = start_date_jalali.to_gregorian()
        end_date = end_date_jalali.to_gregorian()
    except (ValueError, TypeError):
        return JSONResponse({'error': 'تاریخ وارد شده صحیح نیست. لطفاً فرمت صحیح را وارد کنید.'}, status_code=400)

    if username == 'all_users':
        query = '''
            SELECT id, overtime_date, description, status, username, daily_overtime, from_time, to_time
            FROM ezafe_table
            WHERE overtime_date BETWEEN ? AND ?
        '''
        cursor.execute(query, (start_date, end_date))
    else:
        query = '''
            SELECT id, overtime_date, description, status, username, daily_overtime, from_time, to_time
            FROM ezafe_table
            WHERE username = ? AND overtime_date BETWEEN ? AND ?
        '''
        cursor.execute(query, (username, start_date, end_date))

    rows = cursor.fetchall()
    result = []

    for row in rows:
        overtime_date_shamsi = JalaliDate(row.overtime_date).strftime('%Y/%m/%d')
        daily_overtime_shamsi = row.daily_overtime.strftime('%H:%M') if row.daily_overtime else None
        from_time_shamsi = row.from_time.strftime('%H:%M') if row.from_time else None
        to_time_shamsi = row.to_time.strftime('%H:%M') if row.to_time else None

        result.append({
            'id': row.id,
            'overtime_date': convert_to_persian_numbers(overtime_date_shamsi),
            'description': row.description,
            'status': row.status,
            'username': row.username,
            'daily_overtime': convert_to_persian_numbers(daily_overtime_shamsi) if daily_overtime_shamsi else None,
            'from_time': convert_to_persian_numbers(from_time_shamsi) if from_time_shamsi else None,
            'to_time': convert_to_persian_numbers(to_time_shamsi) if to_time_shamsi else None
        })

    return JSONResponse(result)

# تابع دریافت درخواست ها برای ادمین # تابع دریافت درخواست ها برای ادمین # تابع دریافت درخواست ها برای ادمین # تابع دریافت درخواست ها برای ادمین
# تابع دریافت درخواست ها برای ادمین # تابع دریافت درخواست ها برای ادمین # تابع دریافت درخواست ها برای ادمین # تابع دریافت درخواست ها برای ادمین
# تابع دریافت درخواست ها برای ادمین # تابع دریافت درخواست ها برای ادمین # تابع دریافت درخواست ها برای ادمین # تابع دریافت درخواست ها برای ادمین

@app.get("/get_ticket_requests_admin")
async def get_ticket_requests_admin(request: Request):
    return JSONResponse(status_code=410, content={"success": False, "error": "این مسیر قدیمی تیکت منسوخ شده است."})
    actor, auth_error = _ticket_actor(request, admin_only=True)
    if auth_error:
        return auth_error

    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        query = '''
            WITH RankedTickets AS (
                SELECT
                    id, ticketTitle, ticketDescription, username, ticket_date,
                    ticket_status, target_username, Parent_id, is_read,
                    COUNT(*) OVER (PARTITION BY Parent_id) AS message_count,
                    ROW_NUMBER() OVER (
                        PARTITION BY Parent_id ORDER BY ticket_date DESC, id DESC
                    ) AS RowNum
                FROM ticket_table
                WHERE LTRIM(RTRIM(target_username)) = ?
                   OR LTRIM(RTRIM(username)) = ?
            )
            SELECT id, ticketTitle, ticketDescription, username, ticket_date,
                   ticket_status, target_username, Parent_id, is_read, message_count
            FROM RankedTickets
            WHERE RowNum = 1
            ORDER BY ticket_date DESC, id DESC
        '''
        cursor.execute(query, (actor, actor))
        ticket_data = []
        for ticket in cursor.fetchall():
            ticket_data.append({
                "id": ticket[0],
                "ticketTitle": ticket[1],
                "ticketDescription": ticket[2],
                "username": str(ticket[3] or "").strip(),
                "ticket_date": _ticket_date_text(ticket[4]),
                "ticket_status": str(ticket[5] or "ارسال شده").strip(),
                "target_username": str(ticket[6] or "").strip(),
                "parent_id": ticket[7],
                "is_read": bool(ticket[8]) if ticket[8] is not None else False,
                "message_count": ticket[9],
            })
        return JSONResponse(content=ticket_data)
    except Exception:
        return JSONResponse(content={"success": False, "error": "خطا در دریافت تیکت‌ها."}, status_code=500)
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()

# تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت
# تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت
# تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت # تابع حذف تیکت

@app.post("/delete-ticket")
async def delete_ticket(request: Request):
    return JSONResponse(status_code=410, content={"success": False, "error": "این مسیر قدیمی تیکت منسوخ شده است."})
    actor, auth_error = _ticket_actor(request)
    if auth_error:
        return auth_error

    conn = None
    cursor = None
    try:
        data = await request.json()
        ticket_id = int(data.get("ticket_id"))
        conn = get_db_connection()
        cursor = conn.cursor()
        ticket = _ticket_accessible_row(cursor, ticket_id, actor, request.session.get("is_admin") is True)
        if ticket is None:
            return JSONResponse(status_code=404, content={"success": False, "error": "تیکت یافت نشد."})

        cursor.execute("DELETE FROM ticket_table WHERE Parent_id = ?", (ticket[6],))
        conn.commit()
        return JSONResponse(content={"success": True})
    except (TypeError, ValueError):
        return JSONResponse(status_code=400, content={"success": False, "error": "شناسه تیکت معتبر نیست."})
    except Exception:
        if conn is not None:
            conn.rollback()
        return JSONResponse(status_code=500, content={"success": False, "error": "خطا در حذف تیکت."})
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()

# تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت
# تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت
# تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت # تابع بروزرسانی تیکت

@app.post("/update_ticket")
async def update_ticket(request: Request):
    return JSONResponse(status_code=410, content={"success": False, "error": "این مسیر قدیمی تیکت منسوخ شده است."})
    actor, auth_error = _ticket_actor(request)
    if auth_error:
        return auth_error

    conn = None
    cursor = None
    try:
        data = await request.json()
        ticket_id = int(data.get("id"))
        receiver = str(data.get("receiver") or "").strip()
        title = _ticket_text(data.get("title"), TICKET_TITLE_MAX_LENGTH, "عنوان تیکت")
        description = _ticket_text(data.get("description"), TICKET_DESCRIPTION_MAX_LENGTH, "توضیحات")
        if not receiver:
            raise ValueError("دریافت‌کننده تیکت الزامی است.")

        conn = get_db_connection()
        cursor = conn.cursor()
        ticket = _ticket_accessible_row(cursor, ticket_id, actor, request.session.get("is_admin") is True)
        if ticket is None or request.session.get("is_admin") is True or str(ticket[3] or "").strip() != actor:
            return JSONResponse(status_code=403, content={"success": False, "error": "ویرایش این تیکت مجاز نیست."})
        if str(ticket[5] or "").strip() != "ارسال شده":
            return JSONResponse(status_code=409, content={"success": False, "error": "این تیکت دیگر قابل ویرایش نیست."})

        cursor.execute("SELECT 1 FROM user_table WHERE LTRIM(RTRIM(username)) = ?", (receiver,))
        if cursor.fetchone() is None:
            return JSONResponse(status_code=404, content={"success": False, "error": "دریافت‌کننده پیدا نشد."})

        cursor.execute("""
            UPDATE ticket_table
            SET target_username = ?, ticketTitle = ?, ticketDescription = ?
            WHERE id = ? AND LTRIM(RTRIM(username)) = ?
        """, (receiver, title, description, ticket_id, actor))
        conn.commit()
        return JSONResponse(content={"success": True})
    except (TypeError, ValueError):
        return JSONResponse(status_code=400, content={"success": False, "error": "اطلاعات تیکت معتبر نیست."})
    except Exception:
        if conn is not None:
            conn.rollback()
        return JSONResponse(status_code=500, content={"success": False, "error": "خطا در ویرایش تیکت."})
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()

# تابع دریافت درخواست تیکت ها # تابع دریافت درخواست تیکت ها # تابع دریافت درخواست تیکت ها # تابع دریافت درخواست تیکت ها
# تابع دریافت درخواست تیکت ها # تابع دریافت درخواست تیکت ها # تابع دریافت درخواست تیکت ها # تابع دریافت درخواست تیکت ها
# تابع دریافت درخواست تیکت ها # تابع دریافت درخواست تیکت ها # تابع دریافت درخواست تیکت ها # تابع دریافت درخواست تیکت ها

@app.get("/get_ticket_requests")
async def get_ticket_requests(request: Request):
    return JSONResponse(status_code=410, content={"success": False, "error": "این مسیر قدیمی تیکت منسوخ شده است."})
    actor, auth_error = _ticket_actor(request)
    if auth_error:
        return auth_error

    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        cursor.execute("""
            SELECT id, ticketTitle, ticketDescription, username, ticket_date,
                   ticket_status, target_username, Parent_id
            FROM ticket_table
            WHERE LTRIM(RTRIM(username)) = ? OR LTRIM(RTRIM(target_username)) = ?
            ORDER BY ticket_date DESC, id DESC
        """, (actor, actor))
        ticket_data = []
        for ticket in cursor.fetchall():
            ticket_data.append({
                "id": ticket[0],
                "ticketTitle": ticket[1],
                "ticketDescription": ticket[2],
                "username": str(ticket[3] or "").strip(),
                "ticket_date": _ticket_date_text(ticket[4]),
                "ticket_status": str(ticket[5] or "ارسال شده").strip(),
                "target_username": str(ticket[6] or "").strip(),
                "parent_id": ticket[7],
            })
        return JSONResponse(content=ticket_data)
    except Exception:
        return JSONResponse(content={"success": False, "error": "خطا در دریافت تیکت‌ها."}, status_code=500)
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()

# تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت
# تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت
# تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت # تابع بروزرسانی وضعیت تیکت

@app.post("/update_ticket_status")
async def update_ticket_status(request: Request):
    return JSONResponse(status_code=410, content={"success": False, "error": "این مسیر قدیمی تیکت منسوخ شده است."})
    actor, auth_error = _ticket_actor(request)
    if auth_error:
        return auth_error

    conn = None
    cursor = None
    try:
        data = await request.json()
        ticket_id = int(data.get("id"))
        new_status = str(data.get("ticket_status") or "").strip()
        if new_status not in TICKET_STATUSES:
            return JSONResponse(status_code=400, content={"success": False, "error": "وضعیت تیکت معتبر نیست."})

        conn = get_db_connection()
        cursor = conn.cursor()
        ticket = _ticket_accessible_row(cursor, ticket_id, actor, request.session.get("is_admin") is True)
        if ticket is None:
            return JSONResponse(status_code=404, content={"success": False, "error": "تیکت یافت نشد."})

        cursor.execute("UPDATE ticket_table SET ticket_status = ? WHERE Parent_id = ?", (new_status, ticket[6]))
        conn.commit()
        return JSONResponse(content={"success": True})
    except (TypeError, ValueError):
        return JSONResponse(status_code=400, content={"success": False, "error": "اطلاعات تیکت معتبر نیست."})
    except Exception:
        if conn is not None:
            conn.rollback()
        return JSONResponse(status_code=500, content={"success": False, "error": "خطا در تغییر وضعیت تیکت."})
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()


@app.get('/get_ticket_details/{ticket_id}')
async def get_ticket_details(request: Request, ticket_id: int):
    return JSONResponse(status_code=410, content={"error": "این مسیر قدیمی تیکت منسوخ شده است."})
    actor, auth_error = _ticket_actor(request)
    if auth_error:
        return auth_error
    is_admin = request.session.get("is_admin") is True
    try:
        # دریافت تیکت اصلی
        cursor.execute('''
            SELECT id, ticketTitle, ticketDescription, username, ticket_date, ticket_status, parent_id, target_username
            FROM ticket_table
            WHERE id = ?
        ''', (ticket_id,))
        ticket = cursor.fetchone()

        if not ticket:
            return JSONResponse(content={"error": "Ticket not found"}, status_code=404)

        if not is_admin:
            cursor.execute("""
                SELECT 1 FROM ticket_table
                WHERE Parent_id = ?
                  AND (LTRIM(RTRIM(username)) = ? OR LTRIM(RTRIM(target_username)) = ?)
            """, (ticket.parent_id, actor, actor))
            if cursor.fetchone() is None:
                return JSONResponse(content={"error": "دسترسی غیرمجاز"}, status_code=403)

        ticket_datetime = ticket.ticket_date
        ticket_date_shamsi = JalaliDate(ticket_datetime).strftime('%Y/%m/%d')
        ticket_time = ticket_datetime.strftime('%H:%M')

        parent_id = ticket.parent_id
        target_username = ticket.target_username

        # دریافت پیام‌های مرتبط با parent_id
        cursor.execute('''
            SELECT ticketDescription, username, ticket_date
            FROM ticket_table
            WHERE parent_id = ?
            ORDER BY ticket_date ASC
        ''', (parent_id,))
        messages = cursor.fetchall()

        messages_list = []
        for msg in messages:
            msg_datetime = msg.ticket_date
            msg_date_shamsi = JalaliDate(msg_datetime).strftime('%Y/%m/%d')
            msg_time = msg_datetime.strftime('%H:%M')
            messages_list.append({
                'ticketDescription': msg.ticketDescription,
                'username': msg.username,
                'ticket_date': f"{msg_date_shamsi} - {msg_time}"
            })

        ticket_details = {
            'id': ticket.id,
            'ticketTitle': ticket.ticketTitle,
            'ticketDescription': ticket.ticketDescription,
            'username': ticket.username,
            'ticket_date': f"{ticket_date_shamsi} - {ticket_time}",
            'ticket_status': ticket.ticket_status,
            'parent_id': parent_id,
            'target_username': target_username,
            'messages': messages_list
        }

        return JSONResponse(content=ticket_details)

    except Exception as e:
        return JSONResponse(content={"error": "خطا در دریافت جزئیات تیکت."}, status_code=500)

@app.get('/get_ticket_details_payam/{ticket_id}')
async def get_ticket_details_payam(request: Request, ticket_id: int):
    return JSONResponse(status_code=410, content={"error": "این مسیر قدیمی تیکت منسوخ شده است."})
    actor, auth_error = _ticket_actor(request)
    if auth_error:
        return auth_error
    is_admin = request.session.get("is_admin") is True
    try:
        # دریافت تیکت اصلی
        cursor.execute('''
            SELECT id, ticketTitle, ticketDescription, username, ticket_date, ticket_status, parent_id, target_username
            FROM ticket_table
            WHERE id = ?
        ''', (ticket_id,))
        ticket = cursor.fetchone()

        if not ticket:
            return JSONResponse(content={"error": "Ticket not found"}, status_code=404)

        if not is_admin:
            cursor.execute("""
                SELECT 1 FROM ticket_table
                WHERE Parent_id = ?
                  AND (LTRIM(RTRIM(username)) = ? OR LTRIM(RTRIM(target_username)) = ?)
            """, (ticket.parent_id, actor, actor))
            if cursor.fetchone() is None:
                return JSONResponse(content={"error": "دسترسی غیرمجاز"}, status_code=403)

        ticket_datetime = ticket.ticket_date
        ticket_date_shamsi = JalaliDate(ticket_datetime).strftime('%Y/%m/%d')
        ticket_time = ticket_datetime.strftime('%H:%M')

        parent_id = ticket.parent_id
        target_username = ticket.target_username

        # دریافت پیام‌های مربوط به parent_id
        cursor.execute('''
            SELECT ticketDescription, username, ticket_date
            FROM ticket_table
            WHERE parent_id = ?
            ORDER BY ticket_date ASC
        ''', (parent_id,))
        messages = cursor.fetchall()

        messages_list = []
        for msg in messages:
            msg_datetime = msg.ticket_date
            msg_date_shamsi = JalaliDate(msg_datetime).strftime('%Y/%m/%d')
            msg_time = msg_datetime.strftime('%H:%M')
            messages_list.append({
                'ticketDescription': msg.ticketDescription,
                'username': msg.username,
                'ticket_date': f"{msg_date_shamsi} - {msg_time}"
            })

        ticket_details = {
            'id': ticket.id,
            'ticketTitle': ticket.ticketTitle,
            'ticketDescription': ticket.ticketDescription,
            'username': ticket.username,
            'ticket_date': f"{ticket_date_shamsi} - {ticket_time}",
            'ticket_status': ticket.ticket_status,
            'parent_id': parent_id,
            'target_username': target_username,
            'messages': messages_list
        }

        return JSONResponse(content=ticket_details)

    except Exception as e:
        return JSONResponse(content={"error": "خطا در دریافت جزئیات تیکت."}, status_code=500)

@app.post('/add_ticket_response')
async def add_ticket_response(request: Request):
    return await _create_ticket_response(request)

@app.post('/add_ticket_response_userpanel')
async def add_ticket_response_userpanel(request: Request):
    return await _create_ticket_response(request)
    
@app.post('/mark_ticket_as_read/{ticket_id}')
async def mark_ticket_as_read(request: Request, ticket_id: int):
    return JSONResponse(status_code=410, content={"success": False, "error": "این مسیر قدیمی تیکت منسوخ شده است."})
    actor, auth_error = _ticket_actor(request)
    if auth_error:
        return auth_error

    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()
        ticket = _ticket_accessible_row(cursor, ticket_id, actor, request.session.get("is_admin") is True)
        if ticket is None:
            return JSONResponse(status_code=404, content={"success": False, "error": "تیکت یافت نشد."})

        # فقط پیام‌هایی که برای همین کاربر ارسال شده‌اند خوانده می‌شوند.
        cursor.execute("""
            UPDATE ticket_table SET is_read = 1
            WHERE Parent_id = ? AND LTRIM(RTRIM(target_username)) = ?
        """, (ticket[6], actor))
        conn.commit()
        return JSONResponse(content={"success": True})
    except Exception:
        if conn is not None:
            conn.rollback()
        return JSONResponse(status_code=500, content={"success": False, "error": "خطا در ثبت وضعیت خوانده‌شدن."})
    finally:
        if cursor is not None:
            cursor.close()
        if conn is not None:
            conn.close()

# تابع دریافت گزارش حضور و غیاب # تابع دریافت گزارش حضور و غیاب # تابع دریافت گزارش حضور و غیاب # تابع دریافت گزارش حضور و غیاب
# تابع دریافت گزارش حضور و غیاب # تابع دریافت گزارش حضور و غیاب # تابع دریافت گزارش حضور و غیاب # تابع دریافت گزارش حضور و غیاب
# تابع دریافت گزارش حضور و غیاب # تابع دریافت گزارش حضور و غیاب # تابع دریافت گزارش حضور و غیاب # تابع دریافت گزارش حضور و غیاب

# تبدیل اعداد فارسی به انگلیسی
def convert_farsi_to_english(text: str) -> str:
    farsi_to_english = {
        '۰': '0', '۱': '1', '۲': '2', '۳': '3', '۴': '4',
        '۵': '5', '۶': '6', '۷': '7', '۸': '8', '۹': '9'
    }
    for farsi, english in farsi_to_english.items():
        text = text.replace(farsi, english)
    return text


def normalize_time_value(value) -> str:
    if value is None:
        return "0000"

    if isinstance(value, time):
        return value.strftime("%H%M")

    if isinstance(value, datetime):
        return value.strftime("%H%M")

    if isinstance(value, (int, float)):
        return f"{int(value):04d}"

    text = str(value).strip()
    if not text:
        return "0000"

    lowered = text.lower()
    if lowered in {"none", "null", "na", "n/a", "no", "nok", "unknown", "-"}:
        return "0000"

    cleaned = ''.join(ch for ch in text if ch.isdigit())
    if not cleaned:
        return "0000"

    return cleaned[:4].zfill(4)

from datetime import time

@app.get("/get_hozoor/{username}")
def get_hozoor(request: Request, username: str, start_date: str = Query(...), end_date: str = Query(...)):
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    # نرمال‌سازی نام کاربر و تاریخ‌ها قبل از دسترسی به دیتابیس.
    username = str(username or "").strip()
    start_date = convert_farsi_to_english(start_date).strip()
    end_date = convert_farsi_to_english(end_date).strip()
    print(f"[HOZOOR-DBG] endpoint hit: user={username!r} start={start_date!r} end={end_date!r}", flush=True)
    try:
        from_g = JalaliDate.strptime(start_date, "%Y/%m/%d").to_gregorian()
        to_g = JalaliDate.strptime(end_date, "%Y/%m/%d").to_gregorian()
    except (TypeError, ValueError):
        return JSONResponse(status_code=400, content={"error": "بازه تاریخ معتبر نیست."})
    if from_g > to_g:
        return JSONResponse(status_code=400, content={"error": "تاریخ شروع نباید بعد از تاریخ پایان باشد."})

    # اتصال به SQL Server و گرفتن اطلاعات کاربر.
    conn = pyodbc.connect(_db_connection_string())
    cursor = conn.cursor()

    cursor.execute("""
        SELECT hozoor_num, work_hours, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh
        FROM user_table
        WHERE LTRIM(RTRIM(username)) = LTRIM(RTRIM(?))
    """, (username,))
    user = cursor.fetchone()

    if not user:
        return JSONResponse(content={"error": "کاربر پیدا نشد."}, status_code=404)

    hozoor_num, default_work_hours, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh = user
    weekday_map = {0: shanbeh, 1: yekshanbeh, 2: doshanbeh, 3: seshanbeh, 4: chrshanbeh, 5: panjshanbeh}

    # خواندن شیفت‌های اختصاصی این کاربر از جدول shiftha
    shift_rows = []
    try:
        cursor.execute("""
            SELECT jalali_year, jalali_month, start_day, end_day,
                   shanbeh, yekshanbeh, doshanbeh, seshanbeh, chaharshanbeh, panjshanbeh, jomeh
            FROM shiftha
            WHERE REPLACE(REPLACE(LTRIM(RTRIM(username)), N'ي', N'ی'), N'ك', N'ک') = ?
            ORDER BY jalali_year, jalali_month, start_day
        """, (_normalize_fa_username(username),))
        shift_rows = [{
            'jalali_year': r[0], 'jalali_month': r[1], 'start_day': r[2], 'end_day': r[3],
            'shanbeh': r[4], 'yekshanbeh': r[5], 'doshanbeh': r[6], 'seshanbeh': r[7],
            'chaharshanbeh': r[8], 'panjshanbeh': r[9], 'jomeh': r[10]
        } for r in cursor.fetchall()]
    except Exception as shift_err:
        print(f"get_hozoor shiftha fallback for {username}: {shift_err}")

    def resolve_work_hours(sh_year, sh_month, sh_day, wd):
        # اول دنبال بازه‌ی تعریف‌شده در shiftha برای همین ماه/روز می‌گردیم.
        for row in shift_rows:
            try:
                in_range = (
                    int(row.get('jalali_year') or 0) == sh_year and
                    int(row.get('jalali_month') or 0) == sh_month and
                    int(row.get('start_day') or 0) <= sh_day <= int(row.get('end_day') or 0)
                )
            except (TypeError, ValueError):
                in_range = False
            if in_range:
                val = row.get(SHIFT_DAY_COLUMNS[wd])
                if val and "-" in str(val):
                    return str(val)
                break

        # اگر برنامه‌ی هفتگی هم خالی باشد، بازه‌ی صفر برمی‌گردانیم تا endpoint خطا ندهد.
        value = weekday_map.get(wd) or default_work_hours or "00:00-00:00"
        value = str(value).replace(" ", "")
        return value if "-" in value else "00:00-00:00"

    # Access ممکن است روی سرور فعلی نصب یا در دسترس نباشد؛ در این حالت
    # گزارش از رکوردهای hozoor در SQL Server ساخته می‌شود و 500 برنمی‌گرداند.
    rows = []
    conn_access = None
    cursor_access = None
    _access_error_msg = None
    _mdb_path = config("ARAZ_ACCESS_PATH", default=r"E:\Hastama\database\Arazdb.mdb")
    try:
        password = config("ARAZ_ACCESS_PASSWORD", default="")
        if not password:
            raise RuntimeError("ARAZ_ACCESS_PASSWORD not configured")
        conn_str = (r"DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};"
                    rf"DBQ={_mdb_path};"
                    rf"PWD={password};")
        conn_access = pyodbc.connect(conn_str)
        cursor_access = conn_access.cursor()
        query = """
        SELECT CardNo, Date, Time, InOutType
        FROM TPrsInOut
        WHERE CardNo = ? AND Date BETWEEN ? AND ?
        """
        cursor_access.execute(query, (hozoor_num, start_date, end_date))
        rows = cursor_access.fetchall()
    except Exception as access_error:
        _access_error_msg = str(access_error)
        print(f"get_hozoor Access fallback for {username}: {access_error}")
    finally:
        if cursor_access is not None:
            cursor_access.close()
        if conn_access is not None:
            conn_access.close()

    # ساخت دیکشنری attendance از داده‌های اکسس (کمترین entry، بیشترین exit)
    attendance = {}

    for row in rows:
        card_no, date_val, time_val, in_out_type = row

        date_str = str(date_val).replace("/", "-")
        time_val = normalize_time_value(time_val)

        if date_str not in attendance:
            attendance[date_str] = {
                "CardNo": card_no,
                "Date": date_str,
                "Times": []
            }

        attendance[date_str]["Times"].append(time_val)

    for date_str in attendance:
        times = sorted(attendance[date_str]["Times"])

        attendance[date_str]["EntryTime"] = "0000"
        attendance[date_str]["ExitTime"] = "0000"
        attendance[date_str]["EntryTime2"] = "0000"
        attendance[date_str]["ExitTime2"] = "0000"

        if len(times) >= 1:
            attendance[date_str]["EntryTime"] = times[0]

        if len(times) >= 2:
            attendance[date_str]["ExitTime"] = times[1]

        if len(times) >= 3:
            attendance[date_str]["EntryTime2"] = times[2]

        if len(times) >= 4:
            attendance[date_str]["ExitTime2"] = times[3]

    # حالا از جدول hozoor در SQL Server تاریخ‌های ثبت‌شده رو هم اضافه کن
    try:
        cursor.execute("""
            SELECT [date], vrood, khoroj FROM hozoor
            WHERE LTRIM(RTRIM(username)) = LTRIM(RTRIM(?)) AND [date] BETWEEN ? AND ?
            ORDER BY [date]
        """, (username, from_g, to_g))
        rows_sql = cursor.fetchall()
        print(f"[HOZOOR-DBG] SQL hozoor table returned {len(rows_sql)} rows for {username!r}, from_g={from_g}, to_g={to_g}", flush=True)

        for row in rows_sql:
            g_date, vrood, khoroj = row
            shamsi = JalaliDate(g_date).strftime('%Y-%m-%d')
            if shamsi not in attendance:
                entry = normalize_time_value(vrood)
                exit_ = normalize_time_value(khoroj)
                attendance[shamsi] = {"CardNo": "DB", "Date": shamsi, "EntryTime": entry, "ExitTime": exit_}
    except Exception as hozoor_err:
        print(f"get_hozoor hozoor table fallback for {username}: {hozoor_err}")

    # **اضافه کردن تمام تاریخ‌های بین from_g و to_g که رکورد ندارند**
    total_days = (to_g - from_g).days
    for i in range(total_days + 1):
        g = from_g + timedelta(days=i)
        shamsi = JalaliDate(g).strftime('%Y-%m-%d')
        if shamsi not in attendance:
            # Entry/Exit پیش‌فرض برای روزهای بدون رکورد
            attendance[shamsi] = {
                "CardNo": "",
                "Date": shamsi,
                "EntryTime": "0000",
                "ExitTime": "0000",
                "EntryTime2": "0000",
                "ExitTime2": "0000"
            }

    # حالا پردازش نهایی و تعیین وضعیت — خروجی را به صورت مرتب (بر اساس تاریخ) می‌دهیم
    non_zero = sum(1 for d in attendance.values() if d.get('EntryTime','0000') != '0000' or d.get('ExitTime','0000') != '0000')
    print(f"[HOZOOR-DBG] attendance has {len(attendance)} days, {non_zero} with actual data for {username!r}", flush=True)
    result = []
    for date_str in sorted(attendance.keys()):
        data = attendance[date_str]
        entry_time = normalize_time_value(data.get("EntryTime"))
        exit_time = normalize_time_value(data.get("ExitTime"))

        sh_year, sh_month, sh_day = map(int, date_str.split('-'))
        weekday = jdatetime.date(sh_year, sh_month, sh_day).weekday()  # مطابق کد قبلی شما
        work_hours = resolve_work_hours(sh_year, sh_month, sh_day, weekday).replace(" ", "")
        try:
            work_start, work_end = sorted(work_hours.split("-"), key=lambda x: int(x.replace(":", "")))
        except:
            work_start, work_end = "00:00", "00:00"

        data["WorkStart"] = work_start
        data["WorkEnd"] = work_end

        # اگر هیچ رکوردی وجود نداشته باشه (مثلاً جمعه یا روز غیبت)، به صورت مشخص علامت می‌زنیم
        if entry_time == "0000" and exit_time == "0000":
            if weekday == 6:  # جمعه
                data["Status"] = "تعطیل"
            else:
                data["Status"] = "غیبت"
            data["CalculatedTime"] = ""
            data["WorkedHours"] = "00:00"
            result.append(data)
            continue

        # در غیر اینصورت، محاسبات قبلی شما (تاخیر/اضافه کاری/خروج زودهنگام و ...) را انجام بده
        entry_hour, entry_minute = int(entry_time[:2]), int(entry_time[2:])
        exit_hour, exit_minute = int(exit_time[:2]), int(exit_time[2:])
        work_start_hour, work_start_minute = int(work_start[:2]), int(work_start[3:])
        work_end_hour, work_end_minute = int(work_end[:2]), int(work_end[3:])

        status_parts = []
        calculated_time = []
        worked_minutes = 0
        overtime_added = delay_added = early_exit_added = False

        if (entry_hour, entry_minute) == (work_start_hour, work_start_minute):
            status_parts.append("تایید سامانه در ورود")
            worked_minutes = (work_end_hour - work_start_hour) * 60 + (work_end_minute - work_start_minute)
        elif (entry_hour > work_start_hour) or (entry_hour == work_start_hour and entry_minute > work_start_minute):
            status_parts.append("ورود با تاخیر")
            if not delay_added:
                delay = (entry_hour - work_start_hour) * 60 + (entry_minute - work_start_minute)
                calculated_time.append(f"مدت زمان تاخیر: {delay//60:02}:{delay%60:02}")
                delay_added = True
            if (exit_hour > work_end_hour) or (exit_hour == work_end_hour and exit_minute > work_end_minute):
                overtime = (exit_hour - work_end_hour) * 60 + (exit_minute - work_end_minute)
                if overtime > 10 and not overtime_added:
                    status_parts.append("اضافه کاری")
                    calculated_time.append(f"مدت زمان اضافه کاری: {overtime//60:02}:{overtime%60:02}")
                    worked_minutes += overtime
                    overtime_added = True
                else:
                    worked_minutes += (work_end_hour - entry_hour) * 60 + (work_end_minute - entry_minute)
        else:
            status_parts.append("شروع زودهنگام")
            early = (work_start_hour - entry_hour) * 60 + (work_start_minute - entry_minute)
            calculated_time.append(f"مدت زمان شروع زودهنگام: {early//60:02}:{early%60:02}")
            worked_minutes = (work_end_hour - work_start_hour) * 60 + (work_end_minute - work_start_minute)

        if (exit_hour, exit_minute) == (work_end_hour, work_end_minute):
            status_parts.append("تایید سامانه در خروج")
        elif (exit_hour < work_end_hour) or (exit_hour == work_end_hour and exit_minute < work_end_minute):
            if not early_exit_added:
                status_parts.append("خروج زودهنگام")
                early_exit = (work_end_hour - exit_hour) * 60 + (work_end_minute - exit_minute)
                calculated_time.append(f"مدت زمان خروج زودهنگام: {early_exit//60:02}:{early_exit%60:02}")
                early_exit_added = True
        elif (exit_hour > work_end_hour) or (exit_hour == work_end_hour and exit_minute > work_end_minute):
            overtime = (exit_hour - work_end_hour) * 60 + (exit_minute - work_end_minute)
            if overtime > 10 and not overtime_added:
                status_parts.append("اضافه کاری")
                calculated_time.append(f"مدت زمان اضافه کاری: {overtime//60:02}:{overtime%60:02}")

        data["Status"] = ", ".join(status_parts)
        data["CalculatedTime"] = "<br>".join(calculated_time)
        data["WorkedHours"] = f"{worked_minutes // 60:02}:{worked_minutes % 60:02}"

        result.append(data)

    # نتیجه مرتب‌شده برگردون
    try:
        conn.close()
    except Exception:
        pass    # debug info into response
    _dbg = {
        "user": username,
        "hozoor_num": hozoor_num,
        "access_rows": len(rows),
        "access_error": _access_error_msg,
        "mdb_path": _mdb_path,
        "attendance_days": len(attendance),
        "non_zero_days": sum(1 for d in attendance.values() if d.get('EntryTime','0000') != '0000'),
    }
    return JSONResponse(content={"_debug": _dbg, "data": result})

# ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن
# ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن
# ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن # ثبت دستی ساعت زن

@app.post("/sabt_hozoor")
async def sabt_hozoor(request: Request):
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    data = await request.json()

    username = data.get("username") or data.get("usernamedast")
    tarikh_shamsi = data.get("tarikh")
    vorood_str = data.get("vorood")
    khorooj_str = data.get("khorooj")

    if not username or not tarikh_shamsi or not vorood_str or not khorooj_str:
        return JSONResponse(content={"success": False, "message": "لطفاً تمام فیلدها را پر کنید"})

    try:
        # تبدیل تاریخ شمسی به میلادی
        y, m, d = map(int, tarikh_shamsi.split("/"))
        tarikh_miladi = JalaliDate(y, m, d).to_gregorian()
        tarikh_obj = date(tarikh_miladi.year, tarikh_miladi.month, tarikh_miladi.day)

        # تبدیل ساعت‌ها
        vorood_obj = datetime.strptime(vorood_str, "%H:%M").time()
        khorooj_obj = datetime.strptime(khorooj_str, "%H:%M").time()

        # بررسی وجود رکورد قبلی
        cursor.execute("""
            SELECT COUNT(*) FROM hozoor
            WHERE username = ? AND [date] = ?
        """, (username, tarikh_obj))
        record_exists = cursor.fetchone()[0] > 0

        if record_exists:
            # اگر وجود داشت → UPDATE
            cursor.execute("""
                UPDATE hozoor
                SET vrood = ?, khoroj = ?
                WHERE username = ? AND [date] = ?
            """, (vorood_obj, khorooj_obj, username, tarikh_obj))
            message = "اطلاعات قبلی با موفقیت به‌روزرسانی شد"
        else:
            # اگر نبود → INSERT
            cursor.execute("""
                INSERT INTO hozoor (username, [date], vrood, khoroj)
                VALUES (?, ?, ?, ?)
            """, (username, tarikh_obj, vorood_obj, khorooj_obj))
            message = "اطلاعات با موفقیت ثبت شد"

        conn.commit()
        return JSONResponse(content={"success": True, "message": message})

    except Exception as e:
        conn.rollback()
        return JSONResponse(content={"success": False, "message": _safe_error_message(e)})


# ثبت ورود دستی (Check-In) از صفحه کاربران # ثبت ورود دستی (Check-In) از صفحه کاربران
# ثبت ورود دستی (Check-In) از صفحه کاربران # ثبت ورود دستی (Check-In) از صفحه کاربران
# ثبت ورود دستی (Check-In) از صفحه کاربران # ثبت ورود دستی (Check-In) از صفحه کاربران

def _attendance_actor(request: Request):
    """کاربر احراز هویت‌شدهٔ فعلی را برمی‌گرداند.

    خروجی یک tuple است: ``(username, None)`` در حالت موفق و
    ``(None, JSONResponse)`` در حالت عدم دسترسی.
    """
    username = request.session.get("username")
    if not username:
        return None, JSONResponse(
            status_code=401,
            content={"success": False, "message": "ورود به سامانه الزامی است."},
        )
    return username, None


def _attendance_payload_status(username: str, check_in: str | None, check_out: str | None):
    """ساخت بدنهٔ پاسخ مشترک برای وضعیت حضور."""
    return {
        "username": username,
        "status": compute_attendance_status(
            check_in, check_out
        )["status"],
        "check_in": check_in,
        "check_out": check_out,
        "server_now": datetime.now().strftime("%H:%M"),
    }


@app.post("/sabt_hozoor_checkin")
async def sabt_hozoor_checkin(request: Request):
    """ثبت ورود (Check-In) برای یک کاربر — زمان از سمت سرور تعیین می‌شود."""
    actor, _auth_error = _attendance_actor(request)
    if _auth_error:
        return _auth_error

    try:
        payload = await request.json()
    except Exception:
        return JSONResponse(status_code=400, content={"success": False, "message": "درخواست نامعتبر است."})

    username = (payload or {}).get("username") if isinstance(payload, dict) else None
    if not username:
        return JSONResponse(status_code=400, content={"success": False, "message": "کاربر مشخص نشده است."})
    username = str(username)
    if not request.session.get("is_admin") and username != actor:
        return JSONResponse(status_code=403, content={"success": False, "message": "دسترسی ثبت حضور کاربر دیگر مجاز نیست."})

    now = datetime.now()
    today = now.date()
    now_time = now.time()

    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        cursor.execute("SELECT username FROM user_table WHERE username = ?", (username,))
        if cursor.fetchone() is None:
            return JSONResponse(status_code=404, content={"success": False, "message": "کاربر مورد نظر یافت نشد."})

        # قفل برای جلوگیری از ثبت هم‌زمان.  ابتدا ورودِ فعال (بدون خروج) را
        # در همهٔ روزها می‌جوییم: شیفت شب ممکن است دیروز وارد شده باشد و تا
        # زمان ثبت خروج نباید ورود تازه‌ای برای او ثبت شود.
        cursor.execute(
            "SELECT TOP 1 [date], vrood FROM hozoor WITH (UPDLOCK, HOLDLOCK) "
            "WHERE username = ? AND vrood IS NOT NULL AND khoroj IS NULL "
            "ORDER BY [date] DESC",
            (username,),
        )
        active = cursor.fetchone()
        if active is not None and active[0] != today:
            return JSONResponse(status_code=409, content={"success": False, "message": "ورود فعالی از روز قبل بدون ثبت خروج مانده است؛ ابتدا خروج را ثبت کنید."})
        if active is not None:
            return JSONResponse(status_code=409, content={"success": False, "message": "ورود امروز قبلاً ثبت شده است."})

        cursor.execute(
            "SELECT vrood, khoroj FROM hozoor WITH (UPDLOCK, HOLDLOCK) "
            "WHERE username = ? AND [date] = ?",
            (username, today),
        )
        row = cursor.fetchone()
        if row is not None and row[0] is not None:
            if row[1] is None:
                return JSONResponse(status_code=409, content={"success": False, "message": "ورود امروز قبلاً ثبت شده است."})
            return JSONResponse(status_code=409, content={"success": False, "message": "ورود و خروج امروز قبلاً ثبت شده است."})

        if row is not None:
            cursor.execute(
                "UPDATE hozoor SET vrood = ?, khoroj = NULL "
                "WHERE username = ? AND [date] = ?",
                (now_time, username, today),
            )
        else:
            cursor.execute(
                "INSERT INTO hozoor (username, [date], vrood, khoroj) VALUES (?, ?, ?, NULL)",
                (username, today, now_time),
            )

        conn.commit()

        return JSONResponse(content={
            "success": True,
            "message": "ورود با موفقیت ثبت شد.",
            "data": _attendance_payload_status(username, now_time.strftime("%H:%M"), None),
        })

    except Exception as exc:
        if conn is not None:
            try:
                conn.rollback()
            except Exception:
                pass
        print("check-in error:", exc)
        return JSONResponse(status_code=500, content={"success": False, "message": "خطا در ثبت اطلاعات. لطفاً دوباره تلاش کنید."})
    finally:
        if cursor is not None:
            try:
                cursor.close()
            except Exception:
                pass
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass


@app.post("/sabt_hozoor_checkout")
async def sabt_hozoor_checkout(request: Request):
    """ثبت خروج (Check-Out) برای یک کاربر — زمان از سمت سرور تعیین می‌شود."""
    actor, _auth_error = _attendance_actor(request)
    if _auth_error:
        return _auth_error

    try:
        payload = await request.json()
    except Exception:
        return JSONResponse(status_code=400, content={"success": False, "message": "درخواست نامعتبر است."})

    username = (payload or {}).get("username") if isinstance(payload, dict) else None
    if not username:
        return JSONResponse(status_code=400, content={"success": False, "message": "کاربر مشخص نشده است."})
    username = str(username)
    if not request.session.get("is_admin") and username != actor:
        return JSONResponse(status_code=403, content={"success": False, "message": "دسترسی ثبت خروج کاربر دیگر مجاز نیست."})

    now = datetime.now()
    today = now.date()
    now_time = now.time()

    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        cursor.execute("SELECT username FROM user_table WHERE username = ?", (username,))
        if cursor.fetchone() is None:
            return JSONResponse(status_code=404, content={"success": False, "message": "کاربر مورد نظر یافت نشد."})

        # یافتن ورودِ فعال (بدون خروج) — برای شیفت شب ممکن است متعلق به روز قبل باشد
        cursor.execute(
            "SELECT TOP 1 [date], vrood FROM hozoor WITH (UPDLOCK, HOLDLOCK) "
            "WHERE username = ? AND vrood IS NOT NULL AND khoroj IS NULL "
            "ORDER BY [date] DESC",
            (username,),
        )
        active = cursor.fetchone()

        if active is None:
            return JSONResponse(status_code=409, content={"success": False, "message": "کاربر هیچ ورود فعالی ندارد."})

        active_date, active_vrood = active[0], active[1]
        check_in = format_time_value(active_vrood)

        cursor.execute(
            "UPDATE hozoor SET khoroj = ? WHERE username = ? AND [date] = ?",
            (now_time, username, active_date),
        )
        conn.commit()

        return JSONResponse(content={
            "success": True,
            "message": "خروج با موفقیت ثبت شد.",
            "data": _attendance_payload_status(username, check_in, now_time.strftime("%H:%M")),
        })

    except Exception as exc:
        if conn is not None:
            try:
                conn.rollback()
            except Exception:
                pass
        print("check-out error:", exc)
        return JSONResponse(status_code=500, content={"success": False, "message": "خطا در ثبت اطلاعات. لطفاً دوباره تلاش کنید."})
    finally:
        if cursor is not None:
            try:
                cursor.close()
            except Exception:
                pass
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass


@app.get("/get_hozoor_today")
async def get_hozoor_today(request: Request):
    """وضعیت حضور امروز را برای ادمین‌ها یا کاربر فعلی برمی‌گرداند."""
    actor, _auth_error = _attendance_actor(request)
    if _auth_error:
        return _auth_error

    today = datetime.now().date()
    conn = None
    cursor = None
    try:
        conn = get_db_connection()
        cursor = conn.cursor()

        # کاربران عادی فقط وضعیت خودشان را می‌خوانند؛ ادمین وضعیت همه را می‌گیرد.
        # برای جدول کاربران، فقط رکوردی معتبر است که تاریخ آن دقیقاً امروز باشد.
        # رکورد فعالِ روزهای قبل (مثلاً شیفت شب) نباید ساعت ورود امروز تلقی شود.
        if request.session.get("is_admin"):
            cursor.execute("""
                SELECT u.username, h.[date], h.vrood, h.khoroj
                FROM user_table u
                LEFT JOIN hozoor h ON u.username = h.username
                    AND h.[date] = ?
            """, (today,))
        else:
            cursor.execute("""
                                SELECT TOP 1 username, [date], vrood, khoroj
                FROM hozoor
                                WHERE username = ?
                                    AND [date] = ?
                ORDER BY [date] DESC
            """, (actor, today))
        rows = cursor.fetchall()

        by_user = {}
        for username, record_date, vrood, khoroj in rows:
            entry = by_user.setdefault(username, {"active": None, "today": None})
            if vrood is not None and khoroj is None:
                entry["active"] = (record_date, vrood)
            # نرمال‌سازی نوع تاریخ (درایور ممکن است datetime برگرداند)
            normalized_date = record_date.date() if isinstance(record_date, datetime) else record_date
            if normalized_date is not None and normalized_date == today:
                entry["today"] = (vrood, khoroj)

        users = []
        for username, entry in by_user.items():
            if entry["active"] is not None:
                item = {
                    "username": username,
                    "status": "checked_in",
                    "check_in": format_time_value(entry["active"][1]),
                    "check_out": None,
                }
            elif entry["today"] is not None and entry["today"][0] is not None and entry["today"][1] is not None:
                item = {
                    "username": username,
                    "status": "checked_out",
                    "check_in": format_time_value(entry["today"][0]),
                    "check_out": format_time_value(entry["today"][1]),
                }
            else:
                item = {
                    "username": username,
                    "status": "not_checked_in",
                    "check_in": None,
                    "check_out": None,
                }
            users.append(item)

        if not request.session.get("is_admin") and not users:
            users = [{
                "username": actor,
                "status": "not_checked_in",
                "check_in": None,
                "check_out": None,
            }]

        schedule = {"work_start": None, "work_end": None}
        if not request.session.get("is_admin"):
            cursor.execute("""
                SELECT work_hours, shanbeh, yekshanbeh, doshanbeh,
                       seshanbeh, chrshanbeh, panjshanbeh
                FROM user_table
                WHERE username = ?
            """, (actor,))
            schedule_row = cursor.fetchone()
            if schedule_row:
                default_hours = schedule_row[0]
                day_hours = {
                    0: schedule_row[1], 1: schedule_row[2],
                    2: schedule_row[3], 3: schedule_row[4],
                    4: schedule_row[5], 5: schedule_row[6],
                }
                today_jalali = JalaliDate.today()
                hours = day_hours.get(
                    jdatetime.date(
                        today_jalali.year, today_jalali.month, today_jalali.day
                    ).weekday()
                ) or default_hours
                if hours and "-" in str(hours):
                    schedule["work_start"], schedule["work_end"] = [
                        part.strip() for part in str(hours).split("-", 1)
                    ]

        return JSONResponse(content={
            "success": True,
            "data": {
                "server_now": datetime.now().strftime("%H:%M"),
                "users": users,
                "work_start": schedule["work_start"],
                "work_end": schedule["work_end"],
            },
        })

    except Exception as exc:
        print("get_hozoor_today error:", exc)
        return JSONResponse(status_code=500, content={"success": False, "message": "خطا در دریافت اطلاعات حضور و غیاب."})
    finally:
        if cursor is not None:
            try:
                cursor.close()
            except Exception:
                pass
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass


@app.get("/payroll_report_page", response_class=HTMLResponse)
async def payroll_report_page(request: Request):
    return templates.TemplateResponse(request, "payroll_report_page.html", {"request": request})


@app.get("/final_report_page", response_class=HTMLResponse)
async def final_report(request: Request):
    MONTH_NAMES = [
        "", "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور",
        "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"
    ]

    today = JalaliDate.today()
    month_name = MONTH_NAMES[today.month]
    year = today.year

    # ✅ چاپ در ترمینال برای تست
    print(f"📅 تاریخ امروز شمسی: {month_name} {year}")

    return templates.TemplateResponse(request, "final_report_page.html", {
        "request": request,
        "month_name": month_name,
        "year": year
    })

@app.get("/download_pdf")
async def download_pdf(request: Request):
    """گزارش نهایی را به PDF تبدیل می‌کند (مقاوم‌سازی‌شده در برابر CVE-2025-26240)."""
    auth_err = _require_auth(request)
    if auth_err:
        return auth_err

    # Security note (CVE-2025-26240 / GHSA-9g3x-6x24-vf9f, pdfkit <= 1.0.0):
    # ``pdfkit.from_string`` parses ``<meta name="pdfkit-...">`` tags out of the
    # HTML it is given and turns their content into wkhtmltopdf command line
    # options — including ``--post-file`` (local file disclosure) and ``--script``
    # (JavaScript/SSRF).  There is no patched pdfkit release, so the call site is
    # hardened instead: the HTML is rendered from a repository template
    # (autoescaped) and written to a private temporary file, then converted with
    # ``from_file``, which does not parse meta tags at all; the stylesheet is
    # inlined so no ``--user-style-sheet`` path is passed; and JavaScript and
    # local file access are explicitly disabled for wkhtmltopdf, which also
    # neutralises the option-override bypass described in the advisory.

    tmp_path = None
    try:
        # رندر کردن صفحه HTML
        try:
            template = templates.get_template('finalReportUser.html')
        except Exception:
            logger.error("final report PDF template is missing in this installation")
            return JSONResponse(
                status_code=503,
                content={"success": False, "message": "قالب گزارش نهایی در این نصب موجود نیست."},
            )
        html_content = template.render(request=request)

        # CSS را داخل HTML تزریق می‌کنیم (بدون دسترسی wkhtmltopdf به فایل‌های محلی)
        css_candidates = [
            os.path.join("app", "static", "css", "finalReportUserPrint.css"),
            os.path.join("static", "finalReportUserPrint.css"),
        ]
        for css_path in css_candidates:
            try:
                if os.path.isfile(css_path):
                    with open(css_path, encoding="utf-8") as handle:
                        css_text = handle.read()
                    if "</head>" in html_content:
                        html_content = html_content.replace(
                            "</head>", f"<style>{css_text}</style></head>", 1
                        )
                    break
            except OSError:
                continue

        # نوشتن در فایل موقت با نام تصادفی (خارج از دسترس مرورگر)
        import tempfile

        with tempfile.NamedTemporaryFile(
            "w", suffix=".html", encoding="utf-8", delete=False, dir=tempfile.gettempdir()
        ) as handle:
            handle.write(html_content)
            tmp_path = handle.name

        pdf_file = pdfkit.from_file(
            tmp_path,
            False,
            options={
                "encoding": "utf-8",
                "disable-local-file-access": None,
                "disable-javascript": None,
                # بدون این گزینه‌ها wkhtmltopdf می‌تواند فایل‌های سرور را بخواند
                "enable-local-file-access": False,
            },
        )
    except Exception as exc:
        logger.error("PDF generation failed: %s", type(exc).__name__)
        return JSONResponse(
            status_code=500,
            content={"success": False, "message": "خطا در تولید فایل PDF."},
        )
    finally:
        if tmp_path:
            try:
                os.unlink(tmp_path)
            except OSError:
                pass

    # ارسال PDF به عنوان دانلود
    pdf_io = BytesIO(pdf_file)
    return StreamingResponse(pdf_io, media_type="application/pdf", headers={"Content-Disposition": "attachment; filename=final_report.pdf"})

@app.get("/get_today_date")
async def get_today_date():
    today = JalaliDate.today()
    return JSONResponse(content={'year': today.year, 'month': today.month, 'day': today.day})   

# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه
# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه
# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه# تابع خروج از سامانه

@app.get("/logout")
async def logout(request: Request, response: Response):
    sid = str(request.session.get(_session_registry.SESSION_TOKEN_KEY) or "")
    legacy_user = "" if sid else str(request.session.get("username") or "")
    request.session.clear()
    # Also terminate the server-side session record, so a captured cookie
    # cannot be replayed after logout.
    if sid:
        _session_registry.revoke_session(sid, "self")
    elif legacy_user:
        _session_registry.revoke_user_sessions(legacy_user, "logout")

    # ریدایرکت به صفحه اصلی
    return RedirectResponse(url="/login")


@app.get("/api/system-config")
async def public_system_config():
    """Public endpoint: return public-facing system config keys (no auth required)."""
    from app.core.database import connect as db_connect
    keys = ('captcha_enabled', 'idle_timeout_enabled', 'idle_timeout_seconds')
    config = {'captcha_enabled': '1', 'idle_timeout_enabled': '1', 'idle_timeout_seconds': '300'}
    try:
        conn = db_connect()
        cur = conn.cursor()
        cur.execute("SELECT config_key, config_value FROM system_config WHERE config_key IN (?,?,?)", keys)
        for row in cur.fetchall():
            config[row[0]] = row[1]
        conn.close()
    except Exception:
        pass
    return JSONResponse(content={"success": True, "data": config})


@app.post("/api/session/destroy")
async def destroy_session(request: Request):
    """Destroy the session when the browser/tab is closed."""
    sid = str(request.session.get(_session_registry.SESSION_TOKEN_KEY) or "")
    if sid:
        _session_registry.revoke_session(sid, str(request.session.get("username") or "self"))
    request.session.clear()
    return JSONResponse(content={"success": True})
