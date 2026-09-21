"""تست‌های «بوم داده»‌ی داشبورد مدیریت (بازطراحی نسخهٔ ۲).

این سوئیت قرارداد لایهٔ جدید را تضمین می‌کند:

  ۱) بارگذاری درست لایه‌ها: `dashboard-modern.css` بعد از `admin.css` و پیش از
     `dark-theme.css` (تا تم تیره آخرین حرف را بزند) و `dashboard-modern.js`.
  ۲) «حذف کامل باکس‌ها»: هیچ کارت/قاب/سایه‌ای برای بلوک‌های داشبورد تعریف
     نشده و قواعد ظاهری قدیمی از `admin.css` پاک شده‌اند.
  ۳) هیچ دادهٔ سمت سروری حذف نشده و هیچ عددی سمت کلاینت ساخته نمی‌شود.
  ۴) بدون JS هم داشبورد کامل است: حالت پایه هیچ عنصری را پنهان نمی‌کند و
     انیمیشن‌ها فقط با کلاس `is-in` (که JS می‌گذارد) شروع می‌شوند.
  ۵) پوشش کامل تم تیره، احترام به prefers-reduced-motion، چاپ، دسترس‌پذیری
     و راست‌به‌چپ با ویژگی‌های منطقی.

سوئیت رفتاری (jsdom) در tests/js/dashboard-modern.dom.test.js است و از
tests/test_dashboard_modern_dom.py اجرا می‌شود.
"""

from __future__ import annotations

import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
CSS_DIR = ROOT / "app" / "static" / "css"
JS_DIR = ROOT / "app" / "static" / "js"
TPL_DIR = ROOT / "app" / "templates"

ADMIN_HTML = TPL_DIR / "admin.html"
ADMIN_CSS = CSS_DIR / "admin.css"
DARK_CSS = CSS_DIR / "dark-theme.css"
DASH_CSS = CSS_DIR / "dashboard-modern.css"
DASH_JS = JS_DIR / "dashboard-modern.js"


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


def rule_body(css: str, selector: str) -> str:
    """متن بدنهٔ یک قاعدهٔ CSS را با انتخابگر دقیق برمی‌گرداند.

    دستی پیاده‌سازی شده تا سوئیت به هیچ کتابخانهٔ CSS وابسته نباشد.
    فهرست انتخابگرهای گروهی (selector list) هم پشتیبانی می‌شود.
    """
    pattern = re.escape(selector) + r"(?![\w-])"
    match = re.search(pattern, css)
    assert match is not None, f"قاعدهٔ «{selector}» در فایل نیست"
    index = match.start()
    start = css.index("{", index)
    depth = 0
    for pos in range(start, len(css)):
        char = css[pos]
        if char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                return css[start + 1:pos]
    raise AssertionError(f"بدنهٔ قاعدهٔ «{selector}» بسته نشده است")


@pytest.fixture(scope="module")
def template() -> str:
    return read(ADMIN_HTML)


@pytest.fixture(scope="module")
def dashboard_css() -> str:
    return read(DASH_CSS)


@pytest.fixture(scope="module")
def dashboard_js() -> str:
    return read(DASH_JS)


@pytest.fixture(scope="module")
def dark_css() -> str:
    return read(DARK_CSS)


@pytest.fixture(scope="module")
def dashboard_markup(template: str) -> str:
    start = template.index('id="dashboardBox"')
    end = template.index("<!-- باکس مدیریت کارکنان با تب‌ها -->", start)
    return template[start:end]


# --------------------------------------------------------------------------
# ۱) بارگذاری لایهٔ جدید
# --------------------------------------------------------------------------
def test_new_layer_files_exist() -> None:
    assert DASH_CSS.exists(), "dashboard-modern.css پیدا نشد"
    assert DASH_JS.exists(), "dashboard-modern.js پیدا نشد"


def test_template_loads_dashboard_css_before_dark_theme(template: str) -> None:
    """لایهٔ داشبورد باید آخرین شیت روشن باشد؛ یعنی قبل از dark-theme.css."""
    assert "css/dashboard-modern.css" in template
    dash_index = template.index("css/dashboard-modern.css")
    dark_index = template.index("css/dark-theme.css")
    assert dash_index < dark_index, "dashboard-modern.css باید قبل از dark-theme.css بیاید"


