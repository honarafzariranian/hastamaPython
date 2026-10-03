<?php

use App\Http\Controllers\Admin\DashboardStatsController;
use App\Http\Controllers\Admin\HourlyPassController;
use App\Http\Controllers\Admin\LeaveAdminController;
use App\Http\Controllers\Admin\OvertimeController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\ReportPageController;
use App\Http\Controllers\Admin\UserAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin panel - approvals, reports, user and shift management, payroll. Ported from app/main.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes/web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
|
| ### Middleware
|
| The middleware mirrors what each Python handler enforced — and no more.
| FastAPI validates a handler's declared path / query / form / body
| parameters **before** the handler runs, so for every route where that
| validation precedes the handler's own guard the `admin` middleware cannot be
| used: it would answer 401/403 where the running server answers 422.  Those
| routes carry only `legacy.session:optional` and the handler reproduces the
| validation first and the guard second, in that order:
|
| * `POST /add_user` — fifteen `Form(...)` fields are validated (a missing
|   **or empty** one is a 422 `missing`) before `_require_admin`.
| * `GET /fetch_user_data` — `username: str = Query(...)` is validated before
|   `_require_admin`.
| * `POST /api/admin/payroll/save`, `GET /api/admin/payroll/load` — the guard
|   is `get_is_admin_from_session()` (`is_admin is True` only, 403
|   `دسترسی مجاز نیست.`), which is **not** the `_require_admin` guard the
|   `admin` middleware reproduces, so the guard stays in the handler;
|   `payroll/load` also validates its three query parameters first.
| * `POST /update_overtime_status`, `POST /update_overtime_Indivisual_status` —
|   a pydantic model (`requestId: int, status: str` / `id: int, status: str`)
|   is validated before `_require_admin`.
| * `POST /get_overtime_report` — `data: dict` is validated (a JSON array is a
|   422 `dict_type`) before `_require_admin`.
|
| The remaining administrator routes guard with `_require_admin` as their first
| action and have no pre-handler validation, so they carry
| `['admin', 'legacy.session:optional']`.  The report pages and `download_pdf`
| guard with `_require_auth` and carry only `legacy.session:optional`.
|
| Route names are prefixed `admin-panel.` because names are globally unique.
*/

/*
|--------------------------------------------------------------------------
| Administrator routes — `_require_admin` is the handler's first action
|--------------------------------------------------------------------------
*/

Route::middleware(['admin', 'legacy.session:optional'])->group(function (): void {

    Route::get('/admin/coworkers/users', [UserAdminController::class, 'index'])
        ->name('admin-panel.coworker-users');

    /*
     * The dashboard's figures.  The Python page built them as template context
     * (there was no URL), so this path is the port's own — it is registered
     * here rather than beside `/admin/dashboard` because `routes/public-pages.php`
     * owns the page shells and this answers a `fetch()`, not a document.
     */
    Route::get('/admin/dashboard/stats', [DashboardStatsController::class, 'stats'])
        ->name('admin-panel.dashboard-stats');

    Route::post('/update_user', [UserAdminController::class, 'update'])
        ->name('admin-panel.update-user');

    Route::post('/update_leave_status', [LeaveAdminController::class, 'updateStatus'])
        ->name('admin-panel.update-leave-status');

    Route::get('/get_hourly_pass_requests', [HourlyPassController::class, 'requests'])
        ->name('admin-panel.get-hourly-pass-requests');

    Route::post('/change_hourly_pass_status', [HourlyPassController::class, 'changeStatus'])
        ->name('admin-panel.change-hourly-pass-status');

    Route::post('/get_hourly_pass_report', [HourlyPassController::class, 'report'])
        ->name('admin-panel.get-hourly-pass-report');

    Route::post('/update_hourly_pass_status', [HourlyPassController::class, 'updateStatus'])
        ->name('admin-panel.update-hourly-pass-status');

    Route::get('/get_overtime_requests', [OvertimeController::class, 'requests'])
        ->name('admin-panel.get-overtime-requests');

    Route::get('/overtime_report', [OvertimeController::class, 'report'])
        ->name('admin-panel.overtime-report');

    Route::post('/generate_individual_report', [LeaveAdminController::class, 'generateIndividual'])
        ->name('admin-panel.generate-individual-report');
});

/*
|--------------------------------------------------------------------------
| Validation-then-guard routes — FastAPI validates before the handler
|--------------------------------------------------------------------------
*/

Route::middleware(['legacy.session:optional'])->group(function (): void {

    Route::post('/api/admin/payroll/save', [PayrollController::class, 'save'])
        ->name('admin-panel.payroll-save');

    Route::get('/api/admin/payroll/load', [PayrollController::class, 'load'])
        ->name('admin-panel.payroll-load');

    Route::post('/add_user', [UserAdminController::class, 'add'])
        ->name('admin-panel.add-user');

    Route::get('/fetch_user_data', [UserAdminController::class, 'fetch'])
        ->name('admin-panel.fetch-user-data');

    Route::post('/update_overtime_status', [OvertimeController::class, 'updateStatus'])
        ->name('admin-panel.update-overtime-status');

    Route::post('/update_overtime_Indivisual_status', [OvertimeController::class, 'updateIndividualStatus'])
        ->name('admin-panel.update-overtime-individual-status');

    Route::post('/get_overtime_report', [OvertimeController::class, 'reportData'])
        ->name('admin-panel.get-overtime-report');
});

/*
|--------------------------------------------------------------------------
| Report pages and the PDF download — `_require_auth`
|--------------------------------------------------------------------------
*/

Route::middleware(['legacy.session:optional'])->group(function (): void {

    Route::get('/leave_report_page', [ReportPageController::class, 'leave'])
        ->name('admin-panel.leave-report-page');

    Route::get('/hourlypass_Report_page', [ReportPageController::class, 'hourlyPass'])
        ->name('admin-panel.hourlypass-report-page');

    Route::get('/overtime_report_page', [ReportPageController::class, 'overtime'])
        ->name('admin-panel.overtime-report-page');

    Route::get('/payroll_report_page', [ReportPageController::class, 'payroll'])
        ->name('admin-panel.payroll-report-page');

    Route::get('/final_report_page', [ReportPageController::class, 'final'])
        ->name('admin-panel.final-report-page');

    Route::get('/download_pdf', [ReportPageController::class, 'downloadPdf'])
        ->name('admin-panel.download-pdf');
});
