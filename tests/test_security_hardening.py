"""Behavioural security regression tests for the hardening work.

Unlike ``tests/test_security_regressions.py`` (which greps source text and can
pass while the control is missing — the WebSocket test in that file was failing
because the endpoint really had no session check), the tests below drive the
real code paths and assert on observed behaviour:

* the signed-cookie helpers agree with the *installed* Starlette version
  (a salt mismatch made both security middlewares fail open — regression test
  ``test_matches_installed_session_middleware``);
* the CSRF middleware accepts exactly the requests it should and rejects the
  rest, end-to-end through SessionMiddleware;
* a revoked/absent server-side session cannot be used, even with a validly
  signed cookie;
* login refuses disabled accounts, throttles failures and mints the
  session-bound CSRF token;
* password recovery cannot be used to enumerate users and fails closed when
  the HMAC key is missing;
* the master-admin control plane refuses ordinary administrators;
* the Araz bridge fails closed and validates its input;
* kiosk/call endpoints are origin- and rate-limited, and slide management
  requires an administrator;
* ``X-Forwarded-For`` spoofing cannot influence IP-based controls;
* user input is validated before it is stored (stored-XSS defence in depth);
* the database proxies hand each request its own connection and cursor.
"""
from __future__ import annotations

import asyncio
import base64
import json
import os
import time

import pytest
from starlette.applications import Starlette
from starlette.middleware.sessions import SessionMiddleware
from starlette.responses import JSONResponse, PlainTextResponse
from starlette.routing import Route
from starlette.testclient import TestClient

os.environ.setdefault("SESSION_SECRET_KEY", "unit-test-session-secret")
os.environ.setdefault("DEBUG", "false")

import app.main as main  # noqa: E402  (must come after the env vars)
from app.core import sessions as session_registry  # noqa: E402
from app.core.net import client_ip  # noqa: E402
from app.core.session_cookie import installed_signer, parse_session_cookie  # noqa: E402
from app.core.rate_limit import SlidingWindowLimiter  # noqa: E402
from app.core.validation import clean_display_text, strip_control  # noqa: E402
from app.services import audit  # noqa: E402


SECRET = os.environ["SESSION_SECRET_KEY"]
MAX_AGE = 3600


# ── helpers ────────────────────────────────────────────────────────────────

def _routes():
    async def info(request):
        return JSONResponse({"ok": True, "session": dict(request.session)})

    async def bump(request):
        """State-changing endpoint used to exercise the CSRF middleware."""
        return JSONResponse({"ok": True})

    async def bootstrap(request):
        """Simulates a successful login: mints the session-bound CSRF token."""
        request.session["csrf_token"] = "a" * 64
        request.session["username"] = "alice"
        request.session["sid"] = "sid-1"
        return JSONResponse({"ok": True})

    async def page(request):
        return PlainTextResponse("ok")

    return [
        Route("/api/thing", info, methods=["GET", "POST"]),
        Route("/api/write", bump, methods=["POST", "GET"]),
        Route("/bootstrap", bootstrap, methods=["GET"]),
        Route("/page", page, methods=["GET", "POST"]),
        Route("/api/calls", bump, methods=["POST"]),
    ]


def _app_with(*middleware):
    """Build a raw ASGI chain: first argument is the outermost middleware."""
    app = Starlette(routes=_routes())
    for mw in reversed(middleware):
        app = mw(app)
    return app


def _session_middleware(app):
    return SessionMiddleware(app, secret_key=SECRET, max_age=MAX_AGE, same_site="lax")


def _csrf_chain():
    app = _app_with(
        lambda inner: main._CSRFMiddleware(inner, secret=SECRET, session_max_age=MAX_AGE),
        _session_middleware,
    )
    return TestClient(app)


@pytest.fixture(autouse=True)
def _reset_shared_state():
    from app.core import rate_limit

    session_registry.reset_state_for_tests()
    rate_limit.limiter.reset()
    yield
    session_registry.reset_state_for_tests()
    rate_limit.limiter.reset()


# ══════════════════════════════════════════════════════════════════════════
# Session cookie <-> installed Starlette
# ══════════════════════════════════════════════════════════════════════════

class TestSessionCookieParsing:
    def test_matches_installed_session_middleware(self):
        """The helpers MUST understand the cookies this Starlette writes.

        Starlette 1.6 signs with a salt-less TimestampSigner; an earlier
        revision of ``session_cookie.py`` assumed ``salt="starlette.sessions"``,
        so ``parse_session_cookie`` returned ``{}`` for every real cookie and
        both middlewares silently failed open.
        """
        client = TestClient(_app_with(_session_middleware))
        resp = client.get("/bootstrap")
        cookie_header = resp.headers["set-cookie"]
        raw = cookie_header.split(";", 1)[0].split("=", 1)[1]
        parsed = parse_session_cookie(raw, SECRET, MAX_AGE)
        assert parsed.get("username") == "alice"
        assert parsed.get("csrf_token") == "a" * 64

    def test_round_trip_with_installed_signer(self):
        signer = installed_signer(SECRET)
        token = signer.sign(base64.b64encode(json.dumps({"username": "ali"}).encode())).decode()
        assert parse_session_cookie(token, SECRET, MAX_AGE) == {"username": "ali"}

    def test_tampered_cookie_yields_empty_session(self):
        signer = installed_signer(SECRET)
        token = signer.sign(base64.b64encode(json.dumps({"username": "ali"}).encode())).decode()
        tampered = token[:-4] + "AAAA"
        assert parse_session_cookie(tampered, SECRET, MAX_AGE) == {}

    def test_cookie_signed_with_another_key_is_rejected(self):
        signer = installed_signer(SECRET)
        token = signer.sign(b"eyJhIjogMX0=").decode()
        assert parse_session_cookie(token, "a-different-secret", MAX_AGE) == {}

    def test_expired_cookie_is_rejected(self):
        signer = installed_signer(SECRET)
        token = signer.sign(base64.b64encode(json.dumps({"username": "ali"}).encode())).decode()
        assert parse_session_cookie(token, SECRET, max_age=1) != {}
        time.sleep(2.2)  # itsdangerous compares whole seconds
        assert parse_session_cookie(token, SECRET, max_age=1) == {}

    def test_garbage_is_rejected_without_raising(self):
        for value in ("", "not-a-cookie", "a.b.c", "null"):
            assert parse_session_cookie(value, SECRET, MAX_AGE) == {}


