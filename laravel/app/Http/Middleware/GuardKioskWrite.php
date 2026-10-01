<?php

namespace App\Http\Middleware;

use App\Support\Http\ClientAddress;
use App\Support\Http\LegacyOrigin;
use App\Support\Legacy\LegacyHttpException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * `_guard_kiosk_write` — the same-site check and rate limit on the kiosk writes.
 *
 * The reception kiosk has no session, so the endpoints that drive the TV displays are
 * CSRF-exempt in the legacy middleware.  That exemption is only safe because every one
 * of them performs this check first:
 *
 * 1. **the browser context must be our own site** — a cross-site page can no longer make
 *    a logged-in browser fire a call, and a random workstation cannot flood the screens;
 * 2. **120 requests per minute per IP**, so an abusive client cannot bury the displays or
 *    the waiting queue.
 *
 * Both refusals are FastAPI's: `403 {"detail": "درخواست از مبدأ مجاز نیست."}` and
 * `429 {"detail": "تعداد درخواستها بیش از حد مجاز است."}`
 *
 * The bucket name is a route parameter (`kiosk.write:calls-create`), because the Python
 * keyed the limiter by `f"{bucket}:{ip}"` — a shared counter would let the reset button
 * exhaust the budget for taking a ticket, and those are the same 120 requests an
 * operator makes in a different order.
 *
 * **Fixed window, not sliding.** `app/core/rate_limit.py` implements a genuine sliding
 * window; Laravel's `RateLimiter` counts from the first hit and expires the whole window.
 * The two differ by at most one window's worth of requests at a boundary, and the
 * control is a flood guard rather than a per-request quota — recorded here rather than
 * reimplemented, since a sliding window in the cache store would be a second rate
 * limiter to keep correct for the whole application.
 */
final class GuardKioskWrite
{
    /** `CALL_SYSTEM_WRITE_LIMIT` / `CALL_SYSTEM_WRITE_WINDOW`. */
    public const LIMIT = 120;

    public const WINDOW_SECONDS = 60;

    public function handle(Request $request, Closure $next, string $bucket = 'default'): Response
    {
        if (! LegacyOrigin::isSameSite($request)) {
            Log::warning('call-system write rejected for cross-site origin', [
                'origin' => $request->headers->get('origin'),
                'path' => $request->path(),
            ]);

            throw LegacyHttpException::crossSite();
        }

        $key = "hastama.call-system.kiosk.{$bucket}:".ClientAddress::for($request);

        if (RateLimiter::tooManyAttempts($key, self::LIMIT)) {
            throw LegacyHttpException::throttled();
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        return $next($request);
    }
}
