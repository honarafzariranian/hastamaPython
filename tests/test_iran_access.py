"""Iran-only access — the offline verdict, the gate and the warning page.

Only Iranian public addresses may use ``https://hastama.ir``.  A user who is
still behind a VPN (or abroad) is answered with the warning page that asks them
to switch it off.  This module pins the parts that must never drift:

* the verdict is made **offline** from the range file shipped with the
  application (RIPE + APNIC allocations) — no geo-IP web service, so it keeps
  working while the internet is down and never leaks a visitor address;
* the address list is the only thing that needs the network, and only when an
  administrator asks for it;
* an unreadable list must never become a silent lockout: the filter reports that
  it is armed but blind instead of refusing everyone;
* internal addresses (server, laboratory LAN, CGNAT), infrastructure paths, the
  LAN listener and the master administrator are never affected, so the switch is
  always reachable;
* a client can never talk its way in by forging forwarding headers.
"""
from __future__ import annotations

import asyncio
import base64
import json

import pytest
from starlette.testclient import TestClient

from app.services import iran_access


def _run(coro):
    return asyncio.new_event_loop().run_until_complete(coro)


_SNAPSHOT_FIELDS = (
    "enabled",
    "title",
    "message",
    "help_text",
    "log_blocked",
    "image",
    "file_stamp",
    "blocked_count",
    "allowed_count",
    "last_blocked_ip",
    "last_blocked_at",
    "last_blocked_path",
    "recent",
    "last_refresh",
    "last_refresh_error",
)


@pytest.fixture
def only_iran(monkeypatch):
    """Arm the filter with the real shipped list, and restore the state after."""
    state = iran_access._state
    before = {name: getattr(state, name) for name in _SNAPSHOT_FIELDS}
    before["recent"] = dict(state.recent)
    monkeypatch.setattr(iran_access, "log_event_hook", None, raising=False)
    iran_access.start()
    assert iran_access.enforcing(), "the shipped Iranian range list must be usable"
    try:
        yield iran_access
    finally:
        for name, value in before.items():
            setattr(state, name, value)


@pytest.fixture
def filter_off(only_iran):
    """Armed-but-off, to prove that a switched-off filter blocks nobody."""
    only_iran._state.enabled = False
    try:
        yield only_iran
    finally:
        only_iran._state.enabled = True


def _client(ip: str = "", base: str = "https://hastama.ir", **kwargs):
    """A TestClient whose socket peer is *ip* (the tunnel/laboratory shape)."""
    from app.main import app

    if ip:
        kwargs.setdefault("client", (ip, 51000))
    return TestClient(app, base_url=base, raise_server_exceptions=False, **kwargs)


# ── the shipped address list ────────────────────────────────────────────────


def test_the_iranian_address_list_ships_with_the_application():
    assert iran_access.DATA_PATH.exists(), "app/data/iran_ip_ranges.txt must be committed"
    metadata = iran_access.list_metadata()
    assert metadata["bytes"] > 10_000
    assert "generated:" in metadata["generated"] or metadata["generated"]
    assert "ripe" in metadata["source"].lower() and "apnic" in metadata["source"].lower()


def test_the_list_parses_into_far_more_ranges_than_any_hand_written_copy():
    parsed = iran_access.parse_list_text(iran_access.DATA_PATH.read_text(encoding="utf-8"))
    merged_v4 = iran_access._merge(parsed["v4"])
    merged_v6 = iran_access._merge(parsed["v6"])
    assert len(merged_v4[0]) >= 1000, "the whole registry allocation must be present"
    assert len(merged_v6[0]) >= 400


def test_comments_blank_lines_and_junk_are_ignored():
    parsed = iran_access.parse_list_text(
        "\n".join(
            [
                "# a comment",
                "; another comment",
                "",
                "2.144.0.0/14   # trailing comment",
                "not-an-address",
                "999.1.2.3/24",
                "2001:790::/32",
            ]
        )
    )
    assert parsed["v4"] == [(int.from_bytes(bytes([2, 144, 0, 0]), "big"), int.from_bytes(bytes([2, 147, 255, 255]), "big"))]
    assert len(parsed["v6"]) == 1


