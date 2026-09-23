"""تست‌های شناسایی چاپگر لیبل در استودیو لیبل (master-admin).

این تست‌ها تضمین می‌کنند که:
  ۱) سرویس ``app/services/printer.py`` چاپگر لیبل را از میان صف‌های spooler
     تشخیص می‌دهد و چاپگرهای مجازی (PDF/XPS/DorsanDesk) را کنار می‌گذارد؛
  ۲) مسیر ``GET /master-admin/api/printers`` در برنامه ثبت شده است؛
  ۳) رابط کاربری دیگر به ``navigator.usb`` / ``navigator.serial`` تکیه نمی‌کند
     (چون روی HTTP و برای چاپگر شبکه‌ای هیچ‌وقت کار نمی‌کرد) و فهرست چاپگرها را
     از سرور می‌خواند.
"""
import re
from pathlib import Path

import pytest

from app.services import printer as printer_service

ROOT = Path(__file__).resolve().parents[1]
JS = (ROOT / "app" / "static" / "js" / "master-admin.js").read_text(encoding="utf-8")
HTML = (ROOT / "app" / "templates" / "master-admin.html").read_text(encoding="utf-8")
CSS = (ROOT / "app" / "static" / "css" / "master-admin.css").read_text(encoding="utf-8")
LABEL_CSS = (ROOT / "app" / "static" / "css" / "label-print.css").read_text(encoding="utf-8")


def _row(name, **kwargs):
    row = {"name": name, "status": "ready", "port": "", "driver": "", "is_default": False}
    row.update(kwargs)
    return row


# ── طبقه‌بندی چاپگرها ─────────────────────────────────────────


@pytest.mark.parametrize("name", [
    "EPSON TM-T88III Receipt",
    "Zebra ZD220",
    "Xprinter XP-365B",
    "Label Printer",
])
def test_label_printers_are_flagged(name):
    assert printer_service._decorate(_row(name))["is_label"] is True


@pytest.mark.parametrize("name", [
    "Microsoft Print to PDF",
    "Microsoft XPS Document Writer",
    "Fax",
    "dorsandesk Printer",
])
def test_virtual_printers_are_never_label_candidates(name):
    decorated = printer_service._decorate(_row(name))
    assert decorated["is_virtual"] is True
    assert decorated["is_label"] is False


def test_pick_prefers_the_default_label_queue():
    rows = [
        printer_service._decorate(_row("Microsoft Print to PDF", is_default=True)),
        printer_service._decorate(_row("HP LaserJet 1100")),
        printer_service._decorate(_row("EPSON TM-T88III Receipt", is_default=True)),
        printer_service._decorate(_row("Zebra ZD220")),
    ]
    assert printer_service.pick_label_printer(rows)["name"] == "EPSON TM-T88III Receipt"


def test_pick_falls_back_to_a_ready_label_queue():
    rows = [
        printer_service._decorate(_row("Zebra ZD220")),
        printer_service._decorate(_row("EPSON TM-T88III Receipt", status="offline")),
    ]
    assert printer_service.pick_label_printer(rows)["name"] == "Zebra ZD220"


def test_pick_returns_none_without_a_label_queue():
    rows = [
        printer_service._decorate(_row("Microsoft Print to PDF", is_default=True)),
        printer_service._decorate(_row("HP LaserJet 1100", is_default=True)),
    ]
    assert printer_service.pick_label_printer(rows) is None


def test_describe_printers_payload(monkeypatch):
    rows = [
        _row("EPSON TM-T88III Receipt", is_default=True, port=r"\\192.168.3.31\EPSON"),
        _row("Microsoft Print to PDF"),
    ]
    monkeypatch.setattr(printer_service, "raw_printers", lambda force=False: rows)
    data = printer_service.describe_printers()
    assert data["enumerated"] is True
    assert data["default_printer"] == "EPSON TM-T88III Receipt"
    assert data["label_printer"] == "EPSON TM-T88III Receipt"
    assert data["label_printer_source"] == "default"
    assert [p["name"] for p in data["printers"]] == [r["name"] for r in rows]


def test_describe_printers_reports_no_label_queue(monkeypatch):
    monkeypatch.setattr(printer_service, "raw_printers", lambda force=False: [_row("Microsoft Print to PDF")])
    data = printer_service.describe_printers()
    assert data["label_printer"] == ""
    assert data["label_printer_source"] == ""


# ── API و رابط کاربری ─────────────────────────────────────────


def test_printers_route_is_registered():
    from app.main import app

    paths = {getattr(route, "path", "") for route in app.routes}
    assert "/master-admin/api/printers" in paths


def test_label_studio_reads_printers_from_the_server():
    assert "api('/printers'" in JS
    assert "maPrinterList" in JS and "maPrinterList" in HTML
    assert 'id="maPrinterTargetName"' in HTML


def test_browser_usb_detection_is_gone():
    # این API‌ها روی HTTP (بدون secure context) و برای چاپگر شبکه‌ای بی‌فایده‌اند
    assert "navigator.usb" not in JS
    assert "navigator.serial" not in JS
    assert "چاپگر متصل شناسایی نشد" not in JS


def test_printer_panel_styles_and_warning_state_exist():
    assert ".ma-printer-row.is-selected" in CSS
    assert '.ma-printer-status[data-state="warning"]' in CSS
    assert 'data-state="ready"' in CSS


