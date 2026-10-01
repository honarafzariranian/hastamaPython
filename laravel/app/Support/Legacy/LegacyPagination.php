<?php

namespace App\Support\Legacy;

/**
 * The paginated list envelope the admin endpoints publish.
 *
 * Every list route in `app/api/routes/master_admin.py` ends with the same six keys:
 *
 *     {"success": true, "data": [...], "total": N, "page": p,
 *      "per_page": n, "pages": max(1, ceil(total / per_page))}
 *
 * This is **not** the shape `app/core/paginator.py::pagenation()` produced.  That
 * helper is dead code — nothing imports it — and its envelope is different, so
 * porting it would have produced a second, unused contract.  The live shape is the
 * one the admin UI reads.
 *
 * Two details worth stating because they are easy to "fix" into a bug:
 *
 * * `pages` bottoms out at **1**, even for an empty result.  `(0 + 49) // 50` is 0
 *   in Python, but the expression is `max(1, …)`, and the UI's paginator renders
 *   "page 1 of 1" rather than "page 1 of 0".
 * * `per_page` is echoed **as requested**, not clamped to what was returned.  A
 *   caller asking for 200 rows of a 3-row table gets `per_page: 200, pages: 1`.
 */
final class LegacyPagination
{
    /**
     * Build the envelope.
     *
     * @param  array<int, array<string, mixed>>  $data  Already-serialised rows.
     * @param  int  $total  The count over the whole filtered set, not the page.
     * @return array<string, mixed>
     */
    public static function envelope(array $data, int $total, int $page, int $perPage): array
    {
        return [
            'success' => true,
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => self::pages($total, $perPage),
        ];
    }

    /**
     * The envelope with the optional-feature flag inserted.
     *
     * The subscription endpoints answer `{"success": true, "setup_needed": false,
     * "data": …, …}` — `setup_needed` sits directly after `success`, which is why
     * this is a separate builder rather than an extra key merged in afterwards.
     *
     * @param  array<int, array<string, mixed>>  $data
     * @return array<string, mixed>
     */
    public static function withSetupNeeded(array $data, int $total, int $page, int $perPage, bool $setupNeeded): array
    {
        return [
            'success' => true,
            'setup_needed' => $setupNeeded,
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => self::pages($total, $perPage),
        ];
    }

    /**
     * The empty-but-successful subscription answer.
     *
     * `_subscription_empty()` is not the same as an empty page: it pins
     * `page: 1, per_page: 0, pages: 1` regardless of what was requested, because the
     * table it would have queried may not exist at all.
     *
     * @return array<string, mixed>
     */
    public static function setupNotRun(): array
    {
        return [
            'success' => true,
            'setup_needed' => true,
            'data' => [],
            'total' => 0,
            'page' => 1,
            'per_page' => 0,
            'pages' => 1,
        ];
    }

    /**
     * `max(1, ceil($total / $perPage))`, with `$perPage` guarded.
     *
     * `per_page` is validated `ge=1` on every endpoint, so the guard is defensive;
     * it returns 1 rather than dividing by zero, which is also the only sensible
     * answer for "there is one empty page".
     */
    public static function pages(int $total, int $perPage): int
    {
        if ($perPage <= 0) {
            return 1;
        }

        // Python's `(total + per_page - 1) // per_page` is integer floor division,
        // which is what `intdiv` is.
        return max(1, intdiv($total + $perPage - 1, $perPage));
    }

    /** The SQL Server row offset for a 1-indexed page. */
    public static function offset(int $page, int $perPage): int
    {
        return ($page - 1) * $perPage;
    }
}