def test_template_loads_dashboard_css_after_admin_css(template: str) -> None:
    assert template.index("css/admin.css") < template.index("css/dashboard-modern.css")


def test_template_loads_dashboard_js(template: str) -> None:
    assert "js/dashboard-modern.js" in template


def test_old_dashboard_visual_rules_are_gone() -> None:
    """قواعد ظاهری کارت‌های قدیمی داشبورد از admin.css حذف شده‌اند."""
    css = read(ADMIN_CSS)
    for selector in (
        ".dashboard-card--highlight {",
        ".dashboard-quick-card--accent {",
        ".dashboard-chart-row {",
        ".bar-value.overtime {",
    ):
        assert selector not in css, f"قاعدهٔ قدیمی هنوز در admin.css هست: {selector}"


def test_dashboard_box_does_not_force_legacy_scroll_metrics(dashboard_css: str) -> None:
    """ارتفاع ثابت + اسکرول داخلی نسخهٔ قبلی نباید برگردد."""
    assert "height: auto !important" in dashboard_css
    assert "max-height: none !important" in dashboard_css
    assert "position: static !important" in dashboard_css
    assert "overflow: visible !important" in dashboard_css


def test_dashboard_box_keeps_toggle_logic_unbound(dashboard_css: str, template: str) -> None:
    """display نباید در لایهٔ جدید مقدار ثابت بگیرد (toggleBox با style کار می‌کند)."""
    box_rule = rule_body(dashboard_css, "#dashboardBox.hx-dashboard")
    assert "display:" not in box_rule, "display نباید در قاعدهٔ باکس داشبورد ست شود"
    assert 'class="management-box hx-dashboard" id="dashboardBox"' in template
    assert 'style="display: flex;"' not in template


# --------------------------------------------------------------------------
# ۲) «حذف کامل باکس‌ها» — قلب این بازطراحی
# --------------------------------------------------------------------------
BOXLESS_SELECTORS = [
    ".hx-dash",
    "#dashboardBox .hx-pod",
    "#dashboardBox .hx-metrics",
    "#dashboardBox .hx-metric",
    "#dashboardBox .hx-figures",
    "#dashboardBox .hx-figure",
    "#dashboardBox .hx-chartblock",
    "#dashboardBox .hx-boards",
    "#dashboardBox .hx-board",
    "#dashboardBox .hx-ticker",
]


@pytest.mark.parametrize("selector", BOXLESS_SELECTORS)
def test_no_card_chrome_on_dashboard_blocks(dashboard_css: str, selector: str) -> None:
    """هیچ بلوکی قاب/پس‌زمینهٔ کارتی ندارد؛ عمق با نور و خط مو ساخته می‌شود."""
    body = rule_body(dashboard_css, selector)
    assert "box-shadow" not in body, f"سایهٔ جعبه‌ای روی {selector} مانده است"
    assert "background:" not in body, f"پس‌زمینهٔ کارتی روی {selector} مانده است"
    assert "border-radius" not in body or "border-radius: 0" in body, (
        f"گوشهٔ گردِ کارتی روی {selector} مانده است"
    )


def test_hero_is_a_band_not_a_card(dashboard_css: str) -> None:
    """قهرمان تنها «نوار نور» تمام‌عرض است: گرادیان دارد، ولی قاب/مرز کارتی نه."""
    body = rule_body(dashboard_css, "#dashboardBox .hx-hero")
    assert "radial-gradient(" in body
    assert "box-shadow" in body and "inset" in body, "درخشش باید داخلی باشد، نه سایهٔ جعبه"
    assert "border: 1px" not in body and "border-color" not in body


def test_light_layer_has_no_opaque_card_fill(dashboard_css: str) -> None:
    """روی صفحه هیچ‌جا پس‌زمینهٔ ماتِ کارتی نیست (بلوک چاپ جداگانه بررسی می‌شود)."""
    screen_css = dashboard_css[: dashboard_css.index("@media print")]
    for pattern in ("background: #fff", "background-color: #fff", "background: white",
                    "background: rgb(255"):
        assert pattern not in screen_css, f"پس‌زمینهٔ مات کارتی در لایهٔ داشبورد: {pattern}"


