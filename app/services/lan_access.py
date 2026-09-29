"""Optional LAN listener — the fallback path for an internet outage.

Hastama normally has exactly one user facing address, ``https://hastama.ir``
(Cloudflare → Cloudflare Tunnel → ``127.0.0.1:5000``).  Nothing listens on the
laboratory network, which is why the system is unreachable the moment the
internet link — and with it the tunnel — is down.

This module opens a **second, optional** listener on the LAN address of this
machine so the workstations of the laboratory can keep working::

    LAN client  ->  http://<lan-address>:5000   (this module, runtime toggle)
                ->  127.0.0.1:5000              (uvicorn, untouched)

Design rules
------------

* **Byte transparent relay, not a second server.**  ``uvicorn`` keeps binding
  loopback only and the application object is never wrapped a second time.  A
  relay passes HTTP/1.1 *bytes* through, so Server-Sent Events
  (``/api/notifications/stream``) and the call-display WebSocket
  (``/api/ws/call-display``), which are exactly what the laboratory screens use,
  keep working unchanged.
* **Plain HTTP, on purpose.**  There is no certificate for a private address, so
  the LAN origin is ``http://``.  The session cookie therefore loses its
  ``Secure`` flag for LAN requests only (see ``_LanCookieMiddleware`` in
  ``app.main``); the public HTTPS origin is untouched.
* **The client address is proved, not trusted.**  Every forwarding header
  (``X-Forwarded-For`` / ``-Proto`` / ``-Host`` / ``Forwarded`` / ``X-Real-IP``)
  is removed from the incoming request head and replaced with a single
  ``X-Forwarded-For`` carrying the real socket peer.  ``uvicorn
  --proxy-headers`` (trusting ``127.0.0.1``) then hands the application the real
  address, so rate limiting and the audit log cannot be poisoned from the LAN —
  and ``X-Forwarded-Proto: https`` can no longer be used to force a ``Secure``
  cookie the browser would refuse to store.
* **One request per connection.**  The relay answers the head it rewrote and
  asks the origin for ``Connection: close``; a keep-alive request would reach
  the application *without* the sanitised head.  WebSocket upgrades are passed
  through with their original ``Connection: Upgrade``.  Losing keep-alive costs
  one loopback handshake per request on a LAN that is already local.
* **The listener only exists while it is enabled.**  The flag lives in
  ``system_config`` (``lan_access_enabled``), is toggled from
  ``/master-admin/system-settings`` and is applied at runtime — no restart.  No
  automatic time-out is applied: the operator decides (see the residual risk
  entry for this mode in ``docs/security/RESIDUAL_RISK_REGISTER.md``).
* **The firewall is a one-time operator step.**  Windows blocks inbound TCP 5000
  with an explicit block rule; ``scripts/lan_access_firewall.ps1`` (run once as
  administrator) replaces it with an allow rule scoped to the local subnet.
  While the listener is off, nothing is bound on the LAN address at all, so a
  permitted packet is answered with a reset — the in-app toggle is the effective
  switch.

Nothing in this module is imported by the request path except the tiny
``request_is_lan_http`` check.
"""
from __future__ import annotations

import asyncio
import ipaddress
import logging
import os
import socket
from datetime import datetime, timezone
from typing import Iterable, Optional, Sequence

from app.services import system_config

logger = logging.getLogger("hastama.lan_access")

#: ``system_config`` key holding the operator's choice.
ENABLED_KEY = "lan_access_enabled"
ENABLED_DESCRIPTION = "دسترسی رایانه‌های شبکه داخلی به سامانه (حالت اضطراری قطع اینترنت)"

#: Where the application itself listens.  The relay forwards to loopback; it
#: never binds it.
DEFAULT_UPSTREAM_ADDRESS = "127.0.0.1"
DEFAULT_PORT = 5000

