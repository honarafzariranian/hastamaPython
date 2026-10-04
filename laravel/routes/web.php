<?php

use App\Http\Controllers\Auth\CaptchaController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RecoveryController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CallSystem\CallController;
use App\Http\Controllers\CallSystem\QueueController;
use App\Http\Controllers\CallSystem\SlideController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\MasterAdmin\AuditLogController;
use App\Http\Controllers\MasterAdmin\DashboardController;
use App\Http\Controllers\MasterAdmin\SearchController;
use App\Http\Controllers\MasterAdmin\SessionListController;
use App\Http\Controllers\MasterAdmin\SystemHealthController;
use App\Http\Controllers\MasterAdmin\UserController;
use App\Http\Controllers\UserPanel\AttendanceController;
use App\Http\Controllers\UserPanel\CalendarController;
use App\Http\Controllers\UserPanel\HourlyPassController;
use App\Http\Controllers\UserPanel\LeaveController;
use App\Http\Controllers\UserPanel\OvertimeController;
use App\Http\Controllers\UserPanel\PickerController;
use App\Http\Controllers\UserPanel\ProfileController;
use App\Http\Controllers\UserPanel\ShiftController;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web routes
|--------------------------------------------------------------------------
|
| The application is a Vue single-page application.  Laravel serves one shell
| document and Vue Router owns everything below it, so browser history and
| deep links work without a full page load (the brief requires both).
|
| A fallback route is used rather than a greedy "/{any}" pattern so that a
| request which genuinely belongs to another route never reaches the shell, and
| an unknown API path still answers JSON instead of HTML.
|
| The authentication endpoints live **here** rather than in `routes/api.php`,
| and that is deliberate.  They are browser-session endpoints: they mint a
| session, publish a CSRF cookie and read `system_config`.  The `/api` prefix on
| some of their paths is a naming artifact of the Python application, where one
| process owned both the rendered pages and the JSON API.  Laravel's `api`
| middleware group has no session at all, so putting them there would mean
| bolting a session onto the group — which would then quietly apply to every
| future API route as well.  Grouping them here keeps the session boundary where
| the browser actually is.
|
*/

/*
 * Supervision contract, deliberately declared before the fallback.
 *
 * The Windows watchdog (scripts/watchdog_server.ps1) and the public monitor
 * both require `GET /health` to answer 200, and the running FastAPI
 * implementation returns `{"status":"ok"}` without touching the database.
 * These two routes reproduce that contract byte for byte.
 *
 * They MUST stay here rather than being left to the SPA fallback: the fallback
 * answers 200 with an HTML shell for any unknown path, which would report a
 * completely broken backend as healthy.  The Vue client uses the
 * envelope-carrying /api/health* endpoints instead.
 */
Route::get('/health', [HealthController::class, 'probe'])->name('health');
Route::get('/health/database', [HealthController::class, 'probeDatabase'])->name('health.database');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
|
| Ported from `app/api/routes/auth.py` (login) and `app/main.py` (logout, the
| public config).  Paths, request shapes, response bodies and status codes are
| the running application's, because the current front-end is still a client of
| these endpoints during the side-by-side period.
|
*/

/* The SPA shell.  Named `login` because Laravel's own redirect helpers look for
 * that name; the Vue router shows the login view for this path. */
Route::view('/login', 'app')->name('login');

Route::post('/login_user', [LoginController::class, 'login'])->name('login_user');

/* `GET` because that is what the existing logout links and the idle-timeout
 * redirect in the front-end JavaScript issue.  Changing it to `POST` would have
 * been the "correct" verb and would have broken every one of those links. */
Route::get('/logout', [LoginController::class, 'logout'])->name('logout');

/*
 * Password recovery.  Both are CSRF exempt for the same reason: the caller cannot
 * log in, so there is no session to bind a token to.  `LoginThrottle` is what
 * guards them instead, at the limits the Python endpoints used.
 */
Route::post('/forgot_password', [RecoveryController::class, 'forgotPassword'])->name('forgot_password');
Route::post('/reset_password', [RecoveryController::class, 'resetPassword'])->name('reset_password');

Route::get('/captcha', [CaptchaController::class, 'image'])->name('captcha');
Route::post('/captcha/refresh', [CaptchaController::class, 'refresh'])->name('captcha.refresh');
Route::get('/captcha/status', [CaptchaController::class, 'status'])->name('captcha.status');

