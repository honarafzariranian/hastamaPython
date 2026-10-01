<?php

namespace App\Support\Araz;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Jalali (Solar Hijri) → Gregorian conversion for the bridge-sync records.
 *
 * The bridge agent pushes attendance with a Jalali `tarikh` (`YYYY/MM/DD`),
 * which `bridge_sync` converts to a Gregorian date before writing `hozoor`.
 * The Python used `persiantools.jdatetime.JalaliDate(...).to_gregorian()`; the
 * rest of this application already has that algorithm in
 * `App\Support\Legacy\LegacyDate`, but that class only exposes the *inverse*
 * (`fromGregorian`) and is outside this migration's file ownership, so the
 * conversion lives here instead.
 *
 * The arithmetic is the same jalaali-js port `LegacyDate` uses — the same
 * reference implementation, checked date-by-date against `persiantools` — so
 * the two agree on every date, including the leap-year edges.
 */
final class JalaliDate
{
    /**
     * Jalali leap-year cycle breakpoints, transcribed from jalaali-js.
     *
     * @var array<int, int>
     */
    private const BREAKS = [
        -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210,
        1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178,
    ];

    /**
     * The Gregorian date a Jalali date denotes, at UTC midnight.
     *
     * @throws InvalidArgumentException when the year is outside the range the
     *                                  algorithm supports.
     */
    public static function fromJalali(int $year, int $month, int $day): DateTimeImmutable
    {
        return self::gregorianDate(self::dayNumberFromJalali($year, $month, $day));
    }

    /** Truncating division, matching JavaScript's `~~(a / b)`. */
    private static function div(int $a, int $b): int
    {
        return intdiv($a, $b);
    }

    /** Remainder keeping the sign of `a`, matching JavaScript's `a % b`. */
    private static function mod(int $a, int $b): int
    {
        return $a - intdiv($a, $b) * $b;
    }

    /**
     * Leap-year and March-equinox data for one Jalali year.
     *
     * @return array{0: int, 1: int, 2: int} `[leap, gregorianYear, marchDay]`
     */
    private static function jalaliCalendar(int $jalaliYear): array
    {
        $breaks = self::BREAKS;
        $count = count($breaks);

        if ($jalaliYear < $breaks[0] || $jalaliYear >= $breaks[$count - 1]) {
            throw new InvalidArgumentException("Jalali year out of the supported range: {$jalaliYear}");
        }

        $gregorianYear = $jalaliYear + 621;
        $leapJ = -14;
        $previousBreak = $breaks[0];
        $jump = 0;

        for ($index = 1; $index < $count; $index++) {
            $currentBreak = $breaks[$index];
            $jump = $currentBreak - $previousBreak;

            if ($jalaliYear < $currentBreak) {
                break;
            }

            $leapJ += self::div($jump, 33) * 8 + self::div(self::mod($jump, 33), 4);
            $previousBreak = $currentBreak;
        }

        $years = $jalaliYear - $previousBreak;

        $leapJ += self::div($years, 33) * 8 + self::div(self::mod($years, 33) + 3, 4);

        if (self::mod($jump, 33) === 4 && $jump - $years === 4) {
            $leapJ++;
        }

        $leapG = self::div($gregorianYear, 4)
            - self::div((self::div($gregorianYear, 100) + 1) * 3, 4)
            - 150;

        $march = 20 + $leapJ - $leapG;

        if ($jump - $years < 6) {
            $years = $years - $jump + self::div($jump + 4, 33) * 33;
        }

        $leap = self::mod(self::mod($years + 1, 33) - 1, 4);

        if ($leap === -1) {
            $leap = 4;
        }

        return [$leap, $gregorianYear, $march];
    }

    /** Jalali (year, month, day) → Julian Day Number. */
    private static function dayNumberFromJalali(int $jalaliYear, int $jalaliMonth, int $jalaliDay): int
    {
        [, $gregorianYear, $march] = self::jalaliCalendar($jalaliYear);

        return self::dayNumberFromGregorian($gregorianYear, 3, $march)
            + ($jalaliMonth - 1) * 31
            - self::div($jalaliMonth, 7) * ($jalaliMonth - 7)
            + $jalaliDay
            - 1;
    }

    /** Gregorian (year, month, day) → Julian Day Number. */
    private static function dayNumberFromGregorian(int $year, int $month, int $day): int
    {
        $a = self::div(14 - $month, 12);
        $y = $year + 4800 - $a;
        $m = $month + 12 * $a - 3;

        return $day
            + self::div(153 * $m + 2, 5)
            + 365 * $y
            + self::div($y, 4)
            - self::div($y, 100)
            + self::div($y, 400)
            - 32045;
    }

    /**
     * Julian Day Number → Gregorian date at UTC midnight.
     *
     * @return array{0: int, 1: int, 2: int} `[year, month, day]`
     */
    private static function gregorianFromDayNumber(int $jd): array
    {
        $a = $jd + 32044;
        $b = self::div(4 * $a + 3, 146097);
        $c = $a - self::div(146097 * $b, 4);
        $d = self::div(4 * $c + 3, 1461);
        $e = $c - self::div(1461 * $d, 4);
        $m = self::div(5 * $e + 2, 153);

        $day = $e - self::div(153 * $m + 2, 5) + 1;
        $month = $m + 3 - 12 * self::div($m, 10);
        $year = 100 * $b + $d - 4800 + self::div($m, 10);

        return [$year, $month, $day];
    }

    /**
     * The Gregorian date a Jalali date denotes, at UTC midnight.
     */
    private static function gregorianDate(int $jd): DateTimeImmutable
    {
        [$year, $month, $day] = self::gregorianFromDayNumber($jd);

        return new DateTimeImmutable(sprintf('%04d-%02d-%02d 00:00:00', $year, $month, $day), new \DateTimeZone('UTC'));
    }
}