def test_the_list_is_only_ever_rebuilt_from_the_registries():
    """The refresh button must be an explicit, registry-only action."""
    source = open("app/services/iran_access.py", encoding="utf-8").read()
    assert "ftp.ripe.net" in source and "ftp.apnic.net" in source
    # Direct download: a VPN proxy on the server must not decide the list.
    assert 'urllib.request.ProxyHandler({})' in source
    # …and nothing asks a geo-IP service about a visitor.
    for service in ("ip-api.com", "ipapi.co", "ipinfo.io", "maxmind", "geoip2"):
        assert service not in source.lower()


# ── registry parsing (offline, from a fixture) ──────────────────────────────

_REGISTRY_FIXTURE = "\n".join(
    [
        "2|ripencc|20260901|1|20260901|+0000",
        "ripencc|*|asn|*|1|summary",
        "ripencc|IR|ipv4|2.144.0.0|2048|20100604|allocated",
        "ripencc|IR|ipv4|5.22.0.0|1000|20100604|assigned",
        "ripencc|IR|ipv4|31.7.0.0|512|20100604|available",
        "ripencc|DE|ipv4|85.10.0.0|4096|20100604|allocated",
        "ripencc|ir|ipv6|2001:790::|32|20100604|allocated",
        "ripencc|IR|ipv4|2.144.0.0|broken|20100604|allocated",
        "apnic|IR|ipv4|103.1.2.0|1024|20100604|allocated",
    ]
)


def test_registry_parsing_keeps_only_ir_allocations():
    parsed = iran_access.registry_lines(_REGISTRY_FIXTURE)
    starts = sorted(int(start) for start, _ in parsed["v4"])
    assert int.from_bytes(bytes([2, 144, 0, 0]), "big") in starts      # /21
    assert int.from_bytes(bytes([5, 22, 0, 0]), "big") in starts       # assigned, not a power of two
    assert int.from_bytes(bytes([103, 1, 2, 0]), "big") in starts      # another registry
    assert int.from_bytes(bytes([31, 7, 0, 0]), "big") not in starts   # status: available
    assert int.from_bytes(bytes([85, 10, 0, 0]), "big") not in starts  # another country
    assert len(parsed["v6"]) == 1                                      # lowercase country code accepted


def test_the_rendered_list_round_trips_through_the_parser():
    bounds = {"v4": [(int(iran_access.normalize_ip("2.144.0.0")) + 0, int(iran_access.normalize_ip("2.144.0.0")) + 2047)], "v6": []}
    body = iran_access.render_list(bounds, generated="2026-01-01T00:00:00+00:00", source="fixture")
    assert body.startswith("#")
    assert "generated: 2026-01-01T00:00:00+00:00" in body
    parsed = iran_access.parse_list_text(body)
    assert parsed["v4"] == bounds["v4"]


# ── the verdict ─────────────────────────────────────────────────────────────


@pytest.mark.parametrize(
    "address, kind",
    [
        ("2.144.0.1", "iran"),
        ("2.147.255.254", "iran"),
        ("5.22.192.5", "iran"),
        ("185.55.226.10", "iran"),
        ("2001:790::1", "iran"),
        ("8.8.8.8", "foreign"),
        ("1.1.1.1", "foreign"),
        ("104.28.0.1", "foreign"),
        ("192.168.3.69", "internal"),
        ("10.1.2.3", "internal"),
        ("172.16.5.5", "internal"),
        ("100.64.3.4", "internal"),
        ("127.0.0.1", "internal"),
        ("::1", "internal"),
        ("fe80::1", "internal"),
        ("2001:db8::1", "internal"),
        ("", "unknown"),
        ("not-an-ip", "unknown"),
        ("testclient", "unknown"),
    ],
)
def test_addresses_are_classified(only_iran, address, kind):
    assert iran_access.classify(address)["kind"] == kind


def test_a_dual_stack_peer_is_normalised_before_the_verdict(only_iran):
    assert iran_access.classify("::ffff:2.144.0.1")["kind"] == "iran"
    assert iran_access.classify("::ffff:8.8.8.8")["kind"] == "foreign"


def test_a_peer_address_with_its_port_is_still_an_address(only_iran):
    assert iran_access.normalize_ip("2.144.0.1:51000") is not None
    assert iran_access.normalize_ip("[2001:790::1]:51000") is not None
    assert iran_access.classify("2.144.0.1:51000")["kind"] == "iran"


def test_the_verdict_names_the_matching_range(only_iran):
    verdict = iran_access.classify("2.144.0.1")
    assert verdict["allowed"] is True
    assert "2.144.0.0/14" in verdict["range"]


