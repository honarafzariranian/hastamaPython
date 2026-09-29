"""Iran-only access — only Iranian IP addresses may use the public path.

The laboratory works through ``https://hastama.ir`` and only Iranian clients are
accepted.  A user who arrives through a VPN (or from abroad) has a *public*,
non Iranian address, so the system answers with a warning page that tells them to
switch the VPN off and come back — see ``app/templates/vpn-warning.html``.

How the verdict is made
-----------------------

The check is **completely offline**.  The Iranian address space is shipped with
the application as ``app/data/iran_ip_ranges.txt`` (generated from the RIPE and
APNIC delegated statistics, see :func:`refresh`), parsed once into sorted
``(start, end)`` integers and searched with a binary search.  Nothing is asked
from a geo-IP web service, so:

* the verdict is the same while the internet is down (see
  ``app/services/outage.py``) — which is exactly when a fallback must not depend
  on the network;
* no visitor address is ever handed to a third party;
* there is no per-request latency and no rate limit to hit.

Never blocked, so the feature can always be managed while it is on:

* **internal** addresses — loopback, the laboratory LAN, CGNAT, link local: the
  operator on the server, the watchdog's ``/health``, the printer side and every
  client of the LAN listener (``app/services/lan_access.py``);
* an authenticated **master administrator** session (that account may work over
  a VPN);
* the infrastructure paths themselves (``/static/``, ``/health``, ``/iran-only``,
  ``/sw.js``, ``/offline``, ``robots.txt``, ``sitemap.xml``, ``favicon.ico``).

The setting is **armed by default**: a missing row in ``system_config`` means
"on".  The address list is the only thing that is *not* automatic — every
download is an explicit administrator action, and if the list cannot be read the
feature stops filtering (and says so, loudly, in the settings card) instead of
silently locking every Iranian user out.

Operators need an escape hatch, because a *registry* country and an *actual*
user location disagree in both directions: Iranian ISPs and offices sometimes
egress through a neighbouring country's allocation (a shared NAT pool that the
registries mark ``AZ``, ``TR``, ``AE`` …), so a user with no VPN at all is
treated as foreign.  ``app/data/iran_ip_ranges_extra.txt`` is merged on top of
the generated list for exactly those networks.  It is deliberately **not** the
generated file: :func:`update_list_from_registries` rewrites
``iran_ip_ranges.txt`` and would silently discard a hand edit, while the
supplement survives every refresh.  It is the blunt instrument of last resort —
preferable to switching the whole filter off, but every range in it is a range
of foreign addresses that may enter.

The settings live in ``system_config`` and are edited in
``master-admin → system settings``; everything is applied without a restart.
See ``docs/network/UNIFIED_URL_ARCHITECTURE.md`` §9d and RR-31.
"""
from __future__ import annotations

import asyncio
import bisect
import ipaddress
import logging
import os
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

from app.services import lan_access, system_config

logger = logging.getLogger("hastama.iran_access")

# ── Settings (``system_config`` keys) ───────────────────────────────────────

ENABLED_KEY = "iran_only_enabled"
TITLE_KEY = "iran_only_title"
MESSAGE_KEY = "iran_only_message"
HELP_KEY = "iran_only_help"
LOG_KEY = "iran_only_log_blocked"

_DESCRIPTIONS = {
    ENABLED_KEY: "پذیرش ورود فقط با آی‌پی ایران (مسدودسازی VPN)",
    TITLE_KEY: "عنوان پیام مسدودی آی‌پی غیرایرانی",
    MESSAGE_KEY: "متن پیام مسدودی آی‌پی غیرایرانی",
    HELP_KEY: "راهنمای رفع مسدودی (قطع VPN) روی صفحهٔ هشدار",
    LOG_KEY: "ثبت تلاش‌های مسدودشده در گزارش رویداد",
}

