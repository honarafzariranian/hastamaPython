<?php

namespace App\Http\Controllers;

use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Health and database-connectivity probe.
 *
 * This is the migration's owned equivalent of the Python application's
 * ``GET /health`` and ``GET /health/database``: a real check against the real
 * SQL Server instance, not a hard-coded "ok".  It is deliberately safe to
 * expose, because it reports only whether the dependencies answer — never a
 * connection string, a credential or a driver message.
 */
final class HealthController extends Controller
{
    /**
     * Supervision probe — preserves the existing operator contract.
     *
     * The Windows watchdog and the public monitor both require
     * `GET /health` to answer 200, and the current FastAPI implementation
     * returns exactly `{"status":"ok"}` WITHOUT touching the database.  That
     * separation is deliberate and documented in watchdog_server.ps1 ("the
     * port is listening is NOT a health condition", and a health probe that
     * depends on the database would report an application outage as a
     * database outage).  The shape is reproduced verbatim so the supervision
     * scripts need no change at cutover.
     *
     * This route must be registered explicitly: the SPA fallback answers 200
     * for any unknown path, so without it a broken backend would still look
     * healthy to the watchdog.
     */
    public function probe(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Database-layer probe in the existing contract's shape.
     *
     * Mirrors the Python `GET /health/database`: 200 `{"status":"ok"}`, or
     * 503 `{"status":"error"}`.  It carries no detail by design — the script
     * consumer only distinguishes up from down, and the diagnostic goes to the
     * log.
     */
    public function probeDatabase(): JsonResponse
    {
        try {
            DB::connection()->select('SELECT 1');
        } catch (Throwable $exception) {
            Log::error('health.database probe failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return new JsonResponse(['status' => 'error'], 503);
        }

        return new JsonResponse(['status' => 'ok']);
    }

    /**
     * Application liveness.  Answers 200 whenever PHP is serving, without
     * touching any dependency, so a supervisor can tell "process up" from
     * "database reachable".
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success([
            'status' => 'ok',
            'application' => config('app.name'),
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'locale' => app()->getLocale(),
            'time_utc' => now()->utc()->toIso8601String(),
        ]);
    }

    /**
     * Database connectivity, measured against the existing schema.
     *
     * The table count is included on purpose: reaching the server is not the
     * same as reaching the application's data, and the migration needs to
     * prove the second one.  ``user_table`` (the legacy, space-padded table) is
     * counted separately because a successful count there only happens when
     * identifiers are quoted correctly.
     */
    public function database(): JsonResponse
    {
        $startedAt = microtime(true);

        try {
            $tables = (int) DB::connection()->selectOne(
                "SELECT COUNT(*) AS total FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_TYPE = 'BASE TABLE'"
            )->total;

            $users = (int) DB::connection()->selectOne('SELECT COUNT(*) AS total FROM dbo.user_table')->total;

            $instance = (string) DB::connection()->selectOne(
                "SELECT CAST(SERVERPROPERTY('InstanceName') AS nvarchar(128)) AS instance_name"
            )->instance_name;

            $database = (string) DB::connection()->selectOne('SELECT DB_NAME() AS database_name')->database_name;
        } catch (Throwable $exception) {
            // The operator gets a plain message; the detail goes to the log so
            // a driver or authentication failure stays diagnosable.
            Log::error('health.database failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return ApiResponse::error('اتصال به بانک اطلاعاتی برقرار نیست.', 503, null);
        }

        return ApiResponse::success([
            'status' => 'ok',
            'driver' => DB::connection()->getDriverName(),
            'instance' => $instance,
            'database' => $database,
            'tables' => $tables,
            'user_table_rows' => $users,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
