<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRegistry;
use App\Support\Http\ClientAddress;
use App\Support\Http\MasterAdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Account administration: enable/disable and role change.
 *
 * Ported from `toggle_user_status` and `change_user_role` in
 * `app/api/routes/master_admin.py`.
 *
 * The security half of both is the session revocation, and the asymmetry
 * between them is deliberate in the Python:
 *
 * * **disabling** an account revokes the sessions it already holds — a signed
 *   cookie cannot be revoked otherwise, so without this a disabled user would
 *   stay signed in until their cookie expired;
 * * **changing a role** revokes in *both* directions, because a session issued
 *   for the old role must not keep that privilege set alive;
 * * **enabling** an account revokes nothing.
 *
 * Both write an `admin_actions` row carrying the before/after snapshot, and
 * both answer with the number of sessions revoked — the control centre renders
 * that number, so it is part of the response contract rather than a log detail.
 */
final class UserActionController extends MasterAdminController
{
    use MasterAdminActor;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SessionRegistry $sessions,
    ) {}

    /**
     * `POST /master-admin/api/users/{username}/toggle-status`
     *
     * The Python read `is_active` and toggled it: anything that is not exactly
     * `'active'` becomes `'active`, and `'active'` becomes `'disabled'`.  The
     * `or "active"` in `current = row[0] or "active"` means a NULL or empty
     * column reads as active — but note it is a string comparison, so a stored
     * `'0'` is *not* active and toggles to `'active'`.
     */
    public function toggleStatus(string $username): JsonResponse
    {
        $needle = trim($username);

        try {
            $row = DB::selectOne(
                'SELECT is_active FROM user_table WHERE LTRIM(RTRIM(username)) = ?',
                [$needle]
            );

            if ($row === null) {
                return $this->notFound('کاربر یافت نشد.');
            }

            // `row[0] or "active"` — only NULL and the empty string fall back.
            $current = ($row->is_active !== null && $row->is_active !== '')
                ? (string) $row->is_active
                : 'active';
            $newStatus = $current === 'active' ? 'disabled' : 'active';

            DB::update(
                'UPDATE user_table SET is_active = ? WHERE LTRIM(RTRIM(username)) = ?',
                [$newStatus, $needle]
            );

            $revoked = 0;

            if ($newStatus !== 'active') {
                $revoked = $this->sessions->revokeUserSessions($needle, $this->adminUsername());
            }

            $this->audit->adminAction($this->adminUsername(), 'toggle_user_status', [
                'target_username' => $needle,
                'target_type' => 'user',
                'description' => "تغییر وضعیت به {$newStatus}",
                'before_data' => ['is_active' => $current],
                'after_data' => ['is_active' => $newStatus, 'sessions_revoked' => $revoked],
                'ip_address' => ClientAddress::for(request()),
            ]);

            return $this->ok([
                'success' => true,
                'new_status' => $newStatus,
                'sessions_revoked' => $revoked,
            ]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'users.toggle-status');
        }
    }

    /**
     * `POST /master-admin/api/users/{username}/change-role`
     *
     * The role is validated before the database is touched, and the validation
     * is the Python's: `str(data.get("role", "")).strip().lower()` must be
     * exactly `user` or `admin`.  A missing key, a null, a number or an object
     * all become their `str()` form first — `None` → `"None"` — and are
     * rejected with the one message.
     *
     * The audit description quotes the **raw** stored role, `nchar(10)`
     * padding and all: `f"تغییر نقش از {old_role} به {new_role}"`.
     */
    public function changeRole(string $username, Request $request): JsonResponse
    {
        $needle = trim($username);

        $decoded = json_decode((string) $request->getContent(), true);

        // The Python's `await request.json()` ran outside the `try`; a body that
        // is not a JSON object made `data.get` raise and answered a 500.
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('The request body is not a JSON object.');
        }

        $newRole = array_key_exists('role', $decoded)
            ? mb_strtolower(trim($this->pythonStr($decoded['role'])), 'UTF-8')
            : '';

        if (! in_array($newRole, ['user', 'admin'], true)) {
            return response()->json(['detail' => 'نقش معتبر نیست.'], 400);
        }

        try {
            $row = DB::selectOne(
                'SELECT role FROM user_table WHERE LTRIM(RTRIM(username)) = ?',
                [$needle]
            );

            if ($row === null) {
                return $this->notFound('کاربر یافت نشد.');
            }

            $oldRole = $row->role;

            DB::update(
                'UPDATE user_table SET role = ? WHERE LTRIM(RTRIM(username)) = ?',
                [$newRole, $needle]
            );

            // A role change revokes in both directions — unlike a disable, which
            // only cuts sessions that exist to be cut.
            $revoked = $this->sessions->revokeUserSessions($needle, $this->adminUsername());

            $this->audit->adminAction($this->adminUsername(), 'change_role', [
                'target_username' => $needle,
                'target_type' => 'user',
                'description' => "تغییر نقش از {$oldRole} به {$newRole}",
                'before_data' => ['role' => $oldRole],
                'after_data' => ['role' => $newRole, 'sessions_revoked' => $revoked],
                'ip_address' => ClientAddress::for(request()),
            ]);

            return $this->ok(['success' => true, 'sessions_revoked' => $revoked]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'users.change-role');
        }
    }

    /**
     * Python's `str(value)` for the scalars a JSON body can carry.
     *
     * Only the emptiness and the exact characters are observable (the role
     * comparison), so a non-empty placeholder for arrays and objects is
     * faithful where it matters.
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
