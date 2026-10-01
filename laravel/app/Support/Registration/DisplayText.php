<?php

namespace App\Support\Registration;

use App\Support\Legacy\LegacyDisplayText;

/**
 * `app/core/validation.py` — `clean_display_text()`, ported for the registration
 * surface.
 *
 * The shared {@see LegacyDisplayText} is the same function as the *call system*
 * uses, but this port exists for two reasons, both deliberate:
 *
 * * **The registered character set differs.**  The Python
 *   `_DISPLAY_ALLOWED` includes `-` («نمونه-گیری», «علی-رضا»);
 *   `LegacyDisplayText::ALLOWED` does not, so a hyphenated department or given
 *   name would be accepted by the running server and refused here.  The shared
 *   class is outside this migration's file ownership, so the registration
 *   surface carries its own copy with the hyphen — and the divergence is
 *   reported rather than silently inherited.
 * * **The failure shape differs.**  The call system turns the refusal into
 *   `HTTPException(422, …)`; `submit_registration` catches `ValueError` and
 *   answers `400 {"success": false, "errors": [str(exc)]}`.  The message string
 *   is the contract in both cases, so `clean()` throws
 *   {@see DisplayTextError} and the controller translates — exactly where the
 *   Python's `try/except ValueError` block sits.
 *
 * The two string conversions the Python performs are reproduced separately,
 * because they are *not* the same conversion:
 *
 * * `clean_display_text` does `str(value if value is not None else "")` —
 *   `False` becomes `"False"`, `0` becomes `"0"`;
 * * the submit field extraction does `str(data.get(x) or "")` — every **falsy**
 *   value (`None`, `False`, `0`, `0.0`, `""`, `[]`, `{}`) becomes `""` first,
 *   so `"0"`-looking input and a literal `0` are different requests.
 *
 * Python's `str()` of a dict or list is a repr with single quotes; `json_encode`
 * is used instead, because such a value always fails the allowed-set check
 * either way (both representations contain characters that are not in it), and
 * only a value that is already at the length boundary could tell the two
 * representations apart.
 */
final class DisplayText
{
    /**
     * Python's `_DISPLAY_ALLOWED`, `\x{…}` blocks applying to characters.
     *
     * Byte-for-byte the Python pattern **including the hyphen** — the one
     * character `LegacyDisplayText` drops.  `$` is unanchored from `D` on both
     * sides, so a single trailing newline passes here as it did there.
     */
    private const ALLOWED = '/^[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}'
        .'A-Za-z0-9 .,_\-\/()\x{200c}\x{200f}]+$/u';

    /**
     * `clean_display_text(value, max_length=…, field=…)` — or a `ValueError`
     * with the operator-facing Persian message.
     *
     * @throws DisplayTextError
     */
    public static function clean(
        mixed $value,
        int $maxLength,
        string $field = 'value',
        bool $required = false,
        bool $allowPunctuation = true,
    ): string {
        $text = self::strip(LegacyDisplayText::stripControl(self::pyStr($value)));

        if ($text === '') {
            if ($required) {
                throw new DisplayTextError("{$field} الزامی است.");
            }

            return '';
        }

        // `len()` counted characters, and the text may be Persian.
        if (mb_strlen($text) > $maxLength) {
            throw new DisplayTextError("{$field} نباید بیش از {$maxLength} کاراکتر باشد.");
        }

        // `"&" in text and "&amp;" not in text` — the Python's precedence, kept
        // by the parentheses rather than by operator order.
        if (str_contains($text, '<') || str_contains($text, '>')
            || (str_contains($text, '&') && ! str_contains($text, '&amp;'))) {
            throw new DisplayTextError("{$field} شامل کاراکترهای غیرمجاز است.");
        }

        if ($allowPunctuation && preg_match(self::ALLOWED, $text) !== 1) {
            throw new DisplayTextError("{$field} شامل کاراکترهای غیرمجاز است.");
        }

        return $text;
    }

    /**
     * Python's `str.strip()` — Unicode whitespace, not PHP's ASCII-only `trim()`.
     *
     * `mb_trim()` (PHP 8.4) is the same set — the `White_Space` property — while
     * `trim()` would leave a non-breaking space in place, turning `q.strip()` into
     * a different string than the running server computes.  The fallback exists
     * only for the `^8.3` floor in `composer.json`.
     */
    public static function strip(string $text): string
    {
        return function_exists('mb_trim') ? mb_trim($text) : trim($text);
    }

    /**
     * `str(value if value is not None else "")` — the conversion inside
     * `clean_display_text`.
     */
    public static function pyStr(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            // `str(1.0)` is `"1.0"` in Python; `(string) 1.0` is `"1"` in PHP.
            return var_export($value, true);
        }

        if (is_string($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? '' : $encoded;
    }

    /**
     * `str(data.get(x) or "")` — the conversion the submit handler performs on
     * `national_id`, `mobile`, `username` and `password`.
     *
     * The truthiness test is Python's, not PHP's: the string `"0"` is **truthy**
     * in Python and falsy in PHP, and an empty dict is falsy in both despite
     * every PHP object being truthy.
     */
    public static function pyStrOrEmpty(mixed $value): string
    {
        return self::pyTruthy($value) ? self::pyStr($value) : '';
    }

    /** Python `bool(x)` for the JSON value types the submit body can carry. */
    private static function pyTruthy(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '') {
            return false;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        if (is_array($value)) {
            return $value !== [];
        }

        if (is_object($value)) {
            return (array) $value !== [];
        }

        return true;
    }
}
