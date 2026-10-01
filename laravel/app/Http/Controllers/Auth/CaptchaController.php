<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Settings\SystemSettings;
use App\Support\Http\ClientAddress;
use App\Support\Legacy\LegacyCaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The CAPTCHA endpoints: `GET /captcha`, `POST /captcha/refresh`,
 * `GET /captcha/status`.
 *
 * The image endpoint answers a PNG with the same anti-caching headers the Python
 * version set, and the code is never in the response body — it is in the session
 * only, which is the entire point of the control.  The status endpoint exists so the
 * login page can warn the user *while they are still typing* instead of letting them
 * submit a code that expired a minute ago; it reads settings only and touches no
 * database.
 */
final class CaptchaController extends Controller
{
    public function __construct(
        private readonly LegacyCaptcha $captcha,
        private readonly SystemSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    /** A fresh CAPTCHA as an image. */
    public function image(Request $request): Response
    {
        $code = $this->captcha->code();
        $this->captcha->store($request->session(), $code);

        $this->audit->logEvent(AuditLogger::TYPE_CAPTCHA, 'generated', [
            'ip_address' => ClientAddress::for($request),
            'user_agent' => ClientAddress::userAgent($request),
        ]);

        return response($this->captcha->render($code), 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** A fresh CAPTCHA as a data URI, for the "refresh" button. */
    public function refresh(Request $request): JsonResponse
    {
        $code = $this->captcha->code();
        $this->captcha->store($request->session(), $code);

        $this->audit->logEvent(AuditLogger::TYPE_CAPTCHA, 'refreshed', [
            'ip_address' => ClientAddress::for($request),
            'user_agent' => ClientAddress::userAgent($request),
        ]);

        return response()->json([
            'success' => true,
            'image' => 'data:image/png;base64,'.base64_encode($this->captcha->render($code)),
        ]);
    }

    /**
     * How much life the current code has left.
     *
     * `has_captcha` distinguishes "no code was ever issued" from "the code expired",
     * which is the distinction the login page needs to decide between prompting for
     * a new code and showing a countdown.
     */
    public function status(Request $request): JsonResponse
    {
        $session = $request->session();
        $remaining = $this->captcha->remainingSeconds($session);
        $hasCaptcha = $this->captcha->hasCode($session);

        return response()->json([
            'success' => true,
            'valid' => $hasCaptcha && $remaining > 0,
            'remaining_seconds' => $remaining,
            'has_captcha' => $hasCaptcha,
            'ttl_seconds' => $this->captcha->ttlSeconds(),
            'notice_enabled' => $this->settings->flag('login_captcha_notice', true),
            'warning_lead_seconds' => (int) config('hastama.captcha.warning_lead_seconds', 60),
        ]);
    }
}
