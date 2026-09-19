/* ── CSRF bootstrap ────────────────────────────────────────────────────────
 * The server enforces a session-bound CSRF token on every state-changing
 * request:
 *
 *     X-CSRF-Token header  ==  csrf_token cookie  ==  token inside the signed
 *                                                      session
 *
 * All three values must match, so the browser has to echo the cookie back in a
 * header.  This file provides that plumbing for pages that do not load the
 * full Hastama UX bundle:
 *
 *   * reads the readable ``csrf_token`` cookie;
 *   * if the cookie is missing, asks ``GET /api/csrf-token`` (a safe method)
 *     for a fresh token and remembers it in memory;
 *   * wraps ``window.fetch`` once, so every state-changing call carries the
 *     header without touching the individual call sites;
 *   * converts native ``form.submit()`` / form submissions into fetch calls
 *     with the same header, so classic HTML forms keep working.
 *
 * The token is not a secret the page must protect from its own user: the
 * authoritative copy lives inside the signed session cookie, which the browser
 * will not send to a third-party origin.
 * ------------------------------------------------------------------------- */
(function () {
    'use strict';

    if (window.HastamaCSRF && window.HastamaCSRF.installed) {
        return;
    }

    var COOKIE_NAME = 'csrf_token';
    var ENDPOINT = '/api/csrf-token';
    var memoryToken = '';
    var pending = null;

    function readCookie() {
        var match = document.cookie.match(/(?:^|;\s*)csrf_token=([^;]*)/);
        return match ? decodeURIComponent(match[1]) : '';
    }

    function getToken() {
        return readCookie() || memoryToken || '';
    }

    function ensureToken() {
        var existing = getToken();
        if (existing) {
            return Promise.resolve(existing);
        }
        if (!pending) {
            pending = fetch(ENDPOINT, {
                method: 'GET',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    memoryToken = (data && data.csrf_token) || '';
                    return memoryToken;
                })
                .catch(function () { return ''; })
                .then(function (token) { pending = null; return token; });
        }
        return pending;
    }

    function isStateChanging(method) {
        var m = (method || 'GET').toUpperCase();
        return m !== 'GET' && m !== 'HEAD' && m !== 'OPTIONS';
    }

    function withToken(init, token) {
        if (!token) {
            return init;
        }
        var headers = init.headers;
        if (typeof Headers !== 'undefined' && headers instanceof Headers) {
            headers.set('X-CSRF-Token', token);
            init.headers = headers;
        } else if (Array.isArray(headers)) {
            init.headers = headers.concat([['X-CSRF-Token', token]]);
        } else {
            init.headers = Object.assign({}, headers || {}, { 'X-CSRF-Token': token });
        }
        return init;
    }

    if (typeof window.fetch === 'function') {
        var originalFetch = window.fetch;
        window.fetch = function (input, init) {
            init = init || {};
            var method = init.method || (input && input.method) || 'GET';
            if (!isStateChanging(method)) {
                return originalFetch.call(this, input, init);
            }
            var self = this;
            return ensureToken().then(function (token) {
                return originalFetch.call(self, input, withToken(init, token));
            });
        };
    }

    /* Native form submissions bypass fetch entirely — replay them as fetch
       calls with the token header attached. */
    document.addEventListener(
        'submit',
        function (event) {
            var form = event.target;
            if (!form || !form.tagName || form.tagName.toUpperCase() !== 'FORM') {
                return;
            }
            var method = (form.getAttribute('method') || 'GET').toUpperCase();
            if (!isStateChanging(method)) {
                return;
            }
            event.preventDefault();
            ensureToken().then(function (token) {
                var action = form.getAttribute('action') || window.location.pathname;
                return window.fetch(action, withToken({
                    method: method,
                    body: new FormData(form),
                    credentials: 'same-origin'
                }, token));
            }).then(function (response) {
                if (response && response.redirected) {
                    window.location.href = response.url;
                } else {
                    window.location.reload();
                }
            });
        },
        true
    );

    /* ``form.submit()`` does not fire a submit event, so the listener above
       would never see the native-form call sites.  Route programmatic
       submissions of state-changing forms through the same path (GET forms
       keep their original behaviour). */
    if (window.HTMLFormElement && HTMLFormElement.prototype.submit) {
        var nativeSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () {
            var method = (this.getAttribute('method') || 'GET').toUpperCase();
            if (!isStateChanging(method)) {
                return nativeSubmit.call(this);
            }
            var event = new Event('submit', { bubbles: true, cancelable: true });
            this.dispatchEvent(event);
            if (!event.defaultPrevented) {
                return nativeSubmit.call(this);
            }
            return undefined;
        };
    }

    window.HastamaCSRF = {
        installed: true,
        getToken: getToken,
        ensureToken: ensureToken,
        refresh: function () {
            memoryToken = '';
            return ensureToken();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { ensureToken(); });
    } else {
        ensureToken();
    }
})();
