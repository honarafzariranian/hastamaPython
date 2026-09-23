/* =============================================================================
   theme.js — لایهٔ مشترک مدیریت تم روشن/تاریک سامانهٔ هستما
   -----------------------------------------------------------------------------
   چرا این فایل لازم است؟
     • پیش از این هر صفحه تم را جداگانه و ناقص مدیریت می‌کرد:
         - admin.html   → body.classList.toggle('dark-theme')
         - user-panel   → body.classList.toggle('dark-mode')
         - login.html   → onclick اینلاین روی body
         - صفحات گزارش  → اصلاً تمی نداشتند
       در نتیجه نام کلاس‌ها ناهمگون بود، تم با رفتن به صفحهٔ بعد از بین می‌رفت و
       بخش‌های زیادی از برنامه هرگز تیره نمی‌شدند.

   این ماژول تنها منبع حقیقت تم است:
     ۱) کلاس‌های `dark-mode` و `dark-theme` را هم‌زمان روی <html> و <body> ست
        می‌کند تا همهٔ استایل‌های قدیمی (با هر دو نام) بدون بازنویسی کار کنند.
      ۲) انتخاب کاربر را در localStorage نگه می‌دارد؛ تم بین صفحات حفظ می‌شود.
      ۳) پیش‌فرض همیشه «روشن» است؛ تیره فقط با انتخاب صریح کاربر.
     ۴) در صفحاتی که دکمهٔ تم ندارند (صفحات گزارش) یک دکمهٔ شناور می‌سازد.
     ۵) پیش از رنگ‌آمیزی اولیه اجرا می‌شود تا «پرش سفید» (FOUC) رخ ندهد؛ به همین
        دلیل باید در <head> و به‌صورت غیر async بارگذاری شود.
   ========================================================================== */
(function (window, document) {
    'use strict';

    var STORAGE_KEY = 'hastama-theme';
    var DARK = 'dark';
    var LIGHT = 'light';
    var CLASSES = ['dark-mode', 'dark-theme'];

    function readStored() {
        try {
            var v = window.localStorage.getItem(STORAGE_KEY);
            return v === DARK || v === LIGHT ? v : null;
        } catch (e) {
            return null;
        }
    }

    function store(theme) {
        try {
            window.localStorage.setItem(STORAGE_KEY, theme);
        } catch (e) {
            /* حالت مرورگر خصوصی: بی‌صدا رد شو */
        }
    }

    function resolved() {
        return readStored() || LIGHT;
    }

    function paint(theme) {
        var isDark = theme === DARK;
        var targets = [document.documentElement, document.body];

        for (var i = 0; i < targets.length; i++) {
            var el = targets[i];
            if (!el) continue;
            for (var c = 0; c < CLASSES.length; c++) {
                el.classList[isDark ? 'add' : 'remove'](CLASSES[c]);
            }
        }

        if (document.documentElement) {
            document.documentElement.setAttribute('data-theme', theme);
            // کنترل‌های بومی مرورگر (اسکرول‌بار، select، date picker) را هم‌رنگ می‌کند
            document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
        }

        syncButtons(isDark);
    }

    function syncButtons(isDark) {
        var label = isDark ? 'روشن کردن تم' : 'تاریک کردن تم';
        var nodes = document.querySelectorAll(
            '[data-action="toggle-theme"], #themeToggleBtn, #themeToggleButton, .theme-toggle'
        );
        for (var i = 0; i < nodes.length; i++) {
            nodes[i].setAttribute('aria-label', label);
            nodes[i].setAttribute('title', label);
            nodes[i].setAttribute('aria-pressed', isDark ? 'true' : 'false');
            nodes[i].classList.toggle('is-dark', isDark);
        }
    }

    function setTheme(theme) {
        var next = theme === DARK ? DARK : LIGHT;
        store(next);
        paint(next);
        try {
            window.dispatchEvent(new CustomEvent('hastama:themechange', { detail: { theme: next } }));
        } catch (e) {
            /* مرورگرهای قدیمی */
        }
        return next;
    }

    function toggle() {
        return setTheme(resolved() === DARK ? LIGHT : DARK);
    }

    /* دکمهٔ شناور فقط برای صفحاتی که هیچ کلید تمی در مارک‌آپ ندارند (گزارش‌ها) */
    function ensureFloatingToggle() {
        if (document.querySelector('[data-action="toggle-theme"], #themeToggleBtn, #themeToggleButton, .theme-toggle')) {
            return;
        }
        if (document.body.hasAttribute('data-no-theme-toggle')) return;

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'theme-toggle-floating';
        btn.setAttribute('data-action', 'toggle-theme');
        btn.innerHTML =
            '<svg class="theme-toggle-floating__moon" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
            '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79Z" stroke="currentColor" stroke-width="1.8"' +
            ' stroke-linecap="round" stroke-linejoin="round"/></svg>' +
            '<svg class="theme-toggle-floating__sun" viewBox="0 0 24 24" fill="none" aria-hidden="true">' +
            '<circle cx="12" cy="12" r="4.2" stroke="currentColor" stroke-width="1.8"/>' +
            '<path d="M12 2.6v2.2M12 19.2v2.2M2.6 12h2.2M19.2 12h2.2M5.3 5.3l1.6 1.6M17.1 17.1l1.6 1.6' +
            'M18.7 5.3l-1.6 1.6M6.9 17.1l-1.6 1.6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>';
        document.body.appendChild(btn);
        syncButtons(resolved() === DARK);
    }

    /* یک شنوندهٔ سراسری: هر عنصری با data-action="toggle-theme" یا آیدی‌های
       شناخته‌شده، بدون نیاز به onclick اینلاین کار می‌کند (سازگار با CSP). */
    var TOGGLE_SELECTOR = '[data-action="toggle-theme"], #themeToggleBtn, #themeToggleButton, .theme-toggle';

    function bindDelegate() {
        document.addEventListener('click', function (e) {
            var el = e.target.closest ? e.target.closest(TOGGLE_SELECTOR) : null;
            if (!el) return;
            e.preventDefault();
            toggle();
        });

        /* دسترس‌پذیری: کلیدهای Enter/Space روی کلیدهایی که <button> نیستند
           (مثل div.topbar-icon-btn) هم تم را عوض کنند. عناصر بومی مثل
           button/a/input خودشان کلیک کیبوردی دارند و رد می‌شوند. */
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter' && e.key !== ' ' && e.key !== 'Spacebar') return;
            var el = e.target && e.target.closest ? e.target.closest(TOGGLE_SELECTOR) : null;
            if (!el) return;
            var tag = el.tagName;
            if (tag === 'BUTTON' || tag === 'A' || tag === 'INPUT') return;
            e.preventDefault();
            toggle();
        });
    }

    // ── اجرای فوری، پیش از رندر بدنه، برای جلوگیری از پرش سفید ───────────────
    paint(resolved());

    var booted = false;

    function boot() {
        paint(resolved());          // حالا <body> هم موجود است
        ensureFloatingToggle();
        if (booted) return;         // شنوندهٔ کلیک فقط یک‌بار وصل شود
        booted = true;
        bindDelegate();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.HastamaTheme = {
        get: resolved,
        set: setTheme,
        toggle: toggle,
        isDark: function () { return resolved() === DARK; },
        STORAGE_KEY: STORAGE_KEY
    };

    // سازگاری با کدهای قدیمی که مستقیماً toggleTheme() صدا می‌زدند
    if (typeof window.toggleTheme !== 'function') {
        window.toggleTheme = toggle;
    }
})(window, document);
