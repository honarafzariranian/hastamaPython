"""تست‌های رسیدن تپ به آیتم‌های کشوی سایدبار موبایل (پنل مدیریت)

زمینهٔ باگ (۱۴۰۵/۰۷/۰۶):
  روی موبایل کشو باز می‌شد، ولی تپ روی هیچ‌یک از گزینه‌ها کار نمی‌کرد: کشو بسته
  می‌شد و بخش انتخاب‌شده عوض نمی‌شد.  دو عامل روی هم این وضعیت را می‌ساختند:

    ۱) `will-change: transform, opacity` روی خودِ کشو → مرورگر کشو را به یک
       لایهٔ کامپوزیتورِ مستقل ارتقا می‌داد و منطقهٔ hit-test کامپوزیت‌شدهٔ آن
       پس از باز شدن کشو به‌روز نمی‌شد؛ در نتیجه هیچ ورودی‌ای به داخل کشو
       نمی‌رسید.  (اندازه‌گیری واقعی: `pointerdown` روی مرکز آیتم، هدفش
       `DIV.mobile-sidebar-overlay` بود، درحالی‌که همان نقطه روی خودِ آیتم
       قرار داشت.)
    ۲) پردهٔ تمام‌صفحهٔ پشت کشو (`.mobile-sidebar-overlay` با z-index 790) که
       ورودی می‌گرفت و در همان hit-test برنده می‌شد؛ پس تپ روی هر آیتم فقط
       پرده را لمس می‌کرد و `closeMobileSidebar()` اجرا می‌شد — دقیقاً همان
       رفتاری که کاربر می‌دید: «هیچ گزینه‌ای کار نمی‌کند».

  قرارداد جدید که این تست‌ها از آن محافظت می‌کنند:
    • کشو صریحاً از will-change انصراف می‌دهد (`will-change: auto`).
    • پرده هرگز ورودی نمی‌گیرد؛ فقط یک لایهٔ تزئینی است.
    • «تپ بیرون = بستن» در JS و در فاز capture انجام می‌شود تا هم کشو بسته شود
      و هم همان تپ، دکمهٔ پشت پرده را اشتباهاً فعال نکند.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOBILE_CSS = (ROOT / "app" / "static" / "css" / "admin-mobile-redesign.css").read_text(encoding="utf-8")
MOBILE_JS = (ROOT / "app" / "static" / "js" / "admin-mobile.js").read_text(encoding="utf-8")
ADMIN_HTML = (ROOT / "app" / "templates" / "admin.html").read_text(encoding="utf-8")


def _strip_comments(css: str) -> str:
    """توضیحات CSS را حذف می‌کند (تحلیل‌ها نباید مقدارها را جعل کنند)."""
    out = []
    depth = 0
    index = 0
    while index < len(css):
        two = css[index : index + 2]
        if two == "/*":
            depth += 1
            index += 2
            continue
        if two == "*/" and depth:
            depth -= 1
            index += 2
            continue
        if not depth:
            out.append(css[index])
        index += 1
    return "".join(out)


def _rule(source: str, selector: str, start: int = 0) -> str:
    """متن یک قاعدهٔ CSS را (بدون توضیحات) برمی‌گرداند."""
    at = source.index(selector, start)
    brace = source.index("{", at)
    end = source.index("}", brace)
    return _strip_comments(source[at : end + 1])


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


def test_drawer_opts_out_of_will_change():
    """will-change روی کشوی fixed، مسیر ورودی آیتم‌ها را می‌بست."""
    drawer = _rule(MOBILE_CSS, "\n    .rightSidebar {\n")
    assert "will-change: auto" in drawer, "کشو باید از will-change انصراف بدهد"
    assert "will-change: transform" not in drawer, (
        "will-change باعث می‌شود منطقهٔ hit-test کشو پس از باز شدن به‌روز نشود"
    )


def test_scrim_is_never_interactive():
    """پردهٔ پشت کشو فقط تزئینی است و هیچ‌وقت ورودی نمی‌گیرد."""
    base = _rule(MOBILE_CSS, "\n    .mobile-sidebar-overlay {\n")
    assert "pointer-events: none !important" in base, "پردهٔ بسته هم نباید ورودی بگیرد"

    opened = _rule(MOBILE_CSS, "\n    body.mobile-sidebar-open .mobile-sidebar-overlay {")
    assert "pointer-events: none !important" in opened, (
        "وقتی کشو باز است، پرده در hit-test برنده می‌شد و تپ‌ها را می‌بلعید"
    )


def test_closed_drawer_does_not_swallow_taps_on_touch_devices():
    """کشوی بستهٔ موبایل نباید تپ‌های لبهٔ صفحه را بگیرد."""
    guard = MOBILE_CSS.index(".rightSidebar:not(.open)")
    rule = _rule(MOBILE_CSS, ".rightSidebar:not(.open)", guard)
    assert "pointer-events: none" in rule

    hover_none = MOBILE_CSS.rindex("@media (hover: none)", 0, guard)
    assert hover_none < guard, (
        "این محافظ باید فقط روی دستگاه‌های بدون هاور باشد تا هاور-بازکردن "
        "دسکتاپِ باریک از کار نیفتد"
    )


def test_outside_tap_close_runs_in_the_capture_phase():
    """بستن با تپ بیرون باید در فاز capture و پیش از رسیدن رویداد به محتوا باشد."""
    body = _function_body(MOBILE_JS, "function bindOutsideTapClose()")
    assert "'pointerdown'" in body
    assert "contains(target)" in body, "تپ داخل کشو نباید آن را ببندد"
    assert "mobile-menu-toggle" in body, "تپ روی دکمهٔ منو نباید بلعیده شود"
    assert "stopPropagation()" in body, "تپ بیرون نباید دکمهٔ پشت پرده را فعال کند"
    assert "closeDrawer()" in body
    assert "}, true);" in body, "شنونده باید در فاز capture ثبت شود"
    # پرده دیگر ورودی نمی‌گیرد، پس کلیکی که بعد از همان تپ بیرون ساخته می‌شود هم
    # باید بلعیده شود؛ وگرنه با بستن کشو، دکمهٔ پشت پرده فعال می‌شود.
    assert "addEventListener('click'" in body, "کلیک بعد از تپ بیرون باید خنثی شود"
    assert "swallowClickUntil" in body, "خنثی‌سازی کلیک باید پنجرهٔ زمانی کوتاه داشته باشد"


def test_outside_tap_close_is_wired_into_init():
    assert "bindOutsideTapClose();" in _function_body(MOBILE_JS, "function init()")


def test_overlay_markup_has_no_inline_handler():
    """پردهٔ بی‌اثر نباید هندلر inline داشته باشد (وگرنه گمراه‌کننده است)."""
    overlay_tag = " ".join(
        line.strip() for line in ADMIN_HTML.splitlines() if "mobile-sidebar-overlay" in line
    )
    assert "onclick" not in overlay_tag, "پرده دیگر ورودی نمی‌گیرد؛ onclick آن مرده است"
