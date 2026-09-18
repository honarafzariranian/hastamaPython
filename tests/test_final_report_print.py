"""تست‌های لایهٔ اختصاصی چاپ گزارش نهایی (final-report-print)

خروجی چاپ گزارش حضور و غیاب یک «فرم A4» است که توسط
``app/static/js/final-report-print.js`` ساخته می‌شود و استایلش در
``app/static/css/final-report-print.css`` قرار دارد.

این تست‌ها سه چیز را تضمین می‌کنند:
  ۱) سیم‌کشی قالب: stylesheet و اسکریپت چاپ include شده‌اند، ظرف
     ``#printReport`` وجود دارد و لایهٔ چاپ «آخرین» stylesheet صفحه است
     (وگرنه قوانین print قدیمی روی آن غالب می‌شوند)؛
  ۲) قرارداد صفحه‌بندی در CSS: اندازهٔ A4، ارتفاع برگ، شکست صفحه بین
     برگ‌ها و پنهان‌شدن کامل چیدمان نمایشگری هنگام چاپ؛
  ۳) اجرای واقعی منطق صفحه‌بندی در jsdom (فایل DOM suite مجزا).
"""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEMPLATE = (ROOT / "app" / "templates" / "final_report_page.html").read_text(encoding="utf-8")
CSS = (ROOT / "app" / "static" / "css" / "final-report-print.css").read_text(encoding="utf-8")
JS = (ROOT / "app" / "static" / "js" / "final-report-print.js").read_text(encoding="utf-8")


def _link_positions(html: str) -> list[tuple[int, str]]:
    """(index, path) برای همهٔ stylesheetهای صفحه، به ترتیب ظاهرشدن.

    href در قالب‌ها به‌شکل ``{{ url_for('static', path='css/x.css') }}`` است.
    """
    pattern = re.compile(
        r"""<link[^>]+href="\{\{\s*url_for\(\s*'static'\s*,\s*path='(?P<path>css/[^']+\.css)'""",
    )
    return [(m.start(), m.group("path")) for m in pattern.finditer(html)]


def _screen_part(css: str) -> str:
    """بخشی از CSS که بیرون از بلوک ``@media print`` (سرِ خط) قرار دارد."""
    match = re.search(r"^@media print\b", css, re.MULTILINE)
    return css[: match.start()] if match else css


# ── ۱) سیم‌کشی قالب ────────────────────────────────────────────────────
def test_print_assets_are_included():
    assert "css/final-report-print.css" in TEMPLATE, "stylesheet چاپ include نشده است"
    assert "js/final-report-print.js" in TEMPLATE, "اسکریپت چاپ include نشده است"


def test_print_container_exists_in_template():
    assert 'id="printReport"' in TEMPLATE, "ظرف #printReport در قالب وجود ندارد"


def test_print_stylesheet_is_loaded_last():
    """لایهٔ چاپ باید بعد از همهٔ stylesheetهای دیگر بیاید."""
    links = _link_positions(TEMPLATE)
    assert links, "هیچ stylesheetی در قالب پیدا نشد"
    assert links[-1][1] == "css/final-report-print.css", (
        f"آخرین stylesheet باید final-report-print.css باشد، اما {links[-1][1]} است"
    )


def test_print_script_loads_after_report_script():
    """اسکریپت چاپ باید بعد از final-report-script.js بارگذاری شود تا
    داده‌های رندرشدهٔ جداول را ببیند."""
    assert TEMPLATE.index("js/final-report-script.js") < TEMPLATE.index("js/final-report-print.js")


def test_export_buttons_exist():
    for element_id in ("printReportBtn", "savePdfReportBtn"):
        assert f'id="{element_id}"' in TEMPLATE, f"دکمهٔ {element_id} در قالب نیست"


# ── ۲) قرارداد صفحه‌بندی در CSS ────────────────────────────────────────
def test_page_size_is_a4_portrait():
    assert re.search(r"@page\s*\{[^}]*size:\s*A4\s+portrait", CSS), "@page باید A4 portrait باشد"


def test_sheet_has_fixed_a4_height_and_forced_page_break():
    assert "--pr-sheet-h: 279mm" in CSS, "ارتفاع برگ A4 تعریف نشده است"
    assert "page-break-after: always" in CSS, "شکست صفحه بین برگ‌ها تعریف نشده است"
    assert ".pr-sheet:last-child" in CSS, "برگ آخر نباید شکست صفحهٔ اضافی ایجاد کند"


def test_screen_layout_is_hidden_when_print_form_is_ready():
    assert "body.final-report-page.pr-ready > *:not(#printReport)" in CSS, (
        "قاعدهٔ پنهان‌کردن چیدمان نمایشگری هنگام چاپ وجود ندارد"
    )


def test_print_layer_is_hidden_on_screen():
    """#printReport باید بیرون از @media print پنهان باشد."""
    outside = _screen_part(CSS)
    assert re.search(r"#printReport\s*\{[^}]*display:\s*none", outside), (
        "لایهٔ چاپ روی نمایشگر پنهان نشده است"
    )


def test_print_colors_survive_the_printer():
    assert "print-color-adjust: exact" in CSS, "بدون print-color-adjust رنگ‌های چاپ حذف می‌شوند"


def test_no_screen_only_decoration_in_print_layer():
    """فرم چاپ باید بدون سایه/گرادیان تزیینی باشد (کاغذ، نه نمایشگر)."""
    print_block = CSS[len(_screen_part(CSS)):]
    assert "box-shadow: none" in print_block
    # تنها گرادیان مجاز، بافت بسیار کم‌رنگ خانهٔ «موظفی» است
    assert print_block.count("linear-gradient") <= 1


# ── ۳) قرارداد صفحه‌بندی در JS ─────────────────────────────────────────
def test_max_31_records_per_page():
    assert "MAX_ROWS_PER_PAGE = 31" in JS, "سقف ۳۱ رکورد در هر برگ A4 تعریف نشده است"


def test_summary_tables_and_signatures_are_rendered():
    for marker in ("renderSummary", "renderSignatures", "pr-details"):
        assert marker in JS, f"{marker} در اسکریپت چاپ وجود ندارد"


def test_page_geometry_matches_css():
    """ارتفاع صفحه در JS باید با ارتفاع برگ در CSS یکی باشد."""
    js_page = re.search(r"pageH:\s*(\d+)", JS)
    css_page = re.search(r"--pr-sheet-h:\s*(\d+)mm", CSS)
    assert js_page and css_page
    assert js_page.group(1) == css_page.group(1), (
        f"JS pageH={js_page.group(1)}mm اما CSS sheet-h={css_page.group(1)}mm"
    )