# ══════════════════════════════════════════════════════════════════════════
# CSRF middleware (end-to-end through SessionMiddleware)
# ══════════════════════════════════════════════════════════════════════════

class TestCSRFMiddleware:
    def test_state_changing_request_without_token_is_rejected(self):
        client = _csrf_chain()
        assert client.post("/api/write", json={}).status_code == 403

    def test_full_login_then_post_flow_is_accepted(self):
        """Login mints the token; the middleware publishes the readable cookie;
        the subsequent POST carries it back and must be accepted."""
        client = _csrf_chain()
        resp = client.get("/bootstrap")
        assert resp.status_code == 200
        token = client.cookies.get("csrf_token")
        assert token == "a" * 64, "the readable cookie must mirror the session token"
        posted = client.post("/api/write", json={}, headers={"X-CSRF-Token": token})
        assert posted.status_code == 200

    def test_token_not_matching_the_session_is_rejected(self):
        client = _csrf_chain()
        client.get("/bootstrap")
        assert client.post(
            "/api/write", json={}, headers={"X-CSRF-Token": "b" * 64}
        ).status_code == 403

    def test_header_without_cookie_is_rejected(self):
        client = _csrf_chain()
        client.get("/bootstrap")
        token = client.cookies.get("csrf_token")
        client.cookies.delete("csrf_token")
        assert client.post(
            "/api/write", json={}, headers={"X-CSRF-Token": token}
        ).status_code == 403

    def test_cookie_without_header_is_rejected(self):
        client = _csrf_chain()
        client.get("/bootstrap")
        assert client.post("/api/write", json={}).status_code == 403

    def test_anonymous_visitor_receives_a_readable_cookie(self):
        client = _csrf_chain()
        client.get("/api/thing")
        token = client.cookies.get("csrf_token")
        assert token and len(token) == 64

    def test_safe_methods_are_never_blocked(self):
        client = _csrf_chain()
        assert client.get("/api/thing").status_code == 200

    def test_exempt_endpoint_rejects_cross_site_origin(self):
        client = _csrf_chain()
        assert client.post(
            "/api/calls", json={}, headers={"Origin": "https://evil.example"}
        ).status_code == 403

    def test_exempt_endpoint_rejects_opaque_origin(self):
        client = _csrf_chain()
        assert client.post(
            "/api/calls", json={}, headers={"Origin": "null"}
        ).status_code == 403

    def test_exempt_endpoint_allows_same_site_origin(self):
        client = _csrf_chain()
        assert client.post(
            "/api/calls", json={}, headers={"Origin": "http://testserver"}
        ).status_code == 200

    def test_exempt_endpoint_allows_non_browser_client(self):
        """LAN integrations (bridge agent, curl) send no Origin header."""
        client = _csrf_chain()
        assert client.post("/api/calls", json={}).status_code == 200

    def test_pre_auth_endpoints_are_origin_checked_not_token_checked(self):
        """A login POST cannot carry a session-bound token, so it must be
        exempt — but a cross-site login POST is still refused."""
        client = _csrf_chain()
        assert client.post(
            "/login_user", json={}, headers={"Origin": "https://evil.example"}
        ).status_code == 403

    def test_secure_flag_added_on_https(self):
        app = _app_with(
            lambda inner: main._CSRFMiddleware(inner, secret=SECRET, session_max_age=MAX_AGE),
            _session_middleware,
        )
        client = TestClient(app, base_url="https://testserver")
        resp = client.get("/api/thing")
        cookies = [v for k, v in resp.headers.multi_items() if k.lower() == "set-cookie"]
        assert any("csrf_token=" in c and "Secure" in c for c in cookies)

    def test_secure_flag_not_forced_by_an_untrusted_forwarded_header(self):
        client = _csrf_chain()
        resp = client.get("/api/thing", headers={"X-Forwarded-Proto": "https"})
        cookies = [v for k, v in resp.headers.multi_items() if k.lower() == "set-cookie"]
        assert cookies and not any("Secure" in c for c in cookies)

    def test_forwarded_proto_from_a_trusted_proxy_is_honoured(self, monkeypatch):
        monkeypatch.setattr(main, "_scope_peer", lambda scope: "127.0.0.1")
        client = _csrf_chain()
        resp = client.get("/api/thing", headers={"X-Forwarded-Proto": "https"})
        cookies = [v for k, v in resp.headers.multi_items() if k.lower() == "set-cookie"]
        assert any("Secure" in c for c in cookies)


# ══════════════════════════════════════════════════════════════════════════
# Server-side session registry
# ══════════════════════════════════════════════════════════════════════════

class TestSessionRevocation:
    @staticmethod
    def _client():
        app = _app_with(
            lambda inner: main._SessionRegistryMiddleware(
                inner, secret=SECRET, session_max_age=MAX_AGE
            ),
            _session_middleware,
        )
        return TestClient(app)

    @staticmethod
    def _login(client):
        client.get("/bootstrap")  # writes username + sid into the session

    def test_valid_registered_session_passes(self, monkeypatch):
        monkeypatch.setattr(session_registry, "validate_session", lambda sid, user: True)
        client = self._client()
        self._login(client)
        assert client.get("/api/thing").status_code == 200

    def test_revoked_session_is_rejected_for_html(self, monkeypatch):
        monkeypatch.setattr(session_registry, "validate_session", lambda sid, user: False)
        client = self._client()
        self._login(client)
        resp = client.get("/page", follow_redirects=False)
        assert resp.status_code == 303 and resp.headers["location"] == "/login"

    def test_revoked_session_gets_401_json_for_api(self, monkeypatch):
        monkeypatch.setattr(session_registry, "validate_session", lambda sid, user: False)
        client = self._client()
        self._login(client)
        resp = client.get("/api/thing", follow_redirects=False)
        assert resp.status_code == 401
        assert "set-cookie" in {k.lower() for k in resp.headers}

    def test_signed_cookie_without_registry_id_is_rejected(self, monkeypatch):
        """A cookie signed before the registry existed must not stay valid."""
        monkeypatch.setattr(session_registry, "validate_session", lambda sid, user: True)
        client = self._client()

        async def legacy(request):
            request.session["username"] = "alice"
            return JSONResponse({"ok": True})

        signer = installed_signer(SECRET)
        token = signer.sign(
            base64.b64encode(json.dumps({"username": "alice"}).encode())
        ).decode()
        client.cookies.set("session", token)
        resp = client.get("/api/thing", follow_redirects=False)
        assert resp.status_code == 401

    def test_anonymous_request_is_untouched(self):
        client = self._client()
        assert client.get("/api/thing").status_code == 200

    def test_validate_session_returns_false_for_unknown_id(self):
        assert session_registry.validate_session("", "alice") is False

    def test_revoke_session_issues_the_expected_update(self, monkeypatch):
        class Cursor:
            rowcount = 1

            def __init__(self):
                self.executed = []

            def execute(self, query, params=None):
                self.executed.append((query, params))

        cursor = Cursor()

        class Conn:
            def cursor(self):
                return cursor

            def commit(self):
                pass

            def close(self):
                pass

        monkeypatch.setattr(session_registry, "_connect", lambda: Conn())
        session_registry.reset_state_for_tests()
        assert session_registry.revoke_session("sid-1", "admin") is True
        query, params = cursor.executed[0]
        assert "is_active = 0" in query and params == ("admin", "sid-1")

    def test_revoke_user_sessions_targets_every_active_row(self, monkeypatch):
        class Cursor:
            rowcount = 3

            def __init__(self):
                self.executed = []

            def execute(self, query, params=None):
                self.executed.append((query, params))

        cursor = Cursor()

        class Conn:
            def cursor(self):
                return cursor

            def commit(self):
                pass

            def close(self):
                pass

        monkeypatch.setattr(session_registry, "_connect", lambda: Conn())
        session_registry.reset_state_for_tests()
        assert session_registry.revoke_user_sessions("alice", "password_reset") == 3
        query, params = cursor.executed[0]
        assert "is_active = 0" in query
        assert params[0] == "password_reset" and params[1] == "alice"


