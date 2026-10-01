<?php

namespace App\Support\Legacy;

/**
 * `str.strip()` — Python's whitespace rule, in one place.
 *
 * The legacy application calls `.strip()` on nearly every value it reads and on every
 * value a human typed, so the port needs it too — for patient names at the kiosk, for
 * `system_config` values read out of the database, and for path parameters.  PHP's
 * `trim()` is **not** the same function and differs in both directions:
 *
 * | | PHP `trim()` | Python `str.strip()` |
 * |---|---|---|
 * | NUL U+0000 | stripped | **kept** |
 * | NEL U+0085, NBSP U+00A0, OGHAM U+1680 | kept | **stripped** |
 * | U+2000–U+200A, U+2028/29, U+202F, U+205F, U+3000 | kept | **stripped** |
 * | U+001C–U+001F | kept | **kept** (but `isspace()` says otherwise) |
 *
 * The last row is the trap: `chr(0x1c).isspace()` is `True`, so the obvious port would
 * strip U+001C–U+001F, and pydantic — which trims in Rust with `str::trim`, following the
 * Unicode *White_Space* property — does not.  `chr(0x1c)+'5'` is `5` to Python's strip
 * and an `int_parsing` failure to pydantic.  The set below is *White_Space*, which is what
 * the running server does, and it was checked both ways:
 *
 *     chr(0x1c) + '5'   -> int_parsing    # White_Space says no
 *     chr(0x85) + '5'   -> 5              # NEL is White_Space
 *     chr(0x00) + '5'   -> int_parsing    # neither strips NUL
 *
 * Where the two agree — which is every ASCII space a form can actually produce — the
 * result is identical, so using this everywhere `.strip()` appeared does not narrow or
 * widen any value the legacy application accepted.
 */
final class LegacyWhitespace
{
    /** The full set, at either end: exactly Unicode *White_Space*. */
    private const PATTERN = '/^[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}'
        .'\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+'
        .'|[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}'
        .'\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+$/uD';

    /** `value.strip()` — whitespace at both ends removed, everything else untouched. */
    public static function strip(string $value): string
    {
        $stripped = preg_replace(self::PATTERN, '', $value);

        // A malformed-UTF-8 subject makes PCRE fail; Python would have stripped nothing
        // visible and kept the bytes, so keep the value rather than lose it.
        return $stripped ?? $value;
    }
}
