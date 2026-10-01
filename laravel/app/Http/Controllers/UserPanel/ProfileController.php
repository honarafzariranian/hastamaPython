<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The user's own profile block — `GET /get_user_info` and
 * `GET /get_user_info_report` from `app/main.py`.
 *
 * These two are almost the same query with **different authorisation**, and the
 * difference is the interesting part:
 *
 * | | `/get_user_info` | `/get_user_info_report` |
 * |---|---|---|
 * | auth | session only, no guard | `_require_auth`, else 401 |
 * | target | implicit (the session user) | explicit `?username=`, required |
 * | IDOR | n/a — cannot ask for anyone else | non-admins may only ask for themselves |
 *
 * The report variant is the one the individual leave and overtime screens use, and
 * its ownership check is the only thing standing between a signed-in user and every
 * other employee's profile.  It is ported exactly: an admin may look up anyone, a
 * non-admin only themselves, compared case-insensitively after trimming.
 *
 * Both answer with a **200 and `success: false`** for a missing user, not a 404 —
 * the front-end distinguishes "profile not readable" from "request failed" by the
 * flag, not the status.
 */
final class ProfileController extends Controller
{
    /**
     * The generic internal-error message for `main.py` endpoints.
     *
     * `_safe_error_message()` — note it differs from the master-admin module's
     * (`خطای داخلی سرور`, no suffix).  Two modules, two strings, both live.
     */
    private const SAFE_ERROR = 'خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید.';

    /** The five columns both endpoints publish.  Never widened to the password. */
    private const PROFILE_COLUMNS = 'name, last_name, department, work_hours, substitute';

    /** `GET /get_user_info` */
    public function info(Request $request): JsonResponse
    {
        $username = $this->sessionUsername($request);

        if ($username === '') {
            return response()->json(['success' => false, 'message' => 'نام کاربری پیدا نشد']);
        }

        return $this->profile($username);
    }

    /** `GET /get_user_info_report?username=…` */
    public function report(Request $request): JsonResponse
    {
        // The Python signature is `username: str = Query(...)` — a *required* query
        // parameter, and FastAPI resolves those **before** the handler body runs.
        // So the ordering here is load-bearing: a request that omits `username` is
        // rejected with 422, and *not* with the 401 below, even when it is anonymous.
        // Declared through `LegacyQuery` rather than `$request->validate()`, because
        // Laravel renders its own validation failure as a 302 redirect for a request
        // that does not send `Accept: application/json` — which the legacy front-end
        // does not, and which would have replaced a rejection with a redirect to the
        // page the caller was already on.
        $params = LegacyQuery::validate($request, [
            'username' => LegacyQuery::requiredString(),
        ]);

        $requested = $params['username'];

        // `_require_auth` — 401 with the `error` key, not `message`.  The predicate is
        // the session username, exactly as `get_user_from_session()` had it, rather
        // than Laravel's guard: a session that still carries an identity but has lost
        // its guard must behave the way the running server behaves.
        $sessionUsername = $this->sessionUsername($request);

        if ($sessionUsername === '') {
            return response()->json(['success' => false, 'error' => 'لاگین نکرده‌اید.'], 401);
        }

        // Reachable only for `?username=` with an empty value: `Query(...)` requires
        // the parameter to be *present*, not to be non-empty, so FastAPI handed the
        // empty string through to this branch too.
        if ($requested === '') {
            return response()->json(['success' => false, 'message' => 'نام کاربری ارائه نشده است']);
        }

        // IDOR protection: users may only read their own profile; admins may read
        // any.  `str.strip().lower()` on both sides, ported verbatim.
        if (! $this->isAdmin($request) && ! $this->sameIdentity($requested, $sessionUsername)) {
            return response()->json(['success' => false, 'message' => 'دسترسی غیرمجاز'], 403);
        }

        return $this->profile($requested);
    }

    /**
     * The shared lookup and response.
     *
     * The predicate is `WHERE username = ?` with the value **untrimmed**, as the
     * Python code had it.  SQL Server ignores trailing spaces in `=`, so a padded
     * stored value still matches; a caller sending `" admin"` with a leading space
     * would not match, and that is also what the running server does.
     */
    private function profile(string $username): JsonResponse
    {
        try {
            $row = DB::connection()->selectOne(
                'SELECT '.self::PROFILE_COLUMNS.' FROM user_table WHERE username = ?',
                [$username]
            );

            if ($row === null) {
                return response()->json(['success' => false, 'message' => 'اطلاعات کاربر پیدا نشد']);
            }

            $profile = (array) $row;

            return response()->json([
                'success' => true,
                'data' => [
                    'name' => $profile['name'] ?? null,
                    'last_name' => $profile['last_name'] ?? null,
                    'department' => $profile['department'] ?? null,
                    'work_hours' => $profile['work_hours'] ?? null,
                    'substitute' => $profile['substitute'] ?? null,
                ],
            ]);
        } catch (Throwable $exception) {
            Log::error('get_user_info failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => self::SAFE_ERROR]);
        }
    }

    /** The trimmed session username, or `''`. */
    private function sessionUsername(Request $request): string
    {
        return trim((string) $request->session()->get('username', ''));
    }

    /** `request.session.get("is_admin") is True` — never truthy, only `true`. */
    private function isAdmin(Request $request): bool
    {
        return $request->session()->get('is_admin') === true;
    }

    /** `a.strip().lower() == b.strip().lower()` */
    private function sameIdentity(string $left, string $right): bool
    {
        return mb_strtolower(trim($left)) === mb_strtolower(trim($right));
    }
}
