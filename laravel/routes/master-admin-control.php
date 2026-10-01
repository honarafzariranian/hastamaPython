<?php

use App\Http\Controllers\MasterAdmin\AdminActionController;
use App\Http\Controllers\MasterAdmin\AuditLogWriteController;
use App\Http\Controllers\MasterAdmin\PasswordResetController;
use App\Http\Controllers\MasterAdmin\SecurityEventController;
use App\Http\Controllers\MasterAdmin\SessionActionController;
use App\Http\Controllers\MasterAdmin\SubscriptionController;
use App\Http\Controllers\MasterAdmin\SystemErrorController;
use App\Http\Controllers\MasterAdmin\UserActionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Master-admin control centre - writes (subscriptions, users, sessions, password resets, security, errors, admin actions). Ported from app/api/routes/master_admin.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes/web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
*/

/*
|--------------------------------------------------------------------------
| Master administration and control centre (writes)
|--------------------------------------------------------------------------
|
| The write half of `app/api/routes/master_admin.py`, ported with the same
| contract as the read half in `routes/web.php`: same prefix, same middleware
| order (`master_admin` then `legacy.session:optional`), same response
| bodies, because the existing admin UI is still a client of these endpoints
| during the side-by-side period.
|
| Route names are prefixed `ma-control.` so they can never collide with the
| `master-admin.*` names the read half already owns.
|
| Literal segments are registered before the parameterised ones that would
| otherwise swallow them: `/subscriptions/summary` must precede
| `/subscriptions/{subscription_id}`, or `summary` is read as an id.
|
*/
Route::prefix('master-admin/api')
    ->middleware(['master_admin', 'legacy.session:optional'])
    ->group(function (): void {
        Route::get('/subscriptions/summary', [SubscriptionController::class, 'summary'])->name('ma-control.subscriptions.summary');
        Route::get('/subscriptions', [SubscriptionController::class, 'index'])->name('ma-control.subscriptions.index');
        Route::get('/subscriptions/{subscription_id}', [SubscriptionController::class, 'show'])->name('ma-control.subscriptions.show');
        Route::patch('/subscriptions/{subscription_id}', [SubscriptionController::class, 'update'])->name('ma-control.subscriptions.update');

        Route::delete('/audit-logs/{event_id}', [AuditLogWriteController::class, 'destroy'])->name('ma-control.audit-logs.destroy');

        Route::post('/users/{username}/toggle-status', [UserActionController::class, 'toggleStatus'])->name('ma-control.users.toggle-status');
        Route::post('/users/{username}/change-role', [UserActionController::class, 'changeRole'])->name('ma-control.users.change-role');

        Route::post('/sessions/terminate-all', [SessionActionController::class, 'terminateAll'])->name('ma-control.sessions.terminate-all');
        Route::post('/sessions/{session_key}/terminate', [SessionActionController::class, 'terminate'])->name('ma-control.sessions.terminate');
        Route::delete('/sessions', [SessionActionController::class, 'destroyAll'])->name('ma-control.sessions.destroy-all');
        Route::delete('/sessions/{session_key}', [SessionActionController::class, 'destroy'])->name('ma-control.sessions.destroy');

        Route::get('/password-resets', [PasswordResetController::class, 'index'])->name('ma-control.password-resets.index');
        Route::post('/password-resets/{request_id}/approve', [PasswordResetController::class, 'approve'])->name('ma-control.password-resets.approve');
        Route::post('/password-resets/{request_id}/reject', [PasswordResetController::class, 'reject'])->name('ma-control.password-resets.reject');
        Route::delete('/password-resets/{request_id}', [PasswordResetController::class, 'destroy'])->name('ma-control.password-resets.destroy');

        Route::get('/security', [SecurityEventController::class, 'index'])->name('ma-control.security.index');
        Route::post('/security/{event_id}/resolve', [SecurityEventController::class, 'resolve'])->name('ma-control.security.resolve');
        Route::delete('/security/{event_id}', [SecurityEventController::class, 'destroy'])->name('ma-control.security.destroy');

        Route::get('/errors', [SystemErrorController::class, 'index'])->name('ma-control.errors.index');
        Route::post('/errors/{error_id}/resolve', [SystemErrorController::class, 'resolve'])->name('ma-control.errors.resolve');
        Route::delete('/errors/{error_id}', [SystemErrorController::class, 'destroy'])->name('ma-control.errors.destroy');

        Route::get('/admin-actions', [AdminActionController::class, 'index'])->name('ma-control.admin-actions.index');
        Route::delete('/admin-actions/{action_id}', [AdminActionController::class, 'destroy'])->name('ma-control.admin-actions.destroy');
    });
