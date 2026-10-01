<?php

namespace App\Http\Controllers\CallSystem;

use App\Broadcasting\DisplayBroadcaster;
use App\Http\Controllers\Controller;
use App\Services\CallSystem\DisplayQueue;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Shared ground for the call-system controllers.
 *
 * `app/api/routes/call_system.py` is one module with a handful of helpers at the top, and
 * the helpers are where the contract lives: the event object every screen understands,
 * the timestamp format, and the two ways a request body is read.  Splitting the handlers
 * across controllers (they are 36 routes and one class would be 1,800 lines of PHP) moved
 * those helpers here rather than copying them, because a divergence in the event object
 * is the one thing that would break every display at once.
 *
 * **The event object is the wire format.**
 *
 * ```json
 * {"type": "reception_call",
 *  "data": {"number": "12", "persian_number": "۱۲", "department": "نمونه‌گیری",
 *           "message": "…", "voice": "…", "timestamp": "2026-09-30T08:53:41", "is_test": false}}
 * ```
 *
 * The display client switches on `type` and speaks `data.voice` aloud, so the field names,
 * the Persian digits and even the *wording* of `message` are load-bearing.  `timestamp` is
 * the one deliberate exception: the Python used a **naive local-looking** UTC stamp with
 * no `Z` (`strftime("%Y-%m-%dT%H:%M:%S")`), unlike the `_iso()` used for stored rows, and
 * that is what the displays have always received.
 */
abstract class CallSystemController extends Controller
{
    public function __construct(
        protected readonly DisplayBroadcaster $display,
        protected readonly DisplayQueue $displayQueue,
    ) {}

    /**
     * `datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")` — no fractional part, no
     * zone marker.
     *
     * Deliberately not `_iso()`/`isoUtcZ()`: the broadcast timestamp and the stored-row
     * timestamp are different strings in the running application, and the displays parse
     * this one.
     */
    protected function broadcastTimestamp(): string
    {
        return Carbon::now('UTC')->format('Y-m-d\TH:i:s');
    }

    /**
     * The `reception_call` payload, in the Python's field order.
     *
     * @param  array<string, mixed>  $extra  Appended after `is_test` — `audio_available`
     *                                       and `is_queue_ticket` are the two the module
     *                                       adds, and both are read by the display.
     * @return array<string, mixed>
     */
    protected function receptionCallData(
        string $number,
        string $department,
        bool $isTest,
        array $extra = [],
        ?string $messageOverride = null,
        ?string $voiceOverride = null,
    ): array {
        $persian = PersianText::toPersianDigits($number);

        return array_merge([
            'number' => $number,
            'persian_number' => $persian,
            'department' => $department,
            'message' => $messageOverride ?? 'لطفاً به بخش '.$department.' مراجعه کنید.',
            'voice' => $voiceOverride ?? 'شماره '.$persian.'، لطفاً به بخش '.$department.' مراجعه کنید.',
            'timestamp' => $this->broadcastTimestamp(),
            'is_test' => $isTest,
        ], $extra);
    }

    /**
     * `_validate_reception_number` — the reception number, or a 422.
     *
     * The regex the Python declared (`^[\w\u0600-\u06FF\-/.\s]{1,50}$`) is **not** what it
     * enforced: only the length and the markup check run before it returns.  The regex is
     * reproduced here as nothing at all, on purpose — enforcing it would start refusing
     * numbers the running server accepts, and reception numbers are free-form by design
     * (they are the lab's own labels, not a numeric sequence).
     *
     * @throws LegacyHttpException 422
     */
    protected function validateReceptionNumber(mixed $value): string
    {
        $number = trim((string) ($value ?? ''));

        if ($number === '') {
            throw LegacyHttpException::detail(422, 'لطفاً شماره پذیرش را وارد کنید.');
        }

        if (mb_strlen($number) > 50) {
            throw LegacyHttpException::detail(422, 'شماره پذیرش بیش از حد طولانی است.');
        }

        if (str_contains($number, '<') || str_contains($number, '>')
            || str_contains(mb_strtolower($number), 'script')) {
            throw LegacyHttpException::detail(422, 'شماره پذیرش شامل کاراکترهای غیرمجاز است.');
        }

        return $number;
    }

    /**
     * `try: body = await request.json() except: body = {}` plus `isinstance(body, dict)`.
     *
     * The body is decoded by hand rather than with `$request->json()`, which only parses
     * when the request *declares* a JSON content type.  FastAPI's `request.json()` parsed
     * whatever arrived, so a kiosk posting an object with the wrong header succeeded here
     * too; a body that is JSON but not an object (an array, a bare string) is not a dict
     * and becomes `{}`.
     *
     * @return array<string, mixed>
     */
    protected function jsonObject(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * The same read, but a body that cannot be read is a 400.
     *
     * `/api/calls/waiting-queue` is the only handler that made this distinction, and the
     * message is its own.
     *
     * @return array<string, mixed>
     *
     * @throws LegacyHttpException 400
     */
    protected function requiredJsonObject(Request $request): array
    {
        $decoded = json_decode($request->getContent(), true);

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw LegacyHttpException::detail(400, 'داده نامعتبر.');
        }

        return $decoded;
    }

    /**
     * The absolute path of a directory under the legacy application's `app/static`.
     *
     * The Python resolved `Path(__file__).resolve().parents[3] / "app" / "static" / …`,
     * which lands on the *FastAPI* application's static tree — not on this one.  During
     * the side-by-side period both applications must see the same audio files and the same
     * uploaded slides, because the displays are still served by whichever one is currently
     * answering.  Serving a copy out of `laravel/public` would mean an uploaded slide
     * appearing on the new server's screens and vanishing from the old one's.
     */
    protected function legacyStaticPath(string $relative): string
    {
        return base_path('../app/static/'.ltrim($relative, '/'));
    }

    /** A `{"success": true, …}` body, which is what every call-system success looks like. */
    protected function ok(array $payload): JsonResponse
    {
        return response()->json(['success' => true] + $payload);
    }
}
