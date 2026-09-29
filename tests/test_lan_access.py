"""LAN access mode — the toggled, plain-HTTP fallback listener.

The system normally serves one canonical HTTPS URL (Cloudflare → tunnel →
``127.0.0.1:5000``).  For an internet outage an operator can open an optional
second listener on the machine's LAN address; this module pins the parts of that
mode that must never drift:

* the LAN address is derived (never hard-coded) and refuses anything that is not
  a private IPv4 address;
* the relay is byte transparent (SSE and the call-display WebSocket) and it
  *proves* the client address instead of trusting forwarding headers;
* the relay never forwards a request head it could not sanitise, and it never
  keeps a connection alive (a keep-alive request would arrive unsanitised);
* the ``Host`` allow-list only grows while the listener runs;
* the ``Secure`` cookie flag is dropped for LAN requests only — that is the
  single reason a LAN client could otherwise never log in over plain HTTP.
"""
from __future__ import annotations

import asyncio
import socket
from pathlib import Path

import pytest
from starlette.testclient import TestClient

from app.services import lan_access


# ── address selection ───────────────────────────────────────────────────────


def test_only_private_ipv4_addresses_can_be_bound():
    assert lan_access.usable_lan_address(["192.168.3.69"]) == "192.168.3.69"
    assert lan_access.usable_lan_address(["10.1.2.3"]) == "10.1.2.3"
    assert lan_access.usable_lan_address(["172.16.5.5"]) == "172.16.5.5"


def test_unsafe_candidate_addresses_are_refused():
    for candidate in (
        "127.0.0.1",  # loopback: the application already binds it
        "169.254.10.10",  # link-local
        "224.0.0.1",  # multicast
        "0.0.0.0",  # everything
        "8.8.8.8",  # public address
        "::1",  # IPv6 loopback
        "fe80::1",  # IPv6 link-local
        "not-an-address",
        "",
    ):
        assert lan_access.usable_lan_address([candidate]) == "", candidate


def test_the_first_usable_candidate_wins():
    assert lan_access.usable_lan_address(["", "8.8.8.8", "192.168.3.69", "10.0.0.9"]) == "192.168.3.69"


def test_address_is_detected_without_any_hard_coded_laboratory_ip():
    """The address comes from the machine, not from the source code."""
    source = open("app/services/lan_access.py", encoding="utf-8").read()
    assert "192.168." not in source
    assert lan_access.DEFAULT_PORT == 5000
    # The probe socket selects a route; it must not contact anything.
    assert "192.0.2.1" in source  # TEST-NET-1, never routable


def test_explicit_address_override_is_validated(monkeypatch):
    monkeypatch.setenv("HASTAMA_LAN_BIND_ADDRESS", "192.168.3.69")
    assert lan_access.lan_address() == "192.168.3.69"

    # A malformed override must be refused, not silently replaced by detection.
    monkeypatch.setenv("HASTAMA_LAN_BIND_ADDRESS", "192.168.3.69:5000")
    assert lan_access.lan_address() == ""
    monkeypatch.setenv("HASTAMA_LAN_BIND_ADDRESS", "0.0.0.0")
    assert lan_access.lan_address() == ""


def test_port_overrides_are_validated(monkeypatch):
    monkeypatch.delenv("HASTAMA_LAN_PORT", raising=False)
    assert lan_access.configured_port() == 5000
    monkeypatch.setenv("HASTAMA_LAN_PORT", "5099")
    assert lan_access.configured_port() == 5099
    monkeypatch.setenv("HASTAMA_LAN_PORT", "70000")
    assert lan_access.configured_port() == 5000


# ── request head sanitising ─────────────────────────────────────────────────


def _head(*lines: bytes) -> bytes:
    return b"\r\n".join(lines) + b"\r\n\r\n"


def _rewritten(head: bytes, peer: str = "192.168.3.50") -> bytes:
    result = lan_access.rewrite_request_head(head, peer)
    assert result is not None
    return result


