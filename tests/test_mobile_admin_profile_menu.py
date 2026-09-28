"""تست‌های جای‌گیری منوی پروفایل در پنل مدیریت روی موبایل

زمینهٔ باگ (۱۴۰۵/۰۷/۰۶):
  هدر موبایل (`admin-topbar-modern`) شیشه‌ای است و `backdrop-filter` دارد.
  `backdrop-filter` برای فرزندهای `position: fixed` یک «containing block»
  می‌سازد؛ پس منوی پروفایل که با `bottom: 0` به‌عنوان شیت پایین‌چسب تعریف شده
  بود، در واقع نسبت به *خودِ هدر* پایین می‌چسبید و داخل جعبهٔ هدر دیده می‌شد.
  اکنون منو یک پنل تمام‌عرض است که از لبهٔ پایین هدر شروع می‌شود.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
BASE_CSS = (ROOT / "app" / "static" / "css" / "admin.css").read_text(encoding="utf-8")
MOBILE_CSS = (ROOT / "app" / "static" / "css" / "admin-mobile-redesign.css").read_text(encoding="utf-8")


def _rule(source: str, selector: str, start: int = 0) -> str:
    """متن یک قاعدهٔ CSS را برمی‌گرداند."""
    at = source.index(selector, start)
    brace = source.index("{", at)
    end = source.index("}", brace)
    return source[at : end + 1]


def test_mobile_profile_menu_is_anchored_below_the_header():
    rule = _rule(MOBILE_CSS, "\n    .profile-dropdown {")
    assert "position: fixed !important" in rule
    assert "--adm-header-h" in rule, "لبهٔ بالای منو باید ارتفاع هدر باشد (زیر هدر، نه داخل آن)"
    assert "bottom: auto !important" in rule, "شیت پایین‌چسب باید حذف شده باشد"
    assert "inset-inline: 0 !important" in rule and "width: 100% !important" in rule
    assert "z-index: 1000 !important" in rule, "منو باید روی هدر (۷۰۰) و نوار تب (۶۹۰) بنشیند"


def test_open_state_owns_height_and_animation():
    """حالت بسته باید جمع بماند؛ ارتفاع/انیمیشن فقط در حالت باز تعریف شود."""
    open_rule = _rule(MOBILE_CSS, "\n    .profile-dropdown.open {")
    assert "max-height:" in open_rule
    assert "animation:" in open_rule
    assert "100dvh" in open_rule, "ارتفاع باز باید از سرریز شدن زیر نوار تب جلوگیری کند"
    base_rule = _rule(MOBILE_CSS, "\n    .profile-dropdown {")
    assert "animation" not in base_rule.split("transition")[0], "انیمیشن نباید در حالت بسته باقی بماند"
    assert "max-height" not in base_rule, "max-height: 0 قاعدهٔ پایه باید جمع‌شدن منو را حفظ کند"


def test_bottom_sheet_handle_is_removed_for_the_panel():
    rule = _rule(MOBILE_CSS, "\n    .profile-dropdown::before {")
    assert "display: none" in rule


def test_desktop_dropdown_is_untouched():
    base_rule = _rule(BASE_CSS, "\n.profile-dropdown {")
    assert "position: absolute" in base_rule
    assert "top: calc(100% + 12px)" in base_rule
    open_rule = _rule(BASE_CSS, "\n.profile-dropdown.open {")
    assert "max-height: 680px" in open_rule
