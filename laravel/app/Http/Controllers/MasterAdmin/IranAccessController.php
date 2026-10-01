<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Services\Audit\AuditLogger;
use App\Support\Connectivity\IranAccessException;
use App\Support\Connectivity\IranAccessService;
use App\Support\Http\ClientAddress;
use App\Support\Http\MasterAdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The master-admin Iran-only access surface.
 *
 * Ported from `get_iran_access`, `set_iran_access`, `check_iran_access`,
 * `refresh_iran_access` and `reset_iran_access_counters` in
 * `app/api/routes/master_admin.py`.
 *
 * The verdict is made offline from the range file shipped with the
 * application.  The refresh endpoint downloads from the RIPE and APNIC
 * registries — the only network call in the whole feature, and it is never
 * automatic.
 */
final class IranAccessController extends MasterAdminController
{
    use MasterAdminActor;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * `GET /master-admin/api/iran-access`
     */
    public function index(): JsonResponse
    {
        return $this->ok(['success' => true, 'data' => IranAccessService::status()]);
    }

    /**
     * `POST /master-admin/api/iran-access`
     *
     * The body is a JSON object with the filter settings.  The settings are
     * validated, persisted and applied immediately.
     */
    public function store(Request $request): JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $this->detail(400, 'بدنه درخواست نامعتبر است.');
        }

        try {
            $status = IranAccessService::applySettings($decoded, $this->adminUsername());
        } catch (IranAccessException $exception) {
            return $this->detail(400, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'iran-access.store');
        }

        $this->audit->adminAction($this->adminUsername(), 'update_iran_only_settings', [
            'target_type' => 'system_config',
            'target_id' => IranAccessService::ENABLED_KEY,
            'description' => 'تنظیمات «فقط آی‌پی ایران»: '
                .(($status['enabled'] ?? false) ? 'فعال' : 'غیرفعال')
                .(! ($status['list_loaded'] ?? false) ? ' (فهرست آی‌پی بارگذاری نشده)' : ''),
            'after_data' => [
                'enabled' => $status['enabled'] ?? false,
                'enforcing' => $status['enforcing'] ?? false,
                'log_blocked' => $status['log_blocked'] ?? false,
                'ranges_ipv4' => $status['ranges_ipv4'] ?? 0,
                'ranges_ipv6' => $status['ranges_ipv6'] ?? 0,
            ],
            'ip_address' => ClientAddress::for(request()),
        ]);

        return $this->ok(['success' => true, 'data' => $status]);
    }

    /**
     * `POST /master-admin/api/iran-access/check`
     *
     * Tests an address (or the caller's own) against the current range list.
     */
    public function check(Request $request): JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            $decoded = [];
        }

        $ip = isset($decoded['ip']) && is_string($decoded['ip'])
            ? mb_substr(trim($decoded['ip']), 0, 64)
            : '';

        if ($ip === '') {
            $ip = ClientAddress::for($request);
        }

        $verdict = IranAccessService::checkIp($ip);

        $this->audit->adminAction($this->adminUsername(), 'iran_only_test', [
            'target_type' => 'ip_address',
            'target_id' => mb_substr((string) ($verdict['ip'] ?? ''), 0, 45),
            'description' => 'تست آی‌پی «'.($verdict['ip'] ?? '-').'»: '.($verdict['label'] ?? '-')
                .(($verdict['blocked'] ?? false) ? ' — ورود مسدود می‌شود' : ' — ورود مجاز است'),
            'after_data' => [
                'ip' => $verdict['ip'] ?? '',
                'kind' => $verdict['kind'] ?? '',
                'blocked' => $verdict['blocked'] ?? false,
                'range' => $verdict['range'] ?? '',
                'enforcing' => $verdict['enforcing'] ?? false,
            ],
            'ip_address' => ClientAddress::for($request),
        ]);

        return $this->ok(['success' => true, 'data' => $verdict]);
    }

    /**
     * `POST /master-admin/api/iran-access/refresh`
     *
     * Rebuilds the Iranian range list from the RIPE and APNIC registries.
     */
    public function refresh(): JsonResponse
    {
        try {
            $status = IranAccessService::refresh();
        } catch (IranAccessException $exception) {
            return $this->detail(502, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'iran-access.refresh');
        }

        $this->audit->adminAction($this->adminUsername(), 'iran_only_list_refresh', [
            'target_type' => 'system_config',
            'target_id' => IranAccessService::ENABLED_KEY,
            'description' => 'به‌روزرسانی فهرست آی‌پی ایران: '
                .($status['ranges_ipv4'] ?? 0).' بازهٔ IPv4 و '.($status['ranges_ipv6'] ?? 0).' بازهٔ IPv6',
            'after_data' => [
                'ranges_ipv4' => $status['ranges_ipv4'] ?? 0,
                'ranges_ipv6' => $status['ranges_ipv6'] ?? 0,
                'generated' => $status['list_generated'] ?? '',
                'sources_failed' => $status['sources_failed'] ?? [],
            ],
            'ip_address' => ClientAddress::for(request()),
        ]);

        return $this->ok(['success' => true, 'data' => $status]);
    }

    /**
     * `POST /master-admin/api/iran-access/counters/reset`
     *
     * Clears the blocked/allowed counters shown on the card.
     */
    public function resetCounters(): JsonResponse
    {
        $status = IranAccessService::resetCounters();

        $this->audit->adminAction($this->adminUsername(), 'iran_only_counters_reset', [
            'target_type' => 'system_config',
            'target_id' => IranAccessService::ENABLED_KEY,
            'description' => 'صفر کردن شمارندهٔ ورودهای مسدودشده (فقط آمار، بدون تغییر تنظیمات)',
            'after_data' => ['blocked_count' => 0, 'allowed_count' => 0],
            'ip_address' => ClientAddress::for(request()),
        ]);

        return $this->ok(['success' => true, 'data' => $status]);
    }

    private function detail(int $status, string $message): JsonResponse
    {
        return response()->json(['detail' => $message], $status);
    }
}