# ══════════════════════════════════════════════════════════════════════════
# Client IP handling
# ══════════════════════════════════════════════════════════════════════════

class TestClientIP:
    @staticmethod
    def _req(headers, peer="127.0.0.1"):
        class Client:
            host = peer

        class Req:
            client = Client()
            headers = {}

        req = Req()
        req.headers = headers
        return req

    def test_last_forwarded_entry_wins_when_the_peer_is_a_trusted_proxy(self):
        # Caddy appends the real peer, so earlier entries are attacker supplied.
        assert client_ip(self._req({"x-forwarded-for": "1.2.3.4, 192.168.3.50"})) == "192.168.3.50"

    def test_markup_in_forwarded_header_is_ignored(self):
        assert client_ip(self._req({"x-forwarded-for": "<img src=x onerror=alert(1)>"})) == "127.0.0.1"

    def test_trailing_garbage_falls_back_to_last_valid_entry(self):
        assert client_ip(self._req({"x-forwarded-for": "192.168.3.50, garbage"})) == "192.168.3.50"

    def test_no_header_uses_peer_address(self):
        assert client_ip(self._req({}, peer="10.0.0.9")) == "10.0.0.9"

    def test_ipv6_with_port_is_normalised(self):
        assert client_ip(self._req({"x-forwarded-for": "[2001:db8::1]:443"})) == "2001:db8::1"

    def test_untrusted_peer_cannot_forge_its_address(self):
        """Direct access to the app port must not honour forwarding headers."""
        request = self._req({"x-forwarded-for": "1.2.3.4"}, peer="192.168.3.99")
        assert client_ip(request) == "192.168.3.99"

    def test_x_forwarded_proto_from_untrusted_peer_is_ignored(self):
        from app.core.net import is_https

        assert is_https(self._req({"x-forwarded-proto": "https"}, peer="192.168.3.99")) is False
        assert is_https(self._req({"x-forwarded-proto": "https"})) is True

    def test_trusted_proxy_list_is_configurable(self, monkeypatch):
        monkeypatch.setenv("TRUSTED_PROXY_IPS", "127.0.0.1, 10.0.0.1")
        request = self._req({"x-forwarded-for": "1.2.3.4"}, peer="10.0.0.1")
        assert client_ip(request) == "1.2.3.4"


# ══════════════════════════════════════════════════════════════════════════
# Input validation primitives
# ══════════════════════════════════════════════════════════════════════════

class TestInputValidation:
    def test_markup_is_rejected(self):
        with pytest.raises(ValueError):
            clean_display_text("<script>alert(1)</script>", max_length=50, field="نام")

    def test_control_characters_are_stripped(self):
        assert strip_control("ali\x00ce\x07") == "alice"
        assert clean_display_text("ali\x00ce", max_length=50, field="نام") == "alice"

    def test_length_is_enforced(self):
        with pytest.raises(ValueError):
            clean_display_text("a" * 51, max_length=50, field="نام")

    def test_persian_names_are_accepted(self):
        assert clean_display_text("علی رضایی", max_length=50, field="نام") == "علی رضایی"

    def test_required_field_rejects_blank(self):
        with pytest.raises(ValueError):
            clean_display_text("   ", max_length=50, field="نام", required=True)

    def test_empty_optional_field_returns_empty_string(self):
        assert clean_display_text(None, max_length=50, field="نام") == ""


class TestRateLimiter:
    def test_blocks_after_limit_within_window(self):
        limiter = SlidingWindowLimiter()
        for _ in range(3):
            assert limiter.allow("k", limit=3, window_seconds=60) is True
        assert limiter.allow("k", limit=3, window_seconds=60) is False

    def test_separate_keys_are_independent(self):
        limiter = SlidingWindowLimiter()
        assert limiter.allow("a", limit=1, window_seconds=60) is True
        assert limiter.allow("b", limit=1, window_seconds=60) is True
        assert limiter.allow("a", limit=1, window_seconds=60) is False

    def test_window_expiry_frees_capacity(self):
        limiter = SlidingWindowLimiter()
        assert limiter.allow("k", limit=1, window_seconds=0) is True
        assert limiter.allow("k", limit=1, window_seconds=0) is True


# ══════════════════════════════════════════════════════════════════════════
# Login flow
# ══════════════════════════════════════════════════════════════════════════

class _Req:
    def __init__(self, body, headers=None, client_host="10.0.0.5"):
        self._body = body
        self.headers = headers or {}
        self.session = {}
        self.method = "POST"

        class Client:
            host = client_host

        self.client = Client()

    async def json(self):
        return self._body


