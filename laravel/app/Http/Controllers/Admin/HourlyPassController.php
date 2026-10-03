<?php

namespace App\Http\Controllers\Admin;

use App\Support\Legacy\LegacyDate;
use App\Support\Legacy\LegacyHozoorReport;
use App\Support\Legacy\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use ValueError;

/**
 * The hourly-pass approvals — `GET /get_hourly_pass_requests`,
 * `POST /change_hourly_pass_status`, `POST /get_hourly_pass_report` and
 * `POST /update_hourly_pass_status` from `app/main.py`.
 *
 * All four guard with `_require_admin` as their first action and read the body
 * with `await request.json()`, so none has pre-handler validation and all four
 * carry the `admin` middleware.
 *
 * The two status writes differ in exactly one byte of their failure body, and
 * both are live:
 *
 * | route | success | failure |
 * |---|---|---|
 * | `change_hourly_pass_status` | `{"success": true}` | 500 `{"success": false, "message": "خطای داخلی سرور"}` |
 * | `update_hourly_pass_status` | `{"success": true}` | 500 `{"success": false, "message": "خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید."}` |
 *
 * The second is `_safe_error_message()`; the first is a shorter literal the
 * handler wrote itself.
 */
final class HourlyPassController extends AdminPanelController
{
    /** `_safe_error_message()` — the generic refusal the second handler uses. */
    private const SAFE_ERROR = 'خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید.';

    /**
     * `GET /get_hourly_pass_requests` — the pending queue.
     *
     * A **bare array**, with no envelope.  A missing date renders as the
     * placeholder `تاریخ ناموجود`; a missing status becomes `انتظار تایید`.
     * `pass_duration` is `str(time)` — `01:30:00`, seconds included — which is
     * a different shape from the `%H:%M` the report endpoint publishes.
     */
    public function requests(): JsonResponse
    {
        try {
            $rows = DB::connection()->select(
                'SELECT id, request_date, pass_title, pass_duration, username, status
                 FROM totalpass_table
                 WHERE status = \'انتظار تایید\''
            );

            $requestsData = [];

            foreach ($rows as $row) {
                $requestsData[] = [
                    'id' => $row->id,
                    'request_date' => $this->jalaliSlashes($row->request_date, 'تاریخ ناموجود'),
                    'pass_title' => $row->pass_title,
                    // `str(request[3])` — a `time` serialises with its seconds,
                    // and a NULL serialises as the string "None".
                    'pass_duration' => $row->pass_duration === null ? 'None' : (string) $row->pass_duration,
                    'username' => $row->username,
                    'status' => $row->status ?: 'انتظار تایید',
                ];
            }

            return response()->json($requestsData);
        } catch (Throwable) {
            return response()->json([
                'error' => 'خطا در دریافت داده‌ها',
                'message' => self::SAFE_ERROR,
            ], 500);
        }
    }

    /**
     * `POST /change_hourly_pass_status` — move one pass request to a new status.
     *
     * There is no validation of a missing `id` or `status`: they are passed to
     * the UPDATE as `None`, which matches no row and answers success.  A row
     * that is actually updated also notifies the requester.
     */
    public function changeStatus(Request $request): JsonResponse
    {
        try {
            $data = json_decode((string) $request->getContent());

            if (json_last_error() !== JSON_ERROR_NONE || ! is_object($data)) {
                throw new \RuntimeException('The request body is not a JSON object.');
            }

            $requestId = $data->id ?? null;
            $newStatus = $data->status ?? null;

            $updated = DB::connection()->update(
                'UPDATE totalpass_table SET status = ? WHERE id = ?',
                [$newStatus, $requestId]
            );

            if ($updated) {
                $this->notifyRequesterStatus('totalpass_table', $requestId, $newStatus, 'پاس ساعتی');
            }

            return response()->json(['success' => true]);
        } catch (Throwable) {
            return response()->json(['success' => false, 'message' => 'خطای داخلی سرور'], 500);
        }
    }