Route::get('/api/csrf-token', [SessionController::class, 'csrfToken'])->name('api.csrf-token');
Route::get('/api/system-config', [SessionController::class, 'publicConfig'])->name('api.system-config');
Route::post('/api/session/destroy', [SessionController::class, 'destroy'])->name('api.session.destroy');

/*
 * Authenticated bootstrap.  `legacy.session` is the registry-backed
 * revocability check and must run after the session guard, which the middleware
 * ordering here guarantees because it is the only middleware on the route.
 */
Route::middleware(['legacy.session'])->group(function (): void {
    Route::get('/api/me', [SessionController::class, 'me'])->name('api.me');
});

/*
|--------------------------------------------------------------------------
| Master administration and control centre (reads)
|--------------------------------------------------------------------------
|
| Ported from `app/api/routes/master_admin.py`.  Paths, query parameters, response
| bodies and status codes are the running application's, because the existing admin
| UI is still a client of these endpoints during the side-by-side period.
|
| Middleware order is `master_admin` **then** `legacy.session:optional`, and the order
| is deliberate rather than incidental:
|
| * `EnsureMasterAdmin` answers `{"detail": "ورود لازم است."}` for an anonymous
|   request, which is what FastAPI rendered for the Python `HTTPException`.  Running
|   `legacy.session` first would answer a different 401 body for the same condition.
| * `legacy.session:optional` runs second so that a session an administrator has
|   terminated — or one that timed out server-side — is still rejected *after* the
|   guard has resolved it.  Without it, a revoked cookie would keep reading the
|   control plane until the browser dropped it, which is precisely the hole the
|   registry table exists to close.
|
| The `:optional` parameter is what makes the anonymous request reach the guard at
| all; see `ValidateLegacySession::OPTIONAL`.
|
*/
Route::prefix('master-admin/api')
    ->middleware(['master_admin', 'legacy.session:optional'])
    ->group(function (): void {
        Route::get('/dashboard/stats', [DashboardController::class, 'stats'])->name('master-admin.dashboard.stats');
        Route::get('/dashboard/activity', [DashboardController::class, 'activity'])->name('master-admin.dashboard.activity');

        Route::get('/users', [UserController::class, 'index'])->name('master-admin.users.index');
        Route::get('/users/{username}', [UserController::class, 'show'])->name('master-admin.users.show');

        Route::get('/audit-logs', [AuditLogController::class, 'index'])->name('master-admin.audit-logs.index');
        Route::get('/audit-logs/{event_id}', [AuditLogController::class, 'show'])->name('master-admin.audit-logs.show');

        Route::get('/sessions', [SessionListController::class, 'index'])->name('master-admin.sessions.index');
        Route::get('/system-health', [SystemHealthController::class, 'show'])->name('master-admin.system-health');
        Route::get('/search', [SearchController::class, 'search'])->name('master-admin.search');
    });

/*
|--------------------------------------------------------------------------
| User panel (reads)
|--------------------------------------------------------------------------
|
| Ported from `app/main.py`.  These are the handler-level routes: unlike the
| `master_admin` module they live at the application root (`/get_users`, not
| `/api/users`), because that is what the running front-end calls.
|
| Each route's middleware mirrors the guard the Python handler performed, and no
| more — see the docblocks in `App\Http\Controllers\UserPanel` for why
| `/get_user_info` has no guard at all and `/get_user_info_report` guards itself.
|
| `legacy.session:optional` is appended to every route that reads user data, so a
| revoked session is refused there too.  The legacy application enforced the registry
| app-wide; reproducing that per-route is what the parameter is for.
|
*/

/* `_ticket_actor` — 403 `{"success": false, "error": "دسترسی غیرمجاز"}` when there is
 * no session identity, even for a fully anonymous request. */
Route::middleware(['ticket.actor', 'legacy.session:optional'])->group(function (): void {
    Route::get('/get_users', [PickerController::class, 'users'])->name('get_users');
    Route::get('/get_receivers', [PickerController::class, 'receivers'])->name('get_receivers');
});

/* `_require_admin` — the `admin` middleware answers the same two bodies
 * (`{"success": false, "error": …}`), so no in-handler check is needed. */
Route::middleware(['admin', 'legacy.session:optional'])->group(function (): void {
    Route::get('/get_active_shifts', [ShiftController::class, 'active'])->name('get_active_shifts');
    Route::get('/get_leave_requests', [LeaveController::class, 'requests'])->name('get_leave_requests');
});

