<?php

namespace App\Support\Legacy;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use Throwable;

/**
 * The Jalali (Solar Hijri) calendar, as the legacy application computed it.
 *
 * The Python application used **two** libraries for this and they agreed:
 * `persiantools.jdatetime.JalaliDate` (`app/main.py`) and `jdatetime`
 * (`_ticket_date_text` and the report endpoints).  Both implement the arithmetic
 * Jalali calendar — the same one as the reference `jalaali-js`, derived from
 * Kazimierz M. Borkowski's "The Persian calendar for 3000 years" — and this class
 * is a direct port of that algorithm.
 *
 * It is a *direct* port on purpose.  The obvious alternative was to pull in a
 * Composer package (`hekmatinasser/verta`, `sallar/jdatetime`, …), and the
 * migration rejected it for two reasons:
 *
 * 1. **Parity has to be provable, not assumed.**  The existing API answers
 *    `{"year": 1405, "month": 7, "day": 8}` for `/get_today_date`, and the shift
 *    and attendance screens compare those integers against values already stored
 *    as integers in `shiftha`.  A library that disagreed with the running server
 *    by a single day on a leap-year edge would silently mislabel every shift.
 *    This implementation is therefore checked date-by-date against
 *    `persiantools` by `tests/Unit/LegacyDateTest.php`.
 * 2. **There is no Jalali dependency in this project yet.**  Adding one for a
 *    hundred-line calculation would also add a supply-chain surface the operator
 *    would have to keep patched.
 *
 * Timezone note (this one matters and is easy to get wrong).  The Python server
 * read the **machine's local clock** (`date.today()`), which on this deployment is
 * `Asia/Tehran`, while this application is configured `app.timezone = UTC` because
 * the database stores UTC.  Jalali "today" is therefore resolved against
 * `hastama.display_timezone` — the explicit helper the `.env` note promised — and
 * **not** against `app.timezone`.  Between 20:30 and 24:00 UTC the two differ by a
 * day, which would otherwise flip `/get_today_date` and `/get_active_shifts` a day
 * early.
 */
final class LegacyDate
{
    /**
     * Jalali leap-year cycle breakpoints.
     *
     * Transcribed from `jalaali-js`.  Each entry marks the first year of a cycle
     * whose leap pattern differs from the previous one, and the array bounds the
     * supported range: Jalali years `-61` … `3177`.
     *
     * @var array<int, int>
     */
    private const BREAKS = [
        -61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210,
        1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178,
    ];