def test_spoofed_forwarding_headers_are_replaced_by_the_real_peer():
    rewritten = _rewritten(
        _head(
            b"GET /login HTTP/1.1",
            b"Host: 192.168.3.69:5000",
            b"X-Forwarded-For: 10.9.9.9",
            b"X-Forwarded-Proto: https",
            b"X-Real-IP: 10.9.9.8",
            b"Forwarded: for=10.9.9.7",
            b"User-Agent: curl/8",
        )
    )
    lowered = rewritten.lower()
    assert b"x-forwarded-for: 192.168.3.50" in lowered
    assert b"10.9.9.9" not in rewritten
    assert b"10.9.9.8" not in rewritten
    assert b"10.9.9.7" not in rewritten
    # Never let a client claim HTTPS: it would decide the cookie Secure flag.
    assert b"x-forwarded-proto" not in lowered
    assert b"forwarded:" not in lowered
    assert b"user-agent: curl/8" in lowered
    assert b"host: 192.168.3.69:5000" in lowered


def test_every_request_asks_the_origin_to_close_the_connection():
    """One request per connection: a keep-alive request would skip sanitising."""
    rewritten = _rewritten(
        _head(
            b"POST /login_user HTTP/1.1",
            b"Host: 192.168.3.69:5000",
            b"Connection: keep-alive",
            b"Proxy-Connection: keep-alive",
            b"Content-Length: 3",
        )
    )
    lowered = rewritten.lower()
    assert b"connection: close" in lowered
    assert b"keep-alive" not in lowered
    assert b"content-length: 3" in lowered  # the body is untouched


def test_websocket_upgrades_keep_their_connection_semantics():
    rewritten = _rewritten(
        _head(
            b"GET /api/ws/call-display HTTP/1.1",
            b"Host: 192.168.3.69:5000",
            b"Connection: Upgrade",
            b"Upgrade: websocket",
            b"Sec-WebSocket-Key: abc",
        )
    )
    lowered = rewritten.lower()
    assert b"connection: upgrade" in lowered
    assert b"upgrade: websocket" in lowered
    assert b"connection: close" not in lowered
    assert b"x-forwarded-for: 192.168.3.50" in lowered


def test_malformed_heads_are_refused_instead_of_forwarded():
    for head in (
        b"GET\r\nHost: x\r\n\r\n",  # no target / version
        b"GET / HTTP/1.1\r\nbroken-header\r\n\r\n",  # no colon
        b"GET / HTTP/1.1\r\nHost: x\r\n",  # not terminated
        b"GET / HTTP/9\r\nHost: x\r\n\r\n",  # unknown protocol
        b"\x16\x03\x01\x02\x00\x01\x00\x01\xfc\x03\x03" * 8,  # a TLS hello
    ):
        assert not lan_access.rewrite_request_head(head, "192.168.3.50"), head


def test_a_folded_forwarding_header_is_dropped_with_its_parent():
    rewritten = _rewritten(
        _head(
            b"GET / HTTP/1.1",
            b"Host: 192.168.3.69:5000",
            b"X-Forwarded-For: 10.9.9.9",
            b"  10.9.9.10",
        )
    )
    assert b"10.9.9.9" not in rewritten
    assert b"10.9.9.10" not in rewritten


def test_header_count_is_bounded():
    head = _head(b"GET / HTTP/1.1", *[b"X-Pad: 1"] * (lan_access.MAX_HEADER_LINES + 5))
    assert lan_access.rewrite_request_head(head, "192.168.3.50") is None


# ── the relay, end to end (loopback only) ───────────────────────────────────


def _run(coro):
    return asyncio.new_event_loop().run_until_complete(coro)


class _Upstream:
    """Minimal HTTP/1.1 origin that records the head it was given."""

    def __init__(self) -> None:
        self.heads: list[bytes] = []
        self.bodies: list[bytes] = []
        self._server: asyncio.AbstractServer | None = None

    async def start(self, respond: bytes = b"HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok") -> int:
        async def handler(reader: asyncio.StreamReader, writer: asyncio.StreamWriter) -> None:
            try:
                head = await reader.readuntil(b"\r\n\r\n")
            except (asyncio.IncompleteReadError, ConnectionResetError):
                return
            self.heads.append(head)
            length = 0
            for line in head.lower().split(b"\r\n"):
                if line.startswith(b"content-length:"):
                    length = int(line.split(b":", 1)[1].strip() or 0)
            if length:
                self.bodies.append(await reader.readexactly(length))
            writer.write(respond)
            await writer.drain()
            writer.close()

        self._server = await asyncio.start_server(handler, "127.0.0.1", 0)
        return self._server.sockets[0].getsockname()[1]

    async def stop(self) -> None:
        if self._server is not None:
            self._server.close()
            await self._server.wait_closed()
            self._server = None


