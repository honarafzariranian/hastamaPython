<?php

namespace App\Support\Legacy;

use ValueError;

/**
 * The Python scalar coercions the ported handlers rely on, in one place.
 *
 * Three helpers, each a line-for-line port of a Python idiom that is easy to
 * "improve" into a behaviour change:
 *
 * * `pyInt()` — `int(value)`.  Python's `int()` accepts surrounding whitespace,
 *   a sign and underscores between digits, truncates a float, and raises
 *   `TypeError` for `None` / a list but `ValueError` for a non-integer string.
 *   The distinction is observable: a missing `jalali_year` is a 500 in
 *   `add_shift`, not the 200 validation message.
 * * `persianToEnglishDigits()` — the digit translation.  The Python maps only
 *   `۰۱۲۳۴۵۶۷۸۹`; the shared {@see PersianText::toLatinDigits()} also maps
 *   Arabic-Indic digits, so it is deliberately not used here.
 * * `normalizeFaUsername()` — `_normalize_fa_username()`: fold the Arabic
 *   yeh/kaf onto their Persian forms, turn ZWNJ into a space, and collapse the
 *   whitespace.  Shift and attendance lookups compare the *column* through the
 *   `REPLACE(…)` expression and the *parameter* through this function, so both
 *   sides must be normalised or `آی تی` never matches a stored `آي تي`.
 */
final class LegacyPythonScalar
{
    /**
     * `int(value)` — Python's integer parse.
     *
     * @throws ValueError when the value is a non-integer string or a non-scalar.
     * @throws TypeError when the value is null or an array.
     */
    public static function pyInt(mixed $value): int
    {
        if ($value === null || is_array($value)) {
            throw new TypeError('int() argument must be an integer literal');
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            if (preg_match('/^[+-]?\d(_?\d)*$/', $value) === 1) {
                return (int) str_replace('_', '', $value);
            }
        }

        throw new ValueError('invalid literal for int()');
    }

    /**
     * Persian digits to Latin, nothing else.
     */
    public static function persianToEnglishDigits(string $value): string
    {
        return str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value,
        );
    }

    /**
     * `_normalize_fa_username()` — fold Arabic yeh/kaf, ZWNJ to space, collapse.
     */
    public static function normalizeFaUsername(mixed $value): string
    {
        $text = str_replace(
            [PersianText::ARABIC_YEH, PersianText::ARABIC_KAF, "\u{200C}"],
            [PersianText::PERSIAN_YEH, PersianText::PERSIAN_KAF, ' '],
            strval($value ?? ''),
        );

        return (string) preg_replace('/\s+/u', ' ', trim($text));
    }
}
