<?php

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The master-admin control-centre pages — ported from `master_admin_root` and
 * `master_admin_page` in `app/main.py`.
 */
final class MasterAdminController extends Controller
{
    /**
     * `GET /master-admin` — the control-centre entry point.
     *
     * The Python redirected **unconditionally** to the dashboard with 303:
     * there is no auth check on this route at all.  The guard lives one level
     * down, on `/master-admin/{section}`.  Reproduced as-is — "fixing" this
     * into a guard would change what an anonymous bookmark sees.
     */
    public function root(): Response
    {
        return redirect('/master-admin/dashboard', 303);
    }

    /**
     * `GET /master-admin/{section}` — one shell for every section.
     *
     * The guard is the Python's, in the Python's order, because the two
     * failures have two different targets:
     *
     * * no session identity → `/login`;
     * * a signed-in non-master-admin → `/admin`, the panel they do have.
     *
     * The `master_admin` middleware is deliberately **not** applied here.  It
     * answers `{"detail": …}` with 401/403 — the contract for the
     * `/master-admin/api/*` JSON routes — where the page contract is a 303
     * redirect that a browser following a link expects.  The check runs in
     * the handler instead, which the migration rules allow ("middleware or
     * in-handler"), and `legacy.session:optional` on the route still
     * reproduces the app-wide registry revocation.
     */
    public function section(Request $request): Response
    {
        $username = $request->session()->get('username');

        if (! is_string($username) || $username === '') {
            return redirect('/login', 303);
        }

        if ($request->session()->get('is_master_admin') !== true) {
            return redirect('/admin', 303);
        }

        return response()->view('app');
    }
}
