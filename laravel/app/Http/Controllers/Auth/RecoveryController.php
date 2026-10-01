<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\RecoveryCodeService;
use App\Services\Auth\LegacyCredentialWriter;
use App\Services\Auth\LoginThrottle;
use App\Services\Auth\SessionRegistry;
use App\Support\Http\ClientAddress;
use App\Support\Legacy\LegacyIds;
use App\Support\Legacy\LegacyInput;
use App\Support\Legacy\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Public password recovery: `POST /forgot_password`, `POST /reset_password`.
 *
 * Ported from `app/api/routes/auth.py`.  The shape of this flow is worth stating
 * because it is unusual and every part of it is deliberate:
 *
 * * there is **no email and no SMS**; a user files a request, an administrator
 *   approves it in the control centre, and the administrator then tells the user the
 *   8-character code.  The code is generated at *approval*, not at request time;
 * * `/forgot_password` answers with the **same** body, status and message whether or
 *   not the account exists, and mints a *decoy* request id for an unknown account.
 *   That decoy is the whole reason `verify_recovery_code` must not reveal whether a
 *   request id is real until the caller has proven they hold the code;
 * * the request id is returned to the caller and shown to the administrator, which is
 *   how the two find each other.  It is therefore not a secret — the code is.
 *
 * Both endpoints are in the CSRF exemption list, exactly as they were in Python: a
 * visitor who cannot log in has no session token to present.  The throttle below is
 * what protects them instead.
 */
final class RecoveryController extends Controller
{
    /**
     * The one message `/forgot_password` ever answers with.
     *
     * Transcribed verbatim, including the fact that it never says whether the
     * account exists.
     */
    public const UNIFIED_MESSAGE = 'اگر این حساب وجود داشته باشد، درخواست بازیابی رمز ثبت خواهد شد. منتظر تأیید مدیر سامانه باشید.';

    /** Answered when the installation has no `HASTAMA_HMAC_SECRET`. */
    public const DISABLED_MESSAGE = 'بازیابی رمز عبور در این نصب غیرفعال است. با مدیر سامانه تماس بگیرید.';

    public const THROTTLED_MESSAGE = 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً چند دقیقه صبر کنید.';

    public const INVALID_REQUEST_MESSAGE = 'درخواست نامعتبر است.';

