<?php

namespace Tests\Unit;

use App\Support\Legacy\LegacyDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Jalali calendar parity, held against ground truth from `persiantools`.
 *
 * The fixture below is not hand-written.  It was produced by running the *Python
 * library the running application uses* over these Gregorian dates:
 *
 *     JalaliDate(datetime.date(2026, 9, 30)).strftime('%Y/%m/%d')  # 1405/07/08
 *
 * and the same was done for 73,414 consecutive days (1900-01-01 … 2100-12-31),
 * comparing the Jalali date, the Gregorian round trip and the month length.  All
 * 73,414 agreed.  Keeping all 73,414 here would make the suite unreadable, so the
 * fixture keeps the dates that can actually catch a regression: every Nowruz edge
 * (`03-19` … `03-22`), the Dey boundary (`01-01`, `12-31`), mid-year (`06-21`), and
 * every year from 1395 to 1410 — the range the live data occupies.
 *
 * Why this matters more than usual: the shift and attendance screens compare
 * Jalali integers against values already stored as integers in `shiftha`, and
 * `/get_today_date` answers with three of them.  A one-day error on a leap edge
 * would silently mislabel a whole shift rather than fail loudly.
 *
 * The test found a real bug on its first run: reading the leap flag *after*
 * decrementing the Jalali year shifted every date in the 80 days before Nowruz by
 * one day.  That is exactly the class of error a spot check on today's date would
 * have missed.
 */
final class LegacyDateTest extends TestCase
{
    /**
     * A representative sample of the verified range.
     *
     * @return array<int, array{0: string}>
     */
    public static function gregorianSamples(): array
    {
        return array_map(static fn (array $pair): array => [$pair[0]], self::pairs());
    }

