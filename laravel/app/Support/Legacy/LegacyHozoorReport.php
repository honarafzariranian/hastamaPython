<?php

namespace App\Support\Legacy;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Throwable;

/**
 * The attendance-report calculation — the pure half of `GET /get_hozoor/{username}`
 * (`app/main.py:4759`), ported so the rules can be asserted without a database.
 *
 * The handler itself is a reader: it pulls the user's weekly schedule, their
 * `shiftha` overrides, the Araz Access punches and the `hozoor` table rows, then
 * fills every calendar day in the range.  What it does with those rows is three
 * rules, and they are the part a plausible implementation gets wrong:
 *
 * 1. **Work hours resolution** — a `shiftha` range that covers the day wins over
 *    the weekly default, and the first covering range wins even when its own
 *    value for that weekday is empty (it `break`s, it does not continue).
 * 2. **The work window is sorted, not split.**  `sorted(split("-"), key=int)`
 *    means a reversed stored range `"17:00-08:00"` is reported as
 *    `08:00 … 17:00`, and an unparseable one falls back to `00:00 … 00:00`.
 * 3. **Late / early / overtime** — the status is a comma-joined list built from
 *    four independent comparisons, and overtime is only counted when it exceeds
 *    ten minutes.  The durations are formatted `HH:MM` with Python's floor
 *    division, so a negative worked total renders as `-1:30`, not `-0:30`.
 *
 * Also here: the Jalali helpers the report needs and `LegacyDate` does not expose
 * — building a `LegacyDate` from Jalali parts (the class constructor is private)
 * and the `jdatetime` weekday, which is Saturday=0 where Python's
 * `datetime.weekday()` is Monday=0.
 */
final class LegacyHozoorReport
{
    /**
     * Weekday number → `shiftha` column, the mapping `SHIFT_DAY_COLUMNS` in
     * `app/main.py:3460`.  Jalali weekday 0=Saturday … 6=Friday.
     *
     * Note the spelling: `shiftha` uses `chaharshanbeh` while `user_table` uses
     * `chrshanbeh`.  Both are kept verbatim — the weekly default is read from
     * `user_table`, the shift override from `shiftha`, and they are different
     * columns on purpose.
     */
    private const SHIFT_DAY_COLUMNS = [
        0 => 'shanbeh',
        1 => 'yekshanbeh',
        2 => 'doshanbeh',
        3 => 'seshanbeh',
        4 => 'chaharshanbeh',
        5 => 'panjshanbeh',
        6 => 'jomeh',
    ];

    /**
     * Build a `LegacyDate` from Jalali parts.
     *
     * `LegacyDate`'s constructor is private and it only exposes the
     * Gregorian→Jalali direction, but the report needs the inverse: the request
     * query and the stored `hozoor` dates are Jalali strings.  The conversion is
     * monotonic, so the Gregorian date is found by walking forward from the start
     * of the Gregorian year that contains Nowruz — at most ~385 days, and the
     * first match is the answer.
     *
     * @throws InvalidArgumentException when no such Jalali date exists.
     */
    public static function fromJalaliParts(int $year, int $month, int $day): LegacyDate
    {
        $cursor = CarbonImmutable::create($year + 621, 3, 1, 0, 0, 0, 'UTC');

        for ($i = 0; $i < 400; $i++) {
            $candidate = LegacyDate::fromGregorian($cursor);

            if ($candidate->year === $year && $candidate->month === $month && $candidate->day === $day) {
                return $candidate;
            }

            $cursor = $cursor->addDay();
        }

        throw new InvalidArgumentException("Not a Jalali date: {$year}/{$month}/{$day}");
    }

    /**
     * Parse a Jalali `YYYY/MM/DD` string into a {@see LegacyDate}.
     *
     * The request queries and the stored `hozoor` dates are Jalali strings in
     * `Y/m/d` shape; this is the parse the handlers need before they can convert.
     *
     * @throws ValueError when a part is not an integer or the date is invalid.
     * @throws TypeError when the string does not have exactly three parts.
     */
    public static function jalaliDateFromString(string $value): LegacyDate
    {
        $parts = explode('/', $value);

        if (count($parts) !== 3) {
            throw new \TypeError('jdatetime.date() takes 3 arguments');
        }

        $numbers = [];

        foreach ($parts as $part) {
            if (preg_match('/^[+-]?\d+$/', trim($part)) !== 1) {
                throw new \ValueError('invalid literal for int()');
            }

            $numbers[] = (int) $part;
        }

        try {
            return self::fromJalaliParts($numbers[0], $numbers[1], $numbers[2]);
        } catch (InvalidArgumentException $exception) {
            throw new \ValueError('invalid Jalali date');
        }
    }

