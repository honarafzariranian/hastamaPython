<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `POST /api/admin/employment-status` — set a user's employment status.
 *
 * ### A deliberate divergence from the `admin` middleware
 *
 * Nearly every admin route in `app/main.py` is guarded by `_require_admin`,
 * which checks the session username first (401 `لاگین نکرده‌اید.`) and then
 * `is_admin` (403 `دسترسی مدیریتی ندارید.`) — the two answers the `admin`
 * middleware reproduces.  This handler is the exception: it calls
 * `get_is_admin_from_session()`, which checks **only** `is_admin is True` and
 * answers 403 `دسترسی مجاز نیست.` — a different message, and no username check
 * at all.
 *
 * So the guard is kept in the handler and the route carries only
 * `legacy.session:optional`, because the middleware would enforce *more* than
 * the Python did (a username check) and would answer a different body.  The
 * observable differences, all reproduced:
 *
 * | request | Python | `admin` middleware |
 * |---|---|---|
 * | anonymous | 403 `دسترسی مجاز نیست.` | 401 `لاگین نکرده‌اید.` |
 * | session, `is_admin` false | 403 `دسترسی مجاز نیست.` | 403 `دسترسی مدیریتی ندارید.` |
 * | session, `is_admin` true, no username | proceeds | 401 |
 *
 * The body is read with `request.json()` **inside** the try, so an unparseable
 * body is a 500 carrying `خطا در ذخیره وضعیت استخدام.` — not the generic safe
 * error most endpoints use, and not a 422.
 */
final class EmploymentStatusController extends Controller
{
    /** The two values `EMPLOYMENT_STATUS_VALUES` allows. */
    private const ALLOWED_STATUSES = ['official', 'unofficial'];

    public function update(Request $request): JsonResponse
    {
        // `get_is_admin_from_session()` — `is_admin is True`, nothing else.
        if ($request->session()->get('is_admin') !== true) {
            return response()->json(['success' => false, 'error' => 'دسترسی مجاز نیست.'], 403);
        }

        try {
            $body = json_decode((string) $request->getContent());

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('The request body is not valid JSON.');
            }

            // Valid JSON that is not an object: `data.get` raises `AttributeError`.
            $data = is_object($body) ? get_object_vars($body) : null;

            if (! is_array($data)) {
                throw new \RuntimeException('The request body is not a JSON object.');
            }

            $username = trim(strval($data['username'] ?? ''));
            $employmentStatus = mb_strtolower(trim(strval($data['employment_status'] ?? '')));

            if ($username === '' || ! in_array($employmentStatus, self::ALLOWED_STATUSES, true)) {
                return response()->json(['success' => false, 'error' => 'وضعیت استخدام معتبر نیست.'], 400);
            }

            // `ensure_employment_status_column()` is a no-op here: the column exists
            // (docs/migration/DATABASE_SCHEMA.md) and the migration must not create
            // objects in `userDB`.
            $affected = DB::connection()->table('user_table')
                ->whereRaw('LTRIM(RTRIM(username)) = LTRIM(RTRIM(?))', [$username])
                ->update(['employment_status' => $employmentStatus]);

            if ($affected === 0) {
                return response()->json(['success' => false, 'error' => 'کاربر پیدا نشد.'], 404);
            }

            return response()->json(['success' => true, 'employment_status' => $employmentStatus]);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'error' => 'خطا در ذخیره وضعیت استخدام.'], 500);
        }
    }
}