def _run_login(body, *, user_row, headers=None, client_host="10.0.0.5"):
    from app.api.routes import auth as auth_module

    registered = {}

    def fake_register(sid, username, ip, ua):
        registered["sid"] = sid
        registered["username"] = username
        return True

    originals = (
        auth_module.fetch_user_for_login,
        auth_module.validate_captcha,
        auth_module._record_login_result,
        auth_module.session_registry.register_session,
        auth_module.log_event_safe,
    )
    auth_module.fetch_user_for_login = lambda cursor, username: user_row
    auth_module.validate_captcha = lambda request, code: (True, "")
    auth_module._record_login_result = lambda username, success: None
    auth_module.session_registry.register_session = fake_register
    auth_module.log_event_safe = lambda **kwargs: None
    try:
        request = _Req(body, headers=headers, client_host=client_host)
        response = asyncio.new_event_loop().run_until_complete(auth_module.login(request))
        return response, request, registered
    finally:
        (
            auth_module.fetch_user_for_login,
            auth_module.validate_captcha,
            auth_module._record_login_result,
            auth_module.session_registry.register_session,
            auth_module.log_event_safe,
        ) = originals


def _bcrypt(password: str):
    import bcrypt

    return bcrypt.hashpw(password.encode(), bcrypt.gensalt(rounds=4)).decode()


class TestLoginFlow:
    def test_disabled_account_cannot_log_in(self):
        row = ("alice", "admin", "", _bcrypt("Correct#1Pass"), "disabled")
        response, request, _ = _run_login(
            {"username": "alice", "password": "Correct#1Pass", "captcha": "X"}, user_row=row
        )
        assert json.loads(response.body)["success"] is False
        assert "username" not in request.session

    def test_unknown_account_gets_the_same_generic_message(self):
        response, request, _ = _run_login(
            {"username": "ghost", "password": "whatever", "captcha": "X"}, user_row=None
        )
        body = json.loads(response.body)
        assert body["success"] is False
        assert body["message"].startswith("نام کاربری یا رمز عبور اشتباه است")

    def test_active_account_logs_in_with_registry_id_and_csrf_token(self):
        row = ("alice", "admin", "", _bcrypt("Correct#1Pass"), "active")
        response, request, registered = _run_login(
            {"username": "alice", "password": "Correct#1Pass", "captcha": "X"}, user_row=row
        )
        body = json.loads(response.body)
        assert body["success"] is True
        assert request.session["username"] == "alice"
        assert request.session["is_admin"] is True
        assert request.session[session_registry.SESSION_TOKEN_KEY] == registered["sid"]
        assert len(request.session["csrf_token"]) == 64
        cookies = response.headers.getlist("set-cookie")
        assert any("csrf_token=" in c and "HttpOnly" not in c for c in cookies)

    def test_non_admin_role_does_not_receive_admin_flag(self):
        row = ("bob", "user", "", _bcrypt("Correct#1Pass"), "active")
        _, request, _ = _run_login(
            {"username": "bob", "password": "Correct#1Pass", "captcha": "X"}, user_row=row
        )
        assert request.session["is_admin"] is False
        assert request.session["is_master_admin"] is False

    def test_repeated_failures_from_one_ip_are_throttled(self):
        from app.api.routes import auth as auth_module

        saved = auth_module._rate_limiter
        auth_module._rate_limiter = auth_module.RateLimiter()
        try:
            last = None
            for _ in range(auth_module.LOGIN_MAX_FAILURES_PER_IP + 1):
                last, _, _ = _run_login(
                    {"username": "alice", "password": "wrong", "captcha": "X"},
                    user_row=("alice", "admin", "", _bcrypt("Correct#1Pass"), "active"),
                )
            assert last.status_code == 429
        finally:
            auth_module._rate_limiter = saved

    def test_spoofed_forwarded_header_cannot_bypass_the_ip_limit(self):
        from app.api.routes import auth as auth_module

        saved = auth_module._rate_limiter
        auth_module._rate_limiter = auth_module.RateLimiter()
        try:
            row = ("alice", "admin", "", _bcrypt("Correct#1Pass"), "active")
            statuses = []
            for i in range(auth_module.LOGIN_MAX_FAILURES_PER_IP + 2):
                response, _, _ = _run_login(
                    {"username": "alice", "password": "wrong", "captcha": "X"},
                    user_row=row,
                    headers={"x-forwarded-for": f"10.9.9.{i}, 192.168.3.50"},
                )
                statuses.append(response.status_code)
            assert 429 in statuses, "rotating a spoofed XFF prefix must not reset the limit"
        finally:
            auth_module._rate_limiter = saved

    def test_oversized_password_is_rejected_generically(self):
        row = ("alice", "admin", "", _bcrypt("Correct#1Pass"), "active")
        response, _, _ = _run_login(
            {"username": "alice", "password": "x" * 200, "captcha": "X"}, user_row=row
        )
        assert json.loads(response.body)["message"].startswith("نام کاربری یا رمز عبور اشتباه است")


# ══════════════════════════════════════════════════════════════════════════
# Password recovery
# ══════════════════════════════════════════════════════════════════════════

