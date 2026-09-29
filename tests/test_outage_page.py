"""Internet outage mode — detection, session cut-off and the outage page.

When the link that carries ``https://hastama.ir`` is gone, the system must stop
pretending everything is fine: sessions are cut, every page request is answered
with the outage page (which carries the laboratory address), and normal service
returns automatically.  This module pins the parts that must never drift:

* the probe is TCP only (the machine's proxy settings must not decide the
  verdict) and the mode is conservative: several failures to start, two successes
  to end;
* sessions are cut exactly once per outage, with the master administrators kept —
  and never when the operator switched that off;
* the gate blocks users, while infrastructure paths, the laboratory listener,
  direct local callers (the watchdog's ``/health``) and master administrators
  keep working, so the mode is always manageable;
* the outage page needs no network at all (it is cached by the service worker),
  so the address is injected by the server and never hard coded.
"""
from __future__ import annotations

import asyncio
import base64
import json
import re
import socket

import pytest
from starlette.testclient import TestClient

from app.services import outage


def _run(coro):
    return asyncio.new_event_loop().run_until_complete(coro)


@pytest.fixture
def outage_on():
    """Force the outage state on, and put it back afterwards."""
    state = outage._state
    before = (state.active, state.enabled, state.manual, state.since, state.reason, state.threshold)
    state.active = True
    state.enabled = True
    state.manual = False
    state.since = None
    state.reason = "test"
    state.threshold = 3
    try:
        yield state
    finally:
        (state.active, state.enabled, state.manual, state.since, state.reason, state.threshold) = before


# ── probe target parsing ────────────────────────────────────────────────────


def test_targets_are_parsed_and_junk_is_dropped():
    parsed = outage.parse_targets(" hastama.ir:443 , 1.1.1.1:443 ,, nope , :80 , a:abc , b:70000 ")
    assert parsed == [("hastama.ir", 443), ("1.1.1.1", 443)]


def test_ipv6_targets_keep_their_brackets_off():
    assert outage.parse_targets("[2606:4700::1111]:443") == [("2606:4700::1111", 443)]


def test_the_default_targets_are_public_and_use_the_canonical_host():
    targets = outage.parse_targets(outage.DEFAULT_TARGETS)
    assert targets and len(targets) >= 3
    assert ("hastama.ir", 443) == targets[0]
    for host, port in targets:
        assert port == 443
        assert not host.startswith("192.168.") and not host.startswith("127.")


def test_probe_reports_reachability_without_any_http_client():
    server = socket.socket()
    server.bind(("127.0.0.1", 0))
    server.listen(1)
    port = server.getsockname()[1]
    try:
        online, detail = outage.probe([("127.0.0.1", port)], timeout=2.0)
        assert online is True and detail == f"127.0.0.1:{port}"
    finally:
        server.close()

    online, detail = outage.probe([("127.0.0.1", port)], timeout=2.0)
    assert online is False
    assert "Error" in detail or "error" in detail


def test_the_probe_never_goes_through_a_proxy():
    """A proxy environment variable must not be able to declare an outage."""
    source = open("app/services/outage.py", encoding="utf-8").read()
    assert "socket.create_connection" in source
    for forbidden in ("requests.", "httpx", "urllib.request", "http.client", "urlopen"):
        assert forbidden not in source, forbidden


# ── state machine ───────────────────────────────────────────────────────────


def _probe_to(monkeypatch, online: bool):
    monkeypatch.setattr(outage, "probe", lambda targets, timeout=None: (online, "test"))
    return _run(outage.check_now())


def test_an_outage_starts_only_after_the_configured_failures(monkeypatch):
    state = outage._state
    state.enabled, state.manual, state.threshold, state.active = True, False, 3, False
    state.failures = state.successes = 0
    cut: list = []
    monkeypatch.setattr(outage, "_terminate_everyone", lambda: cut.append(True) or 7)

    try:
        assert _probe_to(monkeypatch, False)["active"] is False
        assert _probe_to(monkeypatch, False)["active"] is False
        third = _probe_to(monkeypatch, False)
        assert third["active"] is True
        assert third["failures"] == 3 and cut == [True]
        assert third["terminated_sessions"] == 7
        assert third["since"]
    finally:
        state.active = False
        state.failures = state.successes = 0


