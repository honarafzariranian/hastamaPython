"""Server-side silent print of queue tickets / labels.

Modern kiosk flow (best practice):
1. The label studio stores the chosen printer in ``system_config`` (``label_target_printer``)
   so every surface (kiosk, reception, label studio) shares one source of truth —
   not browser-local ``localStorage``.
2. When the kiosk issues a ticket it POSTs to ``/api/queue/print-ticket``.
3. This module builds a self-contained HTML receipt, renders it to a PDF via
   Edge headless (present on every Windows 10/11 install), and submits that PDF
   to the named Windows printer queue — no dialog, no user interaction.
4. The client always keeps a browser ``window.print()`` fallback when the server
   path is unavailable (Linux/CUPS, missing Edge, offline spooler, etc.).

Only the standard library is used (plus Edge).  ``wkhtmltopdf`` / SumatraPDF
are optional and never required.
"""
from __future__ import annotations

import html
import logging
import os
import platform
import shutil
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any, Optional

logger = logging.getLogger(__name__)

#: system_config key holding the selected printer queue name.
CONFIG_KEY = "label_target_printer"

_EDGE_CANDIDATES = (
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files (x86)\Microsoft\Edge Beta\Application\msedge.exe",
    r"C:\Program Files\Microsoft\Edge Beta\Application\msedge.exe",
)

#: Bound a headless render so a hung spooler cannot pin a worker forever.
_RENDER_TIMEOUT = 20
_PRINT_TIMEOUT = 15

_NO_WINDOW: dict[str, Any] = {}
if platform.system() == "Windows":
    _NO_WINDOW["creationflags"] = getattr(subprocess, "CREATE_NO_WINDOW", 0x08000000)
    try:
        _si = subprocess.STARTUPINFO()
        _si.dwFlags |= subprocess.STARTF_USESHOWWINDOW
        _NO_WINDOW["startupinfo"] = _si
    except Exception:  # pragma: no cover - non-Windows fallback
        pass


def esc(value: Any) -> str:
    return html.escape(str(value if value is not None else ""), quote=True)


def find_edge() -> str:
    """Absolute path to msedge.exe, or ``\"\"`` when Edge is not installed."""
    which = shutil.which("msedge") or shutil.which("msedge.exe")
    if which:
        return which
    for path in _EDGE_CANDIDATES:
        if os.path.isfile(path):
            return path
    return ""


def build_ticket_html(
    ticket: dict[str, Any],
    patient: Optional[dict[str, Any]] = None,
    *,
    clinic_name: str = "آزمایشگاه تشخیص طبی دکتر امینی",
) -> str:
    """Self-contained 70×50 mm receipt HTML (matches the kiosk printTicket design)."""
    patient = patient or {}
    number = esc(ticket.get("persian_number") or ticket.get("number") or "")
    service = esc(ticket.get("service") or "")
    admission = patient.get("admission_number") or patient.get("admission_number_persian") or ""
    name = esc(patient.get("name") or "")
    admission_html = ""
    if admission:
        admission_html = (
            f'<div class="admission">شماره پذیرش: {esc(admission)}</div>'
        )
    name_html = f'<div class="name">{name}</div>' if name else ""
    # Prefer server clock (UTC-naive local) so the receipt never depends on kiosk TZ.
    now = time.localtime()
    date_str = time.strftime("%Y/%m/%d", now)
    time_str = time.strftime("%H:%M", now)
    return f"""<!DOCTYPE html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<title>نوبت {number}</title>
<style>
@page {{ size: 70mm 50mm; margin: 3mm; }}
* {{ box-sizing: border-box; }}
body {{
  font-family: Tahoma, Arial, sans-serif;
  direction: rtl; text-align: center;
  padding: 0; margin: 0;
  color: #111; background: #fff;
}}
.header {{ font-size: 8pt; font-weight: bold; margin-bottom: 1.5mm;
  border-bottom: 1px dashed #333; padding-bottom: 1.5mm; }}
.number {{ font-size: 28pt; font-weight: 900; margin: 1.5mm 0; line-height: 1.1; }}
.service {{ font-size: 9pt; color: #444; margin-bottom: 1mm; }}
.admission {{ font-size: 8pt; font-weight: bold; margin: 1mm 0; }}
.name {{ font-size: 8pt; color: #333; margin: 0.5mm 0; }}
.date {{ font-size: 7pt; color: #666; margin-top: 1.5mm;
  border-top: 1px dashed #333; padding-top: 1.5mm; }}
</style></head><body>
<div class="header">{esc(clinic_name)}</div>
<div class="service">{service}</div>
{admission_html}
{name_html}
<div class="number">{number}</div>
<div class="date">{date_str} — {time_str}</div>
</body></html>"""