def test_nothing_is_blocked_while_the_switch_is_off(filter_off):
    assert filter_off.enforcing() is False
    assert filter_off.is_blocked("8.8.8.8") is False
    assert filter_off.classify("8.8.8.8")["kind"] == "foreign"  # the fact is still reported


def test_an_unreadable_list_reports_itself_instead_of_blocking_everyone(only_iran, monkeypatch, tmp_path):
    monkeypatch.setattr(iran_access, "DATA_PATH", tmp_path / "missing.txt")
    only_iran._load_list(force=True)
    try:
        status = only_iran.status()
        assert status["enabled"] is True
        assert status["list_loaded"] is False
        assert status["enforcing"] is False         # armed, but blind
        assert status["list_error"]                 # …and the card can say why
        assert only_iran.classify("8.8.8.8")["allowed"] is True
        assert only_iran.is_blocked("8.8.8.8") is False
    finally:
        monkeypatch.undo()
        only_iran._load_list(force=True)
    assert only_iran.enforcing() is True            # the real list is back


def test_an_unknown_peer_is_never_treated_as_a_vpn(only_iran):
    """A broken peer value is an infrastructure bug, not a user signal."""
    verdict = only_iran.classify("")
    assert verdict["kind"] == "unknown" and verdict["allowed"] is True


def test_the_status_shape_the_card_depends_on(only_iran):
    status = only_iran.status()
    for key in (
        "enabled",
        "enforcing",
        "list_loaded",
        "ranges_ipv4",
        "ranges_ipv6",
        "list_generated",
        "blocked_count",
        "last_blocked_ip",
        "title",
        "message",
        "help_text",
        "log_blocked",
    ):
        assert key in status, key
    assert status["armed_by_default"] is True


# ── settings ────────────────────────────────────────────────────────────────


def test_the_switch_is_armed_by_default():
    """A missing row means "on": the filter is the standing policy."""
    from app.services import system_config

    source = open("app/services/iran_access.py", encoding="utf-8").read()
    assert "system_config.read_flag(ENABLED_KEY, True)" in source
    assert system_config.truthy("1") is True


def test_settings_round_trip_and_apply(only_iran, monkeypatch):
    writes: list = []
    monkeypatch.setattr(
        iran_access.system_config,
        "write_value",
        lambda key, value, actor="", description="": writes.append((key, value)) or True,
    )

    def fake_read_flag(key, default=False):
        for stored_key, stored_value in reversed(writes):
            if stored_key == key:
                return iran_access.system_config.truthy(stored_value)
        return default

    def fake_read_value(key):
        for stored_key, stored_value in reversed(writes):
            if stored_key == key:
                return stored_value
        return None

    monkeypatch.setattr(iran_access.system_config, "read_flag", fake_read_flag)
    monkeypatch.setattr(iran_access.system_config, "read_value", fake_read_value)

    saved = only_iran.apply_settings(
        {
            "enabled": False,
            "title": "  پیام   سفارشی  ",
            "message": "متن سفارشی مدیر",
            "help_text": "خط اول\n\nخط دوم",
            "log_blocked": False,
        },
        actor="ali",
    )
    assert saved["saved"] is True
    assert saved["enabled"] is False
    assert saved["title"] == "پیام سفارشی"
    assert saved["help_text"] == "خط اول\n\nخط دوم"
    assert saved["log_blocked"] is False
    assert {key for key, _ in writes} == {
        iran_access.ENABLED_KEY,
        iran_access.TITLE_KEY,
        iran_access.MESSAGE_KEY,
        iran_access.HELP_KEY,
        iran_access.LOG_KEY,
    }


def test_a_non_object_body_is_rejected(only_iran):
    with pytest.raises(iran_access.IranAccessError):
        only_iran.apply_settings(["nope"])


def test_the_texts_are_capped(only_iran, monkeypatch):
    monkeypatch.setattr(iran_access.system_config, "write_value", lambda *a, **k: True)
    saved = only_iran.apply_settings({"message": "م" * 5000, "title": "ت" * 500})
    assert len(saved["message"]) <= iran_access.MAX_MESSAGE_CHARS
    assert len(saved["title"]) <= iran_access.MAX_TITLE_CHARS


