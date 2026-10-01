<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Services\Audit\AuditLogger;
use App\Support\Http\ClientAddress;
use App\Support\Http\MasterAdminActor;
use App\Support\LoginExperienceException;
use App\Support\LoginExperienceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The master-admin login experience surface.
 *
 * Ported from `get_login_experience` and `set_login_experience` in
 * `app/api/routes/master_admin.py`.
 *
 * The values live in `system_config` and are applied without a restart.
 */
final class LoginExperienceController extends MasterAdminController
{
    use MasterAdminActor;

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * `GET /master-admin/api/login-experience`
     */
    public function index(): JsonResponse
    {
        return $this->ok(['success' => true, 'data' => LoginExperienceService::status()]);
    }

    /**
     * `POST /master-admin/api/login-experience`
     *
     * The body is a JSON object with the login-page settings.  The settings
     * are validated, persisted and applied immediately.
     */
    public function store(Request $request): JsonResponse
    {
        $decoded = json_decode((string) $request->getContent(), true);

        if (! is_array($decoded)) {
            return $this->detail(400, 'بدنه درخواست نامعتبر است.');
        }

        try {
            $status = LoginExperienceService::applySettings($decoded, $this->adminUsername());
        } catch (LoginExperienceException $exception) {
            return $this->detail(400, $exception->getMessage());
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'login-experience.store');
        }

        $this->audit->adminAction($this->adminUsername(), 'update_login_experience', [
            'target_type' => 'system_config',
            'target_id' => LoginExperienceService::ENABLED_KEY,
            'description' => 'تنظیمات صفحهٔ ورود: '
                .'لودر '.($status['loader_enabled'] ? 'فعال' : 'غیرفعال')
                .' ('.($status['loader_seconds'] ?? 0).' ثانیه)، '
                .'اعتبار کد امنیتی '.($status['captcha_ttl_seconds'] ?? 0).' ثانیه، '
                .'هشدار انقضا '.($status['captcha_notice'] ? 'فعال' : 'غیرفعال'),
            'after_data' => [
                'loader_enabled' => $status['loader_enabled'] ?? false,
                'loader_seconds' => $status['loader_seconds'] ?? 0,
                'captcha_ttl_seconds' => $status['captcha_ttl_seconds'] ?? 0,
                'captcha_notice' => $status['captcha_notice'] ?? false,
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