    /**
     * A Gregorian date (a string or a `DateTimeInterface`) as a Jalali `Y-m-d` string.
     *
     * The report keys its days by this shape, and the fill-in loop and the
     * `hozoor` rows both produce it.
     */
    public static function jalaliDateString(mixed $value): string
    {
        return LegacyDate::fromGregorian($value)->format('%Y-%m-%d');
    }

    /**
     * The `jdatetime` weekday of a Jalali date: Saturday=0 … Friday=6.
     *
     * `jdatetime.date(y, m, d).weekday()` in the Python handler.  The offset from
     * PHP's `format('w')` (Sunday=0) is +1 mod 7, verified against all seven
     * days.
     */
    public static function weekdayOf(LegacyDate $date): int
    {
        return ((int) $date->toGregorian()->format('w') + 1) % 7;
    }

    /**
     * The weekday → `shiftha` column map, for callers that iterate all seven.
     *
     * @return array<int, string>
     */
    public static function shiftDayColumns(): array
    {
        return self::SHIFT_DAY_COLUMNS;
    }

    /**
     * A `shiftha` weekday column for a Jalali weekday number.
     */
    public static function shiftDayColumn(int $weekday): string
    {
        return self::SHIFT_DAY_COLUMNS[$weekday];
    }

    /**
     * A database time value as a four-digit `HHMM` string, or `"0000"`.
     *
     * A port of `normalize_time_value()` in `app/main.py:4730` — note this is a
     * *different* function from {@see LegacyAttendanceStatus::formatTimeValue()}
     * (which yields `HH:MM` and `null`).  This one is the report's: it keeps the
     * four-digit shape the status arithmetic slices, and its "empty" set is wider
     * (`no`, `nok`, `unknown` in addition to the usual nulls).
     */
    public static function normalizeTimeValue(mixed $value): string
    {
        if ($value === null) {
            return '0000';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Hi');
        }

        if (is_int($value) || is_float($value)) {
            return sprintf('%04d', (int) $value);
        }

        $text = trim((string) $value);

        if ($text === '') {
            return '0000';
        }

        if (in_array(strtolower($text), ['none', 'null', 'na', 'n/a', 'no', 'nok', 'unknown', '-'], true)) {
            return '0000';
        }

        $digits = preg_replace('/\D/', '', $text);
        $digits = $digits === null ? '' : $digits;

        if ($digits === '') {
            return '0000';
        }

        return str_pad(substr($digits, 0, 4), 4, '0', STR_PAD_LEFT);
    }

    /**
     * The work-hours range for one day, as the raw stored string.
     *
     * A port of `resolve_work_hours()` in `app/main.py:4814`.  The first `shiftha`
     * range covering the day decides: its own value for that weekday is returned
     * when it contains a `-`, otherwise the weekly default is used — and the loop
     * `break`s either way, so a second covering range is never consulted.
     *
     * @param  array<int, array<string, mixed>>  $shiftRows  Rows from `shiftha`.
     * @param  array<int, mixed>  $weekdayMap  Jalali weekday → `user_table` column value.
     */
    public static function resolveWorkHours(
        array $shiftRows,
        array $weekdayMap,
        ?string $defaultWorkHours,
        int $jalaliYear,
        int $jalaliMonth,
        int $jalaliDay,
        int $weekday,
    ): string {
        foreach ($shiftRows as $row) {
            if (! self::covers($row, $jalaliYear, $jalaliMonth, $jalaliDay)) {
                continue;
            }

            $value = $row[self::SHIFT_DAY_COLUMNS[$weekday]] ?? null;

            if ($value && str_contains((string) $value, '-')) {
                return (string) $value;
            }

            break;
        }

        $value = ($weekdayMap[$weekday] ?? null) ?? $defaultWorkHours ?? '00:00-00:00';
        $value = str_replace(' ', '', (string) $value);

        return str_contains($value, '-') ? $value : '00:00-00:00';
    }