DEFAULT_TITLE = "دسترسی از این آی‌پی مجاز نیست"
DEFAULT_MESSAGE = (
    "سامانه فقط ورود با آی‌پی ایران را می‌پذیرد. به نظر می‌رسد در حال حاضر از "
    "طریق VPN یا پروکسی (یا از خارج از کشور) متصل شده‌اید. لطفاً ابتدا VPN یا "
    "فیلترشکن خود را قطع کنید، سپس این صفحه را دوباره بارگذاری کنید."
)
DEFAULT_HELP = (
    "روی ویندوز: آیکون VPN در نوار کنار ساعت را باز کنید و Disconnect را بزنید.\n"
    "روی گوشی اندروید: تنظیمات ← شبکه و اینترنت ← VPN ← اتصال را قطع کنید.\n"
    "روی iPhone: تنظیمات ← General ← VPN & Device Management ← اتصال را قطع کنید.\n"
    "اگر از افزونهٔ مرورگر (فیلترشکن) استفاده می‌کنید، آن را غیرفعال یا حذف کنید.\n"
    "پس از قطع VPN، این صفحه را دوباره بارگذاری کنید تا وارد شوید."
)

MAX_TITLE_CHARS = 120
MAX_MESSAGE_CHARS = 800
MAX_HELP_CHARS = 1200
MAX_ANSWERED_IP_CHARS = 45

#: How often a single address may be written to the audit log (the counter and
#: the "last blocked" row in the card always move; only the log is throttled, so
#: a hostile client cannot flood the database by looping on a blocked page).
BLOCK_LOG_INTERVAL_SECONDS = 300
MAX_TRACKED_ADDRESSES = 500

#: Paths that keep working while the filter is on.
EXEMPT_PREFIXES = (
    "/static/",
    "/iran-only",
    "/health",
    "/offline",
    "/sw.js",
    "/robots.txt",
    "/sitemap.xml",
    "/favicon.ico",
)

#: Paths answered with JSON (an API caller cannot render a page).
JSON_PREFIXES = (
    "/api/",
    "/master-admin/api/",
    "/registration/",
    "/ticketing/",
    "/notifications",
)

# ── The address list ────────────────────────────────────────────────────────

DATA_PATH = Path(__file__).resolve().parents[1] / "data" / "iran_ip_ranges.txt"

#: Operator maintained supplement, merged on top of the generated list and
#: **never** rewritten by a refresh.  One CIDR per line, ``#`` starts a comment.
#: Every line here is an exception that lets non-Iranian addresses in, so the
#: file carries a reason per entry and the settings card reports how many are in
#: force.  A missing or broken file is an empty supplement, never an error.
EXTRA_PATH = Path(__file__).resolve().parents[1] / "data" / "iran_ip_ranges_extra.txt"

#: Authoritative, machine readable allocations.  ``extended`` carries the record
#: start and the address count (ipv4) or the prefix length (ipv6).
REGISTRY_SOURCES = (
    "https://ftp.ripe.net/pub/stats/ripencc/delegated-ripencc-extended-latest",
    "https://ftp.apnic.net/stats/apnic/delegated-apnic-extended-latest",
)

#: Country code we keep, and the record statuses that mean "really allocated".
COUNTRY = "IR"
KEEP_STATUSES = ("allocated", "assigned")

#: A manual download moves ~30 MB; give it room but never hang forever.
DOWNLOAD_TIMEOUT_SECONDS = 90
MAX_DOWNLOAD_BYTES = 64 * 1024 * 1024

#: Guard rails for an administrator supplied replacement list.
MAX_LIST_ENTRIES = 20000


class IranAccessError(RuntimeError):
    """The settings are invalid, or the address list could not be replaced."""


class _ListImage:
    """Parsed, binary-searchable picture of the Iranian address space."""

    __slots__ = (
        "v4_starts",
        "v4_ends",
        "v6_starts",
        "v6_ends",
        "v4_count",
        "v6_count",
        "extra_v4_count",
        "extra_v6_count",
        "loaded",
        "error",
    )

    def __init__(self) -> None:
        self.v4_starts: list = []
        self.v4_ends: list = []
        self.v6_starts: list = []
        self.v6_ends: list = []
        self.v4_count = 0
        self.v6_count = 0
        self.extra_v4_count = 0
        self.extra_v6_count = 0
        self.loaded = False
        self.error = ""

    def stamp(self) -> tuple:
        """A cheap identity of the image, used to detect a stale file on disk."""
        return (self.v4_count, self.v6_count, self.v4_count and self.v4_starts[0], len(self.v4_starts))


