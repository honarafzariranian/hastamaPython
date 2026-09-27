"""One canonical URL: ``https://hastama.ir`` for LAN and Internet users alike.

The supported production path is::

    browser (LAN or Internet)
        -> https://hastama.ir
        -> Cloudflare (TLS, WAF, redirects)
        -> Cloudflare Tunnel (cloudflared Windows service)
        -> 127.0.0.1:5000 (uvicorn, loopback only)
        -> FastAPI

These tests pin the parts of that contract that live in the repository:

* the ``Host`` allow-list (no host-header poisoning of absolute URLs);
* ``HEAD`` support so uptime monitors can probe the public URL (RR-22);
* the WebSocket handshake rules that the tunnel must carry unchanged;
* "no LAN URL anywhere": private addresses / ``localhost`` bindings must not
  leak into the application, the shipped tools or the operator scripts.

Live probes against the public URL (DNS, TLS, redirects, WebSocket 101) are
recorded in ``docs/network/UNIFIED_URL_ARCHITECTURE.md`` — they need the real
Cloudflare edge and therefore cannot run inside the unit-test suite.
"""
from __future__ import annotations

import asyncio
import json
from pathlib import Path

import pytest
from starlette.testclient import TestClient

ROOT = Path(__file__).resolve().parents[1]


def _read(*parts: str) -> str:
    return ROOT.joinpath(*parts).read_text(encoding="utf-8")


# ── Host allow-list ─────────────────────────────────────────────────────────


def test_default_allowed_hosts_is_the_canonical_name(monkeypatch):
    from app import main

    monkeypatch.delenv("HASTAMA_ALLOWED_HOSTS", raising=False)
    hosts = main.allowed_hosts()
    assert "hastama.ir" in hosts
    assert "www.hastama.ir" in hosts
    # loopback names are for local health checks on the server itself
    assert "127.0.0.1" in hosts and "localhost" in hosts
    # no wildcard: a wildcard would re-open host-header poisoning
    assert "*" not in hosts


def test_allowed_hosts_can_be_overridden_for_staging(monkeypatch):
    from app import main

    monkeypatch.setenv("HASTAMA_ALLOWED_HOSTS", "stage.example, hastama.ir ,")
    assert main.allowed_hosts() == ["stage.example", "hastama.ir"]


def test_public_hostname_is_served_and_unknown_hosts_are_rejected():
    from app.main import app

    good = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    assert good.get("/health").status_code == 200
    assert good.get("/login").status_code == 200

    for bad_host in ("https://evil.example", "https://hastama.ir.evil.example"):
        bad = TestClient(app, base_url=bad_host, raise_server_exceptions=False)
        assert bad.get("/health").status_code == 400, bad_host
        assert bad.get("/login").status_code == 400, bad_host


def test_www_is_accepted_so_a_missed_edge_redirect_still_serves():
    from app.main import app

    client = TestClient(app, base_url="https://www.hastama.ir", raise_server_exceptions=False)
    assert client.get("/health").status_code == 200


def test_canonical_redirect_is_host_relative():
    """``/`` must not hard-code a scheme or host, so the public HTTPS host is kept."""
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", follow_redirects=False)
    response = client.get("/")
    assert response.status_code == 301
    assert response.headers["location"] == "/login"


# ── HEAD support (RR-22) ────────────────────────────────────────────────────


@pytest.mark.parametrize(
    "path,expected",
    [("/health", 200), ("/login", 200), ("/", 301), ("/robots.txt", 200), ("/static/favicon.ico", 200)],
)
def test_head_requests_are_answered_like_get(path, expected):
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", follow_redirects=False)
    response = client.head(path)
    assert response.status_code == expected, path
    assert response.content == b""  # a HEAD response never carries a body


def test_get_responses_are_unchanged_by_the_head_rewrite():
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", follow_redirects=False)
    assert client.get("/health").json() == {"status": "ok"}
    assert client.head("/health").status_code == 200


# ── WebSocket handshake (carried by the tunnel) ─────────────────────────────


def _ws_scope(host: bytes = b"hastama.ir", origin: bytes | None = b"https://hastama.ir"):
    headers = [(b"host", host)]
    if origin is not None:
        headers.append((b"origin", origin))
    return {
        "type": "websocket",
        "asgi": {"version": "3.0", "spec_version": "2.3"},
        "http_version": "1.1",
        "scheme": "wss",
        "path": "/api/ws/call-display",
        "raw_path": b"/api/ws/call-display",
        "query_string": b"",
        "root_path": "",
        "headers": headers,
        "client": ("127.0.0.1", 51234),
        "server": ("127.0.0.1", 5000),
        "subprotocols": [],
        "state": {},
    }


