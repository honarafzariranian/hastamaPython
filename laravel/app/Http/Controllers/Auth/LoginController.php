<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LegacyUserProvider;
use App\Http\Controllers\Controller;
use App\Http\Middleware\VerifyLegacyCsrf;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\SessionRegistry;
use App\Services\Settings\SystemSettings;
use App\Support\Http\ClientAddress;
use App\Support\Http\LegacyCsrfCookie;
use App\Support\Legacy\LegacyCaptcha;
use App\Support\Legacy\LegacyInput;
use App\Support\Legacy\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Login and logout — a port of the flow in `app/api/routes/auth.py`.
 *
 * The order of the checks is part of the security design and is preserved exactly:
 *
 * 1. body must be JSON, else `400`;
 * 2. username and password must be present;
 * 3. the CAPTCHA, when the operator has it enabled — **before** any credential
 *    lookup, so a bot cannot use the endpoint as a password oracle;
 * 4. input length caps;
 * 5. brute-force throttle on the source address;
 * 6. credential verification, which itself folds in the account-active rule.
 *
 * Every failure answer is deliberately generic.  An unknown username, a wrong
 * password and a disabled account all produce the same body and the same status, so
 * the endpoint cannot be used to enumerate accounts.
 *
 * Two additions to the existing design, both required and both documented:
 *
 * * the credential **upgrade** — the confirmed migration decision is to verify the
 *   legacy plaintext and replace it with bcrypt on the first successful login.  No
 *   row is rewritten before the password has been proven correct.
 * * the CSRF token is minted into the session and published in the readable
 *   `csrf_token` cookie, so both the legacy front-end contract and Laravel's own
 *   double-submit cookie keep working while the two applications share a browser.
 */
final class LoginController extends Controller
{
    /** Input caps, from `core/password_utils.py`, via the shared validator. */
    public const MAX_USERNAME_LENGTH = LegacyInput::MAX_USERNAME_LENGTH;

    public const MAX_PASSWORD_LENGTH = LegacyInput::MAX_PASSWORD_LENGTH;

