"""تست‌های استودیو لیبل (master-admin → label-printer) و سیستم واحد چاپ لیبل.

این تست‌ها دو دسته چیز را تضمین می‌کنند:

۱) تشخیص چاپگر لیبل از میان صف‌های spooler و کنار گذاشتن چاپگرهای مجازی؛

۲) «یک سیستم واحد» بودن چاپ لیبل: یک قالب مارک‌آپ، یک موتور کلاینت، یک سند چاپ
   و یک جای ذخیره‌سازی تنظیمات — به‌طوری‌که استودیو لیبل و کیوسک دقیقاً یک
   خروجی بدهند و هر تغییری در استودیو روی لیبل‌های کیوسک هم اعمال شود.
"""
import re
from pathlib import Path

import pytest

from app.services import label_render
from app.services import printer as printer_service

ROOT = Path(__file__).resolve().parents[1]


def _read(*parts):
    return (ROOT.joinpath(*parts)).read_text(encoding="utf-8")


JS = _read("app", "static", "js", "master-admin.js")
LABEL_JS = _read("app", "static", "js", "label-system.js")
HTML = _read("app", "templates", "master-admin.html")
KIOSK_HTML = _read("app", "templates", "ticket-kiosk.html")
CSS = _read("app", "static", "css", "master-admin.css")
LABEL_CSS = _read("app", "static", "css", "label-print.css")
PARTIAL = _read("app", "templates", "partials", "label_queue.html")
DOC_TEMPLATE = _read("app", "templates", "label_print_document.html")
RENDER_PY = _read("app", "services", "label_render.py")


def _row(name, **kwargs):
    row = {"name": name, "status": "ready", "port": "", "driver": "", "is_default": False}
    row.update(kwargs)
    return row


def _all_route_paths(app):
    """Flatten every route path, including lazily included routers.

    This FastAPI wraps ``include_router`` calls in an ``_IncludedRouter`` whose
    ``path`` is ``None``; the real APIRoutes live on ``original_router``.
    """
    paths = set()

    def walk(routes):
        for route in routes:
            path = getattr(route, "path", None)
            if path:
                paths.add(path)
            for attr in ("routes", "original_router"):
                nested = getattr(route, attr, None)
                sub = getattr(nested, "routes", None)
                if sub:
                    walk(sub)

    walk(app.routes)
    return paths


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

    assert "/master-admin/api/printers" in _all_route_paths(app)


def test_label_routes_are_registered():
    from app.main import app

    paths = _all_route_paths(app)
    assert "/api/label/config" in paths
    assert "/api/label/print-document" in paths


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


def test_label_studio_is_never_clipped_by_flex_shrink():
    # overflow:hidden در فلکس‌آیتم بدون flex-shrink:0 باعث می‌شد
    # استودیو کوتاه‌تر از محتوایش شود و بخش‌های پایین (دکمه‌ها/یادداشت) دیده نشوند.
    studio_block = CSS.split(".ma-label-studio {", 1)[1].split("}", 1)[0]
    assert "flex-shrink: 0" in studio_block
    assert "overflow: hidden" in studio_block


# ── یک سیستم واحد: هیچ مارک‌آپ/منطق تکراری لیبل ───────────────


def test_label_markup_lives_only_in_the_shared_partial():
    """مارک‌آپ لیبل فقط یک‌جا نوشته می‌شود؛ هیچ صفحه‌ای نسخهٔ خودش را ندارد."""
    for needle in ("lbl__record", "lbl__queue-number", "lbl__record-group--insurance", "lbl__footer"):
        assert needle in PARTIAL, f"{needle} must live in the shared partial"
        assert needle not in HTML, f"master-admin.html must not duplicate label markup ({needle})"
        assert needle not in KIOSK_HTML, f"ticket-kiosk.html must not duplicate label markup ({needle})"


