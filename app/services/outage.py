"""Internet outage mode — detect the loss of the link and take the system there.

The laboratory works through ``https://hastama.ir`` (Cloudflare → tunnel →
``127.0.0.1:5000``).  When the link that carries that path goes down, the system
used to look "up" from the inside while every user got a browser error page.  An
operator can now switch on **outage mode**, which:

    detect    probe the public internet from this host every few seconds
              (configurable target list, interval and failure threshold);
    cut off   terminate the active sessions (optional) so nobody keeps working
              against a system that can no longer serve the internet path;
    show      answer every blocked request with the outage page, which carries
              the laboratory address of this server so work can continue on the
              internal network — see ``app/services/lan_access.py``;
    recover   return to normal automatically after the link is back
              (two consecutive successful probes), and log both transitions.

Never blocked, so the mode can always be managed while it is on:

* requests that arrived on the **laboratory listener** (``lan_access``);
* **direct local** requests (loopback with no forwarding headers: the watchdog's
  ``/health``, the printer side, an administrator on the server itself);
* an authenticated **master administrator** session;
* the infrastructure paths themselves (``/static/``, ``/health``, ``/offline``,
  ``/sw.js``, ``robots.txt``, ``sitemap.xml``, ``favicon.ico``).

The settings live in ``system_config`` and are edited in
``master-admin → system settings``; everything is re-applied at runtime, without a
restart.  See ``docs/network/UNIFIED_URL_ARCHITECTURE.md`` §9c and RR-30.
"""
from __future__ import annotations

import asyncio
import logging
import os
import socket
from datetime import datetime, timezone
from typing import Optional, Sequence

from app.services import lan_access, system_config

logger = logging.getLogger("hastama.outage")

# ── Settings (``system_config`` keys) ───────────────────────────────────────

ENABLED_KEY = "outage_page_enabled"
INTERVAL_KEY = "outage_probe_interval_seconds"
FAILURES_KEY = "outage_probe_failures"
TARGETS_KEY = "outage_probe_targets"
LOGOUT_KEY = "outage_terminate_sessions"
SHOW_LAN_KEY = "outage_show_lan_address"
TITLE_KEY = "outage_title"
MESSAGE_KEY = "outage_message"
MANUAL_KEY = "outage_manual"

_DESCRIPTIONS = {
    ENABLED_KEY: "فعال‌سازی صفحهٔ قطعی اینترنت و قطع خودکار نشست کاربران",
    INTERVAL_KEY: "فاصلهٔ پایش اینترنت (ثانیه)",
    FAILURES_KEY: "تعداد شکست پیاپی برای اعلام قطعی اینترنت",
    TARGETS_KEY: "آدرس‌های پایش اینترنت (host:port با کاما)",
    LOGOUT_KEY: "خروج خودکار کاربران هنگام تشخیص قطعی اینترنت",
    SHOW_LAN_KEY: "نمایش آدرس شبکهٔ داخلی روی صفحهٔ قطعی",
    TITLE_KEY: "عنوان پیام قطعی اینترنت",
    MESSAGE_KEY: "متن پیام قطعی اینترنت",
    MANUAL_KEY: "اعلام دستی حالت قطعی اینترنت",
}

#: Public targets tried in order; the first one that answers means "online".
#: The canonical hostname comes first so the probe tracks the path users use, and
#: two well known public resolvers follow so a single blocked service cannot
#: declare an outage (the mode is conservative on purpose).
DEFAULT_TARGETS = "hastama.ir:443,1.1.1.1:443,8.8.8.8:443"

DEFAULT_TITLE = "ارتباط سامانه با اینترنت قطع شده است"
DEFAULT_MESSAGE = (
    "دسترسی به سامانه از مسیر اینترنت برقرار نیست. اگر در شبکهٔ داخلی آزمایشگاه "
    "هستید، سامانه از آدرس زیر در دسترس است. پس از برقراری اینترنت، این صفحه "
    "به‌صورت خودکار بسته می‌شود."
)

