<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Support\Legacy\LegacyPagination;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * User directory reads for the control centre.
 *
 * The column list is written out in both endpoints rather than being `SELECT *`.
 * That is not stylistic: `user_table` carries `password` (plaintext for fifteen of
 * sixteen rows on this installation) and `password_hash`, and `SELECT *` would
 * publish both to the admin UI and into any log that captured the response.  The
 * Python endpoints were already explicit about this, and the detail endpoint's
 * comment says so.
 */
final class UserController extends MasterAdminController
{
    /**
     * The exactly-thirteen columns both user endpoints select.
     *
     * Kept as one constant so the list can never drift between the list and the
     * detail view — a field present in one and missing from the other is a bug the
     * UI finds only after a click.
     */
    private const USER_COLUMNS = 'id, username, name, last_name, department, role, work_hours,
                       substitute, hozoor_num, is_active, last_login, failed_login_count,
                       password_changed_at';

    /** `GET /master-admin/api/users` */
    public function index(Request $request): JsonResponse
    {
        // The Python signature, declared as such.  `LegacyQuery` rather than
        // `$request->validate()` so a rejection carries FastAPI's 422 body: Laravel
        // answers its own validation failure with a 302 redirect for a request that
        // does not send `Accept: application/json`, and the existing front-end does
        // not — it reads the status code.
        $params = LegacyQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 50, ge: 1, le: 200),
            'search' => LegacyQuery::nullableString(),
            'role' => LegacyQuery::nullableString(),
            'status' => LegacyQuery::nullableString(),
        ]);

        $page = $params['page'];
        $perPage = $params['per_page'];

        try {
            $where = [];
            $bindings = [];

            if (($search = $this->nonEmpty($params['search'])) !== null) {
                $where[] = '(username LIKE ? OR name LIKE ? OR last_name LIKE ?)';
                $needle = '%'.$search.'%';
                $bindings = array_merge($bindings, [$needle, $needle, $needle]);
            }

            if (($role = $this->nonEmpty($params['role'])) !== null) {
                // Lower-cased by the caller, exactly as `role.lower()` did, because the
                // stored value is space-padded and mixed case.
                $where[] = 'LTRIM(RTRIM(LOWER(role))) = ?';
                $bindings[] = mb_strtolower($role);
            }

            if (($status = $this->nonEmpty($params['status'])) !== null) {
                $where[] = "ISNULL(is_active,'active') = ?";
                $bindings[] = $status;
            }

            $clause = $where === [] ? '' : ' WHERE '.implode(' AND ', $where);
            $connection = DB::connection();

            $total = (int) $connection->selectOne(
                "SELECT COUNT(*) AS total FROM user_table{$clause}",
                $bindings
            )->total;

            $rows = $connection->select(
                'SELECT '.self::USER_COLUMNS."
                 FROM user_table{$clause}
                 ORDER BY id OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
                array_merge($bindings, [LegacyPagination::offset($page, $perPage), $perPage])
            );

            return $this->ok(LegacyPagination::envelope(
                LegacySerializer::rows('user_table', $rows),
                $total,
                $page,
                $perPage,
            ));
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'users.index');
        }
    }

    /**
     * `GET /master-admin/api/users/{username}` — the detail view.
     *
     * Three additional collections are attached: the user's recent audit trail, their
     * session history and their password-reset history.  Two omissions are
     * deliberate and documented in the Python source, and both are security
     * decisions rather than oversights:
     *
     * * the session rows carry **no `session_key`** — the opaque key is only needed
     *   by the session list, where the terminate action lives, and returning it here
     *   would hand the caller the ability to impersonate;
     * * the reset rows carry **no `recovery_code`** — that is the digest that
     *   authorises a password reset.
     */
    public function show(string $username): JsonResponse
    {
        $needle = trim($username);

        try {
            $connection = DB::connection();

            $row = $connection->selectOne(
                'SELECT '.self::USER_COLUMNS.'
                 FROM user_table WHERE LTRIM(RTRIM(username)) = ?',
                [$needle]
            );

            if ($row === null) {
                return $this->notFound('کاربر یافت نشد.');
            }

            $user = LegacySerializer::row('user_table', $row);

            $user['recent_audit'] = LegacySerializer::rows('audit_logs', $connection->select(
                'SELECT TOP 50 event_id, event_type, action, module, resource_type,
                        resource_id, status, severity, ip_address, created_at
                 FROM audit_logs WHERE username = ? ORDER BY created_at DESC',
                [$needle]
            ));

            $user['sessions'] = LegacySerializer::rows('user_sessions', $connection->select(
                'SELECT TOP 20 id, username, ip_address, user_agent, login_at,
                        last_activity, logout_at, is_active, terminated_by
                 FROM user_sessions WHERE username = ? ORDER BY login_at DESC',
                [$needle]
            ));

            $user['password_resets'] = LegacySerializer::rows('password_reset_requests', $connection->select(
                'SELECT TOP 10 request_id, username, ip_address, status, code_attempts,
                        max_attempts, approved_by, approved_at, completed_at, created_at
                 FROM password_reset_requests WHERE username = ? ORDER BY created_at DESC',
                [$needle]
            ));

            return $this->ok(['success' => true, 'data' => $user]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'users.show');
        }
    }

    /**
     * The Python `if search:` test — an empty string is falsy, so it adds no filter.
     *
     * Deliberately **not** trimmed.  `if search:` treats `" "` (a single space) as a
     * filter and searches for `% %`; trimming here would silently turn that into "no
     * filter at all" and answer with the whole table.  Only the exact empty string is
     * dropped, which is also the difference between a working dashboard and one that
     * returns every user when the search box is cleared.
     */
    private function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