def _merge(bounds: list) -> tuple:
    """Sort inclusive ``(start, end)`` pairs and merge the touching ones."""
    bounds.sort()
    starts: list = []
    ends: list = []
    for start, end in bounds:
        if ends and start <= ends[-1] + 1:
            ends[-1] = max(ends[-1], end)
        else:
            starts.append(start)
            ends.append(end)
    return starts, ends


def parse_list_text(text: str) -> dict:
    """Parse a range-list file body into bounds.

    One CIDR per line (``2.144.0.0/14`` / ``2001:790::/32``); blank lines and
    ``#`` comments are ignored and every unusable line is dropped silently — the
    file is generated, so a broken line must never take the whole list down.
    """
    v4: list = []
    v6: list = []
    seen = 0
    for raw in str(text or "").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or line.startswith(";"):
            continue
        line = line.split("#", 1)[0].strip()
        if not line:
            continue
        seen += 1
        if seen > MAX_LIST_ENTRIES * 4:
            break
        try:
            network = ipaddress.ip_network(line, strict=False)
        except ValueError:
            continue
        if network.version == 4:
            v4.append((int(network.network_address), int(network.broadcast_address)))
        else:
            v6.append((int(network.network_address), int(network.broadcast_address)))
    return {"v4": v4, "v6": v6}


def list_metadata() -> dict:
    """Version information of the file on disk (for the settings card)."""
    info = {"path": str(DATA_PATH), "exists": DATA_PATH.exists(), "bytes": 0, "mtime": None, "source": "", "generated": ""}
    try:
        stat = DATA_PATH.stat()
        info["bytes"] = stat.st_size
        info["mtime"] = datetime.fromtimestamp(stat.st_mtime, timezone.utc).isoformat()
        with DATA_PATH.open("r", encoding="utf-8", errors="replace") as handle:
            for _ in range(12):
                line = handle.readline()
                if not line or not line.startswith("#"):
                    break
                body = line.lstrip("#").strip()
                if body.lower().startswith("generated:"):
                    info["generated"] = body.split(":", 1)[1].strip()
                elif body.lower().startswith("source"):
                    info["source"] = body.split(":", 1)[1].strip()
    except Exception as exc:  # pragma: no cover - defensive
        logger.warning("iran ip range metadata unreadable: %s: %s", type(exc).__name__, exc)
    return info


class _IranState:
    def __init__(self) -> None:
        self.enabled = True
        self.title = DEFAULT_TITLE
        self.message = DEFAULT_MESSAGE
        self.help_text = DEFAULT_HELP
        self.log_blocked = True

        self.image = _ListImage()
        self.loaded_at: Optional[str] = None
        self.file_stamp: Optional[tuple] = None
        self.last_refresh: Optional[str] = None
        self.last_refresh_error = ""

        self.blocked_count = 0
        self.allowed_count = 0
        self.last_blocked_ip = ""
        self.last_blocked_at: Optional[datetime] = None
        self.last_blocked_path = ""
        self.recent: dict = {}


_state = _IranState()


# ── Loading ─────────────────────────────────────────────────────────────────

def _list_stamp() -> Optional[tuple]:
    """Identity of both range files, or ``None`` when the generated one is gone.

    The supplement is part of the stamp so that editing it by hand on the server
    is picked up by the next request, exactly like replacing the main file.
    """
    try:
        main = DATA_PATH.stat().st_mtime
    except OSError:
        return None
    try:
        extra = EXTRA_PATH.stat().st_mtime
    except OSError:
        extra = 0.0
    return (main, extra)


def _load_extra_bounds() -> dict:
    """Parse the operator maintained supplement; never raises.

    A missing file is an empty supplement.  A *broken* file is an empty one too:
    the exception list is a convenience, so a typo in it must not take the
    generated list down with it.
    """
    try:
        text = EXTRA_PATH.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return {"v4": [], "v6": []}
    except Exception as exc:  # pragma: no cover - defensive
        logger.error("Iranian list supplement unreadable: %s: %s", type(exc).__name__, exc)
        return {"v4": [], "v6": []}
    return parse_list_text(text)