def _relay_for(upstream_port: int, **kwargs) -> "lan_access.LanAccessService":
    return lan_access.LanAccessService(
        bind_address="127.0.0.1", port=0, upstream="127.0.0.1", upstream_port=upstream_port, **kwargs
    )


def test_the_relay_forwards_a_sanitised_head_and_the_body():
    async def scenario():
        upstream = _Upstream()
        upstream_port = await upstream.start()
        service = _relay_for(upstream_port)
        try:
            await service.start()
            port = service.bound_port
            assert port not in (0, None)

            reader, writer = await asyncio.open_connection("127.0.0.1", port)
            # The request and its body are written in one go: the relay must put
            # the head first and the body directly behind it.
            writer.write(
                b"POST /login_user HTTP/1.1\r\n"
                b"Host: 127.0.0.1:5000\r\n"
                b"Content-Length: 5\r\n"
                b"X-Forwarded-For: 10.9.9.9\r\n"
                b"\r\nhello"
            )
            await writer.drain()
            response = await asyncio.wait_for(reader.read(4096), timeout=5)
            writer.close()

            assert response.endswith(b"ok")
            assert len(upstream.heads) == 1
            head = upstream.heads[0].lower()
            assert b"x-forwarded-for: 127.0.0.1" in head
            assert b"10.9.9.9" not in head
            assert b"connection: close" in head
            assert upstream.bodies == [b"hello"]
        finally:
            await service.stop()
            await upstream.stop()

    _run(scenario())


def test_the_relay_answers_502_when_the_application_is_down():
    async def scenario():
        # Bind a port and close it again: nothing is listening there now.
        probe = socket.socket()
        probe.bind(("127.0.0.1", 0))
        dead_port = probe.getsockname()[1]
        probe.close()

        service = _relay_for(dead_port)
        try:
            await service.start()
            reader, writer = await asyncio.open_connection("127.0.0.1", service.bound_port)
            writer.write(b"GET /health HTTP/1.1\r\nHost: 127.0.0.1:5000\r\n\r\n")
            await writer.drain()
            response = await asyncio.wait_for(reader.read(4096), timeout=5)
            writer.close()
            assert response.startswith(b"HTTP/1.1 502")
            assert service.status()["last_error"]
        finally:
            await service.stop()

    _run(scenario())


def test_the_relay_refuses_a_head_it_cannot_sanitise():
    async def scenario():
        upstream = _Upstream()
        upstream_port = await upstream.start()

        async def _exchange(payload: bytes) -> bytes:
            service = _relay_for(upstream_port, head_timeout=0.3)
            try:
                await service.start()
                reader, writer = await asyncio.open_connection("127.0.0.1", service.bound_port)
                writer.write(payload)
                await writer.drain()
                data = await asyncio.wait_for(reader.read(4096), timeout=5)
                writer.close()
                return data
            finally:
                await service.stop()

        # A complete but malformed request is refused at once …
        assert (await _exchange(b"GET\r\nHost: x\r\n\r\n")).startswith(b"HTTP/1.1 400")
        # … and a TLS handshake (no HTTP head at all) is refused on the timeout,
        # so it can never hold a connection slot open.
        assert (await _exchange(b"\x16\x03\x01\x02\x00TLS-hello")).startswith(b"HTTP/1.1 400")
        assert upstream.heads == []
        await upstream.stop()

    _run(scenario())


def test_the_relay_bounds_concurrent_connections():
    async def scenario():
        service = _relay_for(1, max_connections=0)
        try:
            await service.start()
            reader, writer = await asyncio.open_connection("127.0.0.1", service.bound_port)
            writer.write(b"GET /health HTTP/1.1\r\nHost: 127.0.0.1:5000\r\n\r\n")
            await writer.drain()
            response = await asyncio.wait_for(reader.read(1024), timeout=5)
            writer.close()
            assert response.startswith(b"HTTP/1.1 503")
        finally:
            await service.stop()

    _run(scenario())


# ── status / lifecycle ──────────────────────────────────────────────────────


