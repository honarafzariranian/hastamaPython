"""تست‌های پوشش حالت تاریک در کل سامانه.

این سوئیت تضمین می‌کند هیچ صفحه یا بخشی بدون تم تیره باقی نماند:

  ۱) هر هفت قالب، لایهٔ مشترک تم (dark-theme.css + theme.js) را لود کنند.
  ۲) لایهٔ مشترک، سطوح کلیدی هر صفحه را پوشش دهد (پنل مدیریت، پنل کاربری،
     صفحات گزارش، مودال‌ها، جدول‌ها و لایهٔ موبایل جدول‌ها).
  ۳) کلید تغییر تم در همهٔ صفحات در دسترس باشد (یا در مارک‌آپ یا دکمهٔ شناور).
  ۴) قواعد چاپ، گزارش‌ها را روشن نگه دارند.

سوئیت رفتاری (jsdom) در tests/js/theme.dom.test.js است و از
tests/test_dark_theme_dom.py اجرا می‌شود.
"""

from __future__ import annotations

import re
from pathlib import Path

import pytest

ROOT = Path(__file__).resolve().parents[1]
CSS_DIR = ROOT / "app" / "static" / "css"
JS_DIR = ROOT / "app" / "static" / "js"
TPL_DIR = ROOT / "app" / "templates"

TEMPLATES = [
    "landing.html",
    "login.html",
    "user-panel.html",
    "admin.html",
    "final_report_page.html",
    "leave_report_page.html",
    "hourlypass_Report_page.html",
    "overtime_report_page.html",
]


def read(path: Path) -> str:
    return path.read_text(encoding="utf-8")


@pytest.fixture(scope="module")
def dark_css() -> str:
    return read(CSS_DIR / "dark-theme.css")


# --------------------------------------------------------------------------
# ۱) اتصال لایهٔ مشترک به همهٔ قالب‌ها
# --------------------------------------------------------------------------
@pytest.mark.parametrize("template", TEMPLATES)
def test_every_template_loads_shared_theme_layer(template: str) -> None:
    html = read(TPL_DIR / template)
    assert "css/dark-theme.css" in html, f"{template}: استایل تم تیره لود نمی‌شود"
    assert "js/theme.js" in html, f"{template}: اسکریپت مشترک تم لود نمی‌شود"


@pytest.mark.parametrize("template", TEMPLATES)
def test_theme_assets_are_loaded_inside_head(template: str) -> None:
    """theme.js باید در <head> باشد تا پیش از رنگ‌آمیزی اجرا شود (بدون پرش سفید)."""
    html = read(TPL_DIR / template)
    head = html.split("</head>", 1)[0]
    assert "css/dark-theme.css" in head, f"{template}: dark-theme.css خارج از head است"
    assert "js/theme.js" in head, f"{template}: theme.js خارج از head است"


@pytest.mark.parametrize("template", TEMPLATES)
def test_dark_theme_css_is_last_stylesheet(template: str) -> None:
    """لایهٔ تیره باید آخرین شیت باشد تا بتواند رنگ‌های روشن را بازنویسی کند."""
    html = read(TPL_DIR / template)
    sheets = re.findall(r"path='css/([a-z0-9\-]+\.css)'", html)
    assert sheets, f"{template}: هیچ استایلی پیدا نشد"
    assert sheets[-1] == "dark-theme.css", (
        f"{template}: dark-theme.css آخرین استایل نیست (ترتیب فعلی: {sheets})"
    )


def test_theme_script_exists() -> None:
    assert (JS_DIR / "theme.js").is_file()
    assert (CSS_DIR / "dark-theme.css").is_file()


# --------------------------------------------------------------------------
# ۲) در دسترس بودن کلید تغییر تم در همهٔ صفحات
# --------------------------------------------------------------------------
@pytest.mark.parametrize("template", TEMPLATES)
def test_theme_toggle_reachable_on_every_page(template: str) -> None:
    """یا کلید تم در مارک‌آپ هست، یا theme.js دکمهٔ شناور می‌سازد."""
    html = read(TPL_DIR / template)
    theme_js = read(JS_DIR / "theme.js")

    has_markup_toggle = any(
        token in html
        for token in ('data-action="toggle-theme"', "themeToggleBtn", "themeToggleButton")
    )
    creates_floating = "theme-toggle-floating" in theme_js
    assert has_markup_toggle or creates_floating, f"{template}: راهی برای تغییر تم وجود ندارد"


def test_login_toggle_is_not_inline_onclick() -> None:
    """کلید تم صفحهٔ ورود باید از لایهٔ مشترک استفاده کند (نه onclick اینلاین)."""
    html = read(TPL_DIR / "login.html")
    assert 'data-action="toggle-theme"' in html
    assert "onclick=\"document.body.classList.toggle('dark-mode')\"" not in html


