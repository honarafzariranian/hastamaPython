"""Server-side silent print of queue tickets / labels.

Modern kiosk flow (best practice):
1. The label studio stores the chosen printer in ``system_config`` (``label_target_printer``)
   so every surface (kiosk, reception, label studio) shares one source of truth —
   not browser-local ``localStorage``.
2. When the kiosk issues a ticket it POSTs to ``/api/queue/print``.
3. This module builds a self-contained HTML receipt using the shared
   ``label-print.css`` presentation (same as master-admin → label printer),
   renders it via Edge headless, and submits the job to the named Windows
   printer queue — no dialog, no user interaction.
4. The client always keeps a browser ``window.print()`` fallback when the server
   path is unavailable (Linux/CUPS, missing Edge, offline spooler, etc.).

Print fidelity path (Windows):
  1. Edge → PNG → ``System.Drawing.Printing`` (full label design).
  2. Edge → PDF → shell PrintTo (when a .pdf PrintTo handler exists).
  3. ``Out-Printer`` plain text (last resort so the patient still gets a ticket).

Only the standard library is used (plus Edge + Windows/.NET print APIs).
``wkhtmltopdf`` / SumatraPDF are optional and never required.
"""
from __future__ import annotations

import html
import logging
import os
import platform
import re
import shutil
import subprocess
import tempfile
import time
from pathlib import Path
from typing import Any, Optional

logger = logging.getLogger(__name__)

#: system_config key holding the selected printer queue name.
CONFIG_KEY = "label_target_printer"

#: system_config key holding the label-studio print settings (JSON).
SETTINGS_CONFIG_KEY = "label_print_settings"

#: Label studio defaults (master-admin.js DEFAULT_LABEL_W/H).
DEFAULT_LABEL_W_MM = 75
DEFAULT_LABEL_H_MM = 81

#: Design reference width in mm (label-print.css is authored for ~50 mm).
_LABEL_REF_W_MM = 50

_TEMPLATE_IDS = frozenset({"queue", "compact", "result", "sampling", "blank"})

_EDGE_CANDIDATES = (
    r"C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files\Microsoft\Edge\Application\msedge.exe",
    r"C:\Program Files (x86)\Microsoft\Edge Beta\Application\msedge.exe",
    r"C:\Program Files\Microsoft\Edge Beta\Application\msedge.exe",
)

#: Bound a headless render so a hung spooler cannot pin a worker forever.
_RENDER_TIMEOUT = 20
_PRINT_TIMEOUT = 15

_APP_ROOT = Path(__file__).resolve().parents[1]
_STATIC_ROOT = _APP_ROOT / "static"

#: Services that print only queue number + admission number (no patient block).
_MINIMAL_SERVICES = frozenset(
    {
        "جوابدهی",
        "نمونه‌گیری",
        "نمونه گیری",
        "نوبت خالی",
        "javabdehi",
        "sampling",
        "result",
        "blank",
    }
)

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