#: Caps that keep a misbehaving LAN client from exhausting the process.
MAX_CONNECTIONS = 128
MAX_HEAD_BYTES = 64 * 1024
MAX_HEADER_LINES = 200
UPSTREAM_TIMEOUT_SECONDS = 10.0
#: A client that only half opens a connection (or speaks TLS to the port) must
#: not hold a slot forever: the head has to arrive within this many seconds.
HEAD_TIMEOUT_SECONDS = 15.0
_CHUNK = 64 * 1024

_BAD_REQUEST = (
    b"HTTP/1.1 400 Bad Request\r\nConnection: close\r\n"
    b"Content-Length: 0\r\nContent-Type: text/plain\r\n\r\n"
)
_BAD_GATEWAY = (
    b"HTTP/1.1 502 Bad Gateway\r\nConnection: close\r\n"
    b"Content-Length: 0\r\nContent-Type: text/plain\r\n\r\n"
)
_TOO_MANY_CONNECTIONS = (
    b"HTTP/1.1 503 Service Unavailable\r\nConnection: close\r\n"
    b"Content-Length: 0\r\nContent-Type: text/plain\r\n\r\n"
)

#: Headers a LAN client could use to forge its own address or protocol.  All of
#: them are dropped; the relay adds its own ``X-Forwarded-For``.
_STRIPPED_HEADERS = frozenset(
    {
        b"x-forwarded-for",
        b"x-forwarded-proto",
        b"x-forwarded-host",
        b"x-forwarded-port",
        b"x-forwarded-server",
        b"forwarded",
        b"x-real-ip",
        b"x-client-ip",
        b"x-original-forwarded-for",
        b"x-cluster-client-ip",
        b"x-proxyuser-ip",
    }
)

#: Connection management headers.  Replaced with ``Connection: close`` unless the
#: request is a protocol upgrade (WebSocket), which must keep ``Upgrade``.
_CONNECTION_HEADERS = frozenset({b"connection", b"keep-alive", b"proxy-connection"})


class LanAccessError(RuntimeError):
    """The LAN listener could not be started or stopped."""


# ── Address discovery ───────────────────────────────────────────────────────


def _private_ipv4(value: str) -> str:
    """Return *value* as a usable LAN IPv4 literal, or ``""``.

    Loopback, link-local (``169.254.0.0/16``), multicast and public addresses are
    rejected: binding one of those would either be pointless or expose the
    application beyond the laboratory network.
    """
    candidate = str(value or "").strip().strip("[]")
    if not candidate or len(candidate) > 45:
        return ""
    try:
        address = ipaddress.ip_address(candidate)
    except ValueError:
        return ""
    if address.version != 4:
        return ""
    if address.is_loopback or address.is_link_local or address.is_multicast or address.is_unspecified:
        return ""
    if not address.is_private:
        return ""
    return str(address)


def usable_lan_address(candidates: Iterable[str]) -> str:
    """First usable LAN address of *candidates* (``""`` when there is none)."""
    for candidate in candidates:
        address = _private_ipv4(candidate)
        if address:
            return address
    return ""


def candidate_addresses() -> list[str]:
    """Addresses this machine could be reached at from the LAN, best first."""
    candidates: list[str] = []

    # Routing table: the kernel picks the source address used to reach the
    # outside.  ``connect`` on a UDP socket only selects a route — no packet
    # leaves the machine, and the probe target (TEST-NET-1) is never routable.
    probe = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    try:
        probe.connect(("192.0.2.1", 9))
        candidates.append(str(probe.getsockname()[0]))
    except OSError:
        pass
    finally:
        probe.close()

    # Fallback for a machine with no default route (isolated laboratory switch):
    # whatever the host resolves its own name to.
    try:
        for info in socket.getaddrinfo(socket.gethostname(), None, socket.AF_INET):
            candidates.append(str(info[4][0]))
    except OSError:
        pass
    return candidates


