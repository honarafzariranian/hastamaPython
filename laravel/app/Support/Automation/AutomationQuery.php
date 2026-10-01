<?php

namespace App\Support\Automation;

use App\Support\Legacy\LegacyParam;
use App\Support\Legacy\LegacyQuery;

/**
 * The one query parameter this module declares that `LegacyQuery` has no factory for.
 *
 * The upload route's Python signature is
 *
 * ```python
 * async def upload_attachment(
 *     conversation_id: int,
 *     request: Request,
 *     file: UploadFile = File(...),
 *     message_id: int | None = Query(None, ge=1),
 * ) -> ...
 * ```
 *
 * `message_id` is **optional and nullable**, and the difference from an ordinary default
 * is observable: an absent parameter falls back to `null` (the attachment is stored with
 * `message_id = NULL`), while `?message_id=0` is refused with
 * `greater_than_equal` and `?message_id=` with `int_parsing` — both captured from the
 * running server.  `LegacyQuery`'s factories all carry a non-null default (`int(default:
 * 0, …)`), so a nullable one has to be declared somewhere, and it belongs to this module
 * rather than to the shared class because `LegacyParam` documents its instances as coming
 * from `LegacyQuery`: a second constructor call in a controller would quietly restate the
 * declaration rules the shared class owns.
 *
 * The bounds are the Python's — `ge=1` only — and everything else is `LegacyQuery`'s own
 * resolution, so `?message_id=1.5` answers `int_parsing` and `?message_id=-1` answers
 * `greater_than_equal` with `ctx: {"ge": 1}` without this class restating either message.
 */
final class AutomationQuery
{
    /**
     * `message_id: int | None = Query(None, ge=1)`.
     *
     * Absent is `null`, which is what the handler writes into the attachment row; the
     * parameter is *not* `required`, and it is not `nullable` either — a query string
     * never yields `null` for a present parameter (`?message_id=` is the empty string,
     * which `LegacyQuery` parses as `int_parsing`).
     */
    public static function nullableInt(?int $ge = null, ?int $le = null): LegacyParam
    {
        return new LegacyParam(LegacyParam::INT, default: null, ge: $ge, le: $le);
    }
}