def test_both_surfaces_use_the_same_label_engine():
    assert "js/label-system.js" in HTML
    assert "js/label-system.js" in KIOSK_HTML
    # استودیو قالب را به موتور می‌دهد؛ کیوسک همان سند سرور را چاپ می‌کند.
    assert 'id="hastamaLabelTemplate"' in HTML
    assert "HastamaLabel.print(" in KIOSK_HTML


def test_kiosk_has_no_private_label_builder_left():
    """کیوسک قبلاً نسخهٔ خودش از HTML لیبل + محاسبهٔ zoom + تنظیمات را داشت."""
    for legacy in (
        "function printTicket",
        "function normalizeLabelSettings",
        "function readLocalLabelSettings",
        "function isMinimalLabelService",
        "__lblFit",
        "TEMPLATE_SERVICE",
    ):
        assert legacy not in KIOSK_HTML, f"legacy kiosk label code survived: {legacy}"
    assert "function printIssuedTicket" in KIOSK_HTML


def test_print_document_inlines_the_shared_engine_and_sheet():
    """سند چاپ (هم سرور و هم fallback مرورگر) خودِ موتور و CSS مشترک است."""
    assert "{{ head_styles }}" in DOC_TEMPLATE
    assert "{{ label_js }}" in DOC_TEMPLATE
    assert "include 'partials/label_queue.html'" in DOC_TEMPLATE
    # No autoescape-bypass filter: the trusted blobs are passed as Markup by
    # label_render.render_print_document() instead (see the escaping test above).
    assert "|safe" not in DOC_TEMPLATE
    html = label_render.render_print_document(
        {"number": 1, "service": "پذیرش"}, {}, settings={"width_mm": 75, "height_mm": 81}
    )
    # سه بلوک: فونت‌ها، برگهٔ لیبل و هندسهٔ صفحه
    assert html.count("<style>") == 3
    assert "@font-face" in html
    assert ".lbl {" in html
    assert "@page { size: 75mm 81mm; margin: 0; }" in html


def test_inlined_engine_is_not_html_escaped():
    """رگرسیون: با autoescape، `&&` به `&amp;&amp;` تبدیل می‌شد و اسکریپت
    سند چاپ هیچ‌وقت اجرا نمی‌شد؛ نتیجه‌اش چاپ لیبل در مقیاس غلط و بریده‌شدن
    پایین لیبل بود."""
    html = label_render.render_print_document(
        {"number": 1, "service": "پذیرش"},
        {},
        settings={"width_mm": 75, "height_mm": 81},
    )
    engine = html.split("<script>", 1)[1].split("</script>", 1)[0]
    assert "global.HastamaLabel = {" in engine
    assert "&&" in engine
    assert "&amp;&amp;" not in engine
    assert "&lt;" not in engine
    # و موتور واقعاً همان چیزی است که مرورگر هم بارگذاری می‌کند
    assert engine.strip() == LABEL_JS.strip()


def test_print_document_calls_the_engine_fit():
    html = label_render.render_print_document(
        {"number": 1, "service": "پذیرش"},
        {},
        settings={"width_mm": 75, "height_mm": 81},
    )
    assert "HastamaLabel.fitDocument()" in html


def test_fallback_browser_print_uses_a_hidden_iframe_when_blocked():
    # پاپ‌آپ مسدود شود: چاپ از iframe پنهان انجام می‌شود
    assert "position:fixed;top:0;left:-10000px" in LABEL_JS
    # CSS/فونت از سند سرور می‌آید؛ موتور نسخهٔ محلی نمی‌سازد
    assert "<link rel=\"stylesheet\"" not in LABEL_JS
    assert "@page" not in LABEL_JS


def test_browser_fallback_downloads_the_server_document():
    """fallback مرورگر همان سندی را می‌گیرد که چاپ بی‌صدای سرور استفاده می‌کند."""
    assert "'/api/label/print-document'" in LABEL_JS
    assert "'/api/queue/print'" in LABEL_JS


# ── تنظیمات: سرور تنها منبع، برای هر دو صفحه ─────────────────


