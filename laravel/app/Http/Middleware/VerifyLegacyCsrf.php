<?php

namespace App\Http\Middleware;

use App\Auth\LegacyUserProvider;
use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

/**
 * CSRF protection that accepts the new and the legacy token schemes alike.
 *
 * The existing front-end mints a token into the signed session, publishes a copy in
 * a **readable** `csrf_token` cookie, and echoes it back in an `X-CSRF-Token`
 * header; the server requires the header, the cookie and the session value to
 * agree.  The new Vue client uses Laravel's own `XSRF-TOKEN` cookie, which the
 * browser and axios handle automatically.
 *
 * During the side-by-side period the same `/api` routes are reachable from both
 * clients, so both are accepted — each through its own constant-time comparison:
 *
 * * Laravel's native path (`_token` or `X-CSRF-TOKEN` against the session token),
 *   inherited unchanged;
 * * the legacy path (`X-CSRF-Token` against the session's `csrf_token`), added
 *   here.
 *
 * Two further legacy behaviours are reproduced, and both are security controls
 * rather than conveniences:
 *
 * * **Prefix exemptions.**  The kiosk, the call display, the registration form and
 *   the recovery endpoints have no usable session CSRF token (an anonymous kiosk
 *   has no session to bind one to).  They are exempt by *path prefix*, which is
 *   how the Python middleware matched them (`CSRF_EXEMPT_PREFIXES`).  Laravel's
 *   stock `except` list matches whole URIs, so `inExceptArray()` is extended to
 *   keep the prefix semantics — a list translated to `/*` patterns would silently
 *   stop exempting a nested path the day someone adds one.
 * * **Same-site Origin enforcement on exempt writes.**  An exemption is not a
 *   free pass: the Python middleware still rejected a *cross-site* write to an
 *   exempt path by checking the `Origin` header, and requests with no `Origin`
 *   (the Araz bridge agent, scripts, curl) stayed allowed.  That check runs here
 *   too, because without it every exempt endpoint would be a CSRF hole with the
 *   mitigation left for a later phase to rediscover.
 *
 * Nothing is weakened: an unsafe method that is neither exempt nor carrying a
 * matching token is still rejected.
 *
 * Not `final`, and for the same reason {@see LegacyUserProvider} is not:
 * the CSRF decision is the one control that Laravel's test helpers deliberately
 * switch off (the framework skips validation while running tests), so the only way
 * to assert that a cross-site write to an exempt path is still refused is to
 * subclass this middleware and turn that bypass back off.  A `final` class would
 * leave the exemption list and the Origin check untested.
 *
 * It extends `PreventRequestForgery` rather than the deprecated `ValidateCsrfToken`
 * alias of it, because the alias is only an empty subclass: `bootstrap/app.php`
 * replaces the class the `web` group actually contains, and `replace` matches by
 * exact class name.
 */
class VerifyLegacyCsrf extends PreventRequestForgery
{
    /**
     * Session key holding the legacy CSRF token.
     *
     * Transcribed from `app/api/routes/auth.py`, which stores it as `csrf_token`
     * and re-mints it on every login.
     */
    public const LEGACY_SESSION_KEY = 'csrf_token';

    /** The header the legacy front-end echoes the token in. */
    public const LEGACY_HEADER = 'X-CSRF-Token';

    /** The body the legacy middleware returned for a rejected request. */
    public const REJECTION_BODY = ['success' => false, 'error' => 'CSRF token mismatch.'];

    public function handle($request, Closure $next)
    {
        if ($this->isExempt($request) && ! $this->isReading($request) && ! $this->originAllowed($request)) {
            return response()->json(self::REJECTION_BODY, 403);
        }

        try {
            return parent::handle($request, $next);
        } catch (TokenMismatchException) {
            /*
             * The rejection is answered **here**, not from an exception handler.
             *
             * `Handler::prepareException()` rewrites a `TokenMismatchException`
             * into a generic `HttpException(419)` before any registered `render`
             * callback is consulted, so a callback keyed on the original class never
             * runs.  The client then receives Laravel's `419 Page Expired` — an HTML
             * page, or a full stack trace when `APP_DEBUG` is on, from an endpoint
             * documented to answer JSON.  Returning the body from the middleware that
             * owns the contract removes the whole class of surprise.
             */
            return response()->json(self::REJECTION_BODY, 403);
        }
    }

    protected function tokensMatch($request): bool
    {
        if (parent::tokensMatch($request)) {
            return true;
        }

        $session = $request->session();

        if (! $session) {
            return false;
        }

        $stored = (string) $session->get(self::LEGACY_SESSION_KEY, '');
        $provided = (string) $request->header(self::LEGACY_HEADER, '');

        if ($stored === '' || $provided === '') {
            return false;
        }

        return hash_equals($stored, $provided);
    }

    /**
     * Laravel's own list, plus the legacy prefix list.
     *
     * The configured prefixes keep their leading slash so a prefix can never match
     * a path in the middle of a segment.
     */
    protected function inExceptArray($request): bool
    {
        if (parent::inExceptArray($request)) {
            return true;
        }

        return $this->isExempt($request);
    }

    /** Whether the request path starts with one of the configured exempt prefixes. */
    private function isExempt(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');

        foreach ((array) config('hastama.csrf.exempt_prefixes', []) as $prefix) {
            $prefix = (string) $prefix;

            if ($prefix !== '' && str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a write to an exempt path came from this site.
     *
     * Browsers always send `Origin` on a cross-site unsafe request; a request with
     * no `Origin` at all is a script, the bridge agent or `curl`, and the documented
     * LAN integrations depend on those continuing to work.  `Origin: null` is
     * refused — it appears in sandboxed or `data:` documents, where an attacker can
     * control the value.
     */
    private function originAllowed(Request $request): bool
    {
        $origin = (string) $request->headers->get('Origin', '');

        if ($origin === '') {
            return true;
        }

        if (strcasecmp($origin, 'null') === 0) {
            return false;
        }

        $originHost = parse_url($origin, PHP_URL_HOST);

        if (! is_string($originHost) || $originHost === '') {
            return false;
        }

        $requestHost = (string) $request->getHost();

        return strcasecmp($originHost, $requestHost) === 0;
    }
}
