"""تست‌های قفل اسکرول پنل مدیریت در حالت موبایل

زمینهٔ باگ (۱۴۰۵/۰۷/۰۶):
  روی موبایل، باز شدن کشوی سایدبار به `<body>` کلاس `mobile-sidebar-open`
  می‌دهد و آن کلاس `overflow: hidden` می‌گیرد.  اگر این کلاس *بدون* کشوی باز
  باقی بماند، کل صفحهٔ مدیریت دیگر اسکرول نمی‌شود و کاربر نمی‌تواند باکس‌های
  پایین‌تر داشبورد را ببیند.  دو مسیر واقعی این وضعیت را می‌ساخت:

    ۱) انتخاب یک بخش از منو: `navTo()` فقط باکس را عوض می‌کرد (`pushState`) و
       کشو/قفل را نمی‌بست؛
    ۲) خطا در هندلر بستن کشو: `syncTabs()` قبل از `closeDrawer()` اجرا می‌شد و
       اگر خطا می‌داد، قفل روی body می‌ماند.

این تست‌ها همان دو محافظ + محافظ «هاور روی دستگاه لمسی» را تضمین می‌کنند.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN_JS = (ROOT / "app" / "static" / "js" / "admin.js").read_text(encoding="utf-8")
MOBILE_JS = (ROOT / "app" / "static" / "js" / "admin-mobile.js").read_text(encoding="utf-8")
MOBILE_CSS = (ROOT / "app" / "static" / "css" / "admin-mobile-redesign.css").read_text(encoding="utf-8")


def _function_body(source: str, signature: str) -> str:
    """بدنهٔ تابع را از اولین { تا } هم‌تراز برمی‌گرداند."""
    start = source.index(signature)
    brace = source.index("{", start)
    depth = 0
    for index in range(brace, len(source)):
        if source[index] == "{":
            depth += 1
        elif source[index] == "}":
            depth -= 1
            if depth == 0:
                return source[brace : index + 1]
    raise AssertionError(f"تابع بسته نشده است: {signature}")


def test_nav_to_closes_the_mobile_drawer():
    """انتخاب بخش از کشو نباید قفل اسکرول را باقی بگذارد."""
    body = _function_body(ADMIN_JS, "function navTo(boxId, el, url)")
    assert "closeMobileSidebar()" in body, "navTo باید کشوی موبایل را ببندد"
    assert "classList.contains('open')" in body, "فقط وقتی کشو باز است باید بسته شود"
    assert "window.innerWidth <= 768" in body, "رفتار دسکتاپ نباید تغییر کند"


def test_nav_to_still_switches_section_and_pushes_history():
    """محافظ جدید نباید منطق اصلی انتخاب بخش را خراب کند."""
    body = _function_body(ADMIN_JS, "function navTo(boxId, el, url)")
    assert "toggleBox(boxId, el);" in body
    assert "window.history.pushState" in body


def test_drawer_click_closes_drawer_even_if_tab_sync_fails():
    """closeDrawer باید در همهٔ حالات اجرا شود، حتی با خطای syncTabs."""
    body = _function_body(MOBILE_JS, "function bindDrawerGestures()")
    handler = body[body.index("sidebar.addEventListener('click'") :]
    try_block = handler.index("try {")
    catch_block = handler.index("} catch")
    close_call = handler.index("closeDrawer();")
    assert try_block < catch_block < close_call, "closeDrawer باید بیرون از try/catch و بعد از آن باشد"
    assert "syncTabs();" in handler[try_block:catch_block]


def test_stale_scroll_lock_guard_is_installed():
    """نگهبان قفل اسکرول باید وجود داشته باشد، در init صدا زده شود و قابل تست باشد."""
    assert "function releaseStaleScrollLock()" in MOBILE_JS
    assert "watchScrollLock();" in _function_body(MOBILE_JS, "function init()")
    assert "releaseStaleScrollLock: releaseStaleScrollLock" in MOBILE_JS
    guard = _function_body(MOBILE_JS, "function releaseStaleScrollLock()")
    assert "mobile-sidebar-open" in guard
    assert "classList.contains('open')" in guard, "قفلِ کشوی واقعاً باز باید محفوظ بماند"


def test_scroll_lock_guard_reacts_to_class_changes_and_bfcache():
    body = _function_body(MOBILE_JS, "function watchScrollLock()")
    assert "MutationObserver" in body, "تغییر کلاس body باید رصد شود"
    assert "'pageshow'" in body, "برگشت از bfcache باید بررسی شود"
    assert "releaseStaleScrollLock" in body


def test_drawer_hover_open_is_limited_to_hovering_devices():
    """روی دستگاه لمسی، :hover پایدار نباید کشو را روی صفحه نگه دارد."""
    open_rule = MOBILE_CSS.index(".rightSidebar.open {")
    hover_media = MOBILE_CSS.index("@media (hover: hover) and (pointer: fine)")
    hover_rule = MOBILE_CSS.index(".rightSidebar:hover", open_rule)
    assert open_rule < hover_media < hover_rule, "قاعدهٔ :hover باید داخل media query هاور باشد"
    open_block = MOBILE_CSS[open_rule:hover_media]
    assert "transform: translateX(0)" in open_block, "کشوی باز باید مستقل از هاور کار کند"


def test_admin_page_still_loads_the_mobile_layer():
    admin_html = (ROOT / "app" / "templates" / "admin.html").read_text(encoding="utf-8")
    assert "js/admin-mobile.js" in admin_html
    assert "css/admin-mobile-redesign.css" in admin_html
    assert re.search(r"id=\"dashboardBox\"", admin_html)


# ── ۱۴۰۵/۰۷/۰۷ — دام «اسکرول‌کانتینرِ body» ─────────────────────────────
#
# گزارش کاربر: روی موبایل صفحهٔ /admin/dashboard اسکرول نمی‌شد و پایین باکس
# داشبورد دیده نمی‌شد، در حالی که همان صفحه با چرخ ماوس روی دسکتاپ درست
# اسکرول می‌شد.  علت در لایهٔ موبایل بود:
#
#   html { overflow-x: hidden }  →  overflow-y محاسبه‌شده = auto
#   body { overflow-x: hidden }  →  body یک اسکرول‌کانتینر می‌شود
#
# چون ارتفاع body خودکار است، دامنهٔ اسکرولش فقط چند پیکسل بود (اندازه‌گیری
# زنده: 4px در برابر 1840px دامنهٔ viewport).  روی دستگاه لمسی ژست کاربر به
# همان body قفل می‌شود، آن چند پیکسل را مصرف می‌کند و چون
# ``overscroll-behavior-y: none`` روی body بود، هرگز به viewport زنجیر نمی‌شود
# → «موبایل اسکرول نمی‌شود».  مسیر اسکرول چرخ ماوس متفاوت است، پس باگ روی
# دسکتاپ دیده نمی‌شد.
#
# ``overflow-x: clip`` اسکرول‌کانتینر نمی‌سازد (همان کاری که admin.css و
# responsive-mobile.css از قبل برای html/body می‌کنند) و ``overscroll-behavior``
# هم باید روی روت بماند، نه روی body.


def _strip_comments(css: str) -> str:
    return re.sub(r"/\*.*?\*/", "", css, flags=re.DOTALL)


def _rule_block(css: str, anchor: str) -> str:
    """قاعده‌ای که ``anchor`` داخل آن است را از { تا } هم‌تراز برمی‌گرداند."""
    at = css.index(anchor)
    opening = css.rindex("{", 0, at)
    depth = 0
    for index in range(opening, len(css)):
        if css[index] == "{":
            depth += 1
        elif css[index] == "}":
            depth -= 1
            if depth == 0:
                return css[opening : index + 1]
    raise AssertionError("قاعده بسته نشده است")


def test_mobile_sheet_does_not_turn_body_into_a_scroll_container():
    """html/body لایهٔ موبایل نباید اسکرول‌کانتینر بسازند."""
    html_rule = _strip_comments(_rule_block(MOBILE_CSS, "-webkit-text-size-adjust: 100%"))
    body_rule = _strip_comments(_rule_block(MOBILE_CSS, "background-attachment: fixed"))

    assert "overflow-x: clip" in html_rule, "html باید clip باشد تا overflow-y=visible بماند"
    assert "overflow-x: hidden" not in html_rule, "hidden روی html، body را اسکرول‌کانتینر می‌کند"

    assert "overflow-x: clip" in body_rule, "body باید clip باشد، نه hidden"
    assert "overflow-x: hidden" not in body_rule, "hidden روی body ژست لمسی را قفل می‌کند"
    assert "overscroll-behavior-y" not in body_rule, "نباید روی body باشد؛ زنجیرهٔ اسکرول لمسی را می‌بندد"
    assert "overscroll-behavior-y: none" in html_rule, "قفل overscroll باید روی روت بماند"


def test_mobile_layer_is_linked_with_a_cache_buster():
    """بدون توکن نسخه، اصلاح CSS تا ۴ ساعت به گوشی کاربر نمی‌رسد."""
    admin_html = (ROOT / "app" / "templates" / "admin.html").read_text(encoding="utf-8")
    assert re.search(
        r"css/admin-mobile-redesign\.css'\)\s*\}\}\?v=\d+", admin_html
    ), "لینک لایهٔ موبایل باید ?v=<تاریخ> داشته باشد"