def test_settings_are_saved_to_and_read_from_the_server():
    assert "'/api/label/config'" in LABEL_JS
    assert "key: 'label_print_settings'" in LABEL_JS
    assert "key: 'label_target_printer'" in LABEL_JS
    assert "loadConfig" in LABEL_JS and "saveSettings" in LABEL_JS
    # استودیو فقط رابط است و از همان توابع مشترک استفاده می‌کند
    assert "HL.saveSettings" in JS
    assert "HL.loadConfig" in JS
    assert "HL.saveTargetPrinter" in JS


def test_local_storage_is_only_a_cache():
    # کش محلی برای نمایش فوری است؛ سرور مرجع است تا چاپ کیوسک یکی باشد.
    assert "hastama-label-settings" in LABEL_JS
    assert "hastama-label-target-printer" in LABEL_JS
    assert "localStorage.setItem('hastama-label-settings'" not in JS


def test_label_settings_shape_matches_the_server_normalizer():
    """هر دو طرف (کلاینت و سرور) یک شکل تنظیمات دارند که سند چاپ می‌فهمد."""
    from app.services import ticket_print

    server = ticket_print.normalize_label_settings(None)
    for key in ("width_mm", "height_mm", "template", "rotate", "show_name", "show_time", "show_hint"):
        assert key in LABEL_JS, key
        assert key in server, key
    # قالب از همین تنظیمات به مارک‌آپ واحد می‌رود
    assert 'settings.get("template")' not in RENDER_PY  # template در partial خوانده می‌شود
    assert "settings=dict(settings or {})" in RENDER_PY


def test_default_label_never_requests_a_landscape_page():
    """صفحهٔ افقی (عرض > ارتفاع) را ویندوز ۹۰ درجه می‌چرخاند و خروجی روی لیبل
    افقی می‌افتد؛ پس پیش‌فرض هرگز نباید عرض بزرگ‌تر از ارتفاع داشته باشد."""
    default_w = int(re.search(r"width_mm:\s*(\d+)", LABEL_JS).group(1))
    default_h = int(re.search(r"height_mm:\s*(\d+)", LABEL_JS).group(1))
    assert default_h >= default_w, "پیش‌فرض نباید افقی باشد"
    assert default_w == 75 and default_h == 81
    # پیش‌فرض‌های قالب HTML هم باید همان باشند
    assert 'id="maLabelWidth" type="number" min="30" max="150" value="%d"' % default_w in HTML
    assert 'id="maLabelHeight" type="number" min="20" max="150" value="%d"' % default_h in HTML


def test_size_limits_agree_between_studio_inputs_engine_and_server():
    """حد مجاز اندازهٔ لیبل در سه لایه یکی است.

    اگر محدودیت در سرور/موتور کمتر از input باشد، کاربر عدد بزرگ‌تر می‌زند و
    لیبل «اعمال نمی‌شود» (باگ «ارتفاع بیشتر از ۱۰۰ نمی‌شود»).
    """
    from app.services import ticket_print

    js_widths = re.search(r"var WIDTH_RANGE = \[(\d+), (\d+)\];", LABEL_JS)
    js_heights = re.search(r"var HEIGHT_RANGE = \[(\d+), (\d+)\];", LABEL_JS)
    assert js_widths and js_heights, "رنج‌ها باید در label-system.js تعریف شوند"
    assert tuple(int(v) for v in js_widths.groups()) == ticket_print.LABEL_WIDTH_MM_RANGE
    assert tuple(int(v) for v in js_heights.groups()) == ticket_print.LABEL_HEIGHT_MM_RANGE

    width_input = re.search(r'id="maLabelWidth" type="number" min="(\d+)" max="(\d+)"', HTML)
    height_input = re.search(r'id="maLabelHeight" type="number" min="(\d+)" max="(\d+)"', HTML)
    assert tuple(int(v) for v in width_input.groups()) == ticket_print.LABEL_WIDTH_MM_RANGE
    assert tuple(int(v) for v in height_input.groups()) == ticket_print.LABEL_HEIGHT_MM_RANGE
    # ارتفاع باید بالاتر از ۱۰۰ برود (درخواست کاربر: ۱۰۵)
    assert ticket_print.LABEL_HEIGHT_MM_RANGE[1] >= 105
    server = ticket_print.normalize_label_settings({"height_mm": 105})
    assert server["height_mm"] == 105