DEFAULT_INTERVAL_SECONDS = 30
DEFAULT_FAILURES = 3
#: Consecutive successful probes that end an outage (avoids flapping).
RECOVERY_SUCCESSES = 2

MIN_INTERVAL_SECONDS, MAX_INTERVAL_SECONDS = 5, 3600
MIN_FAILURES, MAX_FAILURES = 1, 20
MAX_TITLE_CHARS, MAX_MESSAGE_CHARS = 120, 600
PROBE_TIMEOUT_SECONDS = 4.0

#: Paths that keep working while the outage page is being served.
EXEMPT_PREFIXES = (
    "/static/",
    "/offline",
    "/sw.js",
    "/health",
    "/robots.txt",
    "/sitemap.xml",
    "/favicon.ico",
)

#: Paths answered with JSON (an API caller cannot render a page).
JSON_PREFIXES = ("/api/", "/master-admin/api/", "/registration/", "/ticketing/", "/notifications")


class OutageError(RuntimeError):
    """The outage settings are invalid or could not be applied."""


# ── Probing ─────────────────────────────────────────────────────────────────


def parse_targets(raw: str) -> list:
    """``"host:port,host:port"`` → ``[(host, port)]`` (junk entries dropped)."""
    targets: list = []
    for chunk in str(raw or "").split(","):
        item = chunk.strip()
        if not item:
            continue
        host, separator, port_text = item.rpartition(":")
        if not separator:
            continue
        host = host.strip().strip("[]")
        if not host or any(character.isspace() for character in host):
            continue
        if not port_text.strip().isdigit():
            continue
        port = int(port_text)
        if not (1 <= port <= 65535):
            continue
        targets.append((host, port))
    return targets


def probe(targets: Sequence, timeout: float = PROBE_TIMEOUT_SECONDS) -> tuple:
    """Try every target; the first reachable one means "online".

    Returns ``(online, detail)``.  TCP only (``socket`` directly, never an HTTP
    client): the machine's proxy environment variables must not influence the
    verdict, and DNS is resolved by the same call, so a DNS outage counts as an
    outage too.
    """
    failures = []
    for host, port in targets:
        try:
            with socket.create_connection((host, int(port)), timeout=timeout):
                return True, f"{host}:{port}"
        except OSError as exc:
            failures.append(f"{host}:{port} ({type(exc).__name__})")
    return False, ", ".join(failures[:4])


# ── State ───────────────────────────────────────────────────────────────────


def _utcnow() -> datetime:
    return datetime.now(timezone.utc)


def _master_admin_usernames() -> tuple:
    """Accounts kept logged in while the outage lasts (``MASTER_ADMIN_USERNAMES``)."""
    raw = os.getenv("MASTER_ADMIN_USERNAMES", "ali")
    return tuple(item.strip() for item in raw.split(",") if item.strip())


class _OutageState:
    def __init__(self) -> None:
        self.enabled = False
        self.manual = False
        self.terminate_sessions = True
        self.show_lan_address = True
        self.title = DEFAULT_TITLE
        self.message = DEFAULT_MESSAGE
        self.interval = DEFAULT_INTERVAL_SECONDS
        self.threshold = DEFAULT_FAILURES
        self.targets: list = parse_targets(DEFAULT_TARGETS)

        self.active = False
        self.reason = ""
        self.since: Optional[datetime] = None
        self.failures = 0
        self.successes = 0
        self.checked_at: Optional[datetime] = None
        self.detail = ""
        self.terminated_sessions = 0
        self.task: Optional[asyncio.Task] = None


_state = _OutageState()


def active() -> bool:
    """Whether normal (non-exempt) requests must be answered with the outage page."""
    return bool(_state.active)