def _load_list(force: bool = False) -> None:
    """(Re)read the range files when they changed on disk; never raises."""
    stamp = _list_stamp()
    if stamp is None:
        if force or _state.image.loaded:
            _state.image = _ListImage()
            _state.image.error = "فایل فهرست آی‌پی ایران یافت نشد."
            logger.error("Iranian address list is missing at %s", DATA_PATH)
        return

    if not force and _state.image.loaded and stamp == _state.file_stamp:
        return

    try:
        text = DATA_PATH.read_text(encoding="utf-8", errors="replace")
    except Exception as exc:
        _state.image = _ListImage()
        _state.image.error = f"فهرست آی‌پی ایران خوانده نشد ({type(exc).__name__})."
        logger.error("Iranian address list unreadable: %s: %s", type(exc).__name__, exc)
        return

    parsed = parse_list_text(text)
    extra = _load_extra_bounds()
    extra_v4 = _merge(list(extra["v4"]))
    extra_v6 = _merge(list(extra["v6"]))
    image = _ListImage()
    image.v4_starts, image.v4_ends = _merge(parsed["v4"] + extra["v4"])
    image.v6_starts, image.v6_ends = _merge(parsed["v6"] + extra["v6"])
    image.v4_count = len(image.v4_starts)
    image.v6_count = len(image.v6_starts)
    image.extra_v4_count = len(extra_v4[0])
    image.extra_v6_count = len(extra_v6[0])
    image.loaded = bool(image.v4_count or image.v6_count)
    if not image.loaded:
        image.error = "فهرست آی‌پی ایران خالی است."
        logger.error("Iranian address list parsed empty (%s)", DATA_PATH)

    _state.image = image
    _state.file_stamp = stamp
    _state.loaded_at = datetime.now(timezone.utc).isoformat()
    logger.warning(
        "Iranian address list loaded: %s ipv4 + %s ipv6 ranges", image.v4_count, image.v6_count
    )
    if image.extra_v4_count or image.extra_v6_count:
        logger.warning(
            "Iranian address list supplement merged: %s extra range(s) from %s",
            image.extra_v4_count + image.extra_v6_count,
            EXTRA_PATH,
        )


def _in_bounds(starts: list, ends: list, value: int) -> bool:
    index = bisect.bisect_right(starts, value) - 1
    return index >= 0 and value <= ends[index]


def _containing_network(address) -> str:
    """The widest stored network that contains *address* (for the test button)."""
    image = _state.image
    starts = image.v4_starts if address.version == 4 else image.v6_starts
    ends = image.v4_ends if address.version == 4 else image.v6_ends
    index = bisect.bisect_right(starts, int(address)) - 1
    if index < 0 or int(address) > ends[index]:
        return ""
    try:
        first = ipaddress.ip_address(starts[index])
        last = ipaddress.ip_address(ends[index])
        return ", ".join(str(network) for network in ipaddress.summarize_address_range(first, last))
    except Exception:  # pragma: no cover - defensive
        return ""


def normalize_ip(value) -> Optional[object]:
    """Parse *value* into an address; ``None`` when it is not one."""
    if isinstance(value, (bytes, bytearray)):
        try:
            value = value.decode("latin-1", "ignore")
        except Exception:  # pragma: no cover - defensive
            return None
    text = str(value or "").strip().strip('"')
    if not text or len(text) > MAX_ANSWERED_IP_CHARS + 32:
        return None
    # A socket peer carries its port: "[2001:790::1]:51000" or "1.2.3.4:5678".
    if text.startswith("["):
        text = text[1 : text.find("]")] if "]" in text else text.lstrip("[")
    elif text.count(":") == 1 and "." in text:
        text = text.split(":", 1)[0]
    try:
        address = ipaddress.ip_address(text)
    except ValueError:
        return None
    # A dual-stack socket reports an IPv4 client as ``::ffff:1.2.3.4``.
    mapped = getattr(address, "ipv4_mapped", None)
    if mapped is not None:
        return mapped
    return address


