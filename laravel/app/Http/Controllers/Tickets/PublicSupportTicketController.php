<?php

namespace App\Http\Controllers\Tickets;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\Legacy\LegacyDate;
use App\Support\Legacy\LegacyWhitespace;
use App\Support\Tickets\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `POST /public/support-ticket` — the anonymous account-recovery form.
 *
 * Ported from `public_support_ticket` in `app/api/routes/auth.py` (lines
 * 679–773).  No session, no CSRF token (`/public/` is an exempt prefix): a
 * visitor who has forgotten their username **and** password cannot use the
 * signed-in recovery flow, so this is the one way in.
 *
 * The body is **not** a pydantic model — the handler reads it field by field
 * and answers `400 {"success": false, "message": …}` for each refusal, in the
 * Python's order: parse, then `full_name`, then `phone`, then the three length
 * bounds.  The lengths are the Python's `len()` — characters, not bytes — and
 * the values are stripped with Python's `str.strip()` before them, which is
 * why {@see LegacyWhitespace::strip} is used rather than PHP's `trim()`.
 *
 * Three behaviours are load-bearing and reproduced rather than "fixed":
 *
 * * **A body that is not JSON at all is a 400** — `داده نامعتبر.` — while a
 *   body that parses to a non-object (`[1]`, `5`, `null`) is a **500**: the
 *   Python calls `body.get(...)` on it and `AttributeError` is unhandled.
 *   Starlette answers a plain-text `Internal Server Error`, which is what the
 *   top-level catch here returns.
 * * **The rate limit fails closed.**  The per-IP hourly count is a database
 *   query, and any failure of it answers `503 سرویس موقتاً در دسترس نیست. …`
 *   rather than allowing unthrottled abuse.
 * * **A non-string `full_name` is a 500**, by the same `AttributeError` path.
 *
 * The ticket itself is created through the same `TicketService::createTicket`
 * the signed-in routes use, with the recipient hardcoded to `ali` — which the
 * service's master-admin check passes under the default
 * `MASTER_ADMIN_USERNAMES`, and a changed one turns into the handler's own
 * JSON 500 rather than a refusal the form could act on.
 */
final class PublicSupportTicketController extends Controller
{
    /** `len(full_name) > 200`. */
    private const MAX_FULL_NAME = 200;

    /** `len(phone) > 20`. */
    private const MAX_PHONE = 20;

    /** `len(description) > 2000`. */
    private const MAX_DESCRIPTION = 2000;

