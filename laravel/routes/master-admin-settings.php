<?php

use App\Http\Controllers\MasterAdmin\IranAccessController;
use App\Http\Controllers\MasterAdmin\LanAccessController;
use App\Http\Controllers\MasterAdmin\LoginExperienceController;
use App\Http\Controllers\MasterAdmin\OutageController;
use App\Http\Controllers\MasterAdmin\PrinterController;
use App\Http\Controllers\MasterAdmin\SystemConfigController;
use App\Http\Controllers\MasterAdmin\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Master-admin control centre - settings surfaces (tickets, config, LAN access, outage, Iran-only, login experience, printers). Ported from app/api/routes/master_admin.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes/web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
*/

/*
|--------------------------------------------------------------------------
| Master administration and control centre (settings)
|--------------------------------------------------------------------------
|
| The settings half of `app/api/routes/master_admin.py`, ported with the same
| contract as the read half in `routes/web.php`: same prefix, same middleware
| order (`master_admin` then `legacy.session:optional`), same response
| bodies, because the existing admin UI is still a client of these endpoints
| during the side-by-side period.
|
| Route names are prefixed `ma-settings.` so they can never collide with the
| `master-admin.*` names the read half already owns or the `ma-control.*`
| names the write half owns.
|
| Literal segments are registered before the parameterised ones that would
| otherwise swallow them: `/tickets/stats`, `/tickets/categories/all` and
| `/tickets/users/all` must precede `/tickets/{ticket_id}`, or `stats` is
| read as an id.
|
*/
Route::prefix('master-admin/api')
    ->middleware(['master_admin', 'legacy.session:optional'])
    ->group(function (): void {
        // Tickets — literals before the parameterised routes
        Route::get('/tickets/stats', [TicketController::class, 'stats'])->name('ma-settings.tickets.stats');
        Route::get('/tickets/categories/all', [TicketController::class, 'categories'])->name('ma-settings.tickets.categories');
        Route::get('/tickets/users/all', [TicketController::class, 'users'])->name('ma-settings.tickets.users');
        Route::get('/tickets', [TicketController::class, 'index'])->name('ma-settings.tickets.index');
        Route::get('/tickets/{ticket_id}', [TicketController::class, 'show'])->name('ma-settings.tickets.show');
        Route::patch('/tickets/{ticket_id}', [TicketController::class, 'update'])->name('ma-settings.tickets.update');
        Route::post('/tickets/{ticket_id}/reply', [TicketController::class, 'reply'])->name('ma-settings.tickets.reply');
        Route::delete('/tickets/{ticket_id}', [TicketController::class, 'destroy'])->name('ma-settings.tickets.destroy');

        // System config
        Route::get('/config', [SystemConfigController::class, 'index'])->name('ma-settings.config.index');
        Route::post('/config', [SystemConfigController::class, 'store'])->name('ma-settings.config.store');

        // LAN access
        Route::get('/lan-access', [LanAccessController::class, 'index'])->name('ma-settings.lan-access.index');
        Route::post('/lan-access', [LanAccessController::class, 'store'])->name('ma-settings.lan-access.store');
        Route::post('/lan-access/selftest', [LanAccessController::class, 'selftest'])->name('ma-settings.lan-access.selftest');

        // Internet outage
        Route::get('/outage', [OutageController::class, 'index'])->name('ma-settings.outage.index');
        Route::post('/outage', [OutageController::class, 'store'])->name('ma-settings.outage.store');
        Route::post('/outage/check', [OutageController::class, 'check'])->name('ma-settings.outage.check');
        Route::post('/outage/manual', [OutageController::class, 'manual'])->name('ma-settings.outage.manual');

        // Iran-only access
        Route::get('/iran-access', [IranAccessController::class, 'index'])->name('ma-settings.iran-access.index');
        Route::post('/iran-access', [IranAccessController::class, 'store'])->name('ma-settings.iran-access.store');
        Route::post('/iran-access/check', [IranAccessController::class, 'check'])->name('ma-settings.iran-access.check');
        Route::post('/iran-access/refresh', [IranAccessController::class, 'refresh'])->name('ma-settings.iran-access.refresh');
        Route::post('/iran-access/counters/reset', [IranAccessController::class, 'resetCounters'])->name('ma-settings.iran-access.counters-reset');

        // Login experience
        Route::get('/login-experience', [LoginExperienceController::class, 'index'])->name('ma-settings.login-experience.index');
        Route::post('/login-experience', [LoginExperienceController::class, 'store'])->name('ma-settings.login-experience.store');

        // Printers
        Route::get('/printers', [PrinterController::class, 'index'])->name('ma-settings.printers.index');
    });