def test_the_defaults_are_about_vpn_not_about_a_country_list():
    assert "VPN" in iran_access.DEFAULT_MESSAGE
    assert "قطع" in iran_access.DEFAULT_MESSAGE
    assert iran_access.DEFAULT_HELP.count("\n") >= 3
    assert "<script" not in iran_access.DEFAULT_MESSAGE


# ── the gate ────────────────────────────────────────────────────────────────


def test_a_foreign_address_gets_the_warning_page(only_iran):
    response = _client("5.5.5.5").get("/login")
    assert response.status_code == 403
    assert 'id="vpnCard"' in response.text
    assert response.headers.get("x-hastama-blocked") == "iran-only"
    assert "VPN" in response.text
    assert "5.5.5.5" in response.text          # the address we derived is shown back
    assert response.headers.get("retry-after") == "30"


def test_an_iranian_address_reaches_the_login_page(only_iran):
    response = _client("2.144.0.1").get("/login")
    assert response.status_code == 200
    assert 'id="vpnCard"' not in response.text


def test_api_callers_get_json_instead_of_html(only_iran):
    client = _client("5.5.5.5")
    response = client.get("/api/notifications/unread-count", headers={"accept": "application/json"})
    assert response.status_code == 403
    payload = response.json()
    assert payload["success"] is False
    assert payload["iran_only"] is True and payload["vpn"] is True
    assert payload["ip"] == "5.5.5.5"
    # An HTML caller on an API path still must not receive a document.
    assert client.get("/api/notifications/unread-count").headers["x-hastama-blocked"] == "iran-only"


def test_infrastructure_paths_keep_working_for_a_blocked_user(only_iran):
    client = _client("5.5.5.5")
    assert client.get("/health").status_code == 200
    assert client.get("/static/css/toast.css").status_code == 200
    assert client.get("/iran-only").status_code == 200
    assert client.get("/iran-only/check").status_code == 200
    assert client.get("/offline").status_code == 200
    assert client.get("/sw.js").status_code == 200
    assert client.get("/robots.txt").status_code == 200


def test_the_warning_page_tells_the_user_what_to_do(only_iran):
    body = _client("5.5.5.5").get("/iran-only").text
    assert "سامانه" in body
    assert "/iran-only/check" in body            # it re-checks by itself
    assert "<link" not in body                   # …and loads nothing from the network
    assert "http://" not in body.replace("http://www.w3.org", "")


def test_the_check_endpoint_answers_about_the_caller_only(only_iran):
    payload = _client("5.5.5.5").get("/iran-only/check").json()
    assert payload == {"success": True, "allowed": False, "ip": "5.5.5.5", "kind": "foreign", "enforcing": True}
    payload = _client("2.144.0.1").get("/iran-only/check").json()
    assert payload["allowed"] is True and payload["kind"] == "iran"


def test_internal_callers_are_never_blocked(only_iran):
    for address in ("127.0.0.1", "192.168.3.44", "10.0.0.9", "100.64.7.7"):
        response = _client(address, base="http://127.0.0.1:5000").get("/login")
        assert response.status_code == 200, address
        assert 'id="vpnCard"' not in response.text


def test_the_laboratory_listener_is_never_blocked(only_iran, monkeypatch):
    monkeypatch.setattr("app.services.lan_access.request_is_lan_http", lambda scope: True)
    response = _client("5.5.5.5").get("/login")
    assert response.status_code == 200
    assert 'id="vpnCard"' not in response.text


def test_master_administrators_may_work_over_a_vpn(only_iran):
    """Otherwise nobody could switch the filter off from where it matters."""
    from app.core.session_cookie import installed_signer
    from app.main import _session_secret

    token = installed_signer(_session_secret).sign(
        base64.b64encode(json.dumps({"username": "ali", "is_master_admin": True}).encode())
    ).decode()

    client = _client("5.5.5.5")
    client.cookies.set("session", token)
    exempt = client.get("/master-admin/system-settings", follow_redirects=False)
    assert exempt.status_code != 403

    # A signed session that is *not* a master administrator is still refused.
    plain = _client("5.5.5.5")
    plain.cookies.set(
        "session",
        installed_signer(_session_secret)
        .sign(base64.b64encode(json.dumps({"username": "کاربر"}).encode()))
        .decode(),
    )
    assert plain.get("/login").status_code == 403


