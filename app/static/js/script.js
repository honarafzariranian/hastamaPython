/* صفحهٔ ورود — رفتار دکمهٔ ورود، لودر تمام‌صفحه و کد امنیتی.
 *
 * سه چیز اینجا مدیریت می‌شود (همه از «master-admin → system settings» قابل
 * تنظیم‌اند و از /api/system-config خوانده می‌شوند؛ مقادیر پیش‌فرض زیر فقط
 * زمانی به کار می‌روند که آن درخواست ناموفق باشد):
 *
 *   1) دکمهٔ ورود دیگر «در حال ورود…» نمی‌شود و سبز نمی‌شود؛ به‌جایش صفحه با یک
 *      لودر برند پوشیده می‌شود که حداقل «login_loader_seconds» ثانیه (پیش‌فرض ۳)
 *      نمایش داده می‌شود و بعد کاربر به مقصد خودش می‌رود. درخواست بی‌درنگ به سرور
 *      می‌رود؛ لودر فقط یک *حداقل* نمایش است، نه تأخیر.
 *   2) اگر کد امنیتی منقضی شود، کاربر همان‌جا پیام می‌گیرد که کد جدید بگیرد
 *      (شمارش معکوس + هشدار). پاسخ سرور هم دقیقاً می‌گوید «منقضی شده» و پیام
 *      پاک نمی‌شود — اشکال قبلی همین بود: refreshCaptcha انتهای کار پیام را
 *      مخفی می‌کرد و کاربر فکر می‌کرد هیچ اتفاقی نیفتاده.
 *   3) کد امنیتی که منقضی شده دیگر یک درخواست بی‌فایده نمی‌فرستد.
 */
