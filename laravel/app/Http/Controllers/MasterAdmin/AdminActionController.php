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
use Throwable;

/**
 * The operator-facing admin activity feed: filtered list and delete.
 *
 * Ported from `list_admin_actions` and `delete_admin_action` in
 * `app/api/routes/master_admin.py`.
 *
 * This is the one feed that records its own deletions: removing an
 * `admin_actions` row writes another one (`delete_admin_action`), so the
 * operator can see that something was removed — the feed loses the entry but
 * keeps the fact.
 */
final class AdminActionController extends MasterAdminController
{
    use MasterAdminActor;

    /** The ten columns the list selects. */
    private const LIST_COLUMNS = 'action_id, admin_username, action, target_username, target_type,
        target_id, description, ip_address, created_at';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * `GET /master-admin/api/admin-actions`
     *
     * The Python signature, declared as such: two bounded integers, a
     * `admin_username` partial match and an exact `action` match.
     */
    public function index(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 50, ge: 1, le: 200),
            'admin_username' => LegacyQuery::nullableString(),
            'action' => LegacyQuery::nullableString(),
        ]);

        try {
            $where = [];
            $bindings = [];

            if (is_string($params['admin_username']) && $params['admin_username'] !== '') {
                $where[] = 'admin_username LIKE ?';
                $bindings[] = '%'.$params['admin_username'].'%';
            }

            if (is_string($params['action']) && $params['action'] !== '') {
                $where[] = 'action = ?';
                $bindings[] = $params['action'];
            }

            $clause = $where === [] ? '' : ' WHERE '.implode(' AND ', $where);
            $connection = DB::connection();

            $total = (int) $connection->selectOne(
                "SELECT COUNT(*) AS total FROM admin_actions{$clause}",
                $bindings
            )->total;

            $rows = $connection->select(
                'SELECT '.self::LIST_COLUMNS."
                 FROM admin_actions{$clause}
                 ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
                array_merge($bindings, [
                    LegacyPagination::offset($params['page'], $params['per_page']),
                    $params['per_page'],
                ])
            );

            return $this->ok(LegacyPagination::envelope(
                LegacySerializer::rows('admin_actions', $rows),
                $total,
                $params['page'],
                $params['per_page'],
            ));
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'admin-actions.index');
        }
    }

    /**
     * `DELETE /master-admin/api/admin-actions/{action_id}`
     *
     * `{"success": bool}` — false for an unknown id, with no audit row.
     */
    public function destroy(string $actionId): JsonResponse
    {
        try {
            $deleted = DB::delete('DELETE FROM admin_actions WHERE action_id = ?', [$actionId]) > 0;

            if ($deleted) {
                $this->audit->adminAction($this->adminUsername(), 'delete_admin_action', [
                    'target_type' => 'admin_action',
                    'target_id' => $actionId,
                    'description' => 'حذف رکورد عملیات مدیریتی',
                    'ip_address' => ClientAddress::for(request()),
                ]);
            }

            return $this->ok(['success' => $deleted]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'admin-actions.destroy');
        }
    }
}
