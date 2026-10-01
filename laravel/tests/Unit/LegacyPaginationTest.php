<?php

namespace Tests\Unit;

use App\Support\Legacy\LegacyPagination;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The six-key list envelope every admin list endpoint publishes.
 *
 * Two of these assertions exist because the *obvious* implementation is wrong:
 *
 * * `pages` must be **1** for an empty result, not 0 — `max(1, …)` in the Python
 *   is load-bearing, and the UI's paginator divides by nothing gracefully only
 *   because of it;
 * * `per_page` is echoed from the request rather than derived from the rows, so a
 *   request for 200 rows of a 3-row table answers `per_page: 200, pages: 1`.
 */
final class LegacyPaginationTest extends TestCase
{
    /** `[total, per_page, expected pages]` */
    public static function pageCountCases(): array
    {
        return [
            'empty result is one page' => [0, 50, 1],
            'exactly one page' => [50, 50, 1],
            'one row over' => [51, 50, 2],
            'the live audit log size' => [3509, 50, 71],
            'a default page of users' => [16, 50, 1],
            'single-row page size' => [16, 1, 16],
            'zero page size is one empty page' => [16, 0, 1],
            'exact multiple' => [200, 50, 4],
        ];
    }

    #[DataProvider('pageCountCases')]
    public function test_page_count_matches_the_python_floor_division(int $total, int $perPage, int $expected): void
    {
        $this->assertSame($expected, LegacyPagination::pages($total, $perPage));
    }

    public function test_the_envelope_has_the_six_keys_in_the_published_order(): void
    {
        $envelope = LegacyPagination::envelope([['id' => 1]], 1, 1, 50);

        $this->assertSame(['success', 'data', 'total', 'page', 'per_page', 'pages'], array_keys($envelope));
        $this->assertTrue($envelope['success']);
        $this->assertSame([['id' => 1]], $envelope['data']);
        $this->assertSame(1, $envelope['total']);
        $this->assertSame(1, $envelope['page']);
        $this->assertSame(50, $envelope['per_page']);
        $this->assertSame(1, $envelope['pages']);
    }

    /**
     * `setup_needed` is inserted **second**, before `data`.  It is not appended at
     * the end, and a client that reads the JSON by position (rather than by key)
     * would notice — as would a snapshot test.
     */
    public function test_the_subscription_envelope_places_setup_needed_second(): void
    {
        $envelope = LegacyPagination::withSetupNeeded([], 0, 1, 50, false);

        $this->assertSame(
            ['success', 'setup_needed', 'data', 'total', 'page', 'per_page', 'pages'],
            array_keys($envelope),
        );
        $this->assertFalse($envelope['setup_needed']);
    }

    /**
     * The "the optional table has not been installed" answer pins its own page
     * numbers regardless of the request, because there is no table to page through.
     */
    public function test_setup_not_run_pins_the_page_numbers(): void
    {
        $envelope = LegacyPagination::setupNotRun();

        $this->assertSame([
            'success' => true,
            'setup_needed' => true,
            'data' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 0,
            'pages' => 1,
        ], $envelope);
    }

    /** 1-indexed pages map to a 0-based `OFFSET`. */
    public function test_the_offset_is_zero_based(): void
    {
        $this->assertSame(0, LegacyPagination::offset(1, 50));
        $this->assertSame(50, LegacyPagination::offset(2, 50));
        $this->assertSame(100, LegacyPagination::offset(3, 50));
        $this->assertSame(1, LegacyPagination::offset(2, 1));
    }
}