    public function __construct(
        private readonly LegacyUserProvider $provider,
        private readonly LegacyCaptcha $captcha,
        private readonly LoginThrottle $throttle,
        private readonly SessionRegistry $registry,
        private readonly SystemSettings $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function login(Request $request): JsonResponse
    {
        if (! $request->isJson()) {
            return response()->json(
                ['success' => false, 'message' => 'درخواست نامعتبر است.'],
                400,
            );
        }

        $username = PersianText::strip((string) $request->input('username', ''));
        $password = PersianText::strip((string) $request->input('password', ''));
        $captcha = PersianText::strip((string) $request->input('captcha', ''));

        if ($username === '' || $password === '') {
            return response()->json([
                'success' => false,
                'message' => 'نام کاربری و رمز عبور الزامی هستند.',
            ]);
        }

        $ip = ClientAddress::for($request);
        $userAgent = ClientAddress::userAgent($request);

        if ($this->settings->captchaEnabled()) {
            $failure = $this->checkCaptcha($request, $captcha, $ip, $userAgent);

            if ($failure !== null) {
                return $failure;
            }
        }

        if (mb_strlen($username) > self::MAX_USERNAME_LENGTH || mb_strlen($password) > self::MAX_PASSWORD_LENGTH) {
            return response()->json([
                'success' => false,
                'message' => 'نام کاربری یا رمز عبور اشتباه است.',
            ]);
        }

        if ($this->throttle->ipIsThrottled($ip)) {
            $this->audit->logEvent(AuditLogger::TYPE_AUTHENTICATION, 'login_throttled', [
                'username' => $username,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'status' => 'failure',
                'severity' => AuditLogger::SEVERITY_HIGH,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'تعداد تلاش‌های ناموفق زیاد است. لطفاً چند دقیقه بعد تلاش کنید.',
            ], 429);
        }

        try {
            $user = $this->provider->retrieveByCredentials(['username' => $username]);

            $authenticated = $user instanceof User
                && $this->provider->validateCredentials($user, ['password' => $password]);

            if ($authenticated) {
                return $this->startSession($request, $user, $password, $ip, $userAgent);
            }

            $counts = $this->throttle->registerLoginFailure($ip, $username);
            $this->recordLoginResult($username, success: false);

            $this->audit->logEvent(AuditLogger::TYPE_AUTHENTICATION, 'login', [
                'username' => $username,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'status' => 'failure',
                'severity' => $counts['failures_from_ip'] >= LoginThrottle::LOGIN_MAX_FAILURES_PER_IP
                    ? AuditLogger::SEVERITY_HIGH
                    : AuditLogger::SEVERITY_LOW,
                'metadata' => [
                    'failures_from_ip' => $counts['failures_from_ip'],
                    'failures_for_account' => $counts['failures_for_account'],
                    'account_known' => $user !== null,
                ],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'نام کاربری یا رمز عبور اشتباه است',
            ]);
        } catch (Throwable $exception) {
            Log::error('login failed', [
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطایی رخ داد. لطفاً دوباره تلاش کنید.',
            ], 500);
        }
    }

    /**
     * End the session.
     *
     * `GET /logout` in the legacy application, and it revoked the server-side
     * record too — but only after taking a copy of the session id, because clearing
     * the session is what removes it.  A session without a registry token falls back
     * to revoking by username, which is what the pre-registry sessions looked like.
     */
    public function logout(Request $request): RedirectResponse
    {
        $token = (string) $request->session()->get(SessionRegistry::SESSION_TOKEN_KEY, '');
        $legacyUsername = $token === '' ? (string) $request->session()->get('username', '') : '';

        if ($token !== '') {
            $this->registry->revoke($token, 'self');
            $this->audit->trackSessionLogout($token, [
                'username' => (string) ($request->session()->get('username') ?? ''),
                'ip_address' => ClientAddress::for($request),
            ]);
        } elseif ($legacyUsername !== '') {
            $this->registry->revokeUserSessions($legacyUsername, 'logout');
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login');
    }

    /**
     * The CAPTCHA gate, or null when the submission may proceed.
     *
     * The `captcha_reason` distinction is learned **before** validation, because
     * validation clears the stored code and every rejection would then look like a
     * mismatch — the same reasoning as the Python comment.
     */
    private function checkCaptcha(Request $request, string $captcha, string $ip, string $userAgent): ?JsonResponse
    {
        $session = $request->session();

        if ($captcha === '') {
            return response()->json([
                'success' => false,
                'message' => 'لطفاً کد امنیتی را وارد کنید.',
                'captcha_error' => true,
                'captcha_reason' => 'missing_input',
            ]);
        }

        $wasPresent = $this->captcha->hasCode($session);
        $wasExpired = $this->captcha->expired($session);

        $result = $this->captcha->validate($session, $captcha);

        if ($result['valid']) {
            return null;
        }

        $reason = $this->captcha->failureReason($session, $wasPresent, $wasExpired);

        $this->audit->logEvent(AuditLogger::TYPE_CAPTCHA, 'validation_failed', [
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'status' => 'failure',
            'severity' => AuditLogger::SEVERITY_LOW,
            'metadata' => ['reason' => $reason, 'expired' => $wasExpired],
        ]);

        return response()->json([
            'success' => false,
            'message' => $result['message'],
            'captcha_error' => true,
            'captcha_expired' => $wasExpired,
            'captcha_reason' => $reason,
        ]);
    }

    /**
     * Rotate the session, register it, set the role flags and answer with the
     * redirect the front-end follows.
     */
    private function startSession(Request $request, User $user, string $password, string $ip, string $userAgent): JsonResponse
    {
        // Session fixation: clear everything before writing the new identity.
        $request->session()->invalidate();
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        $session = $request->session();
        $username = $user->username();

        $session->put('username', $username);
        $token = $this->registry->newToken();
        $session->put(SessionRegistry::SESSION_TOKEN_KEY, $token);
        $session->put(SessionRegistry::SESSION_ISSUED_KEY, time());

        // Minted here so every authenticated state-changing request can be verified
        // against the session (both via Laravel's token and via the legacy header),
        // and published in the readable cookie on the way out.
        $csrfToken = bin2hex(random_bytes(32));
        $session->put(VerifyLegacyCsrf::LEGACY_SESSION_KEY, $csrfToken);

        $role = $user->role();
        $isAdmin = $user->isAdmin();
        $isMasterAdmin = $isAdmin && $this->isMasterAdmin($username);

        $session->put('is_admin', $isAdmin);
        $session->put('is_master_admin', $isMasterAdmin);
        $session->put('role', $role);

        Auth::login($user);

        $this->registry->register($token, $username, $ip, $userAgent);
        $this->throttle->clearLoginFailures($ip, $username);
        $this->recordLoginResult($username, success: true);
        $this->upgradeCredential($user, $password);

        $this->audit->logEvent(AuditLogger::TYPE_AUTHENTICATION, 'login', [
            'username' => $username,
            'role' => $role,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
            'status' => 'success',
        ]);
        $this->audit->trackSessionLogin($token, $username, [
            'role' => $role,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);

        $redirect = match (true) {
            $isMasterAdmin => '/master-admin',
            $isAdmin => '/admin/dashboard',
            default => '/user_panel',
        };

        return response()
            ->json(['success' => true, 'redirect' => $redirect])
            ->withCookie(LegacyCsrfCookie::make($request, $csrfToken));
    }

    /**
     * Replace a legacy credential with bcrypt after a proven authentication.
     *
     * Guarded by `needsUpgrade()` so a row with a modern hash is never rewritten,
     * and by the "we just verified this password" position in the flow.
     */
    private function upgradeCredential(User $user, string $password): void
    {
        if (! $this->provider->needsUpgrade($user)) {
            return;
        }

        if (! $this->provider->upgradeCredential($user, $password)) {
            Log::warning('legacy credential could not be upgraded', ['username' => $user->username()]);

            return;
        }

        $this->audit->logEvent(AuditLogger::TYPE_AUTHENTICATION, 'password_upgraded', [
            'username' => $user->username(),
            'module' => 'password',
            'status' => 'success',
            'severity' => AuditLogger::SEVERITY_MEDIUM,
        ]);
    }

    /**
     * Best-effort `last_login` / `failed_login_count` bookkeeping.
     *
     * Its failures are swallowed by design in the Python implementation ("best-effort
     * bookkeeping for the dashboard"); losing a counter must never block a login.
     */
    private function recordLoginResult(string $username, bool $success): void
    {
        try {
            $query = User::query()->whereRaw('LTRIM(RTRIM(username)) = ?', [PersianText::strip($username)]);

            if ($success) {
                $query->update([
                    'last_login' => now(),
                    'failed_login_count' => 0,
                ]);
            } else {
                $query->update([
                    'failed_login_count' => DB::raw('ISNULL(failed_login_count, 0) + 1'),
                ]);
            }
        } catch (Throwable $exception) {
            Log::warning('login bookkeeping failed', ['exception' => $exception::class]);
        }
    }

    private function isMasterAdmin(string $username): bool
    {
        $masters = array_map(
            static fn ($name): string => mb_strtolower(trim((string) $name)),
            (array) config('hastama.master_admin_usernames', []),
        );

        return in_array(mb_strtolower(PersianText::strip($username)), $masters, true);
    }
}