def classify(ip) -> dict:
    """Decide which side of the filter *ip* belongs to.

    ``kind`` is one of ``internal`` (loopback/LAN/CGNAT — always allowed),
    ``iran`` (inside a registered Iranian range), ``foreign`` (blocked) or
    ``unknown`` (not an address at all — allowed, and flagged, because a broken
    peer value is an infrastructure bug and must not lock users out).
    """
    address = normalize_ip(ip)
    if address is None:
        return {
            "ip": str(ip or "")[:MAX_ANSWERED_IP_CHARS],
            "kind": "unknown",
            "allowed": True,
            "label": "نامشخص (اجازه داده شد)",
            "range": "",
        }

    text = str(address)
    if not address.is_global:
        return {
            "ip": text,
            "kind": "internal",
            "allowed": True,
            "label": "شبکهٔ داخلی یا محلی",
            "range": "",
        }

    _load_list()
    image = _state.image
    if not image.loaded:
        return {
            "ip": text,
            "kind": "unknown",
            "allowed": True,
            "label": "فهرست آی‌پی ایران در دسترس نیست",
            "range": "",
        }

    starts = image.v4_starts if address.version == 4 else image.v6_starts
    ends = image.v4_ends if address.version == 4 else image.v6_ends
    if _in_bounds(starts, ends, int(address)):
        return {
            "ip": text,
            "kind": "iran",
            "allowed": True,
            "label": "ایران",
            "range": _containing_network(address),
        }
    return {
        "ip": text,
        "kind": "foreign",
        "allowed": False,
        "label": "خارج از ایران (VPN یا خارج از کشور)",
        "range": "",
    }


def enforcing() -> bool:
    """Whether the filter is actually able to reject anything right now.

    Armed *and* holding an address list: an unreadable list must never turn into
    a silent lockout, so the card reports the armed-but-blind state instead.
    """
    if not _state.enabled:
        return False
    _load_list()
    return bool(_state.image.loaded)


def is_blocked(ip) -> bool:
    if not enforcing():
        return False
    return classify(ip)["kind"] == "foreign"


# ── Status and the warning page ─────────────────────────────────────────────

def status() -> dict:
    """Everything the settings card and the API need to show."""
    _load_list()
    image = _state.image
    metadata = list_metadata()
    return {
        "enabled": _state.enabled,
        "enforcing": enforcing(),
        "list_loaded": bool(image.loaded),
        "list_error": image.error,
        "list_path": metadata["path"],
        "list_exists": metadata["exists"],
        "list_bytes": metadata["bytes"],
        "list_generated": metadata["generated"],
        "list_source": metadata["source"],
        "list_updated_at": metadata["mtime"],
        "ranges_ipv4": image.v4_count,
        "ranges_ipv6": image.v6_count,
        "extra_file": EXTRA_PATH.name,
        "extra_exists": EXTRA_PATH.exists(),
        "extra_ranges": image.extra_v4_count + image.extra_v6_count,
        "loaded_at": _state.loaded_at,
        "last_refresh": _state.last_refresh,
        "last_refresh_error": _state.last_refresh_error,
        "registry_sources": list(REGISTRY_SOURCES),
        "blocked_count": _state.blocked_count,
        "allowed_count": _state.allowed_count,
        "last_blocked_ip": _state.last_blocked_ip,
        "last_blocked_at": _state.last_blocked_at.isoformat() if _state.last_blocked_at else None,
        "last_blocked_path": _state.last_blocked_path,
        "title": _state.title,
        "message": _state.message,
        "help_text": _state.help_text,
        "log_blocked": _state.log_blocked,
        "armed_by_default": True,
        "block_log_interval_seconds": BLOCK_LOG_INTERVAL_SECONDS,
    }