def status() -> dict:
    """Everything the settings card and the API need to show."""
    return {
        "enabled": _state.enabled,
        "active": _state.active,
        "manual": _state.manual,
        "reason": _state.reason,
        "since": _state.since.isoformat() if _state.since else None,
        "monitoring": bool(_state.task is not None and not _state.task.done()),
        "probe_online": _state.successes > 0,
        "checked_at": _state.checked_at.isoformat() if _state.checked_at else None,
        "detail": _state.detail,
        "failures": _state.failures,
        "threshold": _state.threshold,
        "interval_seconds": _state.interval,
        "targets": ", ".join(f"{host}:{port}" for host, port in _state.targets),
        "target_count": len(_state.targets),
        "recovery_successes": RECOVERY_SUCCESSES,
        "probe_timeout_seconds": PROBE_TIMEOUT_SECONDS,
        "terminate_sessions": _state.terminate_sessions,
        "show_lan_address": _state.show_lan_address,
        "title": _state.title,
        "message": _state.message,
        "terminated_sessions": _state.terminated_sessions,
        "master_admin_accounts": list(_master_admin_usernames()),
    }


def page_context() -> dict:
    """Context for ``offline.html`` (also used for the cached browser copy)."""
    lan = lan_access.status()
    address = lan.get("expected_url") or ""
    return {
        "outage_title": _state.title or DEFAULT_TITLE,
        "outage_message": _state.message or DEFAULT_MESSAGE,
        "outage_active": bool(_state.active),
        "outage_manual": bool(_state.manual),
        "lan_url": address if _state.show_lan_address else "",
        "lan_enabled": bool(lan.get("running")),
        "retry_seconds": max(5, min(_state.interval, 60)),
    }


# ── Transitions ─────────────────────────────────────────────────────────────


def _audit(action: str, status_value: str, severity: str, metadata: dict) -> None:
    try:
        from app.services.audit import log_event

        log_event(
            event_type="SYSTEM",
            action=action,
            module="connectivity",
            resource_type="internet_link",
            status=status_value,
            severity=severity,
            metadata=metadata,
        )
    except Exception:  # pragma: no cover - auditing must never break the gate
        pass


def _terminate_everyone() -> int:
    try:
        from app.core.sessions import revoke_all_sessions

        return int(revoke_all_sessions("outage-monitor", _master_admin_usernames()) or 0)
    except Exception as exc:  # pragma: no cover - defensive
        logger.warning("outage session cut-off failed: %s: %s", type(exc).__name__, exc)
        return 0


def _recompute_active() -> None:
    """Apply the state machine: enabled + (manual or enough failures)."""
    should = bool(_state.enabled and (_state.manual or _state.failures >= _state.threshold))
    if should == _state.active:
        return

    if should:
        _state.active = True
        _state.since = _utcnow()
        _state.reason = "manual" if _state.manual else f"{_state.failures} failed probes"
        _state.terminated_sessions = _terminate_everyone() if _state.terminate_sessions else 0
        logger.error(
            "INTERNET OUTAGE MODE ON (%s); sessions terminated: %s; last probe: %s",
            _state.reason,
            _state.terminated_sessions,
            _state.detail or "-",
        )
        _audit(
            "internet_outage_detected",
            "failure",
            "high",
            {
                "reason": _state.reason,
                "failures": _state.failures,
                "targets": [f"{host}:{port}" for host, port in _state.targets],
                "detail": _state.detail,
                "sessions_terminated": _state.terminated_sessions,
                "master_admin_accounts_kept": list(_master_admin_usernames()),
            },
        )
        return

    duration = int((_utcnow() - _state.since).total_seconds()) if _state.since else 0
    logger.warning("internet outage over after %ss; normal service resumed", duration)
    _state.active = False
    _state.reason = ""
    _state.since = None
    _state.terminated_sessions = 0
    _audit(
        "internet_outage_recovered",
        "success",
        "info",
        {"duration_seconds": duration, "detail": _state.detail},
    )


def _apply_probe_result(online: bool, detail: str, now: Optional[datetime] = None) -> None:
    _state.checked_at = now or _utcnow()
    _state.detail = detail
    if online:
        _state.successes += 1
        # While an outage is on, more than one success is required to end it.
        if not _state.active or _state.successes >= RECOVERY_SUCCESSES:
            _state.failures = 0
    else:
        _state.successes = 0
        _state.failures += 1
    _recompute_active()


