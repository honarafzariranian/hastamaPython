<?php

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public page shells with no guard of their own — ported from
 * `register_page`, `rules`, `ticket_kiosk` and `ticket_print_page` in
 * `app/main.py`.
 *
 * The application is a Vue SPA: Laravel serves one shell document and Vue
 * Router owns everything below it, exactly as `routes/web.php` does for
 * `/login`.  The Python rendered a full Jinja document per route; the port
 * serves the mount document the Vue views render into.  No per-route data
 * was lost with the templates — these four pages carried only `request`.
 */
final class ShellController extends Controller
{
    /**
     * `GET /register` — the self-registration page.
     */
    public function register(): Response
    {
        return $this->shell();
    }

    /**
     * `GET /rules` — the laboratory rules page.
     */
    public function rules(): Response
    {
        return $this->shell();
    }

    /**
     * `GET /ticket-kiosk` — the touch-screen kiosk for visitors.
     */
    public function ticketKiosk(): Response
    {
        return $this->shell();
    }

    /**
     * `GET /ticket-print` — the label-printing page.
     */
    public function ticketPrint(): Response
    {
        return $this->shell();
    }

    private function shell(): Response
    {
        return response()->view('app');
    }
}