def lan_address() -> str:
    """The address the LAN listener must bind (``""`` when undetectable).

    ``HASTAMA_LAN_BIND_ADDRESS`` overrides the detection — an explicit
    administrator choice wins, and a malformed value is refused instead of
    silently binding something else.
    """
    override = os.getenv("HASTAMA_LAN_BIND_ADDRESS", "").strip()
    if override:
        address = _private_ipv4(override)
        if not address:
            logger.error(
                "HASTAMA_LAN_BIND_ADDRESS=%r is not a usable private IPv4 address", override
            )
        return address
    return usable_lan_address(candidate_addresses())


def configured_port() -> int:
    """The LAN port (defaults to the application port, 5000)."""
    raw = os.getenv("HASTAMA_LAN_PORT", "").strip()
    if raw.isdigit() and 1 <= int(raw) <= 65535:
        return int(raw)
    return DEFAULT_PORT


def upstream_address() -> str:
    return os.getenv("HASTAMA_LAN_UPSTREAM_ADDRESS", "").strip() or DEFAULT_UPSTREAM_ADDRESS


def upstream_port() -> int:
    raw = os.getenv("HASTAMA_LAN_UPSTREAM_PORT", "").strip()
    if raw.isdigit() and 1 <= int(raw) <= 65535:
        return int(raw)
    return configured_port()


# ── Request head sanitising ─────────────────────────────────────────────────


def rewrite_request_head(head: bytes, client_address: str) -> Optional[bytes]:
    """Return *head* as it must be forwarded, or ``None`` when it is malformed.

    The head is the raw request line plus headers, terminated by ``\r\n\r\n``.
    Incoming forwarding headers are dropped, ``X-Forwarded-For`` is set to the
    real socket peer, and ``Connection: close`` is forced — except for a protocol
    upgrade, which must reach uvicorn with its original ``Connection: Upgrade``.
    """
    if not head.endswith(b"\r\n\r\n") or len(head) > MAX_HEAD_BYTES:
        return None

    lines = head[:-4].split(b"\r\n")
    if not lines:
        return None

    request_line = lines[0]
    parts = request_line.split(b" ")
    if len(parts) != 3 or not all(parts):
        return None
    if not parts[2].startswith(b"HTTP/1."):
        return None
    if not all(0x20 <= byte < 0x7F for byte in request_line):
        return None

    if len(lines) - 1 > MAX_HEADER_LINES:
        return None

    parsed: list[tuple[bytes, bytes]] = []  # (lowercase name, raw header line)
    for line in lines[1:]:
        if line[:1] in (b" ", b"\t"):
            # Obsolete line folding: keep it attached to the header it belongs
            # to, and drop it with that header when the header is stripped.
            if parsed and parsed[-1][0] not in _STRIPPED_HEADERS:
                name, raw = parsed[-1]
                parsed[-1] = (name, raw + b"\r\n" + line)
            continue
        name, separator, _value = line.partition(b":")
        if not separator:
            return None
        name = name.strip().lower()
        if not name:
            return None
        parsed.append((name, line))

    upgrade = any(
        name == b"upgrade" and raw.partition(b":")[2].strip() for name, raw in parsed
    )

    forwarded: list[bytes] = [request_line]
    for name, raw in parsed:
        if name in _STRIPPED_HEADERS:
            continue
        if not upgrade and name in _CONNECTION_HEADERS:
            continue
        forwarded.append(raw)
    if not upgrade:
        forwarded.append(b"Connection: close")
    if client_address:
        forwarded.append(b"X-Forwarded-For: " + client_address.encode("latin-1"))
    return b"\r\n".join(forwarded) + b"\r\n\r\n"