def test_recovery_needs_two_successes(monkeypatch):
    state = outage._state
    state.enabled, state.manual, state.threshold, state.active = True, False, 1, False
    state.failures = state.successes = 0
    monkeypatch.setattr(outage, "_terminate_everyone", lambda: 0)
    try:
        assert _probe_to(monkeypatch, False)["active"] is True

        first = _probe_to(monkeypatch, True)
        assert first["active"] is True, "one good probe is not enough to reopen the system"
        second = _probe_to(monkeypatch, True)
        assert second["active"] is False
        assert second["since"] is None
    finally:
        state.active = False
        state.failures = state.successes = 0


def test_manual_mode_declares_the_outage_without_any_probe(monkeypatch):
    state = outage._state
    state.enabled, state.manual, state.active = True, False, False
    state.failures = state.successes = 0
    monkeypatch.setattr(outage.system_config, "write_flag", lambda *a, **k: True)
    monkeypatch.setattr(outage, "_terminate_everyone", lambda: 2)
    try:
        declared = _run(outage.set_manual(True, actor="admin"))
        assert declared["active"] is True and declared["manual"] is True
        assert declared["reason"] == "manual"
        assert declared["terminated_sessions"] == 2

        cleared = _run(outage.set_manual(False, actor="admin"))
        assert cleared["active"] is False
    finally:
        state.active = state.manual = False
        state.failures = state.successes = 0


def test_turning_the_feature_off_ends_the_outage(monkeypatch):
    state = outage._state
    state.enabled, state.manual, state.active, state.threshold = False, False, True, 3
    monkeypatch.setattr(outage, "_terminate_everyone", lambda: 0)
    try:
        outage._recompute_active()
        assert state.active is False
    finally:
        state.active = state.failures = state.successes = 0


# ── cutting the users off ───────────────────────────────────────────────────


def test_sessions_are_cut_once_and_master_admins_are_kept(monkeypatch):
    calls: list = []
    monkeypatch.setattr(
        "app.core.sessions.revoke_all_sessions",
        lambda by="system", keep_usernames=(): calls.append((by, tuple(keep_usernames))) or 5,
    )
    monkeypatch.setattr(outage, "_audit", lambda *a, **k: None)
    monkeypatch.setenv("MASTER_ADMIN_USERNAMES", "ali, sara")
    state = outage._state
    state.enabled, state.manual, state.active, state.terminate_sessions = True, False, False, True
    state.threshold, state.failures, state.successes = 1, 0, 0

    try:
        monkeypatch.setattr(outage, "probe", lambda targets, timeout=None: (False, "down"))
        started = _run(outage.check_now())
        assert started["active"] is True
        assert started["terminated_sessions"] == 5
        assert calls == [("outage-monitor", ("ali", "sara"))]

        # A second failing probe must not cut anybody again.
        _run(outage.check_now())
        assert len(calls) == 1
    finally:
        state.active = False
        state.failures = state.successes = 0


def test_sessions_are_kept_when_the_switch_is_off(monkeypatch):
    monkeypatch.setattr(
        "app.core.sessions.revoke_all_sessions",
        lambda *a, **k: pytest.fail("sessions must not be cut while the switch is off"),
    )
    monkeypatch.setattr(outage, "_audit", lambda *a, **k: None)
    state = outage._state
    state.enabled, state.manual, state.active, state.terminate_sessions = True, False, False, False
    state.threshold, state.failures, state.successes = 1, 0, 0
    try:
        monkeypatch.setattr(outage, "probe", lambda targets, timeout=None: (False, "down"))
        status = _run(outage.check_now())
        assert status["active"] is True and status["terminated_sessions"] == 0
    finally:
        state.active = False
        state.failures = state.successes = 0
        state.terminate_sessions = True


