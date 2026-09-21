"""تست‌های بازطراحی مدرن داشبورد مدیریت.

این سوئیت تضمین می‌کند لایهٔ جدید (dashboard-modern.css + dashboard-modern.js)
و مارک‌آپ بازنویسی‌شدهٔ داشبورد:

  ۱) در قالب ادمین بارگذاری شده‌اند، پیش از لایهٔ تم تیره و بعد از admin.css
     (تا اولویت آخر را داشته باشند).
  ۲) ساختار قبلی (کارت‌های تکراری) با ساختار مدرن جایگزین شده، اما هیچ داده‌ای
     از سمت سرور حذف نشده است (همهٔ متغیرهای Jinja هنوز رندر می‌شوند).
  ۳) باکس داشبورد دیگر ارتفاع ثابت/اسکرول داخلی قدیمی را تحمیل نمی‌کند و
     منطق نمایش/پنهان‌سازی بخش‌ها (toggleBox) دست‌نخورده مانده است.
  ۴) همهٔ سطوح جدید در حالت تیره پوشش دارند.
  ۵) حرکت‌ها به prefers-reduced-motion احترام می‌گذارند و قلاب‌های
     data-hx-* بین قالب و JS هم‌خوان‌اند.

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
    end = template.index('<!-- باکس مدیریت کارکنان با تب‌ها -->', start)
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
    box_rules = dashboard_css[dashboard_css.index("#dashboardBox.hx-dashboard"):]
    box_rules = box_rules[: box_rules.index("}")]
    assert "display:" not in box_rules, "display نباید در قاعدهٔ باکس داشبورد ست شود"
    assert 'class="management-box hx-dashboard" id="dashboardBox"' in template
    assert 'style="display: flex;"' not in template


# --------------------------------------------------------------------------
# ۲) ساختار جدید مارک‌آپ
# --------------------------------------------------------------------------
NEW_BLOCKS = [
    "hx-dash",
    "hx-head",
    "hx-hero",
    "hx-spot-row",
    "hx-kpi-grid",
    "hx-insights",
    "hx-charts",
    "hx-tables",
    "hx-table-card",
]


@pytest.mark.parametrize("block", NEW_BLOCKS)
def test_dashboard_markup_uses_modern_blocks(dashboard_markup: str, block: str) -> None:
    assert block in dashboard_markup, f"بلوک جدید در مارک‌آپ نیست: {block}"


def test_dashboard_markup_has_no_legacy_grids(dashboard_markup: str) -> None:
    for legacy in ("dashboard-grid", "dashboard-grid--two-row", "dashboard-chart-row"):
        assert legacy not in dashboard_markup, f"چیدمان قدیمی باقی مانده است: {legacy}"


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
]


@pytest.mark.parametrize("variable", SERVER_VALUES)
def test_no_server_value_was_dropped(dashboard_markup: str, variable: str) -> None:
    assert variable in dashboard_markup, f"دادهٔ سمت سرور حذف شده است: {variable}"


def test_dashboard_is_accessible(dashboard_markup: str) -> None:
    assert dashboard_markup.count("aria-label") >= 3
    assert 'role="img"' in dashboard_markup
    assert dashboard_markup.count("<h3") >= 5, "کارت‌ها باید سرتیتر معنایی داشته باشند"


# --------------------------------------------------------------------------
# ۳) حالت تیره
# --------------------------------------------------------------------------
DARK_SURFACES = [
    "#dashboardBox .hx-dash::before",
    "#dashboardBox .hx-head__text h2",
    "#dashboardBox .hx-chip",
    "#dashboardBox .hx-hero",
    "#dashboardBox .hx-kpi",
    "#dashboardBox .hx-spot",
    "#dashboardBox .hx-card",
    "#dashboardBox .hx-kpi__icon",
    "#dashboardBox .hx-chart",
    "#dashboardBox .hx-col__track",
    "#dashboardBox .hx-gauge .hx-ring__track",
    "#dashboardBox .hx-table-card .dashboard-table thead th",
    "#dashboardBox .hx-rank",
    "#dashboardBox .hx-empty",
]


@pytest.mark.parametrize("selector", DARK_SURFACES)
def test_dark_theme_covers_new_surfaces(dark_css: str, selector: str) -> None:
    assert f"body.dark-mode {selector}" in dark_css, f"سطح تازه بدون تم تیره: {selector}"


def test_dark_tokens_declared_for_dashboard(dark_css: str) -> None:
    assert "body.dark-mode #dashboardBox.hx-dashboard {" in dark_css
    for token in ("--hx-surface", "--hx-ink", "--hx-border"):
        assert token in dark_css, f"توکن تیره تعریف نشده است: {token}"


def test_light_layer_has_no_dark_colors(dashboard_css: str) -> None:
    """رنگ‌های تیره فقط در dark-theme.css مجازند (قاعدهٔ AGENTS.md)."""
    dark_blocks = re.findall(r"body\.dark-mode[^{]*\{[^}]*\}", dashboard_css)
    assert not dark_blocks, "قاعدهٔ تیره داخل dashboard-modern.css نباید باشد"


# --------------------------------------------------------------------------
# ۴) حرکت، دسترس‌پذیری و قرارداد قلاب‌ها
# --------------------------------------------------------------------------
def test_reduced_motion_is_respected(dashboard_css: str, dashboard_js: str) -> None:
    assert "prefers-reduced-motion: reduce" in dashboard_css
    assert "prefers-reduced-motion: reduce" in dashboard_js


def test_animations_are_defined(dashboard_css: str) -> None:
    for animation in ("hx-aurora-drift", "hx-sheen", "hx-pulse", "hx-box-in"):
        assert f"@keyframes {animation}" in dashboard_css, f"کی‌فریم تعریف نشده: {animation}"


HOOKS = ["data-hx-count", "data-hx-ring", "data-hx-share", "data-hx-split", "data-hx-part"]


@pytest.mark.parametrize("hook", HOOKS)
def test_hooks_are_used_on_both_sides(dashboard_markup: str, dashboard_js: str, hook: str) -> None:
    assert hook in dashboard_markup, f"قلاب در قالب نیست: {hook}"
    js_name = hook.replace("data-", "").replace("-", "")  # hxCount → dataset.hxCount
    camel = "dataset." + re.sub(r"-(\w)", lambda m: m.group(1).upper(), hook.replace("data-", ""))
    assert (hook in dashboard_js) or (camel in dashboard_js), f"قلاب در JS استفاده نشده: {hook}"


def test_bar_height_is_animated_by_the_new_layer(dashboard_css: str, dashboard_js: str) -> None:
    """ارتفاع میله‌ها با متغیر --hx-h و انیمیشن از JS تأمین می‌شود."""
    assert "--hx-h" in dashboard_css
    assert "data-percent" in dashboard_js
    assert '.hx-col__fill[data-percent]' in dashboard_js


def test_legacy_bar_height_helper_removed_from_admin_js() -> None:
    admin_js = read(JS_DIR / "admin.js")
    assert "renderDashboardBarHeights" not in admin_js
    assert "bar.style.height" not in admin_js


def test_print_keeps_dashboard_readable(dashboard_css: str) -> None:
    assert "@media print" in dashboard_css
    assert ".hx-reveal" in dashboard_css
