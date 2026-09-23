"""Hastama — OS printer discovery for the label studio.

Why this module exists
----------------------
The label studio used to decide whether a label printer was "connected" by
probing ``navigator.usb`` / ``navigator.serial`` in the browser.  For the way we
actually deploy, that test can never succeed:

* WebUSB / WebSerial are only exposed in *secure contexts*.  The LAN build is
  reached over plain HTTP (``http://<lan-ip>:5000``), so ``navigator.usb`` is
  simply ``undefined`` there and the check always reported "printer not found".
* Even on HTTPS those APIs return only the devices the page itself obtained
  through ``requestDevice()``.  A printer installed in Windows — very often a
  *network* queue such as ``\\\\192.168.3.31\\EPSON TM-T88III Receipt`` — is
  invisible to them by design.

The spooler of the machine that runs the server is the only reliable source of
truth, so we enumerate it here and expose it through the master-admin API.  Only
the standard library is used (``winreg`` + PowerShell on Windows, CUPS tools on
POSIX), which keeps the offline installation story unchanged.
"""
from __future__ import annotations

import json
import logging
import platform
import re
import shutil
import subprocess
import threading
import time
from typing import Any, Iterable, Optional

logger = logging.getLogger(__name__)

#: Sub-strings that mark a queue as a *label / receipt* printer.
LABEL_TOKENS = (
    "epson", "tm-t", "tm_t", "tmt", "thermal", "receipt", "label", "zebra",
    "godex", "xprinter", "bixolon", "citizen", "argox", "tsc ", "pos-",
)

#: Queues that are software endpoints, never a physical label printer.
VIRTUAL_TOKENS = (
    "print to pdf", "microsoft xps", "xps document", "onenote", "fax",
    "cutepdf", "pdfwriter", "snagit", "dorsandesk", "virtual", "adobe pdf",
)

#: How long a spooler snapshot stays valid.  Enumeration spawns a process on
#: Windows, so the label studio must not fan out one probe per page load.
_CACHE_TTL_SECONDS = 20.0
_cache_lock = threading.Lock()
_cache: dict[str, Any] = {"at": 0.0, "rows": None}

_POWERSHELL_TIMEOUT = 8

# Win32_Printer.PrinterStatus  →  our normalised state
_STATUS_MAP = {
    2: "unknown",
    3: "ready",
    4: "busy",
    5: "busy",
    6: "paused",
    7: "offline",
}


# ── Helpers ───────────────────────────────────────────────────

def _matches(name: str, tokens: Iterable[str]) -> bool:
    low = (name or "").lower()
    return any(token in low for token in tokens)


def _normalise(name: str) -> str:
    return re.sub(r"\s+", " ", (name or "").strip())


def _no_window_kwargs() -> dict[str, Any]:
    """Keep the PowerShell probe from flashing a console on the server."""
    if platform.system() != "Windows":
        return {}
    kwargs: dict[str, Any] = {"creationflags": getattr(subprocess, "CREATE_NO_WINDOW", 0x08000000)}
    try:
        si = subprocess.STARTUPINFO()
        si.dwFlags |= subprocess.STARTF_USESHOWWINDOW
        kwargs["startupinfo"] = si
    except Exception:  # pragma: no cover - non Windows fallback
        pass
    return kwargs


def _run(cmd: list[str]) -> str:
    proc = subprocess.run(
        cmd,
        capture_output=True,
        text=True,
        timeout=_POWERSHELL_TIMEOUT,
        **_no_window_kwargs(),
    )
    return proc.stdout or ""


# ── Windows ───────────────────────────────────────────────────

def _windows_registry_printers() -> list[dict[str, Any]]:
    """Registry fallback: names only, no live spooler status."""
    import winreg  # type: ignore[import-not-found]

    rows: list[dict[str, Any]] = []
    root = winreg.OpenKey(
        winreg.HKEY_LOCAL_MACHINE,
        r"SYSTEM\CurrentControlSet\Control\Print\Printers",
    )
    try:
        count = winreg.QueryInfoKey(root)[0]
        for index in range(count):
            name = _normalise(winreg.EnumKey(root, index))
            if name:
                rows.append({"name": name, "status": "unknown", "port": "", "driver": ""})
    finally:
        winreg.CloseKey(root)

    default = _windows_registry_default()
    for row in rows:
        row["is_default"] = bool(default) and row["name"].lower() == default.lower()
    return rows


def _windows_registry_default() -> str:
    try:
        import winreg  # type: ignore[import-not-found]

        key = winreg.OpenKey(
            winreg.HKEY_CURRENT_USER,
            r"Software\Microsoft\Windows NT\CurrentVersion\Windows",
        )
        try:
            device = str(winreg.QueryValueEx(key, "Device")[0] or "")
        finally:
            winreg.CloseKey(key)
        return _normalise(device.split(",")[0])
    except Exception:
        return ""