class TestPasswordRecovery:
    def test_approval_fails_closed_without_hmac_key(self, monkeypatch):
        monkeypatch.setattr(audit, "_HMAC_SECRET", b"")
        result = audit.approve_password_reset("HST-20260101-ABCDEF12", "ali")
        assert result["success"] is False

    def test_approval_generates_an_eight_character_code_and_stores_only_its_digest(self, monkeypatch):
        class Cursor:
            rowcount = 1

            def execute(self, query, params=None):
                self.params = params

        cursor = Cursor()

        class Conn:
            def cursor(self):
                return cursor

            def commit(self):
                pass

            def close(self):
                pass

        monkeypatch.setattr(audit, "db_connect", lambda: Conn())
        monkeypatch.setattr(audit, "_HMAC_SECRET", b"test-key")
        result = audit.approve_password_reset("HST-20260101-ABCDEF12", "ali")
        assert result["success"] is True
        code = result["code"]
        assert len(code) == 8 and code.isalnum() and code == code.upper()
        assert cursor.params[0] == audit._hash_code(code)
        assert code not in str(cursor.params)

    def test_code_verification_uses_a_constant_time_comparison(self):
        import inspect

        assert "compare_digest" in inspect.getsource(audit.verify_recovery_code)

    @staticmethod
    def _forgot_password(user_row, monkeypatch, enabled=True):
        """Drive /forgot_password and return (status, body, created_for)."""
        from app.api.routes import auth as auth_module

        captured = {}
        monkeypatch.setattr(auth_module, "log_event_safe", lambda **kwargs: None)
        monkeypatch.setattr(auth_module, "fetch_user_for_login", lambda cursor, username: user_row)
        monkeypatch.setattr(audit, "recovery_codes_available", lambda: enabled)

        class Cursor:
            def execute(self, query, params=None):
                pass

            def fetchone(self):
                return None

            def commit(self):
                pass

        class Conn:
            def cursor(self):
                return Cursor()

            def close(self):
                pass

        def fake_create(**kwargs):
            captured["created_for"] = kwargs.get("username")
            return {"success": True, "request_id": "HST-20260101-ABCDEF12"}

        monkeypatch.setattr(audit, "create_password_reset_request", fake_create)
        monkeypatch.setattr(auth_module, "_get_connection", lambda: Conn())
        request = _Req({"username": "someone"})
        response = asyncio.new_event_loop().run_until_complete(auth_module.forgot_password(request))
        return response.status_code, json.loads(response.body), captured.get("created_for")

    def test_forgot_password_fails_closed_without_hmac_key(self, monkeypatch):
        status, body, _ = self._forgot_password(None, monkeypatch, enabled=False)
        assert status == 200
        assert body["success"] is False
        assert "request_id" not in body

    def test_known_and_unknown_accounts_get_an_identical_answer(self, monkeypatch):
        row = ("alice", "admin", "", "", "active")
        known_status, known_body, created = self._forgot_password(row, monkeypatch)
        unknown_status, unknown_body, not_created = self._forgot_password(None, monkeypatch)

        assert known_status == unknown_status == 200
        assert known_body["message"] == unknown_body["message"]
        assert set(known_body) == set(unknown_body) == {"success", "message", "request_id"}
        # a real request row is only created for a real account, but the answer
        # cannot be distinguished from the outside
        assert created == "someone" and not_created is None
        assert known_body["request_id"].startswith("HST-")

    def test_decoy_request_id_is_rejected_like_a_wrong_code(self, monkeypatch):
        """The decoy must not be detectable through /reset_password."""
        monkeypatch.setattr(audit, "recovery_codes_available", lambda: True)

        class Cursor:
            def execute(self, query, params=None):
                pass

            def fetchone(self):
                return None  # request id is unknown

            def commit(self):
                pass

        class Conn:
            def cursor(self):
                return Cursor()

            def close(self):
                pass

        result = audit.verify_recovery_code(
            "HST-20260101-ABCDEF12", "ABCD1234", conn=Conn()
        )
        assert result["success"] is False
        assert result["message"] == audit.CODE_REJECTED_MESSAGE

    def test_pending_request_is_indistinguishable_without_the_code(self, monkeypatch):
        """A real but not-yet-approved request must answer like a decoy id.

        The row exists and only its status differs, so a status-specific answer
        at this point would be a single-request username enumeration oracle for
        an unauthenticated caller.
        """
        monkeypatch.setattr(audit, "recovery_codes_available", lambda: True)

        class Cursor:
            def __init__(self):
                self.updates = []

            def execute(self, query, params=None):
                self.updates.append(query)

            def fetchone(self):
                # recovery_code, expiry, attempts, max_attempts, status, username
                return (None, None, 0, 5, "pending", "alice")

        class Conn:
            def __init__(self):
                self.cur = Cursor()

            def cursor(self):
                return self.cur

            def close(self):
                pass

        conn = Conn()
        result = audit.verify_recovery_code("HST-20260101-ABCDEF12", "ABCD1234", conn=conn)
        assert result["success"] is False
        assert result["message"] == audit.CODE_REJECTED_MESSAGE
        assert not any("status='expired'" in q for q in conn.cur.updates)

    def test_expired_answer_requires_possession_of_the_code(self, monkeypatch):
        """The 'expired' explanation is only reachable with a matching code."""
        monkeypatch.setattr(audit, "recovery_codes_available", lambda: True)
        from datetime import datetime, timedelta, timezone

        code = "ABCD1234"
        digest = audit._hash_code(code)  # exact derivation used by the module

        class Cursor:
            def execute(self, query, params=None):
                pass

            def fetchone(self):
                return (digest, datetime.now(timezone.utc) - timedelta(minutes=1), 0, 5, "approved", "alice")

        class Conn:
            def cursor(self):
                return Cursor()

            def close(self):
                pass

        result = audit.verify_recovery_code("HST-20260101-ABCDEF12", code, conn=Conn())
        assert result["success"] is False
        assert result["message"] != audit.CODE_REJECTED_MESSAGE


# ══════════════════════════════════════════════════════════════════════════
# Master-admin control plane
# ══════════════════════════════════════════════════════════════════════════

class TestMasterAdminAuthorization:
    @staticmethod
    def _request(session):
        class URL:
            path = "/master-admin/api/users"

        class Req:
            def __init__(self):
                self.session = session
                self.method = "GET"
                self.headers = {}
                self.url = URL()

            class client:
                host = "10.0.0.5"

        return Req()

    def test_regular_admin_is_rejected(self):
        from fastapi import HTTPException

        from app.api.routes import master_admin

        with pytest.raises(HTTPException) as exc:
            master_admin._master_admin(self._request({"username": "bob", "is_admin": True}))
        assert exc.value.status_code == 403

    def test_anonymous_is_rejected(self):
        from fastapi import HTTPException

        from app.api.routes import master_admin

        with pytest.raises(HTTPException) as exc:
            master_admin._master_admin(self._request({}))
        assert exc.value.status_code == 401

    def test_master_admin_is_accepted(self):
        from app.api.routes import master_admin

        assert master_admin._master_admin(
            self._request({"username": "ali", "is_admin": True, "is_master_admin": True})
        ) == "ali"

    def test_role_elevation_via_client_supplied_flag_is_impossible(self):
        """Only the session flag written by the server counts."""
        from fastapi import HTTPException

        from app.api.routes import master_admin

        with pytest.raises(HTTPException):
            master_admin._master_admin(
                self._request({"username": "bob", "is_admin": True, "is_master_admin": "false"})
            )

    def test_user_list_never_selects_credential_columns(self):
        import inspect

        from app.api.routes import master_admin

        import ast

        tree = ast.parse(inspect.getsource(master_admin))
        constants = [
            node.value for node in ast.walk(tree)
            if isinstance(node, ast.Constant) and isinstance(node.value, str)
        ]
        queries = [c for c in constants if "SELECT" in c.upper()]
        assert queries, "no SQL found — the check would pass vacuously"
        for query in queries:
            assert "SELECT *" not in query.upper(), f"SELECT * in: {query[:120]}"
            if "password_reset_requests" in query:
                assert "recovery_code" not in query, f"recovery code leaked: {query[:120]}"