def to_persian_digits(value: Any) -> str:
    text = str(value if value is not None else "")
    return text.translate(str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹"))


def find_edge() -> str:
    """Absolute path to msedge.exe, or ``\"\"`` when Edge is not installed."""
    which = shutil.which("msedge") or shutil.which("msedge.exe")
    if which:
        return which
    for path in _EDGE_CANDIDATES:
        if os.path.isfile(path):
            return path
    return ""


def is_minimal_label_service(service: Any) -> bool:
    """True when the label must only show queue number + admission number."""
    text = str(service or "").strip()
    if text in _MINIMAL_SERVICES:
        return True
    normalized = text.replace("‌", " ").replace("‍", " ")
    normalized = re.sub(r"\s+", " ", normalized).strip()
    return normalized in _MINIMAL_SERVICES


def _read_static_text(relative: str) -> str:
    path = _STATIC_ROOT / relative
    try:
        return path.read_text(encoding="utf-8")
    except OSError:
        logger.warning("Missing static asset for label print: %s", path)
        return ""


def _static_file_uri(relative: str) -> str:
    return (_STATIC_ROOT / relative).as_uri()


def _format_label_datetime() -> str:
    """Persian date/time pill text (Jalali when jdatetime is available)."""
    try:
        import jdatetime

        now = jdatetime.datetime.now()
        return f"{now.strftime('%Y/%m/%d')} - {now.strftime('%H:%M')}"
    except Exception:
        now = time.localtime()
        return f"{time.strftime('%Y/%m/%d', now)} - {time.strftime('%H:%M', now)}"


def _label_zoom(width_mm: int) -> str:
    # Same formula as master-admin.js: design reference is 50 mm wide.
    zoom = max(0.8, min(1.8, float(width_mm) / _LABEL_REF_W_MM))
    return f"{zoom:.3f}"


def default_label_settings() -> dict[str, Any]:
    """Canonical print settings shared by the studio, kiosk, and server."""
    return {
        "width_mm": DEFAULT_LABEL_W_MM,
        "height_mm": DEFAULT_LABEL_H_MM,
        "template": "queue",
        "rotate": False,
        "show_name": True,
        "show_time": True,
        "show_hint": True,
    }


def _clamp_int(value: Any, low: int, high: int, fallback: int) -> int:
    try:
        n = int(round(float(value)))
    except (TypeError, ValueError):
        return fallback
    return max(low, min(high, n))


def _as_bool(value: Any, fallback: bool = True) -> bool:
    if isinstance(value, bool):
        return value
    if value is None:
        return fallback
    if isinstance(value, (int, float)):
        return bool(value)
    text = str(value).strip().lower()
    if text in {"1", "true", "yes", "on"}:
        return True
    if text in {"0", "false", "no", "off"}:
        return False
    return fallback


def normalize_label_settings(raw: Any) -> dict[str, Any]:
    """Coerce studio / system_config payload into the canonical settings shape.

    Accepts both the master-admin localStorage keys (``maLabelWidth`` …) and
    the already-normalized server keys (``width_mm`` …). Missing fields fall
    back to studio defaults so a partial payload never breaks printing.
    """
    import json

    if isinstance(raw, str):
        try:
            raw = json.loads(raw)
        except (TypeError, ValueError):
            raw = None
    if not isinstance(raw, dict):
        raw = {}

    def pick(*keys: str) -> Any:
        for key in keys:
            if key in raw and raw[key] is not None and raw[key] != "":
                return raw[key]
        return None

    width = pick("width_mm", "maLabelWidth")
    height = pick("height_mm", "maLabelHeight")
    template = pick("template", "maLabelTemplate")
    rotate = pick("rotate", "maPrintRotate")
    show_name = pick("show_name", "maShowName")
    show_time = pick("show_time", "maShowTime")
    show_hint = pick("show_hint", "maShowHint")

    tpl = str(template).strip().lower() if template is not None else "queue"
    if tpl not in _TEMPLATE_IDS:
        tpl = "queue"

    return {
        "width_mm": _clamp_int(width, 30, 150, DEFAULT_LABEL_W_MM),
        "height_mm": _clamp_int(height, 20, 100, DEFAULT_LABEL_H_MM),
        "template": tpl,
        "rotate": _as_bool(rotate, False),
        "show_name": _as_bool(show_name, True),
        "show_time": _as_bool(show_time, True),
        "show_hint": _as_bool(show_hint, True),
    }


def load_label_settings() -> dict[str, Any]:
    """Read label-studio print settings from ``system_config`` (best-effort)."""
    try:
        from app.core.database import connect

        conn = connect()
        try:
            cur = conn.cursor()
            cur.execute(
                "SELECT config_value FROM system_config WHERE config_key = ?",
                (SETTINGS_CONFIG_KEY,),
            )
            row = cur.fetchone()
            value = row[0] if row else None
        finally:
            conn.close()
        return normalize_label_settings(value)
    except Exception as exc:
        logger.warning(
            "label_print_settings lookup failed: %s: %s", type(exc).__name__, exc
        )
        return default_label_settings()


def build_ticket_html(
    ticket: dict[str, Any],
    patient: Optional[dict[str, Any]] = None,
    *,
    clinic_name: str = "آزمایشگاه تشخیص طبی دکتر امینی",
    clinic_slogan: str = "همگام با تکنولوژی امروز، به پشتوانه تجربه دیروز",
    width_mm: Optional[int] = None,
    height_mm: Optional[int] = None,
    settings: Any = None,
) -> str:
    """Self-contained label HTML matching master-admin → label-printer.

    Service variants:
      * جوابدهی / نمونه‌گیری / نوبت خالی → queue number + admission number only.
      * پذیرش / اصلاح پذیرش / others → full label studio template.

    ``settings`` (or explicit ``width_mm`` / ``height_mm``) mirror the studio
    print controls: size, template, 90° rotate, and name/time/hint visibility.
    """
    cfg = normalize_label_settings(settings)
    if width_mm is not None:
        cfg["width_mm"] = _clamp_int(width_mm, 30, 150, DEFAULT_LABEL_W_MM)
    if height_mm is not None:
        cfg["height_mm"] = _clamp_int(height_mm, 20, 100, DEFAULT_LABEL_H_MM)
    width_mm = cfg["width_mm"]
    height_mm = cfg["height_mm"]
    template = cfg["template"]
    rotated = cfg["rotate"]
    # Physical page (studio openPrintWindow): swap axes when content is rotated.
    page_w = height_mm if rotated else width_mm
    page_h = width_mm if rotated else height_mm

    patient = patient or {}
    number = esc(
        ticket.get("persian_number")
        or to_persian_digits(ticket.get("number") or "")
        or ""
    )
    service_raw = str(ticket.get("service") or "")
    service = esc(service_raw)
    admission_raw = (
        patient.get("admission_number_persian")
        or patient.get("admission_number")
        or ""
    )
    admission = esc(admission_raw)
    minimal = is_minimal_label_service(service_raw)

    name = esc(patient.get("name") or "—")
    age = esc(to_persian_digits(patient.get("age") or "") or "—")
    national = esc(to_persian_digits(patient.get("national_id") or "") or "—")
    phone = esc(to_persian_digits(patient.get("phone") or "") or "—")
    insurance_base = esc(patient.get("insurance_base") or "—")
    insurance_extra = esc(patient.get("insurance_extra") or "—")
    insurance_track = esc(
        to_persian_digits(
            patient.get("insurance_tracking")
            or patient.get("tracking_code")
            or patient.get("insurance_track")
            or ""
        )
        or "—"
    )

    # Always emit the studio DOM shape (patient / admission data-field hooks)
    # so shared label-print.css template rules apply on server and kiosk prints.
    # Minimal services omit PII rows entirely (privacy) but keep the same groups.
    admission_row = ""
    if admission:
        admission_row = (
            '<div class="lbl__record" data-field="admission">'
            '<span class="lbl__record-label">شماره پذیرش</span>'
            f'<span class="lbl__record-value lbl__record-value--num">{admission}</span></div>'
        )
    if minimal:
        patient_rows = admission_row
        insurance_block = ""
        records_label = "شماره پذیرش"
    else:
        name_row = ""
        if cfg["show_name"]:
            name_row = (
                '<div class="lbl__record" data-field="name">'
                '<span class="lbl__record-label">نام و نام خانوادگی</span>'
                f'<span class="lbl__record-value">{name}</span></div>'
            )
        patient_rows = (
            admission_row
            + name_row
            + '<div class="lbl__record" data-field="age"><span class="lbl__record-label">سن</span>'
            f'<span class="lbl__record-value">{age}</span></div>'
            '<div class="lbl__record" data-field="national"><span class="lbl__record-label">شماره ملی</span>'
            f'<span class="lbl__record-value lbl__record-value--num">{national}</span></div>'
            '<div class="lbl__record" data-field="phone"><span class="lbl__record-label">شماره همراه</span>'
            f'<span class="lbl__record-value lbl__record-value--num">{phone}</span></div>'
        )
        insurance_block = (
            '<div class="lbl__record-group lbl__record-group--insurance" data-field="insurance">'
            '<div class="lbl__record"><span class="lbl__record-label">کد پیگیری بیمه</span>'
            f'<span class="lbl__record-value lbl__record-value--num lbl__record-value--track">{insurance_track}</span></div>'
            '<div class="lbl__record"><span class="lbl__record-label">بیمه پایه</span>'
            f'<span class="lbl__record-value">{insurance_base}</span></div>'
            '<div class="lbl__record"><span class="lbl__record-label">بیمه تکمیلی</span>'
            f'<span class="lbl__record-value">{insurance_extra}</span></div>'
            "</div>"
        )
        records_label = "اطلاعات مراجعه‌کننده و بیمه"

    if patient_rows or insurance_block:
        records = (
            f'<section class="lbl__records" aria-label="{records_label}">'
            '<div class="lbl__record-group lbl__record-group--patient" data-field="patient">'
            + patient_rows
            + "</div>"
            + insurance_block
            + "</section>"
        )
    else:
        records = ""

    label_css = _read_static_text("css/label-print.css")
    font_css = f"""
@font-face {{
  font-family: 'Vazir';
  src: url('{_static_file_uri('fonts/Vazir.woff2')}') format('woff2'),
       url('{_static_file_uri('fonts/Vazir.woff')}') format('woff'),
       url('{_static_file_uri('fonts/Vazir.ttf')}') format('truetype');
  font-weight: 400;
  font-style: normal;
  font-display: block;
}}
"""
    logo_uri = _static_file_uri("images/lab-logo.png")
    brand_uri = _static_file_uri("images/newlogo.png")
    zoom = _label_zoom(width_mm)

    # Content visibility mirrors the studio toggles (maShowName / maShowTime /
    # maShowHint). The shared CSS has `.lbl [hidden] { display:none !important }`.
    time_hidden = "" if cfg["show_time"] else " hidden"
    hint_hidden = "" if cfg["show_hint"] else " hidden"
    rotated_cls = " is-rotated" if rotated else ""
    # Same rotate geometry as master-admin openPrintWindow (left/right explicit
    # so RTL + absolute positioning still lands on the paper).
    rotate_css = (
        ".lbl--print.is-rotated{position:absolute;top:0;left:0;right:auto;margin:0 !important;"
        "transform:translateY(calc(var(--lbl-mm-w,75) * 1mm)) rotate(-90deg);transform-origin:top left}"
        if rotated
        else ""
    )
    # Same content-fit calibration as the studio print window (FIT_GUARD).
    # Width-only zoom leaves tall templates small / clipped on the paper.
    fit_script = (
        "<script>"
        "window.__lblFit=function(){try{"
        'var r=document.querySelector(".lbl");var c=document.querySelector(".lbl__content");if(!r||!c)return;'
        'r.style.setProperty("--lbl-zoom","1");'
        "var ph=c.style.height,pf=c.style.flex,pt=c.style.transform;"
        'c.style.transform="none";c.style.height="auto";c.style.flex="none";'
        "var cw=c.scrollWidth,ch=c.scrollHeight;"
        "c.style.height=ph;c.style.flex=pf;c.style.transform=pt;"
        "var z=Math.min(r.clientWidth/188.98,r.clientHeight/Math.max(1,ch))*0.97;"
        "z=Math.max(0.8,Math.min(1.8,z));"
        'r.style.setProperty("--lbl-zoom",z.toFixed(3));'
        'var n=document.querySelector(".lbl__queue-number");var b=document.querySelector(".lbl__number-box");'
        "if(n&&b){n.style.fontSize=\"\";var base=parseFloat(window.getComputedStyle(n).fontSize)||20;"
        "var nat=n.scrollWidth;var av=b.clientWidth-24;if(nat>av){"
        "n.style.fontSize=Math.max(10,base*av/nat)+\"px\";}}"
        "}catch(e){}};"
        "window.__lblFit();"
        "if(document.fonts&&document.fonts.ready){document.fonts.ready.then(function(){window.__lblFit();});}"
        "</script>"
    )

    return f"""<!DOCTYPE html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8">
<title>نوبت {number}</title>
<style>
{font_css}
{label_css}
@page {{ size: {page_w}mm {page_h}mm; margin: 0; }}
html, body {{ margin: 0; padding: 0; background: #fff; }}
.lbl-page {{
  position: relative;
  width: {page_w}mm;
  height: {page_h}mm;
  overflow: hidden;
}}
.lbl.lbl--print {{
  width: {width_mm}mm;
  height: {height_mm}mm;
  --lbl-mm-w: {width_mm};
  --lbl-mm-h: {height_mm};
  --lbl-zoom: {zoom};
  margin: 0;
  border: 0;
  border-radius: 0;
  box-shadow: none;
  filter: grayscale(1);
}}
{rotate_css}
</style>
</head><body class="lbl-print-page"><div class="lbl-page">
<div class="lbl lbl--print{rotated_cls}" data-template="{template}" data-service="{service}" aria-label="لیبل نوبت">
  <span class="lbl__accent" aria-hidden="true"></span>
  <div class="lbl__content">
    <header class="lbl__header">
      <span class="lbl__datetime" title="تاریخ و ساعت ثبت نوبت"{time_hidden}>
        <span class="lbl__datetime-value">{esc(_format_label_datetime())}</span>
      </span>
      <div class="lbl__identity">
        <img class="lbl__logo" src="{logo_uri}" alt="لوگوی آزمایشگاه">
        <div class="lbl__identity-text">
          <h3 class="lbl__lab-name">{esc(clinic_name)}</h3>
          <p class="lbl__slogan"{hint_hidden}>{esc(clinic_slogan)}</p>
        </div>
      </div>
    </header>
    <section class="lbl__queue" aria-label="شماره نوبت">
      <div class="lbl__number-box">
        <span class="lbl__service">{service or "پذیرش"}</span>
        <span class="lbl__queue-number">{number or to_persian_digits(ticket.get("number") or "")}</span>
      </div>
    </section>
    {records}
    <p class="lbl__message"{hint_hidden}>
      <span class="lbl__message-text">نوبت شما با دقت پیگیری می‌شود؛ به‌محض آماده‌شدن از همین سامانه اعلام خواهد شد.</span>
    </p>
    <footer class="lbl__footer">
      <img class="lbl__brand-logo" src="{brand_uri}" alt="هستما">
      <div class="lbl__brand-text"><strong>سامانه نوبت‌دهی و فراخوان هستما</strong><span>محصولی از شرکت هنر افزار ایرانیان</span></div>
    </footer>
  </div>
</div>
</div>
{fit_script}
</body></html>"""


def _edge_process(args: list[str], timeout: float) -> bool:
    try:
        proc = subprocess.run(
            args,
            capture_output=True,
            text=True,
            timeout=timeout,
            **_NO_WINDOW,
        )
        return proc.returncode == 0
    except Exception as exc:
        logger.warning("Edge process failed: %s: %s", type(exc).__name__, exc)
        return False


def _write_temp_html(html_text: str) -> str:
    handle = tempfile.NamedTemporaryFile(
        "w", suffix=".html", encoding="utf-8", delete=False, dir=tempfile.gettempdir()
    )
    with handle:
        handle.write(html_text)
    return handle.name


def _render_pdf_with_edge(html_text: str, pdf_path: str) -> bool:
    edge = find_edge()
    if not edge:
        return False
    html_path = _write_temp_html(html_text)
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
        if not _edge_process(cmd, _RENDER_TIMEOUT):
            return False
        return os.path.isfile(pdf_path) and os.path.getsize(pdf_path) > 0
    finally:
        try:
            os.unlink(html_path)
        except OSError:
            pass


def _render_png_with_edge(
    html_text: str,
    png_path: str,
    width_mm: int = DEFAULT_LABEL_W_MM,
    height_mm: int = DEFAULT_LABEL_H_MM,
    *,
    dpi: int = 203,
) -> bool:
    """Screenshot the label HTML at thermal-ish resolution for GDI printing.

    CSS lays out in 96 dpi device pixels (``1mm ≈ 3.7795px``). The viewport
    must match that layout, then ``--force-device-scale-factor`` upsamples to
    the thermal DPI. Sizing the window only in physical px (old behaviour)
    left the mm-sized label occupying ~half the bitmap, so GDI stretched a
    mostly-white PNG onto the paper and the print came out the wrong size.
    """
    edge = find_edge()
    if not edge:
        return False
    html_path = _write_temp_html(html_text)
    try:
        # Viewport is CSS px (96 dpi). No artificial floor: a min larger than
        # the mm layout would change the aspect ratio once scaled up.
        css_w = max(80, int(round(width_mm / 25.4 * 96)))
        css_h = max(80, int(round(height_mm / 25.4 * 96)))
        scale = max(1.0, float(dpi) / 96.0)
        profile = Path(tempfile.gettempdir()) / "hastama-edge-label"
        cmd = [
            edge,
            "--headless",
            "--disable-gpu",
            "--no-first-run",
            "--no-default-browser-check",
            f"--user-data-dir={profile}",
            f"--force-device-scale-factor={scale:.6f}",
            f"--window-size={css_w},{css_h}",
            # Let fonts.ready / __lblFit settle before the screenshot.
            "--virtual-time-budget=3000",
            f"--screenshot={png_path}",
            Path(html_path).as_uri(),
        ]
        if not _edge_process(cmd, _RENDER_TIMEOUT):
            return False
        return os.path.isfile(png_path) and os.path.getsize(png_path) > 0
    finally:
        try:
            os.unlink(html_path)
        except OSError:
            pass


def _ps_quote(value: str) -> str:
    """Single-quote a string for PowerShell (escape embedded quotes)."""
    return "'" + str(value).replace("'", "''") + "'"


def _run_powershell(script: str, *, input_text: Optional[str] = None, timeout: int = _PRINT_TIMEOUT) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["powershell", "-NoProfile", "-NonInteractive", "-Command", script],
        input=input_text,
        capture_output=True,
        text=True,
        timeout=timeout,
        encoding="utf-8",
        errors="replace",
        **_NO_WINDOW,
    )