def test_server_defaults_match_the_studio_defaults():
    defaults = __import__("app.services.ticket_print", fromlist=["x"]).default_label_settings()
    assert defaults["width_mm"] == 75
    assert defaults["height_mm"] == 81
    assert defaults["template"] == "queue"
    assert defaults["rotate"] is False


def test_layout_version_forces_one_reset_of_stale_sizes():
    """نسخهٔ چیدمان در تنظیمات ذخیره می‌شود تا اندازه‌های قدیمی یک‌بار بازنشانی
    شوند و بعد از آن انتخاب کاربر از بین نرود."""
    assert "LAYOUT_VERSION" in LABEL_JS
    assert "layout_version" in LABEL_JS
    assert "HL.DEFAULT_SETTINGS" in JS


# ── چرخش ۹۰ درجه (سرور و مرورگر یکسان) ────────────────────────


def test_rotation_swaps_the_physical_page_and_rotates_the_sheet():
    assert "rotate(-90deg)" in RENDER_PY
    assert "def page_size(width_mm: int, height_mm: int, rotated: bool)" in RENDER_PY
    assert "is-rotated" in RENDER_PY
    # چرخش باید از لبهٔ چپ/بالای صفحه شروع شود و margin خودکار RTL را خنثی کند
    assert "position:absolute;top:0;left:0;right:auto;margin:0 !important;" in RENDER_PY


def test_rotation_is_applied_by_the_shared_engine():
    assert "rotate" in LABEL_JS
    # سند چاپ خودش rotate را از تنظیمات می‌سازد؛ geometry چرخش در label_render
    assert "rotated" in RENDER_PY
    assert "rotate_css" in RENDER_PY
    assert "is-rotated" in RENDER_PY
    assert "{{ head_styles }}" in DOC_TEMPLATE


def test_page_geometry_is_generated_not_interpolated_in_the_template():
    """geometry سند چاپ (اندازهٔ @page، مبدأ ۰٫۰ و چرخش ۹۰ درجه) در
    ``label_render.page_css`` ساخته می‌شود و قالب هیچ CSS درون‌خطی ندارد —
    ادیتورها مقادیر Jinja داخل <style> را CSS خراب می‌خوانند و سیل خطاهای
    تکراری، خطاهای واقعی را می‌پوشاند."""
    assert 'f"@page {{ size: ' in RENDER_PY
    assert '".lbl-page {\\n"' in RENDER_PY
    assert "page_css(width_mm, height_mm, rotated)" in RENDER_PY
    assert "head_styles = Markup(" in RENDER_PY
    # هیچ CSS‌ای درون خودِ قالب نیست که ویرایشگر آن را لینت کند
    assert "<style" not in DOC_TEMPLATE
    assert "@page {" not in DOC_TEMPLATE
    assert ".lbl-page {" not in DOC_TEMPLATE

    # چرخش ۹۰ درجه: محورهای کاغذ جابه‌جا و طرح منفی ۹۰ درجه می‌چرخد
    rotated = label_render.render_print_document(
        {"service": "پذیرش", "number": 1},
        {},
        settings={"width_mm": 50, "height_mm": 70, "rotate": True},
    )
    assert "@page { size: 70mm 50mm; margin: 0; }" in rotated
    assert "rotate(-90deg)" in rotated


def test_rotation_toggle_exists_in_the_studio():
    assert 'id="maPrintRotate"' in HTML
    assert "rotate: el.rotate ? el.rotate.checked : undefined" in JS


# ── مقیاس طرح لیبل (درشت‌شدن جعبه‌ها و متن‌ها) ───────────────


