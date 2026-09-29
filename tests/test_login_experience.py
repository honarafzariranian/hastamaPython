"""Login experience — the full-screen loader and the CAPTCHA lifetime.

Two long-standing annoyances on ``/login`` are pinned here:

* pressing *ورود* must not turn the button into *"در حال ورود..."* and then green;
  the page covers itself with a loader for a configured number of seconds (three
  by default) and then lands on the requested page;
* a CAPTCHA that expired while the user was typing must say so — and the message
  must *stay* on screen (the old code refreshed the image and immediately erased
  the explanation, so the screen looked as if nothing had happened).

Both are configurable in ``master-admin → system settings``, so the settings
round trip, the validation and the values the public page can read are covered
as well.
"""
from __future__ import annotations

import asyncio
import json
import time

import pytest
from starlette.testclient import TestClient

from app.services import captcha as captcha_service
from app.services import login_experience


def _run(coro):
    return asyncio.new_event_loop().run_until_complete(coro)


_SNAPSHOT_FIELDS = (
    "loader_enabled",
    "loader_seconds",
    "loader_title",
    "loader_message",
    "captcha_notice",
    "captcha_ttl",
)


@pytest.fixture
def login_ux(monkeypatch):
    """Known settings for the duration of a test, restored afterwards."""
    state = login_experience._state
    before = {name: getattr(state, name) for name in _SNAPSHOT_FIELDS}
    state.loader_enabled = True
    state.loader_seconds = 3
    state.loader_title = login_experience.DEFAULT_TITLE
    state.loader_message = login_experience.DEFAULT_MESSAGE
    state.captcha_notice = True
    state.captcha_ttl = 180
    try:
        yield login_experience
    finally:
        for name, value in before.items():
            setattr(state, name, value)


class _Req:
    """The request shape ``/login_user`` needs (session + peer + headers)."""

    def __init__(self, body: dict, session: dict | None = None):
        self._body = body
        self.session = dict(session or {})
        self.headers = {"user-agent": "pytest"}
        self.method = "POST"

        class _Client:
            host = "10.0.0.5"

        self.client = _Client()

    async def json(self):
        return self._body


def _login(body: dict, session: dict | None = None):
    """Call the login handler without touching users, sessions or the network."""
    from app.api.routes import auth as auth_module

    originals = (
        auth_module.fetch_user_for_login,
        auth_module._record_login_result,
        auth_module.session_registry.register_session,
        auth_module.log_event_safe,
    )
    auth_module.fetch_user_for_login = lambda cursor, username: None
    auth_module._record_login_result = lambda username, success: None
    auth_module.session_registry.register_session = lambda *a, **k: True
    auth_module.log_event_safe = lambda **kwargs: None
    try:
        request = _Req(body, session=session)
        response = _run(auth_module.login(request))
        payload = json.loads(bytes(response.body).decode("utf-8"))
        return response.status_code, payload, request
    finally:
        (
            auth_module.fetch_user_for_login,
            auth_module._record_login_result,
            auth_module.session_registry.register_session,
            auth_module.log_event_safe,
        ) = originals


# ── the service ─────────────────────────────────────────────────────────────


def test_the_defaults_are_a_three_second_loader_and_a_three_minute_code(login_ux):
    status = login_ux.status()
    assert status["loader_enabled"] is True
    assert status["loader_seconds"] == 3
    assert status["captcha_ttl_seconds"] == 180
    assert status["captcha_notice"] is True
    assert status["min_seconds"] <= 3 <= status["max_seconds"]
    assert login_ux.MIN_CAPTCHA_TTL_SECONDS <= 180 <= login_ux.MAX_CAPTCHA_TTL_SECONDS


def test_the_loader_is_armed_by_default_without_any_row():
    """A missing row must keep the nice default, not disable the loader."""
    source = open("app/services/login_experience.py", encoding="utf-8").read()
    assert "system_config.read_flag(ENABLED_KEY, True)" in source
    assert "system_config.read_flag(NOTICE_KEY, True)" in source


def test_the_public_config_is_strings_only_and_never_a_secret(login_ux):
    public = login_ux.public_config()
    assert public[login_experience.SECONDS_KEY] == "3"
    assert public[login_experience.TTL_KEY] == "180"
    assert public[login_experience.ENABLED_KEY] == "1"
    assert all(isinstance(value, str) for value in public.values())
    # Nothing about the database, the host or an account may leak through it.
    for value in public.values():
        assert "sql" not in value.lower() and "secret" not in value.lower()


