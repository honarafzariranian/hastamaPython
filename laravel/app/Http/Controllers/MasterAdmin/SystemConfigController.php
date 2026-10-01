<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Services\Audit\AuditLogger;
use App\Support\Http\ClientAddress;
use App\Support\Http\MasterAdminActor;
use App\Support\Legacy\LegacySerializer;
use App\Models\SystemConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The master-admin system config surface.
 *
 * Ported from `get_config` and `update_config` in
 * `app/api/routes/master_admin.py`.
 *
 * The POST endpoint validates the key against a hard-coded allow-list and
 * upserts the value into `system_config`.  The Python's `log_admin_action`
 * call is reproduced through `AuditLogger::adminAction`.
 */
final class SystemConfigController extends MasterAdminController
{
    use MasterAdminActor;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * `GET /master-admin/api/config`
     *
     * Every row of `system_config`, serialised the way `_serialize` did.
     */
    public function index(): JsonResponse
    {
        try {
            $rows = DB::connection()->select(
                'SELECT config_key, config_value, description, updated_by, updated_at FROM system_config',
            );

            return $this->ok([
                'success' => true,
                'data' => LegacySerializer::rows('system_config', $rows),
            ]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'config.index');
        }
    }

    /**
     * `POST /master-admin/api/config`
     *
     * The body must carry a non-empty `key` and a `value`.  The key is checked
     * against the allow-list before any database work happens.
     */
    public function store(Request $request): JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $this->detail(400, 'بدنه درخواست نامعتبر است.');
        }

        $key = isset($decoded['key']) && is_string($decoded['key']) ? trim($decoded['key']) : '';
        $value = isset($decoded['value']) && is_string($decoded['value']) ? $decoded['value'] : '';

        if ($key === '') {
            return $this->detail(400, 'کلید الزامی است.');
        }

        $allowedKeys = [
            SystemConfig::KEY_CAPTCHA_ENABLED,
            SystemConfig::KEY_IDLE_TIMEOUT_ENABLED,
            SystemConfig::KEY_IDLE_TIMEOUT_SECONDS,
            SystemConfig::KEY_LABEL_TARGET_PRINTER,
            SystemConfig::KEY_LABEL_PRINT_SETTINGS,
        ];

        if (! in_array($key, $allowedKeys, true)) {
            return $this->detail(400, 'کلید تنظیم مجاز نیست.');
        }

        try {
            $description = '';

            if ($key === SystemConfig::KEY_LABEL_TARGET_PRINTER) {
                $description = 'نام چاپگر انتخابی برای چاپ لیبل و بلیت نوبت';
            } elseif ($key === SystemConfig::KEY_LABEL_PRINT_SETTINGS) {
                $description = 'تنظیمات چاپ لیبل (اندازه، قالب، چرخش، نمایش فیلدها)';
            }

            DB::connection()->table('system_config')
                ->where('config_key', $key)
                ->update([
                    'config_value' => $value,
                    'updated_by' => $this->adminUsername(),
                    'updated_at' => DB::raw('SYSUTCDATETIME()'),
                ]);

            $updated = DB::connection()->table('system_config')
                ->where('config_key', $key)
                ->value('config_key');

            if ($updated === null) {
                DB::connection()->table('system_config')->insert([
                    'config_key' => $key,
                    'config_value' => $value,
                    'description' => $description,
                    'updated_by' => $this->adminUsername(),
                    'updated_at' => DB::raw('SYSUTCDATETIME()'),
                ]);
            }

            $this->audit->adminAction($this->adminUsername(), 'update_config', [
                'target_type' => 'system_config',
                'target_id' => $key,
                'description' => 'تغییر تنظیم '.$key.' به '.$value,
                'ip_address' => ClientAddress::for(request()),
            ]);

            return $this->ok(['success' => true]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'config.store');
        }
    }

    private function detail(int $status, string $message): JsonResponse
    {
        return response()->json(['detail' => $message], $status);
    }
}
