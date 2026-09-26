"""Single renderer for the queue label markup and the print document.

Markup lives in Jinja so every surface renders the *same DOM*:

* ``partials/label_queue.html``      → the label itself (preview + print)
* ``label_print_document.html``      → the standalone print document
                                       (silent server print + browser fallback)

Both the master-admin label studio and the ticket kiosk go through this module
(on the server) or through ``app/static/js/label-system.js`` (in the browser),
so there is exactly one place to change the label design, one sizing rule and
one print document.
"""
from __future__ import annotations

import html as _html
import logging
import re
import time
from pathlib import Path
from typing import Any, Mapping, Optional

from jinja2 import Environment, FileSystemLoader, select_autoescape

logger = logging.getLogger(__name__)

_APP_ROOT = Path(__file__).resolve().parents[1]
_TEMPLATES_DIR = _APP_ROOT / "templates"
_STATIC_ROOT = _APP_ROOT / "static"

#: Design reference width in mm (``label-print.css`` is authored for ~50 mm).
#: Mirrored in ``app/static/js/label-system.js`` (REF_WIDTH_MM).
LABEL_REF_WIDTH_MM = 50

#: Zoom clamp shared by the renderer and the browser fit function.
LABEL_ZOOM_MIN = 0.8
LABEL_ZOOM_MAX = 1.8

PAGE_LABEL_TEMPLATE = "partials/label_queue.html"
PRINT_DOCUMENT_TEMPLATE = "label_print_document.html"