# --------------------------------------------------------------------------
# ۳) هماهنگی نام کلاس‌ها بین صفحات
# --------------------------------------------------------------------------
def test_theme_script_applies_both_legacy_class_names() -> None:
    """admin.css با .dark-theme و user-panel-style.css با .dark-mode نوشته شده‌اند."""
    js = read(JS_DIR / "theme.js")
    assert "'dark-mode'" in js and "'dark-theme'" in js
    assert "documentElement" in js, "کلاس باید روی <html> هم بنشیند"


def test_theme_choice_is_persisted() -> None:
    js = read(JS_DIR / "theme.js")
    assert "localStorage" in js
    assert "hastama-theme" in js


def test_theme_defaults_to_light() -> None:
    """بدون انتخاب کاربر، تم باید روشن باشد (تیره فقط با toggle صریح)."""
    js = read(JS_DIR / "theme.js")
    assert "readStored() || LIGHT" in js
    assert "prefers-color-scheme" not in js
    html = read(TPL_DIR / "landing.html")
    assert "setItem('hastama-theme', 'dark')" not in html


# --------------------------------------------------------------------------
# ۴) پوشش سطوح — پنل مدیریت
# --------------------------------------------------------------------------
ADMIN_SURFACES = [
    ".management-box",
    "#dashboardBox",
    "#userInfoBox",
    "#newUserBox",
    "#shiftBox",
    "#vacationBox",
    "#overtimeBox",
    "#hourlyPassBox",
    "#ticketBox",
    "#hozoorbox",
    "#natigehHozoor",
    "#sabtdst",
    ".dashboard-card",
    ".dashboard-chart-card",
    ".dashboard-table-card",
    ".dashboard-quick-card",
    ".profile-dropdown",
    ".profile-panel",
    ".sidebar-top-card",
    ".rightSidebar",
    ".topbar-user",
    ".topbar-icon-btn",
    ".mobile-menu-toggle",
    ".shift-popup-content",
    ".status-dropdown",
]


@pytest.mark.parametrize("selector", ADMIN_SURFACES)
def test_admin_surfaces_have_dark_rules(dark_css: str, selector: str) -> None:
    assert f"body.dark-mode {selector}" in dark_css, f"سطح بدون تم تیره: {selector}"


# --------------------------------------------------------------------------
# ۵) پوشش سطوح — پنل کاربری
# --------------------------------------------------------------------------
USER_PANEL_SURFACES = [
    ".Information-box",
    ".ticket-status-box",
    "#text-hour-box",
    ".mrkhc",
    ".report-hour-box",
    ".table-container",
    ".mobile-box",
    "#calender",
    ".calendar-container",
    ".profileHa",
    ".userMassage",
    ".settings-section",
    ".settings-panel-dialog",
    ".profile-panel-dialog",
    ".sidebar-submenu",
    ".onvanMassage",
    ".tozihatMassage",
]


@pytest.mark.parametrize("selector", USER_PANEL_SURFACES)
def test_user_panel_surfaces_have_dark_rules(dark_css: str, selector: str) -> None:
    assert f"body.dark-mode {selector}" in dark_css, f"سطح بدون تم تیره: {selector}"


# --------------------------------------------------------------------------
# ۶) پوشش سطوح — صفحات گزارش (قبلاً هیچ تمی نداشتند)
# --------------------------------------------------------------------------
REPORT_SURFACES = [
    ".report-container",
    ".report-box",
    ".titleBox",
    ".userInfoBox",
    ".hozoorBox",
    ".ezafeBox",
    ".passBox",
    ".hourlyPassBox",
    ".numberReport",
    ".numBox",
    ".exportButtons",
    ".custom-dropdown",
    ".dropdown-options",
    ".dropdown-option",
    ".signature-box",
    ".signature-box-hamkar",
    ".input-container input",
    ".info-item span",
]


@pytest.mark.parametrize("selector", REPORT_SURFACES)
def test_report_page_surfaces_have_dark_rules(dark_css: str, selector: str) -> None:
    assert f"body.dark-mode {selector}" in dark_css, f"سطح بدون تم تیره: {selector}"


# --------------------------------------------------------------------------
# ۷) جدول‌ها، مودال‌ها و فرم‌ها
# --------------------------------------------------------------------------
TABLE_SELECTORS = [
    "#userTable",
    ".request-table",
    ".vacation-table",
    ".individual-report-table",
    ".overTime-table",
    ".hourlyPassReport-table",
    ".ticketUsersReport-table",
    ".hozoorUsersReport-table",
    ".ezafeKarUsersReport-table",
    ".morkhcUsersReport-table",
    ".hourlyPassUsersReport-table",
    ".passsaatiReport-table",
    ".dashboard-table",
    "#OverTimeTable",
    "#leaveTable",
    "#HozoorTableReport",
]


