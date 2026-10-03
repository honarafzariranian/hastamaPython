<?php

use App\Http\Controllers\PublicPages\AdminController;
use App\Http\Controllers\PublicPages\CallPageController;
use App\Http\Controllers\PublicPages\DateController;
use App\Http\Controllers\PublicPages\IranOnlyController;
use App\Http\Controllers\PublicPages\MasterAdminController;
use App\Http\Controllers\PublicPages\OfflineController;
use App\Http\Controllers\PublicPages\ShellController;
use App\Http\Controllers\PublicPages\StaticAssetsController;
use App\Http\Controllers\PublicPages\TrainingController;
use App\Http\Controllers\PublicPages\UserPanelController;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\RedirectResponse;

/*
|--------------------------------------------------------------------------
| Public content pages, shells, robots/sitemap and static-ish endpoints. Ported from app/main.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes/web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
|
| Two shapes live here, and the difference is the whole point:
|
| * **Machine / static endpoints** answer bytes — the favicon, the service
|   worker, robots.txt, sitemap.xml, the legacy `/static/...` tree, the two
|   rendered guide documents and the two JSON probes.  Their content types,
|   bodies and headers are the Python's, byte for byte.
| * **Page routes** are mostly the Vue SPA.  Laravel serves the one mount
|   document (`resources/views/app.blade.php`) and Vue Router renders the page,
|   exactly as `routes/web.php` does for `/login`; the exception is the
|   call-display/call-management pair, which now stream the original Python
|   HTML so the browser sees the exact legacy shell while still talking to the
|   Laravel APIs.
|
| **The render-time redirects are the contract.**  The Python checked the
| session inside the handler and answered 303 before rendering, and a browser
| following a link expects the redirect, not a JSON body — so those checks
| run in the handler, in the Python's order, rather than through the
| `admin` / `master_admin` middleware (whose `{"detail": …}` 401/403 bodies are
| the contract for the JSON API routes, not for a page navigation).
| `legacy.session:optional` is applied to every guarded route to reproduce
| the app-wide session-registry revocation.
|
*/

/*
|--------------------------------------------------------------------------
| Machine and static endpoints (no session, no guard)
|--------------------------------------------------------------------------
*/

Route::get('/favicon.ico', [StaticAssetsController::class, 'favicon'])->name('public.favicon');

Route::get('/sw.js', [StaticAssetsController::class, 'serviceWorker'])->name('public.sw.js');

Route::get('/robots.txt', [StaticAssetsController::class, 'robotsTxt'])->name('public.robots');

Route::get('/sitemap.xml', [StaticAssetsController::class, 'sitemapXml'])->name('public.sitemap');

Route::get('/static/{path}', [StaticAssetsController::class, 'legacyStatic'])
    ->where('path', '.*')
    ->name('public.static');

Route::get('/offline', [OfflineController::class, 'offline'])->name('public.offline');

Route::get('/iran-only', [IranOnlyController::class, 'page'])->name('public.iran-only');

Route::get('/iran-only/check', [IranOnlyController::class, 'check'])->name('public.iran-only.check');

/* The Jalali date the login page renders before anyone has a session. */
Route::get('/api/date', [DateController::class, 'today'])->name('public.api.date');

/* The training search — the one training endpoint that is a machine contract. */
Route::get('/api/training/search', [TrainingController::class, 'search'])->name('public.api.training.search');

/*
|--------------------------------------------------------------------------
| The landing page
|--------------------------------------------------------------------------
|
| The root is no longer a marketing page: it is a 301 to `/login`, where the
| private application portal begins.
*/
Route::get('/', fn (): RedirectResponse => redirect('/login', 301))
    ->name('public.landing');

/*
|--------------------------------------------------------------------------
| Public page shells (no guard in the Python handler)
|--------------------------------------------------------------------------
*/
Route::get('/register', [ShellController::class, 'register'])->name('public.register');
Route::get('/rules', [ShellController::class, 'rules'])->name('public.rules');
Route::get('/ticket-kiosk', [ShellController::class, 'ticketKiosk'])->name('public.ticket-kiosk');
Route::get('/ticket-print', [ShellController::class, 'ticketPrint'])->name('public.ticket-print');

Route::get('/training', [TrainingController::class, 'hub'])->name('public.training');
Route::get('/training/{category}', [TrainingController::class, 'category'])->name('public.training.category');
Route::get('/training/lesson/{lesson_id}', [TrainingController::class, 'lesson'])->name('public.training.lesson');

/*
|--------------------------------------------------------------------------
| Guarded page shells
|--------------------------------------------------------------------------
|
| Each group carries `legacy.session:optional` — the registry-backed
| revocation check the Python applied app-wide — and reproduces the
| handler-level guard in the controller, in the Python's order.
|
| Literal segments are registered before the segments that would swallow them:
| `/admin/dashboard` must win over `/admin/{section}`.
|
*/

/* `/master-admin` redirects unconditionally; the guard lives on `/{section}`. */
Route::middleware(['legacy.session:optional'])->group(function (): void {
    Route::get('/master-admin', [MasterAdminController::class, 'root'])->name('public.master-admin');
    Route::get('/master-admin/{section}', [MasterAdminController::class, 'section'])
        ->name('public.master-admin.section');
});

/* The call pages: a master-admin session entered from the dashboard (or self). */
Route::middleware(['legacy.session:optional'])->group(function (): void {
    Route::get('/call-display', [CallPageController::class, 'display'])->name('public.call-display');
    Route::get('/call-management', [CallPageController::class, 'management'])->name('public.call-management');
});

/* The user panel: any signed-in user. */
Route::middleware(['legacy.session:optional'])->group(function (): void {
    Route::get('/user_panel', [UserPanelController::class, 'panel'])->name('public.user-panel');
});

/* The admin panel: `is_admin` (a master admin does not imply it here). */
Route::middleware(['legacy.session:optional'])->group(function (): void {
    Route::get('/admin', [AdminController::class, 'admin'])->name('public.admin');
    Route::get('/admin/dashboard', [AdminController::class, 'dashboard'])->name('public.admin.dashboard');
    Route::get('/admin/{section}', [AdminController::class, 'section'])->name('public.admin.section');
});