def _render_pdf_with_edge(html_text: str, pdf_path: str) -> bool:
    edge = find_edge()
    if not edge:
        return False
    with tempfile.NamedTemporaryFile(
        "w", suffix=".html", encoding="utf-8", delete=False, dir=tempfile.gettempdir()
    ) as handle:
        handle.write(html_text)
        html_path = handle.name
    try:
        cmd = [
            edge,
            "--headless",
            "--disable-gpu",
            "--no-first-run",
            "--no-default-browser-check",
            f"--print-to-pdf={pdf_path}",
            "--no-pdf-header-footer",
            Path(html_path).as_uri(),
        ]
        proc = subprocess.run(
            cmd,
            capture_output=True,
            text=True,
            timeout=_RENDER_TIMEOUT,
            **_NO_WINDOW,
        )
        return proc.returncode == 0 and os.path.isfile(pdf_path) and os.path.getsize(pdf_path) > 0
    except Exception as exc:
        logger.warning("Edge PDF render failed: %s: %s", type(exc).__name__, exc)
        return False
    finally:
        try:
            os.unlink(html_path)
        except OSError:
            pass


def _ps_quote(value: str) -> str:
    """Single-quote a string for PowerShell (escape embedded quotes)."""
    return "'" + str(value).replace("'", "''") + "'"


def _print_pdf_windows(pdf_path: str, printer_name: str) -> bool:
    """Submit a PDF to a named Windows queue without showing a dialog."""
    # PrintTo verb: the shell hands the file to the registered handler with the
    # target queue.  Paths are embedded (quoted) — passing bare extra argv to
    # ``powershell -Command`` breaks on spaces / TEMP short names.
    ps = (
        "Start-Process -FilePath "
        + _ps_quote(pdf_path)
        + " -Verb PrintTo -ArgumentList "
        + _ps_quote(printer_name)
        + " -Wait; exit 0"
    )
    try:
        proc = subprocess.run(
            ["powershell", "-NoProfile", "-NonInteractive", "-Command", ps],
            capture_output=True,
            text=True,
            timeout=_PRINT_TIMEOUT,
            encoding="utf-8",
            errors="replace",
            **_NO_WINDOW,
        )
        if proc.returncode == 0:
            return True
        logger.warning("PrintTo failed rc=%s: %s", proc.returncode, (proc.stderr or "")[:300])
    except Exception as exc:
        logger.warning("Windows PrintTo raised: %s: %s", type(exc).__name__, exc)
    return False


def _print_text_windows(text: str, printer_name: str) -> bool:
    """Last-resort plain-text job via Out-Printer (no PDF / no Edge needed)."""
    # UTF-8 on both sides: Persian ticket text must survive the pipe (default
    # cp1252 console codepage raises UnicodeEncodeError on fa characters).
    ps = (
        "[Console]::OutputEncoding = [Text.Encoding]::UTF8; "
        "$utf8 = New-Object Text.UTF8Encoding $false; "
        "$Input | Out-Printer -Name " + _ps_quote(printer_name)
    )
    try:
        proc = subprocess.run(
            ["powershell", "-NoProfile", "-NonInteractive", "-Command", ps],
            input=text,
            capture_output=True,
            text=True,
            timeout=_PRINT_TIMEOUT,
            encoding="utf-8",
            errors="replace",
            **_NO_WINDOW,
        )
        return proc.returncode == 0
    except Exception as exc:
        logger.warning("Out-Printer failed: %s: %s", type(exc).__name__, exc)
        return False