# ══════════════════════════════════════════════════════════════════════════
# Araz bridge
# ══════════════════════════════════════════════════════════════════════════

class TestBridgeSync:
    @staticmethod
    def _call(payload, secret):
        from app.api.routes import araz_api

        saved = araz_api.BRIDGE_SECRET
        araz_api.BRIDGE_SECRET = secret

        class Req:
            headers = {}
            client = type("C", (), {"host": "192.168.3.10"})()

        async def run():
            return await araz_api.bridge_sync(araz_api.BridgeSyncRequest(**payload), Req())

        try:
            return asyncio.new_event_loop().run_until_complete(run())
        finally:
            araz_api.BRIDGE_SECRET = saved

    def test_fails_closed_without_configured_secret(self):
        response = self._call({"records": [], "secret": "anything"}, "")
        assert response.status_code == 503

    def test_rejects_wrong_secret(self):
        from fastapi import HTTPException

        with pytest.raises(HTTPException) as exc:
            self._call({"records": [], "secret": "guessed"}, "real-secret")
        assert exc.value.status_code == 401

    def test_empty_batch_with_valid_secret_is_accepted(self):
        response = self._call({"records": [], "secret": "real-secret"}, "real-secret")
        assert response.synced == 0

    def test_batch_size_is_capped(self):
        from fastapi import HTTPException

        from app.api.routes import araz_api

        records = [{"username": "u", "tarikh": "1404/01/01", "vorood": "08:00", "khorooj": "17:00"}] * (
            araz_api.MAX_BRIDGE_RECORDS + 1
        )
        with pytest.raises(HTTPException) as exc:
            self._call({"records": records, "secret": "real-secret"}, "real-secret")
        assert exc.value.status_code == 413


# ══════════════════════════════════════════════════════════════════════════
# Call system
# ══════════════════════════════════════════════════════════════════════════

class TestCallSystemAuthorization:
    @staticmethod
    def _ws(origin, host="hastama.local"):
        class Headers(dict):
            def get(self, key, default=None):
                return dict.get(self, key, default)

        data = {"host": host}
        if origin:
            data["origin"] = origin

        class WS:
            headers = Headers(data)

        return WS()

    def test_cross_site_websocket_origin_is_rejected(self):
        from app.api.routes import call_system

        assert call_system._ws_origin_allowed(self._ws("https://evil.example")) is False

    def test_opaque_websocket_origin_is_rejected(self):
        from app.api.routes import call_system

        assert call_system._ws_origin_allowed(self._ws("null")) is False

    def test_same_site_websocket_origin_is_accepted(self):
        from app.api.routes import call_system

        assert call_system._ws_origin_allowed(self._ws("https://hastama.local")) is True

    def test_client_without_origin_is_accepted(self):
        from app.api.routes import call_system

        assert call_system._ws_origin_allowed(self._ws(None)) is True

    def test_slide_management_requires_admin(self):
        from fastapi import HTTPException

        from app.api.routes import call_system

        class Req:
            session = {"username": "bob", "is_admin": False}

        with pytest.raises(HTTPException) as exc:
            call_system._require_admin(Req())
        assert exc.value.status_code == 403

    def test_slide_management_rejects_anonymous(self):
        from fastapi import HTTPException

        from app.api.routes import call_system

        class Req:
            session = {}

        with pytest.raises(HTTPException) as exc:
            call_system._require_admin(Req())
        assert exc.value.status_code == 401

    def test_kiosk_write_guard_rejects_cross_site_origin(self):
        from fastapi import HTTPException

        from app.api.routes import call_system

        class Req:
            headers = {"origin": "https://evil.example", "host": "hastama.local"}
            client = type("C", (), {"host": "10.0.0.5"})()

        with pytest.raises(HTTPException) as exc:
            call_system._guard_kiosk_write(Req(), "create_call")
        assert exc.value.status_code == 403

    def test_audio_status_does_not_disclose_filesystem_paths(self):
        from app.api.routes import call_system

        response = asyncio.new_event_loop().run_until_complete(call_system.audio_status())
        payload = json.loads(response.body)
        assert "directory" not in payload
        assert not any(
            isinstance(value, str) and ("/srv" in value or ":\\" in value or "app/static" in value)
            for value in payload.values()
        )


# ══════════════════════════════════════════════════════════════════════════
# Password storage
# ══════════════════════════════════════════════════════════════════════════

class TestPasswordStorage:
    def test_insert_parameter_count_matches_placeholders(self, monkeypatch):
        """The previous revision passed 17 parameters to a 16-marker INSERT, so
        ``/add_user`` could never insert a row."""
        from app.core import password_utils

        statements = []

        class Cursor:
            def execute(self, query, params=None):
                statements.append((query, params))

        monkeypatch.setattr(
            password_utils, "get_user_table_columns",
            lambda cursor: {"password_hash", "is_active"},
        )
        password_utils.insert_user_with_optional_hash(
            Cursor(), 123, "alice", "Secret#1", b"$2b$12$hash", "Ali", "Rezaei", "IT", "",
            "08-16", "user", "1001", "1", "1", "1", "1", "1", "1",
        )
        query, params = statements[0]
        assert query.count("?") == len(params), "placeholder/parameter mismatch"
        assert "''" in query, "plaintext password column must be written as empty"

    def test_legacy_insert_path_stores_the_hash_not_the_password(self, monkeypatch):
        from app.core import password_utils

        statements = []

        class Cursor:
            def execute(self, query, params=None):
                statements.append((query, params))

        monkeypatch.setattr(password_utils, "get_user_table_columns", lambda cursor: set())
        password_utils.insert_user_with_optional_hash(
            Cursor(), 123, "alice", "Secret#1", b"$2b$12$hash", "Ali", "Rezaei", "IT", "",
            "08-16", "user", "1001", "1", "1", "1", "1", "1", "1",
        )
        _, params = statements[0]
        assert "Secret#1" not in str(params)

    def test_hash_generation_uses_bcrypt(self):
        from app.core.password_utils import hash_password, looks_like_bcrypt

        assert looks_like_bcrypt(hash_password("Secret#1").decode())

    def test_verify_accepts_bcrypt_hash_and_rejects_wrong_password(self):
        from app.core.password_utils import verify_password

        hashed = _bcrypt("Secret#1")
        assert verify_password(hashed, None, "Secret#1") is True
        assert verify_password(hashed, None, "wrong") is False

    def test_legacy_plaintext_and_sha512_rows_still_authenticate(self):
        from app.core.password_utils import verify_password

        assert verify_password("Secret#1", None, "Secret#1") is True
        sha = __import__("hashlib").sha512(b"Secret#1").hexdigest()
        assert verify_password(sha, None, "Secret#1") is True
        assert verify_password("Secret#1", None, "nope") is False

    def test_user_id_allocation_is_not_predictable(self):
        import inspect

        source = inspect.getsource(main._next_available_user_id)
        assert "secrets.randbelow" in source
        assert "random.randint(" not in source