def page_context(scope: Optional[dict] = None, ip: str = "") -> dict:
    """Context for ``vpn-warning.html``."""
    address = ip
    if not address and scope is not None:
        address = client_ip(scope)
    verdict = classify(address)
    return {
        "vpn_title": _state.title or DEFAULT_TITLE,
        "vpn_message": _state.message or DEFAULT_MESSAGE,
        "vpn_help": _state.help_text or DEFAULT_HELP,
        "vpn_help_lines": [line for line in (_state.help_text or DEFAULT_HELP).splitlines() if line.strip()],
        "vpn_ip": verdict.get("ip") or "",
        "vpn_reason": verdict.get("label") or "",
        "vpn_filter_on": bool(_state.enabled),
        "retry_seconds": 30,
    }


# ── Per-request decision ────────────────────────────────────────────────────

def client_ip(scope: dict) -> str:
    """Best-effort trustworthy client address of an ASGI *scope*."""
    from app.core.net import client_ip_from_scope

    return client_ip_from_scope(scope)


def request_is_subject(scope: dict) -> bool:
    """Whether *scope* must pass the filter (i.e. it is not exempt).

    Exempt: the infrastructure paths (a blocked page load must not take away
    ``/health`` or the stylesheets it needs), the LAN listener and an internal
    peer.  A verified master-administrator session is exempted by the gate in
    ``app.main``, which owns the session secret.
    """
    path = scope.get("path", "") or ""
    if any(path.startswith(prefix) for prefix in EXEMPT_PREFIXES):
        return False
    if scope.get("type") not in ("http", "websocket"):
        return False
    try:
        if lan_access.request_is_lan_http(scope):
            return False
    except Exception:  # pragma: no cover - defensive
        pass
    return True


def record_block(scope: dict, verdict: dict) -> None:
    """Count a rejected request; write the audit entry at most once per window."""
    address = verdict.get("ip") or ""
    _state.blocked_count += 1
    _state.last_blocked_ip = address
    _state.last_blocked_at = datetime.now(timezone.utc)
    _state.last_blocked_path = str(scope.get("path", "") or "")[:200]

    if not _state.log_blocked or not address:
        return

    now = datetime.now(timezone.utc)
    previous = _state.recent.get(address)
    if previous is not None and (now - previous).total_seconds() < BLOCK_LOG_INTERVAL_SECONDS:
        return
    if len(_state.recent) >= MAX_TRACKED_ADDRESSES:
        oldest = min(_state.recent, key=_state.recent.get)
        _state.recent.pop(oldest, None)
    _state.recent[address] = now

    headers = dict(scope.get("headers", []))
    user_agent = headers.get(b"user-agent", b"").decode("latin-1", "ignore")[:300]
    _audit(
        "iran_only_blocked",
        "blocked",
        "medium",
        {
            "ip": address,
            "path": str(scope.get("path", "") or "")[:200],
            "reason": verdict.get("label") or "",
            "user_agent": "".join(ch for ch in user_agent if ch.isprintable()),
        },
    )


def record_allowed() -> None:
    _state.allowed_count += 1


def _audit(action: str, status_value: str, severity: str, metadata: dict) -> None:
    try:
        from app.services.audit import log_event

        log_event(
            event_type="SECURITY",
            action=action,
            module="access_control",
            resource_type="ip_address",
            resource_id=str(metadata.get("ip") or "")[:45],
            status=status_value,
            severity=severity,
            metadata=metadata,
        )
    except Exception:  # pragma: no cover - auditing must never break the gate
        pass


# ── Settings ────────────────────────────────────────────────────────────────

def _clean_text(value, limit: int, fallback: str) -> str:
    """Collapse whitespace, keep line breaks for the help text, cap the length."""
    text = str(value or "").replace("\r\n", "\n").replace("\r", "\n")
    lines = [" ".join(line.split()) for line in text.split("\n")]
    cleaned = "\n".join(line for line in lines).strip()
    return (cleaned or fallback)[:limit]


def _load_settings() -> None:
    _state.enabled = system_config.read_flag(ENABLED_KEY, True)
    _state.log_blocked = system_config.read_flag(LOG_KEY, True)
    _state.title = (system_config.read_value(TITLE_KEY) or DEFAULT_TITLE)[:MAX_TITLE_CHARS]
    _state.message = (system_config.read_value(MESSAGE_KEY) or DEFAULT_MESSAGE)[:MAX_MESSAGE_CHARS]
    _state.help_text = (system_config.read_value(HELP_KEY) or DEFAULT_HELP)[:MAX_HELP_CHARS]


