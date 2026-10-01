<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Services\Audit\AuditLogger;
use App\Services\Audit\RecoveryCodeService;
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
 * The admin half of the password-recovery workflow.
 *
 * Ported from `list_password_resets`, `approve_reset`, `reject_reset` and
 * `delete_password_reset` in `app/api/routes/master_admin.py`.  The public half
 * (`/forgot_password`, `/reset_password`) is already ported in
 * `App\Http\Controllers\Auth\RecoveryController`.
 *
 * It is worth stating what approval does **not** do, because the obvious
 * assumption is wrong: approving a request does **not** revoke the account's
 * sessions.  The Python's `approve_password_reset` only writes the request row
 * (status, code digest, expiry, approver) and returns the one-time code to
 * the administrator; the sessions are cut later, by `reset_password`, at the
 * moment the new password is actually set — see
 * `RecoveryController::resetPassword()`.  Cutting them at approval would log
 * everyone out while they were still waiting for their code.
 *
 * The code itself is returned **once**, in the approval response, to the
 * administrator; only its HMAC digest is stored.  There is no email and no SMS
 * anywhere in this flow — the administrator tells the user the code.
 */
final class PasswordResetController extends MasterAdminController
{
    use MasterAdminActor;

    /** The ten columns the list selects — never `recovery_code`. */
    private const LIST_COLUMNS = 'request_id, username, ip_address, status, code_attempts,
        max_attempts, approved_by, approved_at, completed_at, created_at';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RecoveryCodeService $recovery,
    ) {}

    /**
     * `GET /master-admin/api/password-resets`
     *
     * The Python signature, declared as such: two bounded integers and one
     * `Optional[str] = None` status filter.  Unlike the subscription list this
     * one carries no `setup_needed` key — the table is not optional.
     */
    public function index(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 50, ge: 1, le: 200),
            'status' => LegacyQuery::nullableString(),
        ]);

        try {
            $where = [];
            $bindings = [];

            if (is_string($params['status']) && $params['status'] !== '') {
                $where[] = 'status = ?';
                $bindings[] = $params['status'];
            }

            $clause = $where === [] ? '' : ' WHERE '.implode(' AND ', $where);
            $connection = DB::connection();

            $total = (int) $connection->selectOne(
                "SELECT COUNT(*) AS total FROM password_reset_requests{$clause}",
                $bindings
            )->total;

            $rows = $connection->select(
                'SELECT '.self::LIST_COLUMNS."
                 FROM password_reset_requests{$clause}
                 ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
                array_merge($bindings, [
                    LegacyPagination::offset($params['page'], $params['per_page']),
                    $params['per_page'],
                ])
            );

            return $this->ok(LegacyPagination::envelope(
                LegacySerializer::rows('password_reset_requests', $rows),
                $total,
                $params['page'],
                $params['per_page'],
            ));
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'password-resets.index');
        }
    }

    /**
     * `POST /master-admin/api/password-resets/{request_id}/approve`
     *
     * The answer is the service's own result dict, verbatim — including the
     * failure shapes, which are HTTP 200 envelopes rather than errors:
     *
     * * no `HASTAMA_HMAC_SECRET` → `{"success": false, "message": …,
     *   "code_unavailable": true}` — the flow fails closed rather than issuing
     *   a code with no integrity key;
     * * an unknown or already-processed request → `{"success": false,
     *   "message": "درخواست یافت نشد یا قبلاً پردازش شده است."}`.
     *
     * The `admin_actions` row is written only when a code was actually
     * issued.
     */
    public function approve(string $requestId): JsonResponse
    {
        try {
            $result = $this->recovery->approve($requestId, $this->adminUsername());

            if ($result['success'] ?? false) {
                $this->audit->adminAction($this->adminUsername(), 'approve_password_reset', [
                    'target_type' => 'password_reset',
                    'target_id' => $requestId,
                    'description' => 'تأیید درخواست بازیابی رمز عبور',
                    'ip_address' => ClientAddress::for(request()),
                ]);
            }

            return $this->ok($result);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'password-resets.approve');
        }
    }

    /**
     * `POST /master-admin/api/password-resets/{request_id}/reject`
     *
     * `{"success": bool}` — false for an unknown or already-processed request,
     * and again no audit row is written for a rejection that rejected nothing.
     */
    public function reject(string $requestId): JsonResponse
    {
        try {
            $rejected = $this->recovery->reject($requestId, $this->adminUsername());

            if ($rejected) {
                $this->audit->adminAction($this->adminUsername(), 'reject_password_reset', [
                    'target_type' => 'password_reset',
                    'target_id' => $requestId,
                    'description' => 'رد درخواست بازیابی رمز عبور',
                    'ip_address' => ClientAddress::for(request()),
                ]);
            }

            return $this->ok(['success' => $rejected]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'password-resets.reject');
        }
    }

    /**
     * `DELETE /master-admin/api/password-resets/{request_id}`
     *
     * Removes the request row — including the stored code digest, so a deleted
     * request can never be approved after the fact.
     */
    public function destroy(string $requestId): JsonResponse
    {
        try {
            $deleted = DB::delete('DELETE FROM password_reset_requests WHERE request_id = ?', [$requestId]) > 0;

            if ($deleted) {
                $this->audit->adminAction($this->adminUsername(), 'delete_password_reset', [
                    'target_type' => 'password_reset',
                    'target_id' => $requestId,
                    'description' => 'حذف رکورد درخواست بازیابی رمز عبور',
                    'ip_address' => ClientAddress::for(request()),
                ]);
            }

            return $this->ok(['success' => $deleted]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'password-resets.destroy');
        }
    }
}