async def read_request_head(
    reader: asyncio.StreamReader,
) -> tuple[Optional[bytes], bytes]:
    """Read the request head (through the blank line) plus any body bytes read.

    Returns ``(None, b"")`` when the head could not be isolated (oversized, or
    the peer went away).  The caller must then refuse the connection instead of
    forwarding bytes it could not sanitise.
    """
    buffer = bytearray()
    while len(buffer) <= MAX_HEAD_BYTES:
        try:
            chunk = await reader.read(_CHUNK)
        except (ConnectionResetError, BrokenPipeError, OSError):
            return None, b""
        if not chunk:
            return None, b""
        buffer.extend(chunk)
        end = buffer.find(b"\r\n\r\n")
        if end != -1:
            # Whatever was read past the head belongs to the body: never drop it.
            return bytes(buffer[: end + 4]), bytes(buffer[end + 4 :])
    return None, b""


# ── The service ─────────────────────────────────────────────────────────────


class LanAccessService:
    """Runtime state of the LAN listener (enable / disable, status, relay).

    The keyword arguments are a programmatic seam (tests, and a caller that
    already knows the address): an explicit ``bind_address`` is used as given,
    while the environment variable :data:`lan_address` reads is validated.
    """

    def __init__(
        self,
        *,
        bind_address: Optional[str] = None,
        port: Optional[int] = None,
        upstream: Optional[str] = None,
        upstream_port: Optional[int] = None,
        max_connections: int = MAX_CONNECTIONS,
        head_timeout: float = HEAD_TIMEOUT_SECONDS,
    ) -> None:
        self._bind_override = bind_address
        self._port_override = port
        self._upstream_override = upstream
        self._upstream_port_override = upstream_port
        self._max_connections = max_connections
        self._head_timeout = head_timeout

        self._server: Optional[asyncio.AbstractServer] = None
        self._clients: set[asyncio.StreamWriter] = set()
        self._intent = False
        self._bind_address = ""
        self._port = 0
        self._connections = 0
        self._total_connections = 0
        self._started_at: Optional[datetime] = None
        self._last_error = ""

    # -- introspection ----------------------------------------------------

    @property
    def enabled(self) -> bool:
        """Whether the operator asked for LAN access (the *intent*)."""
        return self._intent

    @property
    def running(self) -> bool:
        return self._server is not None

    @property
    def bind_address(self) -> str:
        return self._bind_address if self.running else ""

    @property
    def bound_port(self) -> int:
        return self._port if self.running else 0

    def status(self) -> dict:
        # ``address``/``url`` describe the listener that is actually open (empty
        # while it is off); ``detected_address``/``expected_url`` are what this
        # machine would offer if the operator switched it on — the UI shows both,
        # so "what do my workstations type?" is answerable before enabling it.
        live = self._bind_address if self.running else ""
        detected = self._resolved_address() or ""
        port = self._port if self.running else self._lan_port()
        return {
            "enabled": self._intent,
            "running": self.running,
            "address": live,
            "detected_address": detected,
            "port": port,
            "url": f"http://{live}:{port}" if live else "",
            "expected_url": f"http://{detected}:{port}" if detected else "",
            "upstream": f"{self._upstream_host()}:{self._upstream_port()}",
            "connections": self._connections,
            "total_connections": self._total_connections,
            "started_at": self._started_at.isoformat() if self._started_at else None,
            "last_error": self._last_error,
        }

    def _resolved_address(self) -> str:
        if self._bind_override is not None:
            return str(self._bind_override)
        return lan_address()

    def _lan_port(self) -> int:
        return int(self._port_override) if self._port_override is not None else configured_port()

    def _upstream_host(self) -> str:
        return self._upstream_override if self._upstream_override is not None else upstream_address()

    def _upstream_port(self) -> int:
        if self._upstream_port_override is not None:
            return int(self._upstream_port_override)
        return upstream_port()

    # -- lifecycle --------------------------------------------------------

    async def start(self) -> dict:
        """Bind the LAN listener.  Raises :class:`LanAccessError` on failure."""
        self._intent = True
        if self.running:
            return self.status()

        address = self._resolved_address()
        if not address:
            self._last_error = "آدرس شبکه داخلی این سرور پیدا نشد."
            raise LanAccessError(self._last_error)

        port = self._lan_port()
        try:
            # ``asyncio.start_server`` (not ``loop.create_server``): our handler is
            # a connected-callback that receives (reader, writer), not a protocol
            # factory.
            server = await asyncio.start_server(
                self._handle_client, host=address, port=port, backlog=128
            )
        except OSError as exc:
            self._last_error = (
                f"شنونده شبکه داخلی روی {address}:{port} باز نشد: "
                f"{type(exc).__name__}: {exc}"
            )
            logger.error("LAN access listener could not bind %s:%s: %s", address, port, exc)
            raise LanAccessError(self._last_error) from exc

        self._server = server
        self._bind_address = address
        self._port = int(server.sockets[0].getsockname()[1]) if server.sockets else port
        self._started_at = datetime.now(timezone.utc)
        self._last_error = ""
        _publish_allowed_hosts()
        logger.warning(
            "LAN access listener started on http://%s:%s -> %s:%s",
            address,
            self._port,
            self._upstream_host(),
            self._upstream_port(),
        )
        return self.status()

    async def stop(self) -> dict:
        """Stop the listener and drop every tunnel it is holding open."""
        self._intent = False
        server, self._server = self._server, None
        if server is not None:
            server.close()
            try:
                await asyncio.wait_for(server.wait_closed(), timeout=5)
            except (asyncio.TimeoutError, OSError):
                pass
            except Exception:  # pragma: no cover - defensive
                pass
        for writer in list(self._clients):
            _close(writer)
        self._clients.clear()
        self._connections = 0
        self._port = 0
        self._started_at = None
        _publish_allowed_hosts()
        logger.warning("LAN access listener stopped")
        return self.status()

    async def apply(self, enabled: bool) -> dict:
        """Make the listener match *enabled*; never raises."""
        if enabled:
            try:
                return await self.start()
            except LanAccessError:
                return self.status()
        return await self.stop()

    # -- relay ------------------------------------------------------------

    async def _handle_client(
        self, reader: asyncio.StreamReader, writer: asyncio.StreamWriter
    ) -> None:
        if self._connections >= self._max_connections:
            writer.write(_TOO_MANY_CONNECTIONS)
            await _close_soon(writer)
            return

        peer = writer.get_extra_info("peername") or ()
        client_address = _peer_address(peer)

        self._connections += 1
        self._total_connections += 1
        self._clients.add(writer)
        upstream_writer: Optional[asyncio.StreamWriter] = None
        try:
            try:
                head, remainder = await asyncio.wait_for(
                    read_request_head(reader), timeout=self._head_timeout
                )
            except asyncio.TimeoutError:
                # Nothing usable arrived (a TLS handshake, a port scan, a stalled
                # client): refuse instead of reserving the slot indefinitely.
                logger.debug("LAN relay: no request head within %ss", self._head_timeout)
                writer.write(_BAD_REQUEST)
                await _close_soon(writer)
                return
            if head is None:
                writer.write(_BAD_REQUEST)
                await _close_soon(writer)
                return

            forwarded = rewrite_request_head(head, client_address)
            if forwarded is None:
                writer.write(_BAD_REQUEST)
                await _close_soon(writer)
                return

            try:
                upstream_reader, upstream_writer = await asyncio.wait_for(
                    asyncio.open_connection(self._upstream_host(), self._upstream_port()),
                    timeout=UPSTREAM_TIMEOUT_SECONDS,
                )
            except (OSError, asyncio.TimeoutError) as exc:
                self._last_error = (
                    f"سرور اصلی پاسخ نداد ({type(exc).__name__})."
                )
                logger.error("LAN relay could not reach the application: %s", exc)
                writer.write(_BAD_GATEWAY)
                await _close_soon(writer)
                return

            upstream_writer.write(forwarded)
            if remainder:
                # Bytes read past the head are the start of the body: they must
                # follow the head, in order, exactly once.
                upstream_writer.write(remainder)
            await upstream_writer.drain()

            await _tunnel(reader, upstream_writer, upstream_reader, writer)
        except (ConnectionResetError, BrokenPipeError, asyncio.IncompleteReadError):
            pass
        except asyncio.CancelledError:  # pragma: no cover - shutdown path
            raise
        except Exception as exc:  # pragma: no cover - defensive
            logger.debug("LAN relay connection ended: %s: %s", type(exc).__name__, exc)
        finally:
            self._connections = max(0, self._connections - 1)
            self._clients.discard(writer)
            _close(upstream_writer)
            _close(writer)


