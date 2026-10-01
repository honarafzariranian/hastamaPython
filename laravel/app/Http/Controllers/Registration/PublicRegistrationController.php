<?php

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ClientAddress;
use App\Support\Legacy\LegacyIds;
use App\Support\Legacy\LegacyPassword;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use App\Support\Legacy\PersianText;
use App\Support\Registration\DisplayText;
use App\Support\Registration\DisplayTextError;
use App\Support\Registration\PasswordHashBinding;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The public half of the self-registration surface — ported from
 * `app/api/routes/registration.py`.
 *
 * Endpoints: `check-username`, `check-national-id`, `submit`, `status/{id}`,
 * `departments`, `work-schedules` and `active-users`.  The admin workflow lives
 * in {@see AdminRegistrationController}.
 *
 * Three rules the Python establishes and this port keeps:
 *
 * * **The rate limit runs before everything** — before the query declaration,
 *   before the JSON parse.  A flood cannot reach `bcrypt`, and the 429 body is
 *   the module's own envelope (`success`/`message`), not FastAPI's `detail`.
 * * **`active-users` guards itself in the handler.**  It answers `403
 *   {"success": false, "error": "دسترسی مدیریتی ندارید."}` for *both* anonymous
 *   and non-administrator callers — captured from the running server — so it
 *   deliberately does not carry the `admin` middleware, whose anonymous answer
 *   (401 `لاگین نکرده‌اید.`) is a different contract.
 * * **Every database refusal has its own body.**  `check-username` answers
 *   `خطا در بررسی.`, `check-national-id` answers `available: true` (a real
 *   bug — a dead database reads as "free for the taking", and it is preserved
 *   because the front-end branches on the flag), `status` answers `خطا در
 *   بررسی.`, `submit` answers the 500 `errors` envelope, and `active-users`
 *   answers `success: true` with an empty list.
 */
final class PublicRegistrationController extends Controller
{
    /** `REGISTRATION_SUBMIT_LIMIT` — per IP / 10 minutes. */
    private const SUBMIT_LIMIT = 5;

    /** `REGISTRATION_PROBE_LIMIT` — the status probe, per IP / 10 minutes. */
    private const PROBE_LIMIT = 30;

    /** `REGISTRATION_LOOKUP_LIMIT` — both availability probes. */
    private const LOOKUP_LIMIT = 20;

    /** Every registration window is 600 seconds; only the bridge differs. */
    private const WINDOW_SECONDS = 600;

    private const MAX_USERNAME_LENGTH = 50;

    private const MAX_PASSWORD_LENGTH = 128;

    /**
     * The columns of the `status` projection, by declared SQL type.
     *
     * `LegacySerializer` needs the type because pdo_sqlsrv returns everything as
     * a string, while the Python serialised pyodbc's `datetime` with
     * `isoformat()`.  An explicit map keeps the endpoint from a second schema
     * round trip for a five-column row.
     *
     * @var array<string, string>
     */
    private const STATUS_TYPES = ['reviewed_at' => 'datetime2'];

    /** Digits Python's `int()` accepts in a national id: ASCII + the two Persian blocks. */
    private const DIGIT = '[0-9\x{0660}-\x{0669}\x{06F0}-\x{06F9}]';

    public function __construct(private readonly AuditLogger $audit) {}

    // ── Check username availability ────────────────────────────────────────

    /**
     * `GET /registration/check-username` — `check_username`.
     *
     * The probe is a single projection: existing users, then pending requests,
     * each with its own message.  Both database failures collapse into one
     * `خطا در بررسی.` rather than a 500, because the field is being polled by
     * the registration form.
     */
    public function checkUsername(Request $request): JsonResponse
    {
        if (($denied = $this->throttle($request, 'registration-username-probe', self::LOOKUP_LIMIT)) !== null) {
            return $denied;
        }

        $params = LegacyQuery::validate($request, ['q' => LegacyQuery::string()]);

        // `q.strip()[:MAX_USERNAME_LENGTH]` — strip first, then bound.
        $username = mb_substr(DisplayText::strip((string) $params['q']), 0, self::MAX_USERNAME_LENGTH);

        if ($username === '' || mb_strlen($username) < 3) {
            return response()->json([
                'available' => false,
                'message' => 'نام کاربری باید حداقل ۳ کاراکتر باشد.',
            ]);
        }

        if (preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username) !== 1) {
            return response()->json([
                'available' => false,
                'message' => 'نام کاربری فقط شامل حروف انگلیسی، اعداد و زیرخط باشد.',
            ]);
        }

