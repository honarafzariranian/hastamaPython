<?php

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\ResponseFactory;

/**
 * `response()->json()` — encoded the way FastAPI encoded it.
 *
 * Laravel's default `json_encode` options are **`0`**, which escapes every non-ASCII
 * character (`\u0644\u0627\u06af\u06cc\u0646`) and every forward slash (`\/`).
 * Starlette called `json.dumps(..., ensure_ascii=False)`, so the running server puts
 * the Persian text on the wire as raw UTF-8:
 *
 * ```
 * legacy : {"success":false,"error":"لاگین نکردهاید."}
 * Laravel: {"success":false,"error":"\u0644\u0627\u06af\u06cc\u0646 ..."}
 * ```
 *
 * A JSON decoder reads those as the same value, so nothing in either front-end
 * *breaks* — but almost every message this application returns is Persian, and the two
 * servers are meant to be interchangeable on the wire during the side-by-side period.
 * `Content-Length` differs, a byte-for-byte response diff fails, and any proxy or ETag
 * keyed on the raw body would see two different resources. `JSON_UNESCAPED_SLASHES`
 * comes along for the same reason: FastAPI did not escape `/` either, and the audit
 * and search results carry them in `resource_id` and `link`.
 *
 * `$options` passed explicitly by a caller are **kept**, with the two legacy flags
 * OR-ed in — a caller may add `JSON_PRETTY_PRINT`, but cannot accidentally restore
 * escaping.  That is deliberate: escaping is a Laravel default this application
 * rejects, not a per-call choice.
 */
final class LegacyResponseFactory extends ResponseFactory
{
    /**
     * What Starlette's `ensure_ascii=False` amounts to in PHP.
     *
     * Note what is *not* here: `JSON_UNESCAPED_LINE_TERMINATORS` and PHP's
     * `json_encode` both leave the U+2028/U+2029 pair escaped by default, and FastAPI
     * did the same — so they are left alone rather than "fixed".
     */
    public const ENCODING = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
    {
        return parent::json($data, $status, $headers, (int) $options | self::ENCODING);
    }
}