def test_forwarding_headers_cannot_buy_an_entry(only_iran):
    """A direct client's ``X-Forwarded-For`` is attacker controlled."""
    response = _client("5.5.5.5", headers={"x-forwarded-for": "2.144.0.1"}).get("/login")
    assert response.status_code == 403

    # …and even through the trusted tunnel, only the real client address counts:
    # a client that prepends an Iranian address must still be refused, because
    # the first entry is the one the client chose.
    tunnelled = _client(
        "127.0.0.1",
        headers={"x-forwarded-for": "2.144.0.1, 5.5.5.5", "x-forwarded-proto": "https"},
    )
    assert tunnelled.get("/login").status_code == 403


def test_a_websocket_upgrade_is_refused_cleanly(only_iran):
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

    _run(app(scope, receive, send))
    assert sent and sent[0]["type"] == "websocket.http.response.start"
    assert sent[0]["status"] == 403


def test_normal_work_is_untouched_while_the_filter_is_off(filter_off):
    assert _client("5.5.5.5").get("/login").status_code == 200
    assert _client("5.5.5.5").get("/iran-only/check").json()["enforcing"] is False


# ── auditing and counters ───────────────────────────────────────────────────


def test_a_block_is_counted_and_audited_once_per_window(only_iran, monkeypatch):
    events: list = []
    monkeypatch.setattr("app.services.audit.log_event", lambda **kwargs: events.append(kwargs))
    only_iran.reset_counters()

    client = _client("5.5.5.5")
    for _ in range(5):
        client.get("/login")

    assert only_iran.status()["blocked_count"] == 5
    assert only_iran.status()["last_blocked_ip"] == "5.5.5.5"
    assert only_iran.status()["last_blocked_path"] == "/login"
    assert len(events) == 1, "a hostile loop must not flood the audit log"
    assert events[0]["action"] == "iran_only_blocked"
    assert events[0]["severity"] == "medium"
    assert events[0]["resource_id"] == "5.5.5.5"
    assert events[0]["metadata"]["reason"]


def test_the_audit_switch_is_respected(only_iran, monkeypatch):
    events: list = []
    monkeypatch.setattr("app.services.audit.log_event", lambda **kwargs: events.append(kwargs))
    only_iran.reset_counters()
    only_iran._state.log_blocked = False

    _client("5.5.5.5").get("/login")

    assert only_iran.status()["blocked_count"] == 1
    assert events == []


def test_a_broken_audit_layer_never_breaks_the_gate(only_iran, monkeypatch):
    def explode(**kwargs):
        raise RuntimeError("audit is down")

    monkeypatch.setattr("app.services.audit.log_event", explode)
    only_iran.reset_counters()
    assert _client("5.5.5.5").get("/login").status_code == 403


def test_allowed_traffic_is_counted_too(only_iran):
    only_iran.reset_counters()
    _client("2.144.0.1").get("/login")
    assert only_iran.status()["allowed_count"] == 1


def test_reset_counters_clears_the_stats_but_not_the_settings(only_iran):
    only_iran.reset_counters()
    _client("5.5.5.5").get("/login")
    assert only_iran.status()["blocked_count"] == 1
    before = only_iran.status()["enabled"]
    after = only_iran.reset_counters()
    assert after["blocked_count"] == 0
    assert after["last_blocked_ip"] == ""
    assert after["enabled"] == before


def test_the_tester_reports_what_would_happen(only_iran):
    blocked = only_iran.check_ip("8.8.8.8")
    assert blocked["blocked"] is True and blocked["enforcing"] is True
    assert blocked["label"]
    allowed = only_iran.check_ip("2.144.0.1")
    assert allowed["blocked"] is False and allowed["range"]
    off = only_iran.check_ip("not-an-ip")
    assert off["blocked"] is False


# ── the list refresh (the only network call) ────────────────────────────────


def test_the_refresh_rewrites_the_list_from_the_registries(only_iran, monkeypatch, tmp_path):
    target = tmp_path / "iran_ip_ranges.txt"
    monkeypatch.setattr(iran_access, "DATA_PATH", target)
    monkeypatch.setattr(iran_access, "_download", lambda url: _REGISTRY_FIXTURE)
    try:
        status = _run(iran_access.refresh())
        assert target.exists()
        assert status["ranges_ipv4"] >= 3
        assert status["ranges_ipv6"] == 1
        assert status["enforcing"] is True
        assert status["last_refresh"]
        assert status["sources_failed"] == []
        assert "generated:" in target.read_text(encoding="utf-8")
    finally:
        monkeypatch.undo()
        only_iran._load_list(force=True)


