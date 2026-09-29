"""تست‌های انیمیشن کشوی سایدبار موبایل در پنل مدیریت

زمینه (۱۴۰۵/۰۷/۰۶):
  کشوی سایدبار روی موبایل فقط با یک `transform` ساده باز/بسته می‌شد (خشک و
  بی‌جان) و دکمهٔ «خروج» به‌صورت درون‌خطی زیر بقیهٔ آیتم‌ها می‌نشست.  اکنون:

    ۱) باز/بسته شدن با ease فنری + محو شدن نرم انجام می‌شود.
    ۲) آیتم‌ها پله‌ای (با `--adm-i` که در JS ست می‌شود) ظاهر می‌شوند.
    ۳) جداکننده `margin-top: auto` دارد و دکمهٔ خروج sticky و پایینِ کشو
       می‌مانند.

  نکتهٔ مهمی که این تست‌ها از آن محافظت می‌کنند: قاعدهٔ پایهٔ
  `.rightSidebar .icon-container` یک `transition` کامل دارد؛ اگر ترتیب
  پله‌ای *پیش از* آن تعریف شود، shorthand آن را بازنویسی می‌کند و محو شدن
  آیتم‌ها از بین می‌رود.  پس ترتیب اعلان‌ها خودش بخشی از قرارداد است.
"""
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CSS_PATH = ROOT / "app" / "static" / "css" / "admin-mobile-redesign.css"
JS_PATH = ROOT / "app" / "static" / "js" / "admin-mobile.js"
MOBILE_CSS = CSS_PATH.read_text(encoding="utf-8")
MOBILE_JS = JS_PATH.read_text(encoding="utf-8")

OUTER_MEDIA = "@media screen and (max-width: 768px) {"


def _rule(source: str, selector: str, start: int = 0) -> str:
    """متن یک قاعدهٔ CSS را برمی‌گرداند."""
    at = source.index(selector, start)
    brace = source.index("{", at)
    end = source.index("}", brace)
    return source[at : end + 1]


def test_every_drawer_rule_lives_inside_the_mobile_media_query():
    end = MOBILE_CSS.index("\n}\n", MOBILE_CSS.index(OUTER_MEDIA))
    mobile_block = MOBILE_CSS[MOBILE_CSS.index(OUTER_MEDIA) : end]
    for selector in (
        "\n    .rightSidebar {\n",
        "\n    .rightSidebar.open > .icon-container",
        '\n    .rightSidebar .icon-container[data-accent="exit"] {\n',
    ):
        assert selector in mobile_block, f"قاعدهٔ {selector.strip()} باید فقط در حالت موبایل باشد"


def test_drawer_opens_with_a_spring_and_fades():
    rule = _rule(MOBILE_CSS, "\n    .rightSidebar {\n")
    assert "transform: translateX(" in rule
    assert "opacity:" in rule, "کشوی بسته باید نیم‌محو باشد تا باز شدن نرم دیده شود"
    assert "var(--adm-ease-spring)" in rule, "باز شدن باید ease فنری داشته باشد"
    # باگ ۱۴۰۵/۰۷/۰۶: `will-change: transform, opacity` روی همین قاعده، کشو را
    # به یک لایهٔ کامپوزیتور مستقل ارتقا می‌داد و منطقهٔ hit-test آن پس از باز
    # شدن به‌روز نمی‌شد؛ نتیجه این بود که هیچ تپی به آیتم‌های کشو نمی‌رسید و
    # انتخاب هر بخش فقط کشو را می‌بست. پس کشو باید صریحاً از will-change
    # انصراف بدهد.
    assert "will-change: auto" in rule, (
        "will-change روی کشوی fixed مسیر ورودی آیتم‌ها را می‌بندد"
    )

    open_rule = _rule(MOBILE_CSS, "\n    .rightSidebar.open {\n")
    assert "translateX(0)" in open_rule and "scale(1)" in open_rule
    assert "opacity: 1" in open_rule