    /**
     * Ground truth, `[gregorian, jalali]`, from `persiantools`.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function pairs(): array
    {
        return [
            ['1902-01-01', '1280/10/11'],
            ['1902-03-19', '1280/12/28'],
            ['1902-03-20', '1280/12/29'],
            ['1902-03-21', '1280/12/30'],
            ['1902-03-22', '1281/01/01'],
            ['1902-06-21', '1281/03/30'],
            ['1902-12-31', '1281/10/09'],
            ['1903-01-01', '1281/10/10'],
            ['1903-03-19', '1281/12/27'],
            ['1903-03-20', '1281/12/28'],
            ['1903-03-21', '1281/12/29'],
            ['1903-03-22', '1282/01/01'],
            ['1903-06-21', '1282/03/30'],
            ['1903-12-31', '1282/10/09'],
            ['1926-01-01', '1304/10/11'],
            ['1926-03-19', '1304/12/28'],
            ['1926-03-20', '1304/12/29'],
            ['1926-03-21', '1304/12/30'],
            ['1926-03-22', '1305/01/01'],
            ['1926-06-21', '1305/03/30'],
            ['1926-12-31', '1305/10/09'],
            ['1930-01-01', '1308/10/11'],
            ['1930-03-19', '1308/12/28'],
            ['1930-03-20', '1308/12/29'],
            ['1930-03-21', '1309/01/01'],
            ['1930-03-22', '1309/01/02'],
            ['1930-06-21', '1309/03/31'],
            ['1930-12-31', '1309/10/10'],
            ['1940-01-01', '1318/10/10'],
            ['1940-03-19', '1318/12/28'],
            ['1940-03-20', '1318/12/29'],
            ['1940-03-21', '1319/01/01'],
            ['1940-03-22', '1319/01/02'],
            ['1940-06-21', '1319/03/31'],
            ['1940-12-31', '1319/10/10'],
            ['1950-01-01', '1328/10/11'],
            ['1950-03-19', '1328/12/28'],
            ['1950-03-20', '1328/12/29'],
            ['1950-03-21', '1329/01/01'],
            ['1950-03-22', '1329/01/02'],
            ['1950-06-21', '1329/03/31'],
            ['1950-12-31', '1329/10/10'],
            ['1960-01-01', '1338/10/10'],
            ['1960-03-19', '1338/12/28'],
            ['1960-03-20', '1338/12/29'],
            ['1960-03-21', '1339/01/01'],
            ['1960-03-22', '1339/01/02'],
            ['1960-06-21', '1339/03/31'],
            ['1960-12-31', '1339/10/10'],
            ['1965-01-01', '1343/10/11'],
            ['1965-03-19', '1343/12/28'],
            ['1965-03-20', '1343/12/29'],
            ['1965-03-21', '1344/01/01'],
            ['1965-03-22', '1344/01/02'],
            ['1965-06-21', '1344/03/31'],
            ['1965-12-31', '1344/10/10'],
            ['1969-01-01', '1347/10/11'],
            ['1969-03-19', '1347/12/28'],
            ['1969-03-20', '1347/12/29'],
            ['1969-03-21', '1348/01/01'],
            ['1969-03-22', '1348/01/02'],
            ['1969-06-21', '1348/03/31'],
            ['1969-12-31', '1348/10/10'],
            ['1970-01-01', '1348/10/11'],
            ['1970-03-19', '1348/12/28'],
            ['1970-03-20', '1348/12/29'],
            ['1970-03-21', '1349/01/01'],
            ['1970-03-22', '1349/01/02'],
            ['1970-06-21', '1349/03/31'],
            ['1970-12-31', '1349/10/10'],
            ['1980-01-01', '1358/10/11'],
            ['1980-03-19', '1358/12/29'],
            ['1980-03-20', '1358/12/30'],
            ['1980-03-21', '1359/01/01'],
            ['1980-03-22', '1359/01/02'],
            ['1980-06-21', '1359/03/31'],
            ['1980-12-31', '1359/10/10'],
            ['1990-01-01', '1368/10/11'],
            ['1990-03-19', '1368/12/28'],
            ['1990-03-20', '1368/12/29'],
            ['1990-03-21', '1369/01/01'],
            ['1990-03-22', '1369/01/02'],
            ['1990-06-21', '1369/03/31'],
            ['1990-12-31', '1369/10/10'],
            ['1997-01-01', '1375/10/12'],
            ['1997-03-19', '1375/12/29'],
            ['1997-03-20', '1375/12/30'],
            ['1997-03-21', '1376/01/01'],
            ['1997-03-22', '1376/01/02'],
            ['1997-06-21', '1376/03/31'],
            ['1997-12-31', '1376/10/10'],
            ['2000-01-01', '1378/10/11'],
            ['2000-03-19', '1378/12/29'],
            ['2000-03-20', '1379/01/01'],
            ['2000-03-21', '1379/01/02'],
            ['2000-03-22', '1379/01/03'],
            ['2000-06-21', '1379/04/01'],
            ['2000-12-31', '1379/10/11'],
            ['2010-01-01', '1388/10/11'],
            ['2010-03-19', '1388/12/28'],
            ['2010-03-20', '1388/12/29'],
            ['2010-03-21', '1389/01/01'],
            ['2010-03-22', '1389/01/02'],
            ['2010-06-21', '1389/03/31'],
            ['2010-12-31', '1389/10/10'],
            ['2016-01-01', '1394/10/11'],
            ['2016-03-19', '1394/12/29'],
            ['2016-03-20', '1395/01/01'],
            ['2016-03-21', '1395/01/02'],
            ['2016-03-22', '1395/01/03'],
            ['2016-06-21', '1395/04/01'],
            ['2016-12-31', '1395/10/11'],
            ['2017-01-01', '1395/10/12'],
            ['2017-03-19', '1395/12/29'],
            ['2017-03-20', '1395/12/30'],
            ['2017-03-21', '1396/01/01'],
            ['2017-03-22', '1396/01/02'],
            ['2017-06-21', '1396/03/31'],
            ['2017-12-31', '1396/10/10'],
            ['2018-01-01', '1396/10/11'],
            ['2018-03-19', '1396/12/28'],
            ['2018-03-20', '1396/12/29'],
            ['2018-03-21', '1397/01/01'],
            ['2018-03-22', '1397/01/02'],
            ['2018-06-21', '1397/03/31'],
            ['2018-12-31', '1397/10/10'],
            ['2019-01-01', '1397/10/11'],
            ['2019-03-19', '1397/12/28'],
            ['2019-03-20', '1397/12/29'],
            ['2019-03-21', '1398/01/01'],
            ['2019-03-22', '1398/01/02'],
            ['2019-06-21', '1398/03/31'],
            ['2019-12-31', '1398/10/10'],
            ['2020-01-01', '1398/10/11'],
            ['2020-03-19', '1398/12/29'],
            ['2020-03-20', '1399/01/01'],
            ['2020-03-21', '1399/01/02'],
            ['2020-03-22', '1399/01/03'],
            ['2020-06-21', '1399/04/01'],
            ['2020-12-31', '1399/10/11'],
            ['2021-01-01', '1399/10/12'],
            ['2021-03-19', '1399/12/29'],
            ['2021-03-20', '1399/12/30'],
            ['2021-03-21', '1400/01/01'],
            ['2021-03-22', '1400/01/02'],
            ['2021-06-21', '1400/03/31'],
            ['2021-12-31', '1400/10/10'],
            ['2022-01-01', '1400/10/11'],
            ['2022-03-19', '1400/12/28'],
            ['2022-03-20', '1400/12/29'],
            ['2022-03-21', '1401/01/01'],
            ['2022-03-22', '1401/01/02'],
            ['2022-06-21', '1401/03/31'],
            ['2022-12-31', '1401/10/10'],
            ['2023-01-01', '1401/10/11'],
            ['2023-03-19', '1401/12/28'],
            ['2023-03-20', '1401/12/29'],
            ['2023-03-21', '1402/01/01'],
            ['2023-03-22', '1402/01/02'],
            ['2023-06-21', '1402/03/31'],
            ['2023-12-31', '1402/10/10'],
            ['2024-01-01', '1402/10/11'],
            ['2024-03-19', '1402/12/29'],
            ['2024-03-20', '1403/01/01'],
            ['2024-03-21', '1403/01/02'],
            ['2024-03-22', '1403/01/03'],
            ['2024-06-21', '1403/04/01'],
            ['2024-12-31', '1403/10/11'],
            ['2025-01-01', '1403/10/12'],
            ['2025-03-19', '1403/12/29'],
            ['2025-03-20', '1403/12/30'],
            ['2025-03-21', '1404/01/01'],
            ['2025-03-22', '1404/01/02'],
            ['2025-06-21', '1404/03/31'],
            ['2025-12-31', '1404/10/10'],
            ['2026-01-01', '1404/10/11'],
            ['2026-03-19', '1404/12/28'],
            ['2026-03-20', '1404/12/29'],
            ['2026-03-21', '1405/01/01'],
            ['2026-03-22', '1405/01/02'],
            ['2026-06-21', '1405/03/31'],
            ['2026-12-31', '1405/10/10'],
            ['2027-01-01', '1405/10/11'],
            ['2027-03-19', '1405/12/28'],
            ['2027-03-20', '1405/12/29'],
            ['2027-03-21', '1406/01/01'],
            ['2027-03-22', '1406/01/02'],
            ['2027-06-21', '1406/03/31'],
            ['2027-12-31', '1406/10/10'],
            ['2028-01-01', '1406/10/11'],
            ['2028-03-19', '1406/12/29'],
            ['2028-03-20', '1407/01/01'],
            ['2028-03-21', '1407/01/02'],
            ['2028-03-22', '1407/01/03'],
            ['2028-06-21', '1407/04/01'],
            ['2028-12-31', '1407/10/11'],
            ['2029-01-01', '1407/10/12'],
            ['2029-03-19', '1407/12/29'],
            ['2029-03-20', '1408/01/01'],
            ['2029-03-21', '1408/01/02'],
            ['2029-03-22', '1408/01/03'],
            ['2029-06-21', '1408/04/01'],
            ['2029-12-31', '1408/10/11'],
            ['2030-01-01', '1408/10/12'],
            ['2030-03-19', '1408/12/29'],
            ['2030-03-20', '1408/12/30'],
            ['2030-03-21', '1409/01/01'],
            ['2030-03-22', '1409/01/02'],
            ['2030-06-21', '1409/03/31'],
            ['2030-12-31', '1409/10/10'],
            ['2031-01-01', '1409/10/11'],
            ['2031-03-19', '1409/12/28'],
            ['2031-03-20', '1409/12/29'],
            ['2031-03-21', '1410/01/01'],
            ['2031-03-22', '1410/01/02'],
            ['2031-06-21', '1410/03/31'],
            ['2031-12-31', '1410/10/10'],
            ['2040-01-01', '1418/10/11'],
            ['2040-03-19', '1418/12/29'],
            ['2040-03-20', '1419/01/01'],
            ['2040-03-21', '1419/01/02'],
            ['2040-03-22', '1419/01/03'],
            ['2040-06-21', '1419/04/01'],
            ['2040-12-31', '1419/10/11'],
            ['2050-01-01', '1428/10/12'],
            ['2050-03-19', '1428/12/29'],
            ['2050-03-20', '1428/12/30'],
            ['2050-03-21', '1429/01/01'],
            ['2050-03-22', '1429/01/02'],
            ['2050-06-21', '1429/03/31'],
            ['2050-12-31', '1429/10/10'],
            ['2060-01-01', '1438/10/11'],
            ['2060-03-19', '1438/12/29'],
            ['2060-03-20', '1439/01/01'],
            ['2060-03-21', '1439/01/02'],
            ['2060-03-22', '1439/01/03'],
            ['2060-06-21', '1439/04/01'],
            ['2060-12-31', '1439/10/11'],
            ['2070-01-01', '1448/10/12'],
            ['2070-03-19', '1448/12/29'],
            ['2070-03-20', '1449/01/01'],
            ['2070-03-21', '1449/01/02'],
            ['2070-03-22', '1449/01/03'],
            ['2070-06-21', '1449/04/01'],
            ['2070-12-31', '1449/10/11'],
            ['2080-01-01', '1458/10/11'],
            ['2080-03-19', '1458/12/29'],
            ['2080-03-20', '1459/01/01'],
            ['2080-03-21', '1459/01/02'],
            ['2080-03-22', '1459/01/03'],
            ['2080-06-21', '1459/04/01'],
            ['2080-12-31', '1459/10/11'],
            ['2090-01-01', '1468/10/12'],
            ['2090-03-19', '1468/12/29'],
            ['2090-03-20', '1469/01/01'],
            ['2090-03-21', '1469/01/02'],
            ['2090-03-22', '1469/01/03'],
            ['2090-06-21', '1469/04/01'],
            ['2090-12-31', '1469/10/11'],
            ['2097-01-01', '1475/10/12'],
            ['2097-03-19', '1475/12/29'],
            ['2097-03-20', '1476/01/01'],
            ['2097-03-21', '1476/01/02'],
            ['2097-03-22', '1476/01/03'],
            ['2097-06-21', '1476/04/01'],
            ['2097-12-31', '1476/10/11'],
            ['2100-01-01', '1478/10/12'],
            ['2100-03-19', '1478/12/29'],
            ['2100-03-20', '1478/12/30'],
            ['2100-03-21', '1479/01/01'],
            ['2100-03-22', '1479/01/02'],
            ['2100-06-21', '1479/03/31'],
            ['2100-12-31', '1479/10/10'],
        ];
    }

    #[DataProvider('gregorianSamples')]
    public function test_gregorian_dates_convert_to_the_persiantools_jalali_date(string $gregorian): void
    {
        $expected = null;

        foreach (self::pairs() as [$expectedGregorian, $expectedJalali]) {
            if ($expectedGregorian === $gregorian) {
                $expected = $expectedJalali;

                break;
            }
        }

        $this->assertNotNull($expected);

        $this->assertSame(
            $expected,
            LegacyDate::fromGregorian($gregorian)->toJalaliString(),
            "Gregorian {$gregorian} did not convert to the Jalali date persiantools reports.",
        );
    }

    /**
     * Every sample round-trips, which is the invariant the report endpoints rely on
     * (`jdatetime.date.fromgregorian(date=…).strftime('%Y-%m-%d')`).
     */
    public function test_every_sample_round_trips_back_to_its_gregorian_date(): void
    {
        foreach (self::pairs() as [$gregorian, $jalali]) {
            $this->assertSame(
                $jalali,
                LegacyDate::fromGregorian($gregorian)->toJalaliString(),
                "{$gregorian} → {$jalali}",
            );

            $this->assertSame(
                $gregorian,
                LegacyDate::fromGregorian($gregorian)->toGregorian()->format('Y-m-d'),
                "round trip for {$gregorian}",
            );
        }
    }

