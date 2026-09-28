"""تست‌های رنگی بودن آیکون‌های نوار تب موبایل

زمینه (۱۴۰۵/۰۷/۰۶):
  SVGهای نوار تب از سایدبار دسکتاپ کپی شده‌اند و رنگشان سفیدِ ثابت است؛ در
  دسکتاپ این آیکون‌ها روی «تایل گرادیانی» هر لهجه می‌نشینند، پس رنگی دیده
  می‌شوند.  نوار تب موبایل آن تایل را نداشت و آیکون‌ها بی‌رنگ (سفید روی شیشه)
  دیده می‌شدند.  اکنون همان پالت لهجه‌ها در نوار تب هم تعریف شده است.
"""
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN_CSS = (ROOT / "app" / "static" / "css" / "admin.css").read_text(encoding="utf-8")
MOBILE_CSS = (ROOT / "app" / "static" / "css" / "admin-mobile-redesign.css").read_text(encoding="utf-8")

DESKTOP_ACCENT = re.compile(
    r"\.rightSidebar \.icon-container\[data-accent=\"(?P<accent>[a-z-]+)\"\]\s*\{[^}]*?"
    r"--tile-from:\s*(?P<from>#[0-9A-Fa-f]{3,8})[^}]*?--tile-to:\s*(?P<to>#[0-9A-Fa-f]{3,8})",
    re.S,
)
MOBILE_ACCENT = re.compile(
    r"\.adm-tab\[data-accent=\"(?P<accent>[a-z-]+)\"\]\s*\{[^}]*?"
    r"--tile-from:\s*(?P<from>#[0-9A-Fa-f]{3,8})[^}]*?--tile-to:\s*(?P<to>#[0-9A-Fa-f]{3,8})",
    re.S,
)


def _palette(pattern: re.Pattern) -> dict:
    return {m.group("accent"): (m.group("from").upper(), m.group("to").upper()) for m in pattern.finditer(MOBILE_CSS if pattern is MOBILE_ACCENT else ADMIN_CSS)}


def test_desktop_palette_is_not_empty():
    assert len(_palette(DESKTOP_ACCENT)) >= 8, "پالت لهجه‌های سایدبار دسکتاپ پیدا نشد"


def test_mobile_tabbar_covers_every_desktop_accent_with_the_same_colors():
    desktop = _palette(DESKTOP_ACCENT)
    mobile = _palette(MOBILE_ACCENT)
    missing = {a: c for a, c in desktop.items() if a not in mobile}
    assert not missing, f"این لهجه‌ها در نوار تب موبایل رنگ ندارند: {sorted(missing)}"
    mismatched = {a: (desktop[a], mobile[a]) for a in desktop if mobile.get(a) != desktop[a]}
    assert not mismatched, f"رنگ لهجه‌ها با دسکتاپ یکی نیست: {mismatched}"


def test_tab_icon_has_a_colored_tile():
    icon = MOBILE_CSS[MOBILE_CSS.index(".adm-tab__icon {") :]
    icon = icon[: icon.index("}")]
    assert "linear-gradient(135deg, var(--tile-from" in icon
    assert "background" in icon
    assert "opacity: .78" in icon, "تب‌های غیرفعال باید کم‌رنگ‌تر از تب فعال باشند"


def test_active_tab_icon_is_fully_opaque_and_lifted():
    active = MOBILE_CSS[MOBILE_CSS.index(".adm-tab.is-active .adm-tab__icon {") :]
    active = active[: active.index("}")]
    assert "opacity: 1" in active
    assert "scale(" in active, "تب فعال باید کمی برجسته شود"


def test_more_tab_has_a_neutral_tile():
    rule = MOBILE_CSS[MOBILE_CSS.index(".adm-tab--more "):]
    rule = rule[: rule.index("}")]
    assert "--tile-from" in rule and "--tile-to" in rule


def test_mobile_js_builds_tabs_from_sidebar_accents():
    mobile_js = (ROOT / "app" / "static" / "js" / "admin-mobile.js").read_text(encoding="utf-8")
    assert "PRIMARY_ACCENTS = ['dashboard', 'staff', 'leave', 'ticket']" in mobile_js
    assert "data-accent" in mobile_js, "تب‌ها باید data-accent سایدبار را حمل کنند تا پالت اعمال شود"
