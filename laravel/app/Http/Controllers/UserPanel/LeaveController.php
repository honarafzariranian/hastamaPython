<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Leave reads — the user's own list (`GET /get_leave_info`) and the administrator's
 * view of everyone's (`GET /get_leave_requests`), both from `app/main.py`.
 *
 * The interesting part of both is the **Jalali conversion**, and specifically how
 * `mrkhc_table` stores its dates.  `start_date` and `end_date` are `date` columns, so
 * `pyodbc` returned `datetime.date` objects and the Python handler handed them
 * straight to `jdatetime.date.fromgregorian(date=…)`.  Via `pdo_sqlsrv` the same
 * columns arrive as `'2026-08-27'` strings, which is why the conversion goes through
 * `LegacyDate::fromGregorian()` — it accepts both shapes, and the physical type of
 * the column is irrelevant to the result.
 *
 * Two behaviours that look like bugs and are not:
 *
 * * a missing or unparsable date renders as a **placeholder string**, not null
 *   (`'نامعتبر'` in the user view, `'تاریخ ناموجود'` in the admin view — two
 *   different words for the same condition, because two different handlers wrote
 *   them);
 * * a missing `status` becomes `'انتظار تایید'` ("awaiting approval"), because the
 *   column is nullable and the UI has no empty-state rendering for a status chip.
 *
 * One that *is* a bug and is preserved for now: `get_leave_info` returns the user's
 * leave rows **without their ids**, so the client cannot address an individual row.
 * See `MIGRATION_STATUS.md` — this is a candidate for the write phase, not a read
 * fix.
 */
final class LeaveController extends Controller
{
    /** The user-facing placeholder for a date that cannot be converted. */
    private const INVALID_DATE = 'نامعتبر';

    /** The admin-facing placeholder.  A second string for the same condition. */
    private const MISSING_DATE = 'تاریخ ناموجود';

    /** The default status for a row whose `status` is NULL. */
    private const PENDING_STATUS = 'انتظار تایید';

    /**
     * `GET /get_leave_info` — the signed-in user's own leave requests.
     *
     * The Python handler raised `HTTPException(400, "نام کاربری پیدا نشد")` rather
     * than answering with a `success: false` envelope, and it is the only endpoint in
     * `main.py` that does so for a missing session.  Ported as-is: the body is
     * `{"detail": "نام کاربری پیدا نشد"}` with status 400.
     */
    public function info(Request $request): JsonResponse
    {
        $username = trim((string) $request->session()->get('username', ''));

        if ($username === '') {
            return response()->json(['detail' => 'نام کاربری پیدا نشد'], 400);
        }

        try {
            $rows = DB::connection()->select(
                'SELECT start_date, end_date, days, status FROM mrkhc_table WHERE username = ?',
                [$username]
            );

            $leaves = [];

            foreach ($rows as $row) {
                $leaves[] = [
                    'start_date' => $this->toJalali($row->start_date, self::INVALID_DATE),
                    'end_date' => $this->toJalali($row->end_date, self::INVALID_DATE),
                    'days' => self::legacyInt($row->days),
                    'status' => $row->status === null || $row->status === '' ? self::PENDING_STATUS : $row->status,
                ];
            }

            // An empty list is still `success: true`.  The front-end renders "no
            // requests yet" from a successful empty array.
            return response()->json(['success' => true, 'data' => $leaves]);
        } catch (Throwable $exception) {
            Log::error('get_leave_info failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            // Note the trailing period: `detail="خطای داخلی سرور."` is what the Python
            // handler raised, and it is not the same string as `_safe_error_message()`.
            return response()->json(['detail' => 'خطای داخلی سرور.'], 500);
        }
    }

    /**
     * `GET /get_leave_requests` — every leave request, for the admin review screen.
     *
     * Two shape oddities, both real and both depended on:
     *
     * * success is a **bare JSON array**, with no envelope at all;
     * * failure is HTTP **200** carrying `{"error": …, "message": …}` — the Python
     *   handler returned `JSONResponse(content=…)` with no `status_code`, so a
     *   database error answered success.  Reproduced, because the review screen
     *   branches on the presence of the `error` key and a 500 would surface as a
     *   network failure instead of the message it is written to display.
     */
    public function requests(): JsonResponse
    {
        try {
            $rows = DB::connection()->select(
                'SELECT id, start_date, end_date, days, substitute, username, status FROM mrkhc_table'
            );

            $requests = [];

            foreach ($rows as $row) {
                $requests[] = [
                    // The id is present here but not in the user's own view.  Not an
                    // oversight in either direction: this endpoint is the one with the
                    // approve/reject actions.
                    'id' => (int) $row->id,
                    // `%Y-%m-%d`, *not* `%Y/%m/%d` — the admin screen and the user
                    // screen render Jalali dates with different separators.
                    'start_date' => $this->toJalali($row->start_date, self::MISSING_DATE, '%Y-%m-%d'),
                    'end_date' => $this->toJalali($row->end_date, self::MISSING_DATE, '%Y-%m-%d'),
                    'days' => self::legacyInt($row->days),
                    'substitute' => $row->substitute,
                    // Space-padded in the database (`username` is `nchar`) and passed
                    // through padded, because the admin screen matches this value
                    // against its own padded user list.
                    'username' => $row->username,
                    'status' => $row->status === null || $row->status === '' ? self::PENDING_STATUS : $row->status,
                ];
            }

            return response()->json($requests);
        } catch (Throwable $exception) {
            Log::error('get_leave_requests failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'error' => 'خطا در دریافت داده‌ها',
                'message' => 'خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید.',
            ]);
        }
    }

    /**
     * An integer column, preserving NULL as `null`.
     *
     * `days` is nullable, and `pyodbc` returned `None` for a NULL — `(int) null` is
     * `0` in PHP, which would render as "0 days of leave" instead of a blank cell.
     */
    private static function legacyInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    /**
     * A Gregorian date from the database, as a Jalali string.
     *
     * Returns `$fallback` for a null or unparsable value rather than throwing, which
     * is what both Python handlers did — a single damaged row must not blank the
     * whole list.
     */
    private function toJalali(mixed $value, string $fallback, string $pattern = '%Y/%m/%d'): string
    {
        if ($value === null || $value === '') {
            return $fallback;
        }

        try {
            return LegacyDate::fromGregorian(
                $value instanceof \DateTimeInterface ? $value : (string) $value
            )->format($pattern);
        } catch (Throwable) {
            return $fallback;
        }
    }
}