def test_print_falls_back_to_a_hidden_iframe():
    # پاپ‌آپ مسدود نشود: اگر window.open مسدود شد، چاپ از iframe انجام می‌شود
    assert "position:fixed;top:0;left:-10000px" in JS
    assert "label-print.css" in JS


# ── جهت لیبل (چاپ باید عمودی باشد) ───────────────────────────


def test_default_label_never_requests_a_landscape_page():
    """صفحهٔ افقی (عرض > ارتفاع) را ویندوز ۹۰ درجه می‌چرخاند و خروجی روی لیبل
    افقی می‌افتد؛ پس پیش‌فرض هرگز نباید عرض بزرگ‌تر از ارتفاع داشته باشد.
    """
    default_w = int(re.search(r"DEFAULT_LABEL_W = (\d+)", JS).group(1))
    default_h = int(re.search(r"DEFAULT_LABEL_H = (\d+)", JS).group(1))
    assert default_h >= default_w, "پیش‌فرض نباید افقی باشد"
    # پیش‌فرض‌های قالب و جاوااسکریپت باید یکسان باشند
    assert ('id="maLabelWidth" type="number" min="30" max="150" value="%d"' % default_w) in HTML
    assert ('id="maLabelHeight" type="number" min="20" max="100" value="%d"' % default_h) in HTML


def test_layout_version_forces_the_new_default_once():
    """تنظیمات قدیمی ذخیره‌شده (۵۵ × ۵۰ افقی) باید یک‌بار به پیش‌فرض جدید برود
    و بعد از آن اندازهٔ انتخابی کاربر از بین نرود."""
    assert "LABEL_LAYOUT_VERSION" in JS
    assert "< LABEL_LAYOUT_VERSION" in JS
    # نسخه باید در refresh() هم ذخیره شود، وگرنه هر بار صفحه بار شود بازنشانی می‌شود
    assert "maLabelLayoutVersion: LABEL_LAYOUT_VERSION" in JS


def test_print_page_orientation_and_rotation_switch():
    # صفحهٔ چاپ از pageW/pageH ساخته می‌شود (در حالت چرخش جابه‌جا می‌شوند)
    assert "const pageW = rotated ? h : w;" in JS
    assert "const pageH = rotated ? w : h;" in JS
    assert "'<style>@page{size:' + pageW + 'mm ' + pageH + 'mm;margin:0}'" in JS
    # چرخش باید از لبهٔ چپ/بالای صفحه شروع شود و margin خودکار RTL را خنثی کند
    assert ".lbl--print.is-rotated{position:absolute;top:0;left:0;right:auto;margin:0 !important;" in JS
    assert "rotate(-90deg)" in JS


def test_rotation_toggle_exists_in_the_studio():
    assert 'id="maPrintRotate"' in HTML
    assert "'maPrintRotate'" in JS
    assert "rotateToggle.checked = false;" in JS


# ── مقیاس طرح لیبل (درشت‌شدن جعبه‌ها و متن‌ها) ───────────────


def test_label_content_is_scaled_to_the_physical_size():
    """طرح لیبل با --lbl-zoom به اندازهٔ فیزیکی لیبل مقیاس می‌گیرد.

    روی لیبل بزرگ (مثل ۸۰×۸۰ میلی‌متر) طرح کوچک و خالی می‌ماند؛ zoom کل
    محتوا (متن، جعبه، حاشیه و آیکون) را یک‌جا درشت می‌کند.
    """
    assert "zoom: var(--lbl-zoom, 1);" in LABEL_CSS
    assert "--lbl-zoom" in JS
    assert "function applyLabelZoom" in JS
    assert "function naturalContentSize" in JS
    assert "applyLabelZoom(w, h);" in JS


def test_zoom_is_bounded_by_height_and_reference_width():
    # ارتفاع: محتوا باید جا شود / عرض: طرح نباید از عرض مرجع باریک‌تر شود
    assert "const LABEL_REF_W_MM = 50;" in JS
    assert "const widthRoom = wmm / LABEL_REF_W_MM;" in JS
    assert "const heightRoom = boxH / Math.max(1, natural.h);" in JS
    assert "const ZOOM_MIN = 0.8;" in JS
    assert "const ZOOM_MAX = 1.8;" in JS


def test_print_document_calibrates_its_own_zoom():
    """سند چاپ خودش مقیاس را با ابعاد واقعی کاغذ حساب می‌کند و قبل از چاپ
    یک‌بار دیگر (با فونت‌های بارگذاری‌شده) کالیبره می‌شود."""
    assert "window.__lblFit=function()" in JS
    assert "window.__lblFit();" in JS
    assert "if (win.__lblFit) win.__lblFit();" in JS
    # عرض مرجع ۵۰ میلی‌متر = ۱۸۸٫۹۸ پیکسل در ۹۶dpi
    assert "r.clientWidth/188.98" in JS


def test_thermal_print_quality_rules():
    """برای چاپ حرارتی: پس‌زمینهٔ سفید خالص، خط‌های یکدست و متن ریز ضخیم‌تر."""
    print_block = LABEL_CSS.split("@media print", 1)[1]
    assert "background: #fff !important;" in print_block
    assert "border-top-style: solid !important;" in print_block
    assert ".lbl__record-label" in print_block
    assert "font-weight: 700 !important;" in print_block