    public function __construct(
        private readonly RecoveryCodeService $recovery,
        private readonly LoginThrottle $throttle,
        private readonly SessionRegistry $registry,
        private readonly LegacyCredentialWriter $credentials,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * File a reset request.
     *
     * Order of checks is the legacy order and matters: the address throttle can be
     * answered with a rejection (it protects the endpoint itself), while every
     * *account*-level answer — malformed username, per-username throttle, unknown
     * account, persistence failure — is folded into the single unified message.  A
     * caller must not be able to tell them apart.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $ip = ClientAddress::for($request);
        $userAgent = ClientAddress::userAgent($request);

        if (! $this->throttle->allowRecoveryFromIp($ip)) {
            return response()->json(['success' => false, 'message' => self::THROTTLED_MESSAGE], 429);
        }

        if (! $request->isJson()) {
            return response()->json(['success' => false, 'message' => self::INVALID_REQUEST_MESSAGE], 400);
        }

        $username = PersianText::strip((string) $request->input('username', ''));

        if (! LegacyInput::validateUsername($username)['valid']) {
            // Still a success: the answer must not depend on the input's validity.
            return response()->json(['success' => true, 'message' => self::UNIFIED_MESSAGE]);
        }

        if (! $this->throttle->allowRecoveryForUsername($username)) {
            return response()->json(['success' => true, 'message' => self::UNIFIED_MESSAGE]);
        }

        /*
         * Fail closed.
         *
         * Without the HMAC key the stored digest would certify nothing, so recovery
         * is refused outright rather than served with a weaker check.  This is the
         * one case that *is* answered differently, because an operator has to know
         * why nothing is happening.
         */
        if (! $this->recovery->available()) {
            Log::error('password recovery requested but HASTAMA_HMAC_SECRET is not configured');

            return response()->json(['success' => false, 'message' => self::DISABLED_MESSAGE]);
        }

        try {
            $known = User::query()->whereUsername($username)->exists();

            if (! $known) {
                // The decoy id: a real-looking identifier for a request that was
                // never stored, so the response is indistinguishable from one for an
                // account that exists.
                $this->audit->logEvent(AuditLogger::TYPE_AUTHENTICATION, 'password_reset_requested', [
                    'username' => $username,
                    'module' => 'password',
                    'ip_address' => $ip,
                    'user_agent' => $userAgent,
                    'status' => 'failure',
                    'severity' => AuditLogger::SEVERITY_LOW,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => self::UNIFIED_MESSAGE,
                    'request_id' => LegacyIds::requestId(),
                ]);
            }

            $requestId = $this->recovery->createRequest($username, $ip, $userAgent);

            if ($requestId === null) {
                Log::warning('password reset request could not be persisted', ['username' => $username]);
            }

            $this->audit->logEvent(AuditLogger::TYPE_AUTHENTICATION, 'password_reset_requested', [
                'username' => $username,
                'module' => 'password',
                'resource_type' => 'password_reset',
                'resource_id' => $requestId,
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'status' => 'success',
                'severity' => AuditLogger::SEVERITY_LOW,
            ]);

            return response()->json([
                'success' => true,
                'message' => self::UNIFIED_MESSAGE,
                'request_id' => $requestId ?? LegacyIds::requestId(),
            ]);
        } catch (Throwable $exception) {
            Log::error('forgot password failed', [
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);

            return response()->json(['success' => true, 'message' => self::UNIFIED_MESSAGE]);
        }
    }

    /**
     * Verify a recovery code and set the new password.
     *
     * The credential is written exactly as the Python version wrote it: the bcrypt
     * hash into `password_hash` and the plaintext column **emptied**, so the legacy
     * plaintext stops existing at the moment it is replaced.  Every session that
     * existed before the reset is then terminated — a password change that leaves
     * an attacker's session alive has not changed anything that matters.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $ip = ClientAddress::for($request);
        $userAgent = ClientAddress::userAgent($request);

        if (! $this->throttle->allowRecoveryFromIp($ip)) {
            return response()->json(['success' => false, 'message' => self::THROTTLED_MESSAGE], 429);
        }

        if (! $request->isJson()) {
            return response()->json(['success' => false, 'message' => self::INVALID_REQUEST_MESSAGE], 400);
        }

        $requestId = PersianText::strip((string) $request->input('request_id', ''));
        $code = PersianText::strip((string) $request->input('code', ''));
        $newPassword = PersianText::strip((string) $request->input('new_password', ''));

        $requestValidation = LegacyInput::validateRequestId($requestId);

        if (! $requestValidation['valid']) {
            return response()->json(['success' => false, 'message' => $requestValidation['error']]);
        }

        $codeValidation = LegacyInput::validateRecoveryCode($code);

        if (! $codeValidation['valid']) {
            return response()->json(['success' => false, 'message' => $codeValidation['error']]);
        }

        if ($newPassword === '') {
            return response()->json(['success' => false, 'message' => 'رمز عبور جدید الزامی است.']);
        }

        if (mb_strlen($newPassword) > LegacyInput::MAX_PASSWORD_LENGTH) {
            return response()->json(['success' => false, 'message' => 'رمز عبور نباید بیش از ۱۲۸ کاراکتر باشد.']);
        }

        $policy = LegacyInput::passwordPolicy($newPassword);

        if (! $policy['valid']) {
            return response()->json([
                'success' => false,
                'message' => 'رمز عبور ضعیف است: '.implode(', ', $policy['errors']),
            ]);
        }

        try {
            $result = $this->recovery->verify($requestId, $code);

            if (! ($result['success'] ?? false)) {
                return response()->json([
                    'success' => false,
                    'message' => $result['message'] ?? RecoveryCodeService::CODE_REJECTED_MESSAGE,
                ]);
            }

            $username = (string) $result['username'];

            $written = $this->credentials->forUsername($username, $newPassword);

            if ($written === 0) {
                // The account disappeared between approval and this request.  Reported
                // as a failure rather than a success message, because the user would
                // otherwise be told to log in with a password nothing ever stored.
                Log::error('password reset matched no account', ['username' => $username]);

                return response()->json([
                    'success' => false,
                    'message' => 'خطا در تغییر رمز عبور. لطفاً دوباره تلاش کنید.',
                ], 500);
            }

            $revoked = $this->registry->revokeUserSessions($username, 'password_reset');

            $this->audit->logEvent(AuditLogger::TYPE_AUTHENTICATION, 'password_reset_complete', [
                'username' => $username,
                'module' => 'password',
                'ip_address' => $ip,
                'user_agent' => $userAgent,
                'status' => 'success',
                'severity' => AuditLogger::SEVERITY_MEDIUM,
                'metadata' => ['sessions_revoked' => $revoked],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'رمز عبور با موفقیت تغییر کرد. حالا می‌توانید وارد شوید.',
            ]);
        } catch (Throwable $exception) {
            Log::error('reset password failed', [
                'exception' => $exception::class,
                'reason' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطا در تغییر رمز عبور. لطفاً دوباره تلاش کنید.',
            ], 500);
        }
    }
}