def _print_pdf_windows(pdf_path: str, printer_name: str) -> bool:
    """Submit a PDF to a named Windows queue without showing a dialog."""
    # PrintTo verb: the shell hands the file to the registered handler with the
    # target queue.  Never force exit 0: a missing .pdf association / PrintTo
    # failure must fall through instead of reporting a false success.
    ps = (
        "$ErrorActionPreference = 'Stop'; "
        "try { "
        "Start-Process -FilePath "
        + _ps_quote(pdf_path)
        + " -Verb PrintTo -ArgumentList "
        + _ps_quote(printer_name)
        + " -Wait; exit 0 "
        "} catch { "
        "[Console]::Error.WriteLine($_.Exception.Message); exit 1 "
        "}"
    )
    try:
        proc = _run_powershell(ps, timeout=_PRINT_TIMEOUT)
        if proc.returncode == 0:
            return True
        logger.warning("PrintTo failed rc=%s: %s", proc.returncode, (proc.stderr or "")[:300])
    except Exception as exc:
        logger.warning("Windows PrintTo raised: %s: %s", type(exc).__name__, exc)
    return False


def _print_png_windows(
    png_path: str,
    printer_name: str,
    width_mm: int = DEFAULT_LABEL_W_MM,
    height_mm: int = DEFAULT_LABEL_H_MM,
) -> bool:
    """Print a label PNG via PrintTicket + XpsDocumentWriter (no shell verb).

    Classic ``PrintDocument`` + ``PaperSize`` is unreliable on network/thermal
    queues: the driver keeps its default form (here ``new`` 303×315) and the
    job lands at A4/Letter — content ends up off the 75×81 label.

    Browser studio print works because ``@page { size: … }`` becomes a
    PrintTicket ``PageMediaSize``. This script does the same: WPF
    ``PageMediaSize(Unknown, w*100, h*100)`` (1/100 mm) + draw the PNG into a
    rect of the exact physical size, then ``XpsDocumentWriter.Write``.
    """
    # Temp .ps1: long embedded PowerShell -Command breaks on paths/quotes.
    script = f"""
$ErrorActionPreference = 'Stop'
try {{
  Add-Type -AssemblyName ReachFramework
  Add-Type -AssemblyName System.Printing
  Add-Type -AssemblyName WindowsBase
  Add-Type -AssemblyName PresentationCore
  Add-Type -AssemblyName PresentationFramework
  Add-Type -AssemblyName System.Drawing
  $png = { _ps_quote(png_path) }
  if (-not (Test-Path -LiteralPath $png)) {{ exit 2 }}
  $w = [double]({float(width_mm)} * 100)
  $h = [double]({float(height_mm)} * 100)
  $local = New-Object System.Printing.LocalPrintServer
  $queue = $local.GetPrintQueue({ _ps_quote(printer_name) })
  $ticket = $queue.DefaultPrintTicket
  $pms = New-Object System.Printing.PageMediaSize @(
    [System.Printing.PageMediaSizeName]::Unknown, $w, $h
  )
  $ticket.PageMediaSize = $pms
  try {{ $ticket.PageOrientation = [System.Printing.PageOrientation]::Portrait }} catch {{ }}
  try {{ $ticket.ColorSetting = [System.Printing.PrintColorMode]::Monochrome }} catch {{ }}
  try {{
    $m = New-Object System.Printing.PageMargin
    $m.Left = 0
    $m.Top = 0
    $m.Right = 0
    $m.Bottom = 0
    $ticket.PageMargin = $m
  }} catch {{ }}
  $bi = New-Object System.Windows.Media.Imaging.BitmapImage
  $bi.BeginInit()
  $bi.CacheOption = [System.Windows.Media.Imaging.BitmapCacheOption]::OnLoad
  $bi.UriSource = New-Object Uri($png, [UriKind]::Absolute)
  $bi.EndInit()
  $bi.Freeze()
  $pxW = {float(width_mm)} / 25.4 * 96.0
  $pxH = {float(height_mm)} / 25.4 * 96.0
  $rect = New-Object System.Windows.Rect(0, 0, $pxW, $pxH)
  $drawing = New-Object System.Windows.Media.ImageDrawing($bi, $rect)
  $dv = New-Object System.Windows.Media.DrawingVisual
  $dc = $dv.RenderOpen()
  $dc.DrawDrawing($drawing)
  $dc.Close()
  $writer = [System.Printing.PrintQueue]::CreateXpsDocumentWriter($queue)
  $writer.Write($dv, $ticket)
  exit 0
}} catch {{
  [Console]::Error.WriteLine($_.Exception.Message)
  exit 1
}}
"""
    script_path = os.path.join(tempfile.gettempdir(), f"hastama-print-{os.getpid()}-{int(time.time() * 1000)}.ps1")
    try:
        Path(script_path).write_text(script, encoding="utf-8")
        proc = subprocess.run(
            ["powershell", "-NoProfile", "-NonInteractive", "-ExecutionPolicy", "Bypass", "-File", script_path],
            capture_output=True,
            text=True,
            timeout=_PRINT_TIMEOUT,
            encoding="utf-8",
            errors="replace",
            **_NO_WINDOW,
        )
        if proc.returncode == 0:
            return True
        logger.warning(
            "PNG PrintTicket failed rc=%s: %s | %s",
            proc.returncode,
            (proc.stderr or "")[:400],
            (proc.stdout or "")[:200],
        )
    except Exception as exc:
        logger.warning("PNG print raised: %s: %s", type(exc).__name__, exc)
    finally:
        try:
            os.unlink(script_path)
        except OSError:
            pass
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
        proc = _run_powershell(ps, input_text=text, timeout=_PRINT_TIMEOUT)
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


