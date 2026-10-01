<?php

namespace App\Http\Controllers\MasterAdmin;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `GET /master-admin/api/system-health` — the control centre's status panel.
 *
 * Three independent probes, each with its own failure answer, and the shape is a
 * deliberate departure from the rest of the module:
 *
 * * the endpoint itself always answers **200 with `success: true`**, even when the
 *   database probe failed.  A health panel that 500s when something is unhealthy is
 *   the one thing it must not do.
 * * each probe reports `-1` (or a `status`/`message` pair) on failure rather than
 *   omitting the key, so the UI can render "unknown" instead of "no data".
 *
 * The Python implementation opened a **new connection per probe**; that is not
 * reproduced, because Laravel reuses one connection per request and reconnecting
 * would not change the answer.  What is reproduced is the per-probe isolation: a
 * failure in one does not stop the others.
 */
final class SystemHealthController extends MasterAdminController
{
    /** `GET /master-admin/api/system-health` */
    public function show(): JsonResponse
    {
        $health = [];
        $connection = DB::connection();

        // Database round trip and latency.  `SELECT 1` is the cheapest statement that
        // still proves the connection can execute one.
        try {
            $startedAt = CarbonImmutable::now('UTC');
            $connection->selectOne('SELECT 1');
            $latency = $startedAt->diffInMilliseconds(CarbonImmutable::now('UTC'), true);

            $health['database'] = [
                'status' => 'healthy',
                'latency_ms' => round($latency, 1),
            ];
        } catch (Throwable $exception) {
            Log::error('system-health database probe failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $health['database'] = [
                'status' => 'error',
                'message' => 'خطای اتصال به پایگاه داده',
            ];
        }

        $health['active_sessions'] = $this->count(
            'SELECT COUNT(*) AS total FROM user_sessions WHERE is_active = 1'
        );

        $todayStart = CarbonImmutable::now('UTC')->startOfDay()->format('Y-m-d H:i:s');

        $health['audit_events_today'] = $this->count(
            'SELECT COUNT(*) AS total FROM audit_logs WHERE created_at >= ?',
            [$todayStart]
        );

        // `datetime.now(timezone.utc).isoformat()` — six fractional digits and an
        // explicit `+00:00`, which is what the UI shows and what distinguishes this
        // field from every `created_at` in the rest of the module.
        $health['server_time_utc'] = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s.uP');

        return $this->ok(['success' => true, 'data' => $health]);
    }

    /**
     * A count, or `-1` when it cannot be read.
     *
     * `-1` is the Python sentinel and it is load-bearing: `0` means "there are none",
     * and the UI renders the two differently.
     */
    private function count(string $sql, array $bindings = []): int
    {
        try {
            return (int) DB::connection()->selectOne($sql, $bindings)->total;
        } catch (Throwable $exception) {
            Log::warning('system-health probe failed', [
                'sql' => $sql,
                'exception' => $exception::class,
            ]);

            return -1;
        }
    }
}
