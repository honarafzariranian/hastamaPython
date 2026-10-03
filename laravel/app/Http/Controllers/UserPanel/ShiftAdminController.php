<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyHozoorReport;
use App\Support\Legacy\LegacyPath;
use App\Support\Legacy\LegacyPythonScalar;
use App\Support\Legacy\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The monthly shift administration — `GET /get_shifts/{username}/{year}/{month}`,
 * `POST /add_shift`, `POST /update_shift` and `POST /delete_shift/{shift_id}`
 * from `app/main.py`.
 *
 * All four are guarded by `_require_admin`, so they carry the `admin`
 * middleware.  The interesting parts are the two rules a plausible
 * implementation gets wrong:
 *
 * * **Username matching is normalised on both sides.**  The column goes through
 *   `REPLACE(REPLACE(LTRIM(RTRIM(username)), N'ي', N'ی'), N'ك', N'ک')` and the
 *   parameter through `_normalize_fa_username()`, so a shift stored with the
 *   Arabic yeh still matches a request typed with the Persian yeh.
 * * **Ranges may not overlap** within one user and one Jalali month.  The check
 *   is `start_day <= ? AND end_day >= ?` against the *new* range, and
 *   `update_shift` excludes the row being edited (`id <> ?`).
 *
 * The day columns are written and read as a fixed set; a missing day value is
 * stored as NULL, and a NULL is read back as `''` because the shift renderer
 * concatenates them without a null check.
 */
final class ShiftAdminController extends Controller
{
    /** `_safe_error_message()` from `app/main.py`. */
    private const SAFE_ERROR = 'خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید.';

    /** The overlap message, shared by `add_shift` and `update_shift`. */
    private const OVERLAP_MESSAGE = 'این بازه با یک بازه‌ی تعریف‌شده‌ی دیگر برای همین ماه همپوشانی دارد';