async def check_now() -> dict:
    """Probe once (in a worker thread) and apply the result immediately."""
    targets = _state.targets or parse_targets(DEFAULT_TARGETS)
    try:
        online, detail = await asyncio.to_thread(probe, targets)
    except Exception as exc:  # pragma: no cover - defensive
        online, detail = False, f"probe error: {type(exc).__name__}"
    _apply_probe_result(online, detail)
    return status()


# ── Monitor lifecycle ───────────────────────────────────────────────────────


async def _monitor_loop() -> None:
    logger.warning("internet-outage monitor started (every %ss)", _state.interval)
    try:
        while True:
            try:
                await check_now()
            except asyncio.CancelledError:
                raise
            except Exception:  # pragma: no cover - the loop must survive anything
                logger.exception("outage probe failed")
            await asyncio.sleep(max(MIN_INTERVAL_SECONDS, _state.interval))
    except asyncio.CancelledError:
        logger.warning("internet-outage monitor stopped")
        raise


async def _sync_monitor() -> None:
    running = _state.task is not None and not _state.task.done()
    if _state.enabled and not running:
        _state.task = asyncio.create_task(_monitor_loop())
    elif not _state.enabled and running:
        _state.task.cancel()
        try:
            await _state.task
        except (asyncio.CancelledError, Exception):  # pragma: no cover - shutdown
            pass
        _state.task = None


def _load_settings() -> None:
    _state.enabled = system_config.read_flag(ENABLED_KEY, True)
    _state.manual = system_config.read_flag(MANUAL_KEY, False)
    _state.terminate_sessions = system_config.read_flag(LOGOUT_KEY, True)
    _state.show_lan_address = system_config.read_flag(SHOW_LAN_KEY, True)
    _state.title = (
        system_config.read_value(TITLE_KEY) or DEFAULT_TITLE
    )[:MAX_TITLE_CHARS]
    _state.message = (
        system_config.read_value(MESSAGE_KEY) or DEFAULT_MESSAGE
    )[:MAX_MESSAGE_CHARS]
    _state.interval = system_config.read_int(
        INTERVAL_KEY, DEFAULT_INTERVAL_SECONDS, minimum=MIN_INTERVAL_SECONDS, maximum=MAX_INTERVAL_SECONDS
    )
    _state.threshold = system_config.read_int(
        FAILURES_KEY, DEFAULT_FAILURES, minimum=MIN_FAILURES, maximum=MAX_FAILURES
    )
    targets = parse_targets(system_config.read_value(TARGETS_KEY) or DEFAULT_TARGETS)
    _state.targets = targets or parse_targets(DEFAULT_TARGETS)


async def start() -> None:
    """Startup hook: load the saved settings, start the monitor, apply the state."""
    _load_settings()
    _recompute_active()
    await _sync_monitor()
    if _state.enabled:
        logger.warning(
            "outage mode armed (interval %ss, %s failures, targets: %s)",
            _state.interval,
            _state.threshold,
            status()["targets"],
        )


async def stop() -> None:
    """Shutdown hook: stop probing (the state itself is persisted)."""
    task, _state.task = _state.task, None
    if task is not None and not task.done():
        task.cancel()
        try:
            await task
        except (asyncio.CancelledError, Exception):  # pragma: no cover - shutdown
            pass


async def reload() -> dict:
    """Re-read the settings after they were saved and apply them at once."""
    _load_settings()
    _recompute_active()
    await _sync_monitor()
    return status()


# ── Settings are written from the master-admin API ──────────────────────────


def _clean_text(value, limit: int, fallback: str) -> str:
    text = " ".join(str(value or "").split())
    return (text or fallback)[:limit]