def test_termination_and_recovery_are_audited(monkeypatch):
    events: list = []
    monkeypatch.setattr("app.services.audit.log_event", lambda **kwargs: events.append(kwargs) or "id")
    monkeypatch.setattr(outage, "_terminate_everyone", lambda: 1)
    state = outage._state
    state.enabled, state.manual, state.active = True, False, False
    state.threshold, state.failures, state.successes = 1, 0, 0
    state.terminate_sessions = True
    try:
        monkeypatch.setattr(outage, "probe", lambda targets, timeout=None: (False, "down"))
        _run(outage.check_now())
        monkeypatch.setattr(outage, "probe", lambda targets, timeout=None: (True, "up"))
        _run(outage.check_now())
        _run(outage.check_now())

        actions = [event["action"] for event in events]
        assert actions == ["internet_outage_detected", "internet_outage_recovered"]
        assert events[0]["event_type"] == "SYSTEM" and events[0]["status"] == "failure"
        assert events[0]["metadata"]["sessions_terminated"] == 1
        assert events[1]["status"] == "success"
    finally:
        state.active = False
        state.failures = state.successes = 0


def test_a_broken_audit_or_session_layer_never_breaks_the_gate(monkeypatch):
    def _explode(*_args, **_kwargs):
        raise RuntimeError("database is gone")

    monkeypatch.setattr("app.services.audit.log_event", _explode)
    monkeypatch.setattr("app.core.sessions.revoke_all_sessions", _explode)
    state = outage._state
    state.enabled, state.manual, state.active, state.threshold = True, False, False, 1
    state.failures = state.successes = 0
    state.terminate_sessions = True
    try:
        monkeypatch.setattr(outage, "probe", lambda targets, timeout=None: (False, "down"))
        status = _run(outage.check_now())
        assert status["active"] is True
        assert status["terminated_sessions"] == 0
    finally:
        state.active = False
        state.failures = state.successes = 0


# ── settings ────────────────────────────────────────────────────────────────


def test_settings_are_validated(monkeypatch):
    monkeypatch.setattr(outage, "reload", lambda: _status_stub())
    monkeypatch.setattr(outage.system_config, "write_value", lambda *a, **k: True)
    state = outage._state
    before = (state.interval, state.threshold)
    try:
        with pytest.raises(outage.OutageError):
            _run(outage.apply_settings({"interval_seconds": 1}))
        with pytest.raises(outage.OutageError):
            _run(outage.apply_settings({"interval_seconds": 99999}))
        with pytest.raises(outage.OutageError):
            _run(outage.apply_settings({"failures": 0}))
        with pytest.raises(outage.OutageError):
            _run(outage.apply_settings({"targets": "  , ,,"}))
        assert (state.interval, state.threshold) == before
    finally:
        pass


async def _status_stub():
    return outage.status()


def test_settings_round_trip_and_are_applied(monkeypatch):
    written: dict = {}
    monkeypatch.setattr(
        outage.system_config, "write_value", lambda key, value, **k: written.setdefault(key, value) or True
    )
    # A working database: what was written is read back by the reload below.
    monkeypatch.setattr(outage.system_config, "read_value", lambda key: written.get(key))
    monkeypatch.setattr(
        outage.system_config,
        "read_flag",
        lambda key, default=False: outage.system_config.truthy(written[key]) if key in written else default,
    )
    monkeypatch.setattr(
        outage.system_config,
        "read_int",
        lambda key, default, **k: int(written[key]) if key in written else default,
    )
    monkeypatch.setattr(outage, "_recompute_active", lambda: None)
    monkeypatch.setattr(outage, "_sync_monitor", lambda: _noop())
    state = outage._state
    try:
        data = _run(
            outage.apply_settings(
                {
                    "enabled": True,
                    "interval_seconds": 45,
                    "failures": 5,
                    "targets": "example.invalid:443, 10.1.2.3:8443",
                    "terminate_sessions": False,
                    "show_lan_address": False,
                    "title": "عنوان آزمایشی",
                    "message": "متن آزمایشی",
                },
                actor="admin",
            )
        )
        assert data["enabled"] is True
        assert data["terminate_sessions"] is False
        assert data["show_lan_address"] is False
        assert data["targets"] == "example.invalid:443, 10.1.2.3:8443"
        assert written[outage.ENABLED_KEY] == "1"
        assert written[outage.INTERVAL_KEY] == "45"
        assert written[outage.FAILURES_KEY] == "5"
        assert written[outage.TARGETS_KEY] == "example.invalid:443, 10.1.2.3:8443"
        assert written[outage.LOGOUT_KEY] == "0"
        assert written[outage.SHOW_LAN_KEY] == "0"
        assert written[outage.TITLE_KEY] == "عنوان آزمایشی"
        assert data["saved"] is True
        assert data["title"] == "عنوان آزمایشی"
        assert data["interval_seconds"] == 45
        assert data["threshold"] == 5
    finally:
        state.title = outage.DEFAULT_TITLE
        state.message = outage.DEFAULT_MESSAGE
        state.interval = outage.DEFAULT_INTERVAL_SECONDS
        state.threshold = outage.DEFAULT_FAILURES
        state.targets = outage.parse_targets(outage.DEFAULT_TARGETS)


