/* =============================================================================
   dashboard-modern.js — لایهٔ حرکتی «بوم داده»ی داشبورد مدیریت
   -----------------------------------------------------------------------------
   همهٔ مقادیر از سمت سرور در HTML رندر شده‌اند؛ این فایل «فقط» حرکت می‌آورد:

     ۱) ورود پله‌ای بلوک‌ها هنگام دیده‌شدن (IntersectionObserver → کلاس is-in)
     ۲) شمارش نرم اعداد با حفظ ارقام فارسی و ترتیب کاراکترها (۲۳۱، ۱۵:۰۵، ۱۴۰۵/۱۲/۲۹)
     ۳) رشد ستون‌های نمودار (data-percent → متغیر ثبت‌شدهٔ --hx-h در CSS)
     ۴) جاروی حلقه‌های پیشرفت (data-hx-ring → --hx-len و --hx-p)
     ۵) عرض نوارهای سهم و نوار توزیع پرسنل
     ۶) هالهٔ دنبال‌کنندهٔ نشانگر روی عناصری با data-hx-spot
     ۷) ساعت/تاریخ زندهٔ سربرگ داشبورد
     ۸) تبدیل ارقام لاتین به فارسی در نشانگرهای رتبه (data-hx-fa)

   اصول:
     • هیچ رنگی با style اینلاین ست نمی‌شود؛ فقط متغیرهای چیدمانی (--hx-*) که
       CSS آن‌ها را ترانزیشن می‌دهد — مطابق AGENTS.md.
     • هیچ‌چیز در حالت پایه پنهان نمی‌شود؛ انیمیشن‌ها با کلاس is-in آغاز می‌شوند،
       پس داشبورد بدون JS هم کامل است.
     • prefers-reduced-motion کاملاً رعایت می‌شود (مقادیر مستقیم اعمال می‌شوند).
   ========================================================================== */

