<?php

namespace App\Support\Http;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * The readable `csrf_token` cookie, built in one place.
 *
 * The legacy front-end reads this cookie and echoes it in the `X-CSRF-Token`
 * header; Laravel cannot produce it, because Laravel's own token travels in the
 * encrypted `XSRF-TOKEN` cookie.  Both the login flow and `/api/csrf-token`
 * publish it, and a second hand-written `cookie()` call is exactly the kind of
 * duplication that drifts — one copy gains a flag, the other does not, and the
 * two endpoints start disagreeing about a security attribute.
 *
 * Two properties are deliberate and must not be "fixed":
 *
 * * **`httpOnly` is false.**  The whole point is that the page's JavaScript can
 *   read it.  The token is not a credential: it is the double-submit half of the
 *   scheme and is worthless without the session cookie, which stays `httpOnly`.
 * * **`SameSite=Lax`,** as the legacy middleware set.  `Strict` would drop the
 *   cookie on the first navigation back from an external link, producing a
 *   spurious "session expired" on an otherwise valid session.
 */
final class LegacyCsrfCookie
{
    public const NAME = 'csrf_token';

    /**
     * Lifetime of the cookie, in seconds.
     *
     * Seconds, because that is the operator-facing unit
     * (`SESSION_MAX_AGE_SECONDS`, and the `Max-Age` attribute the legacy middleware
     * emitted).  Laravel's `cookie()` helper takes **minutes**, so the conversion
     * happens once, here, rather than being left to every caller to remember —
     * passing seconds straight through produced a cookie valid for 20 days instead
     * of 8 hours, which is a silent widening of the window in which a leaked token
     * is still usable.
     */
    public static function lifetimeSeconds(): int
    {
        return max(0, (int) config('hastama.session.csrf_cookie_max_age', 28800));
    }

    public static function make(Request $request, string $token): Cookie
    {
        return cookie(
            self::NAME,
            $token,
            intdiv(self::lifetimeSeconds(), 60),
            '/',
            null,
            $request->isSecure(),
            false,
            false,
            'lax',
        );
    }
}
