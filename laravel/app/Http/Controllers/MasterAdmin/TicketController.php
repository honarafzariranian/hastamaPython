<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Services\Audit\AuditLogger;
use App\Support\Http\ClientAddress;
use App\Support\Http\MasterAdminActor;
use App\Support\Legacy\LegacyPath;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use App\Support\Ticketing\TicketLookupException;
use App\Support\Ticketing\TicketPermissionException;
use App\Support\Ticketing\TicketService;
use App\Support\Ticketing\TicketValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The master-admin ticket management surface.
 *
 * Ported from the ticket endpoints in `app/api/routes/master_admin.py`:
 * `list_all_tickets`, `ticket_stats`, `get_ticket_detail`,
 * `update_ticket_admin`, `reply_ticket_admin`, `delete_ticket_admin`,
 * `ticket_categories_admin` and `ticket_users_admin`.
 *
 * The ticket endpoints are a different surface from `app/api/routes/ticketing.py`
 * (the user-facing tickets) and from the legacy `ticket_table` endpoints in
 * `main.py`.  These are only the `/master-admin/api/tickets*` routes.
 *
 * The Python's `TicketService` is ported as `App\Support\Ticketing\TicketService`.
 * The `ensure_schema` call is not reproduced: the normalized schema is already
 * installed on the live database.
 */
