/* =============================================================================
   dashboard-modern.js — لایهٔ رفتاری/حرکتی داشبورد بازطراحی‌شده
   -----------------------------------------------------------------------------
   مسئولیت‌ها (همه اختیاری و تزئینی — اگر JS اجرا نشود، داشبورد کامل و خوانا
   باقی می‌ماند چون همهٔ مقادیر از سمت سرور در HTML رندر شده‌اند):

     ۱) شمارش نرم اعداد (data-hx-count) با حفظ ارقام فارسی و جداکننده‌ها
     ۲) رشد انیمیشنی میله‌های نمودار (data-percent → متغیر --hx-h)
     ۳) پر شدن حلقه‌های پیشرفت (data-hx-ring → stroke-dashoffset)
     ۴) نوارهای «سهم از کل» در کارت‌های ستاره و نوار توزیع کارکنان
     ۵) ورود پله‌ای کارت‌ها (reveal) هنگام دیده‌شدن داشبورد
     ۶) هالهٔ دنبال‌کنندهٔ نشانگر روی کارت‌ها (--hx-mx/--hx-my)
     ۷) ساعت/تاریخ زندهٔ سربرگ داشبورد
     ۸) تبدیل ارقام لاتین به فارسی در نشانگرهای رتبه (data-hx-fa)

   نکته‌ها:
     • هیچ رنگی به‌صورت inline ست نمی‌شود (فقط متغیرهای چیدمانی) تا لایهٔ تم
       تیره دست‌نخورده بماند — مطابق AGENTS.md.
     • prefers-reduced-motion رعایت می‌شود؛ در آن حالت مقادیر نهایی مستقیم
       اعمال می‌شوند.
     • داشبورد ممکن است ابتدا مخفی باشد (display:none)؛ بنابراین انیمیشن‌ها
       با اولین دیده‌شدن باکس (IntersectionObserver) آغاز می‌شوند.
   ========================================================================== */

