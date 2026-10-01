<?php

namespace App\Support\Legacy;

use Illuminate\Http\Request;

/**
 * `_actor()` and `_require_admin()` from `app/api/routes/call_system.py`.
 *
 * The call system has **two** identity conventions, and the difference is load-bearing
 * rather than incidental:
 *
 * | Python                                          | here                                  |
 * |-------------------------------------------------|---------------------------------------|
 * | `_actor(request, admin=True, required=False)`   | `resolve($request, admin: true, required: false)` |
 * | `_actor(request, admin=True, required=True)`    | `resolve($request, admin: true)`      |
 * | `_require_admin(request)`                       | `requireAdmin($request)`              |
 *
 * The first is what the **kiosk** calls.  The reception desk has no session, so a call
 * taken there must not require one: `required=False` skips both checks and the row is
 * recorded against the literal username **`guest`**.  That string is not a placeholder —
 * it is written into `reception_calls.called_by` and `waiting_queue.added_by`, it is
 * what the history shows for every kiosk call, and `to_persian_numbers` never touches
 * it.  Anything other than `guest` would silently rewrite the audit trail of the
 * running system.
 *
 * The second and third are the administrator paths, and they refuse with FastAPI's
 * `{"detail": …}` bodies — `401` when there is no identity at all, `403` when there is
 * one but it is not an administrator.
 *
 * `is_admin is True` is compared with `=== true`, not truthiness: a session flag that
 * arrived as the string `"1"` must not pass as an administrator.  Phase 4 stored the
 * real boolean, and the strict comparison is what keeps that a property of the session
 * rather than a property of whatever a future writer puts in it.
 *
 * These run *inside* the handlers rather than in middleware, because the Python called
 * them at a specific point in each handler — after the kiosk guard, before the payload
 * was read — and the order decides which refusal a bad request gets.  See
 * `SlideController::upload()`, where `_actor(admin=True, required=True)` deliberately
 * fires **before** the origin check.
 */
final class CallActor
{
    /** The username the kiosk's calls are recorded against. */
    public const GUEST = 'guest';

    /**
     * `_actor(request, admin, required)` — the trimmed session username, or `guest`.
     *
     * @throws LegacyHttpException 401 when required and unauthenticated, 403 when
     *                             required, administrator-only and not an administrator.
     */
    public static function resolve(Request $request, bool $admin = false, bool $required = true): string
    {
        $username = trim((string) $request->session()->get('username', ''));

        if ($username === '' && $required) {
            throw LegacyHttpException::unauthenticated();
        }

        // `admin and required` — the administrator check is skipped when the identity
        // was optional, which is what lets the kiosk post a call at all.
        if ($admin && $required && $request->session()->get('is_admin') !== true) {
            throw LegacyHttpException::forbidden();
        }

        return $username === '' ? self::GUEST : $username;
    }

    /**
     * `_require_admin(request)` — always both checks, never optional.
     *
     * Unlike `resolve()`, this one does not take an `admin` flag: it exists only for the
     * slide-management handlers, and a caller of it is by definition administrator-only.
     *
     * @throws LegacyHttpException
     */
    public static function requireAdmin(Request $request): string
    {
        $username = trim((string) $request->session()->get('username', ''));

        if ($username === '') {
            throw LegacyHttpException::unauthenticated();
        }

        if ($request->session()->get('is_admin') !== true) {
            throw LegacyHttpException::forbidden();
        }

        return $username;
    }
}
