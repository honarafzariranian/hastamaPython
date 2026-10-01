<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Services\Audit\AuditLogger;
use App\Support\Http\ClientAddress;
use App\Support\Http\MasterAdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `DELETE /master-admin/api/audit-logs/{event_id}` — removing one audit row.
 *
 * The read half of the audit trail lives in `AuditLogController`; this is the
 * delete the control centre's log view calls, ported from
 * `delete_audit_event` in `app/api/routes/master_admin.py`.
 *
 * Two behaviours worth stating because both are visible to the caller:
 *
 * * a row that does not exist is **not** an error — the answer is
 *   `{"success": false}` with status 200, exactly as the Python's
 *   `JSONResponse(content={"success": deleted})` produced;
 * * the `admin_actions` entry is written **only when a row was actually
 *   deleted**.  Deleting an unknown id twice therefore writes one action, not
 *   two — the audit feed is a record of what happened, not of what was
 *   attempted.
 */
final class AuditLogWriteController extends MasterAdminController
{
    use MasterAdminActor;

    public function __construct(private readonly AuditLogger $audit) {}

    public function destroy(string $eventId): JsonResponse
    {
        try {
            $deleted = DB::delete('DELETE FROM audit_logs WHERE event_id = ?', [$eventId]) > 0;

            if ($deleted) {
                $this->audit->adminAction($this->adminUsername(), 'delete_audit_log', [
                    'target_type' => 'audit_log',
                    'target_id' => $eventId,
                    'description' => 'حذف رکورد لاگ حسابرسی',
                    'ip_address' => ClientAddress::for(request()),
                ]);
            }

            return $this->ok(['success' => $deleted]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'audit-logs.destroy');
        }
    }
}