async def _noop():
    return None


def test_the_saved_settings_are_read_back_at_startup(monkeypatch):
    stored = {
        outage.ENABLED_KEY: "0",
        outage.INTERVAL_KEY: "12",
        outage.FAILURES_KEY: "9",
        outage.TARGETS_KEY: "1.1.1.1:443",
        outage.LOGOUT_KEY: "0",
        outage.SHOW_LAN_KEY: "1",
        outage.TITLE_KEY: "تیتر",
        outage.MESSAGE_KEY: "متن",
        outage.MANUAL_KEY: "0",
    }
    monkeypatch.setattr(outage.system_config, "read_value", lambda key: stored.get(key))
    monkeypatch.setattr(outage.system_config, "read_flag", lambda key, default=False: outage.system_config.truthy(stored.get(key)) if stored.get(key) is not None else default)
    monkeypatch.setattr(outage.system_config, "read_int", lambda key, default, **k: int(stored.get(key, default)))
    state = outage._state
    previous = (state.enabled, state.interval, state.threshold, state.targets, state.title, state.message, state.terminate_sessions)
    try:
        outage._load_settings()
        assert state.enabled is False
        assert state.interval == 12 and state.threshold == 9
        assert state.targets == [("1.1.1.1", 443)]
        assert state.title == "تیتر" and state.message == "متن"
        assert state.terminate_sessions is False
    finally:
        (state.enabled, state.interval, state.threshold, state.targets, state.title, state.message, state.terminate_sessions) = previous


# ── the outage page ─────────────────────────────────────────────────────────


def test_the_offline_page_can_be_rendered_without_any_network(monkeypatch):
    monkeypatch.setattr("app.services.lan_access.lan_address", lambda: "192.168.3.69")
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    response = client.get("/offline")
    assert response.status_code == 200
    body = response.text
    # Self contained: every asset would be another request that cannot be made.
    assert "<link" not in body
    assert "<script src" not in body
    assert "<img" not in body
    assert "http://192.168.3.69:5000" in body
    assert 'id="offlineCard"' in body
    assert "Cache-Control" in response.headers and response.headers["Cache-Control"] == "no-store"


def test_the_page_hides_the_laboratory_address_when_asked(monkeypatch):
    monkeypatch.setattr("app.services.lan_access.lan_address", lambda: "192.168.3.69")
    from app.main import app

    state = outage._state
    previous = state.show_lan_address
    state.show_lan_address = False
    try:
        client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
        body = client.get("/offline").text
        assert "192.168.3.69" not in body
        assert 'id="offlineLanUrl"' not in body  # no address block at all
        assert "کپی آدرس شبکهٔ داخلی" not in body
    finally:
        state.show_lan_address = previous


def test_the_page_carries_the_editable_message(monkeypatch):
    from app.main import app

    state = outage._state
    previous = (state.title, state.message)
    state.title, state.message = "تیتر سفارشی", "متن سفارشی مدیر"
    try:
        client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
        body = client.get("/offline").text
        assert "تیتر سفارشی" in body and "متن سفارشی مدیر" in body
    finally:
        state.title, state.message = previous


# ── the gate ────────────────────────────────────────────────────────────────


