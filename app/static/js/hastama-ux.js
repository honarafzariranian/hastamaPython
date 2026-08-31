/* ==========================================================================
   Hastama Global UX JavaScript Infrastructure
   --------------------------------------------------------------------------
   Centralized utilities for page loading, button states, double-submit
   protection, confirm dialogs, and accessibility.
   
   Usage:
     <script src="/static/js/hastama-ux.js"></script>
   
   Provides:
     - window.HastamaUX.pageReady()      — dismiss page loader
     - window.HastamaUX.btnLoad(btn, text) — set button loading
     - window.HastamaUX.btnDone(btn)       — set button success
     - window.HastamaUX.btnReset(btn)      — reset button
     - window.HastamaUX.confirm(opts)      — show confirm dialog
     - window.HastamaUX.emptyState(container, opts) — render empty state
     - window.HastamaUX.errorState(container, opts) — render error state
     - window.HastamaUX.inlineLoader(container, text) — show inline loader
     - window.HastamaUX.trapFocus(el)      — trap focus in element
   ========================================================================== */
(function () {
    'use strict';

    var ux = {};

    /* ──────────────────────────────────────────────────────────────────────
       1) PAGE LOADER
       ────────────────────────────────────────────────────────────────────── */
    ux.pageReady = function () {
        var overlay = document.querySelector('.h-ux-page-loading-overlay');
        if (!overlay) return;
        overlay.classList.add('h-ux-page-loading--exit');
        setTimeout(function () {
            if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
        }, 350);
    };

    // Auto-dismiss after safety timeout (prevent infinite loading)
    setTimeout(function () { ux.pageReady(); }, 8000);

    /* ──────────────────────────────────────────────────────────────────────
       2) BUTTON LOADING SYSTEM
       ────────────────────────────────────────────────────────────────────── */
    ux.btnLoad = function (btn, loadingText) {
        if (!btn) return;
        if (!btn.getAttribute('data-h-ux-orig-text')) {
            btn.setAttribute('data-h-ux-orig-text', btn.textContent);
        }
        btn.disabled = true;
        btn.classList.add('h-ux-loading');
        if (loadingText) {
            var loadingEl = btn.querySelector('.h-ux-btn-loading-text');
            if (loadingEl) loadingEl.textContent = loadingText;
        }
    };

    ux.btnDone = function (btn, successText) {
        if (!btn) return;
        btn.disabled = true;
        btn.classList.remove('h-ux-loading');
        btn.classList.add('h-ux-success');
        if (successText) {
            var textEl = btn.querySelector('.h-ux-btn-text');
            if (textEl) textEl.textContent = successText;
        }
        setTimeout(function () { ux.btnReset(btn); }, 2000);
    };

    ux.btnReset = function (btn) {
        if (!btn) return;
        btn.disabled = false;
        btn.classList.remove('h-ux-loading', 'h-ux-success');
        var orig = btn.getAttribute('data-h-ux-orig-text');
        if (orig) {
            var textEl = btn.querySelector('.h-ux-btn-text');
            if (textEl) textEl.textContent = orig;
        }
    };

    /* ──────────────────────────────────────────────────────────────────────
       3) DOUBLE-SUBMIT PROTECTION
       ──────────────────────────────────────────────────────────────────────
       Returns false if already pending, true if safe to proceed.
       Uses data attribute to track pending state.
       --------------------------------------------------------------------- */
    ux.submitLock = function (key) {
        var marker = 'h-ux-submitting-' + key;
        if (document.body.getAttribute(marker) === '1') return false;
        document.body.setAttribute(marker, '1');
        return true;
    };

    ux.submitUnlock = function (key) {
        document.body.removeAttribute('h-ux-submitting-' + key);
    };

    /* ──────────────────────────────────────────────────────────────────────
       4) CONFIRM DIALOG
       ────────────────────────────────────────────────────────────────────── */
    ux.confirm = function (opts) {
        return new Promise(function (resolve) {
            var title = opts.title || 'تأیید عملیات';
            var message = opts.message || 'آیا مطمئن هستید؟';
            var confirmText = opts.confirmText || 'بله';
            var cancelText = opts.cancelText || 'انصراف';
            var danger = opts.danger !== false;

            // Build overlay
            var overlay = document.createElement('div');
            overlay.className = 'h-ux-confirm-overlay';
            overlay.setAttribute('role', 'dialog');
            overlay.setAttribute('aria-modal', 'true');
            overlay.setAttribute('aria-label', title);

            overlay.innerHTML =
                '<div class="h-ux-confirm-card">' +
                    '<svg class="h-ux-confirm-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                        '<path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/>' +
                        '<line x1="12" y1="9" x2="12" y2="13"/>' +
                        '<line x1="12" y1="17" x2="12.01" y2="17"/>' +
                    '</svg>' +
                    '<h3 class="h-ux-confirm-title">' + escHtml(title) + '</h3>' +
                    '<p class="h-ux-confirm-message">' + escHtml(message) + '</p>' +
                    '<div class="h-ux-confirm-actions">' +
                        '<button type="button" class="h-ux-confirm-cancel" id="h-ux-confirm-cancel">' + escHtml(cancelText) + '</button>' +
                        '<button type="button" class="h-ux-confirm-ok" id="h-ux-confirm-ok">' + escHtml(confirmText) + '</button>' +
                    '</div>' +
                '</div>';

            document.body.appendChild(overlay);

            // Trigger animation
            requestAnimationFrame(function () {
                overlay.classList.add('h-ux-active');
            });

            var cancelBtn = overlay.querySelector('#h-ux-confirm-cancel');
            var okBtn = overlay.querySelector('#h-ux-confirm-ok');

            function close(result) {
                overlay.classList.remove('h-ux-active');
                setTimeout(function () {
                    if (overlay.parentNode) overlay.parentNode.removeChild(overlay);
                }, 300);
                resolve(result);
            }

            cancelBtn.addEventListener('click', function () { close(false); });
            okBtn.addEventListener('click', function () { close(true); });
            overlay.addEventListener('click', function (e) {
                if (e.target === overlay) close(false);
            });

            document.addEventListener('keydown', function handler(e) {
                if (e.key === 'Escape') {
                    close(false);
                    document.removeEventListener('keydown', handler);
                }
            });

            okBtn.focus();
        });
    };

    /* ──────────────────────────────────────────────────────────────────────
       5) EMPTY STATE RENDERER
       ────────────────────────────────────────────────────────────────────── */
    ux.emptyState = function (container, opts) {
        if (!container) return;
        var icon = opts.icon || '';
        var title = opts.title || 'داده‌ای یافت نشد';
        var desc = opts.desc || '';
        var actionHtml = opts.actionHtml || '';

        container.innerHTML =
            '<div class="h-ux-empty-state">' +
                (icon ? '<div class="h-ux-empty-state__icon">' + icon + '</div>' : '') +
                '<div class="h-ux-empty-state__title">' + escHtml(title) + '</div>' +
                (desc ? '<div class="h-ux-empty-state__desc">' + escHtml(desc) + '</div>' : '') +
                (actionHtml ? '<div class="h-ux-empty-state__action">' + actionHtml + '</div>' : '') +
            '</div>';
    };

    /* ──────────────────────────────────────────────────────────────────────
       6) ERROR STATE RENDERER
       ────────────────────────────────────────────────────────────────────── */
    ux.errorState = function (container, opts) {
        if (!container) return;
        var title = opts.title || 'خطا در بارگذاری';
        var desc = opts.desc || 'لطفاً اتصال خود را بررسی کنید و دوباره تلاش کنید.';
        var retryFn = opts.onRetry || null;

        container.innerHTML =
            '<div class="h-ux-error-state">' +
                '<svg class="h-ux-error-state__icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                    '<circle cx="12" cy="12" r="10"/>' +
                    '<line x1="15" y1="9" x2="9" y2="15"/>' +
                    '<line x1="9" y1="9" x2="15" y2="15"/>' +
                '</svg>' +
                '<div class="h-ux-error-state__title">' + escHtml(title) + '</div>' +
                '<div class="h-ux-error-state__desc">' + escHtml(desc) + '</div>' +
                (retryFn ? '<div class="h-ux-error-state__action"><button type="button" class="h-ux-btn h-ux-btn--retry" style="padding:8px 20px;border-radius:8px;background:#3b82f6;color:#fff;font-size:13px;font-weight:700;border:none;cursor:pointer">تلاش مجدد</button></div>' : '') +
            '</div>';

        if (retryFn) {
            var retryBtn = container.querySelector('.h-ux-btn--retry');
            if (retryBtn) retryBtn.addEventListener('click', retryFn);
        }
    };

    /* ──────────────────────────────────────────────────────────────────────
       7) INLINE LOADER
       ────────────────────────────────────────────────────────────────────── */
    ux.inlineLoader = function (container, text) {
        if (!container) return;
        container.innerHTML =
            '<div class="h-ux-inline-loader">' +
                '<div class="h-ux-inline-loader__spinner"></div>' +
                '<span>' + escHtml(text || 'در حال بارگذاری...') + '</span>' +
            '</div>';
    };

    /* ──────────────────────────────────────────────────────────────────────
       8) FOCUS TRAP (for modals/dialogs)
       ────────────────────────────────────────────────────────────────────── */
    ux.trapFocus = function (el) {
        var focusable = el.querySelectorAll(
            'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
        );
        if (!focusable.length) return function () {};
        var first = focusable[0];
        var last = focusable[focusable.length - 1];

        function handler(e) {
            if (e.key !== 'Tab') return;
            if (e.shiftKey) {
                if (document.activeElement === first) {
                    e.preventDefault();
                    last.focus();
                }
            } else {
                if (document.activeElement === last) {
                    e.preventDefault();
                    first.focus();
                }
            }
        }

        el.addEventListener('keydown', handler);
        first.focus();

        return function () { el.removeEventListener('keydown', handler); };
    };

    /* ──────────────────────────────────────────────────────────────────────
       9) SAFE FETCH WRAPPER
       ──────────────────────────────────────────────────────────────────────
       Adds timeout, JSON parsing, and error normalization.
       --------------------------------------------------------------------- */
    ux.fetch = function (url, opts) {
        var timeout = (opts && opts.timeout) || 15000;
        var controller = null;
        var signal = undefined;

        if (typeof AbortController !== 'undefined') {
            controller = new AbortController();
            signal = controller.signal;
        }

        var timer = null;
        if (controller) {
            timer = setTimeout(function () { controller.abort(); }, timeout);
        }

        var config = Object.assign({ credentials: 'same-origin' }, opts || {});
        if (signal) config.signal = signal;

        if (config.body && typeof config.body === 'object' && !(config.body instanceof FormData)) {
            config.headers = Object.assign({ 'Content-Type': 'application/json', 'Accept': 'application/json' }, config.headers || {});
            config.body = JSON.stringify(config.body);
        }

        return fetch(url, config)
            .then(function (res) {
                if (timer) clearTimeout(timer);
                return res.json().then(function (data) {
                    if (!res.ok) {
                        var detail = data.detail || data.message || data.error;
                        if (Array.isArray(detail)) detail = detail.map(function (x) { return x.msg || x; }).join('، ');
                        throw { status: res.status, message: detail || 'درخواست انجام نشد.', data: data };
                    }
                    return data;
                });
            })
            .catch(function (err) {
                if (timer) clearTimeout(timer);
                if (err && err.name === 'AbortError') {
                    throw { status: 0, message: 'درخواست منقضی شد. دوباره تلاش کنید.' };
                }
                throw err;
            });
    };

    /* ──────────────────────────────────────────────────────────────────────
       UTILITIES
       ────────────────────────────────────────────────────────────────────── */
    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    /* ──────────────────────────────────────────────────────────────────────
       EXPOSE
       ────────────────────────────────────────────────────────────────────── */
    window.HastamaUX = ux;

})();
