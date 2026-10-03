<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Ticketing\NotificationPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The three session guards `app/main.py` applies, in one place.
 *
 * The guards are reproduced rather than taken from the `admin` middleware
 * because the two disagree about **when** the refusal happens.  FastAPI
 * validates a handler's declared path / query / form / body parameters before
 * the handler runs, so on the routes where that validation precedes the guard
 * the middleware would answer 401/403 where the running server answers 422.
 * Those routes therefore carry only `legacy.session:optional` and call the
 * guard here, after their own validation.
 *
 * The three guards, verbatim from `app/main.py`:
 *
 * * `_require_admin` — a missing session username is 401
 *   `لاگین نکرده‌اید.`, a present non-administrator is 403
 *   `دسترسی مدیریتی ندارید.`;
 * * `get_is_admin_from_session` — `is_admin is True` and nothing else, always
 *   403 `دسترسی مجاز نیست.`, with no username check at all (this is the guard
 *   the two `/api/admin/payroll/*` routes use, and it is **not** the guard the
 *   `admin` middleware reproduces);
 * * `_require_auth` — a missing session username is 401 `لاگین نکرده‌اید.`.
 */
abstract class AdminPanelController extends Controller
{
    /**
     * `_require_admin()` — 401 for a missing username, 403 for a non-admin.
     *
     * @return JsonResponse|null `null` when the request may proceed.
     */
    protected function requireAdmin(Request $request): ?JsonResponse
    {
        $username = trim((string) $request->session()->get('username', ''));

        if ($username === '') {
            return response()->json(['success' => false, 'error' => 'لاگین نکرده‌اید.'], 401);
        }

        if ($request->session()->get('is_admin') !== true) {
            return response()->json(['success' => false, 'error' => 'دسترسی مدیریتی ندارید.'], 403);
        }

        return null;
    }

    /**
     * `get_is_admin_from_session()` — `is_admin is True`, nothing else.
     *
     * Deliberately not {@see requireAdmin}: there is no username check, so a
     * session with `is_admin` set and no username proceeds, and the 403 body is
     * a different string.
     */
    protected function requireIsAdmin(Request $request): JsonResponse
    {
        return response()->json(['success' => false, 'error' => 'دسترسی مجاز نیست.'], 403);
    }

    /**
     * Whether the session's `is_admin` flag is exactly `true`.
     */
    protected function isAdminSession(Request $request): bool
    {
        return $request->session()->get('is_admin') === true;
    }

    /**
     * `_require_auth()` — 401 for a missing username.
     *
     * @return JsonResponse|null `null` when the request may proceed.
     */
    protected function requireAuth(Request $request): ?JsonResponse
    {
        if (trim((string) $request->session()->get('username', '')) === '') {
            return response()->json(['success' => false, 'error' => 'لاگین نکرده‌اید.'], 401);
        }

        return null;
    }

    /**
     * `_notify_requester_status()` — tell the requester their request moved.
     *
     * The leave, overtime and hourly-pass status writes all call this when a
     * row is actually updated, so the request appears in the requester's
     * notification inbox.  The table name is part of the SQL text (identifiers
     * cannot be parameterised), so it is restricted to the same fixed allow-list
     * the Python used.
     *
     * @param  array<int, string>  $allowedTables
     */
    protected function notifyRequesterStatus(string $table, mixed $requestId, mixed $newStatus, string $label): void
    {
        $allowedTables = ['mrkhc_table', 'totalpass_table', 'ezafe_table'];

        if (! in_array($table, $allowedTables, true)) {
            return;
        }

        $row = DB::connection()->selectOne(
            "SELECT TOP 1 LTRIM(RTRIM(username)) AS username FROM {$table} WHERE id = ?",
            [$requestId]
        );

        $username = $row === null ? '' : trim((string) $row->username);

        if ($username === '') {
            return;
        }

        $status = trim((string) ($newStatus ?: 'انتظار تایید'));
        $kind = $status === 'تایید شده' ? 'success' : ($status === 'رد شده' ? 'warning' : 'information');

        NotificationPublisher::publish(
            "تغییر وضعیت درخواست {$label}",
            "وضعیت درخواست {$label} شما به «{$status}» تغییر کرد.",
            [$username],
            $kind,
            'normal',
            '/user_panel'
        );
    }
}
