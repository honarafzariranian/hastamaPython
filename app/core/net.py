"""Trusted client-IP and request-origin helpers.

Hastama is reachable in two supported topologies:

1. **Production LAN** — Caddy terminates HTTPS on ``hastama.local`` and proxies
   to ``uvicorn --host 127.0.0.1 --port 8000 --proxy-headers``.
2. **Direct/dev** — ``uvicorn --host 0.0.0.0 --port 5000`` (see
   ``scripts/run_server.bat``).

``X-Forwarded-For`` is *client controlled* in every topology unless a trusted
proxy appends the real peer address.  Caddy appends the immediate peer to any
client-supplied header, so the **last** syntactically valid entry of the header
is the closest value we can trust, while the first entry is attacker chosen.

The previous implementation used ``split(",")[0]`` which allowed any client to
forge an arbitrary address.  That broke IP based rate limiting, poisoned audit
records, and (because audit values are rendered in the admin UI) provided an
unauthenticated stored-XSS primitive.  See ``docs/security/`` for the finding.

Rules implemented here:

* Honor ``X-Forwarded-For`` / ``X-Forwarded-Proto`` **only** when the request
  arrives from a configured trusted proxy (default ``127.0.0.1, ::1``, the
  Caddy sidecar on the same host).  A client that reaches the application port
  directly therefore cannot forge its own address.
* Use the last syntactically valid IP in ``X-Forwarded-For``.
* Ignore the header completely when no entry is a valid IP literal.
* Fall back to the socket peer address.
* Bound the amount of header data we are willing to parse.
"""

from __future__ import annotations

import ipaddress
import logging
import os
from typing import Optional

from fastapi import Request

logger = logging.getLogger("hastama.net")

MAX_XFF_LENGTH = 512
MAX_XFF_ENTRIES = 20
MAX_IP_LENGTH = 45  # longest textual IPv6 form


def trusted_proxies() -> frozenset:
    """Addresses whose forwarding headers are believed.

    ``TRUSTED_PROXY_IPS`` is a comma separated list; the default trusts only the
    loopback addresses, which matches the supported deployment (Caddy and the
    application on the same host).  Set it to the reverse proxy address when the
    proxy runs on another machine.
    """
    raw = os.getenv("TRUSTED_PROXY_IPS", "127.0.0.1,::1")
    values = {item.strip() for item in raw.split(",") if item.strip()}
    return frozenset(values)


def _peer_address(request: Request) -> Optional[str]:
    peer = getattr(getattr(request, "client", None), "host", None)
    return _parse_ip(str(peer or ""))


def _peer_is_trusted(request: Request) -> bool:
    peer = _peer_address(request)
    return bool(peer) and peer in trusted_proxies()


def forwarded_proto(request: Request) -> str:
    """Return the forwarded protocol, but only from a trusted peer."""
    if not _peer_is_trusted(request):
        return ""
    try:
        raw = request.headers.get("x-forwarded-proto", "") or ""
    except Exception:  # pragma: no cover - defensive
        return ""
    if len(raw) > 64:
        return ""
    return raw.split(",")[-1].strip().lower()


def _parse_ip(value: str) -> Optional[str]:
    """Return a normalised IP literal or ``None`` when *value* is not an IP."""
    candidate = value.strip().strip('"')
    if not candidate or len(candidate) > MAX_IP_LENGTH:
        return None
    # Strip a port suffix such as "10.0.0.5:44321" (IPv6 uses brackets).
    if candidate.startswith("["):
        end = candidate.find("]")
        if end > 0:
            candidate = candidate[1:end]
    elif candidate.count(":") == 1:
        candidate = candidate.split(":", 1)[0]
    try:
        return str(ipaddress.ip_address(candidate))
    except ValueError:
        return None


def client_ip(request: Request) -> str:
    """Return the best-effort trustworthy client IP for *request*.

    Never returns a client supplied string that is not a valid IP literal, so
    the value is safe to store in the database and to render in HTML.
    """
    header = ""
    if _peer_is_trusted(request):
        try:
            header = request.headers.get("x-forwarded-for", "") or ""
        except Exception:  # pragma: no cover - defensive, malformed ASGI scope
            header = ""

    if header and len(header) <= MAX_XFF_LENGTH:
        entries = [part for part in header.split(",")][-MAX_XFF_ENTRIES:]
        for part in reversed(entries):
            parsed = _parse_ip(part)
            if parsed:
                return parsed

    parsed_peer = _peer_address(request)
    if parsed_peer:
        return parsed_peer
    return "unknown"


def is_https(request: Request) -> bool:
    """Return ``True`` when the request reached the app over HTTPS."""
    proto = forwarded_proto(request)
    if proto:
        return proto == "https"
    try:
        return request.url.scheme == "https"
    except Exception:  # pragma: no cover - defensive
        return False


def user_agent(request: Request, limit: int = 500) -> str:
    """Return a length-capped user agent string (never ``None``)."""
    try:
        value = request.headers.get("user-agent", "") or ""
    except Exception:  # pragma: no cover - defensive
        value = ""
    # Control characters would be an injection vector for log/report consumers.
    cleaned = "".join(ch for ch in value if ch.isprintable())
    return cleaned[:limit]


def origin_is_same_site(request: Request) -> bool:
    """Best-effort same-origin check for browser initiated requests.

    Returns ``True`` when the ``Origin`` header is absent (non-browser client or
    same-origin navigation) or when its host matches the ``Host`` header.  A
    cross-site ``Origin`` is rejected so that browser-driven cross-site writes
    to CSRF-exempt endpoints are blocked.
    """
    origin = request.headers.get("origin")
    if not origin:
        return True
    if origin == "null":
        return False
    host = request.headers.get("host") or ""
    try:
        from urllib.parse import urlparse

        parsed = urlparse(origin)
    except Exception:  # pragma: no cover - defensive
        return False
    if not parsed.netloc:
        return False
    return parsed.netloc.split(":")[0].lower() == host.split(":")[0].lower()


def trusted_proxy_configured() -> bool:
    """Whether the operator declared a trusted reverse proxy (informational)."""
    return bool(os.getenv("HASTAMA_TRUSTED_PROXY", "").strip())
