<?php

namespace App\Support\Legacy;

use App\Support\Http\ClientAddress;
use App\Support\Http\LegacyOrigin;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `_guard_queue_pii()` — the stricter rule on the two endpoints that expose patient data.
 *
 * `/api/queue/ticket/{n}` carries a name, a national id, a phone number and the insurance
 * fields, and both methods are reachable **without a session** because the kiosk's edit
 * screen is.  That makes them the one place in the call system where an anonymous script
 * could enumerate personal data, so the Python's rule is tighter than the kiosk guard and
 * is ported exactly:
 *
 * 1. **an `Origin` header is always enforced — even for an administrator.** The path is
 *    CSRF-exempt, so a leaked admin cookie must not be drivable from another site;
 * 2. **otherwise a `Referer` must point at this host**, which is what a same-origin
 *    browser navigation sends;
 * 3. **otherwise only an authenticated administrator gets in** — a non-browser client
 *    with no `Origin` and no `Referer` cannot enumerate anything, while internal tooling
 *    holding a session cookie still can;
 * 4. **30 requests per minute per IP.**
 *
 * ### Why this is a static call and not middleware
 *
 * It started as one, and the running server says that was wrong.  FastAPI validates a
 * handler's declared parameters **before** calling it, and `_guard_queue_pii` is the
 * first line of the handler body — so for a malformed ticket number the running server
 * answers `422 int_parsing`, and it does so even when the browser context is one this
 * guard would have refused:
 *
 *     GET /api/queue/ticket/abc                     -> 422   (no context at all)
 *     GET /api/queue/ticket/abc -H 'Referer: evil'   -> 422   (cross-site referer)
 *     GET /api/queue/ticket/5   -H 'Referer: evil'   -> 403   (guard, path is fine)
 *
 * As route middleware the guard would have run first and answered `403` to all three,
 * so every non-browser client sending a bad segment — which is what a stray `curl` or a
 * mistyped kiosk id looks like — would have got a different status *and* a different
 * body.  The guard therefore runs inside the handler, after {@see LegacyPath} and
 * {@see LegacyBody}, which is where the Python puts it.
 *
 * `kiosk.write` stays middleware: its cross-site branch is already covered by
 * `VerifyLegacyCsrf` one layer up, so the only way it can differ is when the rate limit
 * is spent — a divergence already recorded with the other middleware-ordering ones.
 *
 * The referer comparison is deliberately the naive one the Python wrote — strip the port
 * from both sides and compare hosts case-insensitively, refusing an unparseable referer.
 * A stricter check would start rejecting the kiosk's own navigation.
 */
final class QueuePii
{
    /** `QUEUE_PII_LIMIT` / `QUEUE_PII_WINDOW`. */
    public const LIMIT = 30;

    public const WINDOW_SECONDS = 60;

    /**
     * `_guard_queue_pii(request, bucket)` — refuse the request, or return.
     *
     * @param  string  $bucket  The limiter key: `queue-ticket-read` and
     *                          `queue-ticket-write` are separate budgets, because they
     *                          are separate buttons on the kiosk's edit screen.
     *
     * @throws LegacyHttpException 403 cross-site, 429 over the limit.
     */
    public static function assert(Request $request, string $bucket): void
    {
        $origin = trim((string) $request->headers->get('origin', ''));

        if ($origin !== '') {
            if (! LegacyOrigin::isSameSite($request)) {
                Log::warning('queue PII rejected for cross-site origin', ['origin' => $origin]);

                throw LegacyHttpException::crossSite();
            }
        } else {
            $referer = trim((string) $request->headers->get('referer', ''));

            if ($referer !== '') {
                if (! self::refererMatchesHost($referer, (string) $request->headers->get('host', ''))) {
                    Log::warning('queue PII rejected for cross-site referer', ['referer' => $referer]);

                    throw LegacyHttpException::crossSite();
                }
            } elseif (! self::isAdministrator($request)) {
                throw LegacyHttpException::crossSite();
            }
        }

        $key = "hastama.call-system.pii.{$bucket}:".ClientAddress::for($request);

        if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
            throw LegacyHttpException::throttled();
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);
    }

    /**
     * `session("is_admin") is True` is compared with `=== true`, not truthiness: the
     * legacy session flag is a real boolean and a string `"1"` must not pass as an
     * administrator.  This is the same distinction `_require_admin` makes.
     *
     * A request that never entered the session middleware has no store to read (Laravel
     * throws `Session store not set on request.`), and "no session" is exactly the case
     * branch 3 exists to refuse — so `hasSession()` answers first.
     */
    private static function isAdministrator(Request $request): bool
    {
        return $request->hasSession() && $request->session()->get('is_admin') === true;
    }

    /** `urlparse(referer).netloc.split(":")[0]` vs `host.split(":")[0]`, both lower-cased. */
    private static function refererMatchesHost(string $referer, string $host): bool
    {
        $refererHost = '';

        // `~` delimiter — see `LegacyOrigin::originHost()`, where a `#` inside the
        // character class turned the pattern into a warning and matched nothing.
        if (preg_match('~^[a-z][a-z0-9+.-]*://([^/?#]*)~i', $referer, $matches) === 1) {
            $refererHost = strtolower(explode(':', $matches[1], 2)[0]);
        }

        $requestHost = strtolower(explode(':', trim($host), 2)[0]);

        return $refererHost !== '' && $requestHost !== '' && $refererHost === $requestHost;
    }
}