def _peer_address(peer: Sequence) -> str:
    """Normalised IP literal of the socket peer (``""`` when unparsable)."""
    try:
        raw = str(peer[0]) if peer else ""
    except Exception:  # pragma: no cover - defensive
        return ""
    try:
        return str(ipaddress.ip_address(raw))
    except ValueError:
        return ""


async def _tunnel(
    client_reader: asyncio.StreamReader,
    upstream_writer: asyncio.StreamWriter,
    upstream_reader: asyncio.StreamReader,
    client_writer: asyncio.StreamWriter,
) -> None:
    """Pipe both directions until one side finishes (SSE/WS stay open)."""

    async def client_to_upstream() -> None:
        while True:
            chunk = await client_reader.read(_CHUNK)
            if not chunk:
                return
            upstream_writer.write(chunk)
            await upstream_writer.drain()

    async def upstream_to_client() -> None:
        while True:
            chunk = await upstream_reader.read(_CHUNK)
            if not chunk:
                return
            client_writer.write(chunk)
            await client_writer.drain()

    tasks = [
        asyncio.ensure_future(client_to_upstream()),
        asyncio.ensure_future(upstream_to_client()),
    ]
    try:
        done, pending = await asyncio.wait(tasks, return_when=asyncio.FIRST_COMPLETED)
    finally:
        for task in tasks:
            if not task.done():
                task.cancel()
    for task in done:
        if task.cancelled():
            continue
        exception = task.exception()
        if exception is not None:
            raise exception


