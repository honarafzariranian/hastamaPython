<?php

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel pages — ported from `admin`, `admin_dashboard` and
 * `admin_section` in `app/main.py`.
 *
 * The route inventory lists `/admin/dashboard` and `/admin/{section}` as
 * public; the source says otherwise.  Both call `_render_admin_page`, which
 * runs the same `not username or not is_admin` check and answers 303 `/login`
 * — the source is the specification, so the guard is reproduced and the
 * inventory row is recorded as wrong in the migration report.
 */
final class AdminController extends Controller
{
    /**
     * `GET /admin` — the admin entry point: a redirect chain, not a page.
     *
     * The Python checked the session first and sent an anonymous caller **or**
     * a signed-in non-admin to `/login`; only an admin went on to the
     * dashboard.  Both hops are 303.
     */
    public function admin(Request $request): Response
    {
        if (! $this->isAdmin($request)) {
            return redirect('/login', 303);
        }

        return redirect('/admin/dashboard', 303);
    }

    /**
     * `GET /admin/dashboard` — the dashboard shell.
     */
    public function dashboard(Request $request): Response
    {
        if (! $this->isAdmin($request)) {
            return redirect('/login', 303);
        }

        return response()->view('app');
    }

    /**
     * `GET /admin/{section}` — one shell for every section.
     *
     * The Python passed every section through the same `_render_admin_page`,
     * so the section name never reached a template; the Vue router owns it now.
     */
    public function section(Request $request): Response
    {
        return $this->dashboard($request);
    }

    /**
     * `get_user_from_session` + `get_is_admin_from_session`.
     *
     * Note the strip, which the master-admin page guard does not do: a
     * whitespace-only username is "nobody" here.
     */
    private function isAdmin(Request $request): bool
    {
        $username = trim((string) ($request->session()->get('username') ?? ''));

        if ($username === '') {
            return false;
        }

        return $request->session()->get('is_admin') === true;
    }
}
