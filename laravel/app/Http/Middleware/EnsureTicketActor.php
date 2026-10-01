<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `_ticket_actor` from `app/main.py` — the guard the ticket and picker endpoints use.
 *
 *     def _ticket_actor(request, admin_only=False):
 *         actor = str(request.session.get("username") or "").strip()
 *         is_admin = request.session.get("is_admin") is True
 *         if not actor or (admin_only and not is_admin):
 *             return None, JSONResponse(403, {"success": False, "error": "دسترسی غیرمجاز"})
 *         return actor, None
 *
 * Note the failure body: **403 with `error`**, and it answers 403 even for a
 * completely anonymous request.  That is not the same as the admin guard, which
 * distinguishes 401 from 403 — and the ticket UI branches on it, so it is
 * preserved.  `/get_users` and `/get_receivers` are its first two consumers; the
 * ticketing phase will add the rest.
 *
 * The resolved actor is published as a request attribute (`legacy.actor`) so a
 * controller does not have to re-read the session and re-derive the same string.
 */
final class EnsureTicketActor
{
    /** Request attribute carrying the trimmed session username. */
    public const ACTOR_ATTRIBUTE = 'legacy.actor';

    public function handle(Request $request, Closure $next, ?string $adminOnly = null): Response
    {
        $actor = trim((string) $request->session()->get('username', ''));

        if ($actor === '' || ($this->wantsAdmin($adminOnly) && $request->session()->get('is_admin') !== true)) {
            return response()->json([
                'success' => false,
                'error' => 'دسترسی غیرمجاز',
            ], 403);
        }

        $request->attributes->set(self::ACTOR_ATTRIBUTE, $actor);

        return $next($request);
    }

    /** `ticket.actor:admin` requires the admin flag, exactly like `admin_only=True`. */
    private function wantsAdmin(?string $flag): bool
    {
        return $flag !== null && $flag !== '' && $flag !== '0';
    }
}