    /**
     * `POST /get_hourly_pass_report` — every decided pass in a Jalali range.
     *
     * `username: "all_users"` covers everyone; any other value (including a
     * missing one) filters to that username.  Pending passes are excluded.  The
     * dates are parsed with `JalaliDate(*map(int, ….split('/')))`, so Persian
     * digits are folded first and a non-padded or malformed date is refused.
     *
     * A `ValueError` from the date parse is a 400; anything else — a
     * `TypeError` from the wrong number of parts, a database failure — is a
     * 500.  An unparseable body is a `json.JSONDecodeError`, which is a
     * `ValueError` subclass, so it answers the **400** like a bad date.
     */
    public function report(Request $request): JsonResponse
    {
        try {
            $data = json_decode((string) $request->getContent());

            if (json_last_error() !== JSON_ERROR_NONE || ! is_object($data)) {
                throw new ValueError('The request body is not a JSON object.');
            }

            $username = $data->username ?? null;
            $startDate = $this->jalaliReportDate($data->start_date ?? '');
            $endDate = $this->jalaliReportDate($data->end_date ?? '');

            if ($username === 'all_users') {
                $rows = DB::connection()->select(
                    'SELECT id, request_date, pass_title, pass_duration, status, username
                     FROM totalpass_table
                     WHERE request_date BETWEEN ? AND ? AND status != \'انتظار تایید\'',
                    [$startDate, $endDate]
                );
            } else {
                $rows = DB::connection()->select(
                    'SELECT id, request_date, pass_title, pass_duration, status, username
                     FROM totalpass_table
                     WHERE username = ? AND request_date BETWEEN ? AND ? AND status != \'انتظار تایید\'',
                    [$username, $startDate, $endDate]
                );
            }

            $result = [];

            foreach ($rows as $row) {
                $result[] = [
                    'id' => $row->id,
                    'username' => $row->username,
                    'request_date' => $this->jalaliSlashes($row->request_date, 'تاریخ ناموجود'),
                    'pass_title' => $row->pass_title,
                    'pass_duration' => self::timeToHi($row->pass_duration),
                    'status' => $row->status,
                ];
            }

            return response()->json($result);
        } catch (ValueError) {
            return response()->json([
                'error' => 'تاریخ وارد شده صحیح نیست. لطفاً فرمت صحیح را وارد کنید.',
            ], 400);
        } catch (Throwable) {
            return response()->json(['error' => 'خطای داخلی سرور'], 500);
        }
    }

    /**
     * `POST /update_hourly_pass_status` — the second status write.
     *
     * The same shape as {@see changeStatus} with the `_safe_error_message()`
     * failure body.
     */
    public function updateStatus(Request $request): JsonResponse
    {
        try {
            $data = json_decode((string) $request->getContent());

            if (json_last_error() !== JSON_ERROR_NONE || ! is_object($data)) {
                throw new \RuntimeException('The request body is not a JSON object.');
            }

            $rowId = $data->id ?? null;
            $newStatus = $data->status ?? null;

            $updated = DB::connection()->update(
                'UPDATE totalpass_table SET status = ? WHERE id = ?',
                [$newStatus, $rowId]
            );

            if ($updated) {
                $this->notifyRequesterStatus('totalpass_table', $rowId, $newStatus, 'پاس ساعتی');
            }

            return response()->json(['success' => true]);
        } catch (Throwable) {
            return response()->json(['success' => false, 'message' => self::SAFE_ERROR], 500);
        }
    }

    /**
     * `JalaliDate(*map(int, convert_farsi_to_english(str(value)).split('/')))`
     * for the two report endpoints.
     *
     * Persian digits are folded first (`convert_farsi_to_english`); the value
     * is then split on `/` and each part is `int()`-ed.  The order is the
     * Python's and it is observable: `map(int, …)` runs before the `JalaliDate`
     * call, so a part that is not an integer is a `ValueError` even when the
     * number of parts is also wrong — `not-a-date` is a `ValueError`, where a
     * count-first parse would report a `TypeError`.
     *
     * @throws ValueError
     * @throws \TypeError
     */
    private function jalaliReportDate(mixed $value): CarbonImmutable
    {
        $text = PersianText::toLatinDigits((string) ($value ?? ''));
        $parts = explode('/', $text);

        $numbers = [];

        foreach ($parts as $part) {
            if (preg_match('/^[+-]?\d+$/', trim($part)) !== 1) {
                throw new ValueError('invalid literal for int()');
            }

            $numbers[] = (int) $part;
        }

        if (count($numbers) !== 3) {
            throw new \TypeError('jdatetime.date() takes 3 arguments');
        }

        try {
            return LegacyHozoorReport::fromJalaliParts($numbers[0], $numbers[1], $numbers[2])->toGregorian();
        } catch (\InvalidArgumentException) {
            throw new ValueError('invalid Jalali date');
        }
    }

    /**
     * `jdatetime.date.fromgregorian(date=…).strftime('%Y/%m/%d')`, with the
     * placeholder the two list endpoints substitute for a missing date.
     */
    private function jalaliSlashes(mixed $value, string $fallback): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        return LegacyDate::fromGregorian(
            $value instanceof \DateTimeInterface ? $value : (string) $value
        )->format('%Y/%m/%d');
    }

    /**
     * `time.strftime('%H:%M')` — a `time` as `HH:MM`, or `null` for a NULL.
     *
     * `pyodbc` handed the running server a `time` object; `pdo_sqlsrv` hands
     * back the `HH:MM:SS` string, which is re-formatted to the same `HH:MM`.
     */
    private static function timeToHi(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = is_string($value) ? trim($value) : '';

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $matches) === 1) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        return (string) $value;
    }
}