    /**
     * `GET /get_shifts/{username}/{year}/{month}` — one user's shifts for a month.
     *
     * `year` and `month` are declared `int` path parameters in the Python, so a
     * non-numeric segment is a 422 with `loc: ["path", …]` — not a 404 and not a
     * 500.
     *
     * @param  string  $username  The path username, normalised before the lookup.
     */
    public function show(string $username, string $year, string $month): JsonResponse
    {
        $year = LegacyPath::int($year, 'year');
        $month = LegacyPath::int($month, 'month');

        try {
            $normalised = LegacyPythonScalar::normalizeFaUsername($username);

            $rows = DB::connection()->select(
                'SELECT id, start_day, end_day, shanbeh, yekshanbeh, doshanbeh, seshanbeh,
                        chaharshanbeh, panjshanbeh, jomeh, title
                 FROM shiftha
                 WHERE '.PersianText::normalizedColumnExpression('username').' = ?
                   AND jalali_year = ? AND jalali_month = ?
                 ORDER BY start_day',
                [$normalised, (int) $year, (int) $month]
            );

            $shifts = [];

            foreach ($rows as $row) {
                $shifts[] = [
                    'id' => (int) $row->id,
                    'start_day' => (int) $row->start_day,
                    'end_day' => (int) $row->end_day,
                    'shanbeh' => $row->shanbeh ?: '',
                    'yekshanbeh' => $row->yekshanbeh ?: '',
                    'doshanbeh' => $row->doshanbeh ?: '',
                    'seshanbeh' => $row->seshanbeh ?: '',
                    'chaharshanbeh' => $row->chaharshanbeh ?: '',
                    'panjshanbeh' => $row->panjshanbeh ?: '',
                    'jomeh' => $row->jomeh ?: '',
                    'title' => $row->title ?: '',
                ];
            }

            return response()->json(['success' => true, 'shifts' => $shifts]);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => self::SAFE_ERROR]);
        }
    }

    /**
     * `POST /add_shift` — create a shift range for a user.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $body = json_decode((string) $request->getContent());

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('The request body is not valid JSON.');
            }

            $data = is_object($body) ? get_object_vars($body) : null;

            if (! is_array($data)) {
                throw new \RuntimeException('The request body is not a JSON object.');
            }

            $username = $data['username'] ?? null;

            $year = LegacyPythonScalar::pyInt($data['jalali_year'] ?? null);
            $month = LegacyPythonScalar::pyInt($data['jalali_month'] ?? null);
            $startDay = LegacyPythonScalar::pyInt($data['start_day'] ?? null);
            $endDay = LegacyPythonScalar::pyInt($data['end_day'] ?? null);
            $title = ($data['title'] ?? null) ?: null;

            if (! $username || ! ($month >= 1 && $month <= 12) || ! ($startDay >= 1 && $startDay <= 31) || $endDay < $startDay) {
                return response()->json(['success' => false, 'message' => 'اطلاعات ارسالی نامعتبر است']);
            }

            $days = $this->dayColumns($data);
            $normalised = LegacyPythonScalar::normalizeFaUsername($username);

            $overlap = DB::connection()->table('shiftha')
                ->whereRaw(PersianText::normalizedColumnExpression('username').' = ?', [$normalised])
                ->where('jalali_year', $year)
                ->where('jalali_month', $month)
                ->where('start_day', '<=', $endDay)
                ->where('end_day', '>=', $startDay)
                ->count();

            if ($overlap > 0) {
                return response()->json(['success' => false, 'message' => self::OVERLAP_MESSAGE]);
            }

            DB::connection()->table('shiftha')->insert([
                'username' => $normalised,
                'jalali_year' => $year,
                'jalali_month' => $month,
                'start_day' => $startDay,
                'end_day' => $endDay,
                'shanbeh' => $days['shanbeh'],
                'yekshanbeh' => $days['yekshanbeh'],
                'doshanbeh' => $days['doshanbeh'],
                'seshanbeh' => $days['seshanbeh'],
                'chaharshanbeh' => $days['chaharshanbeh'],
                'panjshanbeh' => $days['panjshanbeh'],
                'jomeh' => $days['jomeh'],
                'title' => $title,
            ]);

            return response()->json(['success' => true, 'message' => 'شیفت با موفقیت ثبت شد']);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => self::SAFE_ERROR]);
        }
    }

    /**
     * `POST /update_shift` — change an existing shift range.
     *
     * The row's own username/year/month are read back from the database, so the
     * overlap check runs against the month the shift is actually in.
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $body = json_decode((string) $request->getContent());

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \RuntimeException('The request body is not valid JSON.');
            }

            $data = is_object($body) ? get_object_vars($body) : null;

            if (! is_array($data)) {
                throw new \RuntimeException('The request body is not a JSON object.');
            }

            $shiftId = LegacyPythonScalar::pyInt($data['id'] ?? null);
            $startDay = LegacyPythonScalar::pyInt($data['start_day'] ?? null);
            $endDay = LegacyPythonScalar::pyInt($data['end_day'] ?? null);
            $title = ($data['title'] ?? null) ?: null;

            if (! ($startDay >= 1 && $startDay <= 31) || $endDay < $startDay) {
                return response()->json(['success' => false, 'message' => 'بازه‌ی روز نامعتبر است']);
            }

            $days = $this->dayColumns($data);

            $row = DB::connection()->table('shiftha')
                ->where('id', $shiftId)
                ->first(['username', 'jalali_year', 'jalali_month']);

            if ($row === null) {
                return response()->json(['success' => false, 'message' => 'شیفت مورد نظر پیدا نشد']);
            }

            $username = $row->username;
            $year = (int) $row->jalali_year;
            $month = (int) $row->jalali_month;

            $overlap = DB::connection()->table('shiftha')
                ->whereRaw(PersianText::normalizedColumnExpression('username').' = ?', [$username])
                ->where('jalali_year', $year)
                ->where('jalali_month', $month)
                ->where('id', '<>', $shiftId)
                ->where('start_day', '<=', $endDay)
                ->where('end_day', '>=', $startDay)
                ->count();

            if ($overlap > 0) {
                return response()->json(['success' => false, 'message' => self::OVERLAP_MESSAGE]);
            }

            DB::connection()->table('shiftha')
                ->where('id', $shiftId)
                ->update([
                    'start_day' => $startDay,
                    'end_day' => $endDay,
                    'shanbeh' => $days['shanbeh'],
                    'yekshanbeh' => $days['yekshanbeh'],
                    'doshanbeh' => $days['doshanbeh'],
                    'seshanbeh' => $days['seshanbeh'],
                    'chaharshanbeh' => $days['chaharshanbeh'],
                    'panjshanbeh' => $days['panjshanbeh'],
                    'jomeh' => $days['jomeh'],
                    'title' => $title,
                ]);

            return response()->json(['success' => true, 'message' => 'شیفت با موفقیت به‌روزرسانی شد']);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => self::SAFE_ERROR]);
        }
    }

    /**
     * `POST /delete_shift/{shift_id}` — remove a shift.
     *
     * The Python registers this as `POST`, not `DELETE`, and the front-end calls
     * it with `method: 'POST'` (`admin.js:5727`); the port keeps the Python's
     * method.
     */
    public function destroy(string $shiftId): JsonResponse
    {
        $shiftId = LegacyPath::int($shiftId, 'shift_id');

        try {
            DB::connection()->table('shiftha')->where('id', $shiftId)->delete();

            return response()->json(['success' => true, 'message' => 'شیفت حذف شد']);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => self::SAFE_ERROR]);
        }
    }

    /**
     * The seven day columns from the body, each `null` when absent or empty.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function dayColumns(array $data): array
    {
        $days = [];

        foreach (LegacyHozoorReport::shiftDayColumns() as $column) {
            $days[$column] = ($data[$column] ?? null) ?: null;
        }

        return $days;
    }
}