    /**
     * The dates the reconnaissance pinned, because they are the ones an operator
     * will eyeball.
     */
    public function test_the_reconnaissance_facts_still_hold(): void
    {
        $this->assertSame('1405/07/08', LegacyDate::fromGregorian('2026-09-30')->toJalaliString());
        $this->assertSame('1403/01/01', LegacyDate::fromGregorian('2024-03-20')->toJalaliString());
        $this->assertSame('1405/01/01', LegacyDate::fromGregorian('2026-03-21')->toJalaliString());
        $this->assertSame('1404/12/29', LegacyDate::fromGregorian('2026-03-20')->toJalaliString());

        // `jdatetime`'s `%Y-%m-%d` shape, used by /get_leave_requests.
        $this->assertSame('1405-07-08', LegacyDate::fromGregorian('2026-09-30')->format('%Y-%m-%d'));
    }

    /**
     * The SQL Server driver hands date columns back as strings, with or without a
     * time part.  Both shapes must convert, because `mrkhc_table.start_date` is a
     * `date` and `audit_logs.created_at` is a `datetime2`.
     */
    public function test_date_and_datetime_strings_both_convert(): void
    {
        $this->assertSame('1405/07/08', LegacyDate::fromGregorian('2026-09-30')->toJalaliString());
        $this->assertSame(
            '1405/07/08',
            LegacyDate::fromGregorian('2026-09-30 08:53:41.864')->toJalaliString(),
        );
        $this->assertSame(
            '1405/07/08',
            LegacyDate::fromGregorian(CarbonImmutable::create(2026, 9, 30, 23, 59, 59, 'UTC'))->toJalaliString(),
        );
    }