def test_blocked_page_requests_get_the_outage_page(outage_on, monkeypatch):
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    response = client.get("/login")
    assert response.status_code == 503
    assert 'id="offlineCard"' in response.text
    assert response.headers.get("Retry-After") == "30"


def test_api_callers_get_json_instead_of_html(outage_on):
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    response = client.get("/api/notifications/unread-count")
    assert response.status_code == 503
    payload = response.json()
    assert payload["success"] is False and payload["outage"] is True

    accepts_html = client.get("/api/notifications/unread-count", headers={"accept": "text/html"})
    assert accepts_html.status_code == 503


def test_non_page_paths_stay_reachable_during_an_outage(outage_on):
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    assert client.get("/health").status_code == 200          # the watchdog
    assert client.get("/offline").status_code == 200         # the guide itself
    assert client.get("/sw.js").status_code == 200
    assert client.get("/static/css/toast.css").status_code == 200
    assert client.get("/robots.txt").status_code == 200


def test_direct_local_callers_are_never_blocked(outage_on):
    """The watchdog, the spooler side and an administrator on the server."""
    from app.main import app

    local = TestClient(
        app, base_url="http://127.0.0.1:5000", client=("127.0.0.1", 51000), raise_server_exceptions=False
    )
    assert local.get("/health").status_code == 200
    assert 'id="offlineCard"' not in local.get("/login").text


def test_the_public_path_is_blocked_even_though_it_arrives_from_loopback(outage_on):
    """``cloudflared`` connects on loopback with forwarding headers: a user."""
    from app.main import app

    tunnelled = TestClient(
        app,
        base_url="https://hastama.ir",
        client=("127.0.0.1", 51000),
        headers={"x-forwarded-for": "5.5.5.5", "x-forwarded-proto": "https"},
        raise_server_exceptions=False,
    )
    assert tunnelled.get("/login").status_code == 503


def test_the_laboratory_listener_is_never_blocked(outage_on, monkeypatch):
    from app.main import app

    monkeypatch.setattr("app.services.lan_access.request_is_lan_http", lambda scope: True)
    client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    assert 'id="offlineCard"' not in client.get("/login").text


def test_master_administrators_keep_working(outage_on):
    """Otherwise nobody could switch the mode off again."""
    from app.core.session_cookie import installed_signer
    from app.main import app, _session_secret

    token = installed_signer(_session_secret).sign(
        base64.b64encode(json.dumps({"username": "ali", "is_master_admin": True}).encode())
    ).decode()

    client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    client.cookies.set("session", token)
    # Not the outage page: the master-admin URL itself is behind a login (the
    # fake session has no server-side record), and that is not our concern here.
    exempt = client.get("/master-admin/system-settings", follow_redirects=False)
    assert exempt.status_code != 503
    assert 'id="offlineCard"' not in exempt.text

    # A signed session without the master-admin flag is still blocked.
    plain = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    plain.cookies.set(
        "session",
        installed_signer(_session_secret)
        .sign(base64.b64encode(json.dumps({"username": "کاربر"}).encode()))
        .decode(),
    )
    assert plain.get("/login").status_code == 503


def test_a_websocket_upgrade_is_refused_cleanly(outage_on):
    """A call-display screen over the internet path must not hang."""
    from app.main import app

    sent: list = []
    scope = {
        "type": "websocket",
        "asgi": {"version": "3.0", "spec_version": "2.3"},
        "http_version": "1.1",
        "scheme": "wss",
        "path": "/api/ws/call-display",
        "raw_path": b"/api/ws/call-display",
        "query_string": b"",
        "root_path": "",
        "headers": [(b"host", b"hastama.ir")],
        "client": ("5.5.5.5", 51234),
        "server": ("127.0.0.1", 5000),
        "subprotocols": [],
        "state": {},
    }

    async def receive():
        return {"type": "websocket.connect"}

    async def send(message):
        sent.append(message)

    asyncio.new_event_loop().run_until_complete(app(scope, receive, send))
    assert sent[0]["type"] == "websocket.http.response.start"
    assert sent[0]["status"] == 503