    /**
     * Whether a `shiftha` row covers the given Jalali day.
     *
     * The Python wraps the three comparisons in `try/except (TypeError,
     * ValueError)` and answers `False` when any value is not an integer — a
     * non-numeric stored day must not crash the report, it must just not match.
     *
     * @param  array<string, mixed>  $row
     */
    private static function covers(array $row, int $year, int $month, int $day): bool
    {
        try {
            $rowYear = self::pyIntOrZero($row['jalali_year'] ?? null);
            $rowMonth = self::pyIntOrZero($row['jalali_month'] ?? null);
            $startDay = self::pyIntOrZero($row['start_day'] ?? null);
            $endDay = self::pyIntOrZero($row['end_day'] ?? null);
        } catch (Throwable) {
            return false;
        }

        return $rowYear === $year && $rowMonth === $month && $startDay <= $day && $day <= $endDay;
    }

    /**
     * `int(value or 0)` — Python's `int()` on the falsy-coerced value.
     *
     * The falsy set is Python's (`None`, `False`, `0`, `0.0`, `''`, `[]`), and a
     * string must be a valid integer literal — `int("5.5")` raises `ValueError`,
     * which the caller catches, while `int(5.5)` truncates to `5`.
     *
     * @throws \ValueError when the value is not representable as an integer.
     */
    private static function pyIntOrZero(mixed $value): int
    {
        if ($value === null || $value === false || $value === '' || $value === [] || $value === 0 || $value === 0.0) {
            return 0;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^[+-]?\d+$/', trim($value)) === 1) {
            return (int) $value;
        }

        throw new \ValueError('int() argument must be an integer literal');
    }

    /**
     * The sorted `[work_start, work_end]` pair from a work-hours string.
     *
     * A port of the `sorted(work_hours.split("-"), key=lambda x: int(x.replace(":", "")))`
     * in `app/main.py:4955`, including its bare `except`: anything that is not two
     * colon-bearing numeric parts falls back to `00:00 … 00:00` rather than
     * erroring, because a damaged schedule must not blank the whole report.
     *
     * @return array{0: string, 1: string}
     */
    public static function workStartEnd(string $workHours): array
    {
        try {
            $parts = explode('-', $workHours);

            if (count($parts) !== 2) {
                throw new \ValueError('not two parts');
            }

            $first = self::pyIntOrZero(str_replace(':', '', $parts[0]));
            $second = self::pyIntOrZero(str_replace(':', '', $parts[1]));

            return $first <= $second ? [$parts[0], $parts[1]] : [$parts[1], $parts[0]];
        } catch (Throwable) {
            return ['00:00', '00:00'];
        }
    }