def _print_pdf_posix(pdf_path: str, printer_name: str) -> bool:
    lp = shutil.which("lp")
    if not lp:
        return False
    try:
        cmd = [lp, "-d", printer_name, pdf_path] if printer_name else [lp, pdf_path]
        proc = subprocess.run(cmd, capture_output=True, text=True, timeout=_PRINT_TIMEOUT)
        return proc.returncode == 0
    except Exception as exc:
        logger.warning("lp failed: %s: %s", type(exc).__name__, exc)
        return False


def print_ticket_to_printer(
    ticket: dict[str, Any],
    patient: Optional[dict[str, Any]] = None,
    printer_name: str = "",
) -> dict[str, Any]:
    """Silently print one queue-ticket receipt.

    Returns a JSON-serialisable result:
    ``{"ok": bool, "printer": str, "method": str, "message": str}``.
    ``ok=False`` means the client should fall back to ``window.print()``.
    """
    printer_name = (printer_name or "").strip()
    if not printer_name:
        return {
            "ok": False,
            "printer": "",
            "method": "none",
            "message": "چاپگری در سامانه انتخاب نشده است.",
        }

    html_text = build_ticket_html(ticket, patient)
    is_windows = platform.system() == "Windows"

    if not is_windows:
        # POSIX: still try lp with a temp PDF if Edge exists; otherwise fail soft.
        pdf_path = os.path.join(tempfile.gettempdir(), f"hastama-ticket-{os.getpid()}.pdf")
        if find_edge() and _render_pdf_with_edge(html_text, pdf_path):
            ok = _print_pdf_posix(pdf_path, printer_name)
            try:
                os.unlink(pdf_path)
            except OSError:
                pass
            if ok:
                return {"ok": True, "printer": printer_name, "method": "edge-lp", "message": "چاپ انجام شد."}
        return {
            "ok": False,
            "printer": printer_name,
            "method": "posix",
            "message": "چاپ سمت سرور در این سیستم‌عامل پشتیبانی نمی‌شود؛ از چاپ مرورگر استفاده کنید.",
        }

    pdf_path = os.path.join(tempfile.gettempdir(), f"hastama-ticket-{os.getpid()}-{int(time.time()*1000)}.pdf")
    try:
        if _render_pdf_with_edge(html_text, pdf_path) and _print_pdf_windows(pdf_path, printer_name):
            return {"ok": True, "printer": printer_name, "method": "edge-printto", "message": "چاپ انجام شد."}

        # Degraded path: plain-text job so the patient still gets a ticket.
        plain = (
            f"{ticket.get('service') or ''}\n"
            f"شماره نوبت: {ticket.get('persian_number') or ticket.get('number') or ''}\n"
            f"{patient.get('name') or ''}\n"
            f"{time.strftime('%Y/%m/%d %H:%M')}\n"
        )
        if _print_text_windows(plain, printer_name):
            return {
                "ok": True,
                "printer": printer_name,
                "method": "out-printer",
                "message": "چاپ متنی انجام شد (طرح کامل در دسترس نبود).",
            }

        return {
            "ok": False,
            "printer": printer_name,
            "method": "failed",
            "message": "ارسال شغل چاپ به چاپگر ناموفق بود؛ از چاپ مرورگر استفاده کنید.",
        }
    finally:
        try:
            if os.path.isfile(pdf_path):
                os.unlink(pdf_path)
        except OSError:
            pass


def resolve_printer_name(config_value: str = "", *, prefer_label_pick: bool = True) -> str:
    """Choose the queue to print to: explicit config first, then label heuristic."""
    name = (config_value or "").strip()
    if name:
        return name
    if not prefer_label_pick:
        return ""
    try:
        from app.services.printer import pick_label_printer

        picked = pick_label_printer()
        return str(picked["name"]) if picked else ""
    except Exception as exc:  # pragma: no cover - discovery optional
        logger.debug("pick_label_printer failed: %s", exc)
        return ""
