<?php

namespace App\Http\Controllers\Tickets;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The **legacy** ticket generation — `app/main.py`'s `ticket_table` thread
 * model, where one conversation is several rows joined by `Parent_id`.
 *
 * Every handler in `app/main.py` for this generation is a stub:
 *
 * ```python
 * @app.get("/get_ticket_requests_admin")
 * async def get_ticket_requests_admin(request: Request):
 *     return JSONResponse(status_code=410, content={"success": False, "error": "این مسیر قدیمی تیکت منسوخ شده است."})
 *     actor, auth_error = _ticket_actor(request)   # dead code, never reached
 *     ...
 * ```
 *
 * The `return` is the first statement, so the deprecation answer is the whole
 * behaviour: no session is read, no query runs, and the dead code after it —
 * `_ticket_actor`, the CTE over `ticket_table`, the reply insert — is never
 * reached.  The port reproduces that literally: each method answers **410 Gone**
 * and nothing else.
 *
 * Two shapes exist and both are live, because two different handlers wrote them:
 *
 * * `{"success": false, "error": "این مسیر قدیمی تیکت منسوخ شده است."}` —
 *   `get_ticket_requests_admin`, `delete-ticket`, `update_ticket`,
 *   `get_ticket_requests`, `update_ticket_status`, `add_ticket_response`,
 *   `add_ticket_response_userpanel` (via `_create_ticket_response`) and
 *   `mark_ticket_as_read`;
 * * `{"error": "این مسیر قدیمی تیکت منسوخ شده است."}` — the two detail views,
 *   which never had the `success` key at all.
 *
 * The routes carry `legacy.session:optional`, which is what the Python's
 * app-wide `_SessionRegistryMiddleware` enforced ahead of every handler: an
 * anonymous request passes through to the 410, and a revoked or expired session
 * is refused by the middleware before the handler would have answered.
 */
final class LegacyTicketController extends Controller
{
    /** The deprecation body most of the stubs answer with. */
    private const DEPRECATED = ['success' => false, 'error' => 'این مسیر قدیمی تیکت منسوخ شده است.'];

    /** The detail views' body — the same message without the `success` key. */
    private const DEPRECATED_BARE = ['error' => 'این مسیر قدیمی تیکت منسوخ شده است.'];

    /** `GET /get_ticket_requests_admin` — the admin's grouped ticket list. */
    public function requestsAdmin(): JsonResponse
    {
        return $this->deprecated();
    }

    /** `POST /delete-ticket` — delete a conversation by `Parent_id`. */
    public function destroy(): JsonResponse
    {
        return $this->deprecated();
    }

    /** `POST /update_ticket` — edit a pending ticket's receiver and text. */
    public function update(): JsonResponse
    {
        return $this->deprecated();
    }

    /** `GET /get_ticket_requests` — the user's own ticket list. */
    public function requests(): JsonResponse
    {
        return $this->deprecated();
    }

    /** `POST /update_ticket_status` — move a conversation's status. */
    public function updateStatus(): JsonResponse
    {
        return $this->deprecated();
    }

    /** `GET /get_ticket_details/{ticket_id}` — the admin conversation view. */
    public function details(string $ticketId): JsonResponse
    {
        return $this->deprecatedBare();
    }

    /** `GET /get_ticket_details_payam/{ticket_id}` — the user conversation view. */
    public function detailsPayam(string $ticketId): JsonResponse
    {
        return $this->deprecatedBare();
    }

    /** `POST /add_ticket_response` — reply as staff. */
    public function addResponse(): JsonResponse
    {
        return $this->deprecated();
    }

    /** `POST /add_ticket_response_userpanel` — reply as the user. */
    public function addResponseUserpanel(): JsonResponse
    {
        return $this->deprecated();
    }

    /** `POST /mark_ticket_as_read/{ticket_id}` — mark a conversation read. */
    public function markRead(string $ticketId): JsonResponse
    {
        return $this->deprecated();
    }

    private function deprecated(): JsonResponse
    {
        return response()->json(self::DEPRECATED, 410);
    }

    private function deprecatedBare(): JsonResponse
    {
        return response()->json(self::DEPRECATED_BARE, 410);
    }
}