def _drive_ws(scope):
    from app.main import app

    sent: list[dict] = []
    inbox = [{"type": "websocket.connect"}, {"type": "websocket.receive", "text": "ping"}]

    async def receive():
        return inbox.pop(0) if inbox else {"type": "websocket.disconnect", "code": 1000}

    async def send(message):
        sent.append(message)

    asyncio.new_event_loop().run_until_complete(app(scope, receive, send))
    return sent


def test_same_origin_websocket_is_accepted_on_the_canonical_host():
    sent = _drive_ws(_ws_scope())
    assert sent[0]["type"] == "websocket.accept"
    assert any(m.get("text") == json.dumps({"type": "pong"}) for m in sent)


def test_cross_site_websocket_handshake_is_refused():
    sent = _drive_ws(_ws_scope(origin=b"https://evil.example"))
    assert sent[0]["type"] == "websocket.close"
    assert sent[0]["code"] == 1008


def test_websocket_on_a_foreign_host_is_refused_before_the_app():
    sent = _drive_ws(_ws_scope(host=b"evil.example"))
    assert sent[0]["type"] == "websocket.http.response.start"
    assert sent[0]["status"] == 400


# ── "No LAN URL, one canonical name" ────────────────────────────────────────


def _sources(*patterns: str, skip_vendor: bool = False) -> list[Path]:
    files: list[Path] = []
    for pattern in patterns:
        files.extend(ROOT.glob(pattern))
    blocked = {"__pycache__"} | ({"vendor"} if skip_vendor else set())
    return [p for p in files if not (blocked & set(p.parts))]


def test_shipped_pages_never_point_users_at_a_private_address():
    """Templates, JS and CSS are what users load: no LAN URL may appear there."""
    offenders = []
    for path in _sources("app/**/*.html", "app/**/*.js", "app/**/*.css", skip_vendor=True):
        text = path.read_text(encoding="utf-8", errors="ignore")
        for needle in ("192.168.3.69", "localhost", "127.0.0.1", "http://hastama.ir"):
            if needle in text:
                offenders.append(f"{path.relative_to(ROOT)}: {needle}")
    assert offenders == [], offenders


def test_python_code_never_hard_codes_the_laboratory_address():
    """A loopback bind is correct; the LAN address and an ``http://`` canonical
    link are not."""
    offenders = []
    for path in _sources("app/**/*.py", "tools/*.py"):
        text = path.read_text(encoding="utf-8", errors="ignore")
        for needle in ("192.168.3.69", "http://hastama.ir"):
            if needle in text:
                offenders.append(f"{path.relative_to(ROOT)}: {needle}")
    assert offenders == [], offenders


def test_frontend_derives_websocket_transport_from_the_origin():
    for name in ("call-display.js", "call-system-standalone.js"):
        text = _read("app", "static", "js", name)
        assert "location.protocol === 'https:' ? 'wss:' : 'ws:'" in text, name
        assert "location.host" in text, name
        assert "ws://" not in text.replace("wss:", ""), name


def test_bridge_agent_uses_the_public_url():
    config = json.loads(_read("tools", "bridge_config.json"))
    assert config["hastama_url"] == "https://hastama.ir"
    text = _read("tools", "bridge_agent.py")
    assert '"hastama_url": "https://hastama.ir"' in text
    assert "192.168.3.69" not in text


def test_autostart_script_binds_loopback_and_states_proxy_trust():
    bat = _read("scripts", "run_server.bat")
    assert "--host 127.0.0.1 --port 5000" in bat
    assert "--proxy-headers" in bat
    assert "--forwarded-allow-ips 127.0.0.1" in bat
    assert "0.0.0.0" not in bat


def test_operator_scripts_point_users_at_the_canonical_url_only():
    start = _read("scripts", "start_server.bat")
    assert "https://hastama.ir" in start
    assert "open http://127.0.0.1:5000" not in start


def test_host_validation_and_head_support_stay_installed():
    source = _read("app", "main.py")
    assert "TrustedHostMiddleware" in source
    assert "app.add_middleware(TrustedHostMiddleware, allowed_hosts=allowed_hosts())" in source
    assert "_HeadMethodMiddleware" in source
    assert 'os.getenv("HASTAMA_ALLOWED_HOSTS"' in source