/* Session-scoped, unguarded or self-guarded in the handler. */
Route::middleware(['legacy.session:optional'])->group(function (): void {
    Route::get('/get_user_info', [ProfileController::class, 'info'])->name('get_user_info');
    Route::get('/get_user_info_report', [ProfileController::class, 'report'])->name('get_user_info_report');
    Route::get('/get_leave_info', [LeaveController::class, 'info'])->name('get_leave_info');
    Route::get('/get_user_hourly_pass_requests', [HourlyPassController::class, 'userRequests'])->name('get_user_hourly_pass_requests');
    Route::get('/get_user_overtime_requests', [OvertimeController::class, 'userRequests'])->name('get_user_overtime_requests');
});

/* Public: the login page renders today's Jalali date from this, before anyone has a
 * session.  It answers `{"year": …, "month": …, "day": …}` with no `success` key. */
Route::get('/get_today_date', [CalendarController::class, 'today'])->name('get_today_date');

/*
|--------------------------------------------------------------------------
| Call system (سامانه فراخوان)
|--------------------------------------------------------------------------
|
| Ported from `app/api/routes/call_system.py`.  The `/api` prefix is not Laravel's
| `api` middleware group — these live in the **web** group, because the whole
| application is browser-session based and the legacy `/api` prefix is a naming
| artifact of the single-process Python application.
|
| Three things about this block are easy to get wrong:
|
| * **The kiosk writes are CSRF-exempt and guarded instead.**  `/api/calls` and
|   `/api/slides` are in `config('hastama.csrf.exempt_prefixes')`, and
|   `VerifyLegacyCsrf` enforces the same-site `Origin` check on an exempt write, so
|   the boundary is the origin plus `kiosk.write:<bucket>` — which is exactly the two
|   controls the Python middleware and handler applied together.
| * **The bucket names are the Python's.**  `kiosk.write:calls-create`,
|   `calls-waiting-del`, `reset-display`… the limiter counts `bucket:ip`, so renaming
|   one silently merges two budgets and lets a client flood the displays by
|   alternating between the buttons that share a name.
| * **The reads have no guard at all.**  The TV displays and the kiosk fetch
|   `/audio-status`, `/display-queue`, the waiting queue and the slides with no
|   session; that is the running behaviour and it is what the displays rely on.
*/
Route::prefix('api')->group(function (): void {
    Route::post('/calls', [CallController::class, 'store'])
        ->middleware('kiosk.write:calls-create')->name('api.calls.store');
    Route::post('/calls/repeat', [CallController::class, 'repeat'])
        ->middleware('kiosk.write:calls-repeat')->name('api.calls.repeat');

    // The three test buttons share one bucket (`calls-test`), as in the Python: they are
    // the same operator pressing the same panel, and separate budgets would let a held
    // button spend three times the allowance on the wall screens.
    Route::post('/calls/test-display', [CallController::class, 'testDisplay'])
        ->middleware('kiosk.write:calls-test')->name('api.calls.test-display');
    Route::post('/calls/test-voice', [CallController::class, 'testVoice'])
        ->middleware('kiosk.write:calls-test')->name('api.calls.test-voice');
    Route::post('/calls/test-audio', [CallController::class, 'testAudio'])
        ->middleware('kiosk.write:calls-test')->name('api.calls.test-audio');

    Route::get('/calls/audio-status', [CallController::class, 'audioStatus'])
        ->name('api.calls.audio-status');
    Route::get('/calls/display-queue', [CallController::class, 'displayQueue'])
        ->name('api.calls.display-queue');
    Route::get('/calls/status', [CallController::class, 'status'])->name('api.calls.status');

    Route::post('/calls/reset-display', [CallController::class, 'resetDisplay'])
        ->middleware('kiosk.write:reset-display')->name('api.calls.reset-display');
    Route::post('/calls/refresh-display', [CallController::class, 'refreshDisplay'])
        ->middleware('kiosk.write:refresh-display')->name('api.calls.refresh-display');
    Route::post('/calls/remove', [CallController::class, 'removeCall'])
        ->middleware('kiosk.write:calls-remove')->name('api.calls.remove');

    Route::get('/calls/recent', [CallController::class, 'recent'])->name('api.calls.recent');
    Route::delete('/calls/recent', [CallController::class, 'clearRecent'])
        ->middleware('kiosk.write:calls-recent-clear')->name('api.calls.recent.clear');

    Route::get('/calls/waiting-queue', [CallController::class, 'waitingQueue'])
        ->name('api.calls.waiting-queue');
    Route::post('/calls/waiting-queue', [CallController::class, 'addToWaitingQueue'])
        ->middleware('kiosk.write:calls-waiting-add')->name('api.calls.waiting-queue.store');
    Route::delete('/calls/waiting-queue/{itemId}', [CallController::class, 'removeFromWaitingQueue'])
        ->middleware('kiosk.write:calls-waiting-del')->name('api.calls.waiting-queue.destroy');
    Route::post('/calls/waiting-queue/{itemId}/call', [CallController::class, 'callFromWaitingQueue'])
        ->middleware('kiosk.write:calls-waiting-call')->name('api.calls.waiting-queue.call');

    Route::get('/calls/slides', [SlideController::class, 'index'])->name('api.calls.slides');
    Route::get('/calls/slides/active', [SlideController::class, 'active'])->name('api.calls.slides.active');

    // The slide writes guard themselves in the handler, in the Python's order — and the
    // order differs between them; see `SlideController`.
    Route::post('/calls/slides/upload', [SlideController::class, 'upload'])->name('api.calls.slides.upload');
    Route::put('/calls/slides/{slideId}/toggle', [SlideController::class, 'toggle'])->name('api.calls.slides.toggle');
    Route::delete('/calls/slides/{slideId}', [SlideController::class, 'destroy'])->name('api.calls.slides.destroy');

    /*
    |--------------------------------------------------------------------------
    | Queue (نوبت‌دهی)
    |--------------------------------------------------------------------------
    |
    | Only two of these carry route middleware, and that is the Python's shape:
    |
    | * `queue-take` is `kiosk.write`, the same guard every kiosk write has, and
    |   the last bucket the limiter keys on — `queue-take`, `queue-print` and
    |   `calls-create` are three separate budgets for three separate buttons.
    | * The rest guard **themselves in the handler**, in the order FastAPI ran
    |   them: validate the path, then validate the body, then the origin/actor/
    |   PII check.  Putting those in middleware would answer `403` to a request
    |   the running server answers `422`, because middleware cannot see a body it
    |   has not dispatched to.  `QueuePii` documents the one case where that is
    |   observable with a plain `curl`.
    |
    | `POST /queue/call/{ticketId}` and friends are **not** on
    | `config('hastama.csrf.exempt_prefixes')` — only `/api/queue/take`,
    | `/api/queue/print` and `/api/queue/ticket` are — so `VerifyLegacyCsrf`
    | answers a tokenless write before any of this runs, on both servers.
    */
    Route::post('/queue/take', [QueueController::class, 'take'])
        ->middleware('kiosk.write:queue-take')->name('api.queue.take');

    Route::get('/queue/list', [QueueController::class, 'index'])->name('api.queue.list');
    Route::get('/queue/stats', [QueueController::class, 'stats'])->name('api.queue.stats');

    // Registered ahead of `/queue/{ticketId}`: `call-next` and `complete` are literals,
    // and a literal must win over the segment that would otherwise swallow it.
    Route::post('/queue/call-next', [QueueController::class, 'callNext'])->name('api.queue.call-next');
    Route::post('/queue/call/{ticketId}', [QueueController::class, 'callTicket'])->name('api.queue.call');
    Route::post('/queue/complete/{ticketId}', [QueueController::class, 'complete'])->name('api.queue.complete');

    Route::delete('/queue', [QueueController::class, 'destroyAll'])->name('api.queue.destroy-all');
    Route::delete('/queue/{ticketId}', [QueueController::class, 'destroy'])->name('api.queue.destroy');

    Route::get('/queue/ticket/{ticketNumber}', [QueueController::class, 'showTicket'])->name('api.queue.ticket.show');
    Route::put('/queue/ticket/{ticketNumber}', [QueueController::class, 'editTicket'])->name('api.queue.ticket.edit');
});

/*
 * `/admin`, `/admin/dashboard` and `/admin/{section}` are NOT declared here:
 * they are ported page routes in `routes/public-pages.php`
 * (`PublicPages\AdminController`), which reproduce the Python guard — an
 * anonymous caller or a signed-in non-admin is answered `303 /login`, an admin
 * is answered `303 /admin/dashboard` for `/admin` and the SPA shell for the
 * section paths.  Declaring a second `/admin` here would shadow nothing (that
 * file is loaded first) while making the route table claim two handlers for
 * one path.
 */

Route::fallback(function (Request $request) {
    if ($request->is('api/*')) {
        return ApiResponse::error('نقطه پایانی مورد نظر یافت نشد.', 404);
    }

    return response()->view('app');
})->name('spa');