def _close(writer: Optional[asyncio.StreamWriter]) -> None:
    if writer is None:
        return
    try:
        writer.close()
    except Exception:  # pragma: no cover - defensive
        pass


async def _close_soon(writer: asyncio.StreamWriter) -> None:
    """Flush a short error response and close, without waiting for the peer."""
    try:
        await writer.drain()
    except Exception:
        pass
    _close(writer)


# ── Host allow-list / cookie integration hooks ──────────────────────────────


def _publish_allowed_hosts() -> None:
    """Tell ``app.main`` which extra ``Host`` values the listener needs.

    ``TrustedHostMiddleware`` is built with a fixed list, so the LAN address is
    appended to the live instance while the listener runs and removed again when
    it stops.  A missing application module (unit tests importing this service
    alone) is not an error.
    """
    try:
        from app.main import set_lan_allowed_hosts
    except Exception:  # pragma: no cover - import cycle guard
        return
    try:
        set_lan_allowed_hosts([_state.bind_address] if _state.bind_address else [])
    except Exception as exc:  # pragma: no cover - defensive
        logger.debug("LAN host allow-list could not be updated: %s", exc)


def request_is_lan_http(scope: dict) -> bool:
    """Whether *scope* is a request that arrived on the LAN listener.

    Used by ``_LanCookieMiddleware`` to decide if the ``Secure`` cookie flag must
    be dropped.  The check is deliberately narrow: the flag is only removed for a
    plain-HTTP request whose ``Host`` is the address the LAN listener is bound
    to, while that listener is actually running.
    """
    if scope.get("type") != "http":
        return False
    if not _state.running or not _state.bind_address:
        return False
    if str(scope.get("scheme", "")).lower() == "https":
        return False
    headers = dict(scope.get("headers", []))
    forwarded_proto = headers.get(b"x-forwarded-proto", b"").decode("latin-1", "ignore")
    if forwarded_proto.split(",")[-1].strip().lower() == "https":
        return False
    host = headers.get(b"host", b"").decode("latin-1", "ignore").strip().lower()
    if not host:
        return False
    hostname = host.split(":")[0].strip()
    return hostname == _state.bind_address.lower()