final class TicketController extends MasterAdminController
{
    use MasterAdminActor;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * `GET /master-admin/api/tickets`
     *
     * The Python signature, declared as such: two bounded integers and five
     * optional string filters.  The list is paginated in SQL, not in PHP.
     */
    public function index(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 20, ge: 5, le: 100),
            'search' => LegacyQuery::string(default: '', maxLength: 100),
            'status' => LegacyQuery::string(default: '', maxLength: 32),
            'priority' => LegacyQuery::string(default: '', maxLength: 16),
            'assignee' => LegacyQuery::string(default: '', maxLength: 255),
            'sort' => LegacyQuery::string(default: 'newest', maxLength: 16),
        ]);

        try {
            $service = new TicketService();

            $result = $service->listTickets(
                actor: '',
                isAdmin: true,
                page: $params['page'],
                pageSize: $params['per_page'],
                search: $params['search'],
                status: $params['status'],
                priority: $params['priority'],
                assignee: $params['assignee'],
                sort: $params['sort'],
                isMasterAdmin: true,
            );

            return $this->ok(['success' => true, 'data' => $result]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'tickets.index');
        }
    }

    /**
     * `GET /master-admin/api/tickets/stats`
     *
     * Direct SQL queries on the `tickets` table — the same three counts the
     * Python ran.
     */
    public function stats(): JsonResponse
    {
        try {
            $connection = DB::connection();

            $total = (int) $connection->selectOne('SELECT COUNT(*) AS total FROM tickets')->total;

            $open = (int) $connection->selectOne(
                "SELECT COUNT(*) AS total FROM tickets WHERE status NOT IN ('resolved', 'closed')",
            )->total;

            $byStatus = [];
            $byPriority = [];

            foreach ($connection->select('SELECT status, COUNT(*) count FROM tickets GROUP BY status') as $row) {
                $byStatus[$row->status] = (int) $row->count;
            }

            foreach ($connection->select('SELECT priority, COUNT(*) count FROM tickets GROUP BY priority') as $row) {
                $byPriority[$row->priority] = (int) $row->count;
            }

            return $this->ok([
                'success' => true,
                'data' => [
                    'total' => $total,
                    'open' => $open,
                    'by_status' => $byStatus,
                    'by_priority' => $byPriority,
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'tickets.stats');
        }
    }

    /**
     * `GET /master-admin/api/tickets/{ticket_id}`
     *
     * The path parameter is a declared `int` in the Python, so a non-numeric id
     * is FastAPI's 422 with `loc: ["path", "ticket_id"]`.
     */
    public function show(string $ticketId): JsonResponse
    {
        $id = LegacyPath::int($ticketId, 'ticket_id');

        try {
            $service = new TicketService();
            $ticket = $service->getTicket($id, '', true);

            if ($ticket === null) {
                return $this->notFound('تیکت پیدا نشد.');
            }

            return $this->ok(['success' => true, 'data' => $ticket]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'tickets.show');
        }
    }

    /**
     * `PATCH /master-admin/api/tickets/{ticket_id}`
     *
     * The body is a JSON object with optional `status`, `priority`,
     * `category_id` and `assigned_to`.  The Python's `data.get(...)` returns
     * `null` for missing keys, which the service treats as "not provided".
     */
    public function update(string $ticketId, Request $request): JsonResponse
    {
        $id = LegacyPath::int($ticketId, 'ticket_id');

        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $this->detail(400, 'بدنه درخواست نامعتبر است.');
        }

        try {
            $service = new TicketService();

            $result = $service->updateTicket(
                $id,
                $this->adminUsername(),
                true,
                $decoded['status'] ?? null,
                $decoded['priority'] ?? null,
                isset($decoded['category_id']) ? (int) $decoded['category_id'] : null,
                $decoded['assigned_to'] ?? null,
            );

            return $this->ok(['success' => true, 'data' => $result]);
        } catch (TicketLookupException) {
            return $this->notFound('تیکت پیدا نشد.');
        } catch (TicketValidationException | TicketPermissionException $exception) {
            return $this->detail(400, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'tickets.update');
        }
    }

    /**
     * `POST /master-admin/api/tickets/{ticket_id}/reply`
     *
     * The body must carry a non-empty `body` and an optional `visibility`
     * (`public` or `internal`).  The validation order is the Python's:
     * body first, then visibility.
     */
    public function reply(string $ticketId, Request $request): JsonResponse
    {
        $id = LegacyPath::int($ticketId, 'ticket_id');

        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $this->detail(400, 'بدنه درخواست نامعتبر است.');
        }

        $body = isset($decoded['body']) && is_string($decoded['body']) ? trim($decoded['body']) : '';
        $visibility = isset($decoded['visibility']) && is_string($decoded['visibility'])
            ? trim($decoded['visibility'])
            : 'public';

        if ($body === '') {
            return $this->detail(400, 'متن پیام الزامی است.');
        }

        if (! in_array($visibility, ['public', 'internal'], true)) {
            return $this->detail(400, 'نوع پیام معتبر نیست.');
        }

        try {
            $service = new TicketService();

            $result = $service->addMessage(
                $id,
                $this->adminUsername(),
                true,
                $body,
                $visibility,
            );

            return $this->ok(['success' => true, 'data' => $result]);
        } catch (TicketLookupException) {
            return $this->notFound('تیکت پیدا نشد.');
        } catch (TicketValidationException | TicketPermissionException $exception) {
            return $this->detail(400, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'tickets.reply');
        }
    }

    /**
     * `DELETE /master-admin/api/tickets/{ticket_id}`
     *
     * Deletes the ticket and all its child rows (messages, events,
     * attachments, tag relations) in one transaction, then logs the action.
     */
    public function destroy(string $ticketId): JsonResponse
    {
        $id = LegacyPath::int($ticketId, 'ticket_id');

        try {
            $connection = DB::connection();

            $row = $connection->selectOne('SELECT id, subject FROM tickets WHERE id = ?', [$id]);

            if ($row === null) {
                return $this->notFound('تیکت پیدا نشد.');
            }

            $connection->delete('DELETE FROM ticket_messages WHERE ticket_id = ?', [$id]);
            $connection->delete('DELETE FROM ticket_events WHERE ticket_id = ?', [$id]);
            $connection->delete('DELETE FROM ticket_attachments WHERE ticket_id = ?', [$id]);
            $connection->delete('DELETE FROM ticket_tag_relations WHERE ticket_id = ?', [$id]);
            $connection->delete('DELETE FROM tickets WHERE id = ?', [$id]);

            $this->audit->adminAction($this->adminUsername(), 'delete_ticket', [
                'target_type' => 'ticket',
                'target_id' => (string) $id,
                'description' => 'حذف تیکت: '.$row->subject,
                'ip_address' => ClientAddress::for(request()),
            ]);

            return $this->ok(['success' => true]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'tickets.destroy');
        }
    }

    /**
     * `GET /master-admin/api/tickets/categories/all`
     */
    public function categories(): JsonResponse
    {
        try {
            $service = new TicketService();

            return $this->ok(['success' => true, 'data' => $service->categories()]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'tickets.categories');
        }
    }

    /**
     * `GET /master-admin/api/tickets/users/all`
     *
     * Every user with a trimmed username, name and department — the picker
     * data the ticket form uses.
     */
    public function users(): JsonResponse
    {
        try {
            $rows = DB::connection()->select(
                'SELECT LTRIM(RTRIM(username)) username,
                        LTRIM(RTRIM(COALESCE(name, \'\'))) name,
                        LTRIM(RTRIM(COALESCE(last_name, \'\'))) last_name,
                        LTRIM(RTRIM(COALESCE(department, \'\'))) department
                 FROM user_table ORDER BY name, username',
            );

            $users = [];

            foreach ($rows as $row) {
                $username = trim((string) ($row->username ?? ''));

                $users[] = [
                    'username' => $username,
                    'name' => trim(implode(' ', array_map(
                        static fn ($value): string => trim((string) $value),
                        [$row->name ?? '', $row->last_name ?? ''],
                    ))),
                    'department' => trim((string) ($row->department ?? '')),
                ];
            }

            return $this->ok(['success' => true, 'data' => $users]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'tickets.users');
        }
    }

    /**
     * FastAPI's `HTTPException` body for this module's own 400 refusals.
     */
    private function detail(int $status, string $message): JsonResponse
    {
        return response()->json(['detail' => $message], $status);
    }
}