(function (window, document) {
    'use strict';

    var BOX_ID = 'dashboardBox';
    var FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    var LATIN_TO_FA = { 0: '۰', 1: '۱', 2: '۲', 3: '۳', 4: '۴', 5: '۵', 6: '۶', 7: '۷', 8: '۸', 9: '۹' };
    var started = false;

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

    /* «۱۵:۰۵» را به دقیقه تبدیل می‌کند تا سهم «زمانی» درست محاسبه شود؛
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

    /* «۱۵:۰۵» یا «۱۴۰۵/۱۲/۲۹» را به قطعه‌های عددی می‌شکند تا هر بخش جداگانه
       شمرده شود و ارقام فارسی و جداکننده‌ها حفظ شوند. */
    function splitNumberGroups(text) {
        var latin = toLatinDigits(text);
        var groups = latin.match(/\d+/g) || [];
        var parts = latin.split(/(\d+)/);
        return { latin: latin, groups: groups, parts: parts };
    }

    /* ── ۱) شمارش اعداد ────────────────────────────────────────────── */

    function animateCount(el, delay) {
        var groups = splitNumberGroups(el.textContent);
        if (!groups.groups.length) return;

        if (reduceMotion) return; // مقدار نهایی از قبل در HTML است

        var localeNumbers = groups.latin.split(/(\d+)/);
        var startTime = null;
        var duration = 1150;
        var timer;

        function easeOutExpo(t) {
            return t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
        }

        function frame(now) {
            if (startTime === null) startTime = now;
            var progress = Math.min(1, (now - startTime) / duration);
            var eased = easeOutExpo(progress);
            var out = '';
            for (var i = 0; i < localeNumbers.length; i++) {
                var chunk = localeNumbers[i];
                if (i % 2 === 0) {
                    out += chunk;
                } else {
                    var target = parseInt(chunk, 10);
                    var shifted = String(Math.round(target * eased));
                    if (chunk.length > 1) {
                        while (shifted.length < chunk.length) shifted = '0' + shifted;
                    }
                    out += shifted;
                }
            }
            el.textContent = toFaDigits(out);
            if (progress < 1) {
                timer = window.requestAnimationFrame(frame);
            } else {
                el.textContent = toFaDigits(groups.latin);
            }
        }

        var startDelay = delay || 0;

        window.setTimeout(function () {
            el.textContent = toFaDigits(localeNumbers.map(function (chunk, i) {
                return i % 2 === 1 ? new Array(chunk.length + 1).join('0') : chunk;
            }).join(''));
            timer = window.requestAnimationFrame(frame);

            /* تضمین مقدار نهایی: اگر rAF توسط مرورگر throttle شود (تب پنهان،
               اسکرین‌شات خودکار و…) عدد در هر حالت روی مقدار واقعی می‌نشیند. */
            window.setTimeout(function () {
                if (timer) window.cancelAnimationFrame && window.cancelAnimationFrame(timer);
                el.textContent = toFaDigits(groups.latin);
            }, duration + 260);
        }, startDelay);
    }

    /* ── ۲) میله‌های نمودار ────────────────────────────────────────── */

    function animateBars(root) {
        var bars = root.querySelectorAll('.hx-col__fill[data-percent]');
        Array.prototype.forEach.call(bars, function (bar, index) {
            var percent = parseInt(toLatinDigits(bar.dataset.percent || ''), 10);
            if (isNaN(percent)) return;
            var value = percent <= 0 ? 0 : Math.max(3, Math.min(100, percent));
            if (reduceMotion) {
                bar.style.setProperty('--hx-h', value + '%');
                return;
            }
            window.setTimeout(function () {
                bar.style.setProperty('--hx-h', value + '%');
            }, 120 + index * 70);
        });
    }

    /* ── ۳) حلقه‌های پیشرفت ────────────────────────────────────────── */

    function animateRing(circle, root) {
        var percent = parseNumeric(circle.dataset.hxRing);
        if (percent === null) return;
        percent = Math.max(0, Math.min(100, percent));

        var length = 0;
        try {
            length = circle.getTotalLength();
        } catch (e) {
            length = 0;
        }
        if (!length) {
            // در مرورگرهای خیلی قدیمی، محیط دایره را دستی حساب می‌کنیم
            var r = parseFloat(circle.getAttribute('r') || '0');
            length = 2 * Math.PI * r;
        }
        if (!length) return;

        circle.style.strokeDasharray = length + ' ' + length;
        circle.style.strokeDashoffset = String(length);
        if (reduceMotion) {
            circle.style.strokeDashoffset = String(length * (1 - percent / 100));
            return;
        }
        window.setTimeout(function () {
            circle.style.strokeDashoffset = String(length * (1 - percent / 100));
        }, 260);
    }

    /* ── ۴) نوار سهم (کارت‌های ستاره و ترکیب کارکنان) ───────────────── */

    function animateShares(root) {
        var shares = root.querySelectorAll('[data-hx-share]');
        Array.prototype.forEach.call(shares, function (wrap) {
            var part = parseAmount(wrap.dataset.hxPart);
            var whole = parseAmount(wrap.dataset.hxWhole);
            var bar = wrap.querySelector('.hx-spot__share-bar i');
            if (!bar) return;
            var percent = (part !== null && whole) ? Math.min(100, Math.round((part / whole) * 100)) : 0;
            if (percent > 0) percent = Math.max(3, percent);
            bar.style.setProperty('--hx-w', percent + '%');
        });

        var splits = root.querySelectorAll('[data-hx-split]');
        Array.prototype.forEach.call(splits, function (split) {
            var total = parseNumeric(split.dataset.hxTotal);
            if (!total) return;
            var segs = split.querySelectorAll('[data-hx-part]');
            Array.prototype.forEach.call(segs, function (seg) {
                var part = parseAmount(seg.dataset.hxPart) || 0;
                var percent = part <= 0 ? 0 : Math.max(2, Math.round((part / total) * 100));
                seg.style.width = percent + '%';
            });
        });
    }

    /* ── ۸) ارقام فارسی برای نشانگرهای رتبه ────────────────────────── */

    function localizeDigits(root) {
        var nodes = root.querySelectorAll('[data-hx-fa]');
        Array.prototype.forEach.call(nodes, function (node) {
            var normalized = toFaDigits(toLatinDigits(node.textContent)).trim();
            if (normalized && node.textContent !== normalized) node.textContent = normalized;
        });
    }

    /* ── ۷) ساعت زندهٔ سربرگ ───────────────────────────────────────── */

    function startClock(root) {
        var target = root.querySelector('#hxLiveClock');
        if (!target) return;

        function tick() {
            var now = new Date();
            var text = '';
            try {
                var datePart = new Intl.DateTimeFormat('fa-IR', { weekday: 'long', day: '2-digit', month: 'long' }).format(now);
                var timePart = new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit', hour12: false }).format(now);
                text = datePart + ' — ' + timePart;
            } catch (e) {
                text = now.toLocaleString();
            }
            if (target.textContent !== text) target.textContent = text;
        }

        tick();
        window.setInterval(tick, 30000);
    }

    /* ── ۶) هالهٔ دنبال‌کنندهٔ نشانگر ───────────────────────────────── */

    function bindPointerHalo(root) {
        if (reduceMotion || !window.matchMedia) return;
        if (window.matchMedia('(hover: none)').matches) return;

        var cards = root.querySelectorAll('.hx-kpi, .hx-spot, .hx-card');
        Array.prototype.forEach.call(cards, function (card) {
            card.addEventListener('pointermove', function (event) {
                var rect = card.getBoundingClientRect();
                if (!rect.width || !rect.height) return;
                var x = ((event.clientX - rect.left) / rect.width) * 100;
                var y = ((event.clientY - rect.top) / rect.height) * 100;
                card.style.setProperty('--hx-mx', x.toFixed(1) + '%');
                card.style.setProperty('--hx-my', y.toFixed(1) + '%');
            });
        });
    }

    /* ── اجرای همهٔ انیمیشن‌ها ─────────────────────────────────────── */

    function run(root) {
        if (started) return;
        started = true;

        localizeDigits(root);
        animateShares(root);
        animateBars(root);
        animateRings(root);
        animateCounters(root);

        // ورود پله‌ای کارت‌ها
        window.requestAnimationFrame(function () {
            root.classList.add('is-in');
        });
    }

    function animateRings(root) {
        var rings = root.querySelectorAll('[data-hx-ring]');
        Array.prototype.forEach.call(rings, function (ring) {
            animateRing(ring, root);
        });
    }

    function animateCounters(root) {
        var counters = root.querySelectorAll('[data-hx-count]');
        Array.prototype.forEach.call(counters, function (el, index) {
            animateCount(el, reduceMotion ? 0 : 90 + index * 45);
        });
    }

    function boot() {
        var box = document.getElementById(BOX_ID);
        if (!box || box.dataset.hxReady === '1') return;
        box.dataset.hxReady = '1';

        var root = box;
        startClock(root);
        bindPointerHalo(root);

        var isVisible = function () {
            return box.offsetParent !== null && box.getClientRects().length > 0;
        };

        if (isVisible()) {
            run(root);
            return;
        }

        // داشبورد ابتدا مخفی است (مثلاً در /admin/coworkers)؛ اولین نمایش را می‌پاییم.
        if (window.MutationObserver) {
            var observer = new MutationObserver(function () {
                if (isVisible()) {
                    observer.disconnect();
                    run(root);
                }
            });
            observer.observe(box, { attributes: true, attributeFilter: ['style', 'class'] });
        }

        if (window.IntersectionObserver) {
            var io = new IntersectionObserver(function (entries) {
                var entry = entries[0];
                if (entry && entry.isIntersecting && isVisible()) {
                    io.disconnect();
                    run(root);
                }
            }, { threshold: 0.05 });
            io.observe(box);
        }

        // آخرین راه‌حل: اگر تا دو ثانیه بعد باکس دیده شد، انیمیشن اجرا شود.
        var tries = 0;
        var poll = window.setInterval(function () {
            tries += 1;
            if (isVisible() || tries > 40) {
                window.clearInterval(poll);
                if (isVisible()) run(root);
            }
        }, 150);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }

    window.HastamaDashboard = {
        /* اجرای دوباره با بررسی دیده‌شدن باکس (برای وقتی که داشبورد
           تازه از حالت مخفی بیرون آمده است). */
        refresh: function () {
            started = false;
            var box = document.getElementById(BOX_ID);
            if (!box) return;
            box.dataset.hxReady = '';
            boot();
        },
        /* اجرای فوری انیمیشن‌ها بدون بررسی چیدمان (برای تست و برای
           حالتی که مقادیر داشبورد با AJAX تازه‌سازی می‌شوند). */
        runNow: function () {
            var box = document.getElementById(BOX_ID);
            if (!box) return false;
            started = false;
            run(box);
            return true;
        },
        toFaDigits: toFaDigits,
        toLatinDigits: toLatinDigits,
        parseAmount: parseAmount
    };
})(window, document);