#: Services that print only the queue number + admission number (no patient
#: block) — mirrors ``is_minimal_label_service`` in label-system.js.
MINIMAL_LABEL_SERVICES = frozenset(
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

_env = Environment(
    loader=FileSystemLoader(str(_TEMPLATES_DIR)),
    autoescape=select_autoescape(["html", "xml"]),
)

#: Default images; the pages use plain static paths, the server uses file URIs.
DEFAULT_ASSETS: dict[str, str] = {
    "logo": "/static/images/lab-logo.png",
    "brand": "/static/images/newlogo.png",
}


# ── Small pure helpers (re-exported by ticket_print for compatibility) ──────


def esc(value: Any) -> str:
    """HTML-escape a value (same contract as the old ticket_print.esc)."""
    return _html.escape(str(value if value is not None else ""), quote=True)


def to_persian_digits(value: Any) -> str:
    text = str(value if value is not None else "")
    return text.translate(str.maketrans("0123456789", "۰۱۲۳۴۵۶۷۸۹"))


def is_minimal_label_service(service: Any) -> bool:
    """True when the label must only show queue number + admission number."""
    text = str(service or "").strip()
    if text in MINIMAL_LABEL_SERVICES:
        return True
    normalized = text.replace("‌", " ").replace("‍", " ")
    normalized = re.sub(r"\s+", " ", normalized).strip()
    return normalized in MINIMAL_LABEL_SERVICES


def format_label_datetime() -> str:
    """Persian date/time pill text (Jalali when jdatetime is available)."""
    try:
        import jdatetime

        now = jdatetime.datetime.now()
        return f"{now.strftime('%Y/%m/%d')} - {now.strftime('%H:%M')}"
    except Exception:
        now = time.localtime()
        return f"{time.strftime('%Y/%m/%d', now)} - {time.strftime('%H:%M', now)}"


def static_text(relative: str) -> str:
    """Read a static asset as text (CSS/JS are inlined into the print document)."""
    path = _STATIC_ROOT / relative
    try:
        return path.read_text(encoding="utf-8")
    except OSError:
        logger.warning("Missing static asset for label print: %s", path)
        return ""


def static_uri(relative: str) -> str:
    """``file://`` URI for a static asset (headless Edge renders from disk)."""
    return (_STATIC_ROOT / relative).as_uri()


def font_css(url_for_asset=None) -> str:
    """@font-face rules for the label font, pointing at the given URL builder."""
    build = url_for_asset or static_uri
    return (
        "@font-face {\n"
        "  font-family: 'Vazir';\n"
        f"  src: url('{build('fonts/Vazir.woff2')}') format('woff2'),\n"
        f"       url('{build('fonts/Vazir.woff')}') format('woff'),\n"
        f"       url('{build('fonts/Vazir.ttf')}') format('truetype');\n"
        "  font-weight: 400;\n"
        "  font-style: normal;\n"
        "  font-display: block;\n"
        "}\n"
    )


def zoom_seed(width_mm: float) -> str:
    """First guess for ``--lbl-zoom`` before the fit script measures the content."""
    try:
        width = float(width_mm)
    except (TypeError, ValueError):
        width = float(LABEL_REF_WIDTH_MM)
    zoom = max(LABEL_ZOOM_MIN, min(LABEL_ZOOM_MAX, width / LABEL_REF_WIDTH_MM))
    return f"{zoom:.3f}"


def page_size(width_mm: int, height_mm: int, rotated: bool) -> tuple[int, int]:
    """Physical paper size: axes swap when the design is rotated 90°."""
    return (height_mm, width_mm) if rotated else (width_mm, height_mm)


_ROTATE_CSS = (
    ".lbl--print.is-rotated{position:absolute;top:0;left:0;right:auto;margin:0 !important;"
    "transform:translateY(calc(var(--lbl-mm-w,75) * 1mm)) rotate(-90deg);transform-origin:top left}"
)


def rotate_css(rotated: bool) -> str:
    """Rotation geometry for the print document (RTL-safe, starts at 0,0)."""
    return _ROTATE_CSS if rotated else ""


# ── Data shaping ───────────────────────────────────────────────────────────


def label_context(
    ticket: Optional[Mapping[str, Any]] = None,
    patient: Optional[Mapping[str, Any]] = None,
    *,
    datetime_text: Optional[str] = None,
    clinic_name: str = "آزمایشگاه تشخیص طبی دکتر امینی",
    clinic_slogan: str = "همگام با تکنولوژی امروز، به پشتوانه تجربه دیروز",
) -> dict[str, Any]:
    """Flatten a ticket + patient into the canonical label data shape.

    This is the single mapping used by the server print, the kiosk and the
    studio sample print; the browser mirror lives in ``label-system.js``
    (``HastamaLabel.labelData``).
    """
    ticket = dict(ticket or {})
    patient = dict(patient or {})

    number_raw = ticket.get("persian_number") or to_persian_digits(ticket.get("number") or "")
    admission_raw = (
        patient.get("admission_number_persian")
        or patient.get("admission_number")
        or ""
    )
    track_raw = (
        patient.get("insurance_tracking")
        or patient.get("tracking_code")
        or patient.get("insurance_track")
        or ""
    )
    return {
        "service": str(ticket.get("service") or "پذیرش"),
        "number": str(number_raw or ""),
        "admission": str(admission_raw or ""),
        "name": str(patient.get("name") or "—"),
        "age": to_persian_digits(patient.get("age") or "") or "—",
        "national_id": to_persian_digits(patient.get("national_id") or "") or "—",
        "phone": to_persian_digits(patient.get("phone") or "") or "—",
        "insurance_track": to_persian_digits(track_raw) or "—",
        "insurance_base": str(patient.get("insurance_base") or "—"),
        "insurance_extra": str(patient.get("insurance_extra") or "—"),
        "datetime": datetime_text if datetime_text is not None else format_label_datetime(),
        "clinic_name": clinic_name,
        "clinic_slogan": clinic_slogan,
    }


# ── Rendering ──────────────────────────────────────────────────────────────


def render_label_markup(
    *,
    label: Mapping[str, Any],
    settings: Optional[Mapping[str, Any]] = None,
    minimal: bool = False,
    assets: Optional[Mapping[str, str]] = None,
    root_class: str = "",
    root_style: str = "",
    root_id: str = "",
) -> str:
    """Render the shared ``.lbl`` markup (no document shell)."""
    template = _env.get_template(PAGE_LABEL_TEMPLATE)
    return template.render(
        label=dict(label or {}),
        settings=dict(settings or {}),
        minimal=bool(minimal),
        assets=dict(assets or DEFAULT_ASSETS),
        root_class=root_class,
        root_style=root_style,
        root_id=root_id,
    )


def render_print_document(
    ticket: Optional[Mapping[str, Any]] = None,
    patient: Optional[Mapping[str, Any]] = None,
    *,
    settings: Mapping[str, Any],
    label: Optional[Mapping[str, Any]] = None,
    minimal: Optional[bool] = None,
    assets: Optional[Mapping[str, str]] = None,
    label_css: Optional[str] = None,
    label_js: Optional[str] = None,
    fonts_css: Optional[str] = None,
    title: str = "",
    datetime_text: Optional[str] = None,
) -> str:
    """Render the one print document used by the server print and the browser.

    ``settings`` is the canonical settings dict (width/height/template/rotate/
    toggles).  ``label`` may be supplied directly (the studio sample print);
    otherwise it is derived from ``ticket``/``patient``.
    """
    settings = dict(settings or {})
    width_mm = int(settings.get("width_mm") or 75)
    height_mm = int(settings.get("height_mm") or 81)
    rotated = bool(settings.get("rotate"))
    page_w, page_h = page_size(width_mm, height_mm, rotated)

    data = dict(label or label_context(ticket, patient, datetime_text=datetime_text))
    if minimal is None:
        minimal = is_minimal_label_service(data.get("service"))

    root_style = (
        f"width:{width_mm}mm;height:{height_mm}mm;"
        f"--lbl-mm-w:{width_mm};--lbl-mm-h:{height_mm};"
        f"--lbl-zoom:{zoom_seed(width_mm)}"
    )
    template = _env.get_template(PRINT_DOCUMENT_TEMPLATE)
    return template.render(
        title=title or f"چاپ لیبل نوبت {data.get('number') or ''}".strip(),
        page_w=page_w,
        page_h=page_h,
        rotate_css=rotate_css(rotated),
        label_css=label_css if label_css is not None else static_text("css/label-print.css"),
        label_js=label_js if label_js is not None else static_text("js/label-system.js"),
        font_css=fonts_css if fonts_css is not None else font_css(),
        # context consumed by the shared partial include
        label=data,
        settings=settings,
        minimal=minimal,
        assets=dict(assets or DEFAULT_ASSETS),
        root_class="lbl--print" + (" is-rotated" if rotated else ""),
        root_style=root_style,
    )