def test_a_failed_refresh_reports_instead_of_wiping_the_list(only_iran, monkeypatch, tmp_path):
    target = tmp_path / "iran_ip_ranges.txt"
    target.write_text("2.144.0.0/14\n", encoding="utf-8")
    monkeypatch.setattr(iran_access, "DATA_PATH", target)

    def explode(url):
        raise OSError("no route to host")

    monkeypatch.setattr(iran_access, "_download", explode)
    try:
        with pytest.raises(iran_access.IranAccessError):
            _run(iran_access.refresh())
        # The file is left alone: the filter keeps working from the old list.
        assert target.read_text(encoding="utf-8") == "2.144.0.0/14\n"
    finally:
        monkeypatch.undo()
        only_iran._load_list(force=True)


def test_the_refresh_never_uses_the_machine_proxy():
    import inspect

    source = inspect.getsource(iran_access._download)
    assert "ProxyHandler({})" in source
    assert iran_access.DOWNLOAD_TIMEOUT_SECONDS >= 10


# ── client address derivation ───────────────────────────────────────────────


def test_the_scope_helper_matches_the_request_helper():
    from app.core.net import client_ip, client_ip_from_scope

    scope = {
        "type": "http",
        "path": "/",
        "headers": [(b"x-forwarded-for", b"2.144.0.1")],
        "client": ("127.0.0.1", 5000),
    }
    assert client_ip_from_scope(scope) == "2.144.0.1"

    untrusted = dict(scope, client=("5.5.5.5", 5000))
    assert client_ip_from_scope(untrusted) == "5.5.5.5"

    assert client_ip_from_scope({"type": "http", "headers": [], "client": ("testclient", 1)}) == "unknown"
    assert client_ip_from_scope({"type": "http", "headers": []}) == "unknown"


# ── wiring ──────────────────────────────────────────────────────────────────


def test_the_gate_is_wired_between_the_host_check_and_the_outage_page():
    source = open("app/main.py", encoding="utf-8").read()
    assert "app.add_middleware(_IranOnlyGateMiddleware)" in source
    assert "iran_access.start()" in source
    iran_gate = source.index("app.add_middleware(_IranOnlyGateMiddleware)")
    outage_gate = source.index("app.add_middleware(_OutageGateMiddleware)")
    host = source.index("app.add_middleware(TrustedHostMiddleware")
    registry = source.index("app.add_middleware(\n    _SessionRegistryMiddleware")
    # The Iran-only gate is added first, so the outage gate is the outer one: a
    # link outage is reported before the access policy.  Both run before the host
    # check, the session registry and every database layer.
    assert iran_gate < outage_gate < host
    assert registry < iran_gate


def test_the_settings_card_exposes_the_controls():
    js = open("app/static/js/master-admin.js", encoding="utf-8").read()
    for control in (
        "maIranEnabled",
        "maIranTitle",
        "maIranMessage",
        "maIranHelp",
        "maIranLogBlocked",
        "maIranIp",
        "maIranCheck",
        "maIranCheckResult",
        "maIranSave",
        "maIranRefresh",
        "maIranResetCounters",
        "'/iran-access'",
        "'/iran-access/check'",
        "'/iran-access/refresh'",
        "'/iran-access/counters/reset'",
        "iranAccessCard(",
    ):
        assert control in js, control
    css = open("app/static/css/master-admin.css", encoding="utf-8").read()
    assert ".ma-iran-probe" in css


def test_the_warning_page_is_fully_self_contained():
    body = open("app/templates/vpn-warning.html", encoding="utf-8").read()
    assert 'dir="rtl"' in body and 'lang="fa"' in body
    assert "noindex" in body
    assert "/static/" not in body
    assert "@import" not in body


def test_the_master_admin_endpoints_are_protected():
    paths = (
        "/master-admin/api/iran-access",
        "/master-admin/api/iran-access/check",
        "/master-admin/api/iran-access/refresh",
        "/master-admin/api/iran-access/counters/reset",
    )
    for path in paths:
        assert _client().get(path).status_code in (401, 403, 405), path
        assert _client().post(path, json={}).status_code in (401, 403), path


def test_the_filter_is_documented_as_a_risk():
    register = open("docs/security/RESIDUAL_RISK_REGISTER.md", encoding="utf-8").read()
    assert "iran" in register.lower()