    private function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly int $day,
    ) {}

    /**
     * Today, in the deployment's wall-clock timezone.
     *
     * The equivalent of `JalaliDate.today()` / `jdatetime.date.today()`.
     */
    public static function today(): self
    {
        return self::fromGregorian(CarbonImmutable::now(self::timezone()));
    }

    /** The wall-clock timezone the legacy application used for date boundaries. */
    public static function timezone(): string
    {
        return (string) config('hastama.display_timezone', 'Asia/Tehran');
    }

    /**
     * Build from a Gregorian date — a `Carbon`/`DateTime`, or a date string in the
     * shape the SQL Server driver returns it.
     *
     * `'2026-09-30'` and `'2026-09-30 08:53:41.864'` are both accepted; only the
     * date part is used, because every caller stores a `date` column.  An
     * unparsable value raises rather than silently falling back to "today", so the
     * callers that must answer `'نامعتبر'` can catch it.
     */
    public static function fromGregorian(string|DateTimeInterface $value): self
    {
        if ($value instanceof DateTimeInterface) {
            $gregorian = CarbonImmutable::instance($value);
        } else {
            try {
                $gregorian = CarbonImmutable::parse($value, self::timezone());
            } catch (Throwable) {
                throw new InvalidArgumentException("Not a Gregorian date: {$value}");
            }
        }

        [$year, $month, $day] = self::jalaliFromDayNumber(
            self::dayNumberFromGregorian($gregorian->year, $gregorian->month, $gregorian->day)
        );

        return new self($year, $month, $day);
    }

    /**
     * The Gregorian date this Jalali date denotes, at UTC midnight.
     *
     * `to_gregorian()` in `persiantools`.  The time is pinned to `00:00:00` so a
     * caller comparing dates does not inherit a time of day.
     */
    public function toGregorian(): CarbonImmutable
    {
        return self::gregorianFromDayNumber(self::dayNumberFromJalali($this->year, $this->month, $this->day));
    }

    /**
     * This date as `YYYY/MM/DD` — the string the legacy endpoints publish.
     *
     * Zero-padded, exactly as `strftime('%Y/%m/%d')` produced; `_ticket_date_text`
     * and `/get_leave_info` both depend on the padding.
     */
    public function toJalaliString(string $separator = '/'): string
    {
        return $this->format('%Y'.$separator.'%m'.$separator.'%d');
    }

    /**
     * A minimal `strftime` for the Jalali calendar.
     *
     * Only the directives the legacy code actually used are supported — `%Y`
     * (zero-padded year), `%y`, `%m`, `%d`, `%n` (unpadded month), `%j` (unpadded
     * day) — plus `%%`.  An unknown directive is left verbatim, which is what
     * `strftime` does with one.
     */
    public function format(string $pattern): string
    {
        $replacements = [
            'Y' => sprintf('%04d', $this->year),
            'y' => sprintf('%02d', $this->year % 100),
            'm' => sprintf('%02d', $this->month),
            'd' => sprintf('%02d', $this->day),
            'n' => (string) $this->month,
            'j' => (string) $this->day,
        ];

        $output = '';
        $length = strlen($pattern);

        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];

            if ($character !== '%' || $index + 1 >= $length) {
                $output .= $character;

                continue;
            }

            $directive = $pattern[++$index];

            $output .= $directive === '%'
                ? '%'
                : ($replacements[$directive] ?? ('%'.$directive));
        }

        return $output;
    }

    /**
     * How many days this Jalali month has.
     *
     * The first six months have 31 days, the next five 30, and Esfand has 29 — or
     * 30 in a leap year.
     */
    public function daysInMonth(): int
    {
        if ($this->month <= 6) {
            return 31;
        }

        if ($this->month <= 11) {
            return 30;
        }

        return self::isLeapYear($this->year) ? 30 : 29;
    }

    /** Whether a Jalali year has 366 days.  `isLeapJalaaliYear` in the reference. */
    public static function isLeapYear(int $year): bool
    {
        return self::jalaliCalendar($year)[0] === 0;
    }

    /** @return array{year: int, month: int, day: int, days_in_month: int} */
    public function toArray(): array
    {
        return [
            'year' => $this->year,
            'month' => $this->month,
            'day' => $this->day,
            'days_in_month' => $this->daysInMonth(),
        ];
    }

    /** The three-number shape `/get_today_date` answers with. */
    public function toDayTriple(): array
    {
        return ['year' => $this->year, 'month' => $this->month, 'day' => $this->day];
    }

    // ── The conversion core (a port of `jalaali-js`) ─────────────────────────

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
     * `jalCal` in the reference implementation.  `$leap` is `0` for a leap year.
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

    /** Jalali (year, month, day) → Julian Day Number.  `j2d` in the reference. */
    private static function dayNumberFromJalali(int $jalaliYear, int $jalaliMonth, int $jalaliDay): int
    {
        [, $gregorianYear, $march] = self::jalaliCalendar($jalaliYear);

        return self::dayNumberFromGregorian($gregorianYear, 3, $march)
            + ($jalaliMonth - 1) * 31
            - self::div($jalaliMonth, 7) * ($jalaliMonth - 7)
            + $jalaliDay
            - 1;
    }

    /**
     * Julian Day Number → Jalali (year, month, day).  `d2j` in the reference.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function jalaliFromDayNumber(int $jd): array
    {
        $gregorian = self::gregorianFromDayNumber($jd);
        $jalaliYear = $gregorian->year - 621;

        // `$leap` is read from the calendar of the year the *Gregorian* date falls
        // in, and it is deliberately read BEFORE the `$jalaliYear--` below: a date
        // before Nowruz belongs to the previous Jalali year, but the correction
        // that accounts for that year's 30-day Esfand uses the leap flag of the
        // original (`r.leap` in the reference).  Reading it after the decrement
        // shifts every date in the 80 days before Nowruz by one day — which is
        // exactly the failure `tests/Unit/LegacyDateTest.php` caught.
        [$leap, , $march] = self::jalaliCalendar($jalaliYear);

        $marchDayNumber = self::dayNumberFromGregorian($gregorian->year, 3, $march);
        $elapsed = $jd - $marchDayNumber;

        if ($elapsed >= 0) {
            if ($elapsed <= 185) {
                return [$jalaliYear, 1 + self::div($elapsed, 31), self::mod($elapsed, 31) + 1];
            }

            $elapsed -= 186;
        } else {
            $jalaliYear--;
            $elapsed += 179;

            if ($leap === 1) {
                $elapsed++;
            }
        }

        return [$jalaliYear, 7 + self::div($elapsed, 30), self::mod($elapsed, 30) + 1];
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

    /** Julian Day Number → Gregorian date at UTC midnight. */
    private static function gregorianFromDayNumber(int $jd): CarbonImmutable
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

        return CarbonImmutable::create($year, $month, $day, 0, 0, 0, 'UTC');
    }
}