def test_print_renders_hero_light_and_ink_friendly(dashboard_css: str) -> None:
    """چاپ همیشه روشن است: نوار قهرمان سفید می‌شود تا کاغذ سیاه نشود."""
    print_block = dashboard_css[dashboard_css.index("@media print"):]
    assert "background: #ffffff !important" in print_block
    assert "print-color-adjust" not in print_block or "exact" not in print_block


def test_dark_layer_neutralises_legacy_card_hooks(dark_css: str) -> None:
    """کلاس‌های قدیمی برای سازگاری می‌مانند اما در تیره هم قاب نمی‌سازند."""
    body = rule_body(dark_css, "body.dark-mode .dashboard-card")
    assert "background: none" in body
    assert "box-shadow: none" in body


def test_hairline_dividers_are_used(dashboard_css: str) -> None:
    """جداکننده‌ها خط مو هستند، نه مرز کارت."""
    assert "border-inline-start: 1px solid var(--hx-line)" in dashboard_css
    assert "border-block-start: 1px solid var(--hx-line)" in dashboard_css


# --------------------------------------------------------------------------
# ۳) ساختار و داده‌های جدید
# --------------------------------------------------------------------------
NEW_BLOCKS = [
    "hx-atmos",
    "hx-head",
    "hx-hero",
    "hx-ticker",
    "hx-podium",
    "hx-pod",
    "hx-metrics",
    "hx-metric",
    "hx-figures",
    "hx-gauge",
    "hx-split",
    "hx-chartblock",
    "hx-area",
    "hx-bars",
    "hx-boards",
    "hx-board",
    "hx-empty",
]


@pytest.mark.parametrize("block", NEW_BLOCKS)
def test_dashboard_markup_uses_new_blocks(dashboard_markup: str, block: str) -> None:
    assert block in dashboard_markup, f"بلوک جدید در مارک‌آپ نیست: {block}"


def test_dashboard_markup_has_no_legacy_grids(dashboard_markup: str) -> None:
    for legacy in ("dashboard-grid", "dashboard-grid--two-row", "dashboard-chart-row"):
        assert legacy not in dashboard_markup, f"چیدمان قدیمی باقی مانده است: {legacy}"


def test_dashboard_renders_real_svg_charts(dashboard_markup: str) -> None:
    """نمودار ناحیه‌ای با هندسهٔ محاسبه‌شده در سرور رندر می‌شود (بدون JS)."""
    assert "<polyline" in dashboard_markup
    assert 'class="hx-area__fill"' in dashboard_markup
    assert 'pathLength="1"' in dashboard_markup, "برای کشیده‌شدن مسیر، pathLength لازم است"
    assert dashboard_markup.count("hx-area__node") >= 1
    assert "viewBox=" in dashboard_markup


SERVER_VALUES = [
    "total_users",
    "total_pass_time",
    "total_overtime_time",
    "total_leave_taken",
    "total_leave_requests",
    "unique_departments",
    "average_overtime_per_user",
    "average_pass_per_user",
    "overtime_user_count",
    "no_overtime_users",
    "top_department_name",
    "top_department_count",
    "top_overtime_user",
    "top_pass_user",
    "pass_percent",
    "overtime_percent",
    "pass_chart_data",
    "overtime_chart_data",
    "subscription_days_left",
    "subscription_end_date",
    "subscription_remaining_percent",
]


@pytest.mark.parametrize("variable", SERVER_VALUES)
def test_no_server_value_was_dropped(dashboard_markup: str, variable: str) -> None:
    assert variable in dashboard_markup, f"دادهٔ سمت سرور حذف شده است: {variable}"


def test_chart_geometry_is_computed_server_side(dashboard_markup: str) -> None:
    """مختصات نقاط نمودار در قالب محاسبه می‌شود (نه در JS)."""
    assert "%0.1f'|format(" in dashboard_markup or '%0.1f"|format(' in dashboard_markup
    assert "@media (max-width" not in dashboard_markup, "استایل نباید داخل قالب باشد"
    # هیچ رنگی با style اینلاین ست نمی‌شود
    assert "style=\"color" not in dashboard_markup
    assert "style=\"background" not in dashboard_markup


