<?php

namespace App\Http\Controllers\Admin;

use App\Support\Legacy\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The admin dashboard's numbers — the context `_render_admin_page` built for
 * `admin.html` in `app/main.py`, exposed as JSON.
 *
 * **Why this endpoint exists.**  In the running application the dashboard's
 * figures were *template context*: the page handler summed four tables and
 * handed the results to Jinja, so there was no URL that answered them.  A Vue
 * page cannot read template context, so the same sums are served here.  Where
 * the Python formatted a value before rendering it (`convert_to_persian_numbers`,
 * `format_time`), the formatting happens here too and the response carries the
 * same strings the template received — the markup binds what the template bound.
 *
 * **The sums, table by table** (nothing below is invented; each line mirrors a
 * line of `_render_admin_page`):
 *
 * | Source | Values |
 * |---|---|
 * | `user_table` | `total_users`, `unique_departments`, `top_department_*` |
 * | `totalpass_table` (`status = 'تایید شده'`) | `total_pass_time`, `pass_percent`, `average_pass_per_user`, `top_pass_user`, the five-row pass chart |
 * | `ezafe_total_table` | `total_overtime_time`, `overtime_percent`, `overtime_user_count`, `no_overtime_users`, `average_overtime_per_user`, `top_overtime_user`, the five-row overtime chart |
 * | `leave_report` | `total_leave_taken`, `total_leave_requests` |
 *
 * **Formatting differences that are easy to "fix" by accident.**  The aggregate
 * pass time is `H:MM` (hours unpadded) while every per-user time is `HH:MM`
 * (padded) — the Python builds the first inline and the second through
 * `format_time()`.  Averages divide by `max(1, users)`, and a user count of zero
 * therefore divides by one rather than raising.  Both are reproduced, because a
 * dashboard that prints `07:11` where the running application prints `7:11` is
 * not the same dashboard.
 *
 * **Ties.**  `max()` in the Python walks the rows in fetch order and keeps the
 * first maximum, so two users with the same total resolve to the row the
 * database returned first.  The scans below are deliberately written the same
 * way instead of sorting and taking the head.
 */