def test_label_content_is_scaled_to_the_physical_size():
    """طرح لیبل با --lbl-zoom به اندازهٔ فیزیکی لیبل مقیاس می‌گیرد.

    روی لیبل بزرگ (مثل ۸۰×۸۰ میلی‌متر) طرح کوچک و خالی می‌ماند؛ zoom کل
    محتوا (متن، جعبه، حاشیه و آیکون) را یک‌جا درشت می‌کند.
    """
    assert "zoom: var(--lbl-zoom, 1);" in LABEL_CSS
    assert "function fitLabel" in LABEL_JS
    assert "--lbl-zoom" in LABEL_JS
    # پیش‌نمایش استودیو هم از همان تابع استفاده می‌کند
    assert "fitLabel" in LABEL_JS and "mountPreview" in LABEL_JS


def test_zoom_is_bounded_by_height_and_reference_width():
    # ارتفاع: محتوا باید جا شود / عرض: طرح نباید از عرض مرجع باریک‌تر شود
    assert "var REF_WIDTH_MM = 50;" in LABEL_JS
    assert "labelEl.clientWidth / REF_WIDTH_PX" in LABEL_JS
    assert "labelEl.clientHeight / Math.max(1, naturalHeight)" in LABEL_JS
    assert "var ZOOM_MIN = 0.8;" in LABEL_JS
    assert "var ZOOM_MAX = 1.8;" in LABEL_JS
    assert "LABEL_REF_WIDTH_MM = 50" in RENDER_PY


def test_studio_preview_is_magnified_beyond_the_physical_size():
    """لیبل ۷۶×۸۰ روی نمایشگر ۹۶dpi تقریباً ۳ اینچ می‌شود و متن ریز آن
    خوانده نمی‌شود؛ پس پیش‌نمایش استودیو تا ۱.۵ برابر اندازهٔ واقعی بزرگ
    می‌شود و همان مقیاس به کاربر نشان داده می‌شود."""
    assert "var PREVIEW_MAX_SCALE = 1.5;" in LABEL_JS
    assert "PX_PER_MM * maxScale" in LABEL_JS
    # سند چاپ و کیوسک سقف خودشان را نگه می‌دارند (تغییر مقیاس چاپ نمی‌کنیم)
    assert "var ZOOM_MAX = 1.8;" in LABEL_JS
    assert "function fitLabel(labelEl, options)" in LABEL_JS
    assert "function fitDocument" in LABEL_JS
    assert "fitLabel(labelEl);" in LABEL_JS  # سند چاپ بدون options
    # مقیاس واقعی همان چیزی است که در نوار پیش‌نمایش نوشته می‌شود
    assert 'id="maLabelScaleReadout"' in HTML
    assert "updateScale" in JS and "onScale: updateScale" in JS
    assert "برابر واقعی" in JS
    assert "scale: function () { return state.scale; }" in LABEL_JS


def test_preview_measures_the_stage_not_its_own_box():
    """قاب پیش‌نمایش باید فضای استیج را اندازه بگیرد؛ اگر جعبهٔ خودش را
    معیار بگیرد، هر اندازه‌گیری لیبل را کمی کوچک‌تر می‌کند (حلقهٔ کوچک‌شدن)."""
    host = CSS.split(".ma-label-preview-host {", 1)[1].split("}", 1)[0]
    assert "align-self:stretch" in host
    assert "width:100%" in host
    stage = CSS.split(".ma-label-preview-stage {", 1)[1].split("}", 1)[0]
    assert "min-height:380px" in stage
    assert "hostEl.clientHeight" in LABEL_JS
    # ستون پیش‌نمایش باید برای همان ۱.۵ برابر جا داشته باشد
    assert "minmax(320px,1fr) minmax(320px,1.05fr)" in CSS


def test_print_document_calibrates_its_own_zoom_after_fonts():
    """سند چاپ خودش مقیاس را با ابعاد واقعی کاغذ حساب می‌کند و بعد از آمدن
    فونت‌ها یک‌بار دیگر کالیبره می‌شود (وگرنه پایین لیبل بریده می‌شد)."""
    assert "function fitDocument" in LABEL_JS
    assert "document.fonts.ready.then(refit)" in LABEL_JS
    assert "addEventListener('load', refit)" in LABEL_JS
    assert "setTimeout(refit" in LABEL_JS