def test_the_config_is_clamped_even_if_the_row_was_edited_by_hand(login_ux, monkeypatch):
    state = login_ux._state
    state.loader_seconds = 9999
    state.captcha_ttl = 1
    status = login_ux.status()
    assert status["loader_seconds"] == login_ux.MAX_SECONDS
    assert status["captcha_ttl_seconds"] == login_ux.MIN_CAPTCHA_TTL_SECONDS


def test_settings_round_trip_and_apply(login_ux, monkeypatch):
    writes: list = []
    monkeypatch.setattr(
        login_experience.system_config,
        "write_value",
        lambda key, value, actor="", description="": writes.append((key, value)) or True,
    )

    def fake_read_value(key):
        for stored_key, stored_value in reversed(writes):
            if stored_key == key:
                return stored_value
        return None

    def fake_read_flag(key, default=False):
        for stored_key, stored_value in reversed(writes):
            if stored_key == key:
                return login_experience.system_config.truthy(stored_value)
        return default

    def fake_read_int(key, default, *, minimum=0, maximum=10**9):
        value = fake_read_value(key)
        try:
            parsed = int(str(value).strip())
        except (TypeError, ValueError):
            return default
        return max(minimum, min(maximum, parsed))

    monkeypatch.setattr(login_experience.system_config, "read_value", fake_read_value)
    monkeypatch.setattr(login_experience.system_config, "read_flag", fake_read_flag)
    monkeypatch.setattr(login_experience.system_config, "read_int", fake_read_int)

    saved = login_ux.apply_settings(
        {
            "loader_enabled": True,
            "loader_seconds": 5,
            "loader_title": "  خوش   آمدید  ",
            "loader_message": "متن سفارشی",
            "captcha_notice": False,
            "captcha_ttl_seconds": 90,
        },
        actor="ali",
    )
    assert saved["saved"] is True
    assert saved["loader_seconds"] == 5
    assert saved["loader_title"] == "خوش آمدید"
    assert saved["captcha_ttl_seconds"] == 90
    assert saved["captcha_notice"] is False
    assert {key for key, _ in writes} == {
        login_experience.ENABLED_KEY,
        login_experience.SECONDS_KEY,
        login_experience.TITLE_KEY,
        login_experience.MESSAGE_KEY,
        login_experience.NOTICE_KEY,
        login_experience.TTL_KEY,
    }


@pytest.mark.parametrize(
    "payload",
    [
        {"loader_seconds": 0},
        {"loader_seconds": 60},
        {"loader_seconds": "abc"},
        {"captcha_ttl_seconds": 5},
        {"captcha_ttl_seconds": 100000},
        {"captcha_ttl_seconds": "abc"},
    ],
)
def test_settings_are_validated(login_ux, payload):
    with pytest.raises(login_experience.LoginExperienceError):
        login_ux.apply_settings(payload)


def test_a_non_object_body_is_rejected(login_ux):
    with pytest.raises(login_experience.LoginExperienceError):
        login_ux.apply_settings(["nope"])


def test_the_texts_are_capped(login_ux, monkeypatch):
    monkeypatch.setattr(login_experience.system_config, "write_value", lambda *a, **k: True)
    saved = login_ux.apply_settings({"loader_title": "ت" * 500, "loader_message": "م" * 900})
    assert len(saved["loader_title"]) <= login_experience.MAX_TITLE_CHARS
    assert len(saved["loader_message"]) <= login_experience.MAX_MESSAGE_CHARS


# ── the CAPTCHA lifetime ────────────────────────────────────────────────────


def _session(age_seconds: float, code: str = "ABC123", attempts: int = 0) -> dict:
    return {
        captcha_service.CAPTCHA_SESSION_KEY: code,
        captcha_service.CAPTCHA_TS_KEY: time.time() - age_seconds,
        captcha_service.CAPTCHA_ATTEMPTS_KEY: attempts,
    }


def test_a_freshly_issued_code_has_the_configured_lifetime(login_ux):
    request = _Req({}, session=_session(0))
    assert captcha_service.captcha_expiry_seconds() == 180
    assert captcha_service.captcha_remaining_seconds(request) == 180
    assert captcha_service.captcha_expired(request) is False


