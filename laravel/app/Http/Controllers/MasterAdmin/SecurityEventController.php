<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Services\Audit\AuditLogger;
use App\Support\Http\ClientAddress;
use App\Support\Http\MasterAdminActor;
use App\Support\Legacy\LegacyPagination;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * The security-event feed: filtered list, resolve and delete.
 *
 * Ported from `list_security_events`, `resolve_security_event` and
 * `delete_security_event` in `app/api/routes/master_admin.py`.
 *
 * The resolve endpoint has two behaviours that look like oversights and are
 * not:
 *
 * * it answers `{"success": true}` **even when the event does not exist** —
 *   the Python never checked `rowcount`, so resolving an already-deleted id is
 *   a successful no-op;
 * * it writes the `admin_actions` row **unconditionally**, success or not.
 *   The feed therefore records resolve *attempts*, not just state changes.
 */
final class SecurityEventController extends MasterAdminController
{
    use MasterAdminActor;

    /** The ten columns the list selects. */
    private const LIST_COLUMNS = 'event_id, event_type, severity, username, ip_address, description,
        status, resolved_by, resolved_at, created_at';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * `GET /master-admin/api/security`
     *
     * The Python signature, declared as such: two bounded integers and two
     * `Optional[str] = None` filters.
     */
    public function index(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 50, ge: 1, le: 200),
            'severity' => LegacyQuery::nullableString(),
            'status' => LegacyQuery::nullableString(),
        ]);

        try {
            $where = [];
            $bindings = [];

            foreach (['severity', 'status'] as $column) {
                if (is_string($params[$column]) && $params[$column] !== '') {
                    $where[] = "{$column} = ?";
                    $bindings[] = $params[$column];
                }
            }

            $clause = $where === [] ? '' : ' WHERE '.implode(' AND ', $where);
            $connection = DB::connection();

            $total = (int) $connection->selectOne(
                "SELECT COUNT(*) AS total FROM security_events{$clause}",
                $bindings
            )->total;

            $rows = $connection->select(
                'SELECT '.self::LIST_COLUMNS."
                 FROM security_events{$clause}
                 ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
                array_merge($bindings, [
                    LegacyPagination::offset($params['page'], $params['per_page']),
                    $params['per_page'],
                ])
            );

            return $this->ok(LegacyPagination::envelope(
                LegacySerializer::rows('security_events', $rows),
                $total,
                $params['page'],
                $params['per_page'],
            ));
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'security.index');
        }
    }

    /**
     * `POST /master-admin/api/security/{event_id}/resolve`
     *
     * The body is optional and its shape is the Python's: a JSON content type
     * is parsed (a parse failure is a 500, because the `await request.json()`
     * ran outside the `try`), anything else — including no body at all — is
     * `{}` and the status defaults to `resolved`.  A JSON body that is not an
     * object made `data.get` raise, exactly as in the Python.
     */
    public function resolve(string $eventId, Request $request): JsonResponse
    {
        $data = $this->parseOptionalObject($request);

        $newStatus = array_key_exists('status', $data)
            ? $this->pythonStr($data['status'])
            : 'resolved';

        try {
            DB::update(
                'UPDATE security_events SET status = ?, resolved_by = ?, resolved_at = SYSUTCDATETIME() WHERE event_id = ?',
                [$newStatus, $this->adminUsername(), $eventId]
            );

            $this->audit->adminAction($this->adminUsername(), 'resolve_security_event', [
                'target_type' => 'security_event',
                'target_id' => $eventId,
                'description' => "تغییر وضعیت به {$newStatus}",
                'ip_address' => ClientAddress::for(request()),
            ]);

            return $this->ok(['success' => true]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'security.resolve');
        }
    }

    /**
     * `DELETE /master-admin/api/security/{event_id}`
     *
     * `{"success": bool}` — false for an unknown id, with no audit row.
     */
    public function destroy(string $eventId): JsonResponse
    {
        try {
            $deleted = DB::delete('DELETE FROM security_events WHERE event_id = ?', [$eventId]) > 0;

            if ($deleted) {
                $this->audit->adminAction($this->adminUsername(), 'delete_security_event', [
                    'target_type' => 'security_event',
                    'target_id' => $eventId,
                    'description' => 'حذف رکورد رویداد امنیتی',
                    'ip_address' => ClientAddress::for(request()),
                ]);
            }

            return $this->ok(['success' => $deleted]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'security.destroy');
        }
    }

    /**
     * The Python's `await request.json() if content-type startswith
     * application/json else {}`.
     *
     * A JSON content type with an unparseable body is a 500 in the Python (the
     * call sat outside the `try`); a non-JSON content type skips parsing
     * entirely, so `POST` with no body at all is the ordinary way to call this.
     *
     * @return array<string, mixed>
     */
    private function parseOptionalObject(Request $request): array
    {
        $contentType = (string) $request->headers->get('content-type', '');

        if (! str_starts_with($contentType, 'application/json')) {
            return [];
        }

        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            throw new InvalidArgumentException('The request body is not a JSON object.');
        }

        return $decoded;
    }

    /**
     * Python's `str(value)` for the scalars a JSON body can carry — `None`
     * becomes the string `"None"`, which is what the Python's f-string put in
     * the audit description and what was bound as the new status.
     */
    private function pythonStr(mixed $value): string
    {
        if ($value === null) {
            return 'None';
        }

        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return 'Array';
    }
}