def test_status_describes_the_listener_while_it_runs():
    async def scenario():
        upstream = _Upstream()
        upstream_port = await upstream.start()
        service = _relay_for(upstream_port)
        try:
            before = service.status()
            assert before["running"] is False and before["enabled"] is False
            assert before["url"] == ""
            # The address a workstation would use is known before enabling it.
            assert before["detected_address"] == "127.0.0.1"
            assert before["expected_url"].startswith("http://127.0.0.1:")

            await service.start()
            running = service.status()
            assert running["running"] is True and running["enabled"] is True
            assert running["address"] == "127.0.0.1"
            assert running["url"] == f"http://127.0.0.1:{service.bound_port}"
            assert running["upstream"] == f"127.0.0.1:{upstream_port}"
            assert running["started_at"] and running["last_error"] == ""
        finally:
            await service.stop()
            await upstream.stop()

        after = service.status()
        assert after["running"] is False and after["address"] == "" and after["url"] == ""
        assert service.running is False

    _run(scenario())


def test_start_failure_is_reported_instead_of_raising_from_apply(monkeypatch):
    async def scenario():
        monkeypatch.setattr(lan_access, "lan_address", lambda: "")
        service = lan_access.LanAccessService()
        status = await service.apply(True)  # must not raise
        assert status["running"] is False
        assert status["last_error"]
        assert service.enabled is True  # the intent is remembered for the operator

    _run(scenario())


# ── persisted flag ──────────────────────────────────────────────────────────


def test_the_flag_is_read_and_written_under_one_key(monkeypatch):
    import app.core.database as database

    calls: list[tuple] = []

    class _Cursor:
        rowcount = 1

        def execute(self, sql, params=()):
            calls.append((sql, params))
            return self

        def fetchone(self):
            return ("1",)

    class _Conn:
        def cursor(self):
            return _Cursor()

        def commit(self):
            pass

        def close(self):
            pass

    monkeypatch.setattr(database, "connect", lambda *a, **k: _Conn())
    assert lan_access.read_enabled_flag() is True

    calls.clear()
    assert lan_access.write_enabled_flag(True, actor="admin") is True
    assert calls and "system_config" in calls[0][0]
    assert lan_access.ENABLED_KEY in calls[0][1]


def test_a_missing_or_broken_flag_row_means_disabled(monkeypatch):
    import app.core.database as database

    class _Cursor:
        def execute(self, *a, **k):
            return self

        def fetchone(self):
            return None

    class _Conn:
        def cursor(self):
            return _Cursor()

        def close(self):
            pass

    monkeypatch.setattr(database, "connect", lambda *a, **k: _Conn())
    assert lan_access.read_enabled_flag() is False

    def _explode(*_a, **_k):
        raise RuntimeError("no database")

    monkeypatch.setattr(database, "connect", _explode)
    assert lan_access.read_enabled_flag() is False  # never raises into startup
    assert lan_access.write_enabled_flag(True) is False


def test_the_saved_choice_is_applied_at_startup(monkeypatch):
    async def scenario():
        upstream = _Upstream()
        upstream_port = await upstream.start()
        service = _relay_for(upstream_port)
        monkeypatch.setattr(lan_access, "_state", service)
        monkeypatch.setattr(lan_access, "read_enabled_flag", lambda: True)
        try:
            await lan_access.start_from_config()
            assert service.running is True
            assert service.status()["running"] is True
        finally:
            await lan_access.stop()
            await upstream.stop()
        assert service.running is False

    _run(scenario())


def test_a_disabled_flag_opens_nothing_at_startup(monkeypatch):
    async def scenario():
        service = lan_access.LanAccessService(bind_address="127.0.0.1", port=0)
        monkeypatch.setattr(lan_access, "_state", service)
        monkeypatch.setattr(lan_access, "read_enabled_flag", lambda: False)
        await lan_access.start_from_config()
        assert service.running is False

    _run(scenario())


# ── application integration ─────────────────────────────────────────────────


