<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Services\Audit\AuditLogger;
use App\Support\Connectivity\OutageException;
use App\Support\Connectivity\OutageService;
use App\Support\Http\ClientAddress;
use App\Support\Http\MasterAdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The master-admin internet outage surface.
 *
 * Ported from `get_outage`, `set_outage`, `check_outage` and
 * `set_outage_manual` in `app/api/routes/master_admin.py`.
 *
 * The Python ran an `asyncio` monitor loop inside the uvicorn process.  A PHP
 * request process cannot hold one open, so the monitor is not reproduced —
 * the state machine, the probe and the settings are fully ported.  The probe
 * is a real TCP connection attempt, exactly as the Python wrote it.
 */
final class OutageController extends MasterAdminController
{
    use MasterAdminActor;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * `GET /master-admin/api/outage`
     */
    public function index(): JsonResponse
    {
        return $this->ok(['success' => true, 'data' => OutageService::status()]);
    }

    /**
     * `POST /master-admin/api/outage`
     *
     * The body is a JSON object with the outage settings.  The settings are
     * validated, persisted and applied immediately.
     */
    public function store(Request $request): JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $this->detail(400, 'بدنه درخواست نامعتبر است.');
        }

        try {
            $status = OutageService::applySettings($decoded, $this->adminUsername());
        } catch (OutageException $exception) {
            return $this->detail(400, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'outage.store');
        }

        $this->audit->adminAction($this->adminUsername(), 'update_outage_settings', [
            'target_type' => 'system_config',
            'target_id' => OutageService::ENABLED_KEY,
            'description' => 'تنظیمات صفحهٔ قطعی اینترنت: '
                .(($status['enabled'] ?? false) ? 'فعال' : 'غیرفعال').'، '
                .'پایش هر '.($status['interval_seconds'] ?? 0).' ثانیه، '
                .'آستانه '.($status['threshold'] ?? 0).' شکست',
            'after_data' => [
                'enabled' => $status['enabled'] ?? false,
                'interval_seconds' => $status['interval_seconds'] ?? 0,
                'failures' => $status['threshold'] ?? 0,
                'targets' => $status['targets'] ?? '',
                'terminate_sessions' => $status['terminate_sessions'] ?? false,
                'show_lan_address' => $status['show_lan_address'] ?? false,
            ],
            'ip_address' => ClientAddress::for(request()),
        ]);

        return $this->ok(['success' => true, 'data' => $status]);
    }

    /**
     * `POST /master-admin/api/outage/check`
     *
     * Probes the internet right now and applies the result.
     */
    public function check(): JsonResponse
    {
        $status = OutageService::checkNow();

        $this->audit->adminAction($this->adminUsername(), 'outage_probe_test', [
            'target_type' => 'system_config',
            'target_id' => OutageService::TARGETS_KEY,
            'description' => 'تست پایش اینترنت: '
                .(($status['probe_online'] ?? false) ? 'وصل' : 'قطع')
                .' ('.($status['detail'] ?? '—').')',
            'after_data' => [
                'online' => $status['probe_online'] ?? false,
                'failures' => $status['failures'] ?? 0,
                'active' => $status['active'] ?? false,
                'detail' => $status['detail'] ?? '',
            ],
            'ip_address' => ClientAddress::for(request()),
        ]);

        return $this->ok(['success' => true, 'data' => $status]);
    }

    /**
     * `POST /master-admin/api/outage/manual`
     *
     * Declares the outage by hand (or clears it).
     */
    public function manual(Request $request): JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $this->detail(400, 'بدنه درخواست نامعتبر است.');
        }

        $active = \App\Support\SystemConfigStore::truthy($decoded['active'] ?? false);

        $status = OutageService::setManual($active, $this->adminUsername());

        $this->audit->adminAction($this->adminUsername(), $active ? 'enable_outage_manual' : 'disable_outage_manual', [
            'target_type' => 'system_config',
            'target_id' => OutageService::MANUAL_KEY,
            'description' => ($active ? 'اعلام دستی' : 'لغو').' حالت قطعی اینترنت'
                .($active ? ' (نشست‌ها بسته شد: '.($status['terminated_sessions'] ?? 0).')' : ''),
            'after_data' => [
                'active' => $status['active'] ?? false,
                'enabled' => $status['enabled'] ?? false,
            ],
            'ip_address' => ClientAddress::for(request()),
        ]);

        return $this->ok(['success' => true, 'data' => $status]);
    }

    private function detail(int $status, string $message): JsonResponse
    {
        return response()->json(['detail' => $message], $status);
    }
}