def test_dashboard_is_accessible(dashboard_markup: str) -> None:
    assert dashboard_markup.count("aria-label") >= 4
    assert 'role="img"' in dashboard_markup
    assert dashboard_markup.count("<h3") >= 5, "بخش‌ها باید سرتیتر معنایی داشته باشند"
    assert 'aria-hidden="true"' in dashboard_markup


def test_legacy_hooks_are_kept_for_other_layers(dashboard_markup: str) -> None:
    """قلاب‌های قدیمی برای جدول‌های موبایل و لایهٔ تم تیره باقی مانده‌اند."""
    for hook in ("dashboard-card", "dashboard-quick-card", "dashboard-chart-card",
                 "dashboard-table-card", "dashboard-table", "card-title",
                 "card-value", "card-meta", "table-title", "quick-card-btn"):
        assert hook in dashboard_markup, f"قلاب سازگاری حذف شده است: {hook}"


# --------------------------------------------------------------------------
# ۴) حالت تیره
# --------------------------------------------------------------------------
DARK_SURFACES = [
    "#dashboardBox.hx-dashboard",
    "#dashboardBox .hx-head__title h2",
    "#dashboardBox .hx-chip",
    "#dashboardBox .hx-hero",
    "#dashboardBox .hx-ring__track",
    "#dashboardBox .hx-metric__value",
    "#dashboardBox .hx-metric__icon",
    "#dashboardBox .hx-pod__value",
    "#dashboardBox .hx-gauge .hx-ring__track",
    "#dashboardBox .hx-chartblock::before",
    "#dashboardBox .hx-area__dot",
    "#dashboardBox .hx-bars__name",
    "#dashboardBox .hx-board .dashboard-table thead th",
    "#dashboardBox .hx-rank",
    "#dashboardBox .hx-user__avatar",
    "#dashboardBox .hx-empty",
    "#dashboardBox .hx-foot",
]


@pytest.mark.parametrize("selector", DARK_SURFACES)
def test_dark_theme_covers_new_surfaces(dark_css: str, selector: str) -> None:
    assert f"body.dark-mode {selector}" in dark_css, f"سطح تازه بدون تم تیره: {selector}"


def test_dark_tokens_declared_for_dashboard(dark_css: str) -> None:
    body = rule_body(dark_css, "body.dark-mode #dashboardBox.hx-dashboard")
    for token in ("--hx-ink", "--hx-ink-3", "--hx-line", "--hx-grid-line", "--hx-wash-1"):
        assert token in body, f"توکن تیره تعریف نشده است: {token}"


def test_light_layer_has_no_dark_colors(dashboard_css: str) -> None:
    """رنگ‌های تیره فقط در dark-theme.css مجازند (قاعدهٔ AGENTS.md)."""
    dark_blocks = re.findall(r"body\.dark-mode[^{]*\{[^}]*\}", dashboard_css)
    assert not dark_blocks, "قاعدهٔ تیره داخل dashboard-modern.css نباید باشد"


# --------------------------------------------------------------------------
# ۵) حرکت، بدون‌JS‌سالم‌بودن و دسترس‌پذیری
# --------------------------------------------------------------------------
def test_nothing_is_hidden_in_base_state(dashboard_css: str) -> None:
    """هیچ‌چیز در حالت پایه پنهان نمی‌شود؛ فقط is-in انیمیشن را شروع می‌کند."""
    assert not re.search(r"\.hx-reveal\s*\{[^}]*opacity:\s*0", dashboard_css), (
        "قاعدهٔ پایه نباید reveal را پنهان کند"
    )
    assert ".hx-reveal.is-in" in dashboard_css
    line_rule = rule_body(dashboard_css, "#dashboardBox .hx-area__line")
    assert "stroke-dashoffset: 0" in line_rule, "خط نمودار در حالت پایه باید کامل دیده شود"
    value_rule = rule_body(dashboard_css, "#dashboardBox .hx-bars__value")
    assert "opacity: 0" not in value_rule, "برچسب مقدار ستون نباید در حالت پایه پنهان باشد"


def test_modern_css_features_are_used(dashboard_css: str) -> None:
    """انیمیت متغیرهای CSS با @property و تایپوگرافی سیال با clamp."""
    for token in ("@property --hx-p", "@property --hx-h", "@property --hx-w"):
        assert token in dashboard_css, f"ویژگی مدرن استفاده نشده: {token}"
    assert "clamp(" in dashboard_css
    assert "transform-box: fill-box" in dashboard_css
    assert "mask-image" in dashboard_css