(function (window, document) {
    'use strict';

    var BOX_ID = 'dashboardBox';
    var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    var LATIN_TO_FA = { 0: '۰', 1: '۱', 2: '۲', 3: '۳', 4: '۴', 5: '۵', 6: '۶', 7: '۷', 8: '۸', 9: '۹' };

    var REVEAL_SELECTOR = '.hx-reveal';
    var VALUE_SELECTOR = '[data-hx-count], [data-hx-ring], [data-hx-bar], [data-hx-meter], [data-hx-share], [data-hx-split], [data-hx-fa]';

    var observersReady = false;
    var revealObserver = null;

    var reduceMotion = false;
    try {
        reduceMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    } catch (e) {
        reduceMotion = false;
    }

    /* ── کمکی‌های عددی ─────────────────────────────────────────────── */

    function toLatinDigits(value) {
        var text = String(value == null ? '' : value);
        for (var i = 0; i < FA_DIGITS.length; i++) {
            text = text.split(FA_DIGITS[i]).join(String(i));
        }
        // ارقام عربی (٠-٩) هم پشتیبانی می‌شوند
        for (var j = 0; j < 10; j++) {
            text = text.split(String.fromCharCode(0x0660 + j)).join(String(j));
        }
        return text;
    }

    function toFaDigits(value) {
        return String(value == null ? '' : value).replace(/[0-9]/g, function (d) {
            return LATIN_TO_FA[d];
        });
    }

    function parseNumeric(value) {
        var latin = toLatinDigits(value).replace(/[^\d.\-]/g, '');
        var num = parseFloat(latin);
        return isNaN(num) ? null : num;
    }

    /* «۱۵:۰۵» را به ثانیه تبدیل می‌کند تا سهم «زمانی» درست حساب شود؛
       برای مقادیر ساده همان عدد برگردانده می‌شود. */
    function parseAmount(value) {
        var latin = toLatinDigits(value).trim();
        var timeMatch = latin.match(/(\d{1,3}):(\d{1,2})(?::(\d{1,2}))?/);
        if (timeMatch) {
            var hours = parseInt(timeMatch[1], 10) || 0;
            var minutes = parseInt(timeMatch[2], 10) || 0;
            var seconds = parseInt(timeMatch[3] || '0', 10) || 0;
            return hours * 3600 + minutes * 60 + seconds;
        }
        return parseNumeric(latin);
    }

    function clamp(value, min, max) {
        return Math.min(max, Math.max(min, value));
    }

    function each(list, fn) {
        Array.prototype.forEach.call(list || [], fn);
    }

    function query(root, selector) {
        return (root || document).querySelectorAll(selector);
    }

    /* ── ۱) شمارش اعداد ────────────────────────────────────────────── */

    function animateCount(el, delay) {
        var source = toLatinDigits(el.textContent).trim();
        if (!/\d/.test(source)) return;

        if (reduceMotion) {
            el.textContent = toFaDigits(source);
            return;
        }

        var chunks = source.split(/(\d+)/);
        var duration = 1050;
        var startTime = null;
        var timer = null;

        function easeOutExpo(t) {
            return t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
        }

        function frame(now) {
            if (startTime === null) startTime = now;
            var progress = clamp((now - startTime) / duration, 0, 1);
            var eased = easeOutExpo(progress);
            var out = '';
            for (var i = 0; i < chunks.length; i++) {
                var chunk = chunks[i];
                if (i % 2 === 0 || !/\d/.test(chunk)) {
                    out += chunk;
                    continue;
                }
                var target = parseInt(chunk, 10);
                var shifted = String(Math.round(target * eased));
                while (shifted.length < chunk.length) shifted = '0' + shifted;
                out += shifted;
            }
            el.textContent = toFaDigits(out);
            if (progress < 1) {
                timer = window.requestAnimationFrame(frame);
            } else {
                el.textContent = toFaDigits(source);
            }
        }

        window.setTimeout(function () {
            el.textContent = toFaDigits(chunks.map(function (chunk, i) {
                return (i % 2 === 1 && /\d/.test(chunk)) ? new Array(chunk.length + 1).join('0') : chunk;
            }).join(''));
            timer = window.requestAnimationFrame(frame);

            /* تضمین مقدار نهایی: اگر rAF توسط مرورگر throttle شود (تب پنهان،
               اسکرین‌شات خودکار و…) عدد در هر حالت روی مقدار واقعی می‌نشیند. */
            window.setTimeout(function () {
                if (timer && window.cancelAnimationFrame) window.cancelAnimationFrame(timer);
                el.textContent = toFaDigits(source);
            }, duration + 320);
        }, delay || 0);
    }

    /* ── ۳) ستون‌های نمودار ────────────────────────────────────────── */

    function animateBar(col) {
        var percent = parseNumeric(col.dataset.percent);
        if (percent === null) return;
        var value = percent <= 0 ? 0 : clamp(percent, 3, 100);
        col.style.setProperty('--hx-h', value + '%');
        col.classList.add('is-in');
    }

    /* ── ۴) حلقه‌های پیشرفت ────────────────────────────────────────── */

    function animateRing(circle) {
        var percent = clamp(parseNumeric(circle.dataset.hxRing) || 0, 0, 100);

        var length = 0;
        try {
            length = circle.getTotalLength();
        } catch (e) {
            length = 0;
        }
        if (!length) {
            // مرورگرهای بدون getTotalLength: محیط دایره را دستی حساب می‌کنیم
            var r = parseFloat(circle.getAttribute('r') || '0');
            length = 2 * Math.PI * r;
        }
        if (!length) return;

        circle.style.setProperty('--hx-len', String(Math.round(length * 100) / 100));
        if (reduceMotion) {
            circle.style.setProperty('--hx-p', String(percent / 100));
            return;
        }
        window.requestAnimationFrame(function () {
            circle.style.setProperty('--hx-p', String(percent / 100));
        });
    }

    /* ── ۵) نوارهای سهم و نوار توزیع پرسنل ─────────────────────────── */

    function animateMeter(meter) {
        var part = parseAmount(meter.dataset.hxPart);
        var whole = parseAmount(meter.dataset.hxWhole);
        var percent = (part !== null && whole) ? clamp(Math.round((part / whole) * 100), 0, 100) : 0;
        if (percent > 0) percent = Math.max(3, percent);
        meter.style.setProperty('--hx-w', percent + '%');
    }

    function animateSplit(split) {
        var total = parseNumeric(split.dataset.hxTotal);
        if (!total) return;
        each(split.querySelectorAll('[data-hx-part]'), function (seg) {
            var part = parseAmount(seg.dataset.hxPart) || 0;
            var percent = part <= 0 ? 0 : Math.max(2, Math.round((part / total) * 100));
            seg.style.width = percent + '%';
        });
    }

    /* ── ۸) ارقام فارسی برای نشانگرهای رتبه ────────────────────────── */

    function localizeDigits(root) {
        each(query(root, '[data-hx-fa]'), function (node) {
            var latin = toLatinDigits(node.textContent).trim();
            // تنها عدد خالص (شمارهٔ ردیف) از صفر ابتدایی پاک می‌شود؛
            // مقادیری مثل «۰۷:۱۱» یا «۱۴۰۵/۱۲/۲۹» دست‌نخورده می‌مانند.
            if (/^0+\d+$/.test(latin)) latin = latin.replace(/^0+(?=\d)/, '');
            var normalized = toFaDigits(latin);
            if (normalized && node.textContent !== normalized) node.textContent = normalized;
        });
    }

    /* ── اجرای همهٔ قلاب‌های مقدار داخل یک بلوک ─────────────────────── */

    function activateValues(root) {
        each(query(root, '[data-hx-bar]'), function (col, index) {
            if (col.dataset.hxDone === '1') return;
            col.dataset.hxDone = '1';
            if (reduceMotion) {
                animateBar(col);
                return;
            }
            window.setTimeout(function () { animateBar(col); }, 90 + index * 70);
        });

        each(query(root, '[data-hx-ring]'), function (circle) {
            if (circle.dataset.hxDone === '1') return;
            circle.dataset.hxDone = '1';
            animateRing(circle);
        });

        each(query(root, '[data-hx-meter], [data-hx-share]'), function (meter) {
            if (meter.dataset.hxDone === '1') return;
            meter.dataset.hxDone = '1';
            animateMeter(meter);
        });

        each(query(root, '[data-hx-split]'), function (split) {
            if (split.dataset.hxDone === '1') return;
            split.dataset.hxDone = '1';
            animateSplit(split);
        });

        each(query(root, '[data-hx-count]'), function (el) {
            if (el.dataset.hxDone === '1') return;
            el.dataset.hxDone = '1';
            animateCount(el, reduceMotion ? 0 : 120);
        });

        localizeDigits(root);
    }

    /* ── ۷) ساعت زندهٔ سربرگ ───────────────────────────────────────── */

    function startClock(root) {
        var target = (root || document).querySelector('#hxLiveClock');
        if (!target) return;

        function tick() {
            var now = new Date();
            var text = '';
            try {
                var datePart = new Intl.DateTimeFormat('fa-IR', {
                    weekday: 'long', day: '2-digit', month: 'long'
                }).format(now);
                var timePart = new Intl.DateTimeFormat('fa-IR', {
                    hour: '2-digit', minute: '2-digit', hour12: false
                }).format(now);
                text = datePart + ' — ' + timePart;
            } catch (e) {
                text = now.toLocaleString();
            }
            if (target.textContent !== text) target.textContent = text;
        }

        tick();
        window.setInterval(tick, 30000);
    }

    /* ── ۶) هالهٔ دنبال‌کنندهٔ نشانگر ──────────────────────────────── */

    function bindSpotlight(root) {
        if (reduceMotion || !window.matchMedia) return;
        if (window.matchMedia('(hover: none)').matches) return;

        each(query(root, '[data-hx-spot]'), function (spot) {
            if (spot.dataset.hxSpotBound === '1') return;
            spot.dataset.hxSpotBound = '1';
            spot.addEventListener('pointermove', function (event) {
                var rect = spot.getBoundingClientRect();
                if (!rect.width || !rect.height) return;
                var x = ((event.clientX - rect.left) / rect.width) * 100;
                var y = ((event.clientY - rect.top) / rect.height) * 100;
                spot.style.setProperty('--hx-mx', x.toFixed(1) + '%');
                spot.style.setProperty('--hx-my', y.toFixed(1) + '%');
            }, { passive: true });
        });
    }

    /* ── ۱) ورود پله‌ای ─────────────────────────────────────────────── */

    function reveal(el) {
        if (el.dataset.hxRevealed === '1') return;
        el.dataset.hxRevealed = '1';
        el.classList.add('is-in');
        activateValues(el);
    }

    function revealEverything(root) {
        each(query(root, REVEAL_SELECTOR), reveal);
        activateValues(root);
    }

    function observeReveals(root) {
        if (observersReady) return;
        observersReady = true;

        if (!window.IntersectionObserver || reduceMotion) {
            revealEverything(root);
            return;
        }

        /* ورود بلوک‌ها: هر بلوک با دیده‌شدن، خود و مقادیرش را فعال می‌کند */
        revealObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    reveal(entry.target);
                    revealObserver.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -4% 0px' });

        each(query(root, REVEAL_SELECTOR), function (el) {
            revealObserver.observe(el);
        });

        /* امنیت: اگر observer به هر دلیلی فعال نشد (مرورگر قدیمی/خطای چیدمان)
           تا ۱٫۵ ثانیه بعد همه‌چیز نمایان می‌شود — هیچ محتوایی گم نمی‌شود. */
        window.setTimeout(function () {
            var pending = Array.prototype.filter.call(query(root, REVEAL_SELECTOR), function (el) {
                return !el.classList.contains('is-in') && el.getClientRects().length > 0;
            });
            if (pending.length) {
                each(pending, reveal);
            }
        }, 1500);
    }

    /* ── راه‌اندازی ────────────────────────────────────────────────── */

    function boot() {
        var box = document.getElementById(BOX_ID);
        if (!box || box.dataset.hxReady === '1') return;
        box.dataset.hxReady = '1';

        startClock(box);
        bindSpotlight(box);
        localizeDigits(box);

        var isVisible = function () {
            return box.offsetParent !== null && box.getClientRects().length > 0;
        };

        if (isVisible()) {
            observeReveals(box);
            return;
        }

        // داشبورد ممکن است ابتدا مخفی باشد (مثلاً در /admin/coworkers)؛
        // اولین نمایش را می‌پاییم تا انیمیشن‌ها همان لحظه اجرا شوند.
        if (window.MutationObserver) {
            var mutationObserver = new MutationObserver(function () {
                if (isVisible()) {
                    mutationObserver.disconnect();
                    observeReveals(box);
                }
            });
            mutationObserver.observe(box, { attributes: true, attributeFilter: ['style', 'class'] });
        }

        if (window.IntersectionObserver) {
            var io = new IntersectionObserver(function (entries) {
                var entry = entries[0];
                if (entry && entry.isIntersecting && isVisible()) {
                    io.disconnect();
                    observeReveals(box);
                }
            }, { threshold: 0.05 });
            io.observe(box);
        }

        var tries = 0;
        var poll = window.setInterval(function () {
            tries += 1;
            if (isVisible() || tries > 40) {
                window.clearInterval(poll);
                if (isVisible()) observeReveals(box);
            }
        }, 150);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.HastamaDashboard = {
        /* اجرای دوباره با بررسی دیده‌شدن باکس (بعد از بیرون‌آمدن از حالت مخفی) */
        refresh: function () {
            observersReady = false;
            var box = document.getElementById(BOX_ID);
            if (!box) return;
            box.dataset.hxReady = '';
            boot();
        },
        /* اجرای فوری همهٔ مقدارها بدون بررسی چیدمان (تست و به‌روزرسانی AJAX) */
        runNow: function () {
            var box = document.getElementById(BOX_ID);
            if (!box) return false;
            revealEverything(box);
            return true;
        },
        toFaDigits: toFaDigits,
        toLatinDigits: toLatinDigits,
        parseAmount: parseAmount
    };
})(window, document);