def start() -> dict:
    """Startup hook: load the saved settings and parse the bundled list."""
    _load_settings()
    _load_list(force=True)
    if _state.enabled and not _state.image.loaded:
        logger.error(
            "Iran-only access is armed but has no address list: it will NOT reject "
            "anything until %s is readable",
            DATA_PATH,
        )
    elif _state.enabled:
        logger.warning(
            "Iran-only access is armed (%s ipv4 + %s ipv6 ranges)",
            _state.image.v4_count,
            _state.image.v6_count,
        )
    return status()


def apply_settings(data: dict, actor: str = "") -> dict:
    """Validate, persist and apply the settings; raises :class:`IranAccessError`."""
    if not isinstance(data, dict):
        raise IranAccessError("تنظیمات نامعتبر است.")

    title = _clean_text(data.get("title"), MAX_TITLE_CHARS, DEFAULT_TITLE)
    message = _clean_text(data.get("message"), MAX_MESSAGE_CHARS, DEFAULT_MESSAGE)
    help_text = _clean_text(data.get("help_text"), MAX_HELP_CHARS, DEFAULT_HELP)
    if not message:
        raise IranAccessError("متن پیام نمی‌تواند خالی باشد.")

    enabled = system_config.truthy(data.get("enabled", _state.enabled))
    log_blocked = system_config.truthy(data.get("log_blocked", _state.log_blocked))

    writes = [
        (ENABLED_KEY, "1" if enabled else "0"),
        (TITLE_KEY, title),
        (MESSAGE_KEY, message),
        (HELP_KEY, help_text),
        (LOG_KEY, "1" if log_blocked else "0"),
    ]
    failed = [
        key
        for key, value in writes
        if not system_config.write_value(key, value, actor=actor, description=_DESCRIPTIONS.get(key, ""))
    ]

    _load_settings()
    _load_list()
    saved = status()
    saved["saved"] = not failed
    if failed:
        logger.error("iran-only settings not fully saved: %s", ", ".join(failed))
    return saved


# ── Address list maintenance ────────────────────────────────────────────────

def registry_lines(text: str, country: str = COUNTRY) -> dict:
    """Extract one country's allocations from a delegated-statistics file.

    Pure function (no network), so the parsing that rebuilds the shipped list can
    be tested against a fixture instead of an 18 MB download.
    """
    v4: list = []
    v6: list = []
    for line in str(text or "").splitlines():
        parts = line.split("|")
        if len(parts) < 7:
            continue
        if parts[1].strip().upper() != country.upper():
            continue
        if parts[6].strip().lower() not in KEEP_STATUSES:
            continue
        kind = parts[2].strip().lower()
        raw_start = parts[3].strip()
        raw_value = parts[4].strip()
        try:
            if kind == "ipv4":
                count = int(raw_value)
                first = ipaddress.ip_address(raw_start)
                if first.version != 4 or count <= 0:
                    continue
                last = ipaddress.ip_address(int(first) + count - 1)
                if last.version != 4:
                    continue
                v4.append((int(first), int(last)))
            elif kind == "ipv6":
                length = int(raw_value)
                network = ipaddress.ip_network(f"{raw_start}/{length}", strict=False)
                v6.append((int(network.network_address), int(network.broadcast_address)))
        except (ValueError, TypeError):
            continue
    return {"v4": v4, "v6": v6}


def _bounds_to_lines(bounds: list) -> list:
    """Inclusive ``(start, end)`` pairs → the fewest CIDRs that cover them."""
    lines: list = []
    for start, end in sorted(bounds):
        try:
            lines.extend(
                str(network)
                for network in ipaddress.summarize_address_range(
                    ipaddress.ip_address(start), ipaddress.ip_address(end)
                )
            )
        except Exception:  # pragma: no cover - defensive
            continue
    return lines


