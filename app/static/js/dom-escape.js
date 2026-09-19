/* ── Output escaping helper ───────────────────────────────────────────────
 * Report pages build table rows with template literals and assign them to
 * `innerHTML`.  The values come from the database (employee written overtime
 * descriptions, leave substitute names, …), so they must be escaped before
 * they are concatenated into markup — otherwise a stored description like
 * `<img src=x onerror=...>` executes in the administrator's browser.
 *
 * The helper is deliberately tiny and dependency free so it can be loaded
 * before any report script.  It is idempotent: loading it twice is harmless.
 * ----------------------------------------------------------------------- */
(function () {
    'use strict';

    if (typeof window.escapeHtml === 'function') {
        return;
    }

    var ENTITIES = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;',
        '`': '&#96;',
        '=': '&#61;'
    };

    window.escapeHtml = function (value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).replace(/[&<>"'`=]/g, function (char) {
            return ENTITIES[char];
        });
    };

    // Short alias used inside the report scripts.
    window.esc = window.escapeHtml;
})();