def test_request_is_lan_http_only_for_the_running_listener():
    async def scenario():
        upstream = _Upstream()
        upstream_port = await upstream.start()
        service = _relay_for(upstream_port)
        scope = {
            "type": "http",
            "scheme": "http",
            "headers": [(b"host", b"127.0.0.1:5000")],
        }
        try:
            assert lan_access.request_is_lan_http(scope) is False  # not running yet
            await service.start()
            monkeypatch_state(service)
            assert lan_access.request_is_lan_http(scope) is True
            assert lan_access.request_is_lan_http({**scope, "headers": [(b"host", b"hastama.ir")]}) is False
            assert lan_access.request_is_lan_http({**scope, "scheme": "https"}) is False
            assert lan_access.request_is_lan_http(
                {**scope, "headers": scope["headers"] + [(b"x-forwarded-proto", b"https")]}
            ) is False
            # Only plain HTTP can carry a cookie at all.
            assert lan_access.request_is_lan_http({"type": "websocket", "headers": scope["headers"]}) is False
            # A different host on the same listener is not the LAN origin either.
            assert lan_access.request_is_lan_http({"type": "http", "scheme": "http", "headers": []}) is False
        finally:
            await service.stop()
            await upstream.stop()

    original = lan_access._state
    monkeypatch_state = lambda s: setattr(lan_access, "_state", s)  # noqa: E731
    try:
        _run(scenario())
    finally:
        lan_access._state = original


def test_the_secure_cookie_flag_is_dropped_for_lan_requests_only(monkeypatch):
    """The one reason a LAN client could otherwise never log in over HTTP."""
    from app import main

    async def scenario():
        service = lan_access.LanAccessService(bind_address="127.0.0.1", port=0)
        await service.start()
        monkeypatch.setattr(lan_access, "_state", service)
        try:
            sent: list[dict] = []

            async def inner(scope, receive, send):
                await send(
                    {
                        "type": "http.response.start",
                        "status": 200,
                        "headers": [
                            (b"set-cookie", b"session=abc; Path=/; HttpOnly; SameSite=Lax; Secure"),
                            (b"content-type", b"text/plain"),
                        ],
                    }
                )
                await send({"type": "http.response.body", "body": b""})

            middleware = main._LanCookieMiddleware(inner)

            async def drive(host: bytes, scheme: str = "http"):
                sent.clear()
                scope = {
                    "type": "http",
                    "method": "GET",
                    "path": "/login",
                    "scheme": scheme,
                    "headers": [(b"host", host)],
                }

                async def receive():
                    return {"type": "http.request", "body": b"", "more_body": False}

                await middleware(scope, receive, lambda message: _collect(sent, message))

            await drive(b"127.0.0.1:5000")
            cookie = dict(sent[0]["headers"])[b"set-cookie"]
            assert b"secure" not in cookie.lower()
            assert b"httponly" in cookie.lower() and b"samesite=lax" in cookie.lower()

            await drive(b"hastama.ir")
            cookie = dict(sent[0]["headers"])[b"set-cookie"]
            assert cookie.endswith(b"; Secure")

            await drive(b"127.0.0.1:5000", scheme="https")
            cookie = dict(sent[0]["headers"])[b"set-cookie"]
            assert cookie.endswith(b"; Secure")
        finally:
            await service.stop()

    async def _collect(sent, message):
        sent.append(message)

    _run(scenario())


def test_the_lan_host_is_allowed_only_while_lan_access_is_enabled():
    from app import main

    client = TestClient(main.app, base_url="http://192.168.3.69:5000", raise_server_exceptions=False)
    try:
        assert client.get("/health").status_code == 400  # nothing is listening yet
        main.set_lan_allowed_hosts(["192.168.3.69"])
        assert client.get("/health").status_code == 200
        # No wildcard ever enters the allow-list.
        assert "*" not in main._extra_allowed_hosts
        main.set_lan_allowed_hosts([])
        assert client.get("/health").status_code == 400
    finally:
        main.set_lan_allowed_hosts([])


def test_the_canonical_hosts_are_unaffected_by_the_lan_switch():
    from app import main

    name, port = "127.0.0.1", 5000
    client = TestClient(main.app, base_url=f"http://{name}:{port}", raise_server_exceptions=False)
    assert client.get("/health").status_code == 200
    assert main.allowed_hosts() == [
        "testserver",
        "localhost",
        "127.0.0.1",
        "hastama.ir",
        "www.hastama.ir",
    ]


def test_lan_endpoints_require_a_master_admin_session():
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    assert client.get("/master-admin/api/lan-access").status_code == 401
    # State changing calls are answered by the CSRF layer first (403), and never
    # reach the handler without a session.
    assert client.post("/master-admin/api/lan-access", json={"enabled": True}).status_code == 403
    assert client.post("/master-admin/api/lan-access/selftest").status_code == 403


# ── master-admin endpoints (authorised path, no browser needed) ─────────────


