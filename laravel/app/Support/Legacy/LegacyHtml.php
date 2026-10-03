<?php

namespace App\Support\Legacy;

/**
 * Jinja-compatible HTML escaping.
 *
 * FastAPI's `Jinja2Templates` builds its environment with
 * `autoescape=jinja2.select_autoescape()`, which is **on** for `.html`
 * templates, so every `{{ … }}` in the Python templates escapes its value with
 * `markupsafe.escape`.  The ported documents (`offline.html`,
 * `vpn-warning.html`) are nowdoc templates with `{{placeholder}}` markers that
 * PHP substitutes, and PHP does not escape them by itself — so the
 * substitution has to escape, or two things break:
 *
 *  * **Security.** The title, message and help text on both documents are
 *    operator-supplied (`outage_title`, `vpn_title`, `vpn_message`,
 *    `help_text` …) and are shown to *unauthenticated* visitors, before they
 *    are allowed in.  Escaping at the render layer is the control that keeps
 *    them from becoming stored markup.
 *  * **Fidelity.** The copy itself carries `&` (the iPhone VPN instructions
 *    contain "VPN & Device Management"), which the Python renders as `&amp;`
 *    and a port that does not escape renders as a bare `&` — a byte
 *    difference in every served document.
 *
 * The replacement set is `markupsafe`'s, not PHP's.  `htmlspecialchars` is not
 * a drop-in: it emits `&quot;`/`&#039;`, where `markupsafe` emits
 * `&#34;`/`&#39;` — which would make every ported document differ from the
 * running server in the bytes that carry a quote.
 */
final class LegacyHtml
{
    /**
     * `markupsafe.escape` — `&` first, or the ampersands it introduces would
     * themselves be escaped.
     */
    private const REPLACEMENTS = [
        '&' => '&amp;',
        '<' => '&lt;',
        '>' => '&gt;',
        "'" => '&#39;',
        '"' => '&#34;',
    ];

    /**
     * Escape a value the way a Jinja `{{ … }}` expression would.
     */
    public static function escape(mixed $value): string
    {
        return strtr((string) $value, self::REPLACEMENTS);
    }
}