# ── Persisted flag ──────────────────────────────────────────────────────────


def truthy(value) -> bool:
    """Interpret a stored / posted flag (``"1"``, ``"true"``, ``True`` …)."""
    return system_config.truthy(value)


def read_enabled_flag() -> bool:
    """Read the persisted choice (``False`` when the row is missing/unreadable)."""
    return system_config.read_flag(ENABLED_KEY)


def write_enabled_flag(enabled: bool, actor: str = "") -> bool:
    """Persist the choice.  Returns ``False`` when the write failed."""
    return system_config.write_flag(
        ENABLED_KEY, enabled, actor=actor, description=ENABLED_DESCRIPTION
    )


# ── Module level facade ─────────────────────────────────────────────────────

_state = LanAccessService()


def status() -> dict:
    """Current state of the listener (single source of truth for the API/UI)."""
    return _state.status()


async def set_enabled(enabled: bool, *, actor: str = "") -> dict:
    """Persist *enabled*, apply it immediately and return the new status."""
    saved = write_enabled_flag(enabled, actor)
    data = await _state.apply(enabled)
    data["saved"] = saved
    return data


async def start_from_config() -> None:
    """Start the listener at boot when the operator left it enabled."""
    if not read_enabled_flag():
        return
    data = await _state.apply(True)
    if not data["running"]:
        logger.error(
            "LAN access is enabled in system settings but the listener is not running: %s",
            data["last_error"] or "unknown error",
        )


async def self_test(timeout: float = 5.0) -> dict:
    """Fetch ``/health`` through the LAN listener from this machine.

    The traffic never leaves the host, so this proves the *relay* end to end
    (listener, rewritten head, ``Host`` allow-list, application) but says nothing
    about the Windows firewall — an outside workstation is the only real test of
    that, which is why the UI states it explicitly.
    """
    address = _state.bind_address
    port = _state.bound_port
    if not _state.running or not address:
        return {
            "ok": False,
            "address": "",
            "port": 0,
            "http_status": 0,
            "message": "شونده شبکه داخلی فعال نیست.",
        }

    request_bytes = (
        f"GET /health HTTP/1.1\r\nHost: {address}:{port}\r\n"
        "Connection: close\r\nUser-Agent: hastama-lan-selftest\r\n\r\n"
    ).encode("latin-1")

    writer = None
    try:
        reader, writer = await asyncio.wait_for(
            asyncio.open_connection(address, port), timeout=timeout
        )
        writer.write(request_bytes)
        await writer.drain()
        raw = await asyncio.wait_for(reader.read(4096), timeout=timeout)
    except (OSError, asyncio.TimeoutError) as exc:
        return {
            "ok": False,
            "address": address,
            "port": port,
            "http_status": 0,
            "message": f"اتصال به {address}:{port} برقرار نشد ({type(exc).__name__}).",
        }
    finally:
        _close(writer)

    status_line = raw.split(b"\r\n", 1)[0].decode("latin-1", "ignore").strip()
    http_status = 0
    parts = status_line.split(" ")
    if len(parts) >= 2 and parts[1].isdigit():
        http_status = int(parts[1])
    ok = http_status == 200
    return {
        "ok": ok,
        "address": address,
        "port": port,
        "http_status": http_status,
        "status_line": status_line,
        "message": (
            f"پاسخ ۲۰۰ از {address}:{port} دریافت شد."
            if ok
            else f"پاسخ نامعتبر از شونده شبکه داخلی: {status_line or 'بدون پاسخ'}"
        ),
    }


async def stop() -> None:
    """Shutdown hook: drop the listener and every tunnel it holds."""
    await _state.apply(False)
