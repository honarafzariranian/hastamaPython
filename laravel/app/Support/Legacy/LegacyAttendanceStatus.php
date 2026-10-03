<?php

namespace App\Support\Legacy;

/**
 * The manual check-in / check-out helpers — a direct port of
 * `app/services/attendance.py`.
 *
 * The attendance record lives in the existing `hozoor` table (`username`,
 * `date`, `vrood`, `khoroj`) — the same table the manual attendance endpoint
 * (`POST /sabt_hozoor`) writes and the attendance report
 * (`GET /get_hozoor/{username}`) reads.  No new storage shape is introduced.
 *
 * Two functions, both pure, both ported line-for-line:
 *
 * * `formatTimeValue()` — a database time value to an `HH:MM` string, or
 *   `null` when empty.  This is the single place that decides what "empty"
 *   means for a `vrood`/`khoroj` column, and its answer is load-bearing: the
 *   status mapping below treats a `null` check-in as "not checked in".
 * * `computeAttendanceStatus()` — one `hozoor` row mapped to the presence
 *   status the front-end renders.
 *
 * The status vocabulary is the module's own and is reproduced exactly:
 * `not_checked_in`, `checked_in`, `checked_out`.
 */
final class LegacyAttendanceStatus
{
    public const STATUS_NOT_CHECKED_IN = 'not_checked_in';

    public const STATUS_CHECKED_IN = 'checked_in';

    public const STATUS_CHECKED_OUT = 'checked_out';

    /**
     * A database time value as an `HH:MM` string, or `null` when empty.
     *
     * `pyodbc` handed back a `datetime.time` for the `time` columns, which the
     * Python formatted with `strftime("%H:%M")`; `pdo_sqlsrv` hands back a
     * string, so the string branch is what the running port actually exercises.
     * Both are reproduced.
     *
     * The "empty" set is wider than "NULL or empty string": the legacy rows
     * contain the literal words `none`, `null`, `na`, `n/a`, `-` and `no`,
     * and a status computed from one of those must read as "no time", not as
     * the parse of a word.  The comparison is case-insensitive.
     */
    public static function formatTimeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        $text = trim((string) $value);

        if ($text === '' || in_array(strtolower($text), ['none', 'null', 'na', 'n/a', '-'], true)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $text);
        $digits = $digits === null ? '' : $digits;

        if (strlen($digits) === 4) {
            return substr($digits, 0, 2).':'.substr($digits, 2);
        }

        if (strlen($digits) === 6) {
            return substr($digits, 0, 2).':'.substr($digits, 2, 2);
        }

        return $text;
    }

    /**
     * Map one `hozoor` row (`vrood`/`khoroj`) to a presence status.
     *
     * @return array{status: string, check_in: string|null, check_out: string|null}
     */
    public static function computeAttendanceStatus(mixed $vrood, mixed $khoroj): array
    {
        $checkIn = self::formatTimeValue($vrood);
        $checkOut = self::formatTimeValue($khoroj);

        if ($checkIn !== null && $checkOut !== null) {
            $status = self::STATUS_CHECKED_OUT;
        } elseif ($checkIn !== null) {
            $status = self::STATUS_CHECKED_IN;
        } else {
            $status = self::STATUS_NOT_CHECKED_IN;
        }

        return [
            'status' => $status,
            'check_in' => $checkIn,
            'check_out' => $checkOut,
        ];
    }
}