    /**
     * The per-day status calculation — the final loop of `get_hozoor`.
     *
     * Given the raw day data (its `EntryTime`/`ExitTime`, already in `HHMM`) and
     * the resolved work window, this reproduces the status list, the calculated
     * durations and the worked-hours total exactly, including:
     *
     * * a day with no punches is **تعطیل** on a Friday (Jalali weekday 6) and
     *   **غیبت** on any other day, with an empty `CalculatedTime` and `00:00`
     *   worked hours;
     * * the four status parts — `تایید سامانه در ورود`, `ورود با تاخیر`,
     *   `شروع زودهنگام`, `تایید سامانه در خروج`, `خروج زودهنگام`, `اضافه کاری` —
     *   are appended in the order the comparisons run, and joined with `", "`;
     * * overtime is only reported when it exceeds ten minutes, and only the first
     *   overtime is added to the worked total.
     *
     * @param  array<string, mixed>  $data  The day's raw data; `EntryTime` and `ExitTime` are read.
     * @return array{Status: string, CalculatedTime: string, WorkedHours: string}
     */
    public static function finalizeDay(array $data, int $weekday, string $workStart, string $workEnd): array
    {
        $entryTime = self::normalizeTimeValue($data['EntryTime'] ?? null);
        $exitTime = self::normalizeTimeValue($data['ExitTime'] ?? null);

        if ($entryTime === '0000' && $exitTime === '0000') {
            return [
                'Status' => $weekday === 6 ? 'تعطیل' : 'غیبت',
                'CalculatedTime' => '',
                'WorkedHours' => '00:00',
            ];
        }

        $entryHour = (int) substr($entryTime, 0, 2);
        $entryMinute = (int) substr($entryTime, 2);
        $exitHour = (int) substr($exitTime, 0, 2);
        $exitMinute = (int) substr($exitTime, 2);
        $workStartHour = (int) substr($workStart, 0, 2);
        $workStartMinute = (int) substr($workStart, 3);
        $workEndHour = (int) substr($workEnd, 0, 2);
        $workEndMinute = (int) substr($workEnd, 3);

        $statusParts = [];
        $calculatedTime = [];
        $workedMinutes = 0;
        $overtimeAdded = false;
        $earlyExitAdded = false;

        if ($entryHour === $workStartHour && $entryMinute === $workStartMinute) {
            $statusParts[] = 'تایید سامانه در ورود';
            $workedMinutes = ($workEndHour - $workStartHour) * 60 + ($workEndMinute - $workStartMinute);
        } elseif ($entryHour > $workStartHour || ($entryHour === $workStartHour && $entryMinute > $workStartMinute)) {
            $statusParts[] = 'ورود با تاخیر';

            $delay = ($entryHour - $workStartHour) * 60 + ($entryMinute - $workStartMinute);
            $calculatedTime[] = sprintf('مدت زمان تاخیر: %02d:%02d', self::floorDiv($delay, 60), self::pyMod($delay, 60));

            if ($exitHour > $workEndHour || ($exitHour === $workEndHour && $exitMinute > $workEndMinute)) {
                $overtime = ($exitHour - $workEndHour) * 60 + ($exitMinute - $workEndMinute);

                if ($overtime > 10 && ! $overtimeAdded) {
                    $statusParts[] = 'اضافه کاری';
                    $calculatedTime[] = sprintf('مدت زمان اضافه کاری: %02d:%02d', self::floorDiv($overtime, 60), self::pyMod($overtime, 60));
                    $workedMinutes += $overtime;
                    $overtimeAdded = true;
                } else {
                    $workedMinutes += ($workEndHour - $entryHour) * 60 + ($workEndMinute - $entryMinute);
                }
            }
        } else {
            $statusParts[] = 'شروع زودهنگام';
            $early = ($workStartHour - $entryHour) * 60 + ($workStartMinute - $entryMinute);
            $calculatedTime[] = sprintf('مدت زمان شروع زودهنگام: %02d:%02d', self::floorDiv($early, 60), self::pyMod($early, 60));
            $workedMinutes = ($workEndHour - $workStartHour) * 60 + ($workEndMinute - $workStartMinute);
        }

        if ($exitHour === $workEndHour && $exitMinute === $workEndMinute) {
            $statusParts[] = 'تایید سامانه در خروج';
        } elseif ($exitHour < $workEndHour || ($exitHour === $workEndHour && $exitMinute < $workEndMinute)) {
            if (! $earlyExitAdded) {
                $statusParts[] = 'خروج زودهنگام';
                $earlyExit = ($workEndHour - $exitHour) * 60 + ($workEndMinute - $exitMinute);
                $calculatedTime[] = sprintf('مدت زمان خروج زودهنگام: %02d:%02d', self::floorDiv($earlyExit, 60), self::pyMod($earlyExit, 60));
                $earlyExitAdded = true;
            }
        } elseif ($exitHour > $workEndHour || ($exitHour === $workEndHour && $exitMinute > $workEndMinute)) {
            $overtime = ($exitHour - $workEndHour) * 60 + ($exitMinute - $workEndMinute);

            if ($overtime > 10 && ! $overtimeAdded) {
                $statusParts[] = 'اضافه کاری';
                $calculatedTime[] = sprintf('مدت زمان اضافه کاری: %02d:%02d', self::floorDiv($overtime, 60), self::pyMod($overtime, 60));
            }
        }

        return [
            'Status' => implode(', ', $statusParts),
            'CalculatedTime' => implode('<br>', $calculatedTime),
            'WorkedHours' => sprintf('%02d:%02d', self::floorDiv($workedMinutes, 60), self::pyMod($workedMinutes, 60)),
        ];
    }

    /**
     * Python's `//` — floor division, which keeps the sign of the divisor.
     *
     * PHP's `intdiv()` truncates toward zero, so `-30` is `0` there and `-1` in
     * Python.  The worked-hours total can go negative (a late arrival after the
     * work end), and the two servers must render it the same way.
     */
    private static function floorDiv(int $a, int $b): int
    {
        return (int) floor($a / $b);
    }

    /**
     * Python's `%` — the remainder keeps the sign of the divisor.
     */
    private static function pyMod(int $a, int $b): int
    {
        return $a - self::floorDiv($a, $b) * $b;
    }
}
