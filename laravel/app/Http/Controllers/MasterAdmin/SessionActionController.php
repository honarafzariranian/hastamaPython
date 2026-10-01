<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRegistry;
use App\Support\Http\ClientAddress;
use App\Support\Http\MasterAdminActor;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Session administration: terminate one, delete one, terminate all, delete all.
 *
 * Ported from `terminate_user_session`, `delete_user_session`,
 * `terminate_all_sessions` and `delete_all_sessions` in
 * `app/api/routes/master_admin.py`.
 *
 * The two pairs are easy to confuse and the difference is the whole point of
 * the registry table:
 *
 * * **terminate** flips `is_active` to 0 and stamps `logout_at` /
 *   `terminated_by` — the login history survives for the audit trail, and the
 *   cookie dies at its next request;
 * **delete** removes the row outright — the history is gone, and because the
 *   registry is what makes a signed cookie revocable, every browser holding
 *   that session is logged out at its next request.
 *
 * `terminate-all` keeps the master-administrator accounts (the same list
 * outage mode uses) so the operator who pressed the button keeps the control
 * plane they would need to undo it; `delete-all` exempts nobody, which is the
 * deliberate difference.
 */
final class SessionActionController extends MasterAdminController
{
    use MasterAdminActor;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SessionRegistry $sessions,
    ) {}

    /**
     * `POST /master-admin/api/sessions/{session_key}/terminate`
     *
     * The forced logout.  `{"success": false}` — status 200 — when the key is
     * unknown or was already closed, and no `admin_actions` row is written for
     * a termination that terminated nothing.
     */
    public function terminate(string $sessionKey): JsonResponse
    {
        try {
            $revoked = $this->sessions->revoke($sessionKey, $this->adminUsername());

            if ($revoked) {
                $this->audit->adminAction($this->adminUsername(), 'terminate_session', [
                    'target_type' => 'session',
                    'target_id' => $sessionKey,
                    'description' => 'خاتمه اجباری نشست',
                    'ip_address' => ClientAddress::for(request()),
                ]);
            }

            return $this->ok(['success' => $revoked]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'sessions.terminate');
        }
    }

    /**
     * `DELETE /master-admin/api/sessions/{session_key}`
     *
     * Removes the registry row — and with it the login history for that
     * session.  Same `{"success": bool}` shape, same audit-only-on-success.
     */
    public function destroy(string $sessionKey): JsonResponse
    {
        try {
            $deleted = $this->sessions->delete($sessionKey);

            if ($deleted) {
                $this->audit->adminAction($this->adminUsername(), 'delete_session_record', [
                    'target_type' => 'session',
                    'target_id' => $sessionKey,
                    'description' => 'حذف رکورد نشست',
                    'ip_address' => ClientAddress::for(request()),
                ]);
            }

            return $this->ok(['success' => $deleted]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'sessions.destroy');
        }
    }

    /**
     * `POST /master-admin/api/sessions/terminate-all`
     *
     * The *خاتمه همه نشست‌ها* button.  The kept accounts are
     * `MASTER_ADMIN_USERNAMES` — read from the same config the Python's
     * `master_admin_usernames()` read the environment variable for — and they
     * are echoed back in the response so the operator can see who stayed
     * signed in.
     */
    public function terminateAll(): JsonResponse
    {
        try {
            // The config already trims and drops empties.  The response echoes
            // these names verbatim — the Python's `list(keep)` — while
            // `revokeAllSessions` does its own lower-casing for the SQL.
            $keep = array_values(array_map(
                static fn (mixed $name): string => (string) $name,
                (array) config('hastama.master_admin_usernames', ['ali']),
            ));

            $terminated = $this->sessions->revokeAllSessions($this->adminUsername(), $keep);

            $this->audit->adminAction($this->adminUsername(), 'terminate_all_sessions', [
                'target_type' => 'session',
                'target_id' => '*',
                'description' => "خاتمه گروهی همه نشست‌های فعال ({$terminated} نشست)",
                'after_data' => ['terminated' => $terminated, 'kept_usernames' => $keep],
                'ip_address' => ClientAddress::for(request()),
            ]);

            return $this->ok([
                'success' => true,
                'terminated' => $terminated,
                'kept_usernames' => $keep,
            ]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'sessions.terminate-all');
        }
    }

    /**
     * `DELETE /master-admin/api/sessions`
     *
     * The *حذف همه رکوردها* button — destructive and not reversible.  No
     * account is exempted, and the audit row carries the number of removed
     * rows.
     */
    public function destroyAll(): JsonResponse
    {
        try {
            $deleted = $this->sessions->deleteAll();

            $this->audit->adminAction($this->adminUsername(), 'delete_all_session_records', [
                'target_type' => 'session',
                'target_id' => '*',
                'description' => "حذف گروهی همه رکوردهای نشست ({$deleted} رکورد)",
                'after_data' => ['deleted' => $deleted],
                'ip_address' => ClientAddress::for(request()),
            ]);

            return $this->ok(['success' => true, 'deleted' => $deleted]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'sessions.destroy-all');
        }
    }
}
