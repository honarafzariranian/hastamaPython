"""تست‌های چیدمان صفحهٔ ثبت‌نام (``/register``)

کارت ثبت‌نام یک فرم بلند است (~۱۴۳۰px) که با ``max-height: 92vh`` در یک
ظرف اسکرول داخلی حبس شده بود؛ نتیجه دو نوار اسکرول بود: یکی داخل جعبه و
یکی برای صفحه. حالا کارت با ارتفاع طبیعی خودش کشیده می‌شود و اسکرول به
خود صفحه واگذار شده است.

این تست‌ها همان سه چیزی را تضمین می‌کنند که در مرورگر اندازه‌گیری شد:

  ۱) ``.register-card`` دیگر ظرف اسکرول داخلی نیست (نه ``max-height``، نه
     ``overflow-y: auto``، نه استایل اسکرول‌بار)؛
  ۲) ``overflow: hidden`` باقی می‌ماند، چون گوشه‌های گرد و هالهٔ ``::before``
     به آن وابسته‌اند؛
  ۳) اسکرول به صفحه منتقل شده: ``body`` و ``.register-shell`` نباید اسکرول
     عمودی را قفل کنند و ``.register-shell`` باید ``min-height`` داشته باشد
     — با ``height`` ثابت، ``align-items: center`` سرِ کارت بلند را می‌بُرد.
"""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
TEMPLATE_PATH = ROOT / "app" / "templates" / "register.html"
SHARED_CSS_PATH = ROOT / "app" / "static" / "css" / "login-style.css"


def _strip_comments(css: str) -> str:
    """توضیح‌ها حذف می‌شوند تا متنِ کامنت باعث false positive نشود."""
    return re.sub(r"/\*.*?\*/", "", css, flags=re.DOTALL)


TEMPLATE = _strip_comments(TEMPLATE_PATH.read_text(encoding="utf-8"))
SHARED_CSS = _strip_comments(SHARED_CSS_PATH.read_text(encoding="utf-8"))


def _declarations(css: str, selector: str) -> str:
    """اعلان‌های اولین قاعدهٔ هم‌سطح برای ``selector``.

    در قالب‌ها CSS داخل ``<style>`` تودرتو است، پس تورفتگی ابتدای خط مجاز
    شمرده می‌شود.
    """
    pattern = re.compile(
        rf"^[ \t]*{re.escape(selector)}[ \t]*\{{(?P<body>[^}}]*)\}}", re.MULTILINE
    )
    match = pattern.search(css)
    assert match, f"قاعدهٔ {selector} پیدا نشد"
    return match.group("body")


# ── ۱) کارت، ظرف اسکرول داخلی نیست ────────────────────────────────────────

def test_register_card_is_not_an_inner_scroll_container():
    body = _declarations(TEMPLATE, ".register-card")
    for prop in ("max-height", "overflow-y", "scrollbar-width", "scrollbar-color"):
        assert prop not in body, (
            f"«{prop}» کارت را به یک ظرف اسکرول داخلی تبدیل می‌کند و نوار "
            f"اسکرول دوم را برمی‌گرداند"
        )


def test_register_card_has_no_scrollbar_styling():
    """استایل اسکرول‌بار داخل جعبه حذف شده است."""
    assert not re.search(
        r"\.register-card::-webkit-scrollbar", TEMPLATE
    ), "استایل اسکرول‌بار داخلی کارت باقی مانده است"


def test_register_card_still_clips_corners_and_glow():
    body = _declarations(TEMPLATE, ".register-card")
    assert "overflow: hidden" in body, (
        "overflow: hidden برای گردی گوشه‌ها و هالهٔ ::before لازم است"
    )
    assert "position: relative" in body, "هالهٔ ::before به آن وابسته است"
    assert ".register-card::before" in TEMPLATE


def test_register_card_keeps_its_responsive_rule():
    """قاعدهٔ موبایل دست‌نخورده مانده است."""
    mobile = TEMPLATE[TEMPLATE.index("@media (max-width: 600px)") :]
    body = _declarations(mobile, ".register-card")
    assert "border-radius: 22px" in body
    for prop in ("max-height", "overflow-y"):
        assert prop not in body


# ── ۲) اسکرول به صفحه منتقل شده ───────────────────────────────────────────

def test_page_can_scroll_instead_of_the_card():
    body = _declarations(SHARED_CSS, "body")
    assert "overflow: hidden" not in body, (
        "body نباید اسکرول عمودی را قفل کند؛ فرم بلندتر از viewport است"
    )
    assert "overflow-y: hidden" not in body

    shell = _declarations(TEMPLATE, ".register-shell")
    assert "overflow" not in shell, (
        ".register-shell نباید اسکرول را ببندد؛ محتوا از سمت صفحه اسکرول می‌شود"
    )


def test_register_shell_grows_with_the_card():
    """``min-height`` (نه ``height``) تا کارت بلند در مرکز صفحه بریده نشود."""
    shell = _declarations(TEMPLATE, ".register-shell")
    assert "min-height: 100vh" in shell
    assert re.search(r"(?<!min-)\bheight:\s*100vh", shell) is None, (
        "ارتفاع ثابت + align-items: center بالای کارت بلند را می‌بُرد"
    )
    assert "align-items: center" in shell


def test_background_scene_stays_fixed_while_the_page_scrolls():
    """پس‌زمینه ثابت است، پس اسکرول صفحه ظاهر را تغییر نمی‌دهد."""
    scene = _declarations(SHARED_CSS, ".bg-scene")
    assert "position: fixed" in scene
    assert "inset: 0" in scene