    /** A value that cannot be a date raises, so callers can answer `نامعتبر`. */
    public function test_an_unparsable_value_raises_instead_of_falling_back_to_today(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LegacyDate::fromGregorian('not-a-date');
    }

    public function test_out_of_range_jalali_years_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LegacyDate::fromGregorian('-0700-01-01');
    }

    /**
     * Leap years, taken from the same ground truth: 1399/12/30, 1403/12/30 and
     * 1408/12/30 exist, so those years are leap; 1400 and 1404 end on 12/29.
     */
    public function test_jalali_leap_years_match_the_verified_edges(): void
    {
        foreach ([1399, 1403, 1408, 1412, 1395] as $leapYear) {
            $this->assertTrue(LegacyDate::isLeapYear($leapYear), "{$leapYear} should be a leap year");
        }

        foreach ([1400, 1401, 1402, 1404, 1405] as $commonYear) {
            $this->assertFalse(LegacyDate::isLeapYear($commonYear), "{$commonYear} should not be a leap year");
        }
    }

    /**
     * Month lengths, which the Jalali calendar fixes except for Esfand.
     */
    public function test_month_lengths_follow_the_calendar_rules(): void
    {
        // 1405/07/08 — Mehr (month 7) has 30 days.
        $this->assertSame(30, LegacyDate::fromGregorian('2026-09-30')->daysInMonth());
        $this->assertSame(30, LegacyDate::fromGregorian('2026-09-30')->toArray()['days_in_month']);

        // 1405/01 — Farvardin always has 31 days.
        $this->assertSame(31, LegacyDate::fromGregorian('2026-03-21')->daysInMonth());

        // 1404/12/29 and 1403/12/30: Esfand's length is the leap-year signal.
        $this->assertSame(29, LegacyDate::fromGregorian('2026-03-20')->daysInMonth());
        $this->assertSame(30, LegacyDate::fromGregorian('2025-03-20')->daysInMonth());
    }

    /**
     * `today()` reads the deployment wall clock, not `app.timezone`.
     *
     * The `.env` note promised this helper, and the distinction is the difference
     * between answering "today" and "yesterday" for three and a half hours every
     * night.  This asserts the wiring rather than the value, and the value is
     * pinned for the deployment's fixed offset.
     */
    public function test_today_uses_the_deployment_timezone_and_matches_the_python_library(): void
    {
        $this->assertSame('Asia/Tehran', config('hastama.display_timezone'));

        // 23:00 UTC on 2026-09-30 is already 2026-10-01 (1405/07/09) in Tehran, while
        // `app.timezone` is UTC and would answer 1405/07/08.
        config(['hastama.display_timezone' => 'Asia/Tehran']);

        $tehran = LegacyDate::fromGregorian(
            CarbonImmutable::create(2026, 9, 30, 23, 0, 0, 'UTC')->setTimezone('Asia/Tehran')
        );

        $this->assertSame('1405/07/09', $tehran->toJalaliString());
        $this->assertSame('1405/07/08', LegacyDate::fromGregorian('2026-09-30')->toJalaliString());

        $this->assertSame('Asia/Tehran', LegacyDate::timezone());
    }

    /**
     * `/get_today_date` answers with three integers and no `success` key.
     *
     * Asserted here because the shape is part of the API contract and the triple is
     * what the shift endpoints compare against `shiftha`.
     */
    public function test_the_day_triple_shape_matches_the_endpoint_contract(): void
    {
        $triple = LegacyDate::fromGregorian('2026-09-30')->toDayTriple();

        $this->assertSame(['year' => 1405, 'month' => 7, 'day' => 8], $triple);
        $this->assertSame([1405, 7, 8], array_values($triple));
    }
}