        try {
            $exists = DB::selectOne(
                'SELECT 1 FROM user_table WHERE LTRIM(RTRIM(username)) = ?',
                [$username],
            );

            if ($exists !== null) {
                return response()->json([
                    'available' => false,
                    'message' => 'این نام کاربری قبلاً استفاده شده است.',
                ]);
            }

            $pending = DB::selectOne(
                "SELECT 1 FROM user_registration_requests WHERE username = ? AND status = 'pending'",
                [$username],
            );

            if ($pending !== null) {
                return response()->json([
                    'available' => false,
                    'message' => 'این نام کاربری قبلاً درخواست داده شده و در انتظار تأیید است.',
                ]);
            }

            return response()->json([
                'available' => true,
                'message' => 'نام کاربری قابل استفاده است.',
            ]);
        } catch (Throwable) {
            return response()->json(['available' => false, 'message' => 'خطا در بررسی.']);
        }
    }

    // ── Check national ID availability ─────────────────────────────────────

    /**
     * `GET /registration/check-national-id` — `check_national_id`.
     *
     * An empty probe answers `{"available": true}` with **no message key** —
     * the field is optional on the form and the running server says so.
     */
    public function checkNationalId(Request $request): JsonResponse
    {
        if (($denied = $this->throttle($request, 'registration-nid-probe', self::LOOKUP_LIMIT)) !== null) {
            return $denied;
        }

        $params = LegacyQuery::validate($request, ['q' => LegacyQuery::string()]);

        $nid = str_replace(' ', '', DisplayText::strip((string) $params['q']));
        $nid = mb_substr($nid, 0, 10);

        if ($nid === '') {
            return response()->json(['available' => true]);
        }

        if (! self::validNationalId($nid)) {
            return response()->json([
                'available' => false,
                'message' => 'شماره ملی معتبر نیست.',
            ]);
        }

        try {
            $pending = DB::selectOne(
                "SELECT 1 FROM user_registration_requests WHERE national_id = ? AND status = 'pending'",
                [$nid],
            );

            if ($pending !== null) {
                return response()->json([
                    'available' => false,
                    'message' => 'این شماره ملی قبلاً درخواست ثبت نام داده است.',
                ]);
            }

            return response()->json(['available' => true]);
        } catch (Throwable) {
            // Preserved bug: the Python's `except Exception: return available
            // True` turns a database outage into "this id is free".
            return response()->json(['available' => true]);
        }
    }

    // ── Submit registration request ────────────────────────────────────────

    /**
     * `POST /registration/submit` — `submit_registration`.
     *
     * The order is the Python's, and each step has its own refusal:
     *
     * 1. rate limit (5 / 600 s per IP) — 429 envelope, plus the
     *    `registration_rate_limited` security event;
     * 2. JSON that must decode to an *object* — 400 `درخواست نامعتبر است.`
     *    for bad JSON and for a list alike;
     * 3. six `clean_display_text` calls — 400 with the single `ValueError`
     *    message, first failure wins;
     * 4. the required/format error list, in the Python's order;
     * 5. `bcrypt` (cost 12) **before** the duplicate checks, exactly as there —
     *    an unusual order, but it means the hash exists even if the duplicate
     *    queries then fail;
     * 6. duplicate checks, insert, audit — any database failure collapses to
     *    the one 500 body, and the audit write is inside the same block.
     */
    public function submit(Request $request): JsonResponse
    {
        if (($denied = $this->throttle($request, 'registration-submit', self::SUBMIT_LIMIT)) !== null) {
            $this->audit->logEvent('SECURITY', 'registration_rate_limited', [
                'module' => 'registration',
                'status' => 'failure',
                'severity' => 'medium',
                'ip_address' => ClientAddress::for($request),
                'user_agent' => ClientAddress::userAgent($request),
            ]);

            return $denied;
        }

        $decoded = json_decode($request->getContent());

        // `await request.json()` failing and `not isinstance(data, dict)` share
        // one body; a JSON list lands in the same bucket as a truncated document.
        if (json_last_error() !== JSON_ERROR_NONE || ! is_object($decoded)) {
            return response()->json(['success' => false, 'errors' => ['درخواست نامعتبر است.']], 400);
        }

        try {
            $firstName = DisplayText::clean($decoded->first_name ?? null, 100, 'نام');
            $lastName = DisplayText::clean($decoded->last_name ?? null, 100, 'نام خانوادگی');
            $fatherName = DisplayText::clean($decoded->father_name ?? null, 100, 'نام پدر');
            $department = DisplayText::clean($decoded->department ?? null, 100, 'بخش');
            $substitute = DisplayText::clean($decoded->substitute ?? null, 100, 'جانشین');
            $workHours = DisplayText::clean($decoded->work_hours ?? null, 50, 'ساعت کاری');
        } catch (DisplayTextError $exception) {
            return response()->json(['success' => false, 'errors' => [$exception->getMessage()]], 400);
        }

        $nationalId = mb_substr(str_replace(' ', '', DisplayText::strip(DisplayText::pyStrOrEmpty($decoded->national_id ?? null))), 0, 20);
        $mobile = mb_substr(DisplayText::strip(DisplayText::pyStrOrEmpty($decoded->mobile ?? null)), 0, 20);
        $username = mb_substr(DisplayText::strip(DisplayText::pyStrOrEmpty($decoded->username ?? null)), 0, self::MAX_USERNAME_LENGTH);
        $password = DisplayText::pyStrOrEmpty($decoded->password ?? null);

        $errors = [];

        if ($firstName === '') {
            $errors[] = 'نام الزامی است.';
        }

        if ($lastName === '') {
            $errors[] = 'نام خانوادگی الزامی است.';
        }

        if ($username === '') {
            $errors[] = 'نام کاربری الزامی است.';
        } elseif (preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username) !== 1) {
            $errors[] = 'نام کاربری فقط شامل حروف انگلیسی، اعداد و زیرخط باشد (۳ تا ۳۰ کاراکتر).';
        }

        if ($password === '') {
            $errors[] = 'رمز عبور الزامی است.';
        } elseif (mb_strlen($password) < 8) {
            $errors[] = 'رمز عبور باید حداقل ۸ کاراکتر باشد.';
        } elseif (mb_strlen($password) > self::MAX_PASSWORD_LENGTH) {
            $errors[] = 'رمز عبور نباید بیش از ۱۲۸ کاراکتر باشد.';
        }

        if ($nationalId !== '' && ! self::validNationalId($nationalId)) {
            $errors[] = 'شماره ملی معتبر نیست.';
        }

        if ($mobile !== '' && ! self::validMobile($mobile)) {
            $errors[] = 'شماره همراه معتبر نیست.';
        }

        if ($department === '') {
            $errors[] = 'بخش فعالیت الزامی است.';
        }

        if ($errors !== []) {
            return response()->json(['success' => false, 'errors' => $errors], 400);
        }

        if ($mobile !== '') {
            $mobile = self::normalizeMobile($mobile);
        }

        // Hash before the duplicate checks — `hash_password` sits exactly there
        // in the Python, and it is what makes the unauthenticated path expensive
        // enough to need the rate limit above.
        $passwordHash = LegacyPassword::hash($password);

        // The `varbinary(64)` binding, chosen per driver by the shared helper —
        // the offline suite runs on sqlite, which has no `CONVERT()`.
        [$hashExpression, $hashParameter] = PasswordHashBinding::for($passwordHash);

        $ipAddress = ClientAddress::for($request);
        $userAgent = ClientAddress::userAgent($request);

        try {
            $usernameTaken = DB::selectOne(
                'SELECT 1 FROM user_table WHERE LTRIM(RTRIM(username)) = ?',
                [$username],
            );

            if ($usernameTaken !== null) {
                return response()->json(['success' => false, 'errors' => ['نام کاربری قبلاً استفاده شده است.']], 400);
            }

            $usernamePending = DB::selectOne(
                "SELECT 1 FROM user_registration_requests WHERE username = ? AND status = 'pending'",
                [$username],
            );

            if ($usernamePending !== null) {
                return response()->json(['success' => false, 'errors' => ['نام کاربری قبلاً درخواست داده شده.']], 400);
            }

            if ($nationalId !== '') {
                $nidPending = DB::selectOne(
                    "SELECT 1 FROM user_registration_requests WHERE national_id = ? AND status = 'pending'",
                    [$nationalId],
                );

                if ($nidPending !== null) {
                    return response()->json(['success' => false, 'errors' => ['شماره ملی قبلاً درخواست ثبت نام داده است.']], 400);
                }
            }

            if ($mobile !== '') {
                $mobilePending = DB::selectOne(
                    "SELECT 1 FROM user_registration_requests WHERE mobile = ? AND status = 'pending'",
                    [$mobile],
                );

                if ($mobilePending !== null) {
                    return response()->json(['success' => false, 'errors' => ['شماره همراه قبلاً درخواست ثبت نام داده است.']], 400);
                }
            }

            $requestId = LegacyIds::requestId();

            DB::insert(
                'INSERT INTO user_registration_requests
                 (request_id, first_name, last_name, father_name, national_id, mobile,
                  username, password_hash, department, work_hours, substitute,
                  created_ip, created_user_agent)
                 VALUES (?, ?, ?, ?, ?, ?, ?, '.$hashExpression.', ?, ?, ?, ?, ?)',
                [
                    $requestId,
                    $firstName,
                    $lastName,
                    $fatherName !== '' ? $fatherName : null,
                    $nationalId !== '' ? $nationalId : null,
                    $mobile !== '' ? $mobile : null,
                    $username,
                    $hashParameter,
                    $department,
                    $workHours !== '' ? $workHours : null,
                    $substitute !== '' ? $substitute : null,
                    $ipAddress,
                    $userAgent,
                ],
            );

            $this->audit->logEvent('USER', 'registration_request', [
                'username' => $username,
                'module' => 'registration',
                'status' => 'success',
                'severity' => 'info',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => ['request_id' => $requestId, 'department' => $department],
            ]);

            return response()->json([
                'success' => true,
                'message' => "درخواست شما با موفقیت ثبت شد.\nپس از بررسی مدیر مجموعه، حساب شما فعال خواهد شد.",
                'request_id' => $requestId,
            ]);
        } catch (Throwable $exception) {
            Log::error('registration submit failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'errors' => ['خطا در ثبت درخواست. لطفاً دوباره تلاش کنید.'],
            ], 500);
        }
    }

    // ── Check request status ───────────────────────────────────────────────

    /**
     * `GET /registration/status/{requestId}` — `check_request_status`.
     *
     * An unauthenticated lookup, so the limit runs first (30 / 600 s per IP)
     * and the id must be in the documented `HST-…` shape before a query is
     * built — otherwise the 32-bit request id is enumerable.  Both the format
     * miss and the empty result answer `درخواست یافت نشد.`; only a database
     * failure answers `خطا در بررسی.`.
     */
    public function status(Request $request, string $requestId): JsonResponse
    {
        if (($denied = $this->throttle($request, 'registration-status', self::PROBE_LIMIT)) !== null) {
            return $denied;
        }

        $trimmed = DisplayText::strip($requestId);

        if (! LegacyIds::isValid($trimmed)) {
            return response()->json(['found' => false, 'message' => 'درخواست یافت نشد.']);
        }

        try {
            $row = DB::selectOne(
                'SELECT request_id, username, status, rejection_reason, reviewed_at
                 FROM user_registration_requests WHERE request_id = ?',
                [$trimmed],
            );

            if ($row === null) {
                return response()->json(['found' => false, 'message' => 'درخواست یافت نشد.']);
            }

            return response()->json([
                'found' => true,
                'data' => LegacySerializer::row('user_registration_requests', $row, self::STATUS_TYPES),
            ]);
        } catch (Throwable) {
            return response()->json(['found' => false, 'message' => 'خطا در بررسی.']);
        }
    }

    // ── Departments ────────────────────────────────────────────────────────

    /**
     * `GET /registration/departments` — `get_departments`.
     *
     * Static option data: the registration form and the admin queue both read
     * it, and the list is part of the stored request's meaning (`department`
     * holds one of these strings verbatim).
     *
     * @return array<string, mixed>
     */
    public function departments(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'بیوشیمی', 'هورمون', 'میکروب', 'مولکولی', 'مدیریت',
                'پذیرش', 'نمونه گیری', 'جوابدهی', 'حسابداری', 'ایمونولوژی',
                'خدمات', 'فناوری', 'هماتولوژی',
            ],
        ]);
    }

    // ── Work schedules ─────────────────────────────────────────────────────

    /**
     * `GET /registration/work-schedules` — `get_work_schedules`.
     *
     * Static option data, copied byte-for-byte — **including the first label's
     * typo** (`۰۹:۰0`: a Latin `0` where the pattern says Persian).  The value
     * is what gets stored, the label is what the form shows, and both are part
     * of what the running server answers.
     *
     * @return array<string, mixed>
     */
    public function workSchedules(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                ['value' => '16:00 - 09:00', 'label' => '۱۶:۰۰ - ۰۹:۰0 (صبح)'],
                ['value' => '14:00 - 07:00', 'label' => '۱۴:۰۰ - ۰۷:۰۰ (صبح)'],
                ['value' => '15:00 - 08:00', 'label' => '۱۵:۰۰ - ۰۸:۰۰ (صبح)'],
                ['value' => '20:00 - 14:00', 'label' => '۲۰:۰۰ - ۱۴:۰۰ (عصر)'],
                ['value' => '18:00 - 13:00', 'label' => '۱۸:۰۰ - ۱۳:۰۰ (عصر)'],
                ['value' => '13:30 - 06:30', 'label' => '۱۳:۳۰ - ۰۶:۳۰'],
                ['value' => '20:00 - 13:00', 'label' => '۲۰:۰۰ - ۱۳:۰۰'],
                ['value' => '14:30 - 07:30', 'label' => '۱۴:۳۰ - ۰۷:۳۰'],
                ['value' => '20:30 - 13:30', 'label' => '۲۰:۳۰ - ۱۳:۳۰'],
                ['value' => 'official', 'label' => 'رسمی'],
                ['value' => 'unofficial', 'label' => 'غیر رسمی'],
            ],
        ]);
    }

    // ── Active users (substitute selection) ────────────────────────────────

    /**
     * `GET /registration/active-users` — `get_active_users`.
     *
     * The one registration endpoint that guards itself rather than through the
     * `admin` middleware: both refusals — anonymous *and* authenticated without
     * `is_admin` — answer the same `403 {"success": false, "error": …}` body,
     * which the captured reference server confirms and the middleware's
     * anonymous `401 لاگین نکرده‌اید.` does not.
     *
     * A database failure answers `success: true` with an empty list: the form
     * renders without a substitute picker rather than failing to open.
     */
    public function activeUsers(Request $request): JsonResponse
    {
        $username = $request->session()->get('username');
        $isAdmin = $request->session()->get('is_admin') === true;

        if (! $username || ! $isAdmin) {
            return response()->json(['success' => false, 'error' => 'دسترسی مدیریتی ندارید.'], 403);
        }

        try {
            $rows = DB::select(
                "SELECT username, name, last_name, department FROM user_table
                 WHERE COALESCE(is_active, 'active') = 'active' ORDER BY name",
            );

            $data = [];

            foreach ($rows as $row) {
                $data[] = [
                    'username' => DisplayText::strip(DisplayText::pyStrOrEmpty($row->username ?? null)),
                    'name' => DisplayText::strip(
                        DisplayText::pyStrOrEmpty($row->name ?? null).' '.DisplayText::pyStrOrEmpty($row->last_name ?? null),
                    ),
                    'department' => DisplayText::pyStrOrEmpty($row->department ?? null),
                ];
            }

            return response()->json(['success' => true, 'data' => $data]);
        } catch (Throwable) {
            return response()->json(['success' => true, 'data' => []]);
        }
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * `_rate_limited()` + `_too_many_requests()` — one check, one refusal.
     *
     * The Python's limiter records the hit only while the caller is *under* the
     * limit and never records a denied request, which is exactly
     * `tooManyAttempts()` followed by `hit()`.  A denial is therefore
     * repeatable and does not extend the window, as there.
     *
     * The body is the module's own envelope — deliberately **not**
     * `LegacyHttpException::throttled()`'s `{"detail": …}` — because
     * `registration.py` answers `success`/`message` here.
     */
    private function throttle(Request $request, string $bucket, int $limit): ?JsonResponse
    {
        $key = 'hastama.registration.'.$bucket.':'.ClientAddress::for($request);

        if (RateLimiter::tooManyAttempts($key, $limit)) {
            return response()->json([
                'success' => false,
                'message' => 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً بعداً تلاش کنید.',
            ], 429);
        }

        RateLimiter::hit($key, self::WINDOW_SECONDS);

        return null;
    }

    /**
     * `_validate_national_id()` — ten digits plus the Iranian checksum.
     *
     * Python's `str.isdigit()`/`int()` accept the Arabic-Indic and Persian
     * digit blocks as well as ASCII, so the digits are folded to Latin first
     * and the checksum is then computed exactly as there (`sum` of
     * `digit * (10 - i)` over the first nine, `mod 11`, check digit in
     * `[0, 1]` directly or `11 - r` otherwise).
     */
    private static function validNationalId(string $nationalId): bool
    {
        $nationalId = DisplayText::strip($nationalId);

        if (mb_strlen($nationalId) !== 10) {
            return false;
        }

        $digits = PersianText::toLatinDigits($nationalId);

        if (preg_match('/^[0-9]{10}$/', $digits) !== 1) {
            return false;
        }

        $check = (int) $digits[9];
        $sum = 0;

        for ($i = 0; $i < 9; $i++) {
            $sum += ((int) $digits[$i]) * (10 - $i);
        }

        $remainder = $sum % 11;

        return $remainder < 2 ? $check === $remainder : $check === 11 - $remainder;
    }

    /**
     * `_validate_mobile()` — `09…`, `+989…` or `989…`, spaces and hyphens
     * removed first.
     *
     * The pattern uses `\d` under `/u`, as Python's does: `\d` there matches
     * every Unicode decimal digit, so a Persian-digit number passes the format
     * check and is stored with the digits it arrived with.
     */
    private static function validMobile(string $mobile): bool
    {
        $mobile = str_replace([' ', '-'], '', DisplayText::strip($mobile));

        return preg_match('/^09\d{9}$/u', $mobile) === 1
            || preg_match('/^\+989\d{9}$/u', $mobile) === 1
            || preg_match('/^989\d{9}$/u', $mobile) === 1;
    }

    /**
     * `_normalize_mobile()` — `+98…`/`98…` → `0…`, spaces and hyphens dropped.
     *
     * The digits themselves are never rewritten: the Python only strips two
     * separators and swaps two prefixes, so the stored value keeps whatever
     * digit script it arrived in.
     */
    private static function normalizeMobile(string $mobile): string
    {
        $mobile = str_replace([' ', '-'], '', DisplayText::strip($mobile));

        if (str_starts_with($mobile, '+98')) {
            return '0'.substr($mobile, 3);
        }

        if (str_starts_with($mobile, '98')) {
            return '0'.substr($mobile, 2);
        }

        return $mobile;
    }
}
