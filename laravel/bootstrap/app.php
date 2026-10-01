<?php

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureMasterAdmin;
use App\Http\Middleware\EnsureTicketActor;
use App\Http\Middleware\GuardKioskWrite;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidateLegacySession;
use App\Http\Middleware\VerifyLegacyCsrf;
use App\Support\Http\LegacyCsrfCookie;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyValidationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        /*
         * Feature route files are listed **before** `routes/web.php` on purpose.
         *
         * `routes/web.php` ends with the SPA fallback, and routes match in
         * registration order — a file loaded after the fallback would be shadowed by
         * `/{fallbackPlaceholder}` for every path it declares.  Grouping the ported
         * route groups into their own files is what lets each feature be migrated
         * without editing the same file, and ordering them ahead of the fallback is
         * what makes that safe.
         */
        web: [
            __DIR__.'/../routes/master-admin-control.php',
            __DIR__.'/../routes/master-admin-settings.php',
            __DIR__.'/../routes/notifications.php',
            __DIR__.'/../routes/ticketing.php',
            __DIR__.'/../routes/automation.php',
            __DIR__.'/../routes/registration.php',
            __DIR__.'/../routes/araz.php',
            __DIR__.'/../routes/user-panel-writes.php',
            __DIR__.'/../routes/admin-panels.php',
            __DIR__.'/../routes/public-pages.php',
            __DIR__.'/../routes/web.php',
        ],
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * CSRF: accept the legacy scheme as well as Laravel's own.
         *
         * The running front-end mints a token into the session, publishes a
         * readable `csrf_token` cookie and echoes it in `X-CSRF-Token`; the new
         * Vue client uses Laravel's `XSRF-TOKEN` cookie.  Both must work while the
         * two applications share a browser, so the stock middleware is *replaced*
         * (not bypassed) by a subclass that keeps the native check and adds the
         * legacy comparison.  An unsafe request with neither token is still
         * rejected — nothing here weakens the control.
         *
         * The key must be `PreventRequestForgery`, which is the class Laravel 13
         * actually puts in the `web` group.  `ValidateCsrfToken` still exists but
         * only as a deprecated empty subclass of it, and a `replace` key that names
         * a class the group does not contain is silently ignored: the stock
         * middleware keeps running, no legacy exemption applies, and every
         * submission from the existing front-end is answered `419 Page Expired`.
         * That failure is invisible to the test suite (which skips CSRF entirely)
         * and to a route listing, so it is asserted directly in
         * `AuthSurfaceTest::the_csrf_bridge_is_the_middleware_in_the_web_group`.
         */
        $middleware->web(replace: [
            PreventRequestForgery::class => VerifyLegacyCsrf::class,
        ]);

        /*
         * The readable `csrf_token` cookie must NOT be encrypted.
         *
         * Laravel encrypts every outgoing cookie by default, which is the right
         * default and the wrong answer here: `app/static/js/csrf-bootstrap.js` reads
         * this cookie with `document.cookie` and echoes the raw value in
         * `X-CSRF-Token`, and the middleware compares that header against the
         * *plaintext* token stored in the session.  An encrypted cookie would put a
         * ciphertext in the header and every unsafe request from the existing
         * front-end would be rejected as a CSRF mismatch.
         *
         * The value is not a credential — it is worthless without the session
         * cookie, which stays encrypted and `httpOnly`.  This mirrors exactly what
         * the legacy middleware published (`Set-Cookie: csrf_token=…; Path=/;
         * SameSite=Lax`), byte for byte.
         */
        $middleware->encryptCookies(except: [
            LegacyCsrfCookie::NAME,
        ]);

        /*
         * Input is NOT rewritten before it reaches a controller.
         *
         * Laravel puts two normalisers in the global stack — `TrimStrings` (trims every
         * string in the query and request bags) and `ConvertEmptyStringsToNull`
         * (turns every `''` into `null`) — and both are wrong for this application,
         * because FastAPI had neither:
         *
         * * `GET /get_user_info_report?username= admin` is a different request from
         *   `?username=admin`.  The Python handler compared `username.strip()` for the
         *   ownership check but passed the **untrimmed** value to `WHERE username = ?`;
         *   with `TrimStrings` active the two spellings collapse into one and a
         *   request the running server distinguishes would answer identically.
         * * `?username=` (present but empty) is not the same request as no `username`
         *   at all: FastAPI answered 200 with the handler's own
         *   `نام کاربری ارائه نشده است` body for the first and `422` for the second.
         *   `ConvertEmptyStringsToNull` made both `null`, which is why
         *   `/get_user_info_report?username=` was rejected as `missing` during Phase 5.
         *
         * The Python code called `.strip()` exactly where it meant to — `_require_admin`
         * did, `_ticket_actor` did, the SQL predicates did `LTRIM(RTRIM(...))` around the
         * *stored* column.  The Phase 4 services follow the same rule explicitly
         * (`LoginThrottle`, `SessionRegistry`, `LoginController` all call `trim()` or
         * `mb_strtolower(trim(...))` by hand), so nothing depends on the framework doing
         * it implicitly.  Removing these two is what makes an empty or padded value
         * mean the same thing on both servers — and it is a single decision rather than
         * a normalisation re-derived in every controller of every remaining phase.
         */
        $middleware->remove([
            TrimStrings::class,
            ConvertEmptyStringsToNull::class,
        ]);

        /*
         * Baseline security headers on every browser response.
         *
         * Appended rather than prepended so a route can override any of them, and
         * deliberately without a Content-Security-Policy: the full policy is a
         * Phase 15 decision taken against the finished front-end, where every
         * resource origin is known.
         */
        $middleware->web(append: [
            SecurityHeaders::class,
        ]);

        /*
         * Named aliases the routes use.
         *
         * `admin` and `master_admin` are the two authorisation levels the legacy
         * application distinguishes (`_require_admin` / `_master_admin`), and their
         * response bodies are part of the front-end contract — see the middleware
         * docblocks.  `legacy.session` is the registry-backed revocability check and
         * must always run *after* the guard has resolved the user.
         */
        /*
         * The call-system's kiosk guard.
         *
         * It takes a **bucket** parameter (`kiosk.write:calls-create`) because the Python
         * keyed its rate limiter by `f"{bucket}:{ip}"` — one shared counter would let the
         * reset button exhaust the budget for taking a ticket.
         *
         * The second call-system guard, `_guard_queue_pii`, is deliberately **not** here:
         * it runs inside its two handlers, because FastAPI validates a request's path and
         * body before the handler's first line and middleware would have answered `403`
         * where the running server answers `422`.  See `App\Support\Legacy\QueuePii`.
         */
        $middleware->alias([
            'admin' => EnsureAdmin::class,
            'master_admin' => EnsureMasterAdmin::class,
            'ticket.actor' => EnsureTicketActor::class,
            'legacy.session' => ValidateLegacySession::class,
            'kiosk.write' => GuardKioskWrite::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * The Vue client always wants the JSON envelope, never an HTML error
         * page, and never a stack trace.  Anything under /api — plus any
         * request that explicitly asks for JSON — is rendered as JSON.
         */
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * A rejected CSRF request answers what the legacy middleware answered:
         * HTTP **403** with `{"success": false, "error": "CSRF token mismatch."}`.
         *
         * Laravel's default is 419 with a framework-shaped body.  Nothing in the
         * existing front-end branches on either — but the two applications share a
         * browser during the side-by-side period, and a client that cannot tell
         * "your session expired" (401) from "your token was stale" (403) will
         * retry the wrong request.  Keeping one body for one condition is worth
         * more than keeping Laravel's status code for it.
         *
         * Answered for **every** request, as the Python middleware did: its
         * `_reject()` returned a JSON body unconditionally, including for the
         * browser requests to `/login_user`.  Gating this on `api/*`/`expectsJson()`
         * produced the one outcome that is hard to diagnose — a bare `419 Page
         * Expired` HTML page from an endpoint documented to answer JSON.
         *
         * This is a safety net rather than the mechanism: `VerifyLegacyCsrf` already
         * answers the rejection itself, because `Handler::prepareException()`
         * rewrites a `TokenMismatchException` into a plain `HttpException(419)`
         * *before* render callbacks run, so a callback keyed on the original class
         * would never fire.  The guard here is on the status instead, which is what
         * the rewritten exception actually carries.
         */
        $exceptions->render(function (HttpException $exception, Request $request) {
            return $exception->getStatusCode() === 419
                ? response()->json(VerifyLegacyCsrf::REJECTION_BODY, 403)
                : null;
        });

        /*
         * A rejected query parameter answers FastAPI's 422, body and all.
         *
         * This must be rendered **explicitly**, not left to Laravel's own
         * validation handling.  `ValidationException` is rendered as a `302` redirect
         * back whenever the request does not send `Accept: application/json`, and the
         * existing front-end calls these endpoints with `fetch()` defaults — so the
         * browser would have received a redirect to the page it was already on
         * instead of the rejection the running server produces.  Nothing in the old
         * response was a redirect, and the status is what the callers branch on.
         *
         * `shouldRenderJsonWhen` above cannot cover this: it is consulted for the
         * *framework's* rendering, and the legacy endpoints live at the application
         * root (`/get_user_info_report`), not under `/api`.
         */
        $exceptions->render(function (LegacyValidationException $exception) {
            return response()->json($exception->body(), 422);
        });

        /*
         * A raised HTTP refusal answers `{"detail": …}`.
         *
         * FastAPI renders `HTTPException` as a single-key body, and the call-system module
         * raises it for every refusal it makes: the kiosk guard's cross-site and throttled
         * answers, the administrator checks, and a dozen "not found" branches.  Laravel's
         * own JSON rendering uses `message` for the same condition, so a client that reads
         * `detail` — which is what both front-ends do, because that is what the running
         * server has always sent — would see `undefined` for every one of them.
         *
         * Rendered here rather than per-controller so the envelope cannot drift, and so the
         * `EnsureAdmin`/`EnsureMasterAdmin` bodies (`{"success": false, "error": …}`) stay
         * distinct: those are the shapes the Python *decorators* produced, and both are live.
         */
        $exceptions->render(function (LegacyHttpException $exception) {
            return response()->json($exception->body(), $exception->getStatusCode());
        });

        /* A refused request is a client mistake, not an application fault — and these carry
         * messages an attacker can trigger on purpose, so they must not become log noise. */
        $exceptions->dontReport([
            LegacyValidationException::class,
            LegacyHttpException::class,
        ]);
    })->create();
