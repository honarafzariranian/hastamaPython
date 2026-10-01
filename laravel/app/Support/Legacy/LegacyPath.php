<?php

namespace App\Support\Legacy;

/**
 * A declared `{int}` path parameter — FastAPI's path validation, error body and all.
 *
 * FastAPI validates a handler's declared path parameters **before** it calls the handler:
 *
 * ```python
 *
 * @router.delete("/calls/waiting-queue/{item_id}")
 * async def remove_from_waiting_queue(request: Request, item_id: int): ...
 * ```
 *
 * ```json
 * // DELETE /api/calls/waiting-queue/abc
 * {"detail":[{"type":"int_parsing","loc":["path","item_id"],
 *             "msg":"Input should be a valid integer, unable to parse string as an integer",
 *             "input":"abc"}]}
 * ```
 *
 * Laravel has no equivalent: a `{param}` bound to an `int` argument raises a `TypeError`
 * inside the dispatcher and answers **500** with a stack trace, which is what the port did
 * until both servers were diffed.  Declaring the parameter as a `string` and parsing it
 * here gives the same status, the same `type`/`msg`, and the same `loc` — with `"path"`
 * where `LegacyQuery` puts `"query"` and `LegacyBody` puts `"body"`.
 *
 * ### The grammar
 *
 * pydantic's lax `int` accepts considerably more than `ctype_digit()`, and the accepted
 * set was derived by asking the running server rather than by reading the source.  With
 * `$value` first stripped of Python's whitespace:
 *
 * ```
 * [+-]? [0-9] (_? [0-9])* (\. 0+ )?
 * ```
 *
 * so `+5`, `05`, `5_0`, `1_2_3`, `5.0`, `-5.0`, `+5_0.00` and `1_0.0` are integers, while
 * `abc`, `5x`, `5.5`, `.5`, `5.`, `1e3`, `0x10`, `5__0`, `_50`, `50_`, `5,0`, `inf`, `nan`
 * and the fullwidth `５` are not.  The two surprises are load-bearing:
 *
 * * **`5.0` parses but `1e3` does not.**  The fractional part must be all zeroes — pydantic
 *   accepts a trailing `.0` and nothing else, so `5.0000` is `5` and `5.0000000000000001`
 *   is a `int_parsing` failure.
 * * **underscores are digits separators** (`5_0` is `50`), which is Python's `int()` rule;
 *   they may appear only *between* digits, so `5_` and `_50` are refused.
 *
 * `input` echoes the segment **as received**, before stripping — `%20abc` answers
 * `" abc"` — which is why the raw value is kept alongside the stripped one.
 * * ### Stripping whitespace, and whose
 *
 * The trimmed set is **not** Python's `str.isspace()`, and the difference is
 * observable: pydantic-core trims in Rust with `str::trim`, which follows the
 * Unicode *White_Space* property.  Python's `isspace()` is wider — it also answers
 * true for the file/group/record/unit separators U+001C–U+001F — so a segment of
 * `"\x1c5"` is `5` to `str.strip()` and an `int_parsing` failure to pydantic:
 *
 *     chr(0x1c) + '5'   -> ERR int_parsing     # Python would have stripped it
 *     chr(0x85) + '5'   -> OK 5                # NEL is White_Space
 *     chr(0x00) + '5'   -> ERR int_parsing     # neither trims NUL
 *
 * {@see LegacyWhitespace} holds that set, which is White_Space and nothing more.  It
 * is wider than PHP's `trim()` default (` \t\n\r\0\x0B`) in every direction that
 * matters and narrower than Python's in one, and U+200B and U+180E are not whitespace
 * to either — correctly left in place to fail the grammar.
 *
 * The trim applies to the **whole segment once**, not after the sign: `"\t+5"` is `5`
 * because the tab falls outside, while `"+\t5"` is a failure because the tab sits
 * between the sign and the digits.
 *
 * ### Beyond the range of a PHP `int`
 *
 * Python integers are unbounded, so `99999999999999999999999` parses to a perfectly
 * valid `int` and is then handed to SQL Server, which overflows it — the running FastAPI
 * answers **500** for that input, which is a crash in the *Python* rather than a
 * validation failure.  PHP saturates at `PHP_INT_MAX` instead, so the value simply does
 * not match any row and the request answers `404` with the module's own
 * `نوبتی با این شماره یافت نشد.`  A refusal that names the condition beats a stack
 * trace, so the divergence is recorded rather than reproduced — the same call already
 * made for `POST /api/calls/remove`.
 */
final class LegacyPath
{
    /** The pydantic grammar above, after stripping. */
    private const INTEGER = '/^[+-]?[0-9](?:_?[0-9])*(?:\.(?:0+))?$/';

    /**
     * Parse a path segment as pydantic would, or refuse the request.
     *
     * @param  string  $value  The segment as the router decoded it.
     * @param  string  $name  The **Python** parameter name (`item_id`), which is what
     *                        `loc[1]` carries — not Laravel's camelCase route name.
     *
     * @throws LegacyValidationException 422 with FastAPI's `int_parsing` detail.
     */
    public static function int(string $value, string $name): int
    {
        $parsed = self::parse($value);

        if ($parsed === null) {
            throw new LegacyValidationException([[
                'type' => 'int_parsing',
                'loc' => ['path', $name],
                'msg' => 'Input should be a valid integer, unable to parse string as an integer',
                'input' => $value,
            ]]);
        }

        return $parsed;
    }

    /**
     * `null` when the segment is not an integer, otherwise its value.
     *
     * Exposed for callers that have to merge this failure with a body failure — FastAPI
     * reports a bad path *and* a bad body in one `detail` list, path error first, and
     * `PUT /api/queue/ticket/{n}` is the endpoint where both can fail at once.
     */
    public static function parse(string $value): ?int
    {
        $trimmed = LegacyWhitespace::strip($value);

        if (preg_match(self::INTEGER, $trimmed) !== 1) {
            return null;
        }

        // `_` is a digit separator, not a digit; the pattern also let a sign through and
        // a fractional part the pattern guarantees to be all zeroes.  Take them apart.
        $negative = $trimmed[0] === '-';
        $digits = ltrim($trimmed, '+-');

        $dot = strpos($digits, '.');

        if ($dot !== false) {
            $digits = substr($digits, 0, $dot);
        }

        $digits = ltrim(str_replace('_', '', $digits), '0');
        $digits = $digits === '' ? '0' : $digits;

        // Python integers are unbounded and PHP's are not.  Casting an unrepresentable
        // numeric string raises "The float-string … is not representable as an int" — a
        // warning that Laravel escalates to an exception and serves as a 500 stack trace,
        // which is exactly the failure this class exists to avoid.  Saturate instead, so
        // the value lands outside every row and the request is answered 404.
        $limit = (string) PHP_INT_MAX;

        if (strlen($digits) > strlen($limit)
            || (strlen($digits) === strlen($limit) && strcmp($digits, $limit) > 0)) {
            return $negative ? PHP_INT_MIN : PHP_INT_MAX;
        }

        return $negative ? -(int) $digits : (int) $digits;
    }

    /**
     * The `int_parsing` error as an array, for merging with other failures.
     *
     * @return array<string, mixed>|null `null` when the value is a valid integer.
     */
    public static function error(string $value, string $name): ?array
    {
        if (self::parse($value) !== null) {
            return null;
        }

        return [
            'type' => 'int_parsing',
            'loc' => ['path', $name],
            'msg' => 'Input should be a valid integer, unable to parse string as an integer',
            'input' => $value,
        ];
    }
}
