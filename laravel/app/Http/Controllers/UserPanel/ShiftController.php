<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `GET /get_active_shifts` — the shifts that cover today.
 *
 * "Active" is not a flag on the row: it is a window test.  Each row in `shiftha`
 * covers a range of days inside one Jalali month (`start_day` … `end_day`), and a
 * shift is active when today's Jalali day number falls inside that range for the
 * current Jalali year and month.
 *
 * Two details the port has to get right:
 *
 * * **The date is Jalali, not Gregorian.**  The comparison is against
 *   `jalali_year`/`jalali_month`/`day`, so a Gregorian "today" would select the
 *   wrong month most of the year.  This is why `LegacyDate` exists.
 * * **`END < NOW` is not the test.**  A shift that started on the 1st and ends on the
 *   31st is returned on the 15th, which an interval-overlap reading of the query
 *   would also give — but a shift ending *today* must still be returned, hence
 *   `end_day >= ?` with the same day number, not a strict inequality.
 *
 * The response carries the date it resolved (`today`, `year`, `month`) alongside the
 * rows, because the client renders "today is the 8th of Mehr" from it and must not
 * recompute it against its own clock.
 */
final class ShiftController extends Controller
{
    /** `GET /get_active_shifts` */
    public function active(): JsonResponse
    {
        try {
            // `jdatetime.date.today()` in the Python handler — the deployment clock.
            $today = LegacyDate::today();

            $rows = DB::connection()->select(
                'SELECT id, username, jalali_year, jalali_month, start_day, end_day, title,
                        shanbeh, yekshanbeh, doshanbeh, seshanbeh,
                        chaharshanbeh, panjshanbeh, jomeh
                 FROM shiftha
                 WHERE jalali_year = ? AND jalali_month = ?
                   AND start_day <= ? AND end_day >= ?
                 ORDER BY username, start_day',
                [$today->year, $today->month, $today->day, $today->day]
            );

            $shifts = [];

            foreach ($rows as $row) {
                $shifts[] = [
                    'id' => (int) $row->id,
                    'username' => $row->username,
                    'jalali_year' => (int) $row->jalali_year,
                    'jalali_month' => (int) $row->jalali_month,
                    'start_day' => (int) $row->start_day,
                    'end_day' => (int) $row->end_day,
                    // Every one of these is nullable in the schema and the handler
                    // substituted `''` for a NULL rather than passing `null` on.  The
                    // shift renderer concatenates them without a null check.
                    'title' => $row->title ?? '',
                    'shanbeh' => $row->shanbeh ?? '',
                    'yekshanbeh' => $row->yekshanbeh ?? '',
                    'doshanbeh' => $row->doshanbeh ?? '',
                    'seshanbeh' => $row->seshanbeh ?? '',
                    'chaharshanbeh' => $row->chaharshanbeh ?? '',
                    'panjshanbeh' => $row->panjshanbeh ?? '',
                    'jomeh' => $row->jomeh ?? '',
                ];
            }

            return response()->json([
                'success' => true,
                'shifts' => $shifts,
                // `str(day)` — a string, while `year` and `month` are ints.  That
                // asymmetry is in the running server and the client compares `today`
                // against `shiftha.start_day` after coercing it, so it is kept.
                'today' => (string) $today->day,
                'year' => $today->year,
                'month' => $today->month,
            ]);
        } catch (Throwable $exception) {
            Log::error('get_active_shifts failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => self::safeError()]);
        }
    }

    /** `_safe_error_message()` from `app/main.py`. */
    private static function safeError(): string
    {
        return 'خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید.';
    }
}
