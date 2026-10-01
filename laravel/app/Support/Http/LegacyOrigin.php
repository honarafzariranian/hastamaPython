<?php

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * `app/core/net.py::origin_is_same_site` — the same-site check the CSRF-exempt write
 * paths rely on.
 *
 * The call-system endpoints that the reception kiosk drives cannot carry a CSRF token
 * (the kiosk has no session at all), so the Python middleware exempted them and each
 * handler instead asserted that the browser context was its own site.  That assertion
 * is the *only* thing standing between a random page on an employee workstation and
 * the TV displays, so it is ported exactly rather than replaced with Laravel's
 * `Sec-Fetch-Site` check (which only recent browsers send — see `Q12`).
 *
 * The rule, verbatim:
 *
 * * **no `Origin` header** → allowed.  A non-browser client, or a same-origin
 *   navigation, does not send one, and the legacy application treated that as
 *   trustworthy.  (A cross-site *form* post is the case `Origin` exists to catch.)
 * * `Origin: null` → **refused**.  That is what a sandboxed iframe or a `data:` URL
 *   sends, and it is never the kiosk.
 * * anything unparseable, or with no host, → refused.
 * * otherwise the origin's host must equal the `Host` header's host, comparing hosts
 *   only (port and scheme are ignored, so `http://lan-host:8000` and
 *   `https://lan-host` both match a `lan-host` request — which matters because the
 *   displays are reached by IP, by hostname and through the tunnel).
 *
 * `strcasecmp` rather than `===`: host names are case-insensitive, and Python's
 * `.lower()` on both sides said so.
 */
final class LegacyOrigin
{
    public static function isSameSite(Request $request): bool
    {
        $origin = trim((string) $request->headers->get('origin', ''));

        if ($origin === '') {
            return true;
        }

        if ($origin === 'null') {
            return false;
        }

        $originHost = self::originHost($origin);

        if ($originHost === '') {
            return false;
        }

        $requestHost = self::headerHost((string) $request->headers->get('host', ''));

        return $requestHost !== '' && strcasecmp($originHost, $requestHost) === 0;
    }

    /**
     * `urlparse(origin).netloc.split(":")[0].lower()`, or `''` when there is no netloc.
     *
     * `parse_url` is used only to find the authority; the host is then taken with the
     * same naive `split(':')` the Python used, so the two agree on every input.  That
     * matters for the bracketed IPv6 form (`http://[::1]:8000`), where the split yields
     * `[` on both sides rather than a correct address — reproduced deliberately: a
     * "better" comparison here would start refusing origins the running server accepts,
     * and the displays are reached by IP as well as by name.
     */
    private static function originHost(string $origin): string
    {
        if (parse_url($origin) === false) {
            return '';
        }

        // `//host[:port]` — parse_url reports an authority only after a scheme, which
        // is why the netloc is searched for rather than read from the `host` key.
        //
        // The delimiter is `~` and not `#`: this pattern has to match a `#`, because a
        // fragment can follow the authority, and PHP reads the first unescaped `#` as the
        // end of the pattern — `[^/?#]` closed it and the tail was parsed as modifiers
        // ("Unknown modifier ']'").  Every origin-carrying request then raised a warning.
        if (preg_match('~^[a-z][a-z0-9+.-]*://([^/?#]*)~i', $origin, $matches) !== 1) {
            return '';
        }

        $netloc = $matches[1];

        if ($netloc === '') {
            return '';
        }

        // Credentials are part of a netloc but not of the comparison the Python made,
        // and `netloc.split(":")[0]` could not even see past them.
        return strtolower(explode(':', $netloc, 2)[0]);
    }

    /**
     * `host.split(":")[0].lower()` for the `Host` header.
     *
     * A bare `host:port` has no scheme, so `parse_url` reads `host` as a scheme and
     * finds no `host` key at all — the header is split by hand instead.
     */
    private static function headerHost(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        return strtolower(explode(':', $value, 2)[0]);
    }
}
