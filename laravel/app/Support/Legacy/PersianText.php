<?php

namespace App\Support\Legacy;

/**
 * Text handling for the legacy SQL Server schema.
 *
 * Two quirks of the existing database are handled here, in one place, because
 * they are the difference between "login works" and "login mysteriously fails":
 *
 * 1. **Trailing space padding.**  The legacy generation of tables uses fixed
 *    length columns (`nchar(10)` for `user_table.password`, `.role`,
 *    `.hozoor_num`, every `*pss_table.username`, …) and later rows were copied
 *    into `nvarchar` columns with their padding intact.  Measured on the live
 *    database: 11 usernames, 13 `hozoor_num` values and all 16 `role` values
 *    return with trailing spaces, and `role` comes back as `'admin     '`.
 *    The Python application strips whitespace at nearly every read site and
 *    every lookup uses `LTRIM(RTRIM(...))`; the Eloquent models reproduce that
 *    by trimming attribute values on read (see `TrimsLegacyStrings`).
 *
 * 2. **Two spellings of the Persian letters yeh and kaf.**  Seven usernames were
 *    entered with the Arabic yeh `ي` (U+064A) and two with the Persian yeh `ی`
 *    (U+06CC); the same split exists for kaf.  A byte comparison therefore
 *    treats two visually identical names as different users, so the existing
 *    application folds them with
 *    `REPLACE(REPLACE(…, N'ي', N'ی'), N'ك', N'ک')` before comparing.  That
 *    expression is reproduced by `normalizedColumnExpression()` so lookups keep
 *    matching exactly the rows the old application matched.
 */
final class PersianText
{
    /** Arabic yeh — the letter the legacy data mostly uses. */
    public const ARABIC_YEH = "\u{064A}";

    /** Persian yeh — the letter the application normalises *to*. */
    public const PERSIAN_YEH = "\u{06CC}";

    /** Arabic kaf. */
    public const ARABIC_KAF = "\u{0643}";

    /** Persian kaf — the letter the application normalises *to*. */
    public const PERSIAN_KAF = "\u{06A9}";

    /**
     * Remove the trailing padding the fixed-length columns add.
     *
     * Deliberately `rtrim` and not `trim`: leading whitespace is never inserted
     * by the database, so there is nothing to gain from removing it, and a value
     * that legitimately starts with a space keeps it.
     */
    public static function trim(?string $value): string
    {
        return $value === null ? '' : rtrim($value);
    }

    /**
     * Remove whitespace from **both** ends — what the Python application does.
     *
     * The existing code calls `.strip()` at nearly every read site and on every
     * value a human typed, so anything used for *comparison* or read out of
     * `system_config` must match that.  `trim()` above is the narrower
     * rtrim-only operation for raw column padding; keep the two apart, because
     * confusing them is how `' Enabled '` stops being recognised as a flag.
     */
    public static function strip(?string $value): string
    {
        return trim($value ?? '');
    }

    /**
     * Fold the Arabic yeh/kaf onto their Persian forms, then trim.
     *
     * This is a pure string operation, so it can also be used to compare values
     * that were already read out of the database (for example to detect the
     * duplicate `hozoor_num` group that exists in the live data).
     */
    public static function normalizeLetters(?string $value): string
    {
        return str_replace(
            [self::ARABIC_YEH, self::ARABIC_KAF],
            [self::PERSIAN_YEH, self::PERSIAN_KAF],
            self::strip($value),
        );
    }

    /**
     * The comparison form of a username: letters folded, whitespace trimmed and
     * case folded.  Case folding matters because the Python application compares
     * with `.lower()` / `.casefold()` in several places.
     */
    public static function foldUsername(?string $value): string
    {
        return mb_strtolower(self::normalizeLetters($value), 'UTF-8');
    }

    /**
     * SQL expression selecting the comparison form of a column, byte-for-byte the
     * expression the Python application uses:
     *
     *     REPLACE(REPLACE(LTRIM(RTRIM(<column>)), N'ي', N'ی'), N'ك', N'ک')
     *
     * `$column` is a column identifier, never user input — callers pass literals.
     * Any identifier that is not a plain `table.column` / `column` pair is
     * rejected instead of being interpolated.
     */
    public static function normalizedColumnExpression(string $column): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) !== 1) {
            throw new \InvalidArgumentException("Refusing to build an expression from: {$column}");
        }

        return "REPLACE(REPLACE(LTRIM(RTRIM({$column})), N'"
            .self::ARABIC_YEH."', N'".self::PERSIAN_YEH."'), N'"
            .self::ARABIC_KAF."', N'".self::PERSIAN_KAF."')";
    }

    /**
     * Latin digits → Persian digits, mirroring `app/static/js/number-format.js`.
     *
     * Kept server-side as well because a few endpoints return pre-formatted
     * strings (report titles, log summaries) that the old application already
     * rendered with Persian digits.
     */
    public static function toPersianDigits(string $value): string
    {
        return str_replace(
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'],
            $value,
        );
    }

    /** Persian (and Arabic-Indic) digits → Latin digits. */
    public static function toLatinDigits(string $value): string
    {
        return str_replace(
            ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'],
            ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '0', '1', '2', '3', '4', '5', '6', '7', '8', '9'],
            $value,
        );
    }
}