def test_items_are_staggered_but_the_transition_is_declared_after_the_base_rule():
    hidden = _rule(MOBILE_CSS, "\n    .rightSidebar > .icon-container,")
    assert "opacity: 0" in hidden, "آیتم‌ها باید از حالت محو شروع کنند"
    assert "translateX(22px)" in hidden, "آیتم‌ها باید با جابه‌جایی کوچک وارد شوند"

    open_state = _rule(MOBILE_CSS, "\n    .rightSidebar.open > .icon-container")
    assert "opacity: 1" in open_state and "transform: none" in open_state

    # قاعدهٔ پایهٔ آیتم‌ها یک shorthand کامل transition دارد؛ بلوک زمان‌بندی
    # پله‌ای باید *بعد از* آن اعلان شده باشد.
    base = MOBILE_CSS.index("\n    .rightSidebar .icon-container,\n")
    timing = MOBILE_CSS.rindex("\n    .rightSidebar > .icon-container,")
    assert timing > base, (
        "بلوک زمان‌بندی پله‌ای باید بعد از قاعدهٔ پایه بیاید؛ در غیر این صورت "
        "shorthand مربوط به transition آن را بازنویسی می‌کند و محو شدن حذف می‌شود"
    )
    timing_rule = _rule(MOBILE_CSS, "\n    .rightSidebar > .icon-container,", timing)
    assert "opacity .28s" in timing_rule
    assert "transform .45s var(--adm-ease-spring)" in timing_rule

    delay_rule = _rule(MOBILE_CSS, "\n    .rightSidebar.open > .icon-container", timing)
    assert "calc(var(--adm-i, 0) * 20ms)" in delay_rule, "تأخیر پله‌ای باید بر اساس --adm-i باشد"


def test_logout_is_pinned_to_the_bottom_of_the_drawer():
    divider = _rule(MOBILE_CSS, "\n    .rightSidebar .sidebar-divider {")
    assert "margin: auto 4px 6px !important" in divider, (
        "margin-top: auto جداکننده را به پاورقی می‌چسباند (مثل سایدبار دسکتاپ)"
    )

    exit_rule = _rule(MOBILE_CSS, '\n    .rightSidebar .icon-container[data-accent="exit"] {')
    assert "position: sticky" in exit_rule
    assert "bottom: calc(var(--adm-safe-b)" in exit_rule, "خروج باید بالای ناحیهٔ امن پایین بماند"
    assert "z-index" in exit_rule and "backdrop-filter" in exit_rule

    # بازطراحی ۱۴۰۵/۰۷/۰۶: دکمهٔ خروج باید ظاهرِ کشوی موبایل را داشته باشد، نه
    # کارت شیشه‌ای شناور دسکتاپ.  blur و سایهٔ بالارونده در کشوی تمام‌قد مثل یک
    # کارت بی‌ربط روی محتوا می‌افتاد، پس هر دو باید صریحاً خنثی شوند.
    assert "box-shadow: none !important" in exit_rule, "سایهٔ دسکتاپی خروج در موبایل حذف می‌شود"
    assert "backdrop-filter: none !important" in exit_rule, "blur شیشه‌ای خروج در موبایل حذف می‌شود"
    assert "var(--adm-surface)" in exit_rule, "پس‌زمینهٔ خروج باید مات باشد تا اسکرول از پشتش دیده نشود"


def test_javascript_indexes_the_drawer_items():
    assert "function indexDrawerItems" in MOBILE_JS
    assert "setProperty('--adm-i'" in MOBILE_JS
    assert "indexDrawerItems();" in MOBILE_JS, "باید در init و هنگام تغییر کلاس‌ها صدا زده شود"
    assert "'.adm-drawer-head, .icon-container, .sidebar-divider'" in MOBILE_JS, (
        "ترتیب پله‌ای باید ترتیب DOM باشد"
    )


def test_reduced_motion_disables_the_stagger_delay():
    block = MOBILE_CSS[MOBILE_CSS.index("(prefers-reduced-motion: reduce)") :]
    assert "transition-duration: .001ms !important" in block
    assert "transition-delay: 0s !important" in block, (
        "برای کاربران کم‌حرکت، تأخیر پله‌ای نباید ظاهر پله‌ای بسازد"
    )
