<?php

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /user_panel` — the user panel shell, ported from `user_panel` in
 * `app/main.py`.
 *
 * The Python redirected an anonymous caller to `/login` with 303 and only
 * then rendered; the redirect is the contract and is reproduced before the
 * shell is served.  The rendered document itself is the Vue shell now — the
 * per-user data the template carried (leave balances, pass totals, the
 * presence ring) is fetched by the client from the ported read endpoints
 * under `/get_*` and `/api/*`.
 */
final class UserPanelController extends Controller
{
    public function panel(Request $request): Response
    {
        $username = $request->session()->get('username');

        if (! is_string($username) || $username === '') {
            return redirect('/login', 303);
        }

        return response()->view('app');
    }
}
