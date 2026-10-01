<?php

use App\Http\Controllers\Registration\AdminRegistrationController;
use App\Http\Controllers\Registration\PublicRegistrationController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Self-registration with admin approval. Ported from app/api/routes/registration.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes.web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
|
| The public half is unauthenticated; the admin workflow carries the standard
| `admin` guard followed by `legacy.session:optional`, the same order the
| control-centre routes use.  Literal segments are registered before the
| parameterised ones that would otherwise swallow them.
*/

Route::prefix('registration')->group(function (): void {
    Route::get('/check-username', [PublicRegistrationController::class, 'checkUsername'])
        ->name('registration.check-username');
    Route::get('/check-national-id', [PublicRegistrationController::class, 'checkNationalId'])
        ->name('registration.check-national-id');
    Route::post('/submit', [PublicRegistrationController::class, 'submit'])
        ->name('registration.submit');
    Route::get('/status/{request_id}', [PublicRegistrationController::class, 'status'])
        ->name('registration.status');
    Route::get('/departments', [PublicRegistrationController::class, 'departments'])
        ->name('registration.departments');
    Route::get('/work-schedules', [PublicRegistrationController::class, 'workSchedules'])
        ->name('registration.work-schedules');
    Route::get('/active-users', [PublicRegistrationController::class, 'activeUsers'])
        ->name('registration.active-users');

    Route::middleware(['admin', 'legacy.session:optional'])->group(function (): void {
        Route::get('/admin/requests', [AdminRegistrationController::class, 'index'])
            ->name('registration.admin.requests.index');
        Route::get('/admin/requests/{request_id}', [AdminRegistrationController::class, 'show'])
            ->name('registration.admin.requests.show');
        Route::post('/admin/requests/{request_id}/approve', [AdminRegistrationController::class, 'approve'])
            ->name('registration.admin.requests.approve');
        Route::post('/admin/requests/{request_id}/reject', [AdminRegistrationController::class, 'reject'])
            ->name('registration.admin.requests.reject');
    });
});