def render_list(bounds: dict, generated: Optional[str] = None, source: str = "") -> str:
    """Serialise bounds into the on-disk file format."""
    v4_lines = _bounds_to_lines(bounds.get("v4") or [])
    v6_lines = _bounds_to_lines(bounds.get("v6") or [])
    header = [
        "# Iranian IP address ranges — only these public addresses may use the system.",
        "# One CIDR per line; '#' starts a comment.  Generated file, do not edit by hand:",
        "#   python scripts/refresh_iran_ip_ranges.py",
        f"# generated: {generated or datetime.now(timezone.utc).isoformat()}",
        f"# source: {source or ', '.join(REGISTRY_SOURCES)}",
        f"# records: {len(v4_lines)} ipv4, {len(v6_lines)} ipv6",
        "",
    ]
    return "\n".join(header + v4_lines + v6_lines) + "\n"


def _download(url: str) -> str:
    """Fetch one registry file **without** any proxy from the environment.

    The machine may run a VPN whose proxy variables would otherwise decide what
    we download; exactly like the outage probe, the list must come straight from
    the registry.
    """
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    request = urllib.request.Request(url, headers={"User-Agent": "Hastama-IR-Range-Refresh/1.0"})
    with opener.open(request, timeout=DOWNLOAD_TIMEOUT_SECONDS) as response:
        data = response.read(MAX_DOWNLOAD_BYTES)
    if len(data) >= MAX_DOWNLOAD_BYTES:
        raise IranAccessError("پاسخ سرور مرجع بیش از حد بزرگ بود.")
    return data.decode("utf-8", errors="replace")


def update_list_from_registries() -> dict:
    """Download both registries and rewrite the shipped range file.

    Blocking; call :func:`refresh` (async) instead from a request handler.
    """
    combined = {"v4": [], "v6": []}
    failures = []
    for url in REGISTRY_SOURCES:
        try:
            parsed = registry_lines(_download(url), COUNTRY)
        except IranAccessError:
            raise
        except Exception as exc:
            failures.append(f"{type(exc).__name__} @ {url.split('/')[2]}")
            continue
        combined["v4"].extend(parsed["v4"])
        combined["v6"].extend(parsed["v6"])

    if not combined["v4"]:
        detail = ", ".join(failures) or "بدون پاسخ"
        raise IranAccessError(f"دریافت فهرست از سرورهای مرجع ناموفق بود ({detail}).")

    generated = datetime.now(timezone.utc).isoformat()
    body = render_list(combined, generated=generated)

    try:
        DATA_PATH.parent.mkdir(parents=True, exist_ok=True)
        temporary = DATA_PATH.with_suffix(DATA_PATH.suffix + ".tmp")
        temporary.write_text(body, encoding="utf-8")
        os.replace(temporary, DATA_PATH)
    except Exception as exc:
        raise IranAccessError(f"نوشتن فایل فهرست ناموفق بود ({type(exc).__name__}).")

    _load_list(force=True)
    _state.last_refresh = generated
    _state.last_refresh_error = ", ".join(failures)
    result = status()
    result["downloaded_ranges"] = len(combined["v4"]) + len(combined["v6"])
    result["sources_failed"] = failures
    logger.warning(
        "Iranian address list refreshed from the registries: %s ipv4 + %s ipv6",
        result["ranges_ipv4"],
        result["ranges_ipv6"],
    )
    return result


async def refresh() -> dict:
    """Async wrapper: download the registries off the event loop."""
    return await asyncio.to_thread(update_list_from_registries)


def check_ip(ip: str) -> dict:
    """The card's tester: how would *ip* be treated right now?"""
    _load_list()
    verdict = classify(ip)
    verdict["enforcing"] = enforcing()
    verdict["blocked"] = bool(verdict["kind"] == "foreign" and enforcing())
    verdict["list_loaded"] = bool(_state.image.loaded)
    return verdict


def reset_counters() -> dict:
    """Clear the blocked/allowed counters shown in the card."""
    _state.blocked_count = 0
    _state.allowed_count = 0
    _state.last_blocked_ip = ""
    _state.last_blocked_at = None
    _state.last_blocked_path = ""
    _state.recent = {}
    return status()