@pytest.mark.parametrize("selector", TABLE_SELECTORS)
def test_tables_have_dark_rules(dark_css: str, selector: str) -> None:
    assert f"body.dark-mode {selector}" in dark_css, f"جدول بدون تم تیره: {selector}"


def test_table_body_rows_and_hover_are_themed(dark_css: str) -> None:
    assert "body.dark-mode table tbody tr" in dark_css
    assert "body.dark-mode table tbody td" in dark_css
    assert "tbody tr:hover" in dark_css


MODAL_SELECTORS = [
    ".modal-content",
    ".modal-content-virayesh",
    ".modal-content-hazfPopup",
    ".modal-content-sabtTicket",
    ".popup-content",
    ".popupSaatHaftehgi",
    ".shift-popup",
    ".MoshahdePopup",
    ".popup-box-massageBox",
    "#calendar-box",
    ".time-picker",
    ".leave-date-picker",
    ".calendar-dropdown",
]


@pytest.mark.parametrize("selector", MODAL_SELECTORS)
def test_modals_and_popups_have_dark_rules(dark_css: str, selector: str) -> None:
    assert f"body.dark-mode {selector}" in dark_css, f"مودال/پاپ‌آپ بدون تم تیره: {selector}"


def test_form_controls_are_themed(dark_css: str) -> None:
    for selector in (
        "body.dark-mode input",
        "body.dark-mode select",
        "body.dark-mode textarea",
        "body.dark-mode option",
        "body.dark-mode ::placeholder",
    ):
        assert selector in dark_css, f"کنترل فرم بدون تم تیره: {selector}"


def test_dark_png_icons_are_inverted(dark_css: str) -> None:
    """آیکون‌های PNG تیره روی سطح تیره نامرئی می‌شوند و باید معکوس شوند."""
    assert ".sidebar-icon" in dark_css
    assert "invert(" in dark_css


# --------------------------------------------------------------------------
# ۸) لایهٔ موبایل جدول‌ها
# --------------------------------------------------------------------------
def test_responsive_tables_layer_supports_dark_mode_class(dark_css: str) -> None:
    """responsive-tables.css فقط .dark-theme را می‌شناخت؛ .dark-mode هم لازم است."""
    for token in ("body.dark-mode .rt-view", "body.dark-mode .rt-sheet", "body.dark-mode .rt-scroll-wrap"):
        assert token in dark_css, f"لایهٔ موبایل جدول بدون تم تیره: {token}"
    assert "--rt-card-bg" in dark_css
    assert "--rt-text" in dark_css


def test_status_badges_are_readable_in_dark(dark_css: str) -> None:
    for badge in ("approved", "rejected", "pending", "sent", "read"):
        assert f"body.dark-mode .rt-badge--{badge}" in dark_css, f"نشان بدون تم تیره: {badge}"


# --------------------------------------------------------------------------
# ۹) چاپ
# --------------------------------------------------------------------------
def test_print_stays_light(dark_css: str) -> None:
    """گزارش کاغذی باید روشن چاپ شود حتی وقتی تم تیره فعال است."""
    assert "@media print" in dark_css
    tail = dark_css.split("@media print", 1)[1]
    assert "#fff" in tail and "#000" in tail


# --------------------------------------------------------------------------
# ۱۰) سلامت فایل
# --------------------------------------------------------------------------
def test_dark_css_braces_are_balanced(dark_css: str) -> None:
    stripped = re.sub(r"/\*.*?\*/", "", dark_css, flags=re.S)
    assert stripped.count("{") == stripped.count("}"), "آکولادهای CSS متوازن نیستند"


def test_dark_css_has_no_invalid_color_tokens(dark_css: str) -> None:
    """جلوگیری از رنگ‌های نامعتبر مثل #2b3purple."""
    stripped = re.sub(r"/\*.*?\*/", "", dark_css, flags=re.S)
    for match in re.finditer(r":\s*(#[0-9a-zA-Z]+)", stripped):
        token = match.group(1)
        assert re.fullmatch(r"#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})", token), (
            f"رنگ نامعتبر در dark-theme.css: {token}"
        )


def test_dark_rules_are_scoped_to_dark_mode(dark_css: str) -> None:
    """هیچ قاعده‌ای نباید تم روشن را تغییر دهد (به‌جز دکمهٔ تم و بلوک چاپ)."""
    stripped = re.sub(r"/\*.*?\*/", "", dark_css, flags=re.S)
    body = stripped.split("@media print", 1)[0]
    allowed_light = (".theme-toggle-floating", ".theme-toggle .theme-toggle-sun")
    for chunk in body.split("}"):
        if "{" not in chunk:
            continue
        selector = chunk.split("{", 1)[0].strip()
        if not selector or selector.startswith("@"):
            continue
        scoped = "dark-mode" in selector or "dark-theme" in selector
        exempt = any(selector.startswith(a) for a in allowed_light)
        assert scoped or exempt, f"قاعدهٔ خارج از حالت تاریک: {selector}"
