<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyLegacyCsrf;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\SessionRegistry;
use App\Services\Settings\SystemSettings;
use App\Support\Http\ClientAddress;
use App\Support\Http\LegacyCsrfCookie;
use App\Support\Legacy\LegacyCaptcha;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The small session/bootstrap endpoints the front-end needs before it can talk to
 * anything else: the CSRF token, the public runtime settings, "who am I", and
 * "forget me".
 *
 * Three of the four are ported:
 *
 *   GET  /api/csrf-token      `app/api/routes/auth.py`
 *   GET  /api/system-config   `app/main.py`
 *   POST /api/session/destroy `app/main.py`
 *
 * The fourth, `GET /api/me`, is **added**: the SPA needs one call that answers
 * "is this session still good, and what is this user's role", where the old
 * server-rendered shell answered it by rendering the panel it had already
 * authorised.  It is marked as an addition rather than presented as a migrated
 * endpoint, and it is the only route here behind `legacy.session`.
 */
final class SessionController extends Controller
{
    public function __construct(
        private readonly SystemSettings $settings,
        private readonly LegacyCaptcha $captcha,
        private readonly SessionRegistry $registry,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Publish the CSRF token, in both schemes.
     *
     * A safe method, so it is not CSRF protected: the value is only useful together
     * with the matching cookie, which a third-party origin cannot read.  Its purpose
     * is recovery when the readable cookie was cleared but the session survives —
     * exactly the case the legacy application minted a fresh token for.
     */
    public function csrfToken(Request $request): JsonResponse
    {
        $session = $request->session();
        $token = (string) $session->get(VerifyLegacyCsrf::LEGACY_SESSION_KEY, '');

        if ($token === '') {
            $token = bin2hex(random_bytes(32));
            $session->put(VerifyLegacyCsrf::LEGACY_SESSION_KEY, $token);
        }

        return response()
            ->json(['success' => true, 'csrf_token' => $token])
            ->withCookie(LegacyCsrfCookie::make($request, $token));
    }

    /**
     * The non-secret settings an unauthenticated page needs.
     *
     * The legacy endpoint returns these as **strings**, as stored in
     * `system_config`, and the existing JavaScript compares them as strings
     * (`res.data.idle_timeout_enabled !== '0'`).  The new Vue client reads the same
     * shapes, so the types are preserved rather than "fixed".
     *
     * The login-experience values come from {@see LegacyCaptcha} and
     * {@see SystemSettings}, which clamp them, so a hand-edited row cannot put a
     * nonsense duration in front of a user.
     */
    public function publicConfig(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => array_merge($this->settings->publicRuntimeSettings(), [
                'login_loader_enabled' => $this->settings->flag('login_loader_enabled', true) ? '1' : '0',
                'login_loader_seconds' => (string) $this->settings->number('login_loader_seconds', 3, 1, 15),
                'login_loader_title' => $this->settings->value('login_loader_title', 'در حال آماده‌سازی میزکار شما…'),
                'login_loader_message' => $this->settings->value('login_loader_message', 'لطفاً چند لحظه صبر کنید؛ در حال ورود به سامانه هستما.'),
                'login_captcha_notice' => $this->settings->flag('login_captcha_notice', true) ? '1' : '0',
                'login_captcha_ttl_seconds' => (string) $this->captcha->ttlSeconds(),
            ]),
        ]);
    }

    /**
     * Forget the session, including the revocable half of it.
     *
     * This is not the same thing as `/logout`: that one ends the session and sends
     * the browser to the login page, and it is what a user clicks.  This endpoint
     * exists for the `beforeunload`/`pagehide` beacon, whose whole purpose is to
     * make a captured cookie useless the moment the tab closes, so it answers the
     * minimal `{"success": true}` the beacon's `sendBeacon` call can ignore.
     */
    public function destroy(Request $request): JsonResponse
    {
        $session = $request->session();
        $token = (string) $session->get(SessionRegistry::SESSION_TOKEN_KEY, '');
        $username = (string) ($session->get('username') ?? 'self');

        if ($token !== '') {
            $this->registry->revoke($token, $username !== '' ? $username : 'self');
        }

        $this->audit->trackSessionLogout($token, [
            'username' => $username,
            'ip_address' => ClientAddress::for($request),
        ]);

        Auth::logout();
        $session->invalidate();
        $session->regenerateToken();

        return response()->json(['success' => true]);
    }

    /**
     * The current session's identity, or 401.
     *
     * Returns the session flags the guards use, so the SPA can route without
     * duplicating the authorisation rules — the two flags are decided once, at
     * login, by {@see LoginController::startSession()}.
     */
    public function me(Request $request): JsonResponse
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return response()->json([
                'success' => false,
                'message' => 'وارد نشده‌اید.',
                'unauthenticated' => true,
            ], 401);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'username' => $user->username(),
                'name' => $user->displayName(),
                'role' => $user->role(),
                'is_admin' => $request->session()->get('is_admin') === true,
                'is_master_admin' => $request->session()->get('is_master_admin') === true,
                'profile_image' => $user->getAttribute('profile_image'),
                'department' => $user->getAttribute('department'),
            ],
        ]);
    }
}
