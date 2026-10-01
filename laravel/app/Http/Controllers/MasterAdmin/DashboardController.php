<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The control-centre dashboard: the summary counters and the recent-activity feed.
 *
 * Both are read-only and both are polled by the admin UI, so they are the two
 * endpoints that must be *fast* — the counters are eleven `COUNT(*)` queries and
 * the activity feed is one `TOP (n)`.
 */
final class DashboardController extends MasterAdminController
{
    /** `GET /master-admin/api/dashboard/stats` */
    public function stats(): JsonResponse
    {
        try {
            $connection = DB::connection();

            // Midnight **UTC**, not midnight in Tehran.  The Python code was explicit
            // about this (`datetime.now(timezone.utc).replace(hour=0, …)`) and it is
            // correct: `audit_logs.created_at` is written by `SYSUTCDATETIME()`, so a
            // local-midnight boundary would count the previous evening's events as
            // today's for three and a half hours.
            //
            // Bound as a string rather than a `Carbon` so the driver sees exactly
            // `2026-09-30 00:00:00`, which is what `pyodbc` sent.
            $todayStart = CarbonImmutable::now('UTC')->startOfDay()->format('Y-m-d H:i:s');

            $count = static fn (string $sql, array $bindings = []): int => (int) $connection->selectOne($sql, $bindings)->total;

            $stats = [];

            $stats['total_users'] = $count('SELECT COUNT(*) AS total FROM user_table');

            // `ISNULL(is_active,'active')`: the column is nullable and a NULL has
            // always meant "active" in this schema.
            $stats['active_users'] = $count(
                "SELECT COUNT(*) AS total FROM user_table WHERE ISNULL(is_active,'active') = 'active'"
            );

            $stats['online_sessions'] = $count(
                'SELECT COUNT(*) AS total FROM user_sessions WHERE is_active = 1'
            );

            $stats['logins_today'] = $count(
                "SELECT COUNT(*) AS total FROM audit_logs
                 WHERE event_type='AUTHENTICATION' AND action='login' AND created_at >= ?",
                [$todayStart]
            );

            $stats['failed_logins_today'] = $count(
                "SELECT COUNT(*) AS total FROM audit_logs
                 WHERE event_type='AUTHENTICATION' AND action='login' AND status='failure' AND created_at >= ?",
                [$todayStart]
            );

            $stats['pending_password_resets'] = $count(
                "SELECT COUNT(*) AS total FROM password_reset_requests WHERE status='pending'"
            );

            $stats['open_security_events'] = $count(
                "SELECT COUNT(*) AS total FROM security_events WHERE status='open'"
            );

            $stats['open_errors'] = $count(
                "SELECT COUNT(*) AS total FROM system_errors WHERE status='open'"
            );

            // The `tickets` table is part of the normalised ticketing schema and is
            // present on this installation, but the Python code still wrapped this one
            // counter in try/except and answered 0 — because on an installation that
            // has not run that migration the whole dashboard would otherwise 500.
            // Preserved, because the counter is informational.
            $stats['open_tickets'] = $this->openTicketCount($connection);

            // `LTRIM(RTRIM(LOWER(role))) = 'admin'` — the role column really does
            // hold `'admin     '`, so the trim is load-bearing.
            $stats['admin_count'] = $count(
                "SELECT COUNT(*) AS total FROM user_table WHERE LTRIM(RTRIM(LOWER(role))) = 'admin'"
            );

            $stats['events_today'] = $count(
                'SELECT COUNT(*) AS total FROM audit_logs WHERE created_at >= ?',
                [$todayStart]
            );

            return $this->ok(['success' => true, 'data' => $stats]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'dashboard.stats');
        }
    }

    /** `GET /master-admin/api/dashboard/activity` */
    public function activity(Request $request): JsonResponse
    {
        // `limit: int = Query(50, ge=1, le=200)`, declared the way FastAPI declared
        // it.  The int type is load-bearing beyond validation: the value is bound into
        // `TOP (?)`, and the SQL Server driver rejects a *string* there — "The number
        // of rows provided for a TOP or FETCH clauses row count parameter must be an
        // integer."  `LegacyQuery` returns a real PHP int, which Laravel binds as
        // `PDO::PARAM_INT`, so the parameterised `TOP` works at all.
        $params = LegacyQuery::validate($request, [
            'limit' => LegacyQuery::int(default: 50, ge: 1, le: 200),
        ]);

        $limit = $params['limit'];

        try {
            $rows = DB::connection()->select(
                'SELECT TOP (?) event_id, event_type, action, username, module,
                        resource_type, resource_id, status, severity, created_at, ip_address
                 FROM audit_logs ORDER BY created_at DESC',
                [$limit]
            );

            return $this->ok([
                'success' => true,
                'data' => LegacySerializer::rows('audit_logs', $rows),
            ]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'dashboard.activity');
        }
    }

    /**
     * Open ticket count, or `0` when the ticketing tables are not installed.
     *
     * Split out so the `try`/`catch` cannot be mistaken for swallowing an error on
     * the whole dashboard.
     */
    private function openTicketCount(Connection $connection): int
    {
        try {
            return (int) $connection->selectOne(
                "SELECT COUNT(*) AS total FROM tickets WHERE status NOT IN ('resolved','closed')"
            )->total;
        } catch (Throwable $exception) {
            Log::debug('open ticket count unavailable', ['exception' => $exception::class]);

            return 0;
        }
    }
}