def _plain_text_fallback(
    ticket: dict[str, Any],
    patient: Optional[dict[str, Any]],
    *,
    minimal: bool,
) -> str:
    patient = patient or {}
    service = str(ticket.get("service") or "")
    number = str(ticket.get("persian_number") or ticket.get("number") or "")
    admission = str(
        patient.get("admission_number_persian") or patient.get("admission_number") or ""
    )
    lines = [service or "پذیرش", f"شماره نوبت: {number}"]
    if admission:
        lines.append(f"شماره پذیرش: {admission}")
    if not minimal:
        name = str(patient.get("name") or "")
        if name:
            lines.append(name)
    lines.append(time.strftime("%Y/%m/%d %H:%M"))
    return "\n".join(lines) + "\n"


def print_ticket_to_printer(
    ticket: dict[str, Any],
    patient: Optional[dict[str, Any]] = None,
    printer_name: str = "",
    *,
    settings: Any = None,
) -> dict[str, Any]:
    """Silently print one queue-ticket label.

    ``settings`` are the shared label-studio print controls (size, template,
    rotate, visibility). When omitted, they are loaded from ``system_config``.

    Returns a JSON-serialisable result:
    ``{"ok": bool, "printer": str, "method": str, "message": str, "settings": dict}``.
    ``ok=False`` means the client should fall back to ``window.print()``.
    """
    printer_name = (printer_name or "").strip()
    cfg = normalize_label_settings(settings) if settings is not None else load_label_settings()
    if not printer_name:
        return {
            "ok": False,
            "printer": "",
            "method": "none",
            "message": "چاپگری در سامانه انتخاب نشده است.",
            "settings": cfg,
        }

    patient = patient or {}
    html_text = build_ticket_html(ticket, patient, settings=cfg)
    minimal = is_minimal_label_service(str(ticket.get("service") or ""))
    is_windows = platform.system() == "Windows"
    # Physical page after optional 90° rotate (axes swapped).
    page_w = cfg["height_mm"] if cfg["rotate"] else cfg["width_mm"]
    page_h = cfg["width_mm"] if cfg["rotate"] else cfg["height_mm"]

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
                return {"ok": True, "printer": printer_name, "method": "edge-lp", "message": "چاپ انجام شد.", "settings": cfg}
        return {
            "ok": False,
            "printer": printer_name,
            "method": "posix",
            "message": "چاپ سمت سرور در این سیستم‌عامل پشتیبانی نمی‌شود؛ از چاپ مرورگر استفاده کنید.",
            "settings": cfg,
        }

    stamp = f"{os.getpid()}-{int(time.time() * 1000)}"
    png_path = os.path.join(tempfile.gettempdir(), f"hastama-ticket-{stamp}.png")
    pdf_path = os.path.join(tempfile.gettempdir(), f"hastama-ticket-{stamp}.pdf")
    try:
        # Preferred: full label design via PNG + GDI (no .pdf PrintTo handler needed).
        if (
            find_edge()
            and _render_png_with_edge(html_text, png_path, page_w, page_h)
            and _print_png_windows(png_path, printer_name, page_w, page_h)
        ):
            return {
                "ok": True,
                "printer": printer_name,
                "method": "edge-png",
                "message": "چاپ انجام شد.",
                "settings": cfg,
            }

        if _render_pdf_with_edge(html_text, pdf_path) and _print_pdf_windows(pdf_path, printer_name):
            return {"ok": True, "printer": printer_name, "method": "edge-printto", "message": "چاپ انجام شد.", "settings": cfg}

        # Degraded path: plain-text job so the patient still gets a ticket.
        plain = _plain_text_fallback(ticket, patient, minimal=minimal)
        if _print_text_windows(plain, printer_name):
            return {
                "ok": True,
                "printer": printer_name,
                "method": "out-printer",
                "message": "چاپ متنی انجام شد (طرح کامل در دسترس نبود).",
                "settings": cfg,
            }

        return {
            "ok": False,
            "printer": printer_name,
            "method": "failed",
            "message": "ارسال شغل چاپ به چاپگر ناموفق بود؛ از چاپ مرورگر استفاده کنید.",
            "settings": cfg,
        }
    finally:
        for path in (png_path, pdf_path):
            try:
                if os.path.isfile(path):
                    os.unlink(path)
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