def test_normal_work_is_untouched_when_no_outage_is_running():
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    assert client.get("/login").status_code == 200
    assert client.get("/offline").status_code == 200


# ── the service worker and the browser guard ────────────────────────────────


def test_the_service_worker_is_served_from_the_origin_root():
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    response = client.get("/sw.js")
    assert response.status_code == 200
    assert response.headers["content-type"].startswith("application/javascript")
    assert response.headers["Service-Worker-Allowed"] == "/"
    assert "no-store" in response.headers["Cache-Control"]
    assert "/offline" in response.text


def test_the_worker_only_handles_failed_page_loads():
    source = open("app/static/sw.js", encoding="utf-8").read()
    assert "request.mode !== 'navigate'" in source
    assert "catch (error)" in source
    assert "caches.open" in source
    # Only the guide may be stored, never a page the user loaded.
    assert "cache.put(OFFLINE_URL, response.clone())" in source


def test_the_guard_registers_the_worker_and_covers_the_open_tab():
    source = open("app/static/js/offline-guard.js", encoding="utf-8").read()
    assert "navigator.serviceWorker.register('/sw.js'" in source
    assert "window.isSecureContext" in source
    assert "addEventListener('offline'" in source
    assert "addEventListener('online'" in source


def test_the_guard_is_loaded_by_the_pages_users_open():
    for name in ("login", "user-panel", "admin", "master-admin", "ticket-kiosk"):
        body = open(f"app/templates/{name}.html", encoding="utf-8").read()
        assert "js/offline-guard.js" in body, name


def test_no_shipped_asset_hard_codes_an_address():
    """The page gets its address from the server — never from a file."""
    for name in ("sw.js", "js/offline-guard.js"):
        source = open(f"app/static/{name}", encoding="utf-8").read()
        for needle in ("192.168.", "127.0.0.1", "localhost"):
            assert needle not in source, f"{name}: {needle}"


# ── wiring ──────────────────────────────────────────────────────────────────


def test_the_monitor_is_wired_into_startup_and_shutdown():
    source = open("app/main.py", encoding="utf-8").read()
    assert "await outage.start()" in source
    assert "await outage.stop()" in source
    assert "app.add_middleware(_OutageGateMiddleware)" in source
    assert "@app.get(\"/offline\"" in source
    assert "@app.get(\"/sw.js\"" in source


def test_the_gate_runs_outside_the_session_and_database_layers():
    """A blocked request must not touch the session registry or the database."""
    source = open("app/main.py", encoding="utf-8").read()
    gate = source.index("app.add_middleware(_OutageGateMiddleware)")
    registry = source.index("app.add_middleware(\n    _SessionRegistryMiddleware")
    host = source.index("app.add_middleware(TrustedHostMiddleware")
    assert registry < gate < host


def test_the_settings_card_exposes_the_controls():
    js = open("app/static/js/master-admin.js", encoding="utf-8").read()
    for control in (
        "maOutageEnabled",
        "maOutageInterval",
        "maOutageFailures",
        "maOutageTargets",
        "maOutageTitle",
        "maOutageMessage",
        "maOutageTerminate",
        "maOutageShowLan",
        "maOutageSave",
        "maOutageCheck",
        "maOutageManual",
        "'/outage'",
        "'/outage/check'",
        "'/outage/manual'",
    ):
        assert control in js, control


def test_the_master_admin_endpoints_are_protected():
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    assert client.get("/master-admin/api/outage").status_code == 401
    for path in ("/master-admin/api/outage", "/master-admin/api/outage/check", "/master-admin/api/outage/manual"):
        assert client.post(path, json={}).status_code == 403, path


def test_the_defaults_are_conservative():
    assert outage.MIN_INTERVAL_SECONDS >= 5
    assert outage.MIN_FAILURES >= 1
    assert outage.RECOVERY_SUCCESSES >= 2
    assert outage.PROBE_TIMEOUT_SECONDS >= 1
    # The feature is armed by default (a missing row means "on") …
    assert outage.DEFAULT_FAILURES == 3
    # … and nothing in the page can be filled with untrusted HTML.
    assert "<script" not in outage.DEFAULT_MESSAGE