    /** The rate-limit ceiling: 3 tickets per IP per hour. */
    private const RATE_LIMIT = 3;

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(Request $request): Response|JsonResponse
    {
        try {
            return $this->storeInternal($request);
        } catch (Throwable $exception) {
            // Starlette's server-error middleware: plain text when debug is off.
            // The only throwable this handler lets escape is the `AttributeError`
            // reproduction below — every refusal is a returned response.
            return response('Internal Server Error', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        }
    }

    private function storeInternal(Request $request): JsonResponse
    {
        $raw = (string) $request->getContent();

        if (trim($raw) === '') {
            return $this->invalidData();
        }

        $decoded = json_decode($raw);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->invalidData();
        }

        if (! is_object($decoded)) {
            // `[1]`, `5`, `"str"`, `null` — `body.get(...)` raises AttributeError,
            // which Starlette answers with the plain-text 500 above.
            throw new \ErrorException('The request body must be a JSON object.');
        }

        $body = (array) $decoded;

        // `body.get("full_name") or ""` — Python's falsy set for a JSON value.
        $fullName = self::pythonOrEmpty($body, 'full_name');
        $phone = self::pythonOrEmpty($body, 'phone');
        $description = self::pythonOrEmpty($body, 'description');

        if ($fullName === '') {
            return $this->refusal('لطفاً نام و نام خانوادگی را وارد کنید.');
        }

        if ($phone === '') {
            return $this->refusal('لطفاً شماره تماس را وارد کنید.');
        }

        if (mb_strlen($fullName) > self::MAX_FULL_NAME) {
            return $this->refusal('نام بیش از حد طولانی است.');
        }

        if (mb_strlen($phone) > self::MAX_PHONE) {
            return $this->refusal('شماره تماس نامعتبر است.');
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            return $this->refusal('توضیحات بیش از حد طولانی است.');
        }

        $ip = $request->ip() ?? 'unknown';

        try {
            $count = (int) DB::connection()->selectOne(
                "SELECT COUNT(*) AS total FROM ticket_messages m
                 JOIN tickets t ON t.id = m.ticket_id
                 WHERE m.body LIKE ? AND t.requester_username = '__anonymous__'
                   AND t.created_at > DATEADD(HOUR, -1, GETDATE())",
                ['%['.$ip.']%'],
            )->total;
        } catch (Throwable) {
            // Fail closed: if the rate-limit store is unavailable, refuse the
            // anonymous ticket rather than allowing unthrottled abuse.
            return response()->json(
                ['success' => false, 'message' => 'سرویس موقتاً در دسترس نیست. لطفاً کمی بعد تلاش کنید.'],
                503,
            );
        }

        if ($count >= self::RATE_LIMIT) {
            return response()->json(
                ['success' => false, 'message' => 'تعداد درخواست‌ها بیش از حد مجاز است. لطفاً بعداً تلاش کنید.'],
                429,
            );
        }

        $subject = 'درخواست بازیابی اطلاعات ورود - '.$fullName;

        $ticketBody = (
            "کاربر به صورت ناشناس درخواست ایجاد نام کاربری و رمز عبور جدید ارسال کرده است.\n\n"
            ."**نام و نام خانوادگی:** {$fullName}\n"
            ."**شماره تماس:** {$phone}\n"
        );

        if ($description !== '') {
            $ticketBody .= "**توضیحات کاربر:**\n{$description}\n";
        }

        $ticketBody .= (
            "\n---\n"
            ."📍 **آی‌پی درخواست‌دهنده:** {$ip}\n"
            // The Python read the machine's local clock (`time.strftime`); this
            // deployment's wall clock is `hastama.display_timezone`.
            ."🕐 **زمان درخواست:** ".now(LegacyDate::timezone())->format('Y-m-d H:i:s')."\n\n"
            ."⚠️ این کاربر نام کاربری و رمز عبور خود را فراموش کرده و امکان استفاده از بازیابی رمز عبور را ندارد. "
            ."لطفاً پس از بر هویت، نام کاربری و رمز عبور جدیدی برای ایشان ایجاد کنید."
        );

        try {
            $result = (new TicketService())->createTicket(
                actor: '__anonymous__',
                isAdmin: false,
                recipientUsername: 'ali',
                subject: $subject,
                body: $ticketBody,
                priority: 'high',
            );

            $ticketId = $result['id'];
        } catch (Throwable $exception) {
            // The Python logs and answers its own JSON 500 — including the case
            // where a changed MASTER_ADMIN_USERNAMES makes the hardcoded `ali`
            // fail the service's master-admin check.
            report($exception);

            return response()->json(
                ['success' => false, 'message' => 'خطا در ثبت درخواست. لطفاً دوباره تلاش کنید.'],
                500,
            );
        }

        $this->audit->logEvent('SUPPORT', 'anonymous_ticket_created', [
            'username' => '__anonymous__',
            'module' => 'support',
            'status' => 'success',
            'severity' => 'low',
            'ip_address' => $ip,
            'user_agent' => $this->userAgent($request),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'درخواست شما با موفقیت ثبت شد. مدیر سامانه در اسرع وقت با شما تماس خواهد گرفت.',
            'ticket_id' => $ticketId,
        ]);
    }

    /** `400 {"success": false, "message": "داده نامعتبر."}` — the unparseable body. */
    private function invalidData(): JsonResponse
    {
        return response()->json(['success' => false, 'message' => 'داده نامعتبر.'], 400);
    }

    /**
     * `body.get("full_name") or ""` — Python's falsy set for a JSON value, then
     * the strip.
     *
     * The falsy values a JSON body can carry are `null`, `false`, `0`, `0.0`,
     * `""`, `[]` and `{}`; every other value reaches the `.strip()` and a
     * non-string one is the 500 above.
     */
    private static function pythonOrEmpty(array $body, string $key): string
    {
        if (! array_key_exists($key, $body)) {
            return '';
        }

        $value = $body[$key];

        if ($value === null || $value === false || $value === 0 || $value === 0.0 || $value === '' || $value === []) {
            return '';
        }

        if (! is_string($value)) {
            // `int.strip()` — AttributeError in the Python, a plain-text 500.
            throw new \ErrorException("{$key} must be a string.");
        }

        return LegacyWhitespace::strip($value);
    }

    /** `400 {"success": false, "message": …}`. */
    private function refusal(string $message): JsonResponse
    {
        return response()->json(['success' => false, 'message' => $message], 400);
    }

    /**
     * `_user_agent(request)` — the printable characters of the header, capped at
     * 500.
     *
     * Control characters would be an injection vector for log/report consumers,
     * which is why the Python filters them before the cap.
     */
    private function userAgent(Request $request): string
    {
        $value = (string) $request->headers->get('user-agent', '');

        $cleaned = preg_replace('/\p{Cc}|\p{Cf}|\p{Cs}|\p{Co}|\p{Cn}|\p{Zl}|\p{Zp}|(?<!\x{20})\p{Zs}/u', '', $value) ?? '';

        return mb_substr($cleaned, 0, 500);
    }
}
