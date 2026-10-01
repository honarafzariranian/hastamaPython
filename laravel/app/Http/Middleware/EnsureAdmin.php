<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin guard, with the existing response bodies.
 *
 * `_require_admin` in `app/main.py` is used by dozens of endpoints, and its two
 * failure answers are part of the API contract the front-end already handles:
 *
 *     401  {"success": false, "error": "لاگین نکرده‌اید."}
 *     403  {"success": false, "error": "دسترسی مدیریتی ندارید."}
 *
 * Note the key: **`error`**, not `message`.  That inconsistency is real — most
 * endpoints answer `message` while these guards answer `error` — and the Vue client
 * reads both.  It is preserved deliberately and recorded in `docs/migration/`
 * rather than "tidied", because the running front-end depends on it.
 *
 * Authorisation is decided from the session flags the login flow sets, exactly as
 * the legacy code did, so a role change takes effect at the next login (or
 * immediately when the administrator revokes the sessions, which the user-management
 * endpoints do).
 */
final class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $this->deny('لاگین نکرده‌اید.', 401);
        }

        if ($request->session()->get('is_admin') !== true) {
            return $this->deny('دسترسی مدیریتی ندارید.', 403);
        }

        return $next($request);
    }

    private function deny(string $message, int $status): Response
    {
        return response()->json([
            'success' => false,
            'error' => $message,
        ], $status);
    }
}