def test_the_lifetime_follows_the_setting(login_ux):
    login_ux._state.captcha_ttl = 45
    request = _Req({}, session=_session(30))
    assert captcha_service.captcha_expiry_seconds() == 45
    assert captcha_service.captcha_remaining_seconds(request) == 15
    assert captcha_service.captcha_expired(request) is False


def test_an_old_code_counts_as_expired_without_being_cleared(login_ux):
    request = _Req({}, session=_session(400))
    assert captcha_service.captcha_expired(request) is True
    # Reading the verdict must not destroy the evidence …
    assert request.session.get(captcha_service.CAPTCHA_SESSION_KEY) == "ABC123"
    # … while validating it does clear the session (that is why the reason has to
    # be read first).
    valid, message = captcha_service.validate_captcha(request, "ABC123")
    assert valid is False
    assert "منقضی" in message
    assert request.session.get(captcha_service.CAPTCHA_SESSION_KEY) is None


def test_without_a_session_there_is_nothing_to_expire(login_ux):
    assert captcha_service.captcha_expired(_Req({}, session={})) is False


# ── the endpoints the login page talks to ───────────────────────────────────


def _client():
    from app.main import app

    return TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)


def test_the_public_config_carries_the_login_settings():
    response = _client().get("/api/system-config")
    assert response.status_code == 200
    data = response.json()["data"]
    for key in (
        "captcha_enabled",
        "login_loader_enabled",
        "login_loader_seconds",
        "login_loader_title",
        "login_loader_message",
        "login_captcha_notice",
        "login_captcha_ttl_seconds",
    ):
        assert key in data, key
    assert data["login_loader_seconds"] in ("1", "2", "3", "4", "5", "6", "7", "8", "9", "10", "11", "12", "13", "14", "15")


def test_the_captcha_status_reports_the_lifetime_and_the_notice(login_ux):
    payload = _client().get("/captcha/status").json()
    assert payload["success"] is True
    assert payload["ttl_seconds"] == 180
    assert payload["notice_enabled"] is True
    assert payload["warning_lead_seconds"] >= 5
    assert "remaining_seconds" in payload and "has_captcha" in payload


def test_an_expired_submission_says_exactly_that(login_ux):
    """The whole point: the page can tell "expired" from "wrong code"."""
    status_code, payload, _ = _login(
        {"username": "ali", "password": "x", "captcha": "ABC123"},
        session=_session(400),
    )
    assert status_code == 200
    assert payload["captcha_error"] is True
    assert payload["captcha_expired"] is True
    assert payload["captcha_reason"] == "expired"
    assert "منقضی" in payload["message"]


def test_a_wrong_code_is_not_reported_as_expired(login_ux):
    _, payload, _ = _login(
        {"username": "ali", "password": "x", "captcha": "WRONG"},
        session=_session(0),
    )
    assert payload["captcha_error"] is True
    assert payload["captcha_expired"] is False
    assert payload["captcha_reason"] == "mismatch"


def test_a_session_without_a_code_is_reported_as_missing(login_ux):
    _, payload, _ = _login({"username": "ali", "password": "x", "captcha": "ABC123"}, session={})
    assert payload["captcha_error"] is True
    assert payload["captcha_reason"] == "missing"


def test_an_empty_code_field_is_reported_as_missing_input(login_ux):
    _, payload, _ = _login({"username": "ali", "password": "x", "captcha": ""}, session=_session(0))
    assert payload["captcha_error"] is True
    assert payload["captcha_reason"] == "missing_input"


# ── the page itself ─────────────────────────────────────────────────────────


def test_the_old_button_sequence_is_gone():
    """No "در حال ورود…" text, no green success state, no such markup at all."""
    html = open("app/templates/login.html", encoding="utf-8").read()
    assert "h-ux-btn-loading-text" not in html
    assert "h-ux-btn-spinner" not in html
    assert "در حال ورود" not in html

    script = open("app/static/js/script.js", encoding="utf-8").read()
    assert "btnLoad" not in script
    assert "btnDone" not in script
    assert "btnReset" not in script


def test_the_loader_is_built_by_the_login_script():
    script = open("app/static/js/script.js", encoding="utf-8").read()
    assert "login-loader" in script
    assert "buildLoginLoader" in script
    assert "animationDuration" in script           # the bar fills over the setting
    assert "whenLoaderIsDone" in script            # …and the page waits for it
    assert "window.location.href = data.redirect" in script
    css = open("app/static/css/login-style.css", encoding="utf-8").read()
    for rule in (".login-loader", ".login-loader__card", ".login-loader__bar", "loginLoaderSpin", "loginLoaderBar"):
        assert rule in css, rule