def _windows_spooler_printers() -> list[dict[str, Any]]:
    """Live enumeration through the spooler (name, port, driver, status)."""
    script = (
        "Get-CimInstance Win32_Printer | "
        "Select-Object Name,Default,PrinterStatus,WorkOffline,PortName,DriverName | "
        "ConvertTo-Json -Compress"
    )
    stdout = _run(["powershell", "-NoProfile", "-NonInteractive", "-Command", script]).strip()
    if not stdout:
        return []
    payload = json.loads(stdout)
    if isinstance(payload, dict):
        payload = [payload]

    rows: list[dict[str, Any]] = []
    for item in payload:
        name = _normalise(str(item.get("Name") or ""))
        if not name:
            continue
        offline = bool(item.get("WorkOffline"))
        state = _STATUS_MAP.get(int(item.get("PrinterStatus") or 0), "unknown")
        if offline:
            state = "offline"
        rows.append({
            "name": name,
            "status": state,
            "port": _normalise(str(item.get("PortName") or "")),
            "driver": _normalise(str(item.get("DriverName") or "")),
            "is_default": bool(item.get("Default")),
        })
    return rows


# ── POSIX ─────────────────────────────────────────────────────

def _posix_printers() -> list[dict[str, Any]]:
    default = ""
    if shutil.which("lpstat"):
        try:
            out = _run(["lpstat", "-d"])
            match = re.search(r":\s*(.+)$", out.strip())
            if match:
                default = _normalise(match.group(1))
        except Exception:
            default = ""

    rows: list[dict[str, Any]] = []
    if shutil.which("lpstat"):
        try:
            out = _run(["lpstat", "-p"])
        except Exception:
            out = ""
        for line in out.splitlines():
            match = re.match(r"printer\s+(\S+)\s+is\s+(\S+)", line.strip())
            if not match:
                continue
            name, state = _normalise(match.group(1)), match.group(2).lower()
            rows.append({
                "name": name,
                "status": "offline" if state in ("disabled", "stopped") else "ready",
                "port": "",
                "driver": "",
                "is_default": bool(default) and name == default,
            })
    if not rows and default:
        rows.append({"name": default, "status": "unknown", "port": "", "driver": "", "is_default": True})
    return rows


# ── Public API ────────────────────────────────────────────────

def _decorate(row: dict[str, Any]) -> dict[str, Any]:
    name = row["name"]
    virtual = _matches(name, VIRTUAL_TOKENS)
    return {
        "name": name,
        "status": row.get("status") or "unknown",
        "port": row.get("port") or "",
        "driver": row.get("driver") or "",
        "is_default": bool(row.get("is_default")),
        "is_virtual": virtual,
        "is_label": bool(not virtual and _matches(name, LABEL_TOKENS)),
    }


def raw_printers(force: bool = False) -> list[dict[str, Any]]:
    """Return the spooler's printer queues (cached for a few seconds)."""
    with _cache_lock:
        fresh = (time.monotonic() - _cache["at"]) < _CACHE_TTL_SECONDS
        if _cache["rows"] is not None and fresh and not force:
            return list(_cache["rows"])

    rows: list[dict[str, Any]] = []
    try:
        if platform.system() == "Windows":
            try:
                rows = _windows_spooler_printers()
            except Exception as exc:
                logger.debug("spooler enumeration failed (%s); falling back to registry", exc)
            if not rows:
                rows = _windows_registry_printers()
            if not rows:
                # Last resort: the registry may be unreadable in a locked-down
                # service account, but the default-printer hint often is not.
                default = _windows_registry_default()
                if default:
                    rows = [{"name": default, "status": "unknown", "port": "", "driver": "", "is_default": True}]
        else:
            rows = _posix_printers()
    except Exception as exc:  # pragma: no cover - defensive
        logger.warning("printer enumeration failed: %s: %s", type(exc).__name__, exc)
        rows = []

    with _cache_lock:
        _cache["rows"] = rows
        _cache["at"] = time.monotonic()
    return list(rows)


def describe_printers(force: bool = False) -> dict[str, Any]:
    """Payload consumed by ``GET /master-admin/api/printers``."""
    printers = [_decorate(row) for row in raw_printers(force=force)]
    default = next((p for p in printers if p["is_default"]), None)
    label = pick_label_printer(printers)
    return {
        "platform": platform.system(),
        "host": platform.node(),
        "printers": printers,
        "default_printer": default["name"] if default else "",
        "label_printer": label["name"] if label else "",
        "label_printer_source": ("default" if label and label["is_default"] else "candidate") if label else "",
        "enumerated": len(printers) > 0,
    }


def pick_label_printer(printers: Optional[list[dict[str, Any]]] = None) -> Optional[dict[str, Any]]:
    """Best guess for the queue a queue-number label should be sent to.

    Preference order: the OS default queue when it looks like a label printer,
    then any label-looking queue that is ready, then any label-looking queue.
    """
    rows = printers if printers is not None else [_decorate(r) for r in raw_printers()]
    candidates = [p for p in rows if p.get("is_label")]
    if not candidates:
        return None
    ready = [p for p in candidates if p.get("status") in ("ready", "busy")] or candidates
    for pref in ready:
        if pref.get("is_default"):
            return pref
    return ready[0]
