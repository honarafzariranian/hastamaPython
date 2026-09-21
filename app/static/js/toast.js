/* ==========================================================================
   Toast Notification System — Standalone
   Provides: showToast(msg, type), showSuccess(msg), showError(msg), showWarning(msg), showInfo(msg)
   Matches the look & feel of notification-system.js announce()
   ========================================================================== */
(function () {
    'use strict';

    var ICONS = { success: '✓', error: '×', warning: '!', info: 'i' };
    var TITLES = { success: 'عملیات موفق', error: 'خطای سامانه', warning: 'توجه سامانه', info: 'پیام سامانه' };
    var DURATIONS = { error: 6000, warning: 5000, success: 4500, info: 4000 };

    function getStack() {
        var stack = document.getElementById('toastStack');
        if (!stack) {
            stack = document.createElement('div');
            stack.id = 'toastStack';
            stack.className = 'toast-stack';
            document.body.appendChild(stack);
        }
        return stack;
    }

    /**
     * Show a toast notification.
     * @param {string} message  - The message to display
     * @param {string} [type]   - 'success' | 'error' | 'warning' | 'info' (default: 'success')
     * @param {object} [opts]   - { title: string, duration: number }
     */
    function showToast(message, type, opts) {
        type = type || 'success';
        opts = opts || {};
        if (['success', 'error', 'warning', 'info'].indexOf(type) === -1) type = 'info';

        var duration = opts.duration || DURATIONS[type] || 4500;
        var title = opts.title || TITLES[type];

        var stack = getStack();

        // Build toast element
        var toast = document.createElement('div');
        toast.className = 'toast toast--' + type;
        toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toast.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
        toast.style.setProperty('--toast-duration', duration + 'ms');

        // Header
        var head = document.createElement('div');
        head.className = 'toast__head';

        var icon = document.createElement('span');
        icon.className = 'toast__icon';
        icon.textContent = ICONS[type];
        icon.setAttribute('aria-hidden', 'true');

        var titleEl = document.createElement('strong');
        titleEl.className = 'toast__title';
        titleEl.textContent = title;

        var close = document.createElement('button');
        close.className = 'toast__close';
        close.type = 'button';
        close.textContent = '×';
        close.setAttribute('aria-label', 'بستن پیام');

        head.appendChild(icon);
        head.appendChild(titleEl);
        head.appendChild(close);

        // Message
        var msgEl = document.createElement('span');
        msgEl.className = 'toast__message';
        msgEl.textContent = message;

        // Timeline
        var timeline = document.createElement('div');
        timeline.className = 'toast__timeline';
        var timelineBar = document.createElement('span');
        timeline.appendChild(timelineBar);

        toast.appendChild(head);
        toast.appendChild(msgEl);
        toast.appendChild(timeline);

        stack.appendChild(toast);

        // Dismiss logic
        var dismissed = false;
        var timer = setTimeout(dismiss, duration);

        function dismiss() {
            if (dismissed) return;
            dismissed = true;
            clearTimeout(timer);
            toast.classList.remove('show');
            toast.classList.add('is-closing');
            setTimeout(function () { toast.remove(); }, 350);
        }

        close.addEventListener('click', dismiss);

        // Trigger animation
        requestAnimationFrame(function () {
            requestAnimationFrame(function () {
                toast.classList.add('show');
            });
        });

        return toast;
    }

    // ── Global API ──
    window.showToast = showToast;
    window.showSuccess = function (msg, opts) { return showToast(msg, 'success', opts); };
    window.showError = function (msg, opts) { return showToast(msg, 'error', opts); };
    window.showWarning = function (msg, opts) { return showToast(msg, 'warning', opts); };
    window.showInfo = function (msg, opts) { return showToast(msg, 'info', opts); };

    // ── Backward compat: pages that already use these names ──
    // showSystemError → showError (used by notification-system.js consumers)
    if (!window.showSystemError) {
        window.showSystemError = function (msg) { showToast(msg, 'error'); };
    }
    // showSuccessMessage → showSuccess
    if (!window.showSuccessMessage) {
        window.showSuccessMessage = function (msg) { showToast(msg, 'success'); };
    }
})();