def test_reduced_motion_is_respected(dashboard_css: str, dashboard_js: str) -> None:
    assert "prefers-reduced-motion: reduce" in dashboard_css
    assert "prefers-reduced-motion: reduce" in dashboard_js


def test_animations_are_defined(dashboard_css: str) -> None:
    for animation in (
        "hx-canvas-in",
        "hx-drift-a",
        "hx-drift-b",
        "hx-scan",
        "hx-sheen",
        "hx-pulse",
        "hx-ticker",
        "hx-rise",
        "hx-draw",
        "hx-area-in",
        "hx-node-in",
        "hx-row-in",
        "hx-pop",
    ):
        assert f"@keyframes {animation}" in dashboard_css, f"کی‌فریم تعریف نشده: {animation}"


HOOKS = [
    "data-hx-count",
    "data-hx-ring",
    "data-hx-bar",
    "data-hx-meter",
    "data-hx-split",
    "data-hx-fa",
    "data-hx-spot",
]


@pytest.mark.parametrize("hook", HOOKS)
def test_hooks_are_used_on_both_sides(dashboard_markup: str, dashboard_js: str, hook: str) -> None:
    assert hook in dashboard_markup, f"قلاب در قالب نیست: {hook}"
    camel = "dataset." + re.sub(r"-(\w)", lambda m: m.group(1).upper(), hook.replace("data-", ""))
    assert (hook in dashboard_js) or (camel in dashboard_js), f"قلاب در JS استفاده نشده: {hook}"


def test_bar_height_is_animated_by_the_new_layer(dashboard_css: str, dashboard_js: str) -> None:
    """ارتفاع ستون‌ها با متغیر --hx-h و ترانزیشن CSS از JS تأمین می‌شود."""
    assert "--hx-h" in dashboard_css
    assert "transition: --hx-h" in dashboard_css
    assert "data-percent" in dashboard_js
    assert "[data-hx-bar]" in dashboard_js


def test_js_never_sets_colors_inline(dashboard_js: str) -> None:
    """رنگ‌ها فقط با کلاس/توکن می‌آیند تا لایهٔ تم تیره دست‌نخورده بماند."""
    for forbidden in ("style.color", "style.background", "style.backgroundColor",
                      "style.borderColor", "style.fill", "style.stroke"):
        assert forbidden not in dashboard_js, f"رنگ اینلاین از JS: {forbidden}"


def test_js_has_no_hardcoded_dashboard_numbers(dashboard_js: str) -> None:
    """هیچ عدد نمایشی سمت کلاینت ساخته نمی‌شود (همه از سرور می‌آید)."""
    assert "innerHTML" not in dashboard_js
    assert "textContent = '" not in dashboard_js


def test_rtl_uses_logical_properties(dashboard_css: str) -> None:
    for physical in ("margin-left:", "margin-right:", "padding-left:", "padding-right:"):
        assert physical not in dashboard_css, f"ویژگی فیزیکی در رابط راست‌به‌چپ: {physical}"
    assert "inset-inline-start" in dashboard_css or "inset-inline-end" in dashboard_css
    assert "padding-inline" in dashboard_css


def test_reveal_fallback_prevents_lost_content(dashboard_js: str) -> None:
    """اگر IntersectionObserver کار نکند، محتوا با تایمر امنیتی نمایان می‌شود."""
    assert "IntersectionObserver" in dashboard_js
    assert "revealEverything" in dashboard_js
    assert "setTimeout" in dashboard_js


def test_legacy_bar_height_helper_removed_from_admin_js() -> None:
    admin_js = read(JS_DIR / "admin.js")
    assert "renderDashboardBarHeights" not in admin_js
    assert "bar.style.height" not in admin_js


def test_print_keeps_dashboard_readable(dashboard_css: str) -> None:
    assert "@media print" in dashboard_css
    print_block = dashboard_css[dashboard_css.index("@media print"):]
    assert ".hx-reveal" in print_block
    assert ".hx-atmos" in print_block, "لایهٔ جوی باید در چاپ حذف شود"
