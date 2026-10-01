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
 * The audit trail: filtered list plus single-event detail.
 *
 * The filter set is wide (eleven optional predicates) and every one of them is
 * bound, never interpolated.  The only text that reaches the SQL string is the
 * `WHERE` keyword itself; the Python implementation took the same care and it is
 * worth keeping, because `audit_logs` is the table an attacker would most like to
 * query with a hand-built clause.
 */
final class AuditLogController extends MasterAdminController
{
    /** The fourteen columns the list view selects. */
    private const LIST_COLUMNS = 'event_id, event_type, action, username, role, module, resource_type,
                       resource_id, request_id, session_id, ip_address, status, severity, created_at';

    /**
     * The nineteen columns the detail view selects.
     *
     * The extra five are the ones too large for a list: `user_agent`, the
     * `before_data`/`after_data`/`metadata` JSON blobs, and `error_id`.
     */
    private const DETAIL_COLUMNS = 'event_id, event_type, action, username, role, module, resource_type,
                      resource_id, request_id, session_id, ip_address, user_agent, status,
                      severity, before_data, after_data, metadata, error_id, created_at';

    /** `GET /master-admin/api/audit-logs` */
    public function index(Request $request): JsonResponse
    {
        // The Python signature, declared as such — two bounded integers and ten
        // `Optional[str] = None` filters.  Declared rather than rule-stringed so a
        // rejection is FastAPI's 422 body instead of Laravel's 302 redirect.
        $params = LegacyQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 50, ge: 1, le: 200),
            'event_type' => LegacyQuery::nullableString(),
            'action' => LegacyQuery::nullableString(),
            'username' => LegacyQuery::nullableString(),
            'module' => LegacyQuery::nullableString(),
            'severity' => LegacyQuery::nullableString(),
            'status' => LegacyQuery::nullableString(),
            'date_from' => LegacyQuery::nullableString(),
            'date_to' => LegacyQuery::nullableString(),
            'request_id' => LegacyQuery::nullableString(),
            'search' => LegacyQuery::nullableString(),
        ]);

        $page = $params['page'];
        $perPage = $params['per_page'];

        try {
            $where = [];
            $bindings = [];

            // Exact-match filters.  Order matters only for readability, but the
            // bindings must stay in the same order as the predicates — each block
            // appends both together so they cannot drift.
            foreach (['event_type', 'action', 'module', 'severity', 'status', 'request_id'] as $column) {
                $value = $this->nonEmpty($params[$column]);

                if ($value !== null) {
                    $where[] = "{$column} = ?";
                    $bindings[] = $value;
                }
            }

            // `username` is a LIKE, unlike the others — the UI's filter box is a
            // partial-match field even though every other filter is a dropdown.
            if (($username = $this->nonEmpty($params['username'])) !== null) {
                $where[] = 'username LIKE ?';
                $bindings[] = '%'.$username.'%';
            }

            // Date bounds are compared against the stored `datetime2`, so the values
            // must already be *strings the driver can send for a datetime* — the
            // Python code passed `date_from`/`date_to` through untouched and the
            // front-end supplies `YYYY-MM-DD`.  Bound as strings for the same reason.
            foreach (['date_from' => 'created_at >= ?', 'date_to' => 'created_at <= ?'] as $key => $predicate) {
                $value = $this->nonEmpty($params[$key]);

                if ($value !== null) {
                    $where[] = $predicate;
                    $bindings[] = $value;
                }
            }

            if (($search = $this->nonEmpty($params['search'])) !== null) {
                $where[] = '(username LIKE ? OR module LIKE ? OR action LIKE ? OR event_id LIKE ?)';
                $needle = '%'.$search.'%';
                $bindings = array_merge($bindings, [$needle, $needle, $needle, $needle]);
            }

            $clause = $where === [] ? '' : ' WHERE '.implode(' AND ', $where);
            $connection = DB::connection();

            $total = (int) $connection->selectOne(
                "SELECT COUNT(*) AS total FROM audit_logs{$clause}",
                $bindings
            )->total;

            $rows = $connection->select(
                'SELECT '.self::LIST_COLUMNS."
                 FROM audit_logs{$clause} ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
                array_merge($bindings, [LegacyPagination::offset($page, $perPage), $perPage])
            );

            return $this->ok(LegacyPagination::envelope(
                LegacySerializer::rows('audit_logs', $rows),
                $total,
                $page,
                $perPage,
            ));
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'audit-logs.index');
        }
    }

    /**
     * `GET /master-admin/api/audit-logs/{event_id}` — one event, in full.
     *
     * **This endpoint is fixed, and the fix is deliberate.**
     *
     * The Python implementation reads the row and then immediately asks the *same
     * exhausted cursor* for all rows:
     *
     *     row = cur.fetchone()
     *     if not row: raise HTTPException(404, ...)
     *     return JSONResponse({"success": True, "data": _serialize(_dict_rows(cur)[0] ...)})
     *
     * `cur.fetchall()` on a cursor that has already returned its only row yields an
     * empty list, so `[0]` raises `IndexError`, which the surrounding
     * `except Exception` turns into HTTP 500.  Reproduced against the live database
     * before writing this: `RAISED IndexError: list index out of range`, on a row
     * that exists.  The endpoint has therefore never returned an event; it always
     * answered `{"success": false, "message": "خطای داخلی سرور"}` with status 500.
     *
     * Reproducing a guaranteed crash is not "preserving functionality", so this
     * returns the event instead.  Every observable that the front-end could depend on
     * is unchanged: the same 404 for a missing event, the same body shape, the same
     * status codes.  The divergence — and the fact that a client which had learned to
     * treat 500 as "no detail available" now gets data — is recorded in
     * `MIGRATION_STATUS.md` and `docs/migration/OPEN_QUESTIONS.md`.
     */
    public function show(string $eventId): JsonResponse
    {
        try {
            $row = DB::connection()->selectOne(
                'SELECT '.self::DETAIL_COLUMNS.' FROM audit_logs WHERE event_id = ?',
                [$eventId]
            );

            if ($row === null) {
                return $this->notFound('رویداد یافت نشد.');
            }

            return $this->ok([
                'success' => true,
                'data' => LegacySerializer::row('audit_logs', $row),
            ]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'audit-logs.show');
        }
    }

    /**
     * The Python truthiness test on an optional query string.
     *
     * Untrimmed on purpose — see the same helper in `UserController`.  A filter of
     * `action=` (empty) must add no predicate rather than being compared against the
     * empty string, and `action=%20` must be compared against a single space.
     */
    private function nonEmpty(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