def test_nothing_is_painted_in_the_unprintable_top_strip():
    """چاپگر حرارتی چند میلی‌متر اول کاغذ را چاپ نمی‌کند؛ نوار بالای لیبل هم
    باید از همان حاشیهٔ ایمن شروع شود تا روی کاغذ گم نشود."""
    safe_y = re.search(r"--lbl-safe-y:\s*([\d.]+)mm", LABEL_CSS).group(1)
    assert float(safe_y) >= 2
    assert "--lbl-safe-y:     2mm;" in LABEL_CSS
    accent = LABEL_CSS.split(".lbl__accent {", 1)[1].split("}", 1)[0]
    assert "top: var(--lbl-safe-y, 2mm);" in accent
    # محتوا هم همین حاشیه را در بالا و پایین نگه می‌دارد
    content = LABEL_CSS.split(".lbl__content {", 1)[1].split("}", 1)[0]
    assert "var(--lbl-safe-y" in content


def test_thermal_print_quality_rules():
    """برای چاپ حرارتی: پس‌زمینهٔ سفید خالص، خط‌های یکدست و متن ریز ضخیم‌تر."""
    print_block = LABEL_CSS.split("@media print", 1)[1]
    assert "background: #fff !important;" in print_block
    assert "border-top-style: solid !important;" in print_block
    assert ".lbl__record-label" in print_block
    assert "font-weight: 700 !important;" in print_block


def _label_font_px(selector: str) -> float:
    block = LABEL_CSS.split(selector, 1)[1].split("}", 1)[0]
    match = re.search(r"font-size:\s*([\d.]+)px", block)
    assert match, f"font-size not found for {selector}"
    return float(match.group(1))


def test_visitor_info_outweighs_the_queue_number():
    """خوانایی برای مراجعه‌کنندهٔ مسن: اطلاعات (مقدار/نام) درشت‌تر از برچسب،
    و شمارهٔ نوبت نباید دوباره بر اطلاعات مسلط شود."""
    value = _label_font_px(".lbl__record-value {")
    label = _label_font_px(".lbl__record-label {")
    number = _label_font_px(".lbl__queue-number {")
    assert value >= 7
    assert label >= 5
    assert value > label
    assert number <= 22
    # حداقل ارتفاع سطر برای مقادیر درشت‌تر
    assert "min-height: 12px" in LABEL_CSS.split(".lbl__record {", 1)[1].split("}", 1)[0]


# ── قالب‌های حداقلی سرویس (جواب‌دهی / نمونه‌گیری / نوبت آزاد) ──


def test_label_studio_offers_minimal_service_templates():
    select = HTML.split('id="maLabelTemplate"', 1)[1].split("</select>", 1)[0]
    for value in ("queue", "compact", "result", "sampling", "blank"):
        assert f'value="{value}"' in select, f"template option {value} missing"
    # برچسب فارسی هر گزینه همراه همان قالب نوشته شده است
    assert len(re.findall(r"<option value=", select)) == 5


def test_minimal_templates_css_hides_patient_block():
    # جواب‌دهی/نمونه‌گیری: فقط شماره پذیرش + نوبت؛ نوبت آزاد: فقط نوبت
    assert "data-template='result'" in LABEL_CSS
    assert "data-template='sampling'" in LABEL_CSS
    assert "data-template='blank'" in LABEL_CSS
    assert "[data-field='admission']" in LABEL_CSS
    assert "[data-field='patient']" in LABEL_CSS
    blank_block = LABEL_CSS.split(".lbl[data-template='blank'] .lbl__records", 1)[1].split("}", 1)[0]
    assert "display: none" in blank_block
    # قالب حداقلی در همان partial با data-field مشترک ساخته می‌شود
    assert 'data-field="patient"' in PARTIAL
    assert 'data-field="admission"' in PARTIAL
    assert "data-field=\"admission-value\"" in PARTIAL


