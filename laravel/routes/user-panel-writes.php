<?php

use App\Http\Controllers\UserPanel\AttendanceController;
use App\Http\Controllers\UserPanel\EmploymentStatusController;
use App\Http\Controllers\UserPanel\FinalReportController;
use App\Http\Controllers\UserPanel\LeaveWriteController;
use App\Http\Controllers\UserPanel\ProfileImageController;
use App\Http\Controllers\UserPanel\ShiftAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| User-panel writes - leave, overtime, hourly pass, tickets, profile images, attendance. Ported from app/main.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes/web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
|
| ### Middleware
|
| The middleware mirrors what each Python handler enforced — and no more:
|
| * **`legacy.session:optional`** — the app-wide session middleware the
|   Python passed anonymous requests through to the handler's own guard.  The
|   handlers that guarded on the session username (`submit_leave`,
|   `submit_overtime`, `submit_hourly_pass`, the profile-image writes,
|   `sabt_hozoor_checkin` / `sabt_hozoor_checkout` / `get_hozoor_today`) carry
|   only this, and their own guards answer the anonymous request.
| * **`admin` + `legacy.session:optional`** — the handlers guarded by
|   `_require_admin` (`get_hozoor`, `get_hozoor_filtered`, `sabt_hozoor`,
|   `get_shifts`, `add_shift`, `update_shift`, `delete_shift`,
|   `get_user_info_final_report_page`).
|
| ### Two deliberate deviations, both recorded
|
| * **`POST /api/admin/employment-status`** is guarded by
|   `get_is_admin_from_session()` in the Python — `is_admin is True` only, with
|   a 403 body of `دسترسی مجاز نیست.` — which is **not** the `_require_admin`
|   guard the `admin` middleware reproduces (that one also checks the username
|   and answers `دسترسی مدیریتی ندارید.`).  The guard is therefore kept in the
|   handler and the route carries only `legacy.session:optional`; see
|   `EmploymentStatusController`.
| * **`POST /delete_shift/{shift_id}`** is registered as `POST`, not `DELETE`:
|   the Python is `@app.post` (`app/main.py:3640`) and the front-end calls it
|   with `method: 'POST'` (`admin.js:5727`).  The task's route list said
|   `DELETE`; the Python is the specification.
|
| Route names are prefixed `upw.` because names are globally unique.
*/

/*
|--------------------------------------------------------------------------
| Authenticated writes — the signed-in user's own requests
|--------------------------------------------------------------------------
*/

Route::middleware(['legacy.session:optional'])->group(function (): void {

    Route::post('/submit_leave', [LeaveWriteController::class, 'submitLeave'])
        ->name('upw.submit-leave');

    Route::post('/submit_overtime', [LeaveWriteController::class, 'submitOvertime'])
        ->name('upw.submit-overtime');

    Route::post('/submit_hourly_pass', [LeaveWriteController::class, 'submitHourlyPass'])
        ->name('upw.submit-hourly-pass');

    Route::post('/upload-profile-image', [ProfileImageController::class, 'upload'])
        ->name('upw.upload-profile-image');

    Route::post('/delete-profile-image', [ProfileImageController::class, 'delete'])
        ->name('upw.delete-profile-image');

    // `_attendance_actor` — any signed-in user, with the ownership check
    // (a non-admin may only act on themselves) kept in the handler.
    Route::post('/sabt_hozoor_checkin', [AttendanceController::class, 'checkin'])
        ->name('upw.sabt-hozoor-checkin');

    Route::post('/sabt_hozoor_checkout', [AttendanceController::class, 'checkout'])
        ->name('upw.sabt-hozoor-checkout');

    Route::get('/get_hozoor_today', [AttendanceController::class, 'today'])
        ->name('upw.get-hozoor-today');
});

/*
|--------------------------------------------------------------------------
| Administrator writes — `_require_admin`
|--------------------------------------------------------------------------
*/

Route::middleware(['admin', 'legacy.session:optional'])->group(function (): void {

    Route::get('/get_user_info_final_report_page/{username}', [FinalReportController::class, 'show'])
        ->name('upw.get-user-info-final-report-page');

    Route::post('/get_hozoor_filtered', [AttendanceController::class, 'filtered'])
        ->name('upw.get-hozoor-filtered');

    Route::get('/get_shifts/{username}/{year}/{month}', [ShiftAdminController::class, 'show'])
        ->name('upw.get-shifts');

    Route::post('/add_shift', [ShiftAdminController::class, 'store'])
        ->name('upw.add-shift');

    Route::post('/update_shift', [ShiftAdminController::class, 'update'])
        ->name('upw.update-shift');

    // `@app.post("/delete_shift/{shift_id}")` — see the header note.
    Route::post('/delete_shift/{shift_id}', [ShiftAdminController::class, 'destroy'])
        ->name('upw.delete-shift');

    Route::get('/get_hozoor/{username}', [AttendanceController::class, 'show'])
        ->name('upw.get-hozoor');

    Route::post('/sabt_hozoor', [AttendanceController::class, 'store'])
        ->name('upw.sabt-hozoor');
});

/*
|--------------------------------------------------------------------------
| Employment status — the non-standard admin guard, kept in the handler
|--------------------------------------------------------------------------
*/

Route::middleware(['legacy.session:optional'])->group(function (): void {

    Route::post('/api/admin/employment-status', [EmploymentStatusController::class, 'update'])
        ->name('upw.employment-status');
});