class _FakeRequest:
    """Just enough of a Starlette request for the handler body."""

    def __init__(self, body=None) -> None:
        self._body = body or {}
        self.session = {"username": "admin", "is_master_admin": True}
        self.client = type("Client", (), {"host": "127.0.0.1"})()
        self.headers: dict = {}
        self.url = type("URL", (), {"path": "/master-admin/api/lan-access"})()

    async def json(self):
        return self._body


def test_the_toggle_endpoint_starts_and_stops_the_listener(monkeypatch):
    import json as _json

    from app.api.routes import master_admin

    audited: list[dict] = []
    monkeypatch.setattr(
        master_admin, "log_admin_action", lambda **kwargs: audited.append(kwargs) or "id"
    )

    async def scenario():
        service = lan_access.LanAccessService(bind_address="127.0.0.1", port=0)
        monkeypatch.setattr(lan_access, "_state", service)
        monkeypatch.setattr(lan_access, "write_enabled_flag", lambda enabled, actor="": True)

        started = await master_admin.set_lan_access(_FakeRequest({"enabled": True}))
        payload = _json.loads(started.body)
        try:
            assert started.status_code == 200
            assert payload["success"] is True
            assert payload["data"]["running"] is True
            assert payload["data"]["upstream"] == "127.0.0.1:5000"
            assert audited[-1]["action"] == "enable_lan_access"
            assert audited[-1]["admin_username"] == "admin"
        finally:
            stopped = await master_admin.set_lan_access(_FakeRequest({"enabled": False}))

        assert _json.loads(stopped.body)["data"]["running"] is False
        assert audited[-1]["action"] == "disable_lan_access"

    _run(scenario())


def test_the_toggle_endpoint_reports_a_listener_that_could_not_open(monkeypatch):
    import json as _json

    from app.api.routes import master_admin

    monkeypatch.setattr(master_admin, "log_admin_action", lambda **kwargs: "id")

    async def scenario():
        # No address at all: the listener cannot be opened.
        service = lan_access.LanAccessService(bind_address="")
        monkeypatch.setattr(lan_access, "_state", service)
        monkeypatch.setattr(lan_access, "write_enabled_flag", lambda enabled, actor="": False)

        response = await master_admin.set_lan_access(_FakeRequest({"enabled": True}))
        payload = _json.loads(response.body)
        assert response.status_code == 409
        assert payload["success"] is False
        assert payload["message"]
        assert payload["data"]["running"] is False
        assert payload["data"]["saved"] is False

    _run(scenario())


def test_the_status_endpoint_describes_the_mode(monkeypatch):
    import json as _json

    from app.api.routes import master_admin

    async def scenario():
        service = lan_access.LanAccessService(bind_address="192.168.3.69")
        monkeypatch.setattr(lan_access, "_state", service)
        response = await master_admin.get_lan_access(_FakeRequest())
        data = _json.loads(response.body)["data"]
        assert data["running"] is False
        assert data["expected_url"] == "http://192.168.3.69:5000"
        assert data["address"] == ""

    _run(scenario())


def test_the_flag_cannot_be_persisted_without_applying_it():
    """`POST /config` must not be able to save the flag on its own.

    If it could, the row would say "on" while nothing listens — the listener has
    to be started by the endpoint that also reports its state.
    """
    source = open("app/api/routes/master_admin.py", encoding="utf-8").read()
    allowed = source[source.index("allowed_keys = {"): source.index("if key not in allowed_keys")]
    assert "lan_access_enabled" not in allowed
    assert "lan_access" in source and "set_lan_enabled" not in source


def test_the_listener_is_wired_into_startup_and_shutdown():
    source = open("app/main.py", encoding="utf-8").read()
    assert "await lan_access.start_from_config()" in source
    assert "await lan_access.stop()" in source
    # The session cookie keeps its forced Secure flag; only the LAN origin drops it.
    assert "https_only=True" in source
    assert "app.add_middleware(_LanCookieMiddleware)" in source


def test_the_selftest_reports_a_stopped_listener_instead_of_failing(monkeypatch):
    async def scenario():
        service = lan_access.LanAccessService(bind_address="127.0.0.1", port=0)
        monkeypatch.setattr(lan_access, "_state", service)
        result = await lan_access.self_test()
        assert result["ok"] is False
        assert result["message"]

    _run(scenario())