def test_free_ticket_is_called_azad_on_every_surface():
    """«نوبت خالی» به «نوبت آزاد» تغییر نام داده و همهٔ سطح‌ها باید همان
    نام تازه را نشان دهند؛ فقط سرور نام قدیمی را می‌شناسد تا نوبت‌های
    ثبت‌شده در بانک قبل از تغییر نام هم مثل قبل حداقلی چاپ شوند."""
    assert "نوبت آزاد" in KIOSK_HTML
    assert "نوبت خالی" not in KIOSK_HTML
    assert "نوبت آزاد" in HTML
    assert "نوبت آزاد" in LABEL_JS
    assert "نوبت خالی" not in LABEL_JS
    assert label_render.is_minimal_label_service("نوبت آزاد")
    assert label_render.is_minimal_label_service("نوبت خالی")
    # انتخاب «نوبت آزاد» در استودیو همین متن را روی چیپ می‌گذارد
    assert _js_template_service_labels()["blank"] == "نوبت آزاد"


def test_service_label_comes_from_the_shared_partial():
    assert 'data-field="service"' in PARTIAL
    assert 'data-field="number"' in PARTIAL
    # برچسب سرویس در مارک‌آپ واحد است، پس استودیو و کیوسک یکی می‌بینند
    assert "is_minimal_label_service" in RENDER_PY
    assert "MINIMAL_LABEL_SERVICES" in RENDER_PY


def test_template_select_persists_and_drives_preview():
    assert "setAttribute('data-template', settings.template || 'queue')" in LABEL_JS
    assert "template: el.template ? el.template.value : undefined" in JS


def _js_template_service_labels():
    """نگاشت «قالب → متن سرویس» از خود فایل موتور کلاینت خوانده می‌شود."""
    block = LABEL_JS.split("var TEMPLATE_SERVICE_LABELS = {", 1)[1].split("};", 1)[0]
    return dict(re.findall(r"(\w+):\s*'([^']+)'", block))


def test_template_choice_drives_the_service_chip():
    """انتخاب «جواب‌دهی» در استودیو باید چیپ سرویس لیبل را هم عوض کند،
    نه اینکه «پذیرش» دادهٔ نمونه سر جایش بماند."""
    js_map = _js_template_service_labels()
    assert js_map == label_render.TEMPLATE_SERVICE_LABELS
    assert "setField(labelEl, 'service', serviceLabel(data, settings))" in LABEL_JS
    assert "function serviceLabel(data, settings)" in LABEL_JS
    # چاپ نمونه هم همان قالب را به موتور می‌دهد تا برگهٔ چاپی با پیش‌نمایش یکی باشد
    assert "serviceLabel(SAMPLE.ticket, settings)" in LABEL_JS
    assert "HL.printSample(settings)" in JS


def test_service_preset_of_a_template_is_minimal():
    for template, label in label_render.TEMPLATE_SERVICE_LABELS.items():
        assert label_render.template_service_label(template) == label
        assert label_render.is_minimal_label_service(label)
    assert label_render.template_service_label("queue") == ""
    assert label_render.template_service_label(None) == ""


def test_sample_print_document_shows_the_selected_service():
    html = label_render.render_print_document(
        {"service": "جواب‌دهی", "number": 12},
        {"admission_number": "12345"},
        settings={"width_mm": 75, "height_mm": 81, "template": "result"},
    )
    assert 'data-field="service">جواب‌دهی</span>' in html
    assert 'data-template="result"' in html


def test_print_document_falls_back_to_the_template_service():
    html = label_render.render_print_document(
        label={"number": "۱۲"},
        settings={"width_mm": 75, "height_mm": 81, "template": "sampling"},
    )
    assert 'data-field="service">نمونه‌گیری</span>' in html


def test_no_template_service_falls_back_to_the_queue_service():
    html = label_render.render_print_document(
        label={"number": "۱۲"},
        settings={"width_mm": 75, "height_mm": 81, "template": "queue"},
    )
    assert 'data-field="service">پذیرش</span>' in html
    assert "el.template.value = settings.template" in JS