def test_the_page_takes_its_settings_from_the_server():
    html = open("app/templates/login.html", encoding="utf-8").read()
    assert "HASTAMA_LOGIN_UX" in html
    assert "login_loader_seconds" in html
    assert "login_captcha_ttl_seconds" in html
    assert "startCaptchaWatch" in html
    script = open("app/static/js/script.js", encoding="utf-8").read()
    # Fallbacks keep the page usable when /api/system-config cannot answer.
    assert "loaderEnabled: true" in script
    assert "loaderSeconds: 3" in script


def test_the_expiry_message_survives_the_refresh():
    """The bug that started this: refreshCaptcha used to erase the message."""
    script = open("app/static/js/script.js", encoding="utf-8").read()
    assert "keepMessage" in script
    assert "refreshCaptcha({ keepMessage: true })" in script
    # The expired branch explains first, then marks the field: the message must
    # not be swallowed by a plain hide call.
    expired_branch = script.split("refreshCaptcha({ keepMessage: true })", 1)[1].split("} else {", 1)[0]
    assert "markCaptchaExpired(message)" in expired_branch
    assert "hideCaptchaError()" not in expired_branch


def test_the_expired_state_is_visible_and_self_healing():
    script = open("app/static/js/script.js", encoding="utf-8").read()
    css = open("app/static/css/login-style.css", encoding="utf-8").read()
    assert "captcha-expired" in script and "captcha-expired" in css
    assert "captchaPulse" in css or "captchaRefreshPulse" in css
    assert "/captcha/status" in script
    assert "setInterval(pollCaptchaStatus" in script
    # …and the animation the old code asked for but never defined.
    assert "captchaShake" in css
    assert "animation = 'shake" not in script


def test_the_login_page_loads_the_reworked_script():
    html = open("app/templates/login.html", encoding="utf-8").read()
    assert "js/script.js" in html
    # A stale cache would keep serving the old behaviour to the users.
    assert "}}?v=" in html


# ── the settings card and the API ───────────────────────────────────────────


def test_the_settings_card_exposes_the_controls():
    js = open("app/static/js/master-admin.js", encoding="utf-8").read()
    for control in (
        "loginExperienceCard(",
        "maLoginLoaderEnabled",
        "maLoginLoaderSeconds",
        "maLoginLoaderTitle",
        "maLoginLoaderMessage",
        "maLoginCaptchaNotice",
        "maLoginCaptchaTtl",
        "maLoginSave",
        "'/login-experience'",
    ):
        assert control in js, control
    assert "loadSystemSettings" in js


def test_the_master_admin_endpoints_are_protected():
    for path in ("/master-admin/api/login-experience",):
        assert _client().get(path).status_code in (401, 403, 405), path
        assert _client().post(path, json={}).status_code in (401, 403), path


def test_the_endpoints_are_registered_and_audited():
    source = open("app/api/routes/master_admin.py", encoding="utf-8").read()
    assert '@router.get("/login-experience")' in source
    assert '@router.post("/login-experience")' in source
    assert "update_login_experience" in source


def test_the_service_is_wired_into_startup():
    source = open("app/main.py", encoding="utf-8").read()
    assert "login_experience.start()" in source
    # The service bundle is imported in one statement; assert membership rather
    # than the exact text so adding another service cannot break this wiring test.
    import_line = next(
        line for line in source.splitlines() if line.startswith("from app.services import")
    )
    for name in ("iran_access", "lan_access", "login_experience", "outage"):
        assert name in import_line, f"{name} is no longer imported in app/main.py"
    assert "login_experience.public_config()" in source


def test_the_hard_coded_lifetime_is_only_a_fallback():
    source = open("app/services/captcha.py", encoding="utf-8").read()
    assert "captcha_ttl_seconds" in source
    # Every decision must go through the helper, not the constant.
    assert "time.time() - stored_ts > captcha_expiry_seconds()" in source
    assert "captcha_expiry_seconds() - elapsed" in source


def test_the_feature_is_documented():
    register = open("docs/security/RESIDUAL_RISK_REGISTER.md", encoding="utf-8").read()
    assert "RR-32" in register