final class DashboardStatsController extends AdminPanelController
{
    public function stats(): JsonResponse
    {
        try {
            return response()->json($this->dashboard());
        } catch (Throwable) {
            /* The Python page answered 500 `{"detail": "خطای داخلی سرور."}` when
             * the handler raised.  A JSON client needs the same verdict without
             * the framework's stack-trace shape. */
            return response()->json(['detail' => 'خطای داخلی سرور.'], 500);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dashboard(): array
    {
        $users = DB::connection()->select('SELECT username, department FROM user_table');
        $passRows = DB::connection()->select(
            "SELECT username, SUM(DATEDIFF(SECOND, 0, pass_duration)) AS total_pass_seconds
             FROM totalpass_table
             WHERE status = 'تایید شده'
             GROUP BY username"
        );
        $overtimeRows = DB::connection()->select('SELECT username, total_ezafe_time FROM ezafe_total_table');
        $leaveRows = DB::connection()->select('SELECT username, total_days, remaining_days FROM leave_report');

        $totalUsers = count($users);

        /* `{user.department for user in users if user.department}` — a falsy
         * department (empty string, NULL) is skipped, and the counter preserves
         * first-seen order for the tie-breaking `most_common(1)`. */
        $departments = [];
        foreach ($users as $user) {
            $department = $user->department ?? null;

            if ($department === null || $department === '') {
                continue;
            }

            $departments[$department] = ($departments[$department] ?? 0) + 1;
        }

        $uniqueDepartments = count($departments);
        $topDepartmentName = '-';
        $topDepartmentCount = 0;
        foreach ($departments as $name => $count) {
            if ($count > $topDepartmentCount) {
                $topDepartmentName = (string) $name;
                $topDepartmentCount = $count;
            }
        }

        /* ── Hourly pass ─────────────────────────────────────────────── */

        $passSecondsByUser = [];
        $totalPassSeconds = 0;
        foreach ($passRows as $row) {
            $seconds = $this->seconds($row->total_pass_seconds);
            $passSecondsByUser[] = ['username' => (string) $row->username, 'seconds' => $seconds];
            $totalPassSeconds += $seconds;
        }

        $passSorted = $passSecondsByUser;
        usort($passSorted, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        $topPass = null;
        foreach ($passSecondsByUser as $row) {
            if ($topPass === null || $row['seconds'] > $topPass['seconds']) {
                $topPass = $row;
            }
        }

        /* ── Overtime ────────────────────────────────────────────────── */

        $overtimeSecondsByUser = [];
        $totalOvertimeSeconds = 0;
        $overtimeUsers = [];
        foreach ($overtimeRows as $row) {
            $seconds = $this->seconds($row->total_ezafe_time);
            $username = (string) $row->username;
            $overtimeSecondsByUser[] = ['username' => $username, 'seconds' => $seconds];
            $totalOvertimeSeconds += $seconds;
            $overtimeUsers[$username] = true;
        }

        $overtimeSorted = $overtimeSecondsByUser;
        usort($overtimeSorted, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);

        $topOvertime = null;
        foreach ($overtimeSecondsByUser as $row) {
            if ($topOvertime === null || $row['seconds'] > $topOvertime['seconds']) {
                $topOvertime = $row;
            }
        }

        /* ── Leave ───────────────────────────────────────────────────── */

        $totalLeaveTaken = 0;
        foreach ($leaveRows as $row) {
            $totalLeaveTaken += $this->seconds($row->total_days);
        }

        $overtimeUserCount = count($overtimeUsers);
        $capacity = max(1, $totalUsers * 3600);

        return [
            'total_users' => $this->digits($totalUsers),
            'unique_departments' => $this->digits($uniqueDepartments),
            'overtime_user_count' => $this->digits($overtimeUserCount),
            'no_overtime_users' => $this->digits(max(0, $totalUsers - $overtimeUserCount)),

            /* `H:MM` — see the class docblock. */
            'total_pass_time' => $this->digits(intdiv($totalPassSeconds, 3600).':'.str_pad((string) intdiv($totalPassSeconds % 3600, 60), 2, '0', STR_PAD_LEFT)),
            'total_overtime_time' => $this->digits($this->formatTime($totalOvertimeSeconds)),

            'average_pass_per_user' => $this->digits($this->formatTime(intdiv($totalPassSeconds, max(1, $totalUsers)))),
            'average_overtime_per_user' => $this->digits($this->formatTime(intdiv($totalOvertimeSeconds, max(1, $totalUsers)))),

            'top_pass_user' => $topPass === null ? null : [
                'username' => $topPass['username'],
                'total_pass_time' => $this->digits($this->formatTime($topPass['seconds'])),
            ],
            'top_overtime_user' => $topOvertime === null ? null : [
                'username' => $topOvertime['username'],
                'total_ezafe_time' => $this->digits($this->formatTime($topOvertime['seconds'])),
            ],

            'top_department_name' => $topDepartmentName,
            'top_department_count' => $this->digits($topDepartmentCount),

            'total_leave_taken' => $this->digits($totalLeaveTaken),
            'total_leave_requests' => $this->digits(count($leaveRows)),

            'pass_percent' => $this->digits(min(100, (int) ($totalPassSeconds / $capacity * 100))),
            'overtime_percent' => $this->digits(min(100, (int) ($totalOvertimeSeconds / $capacity * 100))),

            'pass_chart_data' => $this->chart($passSorted),
            'overtime_chart_data' => $this->chart($overtimeSorted),
        ];
    }

    /**
     * The five-row chart both half-width cards draw, from an already sorted list.
     *
     * `percent` is relative to the **largest** value, and a largest value of zero
     * becomes 1 so an all-zero table renders flat bars instead of dividing by
     * zero — the Python's `if max_seconds == 0: max_seconds = 1`.
     *
     * @param  list<array{username: string, seconds: int}>  $sorted
     * @return list<array{username: string, display: string, percent: int}>
     */
    private function chart(array $sorted): array
    {
        $maximum = $sorted === [] ? 1 : max(1, $sorted[0]['seconds']);

        $rows = [];
        foreach (array_slice($sorted, 0, 5) as $row) {
            $rows[] = [
                'username' => $row['username'],
                'display' => $this->digits($this->formatTime($row['seconds'])),
                'percent' => min(100, (int) ($row['seconds'] / $maximum * 100)),
            ];
        }

        return $rows;
    }

    /** `format_time()` — `HH:MM`, hours padded. */
    private function formatTime(int $seconds): string
    {
        return str_pad((string) intdiv($seconds, 3600), 2, '0', STR_PAD_LEFT)
            .':'.str_pad((string) intdiv($seconds % 3600, 60), 2, '0', STR_PAD_LEFT);
    }

    private function digits(int|string $value): string
    {
        return PersianText::toPersianDigits((string) $value);
    }

    /**
     * `safe_int()` — an integer, a float, or the `HH:MM[:SS]` string SQL Server
     * hands back for a `time` column.
     *
     * The Python had a branch per driver type (`timedelta`, `datetime.time`);
     * PHP receives those same values as strings, which is why the parse lives
     * here rather than in the SQL.
     */
    private function seconds(mixed $value): int
    {
        if ($value === null) {
            return 0;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        $text = trim((string) $value);

        if ($text === '') {
            return 0;
        }

        if (preg_match('/^-?\d+$/', $text) === 1) {
            return (int) $text;
        }

        /* `HH:MM` and `HH:MM:SS`, exactly as `parse_seconds()` reads them: two
         * parts are hours and minutes (not minutes and seconds), three are
         * hours, minutes and seconds, and anything else — including a part that
         * is not an integer, and the fractional tail of a `time` value such as
         * "00:12:30.0000000" — is zero, not a guess. */
        $parts = explode(':', $text);

        if (count($parts) < 2 || count($parts) > 3) {
            return 0;
        }

        $values = [];
        foreach ($parts as $part) {
            $part = trim($part);

            /* A fractional tail belongs to the seconds part only. */
            $part = (string) preg_replace('/\..*$/', '', $part);

            if (preg_match('/^-?\d+$/', $part) !== 1) {
                return 0;
            }

            $values[] = (int) $part;
        }

        return $values[0] * 3600 + $values[1] * 60 + ($values[2] ?? 0);
    }
}