# ══════════════════════════════════════════════════════════════════════════
# Database isolation
# ══════════════════════════════════════════════════════════════════════════

class TestDatabaseIsolation:
    """The legacy code shared one connection/cursor between all requests."""

    @staticmethod
    def _install_fake_connect(monkeypatch):
        from app.core import db_context

        created = []

        class Cursor:
            def __init__(self, owner):
                self.owner = owner

            def close(self):
                pass

        class Conn:
            def __init__(self, name):
                self.name = name
                self.closed = False

            def cursor(self):
                return Cursor(self.name)

            def rollback(self):
                pass

            def close(self):
                self.closed = True

        monkeypatch.setattr(db_context, "_connect", lambda: created.append(Conn(len(created))) or created[-1])
        monkeypatch.setattr(db_context._local, "connection", None, raising=False)
        monkeypatch.setattr(db_context._local, "cursor", None, raising=False)
        return created

    def test_each_request_resolves_its_own_connection_and_cursor(self, monkeypatch):
        created = self._install_fake_connect(monkeypatch)
        from app.core import db_context

        results = []

        async def app(scope, receive, send):
            results.append((db_context.current_connection().name, db_context.cursor_proxy().owner))
            await send({"type": "http.response.start", "status": 200, "headers": []})
            await send({"type": "http.response.body", "body": b"ok"})

        wrapped = db_context.RequestConnectionMiddleware(app)

        async def run_two():
            async def receive():
                return {"type": "http.request", "body": b"", "more_body": False}

            async def send(message):
                return None

            scope = {"type": "http", "method": "GET", "path": "/", "headers": [],
                     "query_string": b"", "scheme": "http", "client": ("10.0.0.1", 1)}
            await asyncio.gather(wrapped(scope, receive, send), wrapped(scope, receive, send))

        asyncio.new_event_loop().run_until_complete(run_two())
        assert len(created) == 2, "one connection per request"
        assert len(set(results)) == 2, "requests must not share a connection/cursor"
        assert all(conn.closed for conn in created), "request connections are closed"

    def test_context_is_cleared_after_the_request(self, monkeypatch):
        self._install_fake_connect(monkeypatch)
        from app.core import db_context

        async def app(scope, receive, send):
            await send({"type": "http.response.start", "status": 200, "headers": []})
            await send({"type": "http.response.body", "body": b"ok"})

        async def run():
            wrapped = db_context.RequestConnectionMiddleware(app)

            async def receive():
                return {"type": "http.request", "body": b"", "more_body": False}

            async def send(message):
                return None

            scope = {"type": "http", "method": "GET", "path": "/", "headers": [],
                     "query_string": b"", "scheme": "http", "client": ("10.0.0.1", 1)}
            await wrapped(scope, receive, send)
            return db_context._request_connection.get(), db_context._request_cursor.get()

        conn, cursor = asyncio.new_event_loop().run_until_complete(run())
        assert conn is None and cursor is None

    def test_proxies_are_reusable_module_state(self):
        from app.core import db_context

        assert db_context.connection_proxy() is db_context.connection_proxy()
        assert db_context.cursor_proxy() is db_context.cursor_proxy()


# ══════════════════════════════════════════════════════════════════════════
# Stored XSS: rendering sinks must be escaped
# ══════════════════════════════════════════════════════════════════════════

class TestTemplateOutputEscaping:
    """Static verification of the JavaScript render layer.

    The browser-side renders cannot be executed by pytest, so this asserts that
    the known database-sourced interpolations are wrapped in ``esc()`` and that
    no unescaped credential-ish field is interpolated into ``innerHTML``.
    """

    JS_FILES = ("app/static/js/master-admin.js", "app/static/js/admin.js")

    def _source(self, path):
        with open(path, encoding="utf-8") as handle:
            return handle.read()

    def test_escape_helper_is_defined(self):
        assert "function esc(" in self._source("app/static/js/master-admin.js")
        assert "function esc(" in self._source("app/static/js/admin.js")

    def test_no_raw_database_field_interpolation_remains(self):
        suspicious = (
            "${e.action}", "${e.module}", "${e.event_type}", "${e.actor_username}",
            "${e.ip_address", "${e.username", "${u.name", "${u.last_name",
            "${u.department}", "${u.work_hours}", "${s.ip_address",
            "${r.ip_address", "${t.subject", "${t.requester_username",
            "${t.recipient_username", "${k}: ${v}", "${report.username",
        )
        for path in self.JS_FILES:
            source = self._source(path)
            for needle in suspicious:
                assert needle not in source, f"unescaped interpolation in {path}: {needle}"

    def test_renderer_escapes_unless_a_renderer_is_supplied(self):
        source = self._source("app/static/js/master-admin.js")
        assert "else val = esc(val);" in source

    def test_no_template_uses_the_safe_filter_blindly(self):
        import pathlib

        for path in pathlib.Path("app/templates").glob("*.html"):
            assert "|safe" not in path.read_text(encoding="utf-8"), f"|safe in {path}"


# ══════════════════════════════════════════════════════════════════════════
# Integration regressions found while reviewing the CSRF rollout
# ══════════════════════════════════════════════════════════════════════════

class _PlainRequest:
    """Minimal Request stand-in for direct endpoint calls (no HTTP layer)."""

    def __init__(self, session, body=None):
        self.session = session
        self._body = body

    async def json(self):
        return self._body


class TestEmployeeReportAuthorization:
    """The ``all_users`` report endpoints used to answer anonymously.

    They return every employee's pass/overtime history, so both the anonymous
    caller and the non-admin caller must be refused before any query runs.
    """

    def test_hourly_pass_report_requires_a_session(self):
        resp = asyncio.run(main.get_hourly_pass_report(_PlainRequest({})))
        assert resp.status_code == 401

    def test_hourly_pass_report_requires_an_admin(self):
        resp = asyncio.run(
            main.get_hourly_pass_report(_PlainRequest({"username": "bob", "is_admin": False}))
        )
        assert resp.status_code == 403

    def test_overtime_report_requires_a_session(self):
        resp = asyncio.run(main.get_overtime_report({"username": "all_users"}, _PlainRequest({})))
        assert resp.status_code == 401

    def test_overtime_report_requires_an_admin(self):
        resp = asyncio.run(
            main.get_overtime_report(
                {"username": "all_users"},
                _PlainRequest({"username": "bob", "is_admin": False}),
            )
        )
        assert resp.status_code == 403


