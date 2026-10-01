<?php

use App\Http\Controllers\Tickets\LegacyTicketController;
use App\Http\Controllers\Tickets\PublicSupportTicketController;
use App\Http\Controllers\Tickets\TicketController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Ticketing - categories, threads, messages, attachments. Ported from app/api/routes/ticketing.py and POST /public/support-ticket from auth.py.
|--------------------------------------------------------------------------
|
| Registered BEFORE routes/web.php so the SPA fallback never shadows these
| paths.  See bootstrap/app.php.
|
| Two ticket generations live here, and they share nothing but the word:
|
| * **`/api/tickets/*` and `/public/support-ticket`** — the normalized
|   generation (`tickets`, `ticket_messages`, `ticket_events`,
|   `ticket_attachments`), ported from `app/api/routes/ticketing.py` and
|   `app/api/routes/auth.py`.  `TicketController` +
|   `PublicSupportTicketController`.
| * **`/get_ticket_requests*`, `/update_ticket*`, `/delete-ticket`,
|   `/get_ticket_details*`, `/add_ticket_response*`, `/mark_ticket_as_read`** —
|   the legacy `ticket_table` thread model from `app/main.py`, where every
|   handler is a `410 Gone` stub.  `LegacyTicketController`.
|
| ### Auth — a deliberate deviation from ROUTE_INVENTORY.md
|
| The inventory marks every `/api/tickets/*` row "master-admin session", but
| that column is auto-generated noise: row 13 (`POST /login_user` → "master-admin
| session") proves it, because that endpoint is public and already ported.  The
| Python handlers enforce only `_actor(request)` — any logged-in user — plus
| row-level ownership, and `user-panel-script.js` calls `/api/tickets` as an
| ordinary user, so master-admin middleware would break the live user panel.
| These routes therefore carry `legacy.session:optional` and the handler's own
| `_actor` answers the anonymous request with
| `401 {"detail":"برای ادامه وارد سامانه شوید."}`.  The inventory is left
| untouched; this note is the record of the decision.
|
| The legacy `main.py` routes are stubs that read no session at all, so their
| middleware is the app-wide `_SessionRegistryMiddleware` they actually passed
| through — `legacy.session:optional` — and nothing more.
|
| Route names are prefixed `tickets.` because names are globally unique.
*/

/*
|--------------------------------------------------------------------------
| Normalized ticketing — /api/tickets/*
|--------------------------------------------------------------------------
|
| `legacy.session:optional` on the whole group: the Python's app-wide session
| middleware passed an anonymous request through to the handler's own `_actor`,
| which is where the 401 comes from.  Literals are declared before the
| `{ticket_id}` wildcard, which is the Python's registration order.
*/

Route::prefix('api/tickets')->middleware(['legacy.session:optional'])->group(function (): void {

    Route::get('/categories', [TicketController::class, 'categories'])
        ->name('tickets.categories');

    Route::get('/users', [TicketController::class, 'users'])
        ->name('tickets.users');

    Route::get('', [TicketController::class, 'index'])
        ->name('tickets.index');

    Route::post('', [TicketController::class, 'store'])
        ->name('tickets.store');

    Route::get('/{ticket_id}', [TicketController::class, 'show'])
        ->name('tickets.show');

    Route::post('/{ticket_id}/messages', [TicketController::class, 'storeMessage'])
        ->name('tickets.messages');

    Route::patch('/{ticket_id}', [TicketController::class, 'update'])
        ->name('tickets.update');

    Route::post('/{ticket_id}/attachments', [TicketController::class, 'storeAttachment'])
        ->name('tickets.attachments.store');

    Route::get('/{ticket_id}/attachments/{attachment_id}', [TicketController::class, 'downloadAttachment'])
        ->name('tickets.attachments.download');
});

/*
|--------------------------------------------------------------------------
| The anonymous account-recovery form — no middleware at all
|--------------------------------------------------------------------------
|
| `POST /public/support-ticket` is public by design (a visitor who has
| forgotten their password cannot sign in), and `/public/` is a CSRF-exempt
| prefix in both the Python's `CSRF_EXEMPT_PREFIXES` and
| `config('hastama.csrf.exempt_prefixes')`, so no token is required either.
*/

Route::post('/public/support-ticket', [PublicSupportTicketController::class, 'store'])
    ->name('tickets.public.store');

/*
|--------------------------------------------------------------------------
| The legacy ticket_table generation — every handler a 410 Gone stub
|--------------------------------------------------------------------------
|
| Registered after the normalized group so the literal `/api/tickets` paths
| keep their priority.  The names are prefixed `tickets.legacy.` to keep them
| distinct from the normalized surface's.
*/

Route::middleware(['legacy.session:optional'])->group(function (): void {

    Route::get('/get_ticket_requests_admin', [LegacyTicketController::class, 'requestsAdmin'])
        ->name('tickets.legacy.requests-admin');

    Route::post('/delete-ticket', [LegacyTicketController::class, 'destroy'])
        ->name('tickets.legacy.delete');

    Route::post('/update_ticket', [LegacyTicketController::class, 'update'])
        ->name('tickets.legacy.update');

    Route::get('/get_ticket_requests', [LegacyTicketController::class, 'requests'])
        ->name('tickets.legacy.requests');

    Route::post('/update_ticket_status', [LegacyTicketController::class, 'updateStatus'])
        ->name('tickets.legacy.update-status');

    Route::get('/get_ticket_details/{ticket_id}', [LegacyTicketController::class, 'details'])
        ->name('tickets.legacy.details');

    Route::get('/get_ticket_details_payam/{ticket_id}', [LegacyTicketController::class, 'detailsPayam'])
        ->name('tickets.legacy.details-payam');

    Route::post('/add_ticket_response', [LegacyTicketController::class, 'addResponse'])
        ->name('tickets.legacy.add-response');

    Route::post('/add_ticket_response_userpanel', [LegacyTicketController::class, 'addResponseUserpanel'])
        ->name('tickets.legacy.add-response-userpanel');

    Route::post('/mark_ticket_as_read/{ticket_id}', [LegacyTicketController::class, 'markRead'])
        ->name('tickets.legacy.mark-read');
});