@pytest.mark.parametrize(
    "value,expected",
    [("1", True), ("true", True), ("On", True), (True, True), ("0", False), (False, False), (None, False), ("", False)],
)
def test_flag_values_are_interpreted_consistently(value, expected):
    assert lan_access.truthy(value) is expected


# ── operator tooling, UI and documentation ──────────────────────────────────


def test_the_firewall_helper_is_scoped_and_exactly_reversible():
    script = open("scripts/lan_access_firewall.ps1", encoding="utf-8").read()
    script.encode("ascii")  # PS 5.1 reads a BOM-less .ps1 as ANSI: keep it ASCII

    # Scope: inbound TCP 5000 from the local subnet only.
    assert "-Direction Inbound" in script and "-Action Allow" in script
    assert "-RemoteAddress LocalSubnet" in script
    assert "-Profile Domain,Private" in script
    assert "$AppPort       = 5000" in script
    # Only Hastama rules that actually cover port 5000 are ever considered.
    assert "$_.DisplayName -like '*astama*'" in script
    assert "@($ports) -contains \"$AppPort\"" in script
    # Nothing else on this host is touched, and no subnet is hard-coded.
    for forbidden in ("1433", "3389", "445", "192.168.", "0.0.0.0"):
        assert forbidden not in script, forbidden
    # Exact undo: the rules the enable step disabled are remembered, then re-enabled.
    assert "Set-NetFirewallRule -Name $rule.Name -Enabled True" in script
    assert "lan-access-firewall.json" in script
    assert "ValidateSet('enable', 'disable', 'status')" in script


def _lan_firewall_wrappers() -> dict:
    """The two operator wrappers, found by what they do rather than by file name.

    The files under ``scripts/`` are named in Persian, so hard-coding a name here
    would only pin a spelling.  Each wrapper is identified by the action it hands
    to the helper, which is what actually has to be right.
    """
    wrappers = {}
    for action in ("enable", "disable"):
        matches = [
            path
            for path in sorted(Path("scripts").glob("*.bat"))
            if ("-Action " + action) in path.read_text(encoding="utf-8")
        ]
        assert len(matches) == 1, f"expected exactly one {action} wrapper, found {matches}"
        wrappers[action] = matches[0]
    return wrappers


def test_the_firewall_wrappers_call_the_helper():
    for action, path in _lan_firewall_wrappers().items():
        text = path.read_text(encoding="utf-8")
        assert text.startswith("@echo off"), path.name
        assert "lan_access_firewall.ps1" in text, path.name
        assert "-Action " + action in text, path.name


def test_the_settings_card_drives_the_toggle_and_the_self_test():
    js = open("app/static/js/master-admin.js", encoding="utf-8").read()
    assert "maCfgLanAccess" in js
    assert "'/lan-access'" in js and "'/lan-access/selftest'" in js
    assert "lanAccessCard" in js
    # A failed switch must never be shown as applied …
    assert "this.checked = !want" in js
    # … and the live state is re-read afterwards (address, connections, error).
    assert "loadSystemSettings();  // re-read the live state" in js
    assert "lan_access_firewall.ps1" in js  # points the operator at the one-time step


def test_the_settings_card_styles_exist():
    css = open("app/static/css/master-admin.css", encoding="utf-8").read()
    for selector in (".ma-lan-card", ".ma-lan-state--on", ".ma-lan-status__value", ".ma-lan-warning"):
        assert selector in css, selector


def test_the_operating_documents_describe_the_mode():
    network = open("docs/network/UNIFIED_URL_ARCHITECTURE.md", encoding="utf-8").read()
    deployment = open("docs/HASTAMA_PRODUCTION_DEPLOYMENT.md", encoding="utf-8").read()
    register = open("docs/security/RESIDUAL_RISK_REGISTER.md", encoding="utf-8").read()

    assert "LAN access mode" in network
    assert "RR-29" in network
    assert "lan_access_firewall.ps1" in network and "lan_access_firewall.ps1" in deployment
    # The document must name the wrappers as they really are on disk.
    for wrapper in _lan_firewall_wrappers().values():
        assert wrapper.name in deployment, wrapper.name
        assert wrapper.name in network, wrapper.name
    assert "LAN access mode (internet outage fallback)" in deployment
    # The explicit decisions are recorded, not implied.
    assert "RR-29" in register
    assert "lan_access_enabled" in register
    assert "no automatic time-out" in register.lower() or "no automatic time-out was chosen" in register