(function () {
    'use strict';

    var DEFAULT_UX = {
        loaderEnabled: true,
        loaderSeconds: 3,
        loaderTitle: 'در حال آماده‌سازی میزکار شما…',
        loaderMessage: 'لطفاً چند لحظه صبر کنید؛ در حال ورود به سامانه هستما.',
        captchaNotice: true,
        captchaTtl: 180,
        warningLead: 60
    };

    var EXPIRED_MESSAGE = 'کد امنیتی منقضی شده است؛ لطفاً روی «کد جدید» بزنید و دوباره وارد شوید.';
    var LOADER_STEPS = [
        'در حال بررسی اطلاعات ورود…',
        'در حال آماده‌سازی میزکار شما…',
        'در حال دریافت دسترسی‌ها…',
        'لطفاً کمی بیشتر صبر کنید…'
    ];

    var captchaWatch = { timer: null, remaining: null, expired: false, polling: false };

    function uxConfig() {
        var raw = window.HASTAMA_LOGIN_UX || {};
        var cfg = {};
        Object.keys(DEFAULT_UX).forEach(function (key) { cfg[key] = DEFAULT_UX[key]; });
        Object.keys(raw).forEach(function (key) {
            if (raw[key] !== undefined && raw[key] !== null && raw[key] !== '') cfg[key] = raw[key];
        });
        cfg.loaderSeconds = parseInt(cfg.loaderSeconds, 10);
        if (!(cfg.loaderSeconds >= 1)) cfg.loaderSeconds = DEFAULT_UX.loaderSeconds;
        cfg.loaderSeconds = Math.min(15, cfg.loaderSeconds);
        cfg.captchaTtl = parseInt(cfg.captchaTtl, 10) || DEFAULT_UX.captchaTtl;
        return cfg;
    }

    function el(id) { return document.getElementById(id); }

    /* ── خطاهای فرم ──────────────────────────────────────────────────────
       Everything the login page shows when a submission fails lives here now:
       the old inline wrapper around ``window.login`` in login.html had grown a
       second copy of the field validation and wrapped ``showSystemError`` (a
       helper that is not even loaded on this page). */

    function shakeCard() {
        var card = el('loginCard');
        if (!card) return;
        card.classList.remove('shake');
        void card.offsetWidth; /* restart the animation */
        card.classList.add('shake');
    }

    function setFieldState(input, errorEl, message) {
        var wrap = input ? input.closest('.input-wrapper') : null;
        if (!wrap) return;
        if (message) {
            wrap.classList.add('has-error');
            if (errorEl) errorEl.textContent = message;
        } else {
            wrap.classList.remove('has-error');
            if (errorEl) errorEl.textContent = '';
        }
    }

    function notifyError(message) {
        var hint = el('loginHint');
        if (hint) hint.textContent = message || '';
        shakeCard();
    }

    function clearLoginHint() {
        var hint = el('loginHint');
        if (hint) hint.textContent = '';
    }

    function captchaVisible() {
        var group = el('captchaGroup');
        return !!group && group.style.display !== 'none';
    }

    /* ── خطاهای کد امنیتی ────────────────────────────────────────────── */

    function showCaptchaError(msg) {
        var node = el('captchaError');
        if (!node) return;
        node.textContent = msg;
        node.style.display = 'block';
        var group = document.querySelector('.captcha-group');
        if (group) {
            /* A class, not an inline animation: the old code asked for a
               ``shake`` keyframe that no stylesheet ever defined, so the field
               never actually moved. */
            group.classList.remove('captcha-shake');
            void group.offsetWidth; /* trigger reflow */
            group.classList.add('captcha-shake');
        }
    }

    function hideCaptchaError() {
        var node = el('captchaError');
        if (node) {
            node.textContent = '';
            node.style.display = 'none';
        }
    }

    function captchaHintNode(create) {
        var node = el('captchaHint');
        if (node || !create) return node;
        var group = document.querySelector('.captcha-group');
        if (!group) return null;
        node = document.createElement('p');
        node.id = 'captchaHint';
        node.className = 'captcha-hint';
        node.setAttribute('role', 'status');
        node.setAttribute('aria-live', 'polite');
        group.appendChild(node);
        return node;
    }

    function setCaptchaHint(text) {
        var node = captchaHintNode(!!text);
        if (node) node.textContent = text || '';
    }

    function markCaptchaExpired(message) {
        captchaWatch.expired = true;
        var group = document.querySelector('.captcha-group');
        if (group) group.classList.add('captcha-expired');
        setCaptchaHint('');
        showCaptchaError(message || EXPIRED_MESSAGE);
    }

    function clearCaptchaNotice() {
        captchaWatch.expired = false;
        var group = document.querySelector('.captcha-group');
        if (group) group.classList.remove('captcha-expired');
        hideCaptchaError();
        setCaptchaHint('');
    }

    /* ── پایش عمر کد امنیتی ─────────────────────────────────────────── */

    function stopCaptchaWatch() {
        if (captchaWatch.timer) {
            clearInterval(captchaWatch.timer);
            captchaWatch.timer = null;
        }
    }

    function pollCaptchaStatus() {
        if (!captchaVisible() || captchaWatch.polling) return;
        captchaWatch.polling = true;
        fetch('/captcha/status', { cache: 'no-store' })
            .then(function (response) { return response.ok ? response.json() : null; })
            .then(function (data) {
                if (!data || !data.success) return;
                var cfg = uxConfig();
                captchaWatch.remaining = data.remaining_seconds;
                if (data.has_captcha && data.remaining_seconds <= 0) {
                    if (!captchaWatch.expired) markCaptchaExpired(EXPIRED_MESSAGE);
                    return;
                }
                if (data.valid) {
                    /* A refresh (here or in another tab) gives us a new life. */
                    if (captchaWatch.expired) clearCaptchaNotice();
                    if (cfg.captchaNotice && data.remaining_seconds <= (data.warning_lead_seconds || cfg.warningLead)) {
                        setCaptchaHint('اعتبار کد امنیتی: ' + data.remaining_seconds + ' ثانیه');
                    } else {
                        setCaptchaHint('');
                    }
                }
            })
            .catch(function () { /* offline: the server stays the authority */ })
            .then(function () { captchaWatch.polling = false; });
    }

    function startCaptchaWatch() {
        stopCaptchaWatch();
        if (!captchaVisible()) return;
        pollCaptchaStatus();
        captchaWatch.timer = setInterval(pollCaptchaStatus, 5000);
    }

    /* ── لودر ورود ───────────────────────────────────────────────────── */

    function buildLoginLoader(cfg, username) {
        var seconds = cfg.loaderSeconds;
        var overlay = document.createElement('div');
        overlay.id = 'loginLoader';
        overlay.className = 'login-loader';
        overlay.setAttribute('role', 'status');
        overlay.setAttribute('aria-live', 'polite');
        overlay.setAttribute('aria-label', cfg.loaderTitle);
        overlay.innerHTML =
            '<div class="login-loader__card">' +
                '<div class="login-loader__ring">' +
                    '<span class="login-loader__ring-track"></span>' +
                    '<span class="login-loader__ring-spin"></span>' +
                    '<span class="login-loader__mark"></span>' +
                '</div>' +
                '<div class="login-loader__title"></div>' +
                '<div class="login-loader__message"></div>' +
                '<div class="login-loader__bar"><i></i></div>' +
                '<div class="login-loader__step"></div>' +
            '</div>';

        /* Every dynamic value goes through textContent: the texts are written by
           an administrator and the user name is typed by the visitor. */
        var title = overlay.querySelector('.login-loader__title');
        var message = overlay.querySelector('.login-loader__message');
        var step = overlay.querySelector('.login-loader__step');
        var bar = overlay.querySelector('.login-loader__bar i');
        title.textContent = username ? ('خوش آمدید، ' + username) : cfg.loaderTitle;
        message.textContent = cfg.loaderMessage;
        step.textContent = LOADER_STEPS[0];
        bar.style.animationDuration = (seconds * 1000) + 'ms';
        document.body.appendChild(overlay);

        var index = 0;
        var ticker = setInterval(function () {
            index = (index + 1) % LOADER_STEPS.length;
            step.textContent = LOADER_STEPS[index];
        }, 1200);

        return {
            seconds: seconds,
            success: function () {
                clearInterval(ticker);
                overlay.classList.add('login-loader--done');
                step.textContent = 'ورود موفق؛ در حال انتقال به میزکار…';
            },
            hide: function () {
                clearInterval(ticker);
                if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
            }
        };
    }

    /* ── ورود ────────────────────────────────────────────────────────── */

    function login() {
        var usernameEl = el('username');
        var passwordEl = el('password');
        var captchaEl = el('captcha');
        var username = usernameEl ? usernameEl.value : '';
        var password = passwordEl ? passwordEl.value : '';
        var captcha = captchaEl ? captchaEl.value : '';

        if (!username || !password) {
            if (!username) setFieldState(usernameEl, el('usernameError'), 'لطفاً نام کاربری را وارد کنید');
            if (!password) setFieldState(passwordEl, el('passwordError'), 'لطفاً رمز عبور را وارد کنید');
            notifyError('لطفاً نام کاربری و رمز عبور را وارد کنید.');
            return;
        }
        setFieldState(usernameEl, el('usernameError'), '');
        setFieldState(passwordEl, el('passwordError'), '');
        clearLoginHint();

        if (captchaVisible()) {
            if (!captcha) {
                showCaptchaError('لطفاً کد امنیتی را وارد کنید.');
                if (captchaEl) captchaEl.focus();
                return;
            }
            if (captchaWatch.expired) {
                /* The code is already known to be dead: say so at once instead of
                   spending a request (and the three second loader) on it.  The
                   status poll clears this state as soon as a new code is issued. */
                markCaptchaExpired(EXPIRED_MESSAGE);
                if (captchaEl) captchaEl.focus();
                return;
            }
        }

        if (window.HastamaUX && window.HastamaUX.submitLock) {
            if (!window.HastamaUX.submitLock('login')) return;
        }

        var cfg = uxConfig();
        var loader = cfg.loaderEnabled ? buildLoginLoader(cfg, username) : null;
        var started = Date.now();

        function whenLoaderIsDone(callback) {
            var minimum = loader ? loader.seconds * 1000 : 0;
            var left = minimum - (Date.now() - started);
            if (left > 0) setTimeout(callback, left);
            else callback();
        }

        function release() {
            if (window.HastamaUX && window.HastamaUX.submitUnlock) {
                window.HastamaUX.submitUnlock('login');
            }
        }

        var controller = null;
        if (typeof AbortController !== 'undefined') {
            controller = new AbortController();
            setTimeout(function () { controller.abort(); }, 20000);
        }

        fetch('/login_user', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: username, password: password, captcha: captcha }),
            signal: controller ? controller.signal : undefined
        })
            .then(function (response) {
                if (!response.ok) throw new Error('Network response was not ok');
                return response.json();
            })
            .then(function (data) {
                if (data.success) {
                    if (loader) loader.success();
                    whenLoaderIsDone(function () {
                        /* The loader stays on screen until the next page paints. */
                        window.location.href = data.redirect || '/';
                    });
                    return;
                }

                release();
                whenLoaderIsDone(function () {
                    if (loader) loader.hide();
                    if (data.captcha_error) {
                        var reason = data.captcha_reason || '';
                        var message = data.message || EXPIRED_MESSAGE;
                        if (data.captcha_expired || reason === 'expired') {
                            message = message + ' کد جدید نمایش داده شد؛ لطفاً دوباره وارد کنید.';
                        }
                        /* Refresh, but keep the explanation on screen: that is
                           exactly what the old code got wrong. */
                        refreshCaptcha({ keepMessage: true });
                        if (captchaEl) {
                            captchaEl.value = '';
                            captchaEl.focus();
                        }
                        if (data.captcha_expired || reason === 'expired') {
                            markCaptchaExpired(message);
                        } else {
                            showCaptchaError(message);
                        }
                    } else {
                        notifyError(data.message || 'نام کاربری یا رمز عبور اشتباه است');
                    }
                });
            })
            .catch(function (error) {
                console.error('Error:', error);
                release();
                whenLoaderIsDone(function () {
                    if (loader) loader.hide();
                    notifyError('خطا در برقراری ارتباط با سامانه.');
                });
            });
    }

    /* ── دریافت کد امنیتی جدید ───────────────────────────────────────── */

    function refreshCaptcha(options) {
        var opts = options || {};
        var img = el('captchaImage');
        var btn = el('captchaRefresh');
        if (!img) return;

        if (btn) btn.classList.add('spinning');

        fetch('/captcha/refresh', { method: 'POST' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.success && data.image) {
                    img.src = data.image;
                } else {
                    img.src = '/captcha?t=' + Date.now();
                }
            })
            .catch(function () {
                img.src = '/captcha?t=' + Date.now();
            })
            .then(function () {
                if (btn) setTimeout(function () { btn.classList.remove('spinning'); }, 500);
                captchaWatch.expired = false;
                captchaWatch.remaining = null;
                var group = document.querySelector('.captcha-group');
                if (group) group.classList.remove('captcha-expired');
                if (!opts.keepMessage) hideCaptchaError();
                setCaptchaHint('');
                pollCaptchaStatus();
            });
    }

    /* ── اتصال به صفحه ───────────────────────────────────────────────── */

    var loginBtn = el('loginBtn');
    var usernameInput = el('username');
    var passwordInput = el('password');
    var captchaInput = el('captcha');
    var captchaRefresh = el('captchaRefresh');

    if (loginBtn) {
        loginBtn.addEventListener('click', function (e) {
            e.preventDefault();
            login();
        });
    }

    function handleEnter(e) {
        if (e.key === 'Enter' || e.keyCode === 13) {
            e.preventDefault();
            login();
        }
    }
    if (passwordInput) passwordInput.addEventListener('keydown', handleEnter);
    if (captchaInput) {
        captchaInput.addEventListener('keydown', handleEnter);
        captchaInput.addEventListener('input', function () {
            /* Typing cannot revive an expired code, so its warning stays. */
            if (!captchaWatch.expired) hideCaptchaError();
        });
    }
    if (usernameInput) {
        usernameInput.addEventListener('keydown', handleEnter);
        usernameInput.addEventListener('input', function () {
            setFieldState(usernameInput, el('usernameError'), '');
        });
    }
    if (passwordInput) {
        passwordInput.addEventListener('input', function () {
            setFieldState(passwordInput, el('passwordError'), '');
        });
    }
    if (captchaRefresh) {
        captchaRefresh.addEventListener('click', function () { refreshCaptcha(); });
    }

    window.login = login;
    window.showSystemError = notifyError;
    window.showCaptchaError = showCaptchaError;
    window.hideCaptchaError = hideCaptchaError;
    window.refreshCaptcha = refreshCaptcha;

    /* The login page calls this once /api/system-config answered. */
    window.HastamaLogin = {
        startCaptchaWatch: startCaptchaWatch,
        stopCaptchaWatch: stopCaptchaWatch,
        pollCaptchaStatus: pollCaptchaStatus,
        buildLoginLoader: buildLoginLoader,
        config: uxConfig,
        isCaptchaExpired: function () { return captchaWatch.expired; }
    };

    /* NOTE (performance): the CAPTCHA image is already fetched by the markup
       (``<img id="captchaImage" src="/captcha">``), which also stores the matching
       code in the session.  Refetching it here on DOMContentLoaded generated the
       image a second time on every page view and could overwrite the stored code
       *after* the user had already typed the first one. */
})();