async def apply_settings(data: dict, actor: str = "") -> dict:
    """Validate, persist and apply the outage settings; raises on bad input."""
    if not isinstance(data, dict):
        raise OutageError("تنظیمات نامعتبر است.")

    title = _clean_text(data.get("title"), MAX_TITLE_CHARS, DEFAULT_TITLE)
    message = _clean_text(data.get("message"), MAX_MESSAGE_CHARS, DEFAULT_MESSAGE)

    try:
        interval = int(data.get("interval_seconds", _state.interval))
    except (TypeError, ValueError):
        raise OutageError("فاصلهٔ پایش باید عدد باشد.")
    if not (MIN_INTERVAL_SECONDS <= interval <= MAX_INTERVAL_SECONDS):
        raise OutageError(
            f"فاصلهٔ پایش باید بین {MIN_INTERVAL_SECONDS} و {MAX_INTERVAL_SECONDS} ثانیه باشد."
        )

    try:
        threshold = int(data.get("failures", _state.threshold))
    except (TypeError, ValueError):
        raise OutageError("تعداد شکست پیاپی باید عدد باشد.")
    if not (MIN_FAILURES <= threshold <= MAX_FAILURES):
        raise OutageError(f"تعداد شکست پیاپی باید بین {MIN_FAILURES} و {MAX_FAILURES} باشد.")

    targets = parse_targets(data.get("targets", status()["targets"]))
    if not targets:
        raise OutageError("حداقل یک آدرس پایش معتبر (مثل 1.1.1.1:443) لازم است.")

    enabled = system_config.truthy(data.get("enabled", _state.enabled))
    terminate = system_config.truthy(data.get("terminate_sessions", _state.terminate_sessions))
    show_lan = system_config.truthy(data.get("show_lan_address", _state.show_lan_address))

    target_text = ", ".join(f"{host}:{port}" for host, port in targets)
    writes = [
        (ENABLED_KEY, "1" if enabled else "0"),
        (INTERVAL_KEY, str(interval)),
        (FAILURES_KEY, str(threshold)),
        (TARGETS_KEY, target_text),
        (LOGOUT_KEY, "1" if terminate else "0"),
        (SHOW_LAN_KEY, "1" if show_lan else "0"),
        (TITLE_KEY, title),
        (MESSAGE_KEY, message),
    ]
    failed = [
        key
        for key, value in writes
        if not system_config.write_value(key, value, actor=actor, description=_DESCRIPTIONS.get(key, ""))
    ]

    saved = await reload()
    saved["saved"] = not failed
    if failed:
        logger.error("outage settings not fully saved: %s", ", ".join(failed))
    return saved


async def set_manual(active: bool, actor: str = "") -> dict:
    """Declare the outage by hand (or clear it) — independent of the probes."""
    system_config.write_flag(
        MANUAL_KEY, active, actor=actor, description=_DESCRIPTIONS[MANUAL_KEY]
    )
    _state.manual = bool(active)
    if active:
        _state.failures = 0
        _state.successes = 0
    _recompute_active()
    return status()


def request_is_subject(scope: dict) -> bool:
    """Whether *scope* must see the outage page (i.e. it is not exempt).

    Exempt: the laboratory listener, a direct local request (loopback without
    forwarding headers — the watchdog, the printer side, an administrator on the
    server itself) and the infrastructure paths.  A master-administrator session
    is exempted by the gate in ``app.main``, which owns the session secret.

    An internet user always arrives through ``cloudflared`` on loopback *with*
    forwarding headers, so "local" can never be claimed by rewriting a header.
    """
    path = scope.get("path", "") or ""
    if any(path.startswith(prefix) for prefix in EXEMPT_PREFIXES):
        return False

    if lan_access.request_is_lan_http(scope):
        return False

    headers = dict(scope.get("headers", []))
    peer = (scope.get("client") or ("", 0))[0]
    from app.core.net import trusted_proxies

    if str(peer) in trusted_proxies():
        if not headers.get(b"x-forwarded-for") and not headers.get(b"x-forwarded-proto"):
            return False  # a direct local caller, not the tunnel

    return True
