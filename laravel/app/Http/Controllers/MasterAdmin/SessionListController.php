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
 * The session registry list — who is signed in, from where, and for how long.
 *
 * Named `SessionListController` rather than `SessionController` because the
 * authentication module already owns `App\Http\Controllers\Auth\SessionController`
 * (the CSRF/config/bootstrap endpoints) and two classes answering to
 * `SessionController` in one application is a routing bug waiting to happen.
 *
 * This list is the one place `session_key` **is** published, because it is the
 * handle the terminate action uses.  The user-detail view deliberately omits it;
 * see `UserController::show()`.
 */
final class SessionListController extends MasterAdminController
{
    /** `GET /master-admin/api/sessions` */
    public function index(Request $request): JsonResponse
    {
        // The Python signature, declared as such:
        //   page=Query(1, ge=1), per_page=Query(50, ge=1, le=200),
        //   active_only: bool = False, username: Optional[str] = None
        //
        // `active_only` is deliberately **not** `$request->boolean()`.  That helper is
        // `FILTER_VALIDATE_BOOLEAN`, which silently answers `false` for
        // `?active_only=2` — where FastAPI rejects the request — and rejects `t` and
        // `y`, which FastAPI accepts.  `LegacyQuery::bool()` reproduces pydantic's six
        // spellings in each direction, so `?active_only=` (the commonest accident, an
        // empty value) is a 422 here exactly as it is on the running server.
        $params = LegacyQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 50, ge: 1, le: 200),
            'active_only' => LegacyQuery::bool(),
            'username' => LegacyQuery::nullableString(),
        ]);

        $page = $params['page'];
        $perPage = $params['per_page'];

        try {
            $where = [];
            $bindings = [];

            if ($params['active_only']) {
                $where[] = 'is_active = 1';
            }

            if (($username = $this->nonEmpty($params['username'])) !== null) {
                $where[] = 'username LIKE ?';
                $bindings[] = '%'.$username.'%';
            }

            $clause = $where === [] ? '' : ' WHERE '.implode(' AND ', $where);
            $connection = DB::connection();

            $total = (int) $connection->selectOne(
                "SELECT COUNT(*) AS total FROM user_sessions{$clause}",
                $bindings
            )->total;

            $rows = $connection->select(
                "SELECT id, session_key, username, ip_address, user_agent, login_at,
                        last_activity, logout_at, is_active, terminated_by
                 FROM user_sessions{$clause} ORDER BY login_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
                array_merge($bindings, [LegacyPagination::offset($page, $perPage), $perPage])
            );

            return $this->ok(LegacyPagination::envelope(
                LegacySerializer::rows('user_sessions', $rows),
                $total,
                $page,
                $perPage,
            ));
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'sessions.index');
        }
    }

    /** See `UserController::nonEmpty()` for why the value is not trimmed. */
    private function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