class TestCsrfExemptionIntegration:
    """The exemption list must cover machine/pre-auth callers and nothing else.

    The first CSRF rollout blocked the Araz bridge (a service that sends no
    Origin and no token) and the pre-auth captcha refresh; the tests below
    pin the corrected behaviour on the *real* application stack.
    """

    @staticmethod
    def _client():
        return TestClient(main.app, raise_server_exceptions=False)

    def test_exemption_list_is_narrow(self):
        assert "/api/araz/" in main.CSRF_EXEMPT_PREFIXES
        assert "/captcha/" in main.CSRF_EXEMPT_PREFIXES
        assert "/public/" in main.CSRF_EXEMPT_PREFIXES
        # Never exempt whole trees that contain authenticated mutations.
        for broad in ("/", "/api/", "/api/tickets", "/admin"):
            assert broad not in main.CSRF_EXEMPT_PREFIXES

    def test_bridge_agent_request_is_not_csrf_blocked(self):
        resp = self._client().post("/api/araz/bridge-sync", json={"records": []})
        assert "CSRF token mismatch" not in resp.text
        assert resp.status_code != 403

    def test_bridge_endpoint_still_rejects_cross_site_browser_calls(self):
        resp = self._client().post(
            "/api/araz/bridge-sync", json={}, headers={"Origin": "https://evil.example"}
        )
        assert resp.status_code == 403

    def test_captcha_refresh_works_before_login(self):
        resp = self._client().post(
            "/captcha/refresh", headers={"Origin": "http://testserver"}
        )
        assert resp.status_code == 200

    def test_captcha_refresh_rejects_cross_site_origin(self):
        resp = self._client().post(
            "/captcha/refresh", headers={"Origin": "https://evil.example"}
        )
        assert resp.status_code == 403

    def test_public_support_form_is_not_csrf_blocked(self):
        resp = self._client().post("/public/support-ticket", json={})
        assert "CSRF token mismatch" not in resp.text
        assert resp.status_code == 400  # validation error, not a CSRF 403

    def test_authenticated_mutations_still_require_the_token(self):
        resp = self._client().post("/api/tickets", json={})
        assert resp.status_code == 403
        assert "CSRF token mismatch" in resp.text

    def test_classic_profile_forms_load_the_csrf_bootstrap(self):
        source = open("app/templates/user-panel.html", encoding="utf-8").read()
        assert "csrf-bootstrap.js" in source
        assert "uploadForm" in source and "deleteForm" in source


class TestInternalErrorHandling:
    """Regression: the generic error helper raised NameError itself.

    ``_safe_error_message`` is the function every handler relies on to avoid
    leaking stack traces; it was calling an undefined ``logger``, so any error
    path turned into a 500 with a traceback instead of a generic message.
    """

    def test_safe_error_message_logs_instead_of_raising(self):
        message = main._safe_error_message(ValueError("boom"))
        assert isinstance(message, str) and message
        assert "boom" not in message and "ValueError" not in message

    def test_request_owner_lookup_table_is_allow_listed(self, caplog):
        assert main._NOTIFY_STATUS_TABLES == {"mrkhc_table", "totalpass_table", "ezafe_table"}
        with caplog.at_level("ERROR"):
            main._notify_requester_status("user_table; DROP TABLE hozoor", 1, "x", "y")
        assert any("unexpected table" in rec.message for rec in caplog.records)


class TestStoredXssRendering:
    """Report pages render database values through ``innerHTML`` template
    literals.  The values are employee written (overtime descriptions, leave
    substitute names), so the render layer must escape them; the write paths
    additionally refuse markup.
    """

    REPORT_SCRIPTS = (
        "app/static/js/final-report-script.js",
        "app/static/js/leave-report-script.js",
        "app/static/js/hourlypass-report-script.js",
        "app/static/js/overtime-report-script.js",
    )
    TEMPLATES = (
        "app/templates/final_report_page.html",
        "app/templates/leave_report_page.html",
        "app/templates/hourlypass_Report_page.html",
        "app/templates/overtime_report_page.html",
    )

    def test_escape_helper_is_loaded_by_every_report_page(self):
        for path in self.TEMPLATES:
            source = open(path, encoding="utf-8").read()
            assert "dom-escape.js" in source, f"{path} does not load the escape helper"

    def test_escape_helper_escapes_markup(self):
        source = open("app/static/js/dom-escape.js", encoding="utf-8").read()
        for char in ("&", "<", ">", '"', "'", "`", "="):
            assert char in source
        assert "window.escapeHtml" in source and "window.esc" in source

    def test_report_scripts_escape_database_fields(self):
        for path in self.REPORT_SCRIPTS:
            source = open(path, encoding="utf-8").read()
            for field in ("description", "substitute", "weekday", "passTitle", "pass_title"):
                for needle in (f"${{{field}}}", f"${{data.{field}}}", f"${{row.{field}}}"):
                    assert needle not in source, f"{path}: unescaped {needle}"
            # every row built from a converter is also escaped
            for line in source.splitlines():
                if "convertToPersianNumbers" in line or "convertToFarsiNumbers" in line:
                    if "${" in line and "innerHTML" not in line:
                        assert "esc(" in line, f"{path}: unescaped render: {line.strip()[:90]}"

    def test_free_text_write_paths_reject_markup(self):
        from app.core.validation import reject_markup

        assert reject_markup("گزارش عادی", max_length=50, field="شرح") == "گزارش عادی"
        for payload in ("<img src=x onerror=alert(1)>", "<script>alert(1)</script>", "a > b"):
            with pytest.raises(ValueError):
                reject_markup(payload, max_length=200, field="شرح")

    def test_leave_and_overtime_forms_validate_the_submitted_text(self):
        source = open("app/main.py", encoding="utf-8").read()
        assert "_reject_markup_field(substitute, max_length=100" in source
        assert "_reject_markup_field(description, max_length=500" in source
        assert "_reject_markup_field(fromTime" in source and "_reject_markup_field(toTime" in source
