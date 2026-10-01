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
 * The grouped system-error log: filtered list, resolve and delete.
 *
 * Ported from `list_errors`, `resolve_error` and `delete_error` in
 * `app/api/routes/master_admin.py`.
 *
 * The resolve endpoint reproduces the security event's two no-op behaviours
 * (see `SecurityEventController`): `{"success": true}` even when the error id
 * does not exist, and an `admin_actions` row written unconditionally.
 */
final class SystemErrorController extends MasterAdminController
{
    use MasterAdminActor;

    /** The thirteen columns the list selects. */
    private const LIST_COLUMNS = 'error_id, error_type, severity, message, endpoint, method, username,
        ip_address, occurrences, status, resolved_at, first_seen, last_seen';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * `GET /master-admin/api/errors`
     *
     * The Python signature, declared as such: two bounded integers and three
     * `Optional[str] = None` filters.  Ordered by `first_seen DESC` — unlike
     * every other list in this module, which orders by `created_at`.
     */
    public function index(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 50, ge: 1, le: 200),
            'severity' => LegacyQuery::nullableString(),
            'status' => LegacyQuery::nullableString(),
            'error_type' => LegacyQuery::nullableString(),
        ]);

        try {
            $where = [];
            $bindings = [];

            foreach (['severity', 'status', 'error_type'] as $column) {
                if (is_string($params[$column]) && $params[$column] !== '') {
                    $where[] = "{$column} = ?";
                    $bindings[] = $params[$column];
                }
            }

            $clause = $where === [] ? '' : ' WHERE '.implode(' AND ', $where);
            $connection = DB::connection();

            $total = (int) $connection->selectOne(
                "SELECT COUNT(*) AS total FROM system_errors{$clause}",
                $bindings
            )->total;

            $rows = $connection->select(
                'SELECT '.self::LIST_COLUMNS."
                 FROM system_errors{$clause}
                 ORDER BY first_seen DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
                array_merge($bindings, [
                    LegacyPagination::offset($params['page'], $params['per_page']),
                    $params['per_page'],
                ])
            );

            return $this->ok(LegacyPagination::envelope(
                LegacySerializer::rows('system_errors', $rows),
                $total,
                $params['page'],
                $params['per_page'],
            ));
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'errors.index');
        }
    }

    /**
     * `POST /master-admin/api/errors/{error_id}/resolve`
     *
     * Same optional-body contract as the security resolve: a JSON content type
     * is parsed (unparseable → 500), anything else defaults the new status to
     * `resolved`.
     */
    public function resolve(string $errorId, Request $request): JsonResponse
    {
        $data = $this->parseOptionalObject($request);

        $newStatus = array_key_exists('status', $data)
            ? $this->pythonStr($data['status'])
            : 'resolved';

        try {
            DB::update(
                'UPDATE system_errors SET status = ?, resolved_by = ?, resolved_at = SYSUTCDATETIME() WHERE error_id = ?',
                [$newStatus, $this->adminUsername(), $errorId]
            );

            $this->audit->adminAction($this->adminUsername(), 'resolve_error', [
                'target_type' => 'system_error',
                'target_id' => $errorId,
                'description' => "تغییر وضعیت خطا به {$newStatus}",
                'ip_address' => ClientAddress::for(request()),
            ]);

            return $this->ok(['success' => true]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'errors.resolve');
        }
    }

    /**
     * `DELETE /master-admin/api/errors/{error_id}`
     *
     * `{"success": bool}` — false for an unknown id, with no audit row.
     */
    public function destroy(string $errorId): JsonResponse
    {
        try {
            $deleted = DB::delete('DELETE FROM system_errors WHERE error_id = ?', [$errorId]) > 0;

            if ($deleted) {
                $this->audit->adminAction($this->adminUsername(), 'delete_system_error', [
                    'target_type' => 'system_error',
                    'target_id' => $errorId,
                    'description' => 'حذف رکورد خطای سیستم',
                    'ip_address' => ClientAddress::for(request()),
                ]);
            }

            return $this->ok(['success' => $deleted]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'errors.destroy');
        }
    }

    /**
     * The Python's `await request.json() if content-type startswith
     * application/json else {}`.
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
     * Python's `str(value)` for the scalars a JSON body can carry.
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
