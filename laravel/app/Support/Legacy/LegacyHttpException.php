<?php

namespace App\Support\Legacy;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * `raise HTTPException(status_code=…, detail=…)` — FastAPI's error, body and all.
 *
 * FastAPI renders an `HTTPException` as **`{"detail": "<message>"}`** with the status
 * it was raised with, and the call-system module raises it for every refusal:
 *
 * ```python
 * raise HTTPException(status_code=422, detail="لطفاً شماره پذیرش را وارد کنید.")
 * ```
 *
 * Laravel has no equivalent. `abort(422, '…')` renders `{"message": "…"}` for a JSON
 * request, and a plain `throw new HttpException` goes through the same path — so the
 * key the legacy front-end reads (`detail`) would be missing, and a caller branching on
 * `body.detail` would see `undefined` for every rejection. Phase 5's read surface hit
 * the same shape question on the success side; this is the error side of it.
 *
 * Rendered by a callback in `bootstrap/app.php`, so no controller has to remember to
 * build the envelope — and so the shape cannot drift route by route.
 *
 * Deliberately **not** a general-purpose 4xx: `EnsureAdmin` and `EnsureMasterAdmin`
 * answer `{"success": false, "error": …}` because those are the bodies the Python
 * decorators produced, and the two shapes are both live in the running application.
 */
final class LegacyHttpException extends HttpException
{
    /** A refusal carrying FastAPI's message. */
    public static function detail(int $status, string $message, ?Throwable $previous = null): self
    {
        return new self($status, $message, $previous);
    }

    /** 401 with the message the call-system module uses for a missing session. */
    public static function unauthenticated(): self
    {
        return self::detail(401, 'برای ادامه وارد سامانه شوید.');
    }

    /** 403 with the message the call-system module uses for a non-administrator. */
    public static function forbidden(): self
    {
        return self::detail(403, 'دسترسی مدیریت لازم است.');
    }

    /**
     * 403 — the cross-site refusal.
     *
     * Raised by both guards (`_guard_kiosk_write` and `_guard_queue_pii`) and by the
     * administrator-only slide handlers, always with this one wording.
     */
    public static function crossSite(): self
    {
        return self::detail(403, 'درخواست از مبدأ مجاز نیست.');
    }

    /**
     * 429 — the per-IP rate limit, with the module's one message.
     *
     * The U+200C between «درخواست» and «ها» is written as an escape because it is
     * invisible: the Python literal `"تعداد درخواست‌ها بیش از حد مجاز است."` contains a
     * zero-width non-joiner, and a hand-retyped copy of this message drops it without
     * looking any different in an editor.  A Persian reader sees the wrong word spelling
     * and a byte comparison sees a different string, so the character is spelled out.
     */
    public static function throttled(): self
    {
        return self::detail(429, "تعداد درخواست\u{200C}ها بیش از حد مجاز است.");
    }

    /** The response body FastAPI would have produced. */
    public function body(): array
    {
        return ['detail' => $this->getMessage()];
    }
}
