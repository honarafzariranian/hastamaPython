<?php

namespace App\Support\Notifications;

use App\Support\Legacy\LegacyHttpException;
use Illuminate\Http\Request;

/**
 * `_actor()` from `app/api/routes/notifications.py` — the module's own guard.
 *
 * The notifications module does **not** use the application-wide `_require_admin`
 * decorator; every handler calls `_actor(request)` or `_actor(request, admin=True)`
 * as its first line, and the two refusals are FastAPI's `{"detail": …}` bodies:
 *
 *     401  {"detail": "برای ادامه وارد سامانه شوید."}      no session username
 *     403  {"detail": "دسترسی مدیریت لازم است."}             not an administrator
 *
 * Those are **not** the bodies `App\Http\Middleware\EnsureAdmin` produces
 * (`{"success": false, "error": …}` with two different Persian strings), so the
 * check runs here rather than in middleware — the same reason `CallActor` exists
 * for `call_system.py`.  The order is the Python's too: identity first, then the
 * administrator flag, and only then does the handler touch the database.
 *
 * `is_admin is True` is compared with `=== true`, not truthiness — a session flag
 * that arrived as the string `"1"` must not pass as an administrator.
 */
final class NotificationActor
{
    /**
     * `_actor(request, admin)` — the trimmed session username.
     *
     * @throws LegacyHttpException 401 when there is no session identity, 403 when
     *                             the route is administrator-only and the session
     *                             does not carry `is_admin === true`.
     */
    public static function resolve(Request $request, bool $admin = false): string
    {
        $username = trim((string) $request->session()->get('username', ''));

        if ($username === '') {
            throw LegacyHttpException::unauthenticated();
        }

        if ($admin && $request->session()->get('is_admin') !== true) {
            throw LegacyHttpException::forbidden();
        }

        return $username;
    }
}
