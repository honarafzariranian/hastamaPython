<?php

namespace App\Http\Controllers\Admin;

use App\Support\Legacy\LegacyDate;
use App\Support\Legacy\LegacyHozoorReport;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use ValueError;

/**
 * The administrator leave actions — `POST /update_leave_status` and
 * `POST /generate_individual_report` from `app/main.py`.
 *
 * Both guard with `_require_admin` as their first action and read the body
 * with `await request.json()`, so neither has pre-handler validation and both
 * carry the `admin` middleware.
 *
 * A quirk both share, reproduced: the body is read **outside** the `try`, so a
 * body that is not valid JSON — or is valid JSON but not an object — raises
 * before the handler's own error handling and answers a framework **500**,
 * where a body that parses but names a missing request answers the handler's
 * own 200 body.
 */
final class LeaveAdminController extends AdminPanelController
{
    /**
     * `POST /update_leave_status` — move one leave request to a new status.
     *
     * A missing `requestId` or `status` is a 200 carrying
     * `{'success': false, 'message': 'اطلاعات ناقص ارسال شده است.'}`.  A row
     * that is actually updated also notifies the requester.  A database failure
     * is a 200 carrying `{'success': false, 'message': 'خطا در به‌روزرسانی وضعیت.'}`.
     */
    public function updateStatus(Request $request): JsonResponse
    {
        // `await request.json()` is outside the Python's `try`: an unparseable
        // body, or one that is not an object, is a framework 500 — not the 200
        // the handler's own failures answer.
        $data = json_decode((string) $request->getContent());

        if (json_last_error() !== JSON_ERROR_NONE || ! is_object($data)) {
            throw new \RuntimeException('The request body is not a JSON object.');
        }

        $requestId = $data->requestId ?? null;
        $newStatus = $data->status ?? null;

        if (! $requestId || ! $newStatus) {
            return response()->json(['success' => false, 'message' => 'اطلاعات ناقص ارسال شده است.']);
        }

        try {
            $updated = DB::connection()->update(
                'UPDATE mrkhc_table SET status = ? WHERE id = ?',
                [$newStatus, $requestId]
            );

            if ($updated) {
                $this->notifyRequesterStatus('mrkhc_table', $requestId, $newStatus, 'مرخصی');
            }

            return response()->json(['success' => true, 'message' => 'وضعیت با موفقیت به‌روزرسانی شد!']);
        } catch (Throwable) {
            return response()->json(['success' => false, 'message' => 'خطا در به‌روزرسانی وضعیت.']);
        }
    }

    /**
     * `POST /generate_individual_report` — one user's decided leave rows.
     *
     * The body carries a Jalali `fromDate` / `toDate` and an optional `user`.
     * When `user` is empty the report covers everyone; otherwise it is filtered
     * to that username.  Only the decided statuses are returned
     * (`تایید شده`, `رد شده`, `انصراف`).
     *
     * The dates are parsed with `jdatetime.date.fromisoformat`, which requires
     * a zero-padded `YYYY-MM-DD` — the handler rewrites the slashes to dashes
     * first, so `1405/07/08` works and `1405/7/8` does not.  A date that will
     * not parse is a 500 `{'success': false, 'message': 'خطا در دریافت اطلاعات'}`.
     */
    public function generateIndividual(Request $request): JsonResponse
    {
        // Outside the `try`, for the same reason as `updateStatus`.
        $data = json_decode((string) $request->getContent());

        if (json_last_error() !== JSON_ERROR_NONE || ! is_object($data)) {
            throw new \RuntimeException('The request body is not a JSON object.');
        }

        $username = $data->user ?? null;
        $fromDate = $data->fromDate ?? null;
        $toDate = $data->toDate ?? null;

        try {
            $fromGregorian = $this->jalaliToGregorian($fromDate)->format('Y-m-d');
            $toGregorian = $this->jalaliToGregorian($toDate)->format('Y-m-d');

            if (! $username) {
                $rows = DB::connection()->select(
                    'SELECT start_date, end_date, days, id, substitute, status, username
                     FROM mrkhc_table
                     WHERE CONVERT(date, start_date, 120) >= ?
                       AND CONVERT(date, end_date, 120) <= ?
                       AND status IN (N\'تایید شده\', N\'رد شده\', N\'انصراف\')',
                    [$fromGregorian, $toGregorian]
                );
            } else {
                $rows = DB::connection()->select(
                    'SELECT start_date, end_date, days, id, substitute, status, username
                     FROM mrkhc_table
                     WHERE username = ?
                       AND CONVERT(date, start_date, 120) >= ?
                       AND CONVERT(date, end_date, 120) <= ?
                       AND status IN (N\'تایید شده\', N\'رد شده\', N\'انصراف\')',
                    [$username, $fromGregorian, $toGregorian]
                );
            }

            $reportData = [];

            foreach ($rows as $row) {
                $reportData[] = [
                    // `jdatetime.date.fromgregorian(date=…).strftime('%Y/%m/%d')`
                    // — slashes, not the dashes `jalaliDateString()` produces.
                    'start_date' => $this->jalaliSlashes($row->start_date),
                    'end_date' => $this->jalaliSlashes($row->end_date),
                    'days' => $row->days,
                    'id' => $row->id,
                    'substitute' => $row->substitute,
                    'status' => $row->status,
                    'username' => $row->username,
                ];
            }

            return response()->json(['success' => true, 'reports' => $reportData]);
        } catch (ValueError) {
            return response()->json(['success' => false, 'message' => 'خطا در دریافت اطلاعات'], 500);
        } catch (Throwable) {
            return response()->json(['success' => false, 'message' => 'خطا در دریافت اطلاعات'], 500);
        }
    }

    /**
     * `jdatetime.date.fromgregorian(date=…).strftime('%Y/%m/%d')`.
     *
     * A `None` date raises `TypeError` in the Python, which the handler does not
     * catch — a framework 500.  The conversion raises here and is caught by the
     * handler's own `except Exception`, which is the same 500 body.
     *
     * @throws \InvalidArgumentException when the value is not a Gregorian date.
     */
    private function jalaliSlashes(mixed $value): string
    {
        return LegacyDate::fromGregorian(
            $value instanceof \DateTimeInterface ? $value : (string) $value
        )->format('%Y/%m/%d');
    }

    /**
     * `jdatetime.date.fromisoformat(value.replace('/', '-')).togregorian()`.
     *
     * `fromisoformat` requires a zero-padded `YYYY-MM-DD`; anything else — a
     * non-padded date, the wrong number of parts, a non-integer part, an
     * out-of-range month or day — raises `ValueError`, which the handler turns
     * into its 500.  A non-string raises before the `try` in the Python
     * (`None.replace` / `int.replace`), which is a framework 500.
     *
     * @throws ValueError when the string is not a valid zero-padded Jalali date.
     * @throws \Error when the value is not a string at all.
     */
    private function jalaliToGregorian(mixed $value): CarbonImmutable
    {
        if (! is_string($value)) {
            throw new \Error('The date must be a string.');
        }

        $normalized = str_replace('/', '-', $value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $normalized, $matches) !== 1) {
            throw new ValueError('Invalid isoformat string');
        }

        try {
            return LegacyHozoorReport::fromJalaliParts(
                (int) $matches[1],
                (int) $matches[2],
                (int) $matches[3]
            )->toGregorian();
        } catch (\InvalidArgumentException) {
            throw new ValueError('Invalid Jalali date');
        }
    }
}
