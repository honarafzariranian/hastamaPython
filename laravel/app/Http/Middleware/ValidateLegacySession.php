<?php

namespace App\Http\Middleware;

use App\Services\Auth\SessionRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Validate the revocable session on every authenticated request.
 *
 * Laravel's signed cookie proves the session was issued by this application, but it
 * cannot be revoked — which is why the registry table exists.  This middleware is
 * the bridge: after Laravel's own guard has resolved the user, it asks the registry
 * whether the session that produced this cookie is still active, still belongs to
 * the same username, and has not been idle for too long.
 *
 * The order matters and matches `_SessionRegistryMiddleware` in `app/main.py`: the
 * cheap cookie check happens first (Laravel's guard), and the registry check runs
 * only for a request that already looks authenticated, so an anonymous request
 * never pays for a database round trip.
 *
 * When the registry says no, the callback logs the browser out and redirects a
 * page request to `/login` while answering an API request with the envelope the
 * front-end expects — the split the legacy middleware also made, because a
 * redirect to an HTML page in response to `fetch()` produces a parse error instead
 * of a login prompt.
 */
final class ValidateLegacySession
{
    /**
     * Route-middleware parameter that reproduces the legacy pass-through.
     *
     * `legacy.session:optional` lets a request with **no** session identity reach the
     * handler, exactly as `_SessionRegistryMiddleware` did:
     *
     *     username = str(session.get("username") or "").strip()
     *     if not username:
     *         await self.app(scope, receive, send)   # anonymous passes through
     *         return
     *
     * The distinction matters for the endpoints ported from `app/main.py`, whose own
     * guards produce their own bodies: `_require_admin` answers
     * `{"success": false, "error": "لاگین نکردهاید."}` and `_ticket_actor` answers
     * `{"success": false, "error": "دسترسی غیرمجاز"}`.  Answering either with this
     * middleware's "session expired" body would hand the client a third body for a
     * condition it already handles, and the ticket UI branches on the `error` key.
     *
     * The default (no parameter) keeps the strict behaviour `/api/me` was built
     * against and is tested for.  Only the legacy route groups opt in.
     */
    public const OPTIONAL = 'optional';

    public function __construct(private readonly SessionRegistry $registry) {}

    public function handle(Request $request, Closure $next, ?string $mode = null): Response
    {
        if (! Auth::check()) {
            // An anonymous request that has no identity at all is not a revoked
            // session — there is nothing to revoke.  In `optional` mode the handler's
            // own guard owns this answer.
            if ($this->isOptional($mode) && trim((string) $request->session()->get('username', '')) === '') {
                return $next($request);
            }

            return $this->unauthenticated($request);
        }

        $token = (string) $request->session()->get(SessionRegistry::SESSION_TOKEN_KEY, '');
        $username = (string) (Auth::user()?->username() ?? '');

        if ($this->registry->validate($token, $username)) {
            return $next($request);
        }

        // The session was revoked, expired while idle, or lost its registry row:
        // end the Laravel session too, so a stale cookie cannot linger.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->unauthenticated($request);
    }

    /** Whether the route asked for the legacy pass-through behaviour. */
    private function isOptional(?string $mode): bool
    {
        return $mode === self::OPTIONAL;
    }

    private function unauthenticated(Request $request): Response
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'success' => false,
                'message' => 'نشست شما منقضی شده است. لطفاً دوباره وارد شوید.',
                'unauthenticated' => true,
            ], 401);
        }

        return redirect('/login');
    }
}
