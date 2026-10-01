<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Services\Audit\AuditLogger;
use App\Support\Connectivity\LanAccessService;
use App\Support\Http\ClientAddress;
use App\Support\Http\MasterAdminActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The master-admin LAN access surface.
 *
 * Ported from `get_lan_access`, `set_lan_access` and `lan_access_selftest` in
 * `app/api/routes/master_admin.py`.
 *
 * The Python ran an `asyncio` TCP listener inside the uvicorn process.  A PHP
 * request process cannot hold one open across requests, so the bind is
 * attempted with `stream_socket_server` and the result is reported honestly.
 * The self-test connects to the bound address and reports the real outcome.
 */
final class LanAccessController extends MasterAdminController
{
    use MasterAdminActor;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * `GET /master-admin/api/lan-access`
     */
    public function index(): JsonResponse
    {
        return $this->ok(['success' => true, 'data' => LanAccessService::status()]);
    }

    /**
     * `POST /master-admin/api/lan-access`
     *
     * The body must be a JSON object with an `enabled` flag.  The flag is
     * persisted and the listener is applied immediately.
     */
    public function store(Request $request): JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $this->detail(400, 'بدنه درخواست نامعتبر است.');
        }

        $enabled = \App\Support\SystemConfigStore::truthy($decoded['enabled'] ?? false);

        $status = LanAccessService::setEnabled($enabled, $this->adminUsername());

        $this->audit->adminAction($this->adminUsername(), $enabled ? 'enable_lan_access' : 'disable_lan_access', [
            'target_type' => 'system_config',
            'target_id' => LanAccessService::ENABLED_KEY,
            'description' => ($enabled ? 'فعال‌سازی' : 'غیرفعال‌سازی')
                .' دسترسی از شبکه داخلی (آدرس: '.($status['url'] ?? '—').')',
            'after_data' => [
                'enabled' => $enabled,
                'running' => $status['running'] ?? false,
                'address' => $status['address'] ?? '',
                'port' => $status['port'] ?? 0,
                'saved' => $status['saved'] ?? false,
            ],
            'ip_address' => ClientAddress::for(request()),
        ]);

        if ($enabled && ! ($status['running'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $status['last_error'] ?? 'شونده شبکه داخلی باز نشد.',
                'data' => $status,
            ], 409);
        }

        return $this->ok(['success' => true, 'data' => $status]);
    }

    /**
     * `POST /master-admin/api/lan-access/selftest`
     *
     * Fetches `/health` through the LAN listener from the server itself.
     */
    public function selftest(): JsonResponse
    {
        $result = LanAccessService::selfTest();

        $this->audit->adminAction($this->adminUsername(), 'lan_access_selftest', [
            'target_type' => 'system_config',
            'target_id' => LanAccessService::ENABLED_KEY,
            'description' => 'تست داخلی مسیر شبکه داخلی: '.($result['status_line'] ?? 'بدون پاسخ'),
            'after_data' => $result,
            'ip_address' => ClientAddress::for(request()),
        ]);

        return $this->ok(['success' => (bool) ($result['ok'] ?? false), 'data' => $result]);
    }

    private function detail(int $status, string $message): JsonResponse
    {
        return response()->json(['detail' => $message], $status);
    }
}
