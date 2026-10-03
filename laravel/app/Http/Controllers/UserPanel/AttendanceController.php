<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyAttendanceStatus;
use App\Support\Legacy\LegacyDate;
use App\Support\Legacy\LegacyHozoorReport;
use App\Support\Legacy\LegacyPythonScalar;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PDO;
use RuntimeException;
use Throwable;

/**
 * The attendance surface — the ports of `sabt_hozoor`, `sabt_hozoor_checkin`,
 * `sabt_hozoor_checkout`, `get_hozoor*` and `get_hozoor_filtered` from
 * `app/main.py`.
 *
 * This is the core feature, and the part where a plausible implementation
 * diverges most.  The rules that are reproduced exactly, with the Python
 * file:line they came from:
 *
 * | rule | source |
 * |---|---|
 * | entry/exit, work hours, late arrival, early departure, work schedule, working days | `app/main.py:4974-5024` |
 * | overtime only when it exceeds ten minutes; only the first is added to the total | `app/main.py:4995`, `:5018` |
 * | a `shiftha` range covering the day beats the weekly default, and the first covering range wins even when its own value is empty | `app/main.py:4814-4834` |
 * | the work window is **sorted**, so a reversed stored range is reported ascending | `app/main.py:4955` |
 * | a day with no punches is **تعطیل** on a Friday, **غیبت** otherwise | `app/main.py:4963-4967` |
 * | `hozoor_num` ↔ Araz `CardNo` mapping for the Access punches | `app/main.py:4784`, `:4857` |
 * | the Access query is bounded by the **Jalali** date strings, not the Gregorian ones | `app/main.py:4857` |
 * | check-in refuses a second entry and an entry left open from a previous day | `app/main.py:5163-5184` |
 * | check-out stamps the most recent open entry, which may be from the previous day | `app/main.py:5261-5278` |
 *
 * The pure calculation lives in {@see LegacyHozoorReport} so it can be asserted
 * without a database; this controller owns the reads, the Access fallback and
 * the response assembly.
 */
final class AttendanceController extends Controller
{
    /** `_safe_error_message()` from `app/main.py`. */
    private const SAFE_ERROR = 'خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید.';

    /**
     * `GET /get_hozoor/{username}` — the attendance report for a date range.
     *
     * The report merges three sources, in priority order: the Araz Access
     * punches (keyed by the card number), the `hozoor` table rows, and finally
     * a fill-in row for every calendar day in the range that has neither.  A
     * day that appears in more than one source keeps the first one that claimed
     * it — the Access rows are added first, and the `hozoor` rows only fill a
     * date that is not already present.
     *
     * The Access database is optional: when it is not configured or not
     * reachable the report is built from the `hozoor` table alone, exactly as
     * the Python's `try/except` around the Access connection did.
     */
    public function show(Request $request, string $username): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'start_date' => LegacyQuery::requiredString(),
            'end_date' => LegacyQuery::requiredString(),
        ]);

        $username = trim((string) $username);
        $startDate = trim(LegacyPythonScalar::persianToEnglishDigits($params['start_date']));
        $endDate = trim(LegacyPythonScalar::persianToEnglishDigits($params['end_date']));

        try {
            $fromGregorian = LegacyHozoorReport::jalaliDateFromString($startDate)->toGregorian();
            $toGregorian = LegacyHozoorReport::jalaliDateFromString($endDate)->toGregorian();
        } catch (Throwable) {
            return response()->json(['error' => 'بازه تاریخ معتبر نیست.'], 400);
        }

        if ($fromGregorian->greaterThan($toGregorian)) {
            return response()->json(['error' => 'تاریخ شروع نباید بعد از تاریخ پایان باشد.'], 400);
        }

        try {
            $user = DB::connection()->selectOne(
                'SELECT hozoor_num, work_hours, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh
                 FROM user_table
                 WHERE LTRIM(RTRIM(username)) = LTRIM(RTRIM(?))',
                [$username]
            );

            if ($user === null) {
                return response()->json(['error' => 'کاربر پیدا نشد.'], 404);
            }

            $hozoorNum = $user->hozoor_num;
            $defaultWorkHours = $user->work_hours;

            // `user_table` spells the Thursday column `chrshanbeh`; `shiftha`
            // spells it `chaharshanbeh`.  Both are kept verbatim.
            $weekdayMap = [
                0 => $user->shanbeh,
                1 => $user->yekshanbeh,
                2 => $user->doshanbeh,
                3 => $user->seshanbeh,
                4 => $user->chrshanbeh,
                5 => $user->panjshanbeh,
            ];

            $shiftRows = $this->shiftRows($username);
            $accessRows = $this->accessRows($hozoorNum, $params['start_date'], $params['end_date']);
            $sqlRows = $this->hozoorRows($username, $fromGregorian, $toGregorian);

            $attendance = $this->buildAttendance($accessRows, $sqlRows, $fromGregorian, $toGregorian);

            $result = [];

            foreach (array_keys($attendance) as $dateStr) {
                $data = $attendance[$dateStr];

                [$jalaliYear, $jalaliMonth, $jalaliDay] = array_map('intval', explode('-', $dateStr));
                $weekday = LegacyHozoorReport::weekdayOf(
                    LegacyHozoorReport::fromJalaliParts($jalaliYear, $jalaliMonth, $jalaliDay)
                );

                $workHours = str_replace(' ', '', LegacyHozoorReport::resolveWorkHours(
                    $shiftRows,
                    $weekdayMap,
                    $defaultWorkHours,
                    $jalaliYear,
                    $jalaliMonth,
                    $jalaliDay,
                    $weekday,
                ));

                [$workStart, $workEnd] = LegacyHozoorReport::workStartEnd($workHours);

                $data['WorkStart'] = $workStart;
                $data['WorkEnd'] = $workEnd;

                foreach (LegacyHozoorReport::finalizeDay($data, $weekday, $workStart, $workEnd) as $key => $value) {
                    $data[$key] = $value;
                }

                $result[] = $data;
            }

            // The Python sorts by the Jalali `Y-m-d` string, which is zero-padded
            // and therefore chronologically ordered.
            usort($result, static fn ($left, $right): int => strcmp($left['Date'], $right['Date']));

            return response()->json(['data' => $result]);
        } catch (Throwable $exception) {
            Log::error('get_hozoor failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['error' => self::SAFE_ERROR], 500);
        }
    }

    /**
     * `POST /get_hozoor_filtered` — the flat entry/exit list for a date range.
     *
     * The response is a **bare JSON array** (no envelope), and a failure is
     * `{"error": …}` with status 500 — the two shapes the filtered report
     * screen reads.
     */
    public function filtered(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent());

        if (json_last_error() !== JSON_ERROR_NONE || ! is_object($body)) {
            return response()->json(['error' => self::SAFE_ERROR], 500);
        }

        $data = get_object_vars($body);

        $username = $data['username'] ?? null;
        $fromDate = $data['from_date'] ?? null;
        $toDate = $data['to_date'] ?? null;

        if (! $username || ! $fromDate || ! $toDate) {
            return response()->json(['error' => 'اطلاعات ناقص است.'], 400);
        }

        try {
            $fromGregorian = LegacyHozoorReport::jalaliDateFromString((string) $fromDate)->toGregorian();
            $toGregorian = LegacyHozoorReport::jalaliDateFromString((string) $toDate)->toGregorian();
        } catch (Throwable) {
            return response()->json(['error' => self::SAFE_ERROR], 500);
        }

        try {
            $rows = DB::connection()->select(
                'SELECT [date], vrood, khoroj FROM hozoor
                 WHERE username = ? AND [date] BETWEEN ? AND ?
                 ORDER BY [date]',
                [$username, $fromGregorian->format('Y-m-d'), $toGregorian->format('Y-m-d')]
            );

            $results = [];

            foreach ($rows as $row) {
                $results[] = [
                    // The Python publishes `tarikh` as `strftime('%Y/%m/%d')` —
                    // slashes, unlike the merged report's `Date` key (`%Y-%m-%d`).
                    'tarikh' => LegacyDate::fromGregorian($row->date)->toJalaliString('/'),
                    'vorood' => $this->formatTimeColumn($row->vrood),
                    'khorooj' => $this->formatTimeColumn($row->khoroj),
                ];
            }

            return response()->json($results);
        } catch (Throwable $exception) {
            Log::error('get_hozoor_filtered failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['error' => self::SAFE_ERROR], 500);
        }
    }

    /**
     * `POST /sabt_hozoor` — a manual entry/exit pair for one day.
     *
     * An existing row for the same user and day is updated, otherwise a new one
     * is inserted; the two answers carry different messages.
     */
    public function store(Request $request): JsonResponse
    {
        $body = json_decode((string) $request->getContent());

        if (json_last_error() !== JSON_ERROR_NONE || ! is_object($body)) {
            return response()->json(['success' => false, 'message' => self::SAFE_ERROR], 500);
        }

        $data = get_object_vars($body);

        $username = $data['username'] ?? $data['usernamedast'] ?? null;
        $tarikhShamsi = $data['tarikh'] ?? null;
        $voroodStr = $data['vorood'] ?? null;
        $khoroojStr = $data['khorooj'] ?? null;

        if (! $username || ! $tarikhShamsi || ! $voroodStr || ! $khoroojStr) {
            return response()->json(['success' => false, 'message' => 'لطفاً تمام فیلدها را پر کنید']);
        }

        try {
            $tarikhObj = LegacyHozoorReport::jalaliDateFromString((string) $tarikhShamsi)->toGregorian()->format('Y-m-d');
            $voroodObj = $this->parseClock((string) $voroodStr);
            $khoroojObj = $this->parseClock((string) $khoroojStr);

            $exists = DB::connection()->table('hozoor')
                ->where('username', $username)
                ->where('date', $tarikhObj)
                ->exists();

            if ($exists) {
                DB::connection()->table('hozoor')
                    ->where('username', $username)
                    ->where('date', $tarikhObj)
                    ->update(['vrood' => $voroodObj, 'khoroj' => $khoroojObj]);

                $message = 'اطلاعات قبلی با موفقیت به‌روزرسانی شد';
            } else {
                DB::connection()->table('hozoor')->insert([
                    'username' => $username,
                    'date' => $tarikhObj,
                    'vrood' => $voroodObj,
                    'khoroj' => $khoroojObj,
                ]);

                $message = 'اطلاعات با موفقیت ثبت شد';
            }

            return response()->json(['success' => true, 'message' => $message]);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => self::SAFE_ERROR]);
        }
    }

    /**
     * `POST /sabt_hozoor_checkin` — stamp the server time as a user's entry.
     *
     * The time comes from the server clock, not the request.  A user may only
     * check themselves in; an administrator may check anyone in.  A second entry
     * for the same day — or an entry left open from a previous day — is a 409.
     */
    public function checkin(Request $request): JsonResponse
    {
        $actor = $this->attendanceActor($request);

        if ($actor === null) {
            return response()->json(['success' => false, 'message' => 'ورود به سامانه الزامی است.'], 401);
        }

        $body = json_decode((string) $request->getContent());

        if (json_last_error() !== JSON_ERROR_NONE || ! is_object($body)) {
            return response()->json(['success' => false, 'message' => 'درخواست نامعتبر است.'], 400);
        }

        $data = get_object_vars($body);
        $username = $data['username'] ?? null;

        if (! $username) {
            return response()->json(['success' => false, 'message' => 'کاربر مشخص نشده است.'], 400);
        }

        $username = (string) $username;

        if ($request->session()->get('is_admin') !== true && $username !== $actor) {
            return response()->json(['success' => false, 'message' => 'دسترسی ثبت حضور کاربر دیگر مجاز نیست.'], 403);
        }

        $now = CarbonImmutable::now(LegacyDate::timezone());
        $today = $now->format('Y-m-d');
        $nowTime = $now->format('H:i');

        try {
            $exists = DB::connection()->table('user_table')->where('username', $username)->exists();

            if (! $exists) {
                return response()->json(['success' => false, 'message' => 'کاربر مورد نظر یافت نشد.'], 404);
            }

            $active = DB::connection()->table('hozoor')
                ->where('username', $username)
                ->whereNotNull('vrood')
                ->whereNull('khoroj')
                ->orderByDesc('date')
                ->first(['date', 'vrood']);

            if ($active !== null && $active->date !== $today) {
                return response()->json(['success' => false, 'message' => 'ورود فعالی از روز قبل بدون ثبت خروج مانده است؛ ابتدا خروج را ثبت کنید.'], 409);
            }

            if ($active !== null) {
                return response()->json(['success' => false, 'message' => 'ورود امروز قبلاً ثبت شده است.'], 409);
            }

            $row = DB::connection()->table('hozoor')
                ->where('username', $username)
                ->where('date', $today)
                ->first(['vrood', 'khoroj']);

            if ($row !== null && $row->vrood !== null) {
                if ($row->khoroj === null) {
                    return response()->json(['success' => false, 'message' => 'ورود امروز قبلاً ثبت شده است.'], 409);
                }

                return response()->json(['success' => false, 'message' => 'ورود و خروج امروز قبلاً ثبت شده است.'], 409);
            }

            if ($row !== null) {
                DB::connection()->table('hozoor')
                    ->where('username', $username)
                    ->where('date', $today)
                    ->update(['vrood' => $nowTime, 'khoroj' => null]);
            } else {
                DB::connection()->table('hozoor')->insert([
                    'username' => $username,
                    'date' => $today,
                    'vrood' => $nowTime,
                    'khoroj' => null,
                ]);
            }

            return response()->json([
                'success' => true,
                'message' => 'ورود با موفقیت ثبت شد.',
                'data' => $this->attendancePayloadStatus($username, $nowTime, null),
            ]);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => 'خطا در ثبت اطلاعات. لطفاً دوباره تلاش کنید.'], 500);
        }
    }

    /**
     * `POST /sabt_hozoor_checkout` — stamp the server time as a user's exit.
     *
     * The exit is written against the most recent open entry, which for a night
     * shift may belong to the previous day.
     */
    public function checkout(Request $request): JsonResponse
    {
        $actor = $this->attendanceActor($request);

        if ($actor === null) {
            return response()->json(['success' => false, 'message' => 'ورود به سامانه الزامی است.'], 401);
        }

        $body = json_decode((string) $request->getContent());

        if (json_last_error() !== JSON_ERROR_NONE || ! is_object($body)) {
            return response()->json(['success' => false, 'message' => 'درخواست نامعتبر است.'], 400);
        }

        $data = get_object_vars($body);
        $username = $data['username'] ?? null;

        if (! $username) {
            return response()->json(['success' => false, 'message' => 'کاربر مشخص نشده است.'], 400);
        }

        $username = (string) $username;

        if ($request->session()->get('is_admin') !== true && $username !== $actor) {
            return response()->json(['success' => false, 'message' => 'دسترسی ثبت خروج کاربر دیگر مجاز نیست.'], 403);
        }

        $now = CarbonImmutable::now(LegacyDate::timezone());
        $nowTime = $now->format('H:i');

        try {
            $exists = DB::connection()->table('user_table')->where('username', $username)->exists();

            if (! $exists) {
                return response()->json(['success' => false, 'message' => 'کاربر مورد نظر یافت نشد.'], 404);
            }

            $active = DB::connection()->table('hozoor')
                ->where('username', $username)
                ->whereNotNull('vrood')
                ->whereNull('khoroj')
                ->orderByDesc('date')
                ->first(['date', 'vrood']);

            if ($active === null) {
                return response()->json(['success' => false, 'message' => 'کاربر هیچ ورود فعالی ندارد.'], 409);
            }

            $checkIn = LegacyAttendanceStatus::formatTimeValue($active->vrood) ?? '';

            DB::connection()->table('hozoor')
                ->where('username', $username)
                ->where('date', $active->date)
                ->update(['khoroj' => $nowTime]);

            return response()->json([
                'success' => true,
                'message' => 'خروج با موفقیت ثبت شد.',
                'data' => $this->attendancePayloadStatus($username, $checkIn, $nowTime),
            ]);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => 'خطا در ثبت اطلاعات. لطفاً دوباره تلاش کنید.'], 500);
        }
    }

    /**
     * `GET /get_hozoor_today` — today's presence for everyone, or for the caller.
     *
     * An administrator gets every user with a `hozoor` row dated exactly today;
     * an ordinary user gets only their own.  A user with no row is
     * `not_checked_in`, and an ordinary user with no row at all is still listed
     * once, as themselves.
     */
    public function today(Request $request): JsonResponse
    {
        $actor = $this->attendanceActor($request);

        if ($actor === null) {
            return response()->json(['success' => false, 'message' => 'ورود به سامانه الزامی است.'], 401);
        }

        $isAdmin = $request->session()->get('is_admin') === true;
        $today = CarbonImmutable::now(LegacyDate::timezone())->format('Y-m-d');

        try {
            if ($isAdmin) {
                $rows = DB::connection()->select(
                    'SELECT u.username, h.[date], h.vrood, h.khoroj
                     FROM user_table u
                     LEFT JOIN hozoor h ON u.username = h.username AND h.[date] = ?',
                    [$today]
                );
            } else {
                $rows = DB::connection()->select(
                    'SELECT TOP 1 username, [date], vrood, khoroj
                     FROM hozoor
                     WHERE username = ? AND [date] = ?
                     ORDER BY [date] DESC',
                    [$actor, $today]
                );
            }

            $byUser = [];

            foreach ($rows as $row) {
                $entry = $byUser[$row->username] ?? ['active' => null, 'today' => null];

                if ($row->vrood !== null && $row->khoroj === null) {
                    $entry['active'] = [$row->date, $row->vrood];
                }

                $recordDate = $row->date instanceof \DateTimeInterface
                    ? $row->date->format('Y-m-d')
                    : $row->date;

                if ($recordDate !== null && $recordDate === $today) {
                    $entry['today'] = [$row->vrood, $row->khoroj];
                }

                $byUser[$row->username] = $entry;
            }

            $users = [];

            foreach ($byUser as $username => $entry) {
                if ($entry['active'] !== null) {
                    $users[] = [
                        'username' => $username,
                        'status' => 'checked_in',
                        'check_in' => LegacyAttendanceStatus::formatTimeValue($entry['active'][1]),
                        'check_out' => null,
                    ];
                } elseif ($entry['today'] !== null && $entry['today'][0] !== null && $entry['today'][1] !== null) {
                    $users[] = [
                        'username' => $username,
                        'status' => 'checked_out',
                        'check_in' => LegacyAttendanceStatus::formatTimeValue($entry['today'][0]),
                        'check_out' => LegacyAttendanceStatus::formatTimeValue($entry['today'][1]),
                    ];
                } else {
                    $users[] = [
                        'username' => $username,
                        'status' => 'not_checked_in',
                        'check_in' => null,
                        'check_out' => null,
                    ];
                }
            }

            if (! $isAdmin && $users === []) {
                $users[] = [
                    'username' => $actor,
                    'status' => 'not_checked_in',
                    'check_in' => null,
                    'check_out' => null,
                ];
            }

            $schedule = ['work_start' => null, 'work_end' => null];

            if (! $isAdmin) {
                $scheduleRow = DB::connection()->selectOne(
                    'SELECT work_hours, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh
                     FROM user_table
                     WHERE username = ?',
                    [$actor]
                );

                if ($scheduleRow !== null) {
                    $defaultHours = $scheduleRow->work_hours;
                    $dayHours = [
                        0 => $scheduleRow->shanbeh,
                        1 => $scheduleRow->yekshanbeh,
                        2 => $scheduleRow->doshanbeh,
                        3 => $scheduleRow->seshanbeh,
                        4 => $scheduleRow->chrshanbeh,
                        5 => $scheduleRow->panjshanbeh,
                    ];

                    $todayJalali = LegacyDate::today();
                    $weekday = LegacyHozoorReport::weekdayOf($todayJalali);
                    $hours = $dayHours[$weekday] ?? null;
                    $hours = $hours ?: $defaultHours;

                    if ($hours && str_contains((string) $hours, '-')) {
                        [$workStart, $workEnd] = array_map('trim', explode('-', (string) $hours, 2));
                        $schedule['work_start'] = $workStart;
                        $schedule['work_end'] = $workEnd;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'server_now' => CarbonImmutable::now(LegacyDate::timezone())->format('H:i'),
                    'users' => $users,
                    'work_start' => $schedule['work_start'],
                    'work_end' => $schedule['work_end'],
                ],
            ]);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => 'خطا در دریافت اطلاعات حضور و غیاب.'], 500);
        }
    }

    // ── Shared helpers ─────────────────────────────────────────────────────

    /**
     * `_attendance_actor()` — the signed-in username, or `null`.
     */
    private function attendanceActor(Request $request): ?string
    {
        $username = $request->session()->get('username');

        return $username ? (string) $username : null;
    }

    /**
     * `_attendance_payload_status()` — the shared check-in/check-out response data.
     *
     * @return array{username: string, status: string, check_in: string|null, check_out: string|null, server_now: string}
     */
    private function attendancePayloadStatus(string $username, ?string $checkIn, ?string $checkOut): array
    {
        $status = LegacyAttendanceStatus::computeAttendanceStatus($checkIn, $checkOut);

        return [
            'username' => $username,
            'status' => $status['status'],
            'check_in' => $status['check_in'],
            'check_out' => $status['check_out'],
            'server_now' => CarbonImmutable::now(LegacyDate::timezone())->format('H:i'),
        ];
    }

    /**
     * The user's `shiftha` rows, or `[]` when the read fails.
     *
     * The Python logged the failure and continued with an empty list, so a
     * damaged shift table degrades the report to the weekly defaults rather than
     * erroring.
     *
     * @return array<int, array<string, mixed>>
     */
    private function shiftRows(string $username): array
    {
        try {
            $rows = DB::connection()->select(
                'SELECT jalali_year, jalali_month, start_day, end_day,
                        shanbeh, yekshanbeh, doshanbeh, seshanbeh, chaharshanbeh, panjshanbeh, jomeh
                 FROM shiftha
                 WHERE '.PersianText::normalizedColumnExpression('username').' = ?
                 ORDER BY jalali_year, jalali_month, start_day',
                [LegacyPythonScalar::normalizeFaUsername($username)]
            );

            return array_map(static fn ($row): array => (array) $row, $rows);
        } catch (Throwable $exception) {
            Log::warning('get_hozoor shiftha fallback', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * The Araz Access punches for a card number, or `[]` when Access is unavailable.
     *
     * The Python bounded the query by the **Jalali** date strings the caller
     * passed (`start_date` / `end_date`), not the Gregorian conversions — a
     * divergence that is reproduced, because the running server's answer
     * depends on it.  The connection is attempted through the Access ODBC
     * driver; any failure (no password, no driver, no file) falls back to an
     * empty list, exactly as the Python's `try/except` did.
     *
     * @return array<int, array{CardNo: mixed, Date: mixed, Time: mixed, InOutType: mixed}>
     */
    private function accessRows(mixed $hozoorNum, string $startDate, string $endDate): array
    {
        $password = (string) env('ARAZ_ACCESS_PASSWORD', '');

        if ($password === '') {
            return [];
        }

        $path = (string) env('ARAZ_ACCESS_PATH', 'E:\Hastama\database\Arazdb.mdb');

        try {
            $connection = new PDO(
                'odbc:DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};DBQ='.$path.';PWD='.$password.';',
                '',
                '',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            $statement = $connection->prepare(
                'SELECT CardNo, Date, Time, InOutType FROM TPrsInOut WHERE CardNo = ? AND Date BETWEEN ? AND ?'
            );
            $statement->execute([$hozoorNum, $startDate, $endDate]);

            $rows = [];

            foreach ($statement as $row) {
                $rows[] = [
                    'CardNo' => $row['CardNo'],
                    'Date' => $row['Date'],
                    'Time' => $row['Time'],
                    'InOutType' => $row['InOutType'],
                ];
            }

            return $rows;
        } catch (Throwable $exception) {
            Log::warning('get_hozoor Access fallback', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * The `hozoor` table rows for the range, or `[]` when the read fails.
     *
     * @return array<int, array{date: mixed, vrood: mixed, khoroj: mixed}>
     */
    private function hozoorRows(string $username, CarbonImmutable $fromGregorian, CarbonImmutable $toGregorian): array
    {
        try {
            $rows = DB::connection()->select(
                'SELECT [date], vrood, khoroj FROM hozoor
                 WHERE LTRIM(RTRIM(username)) = LTRIM(RTRIM(?)) AND [date] BETWEEN ? AND ?
                 ORDER BY [date]',
                [$username, $fromGregorian->format('Y-m-d'), $toGregorian->format('Y-m-d')]
            );

            return array_map(static fn ($row): array => (array) $row, $rows);
        } catch (Throwable $exception) {
            Log::warning('get_hozoor hozoor table fallback', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Merge the Access punches, the `hozoor` rows and the fill-in days.
     *
     * The key is the Jalali `Y-m-d` string, and the first source to claim a date
     * keeps it.  The three branches produce **different key sets** — the Access
     * rows carry a `Times` list, the `hozoor` rows carry neither `Times` nor the
     * `*2` fields, and the fill-in rows carry the `*2` fields but no `Times` —
     * and the report publishes exactly those keys.
     *
     * @param  array<int, array{CardNo: mixed, Date: mixed, Time: mixed, InOutType: mixed}>  $accessRows
     * @param  array<int, array{date: mixed, vrood: mixed, khoroj: mixed}>  $sqlRows
     * @return array<string, array<string, mixed>>
     */
    private function buildAttendance(array $accessRows, array $sqlRows, CarbonImmutable $fromGregorian, CarbonImmutable $toGregorian): array
    {
        $attendance = [];

        foreach ($accessRows as $row) {
            $dateStr = str_replace('/', '-', (string) $row['Date']);
            $timeValue = LegacyHozoorReport::normalizeTimeValue($row['Time']);

            if (! isset($attendance[$dateStr])) {
                $attendance[$dateStr] = ['CardNo' => $row['CardNo'], 'Date' => $dateStr, 'Times' => []];
            }

            $attendance[$dateStr]['Times'][] = $timeValue;
        }

        foreach ($attendance as $dateStr => $data) {
            $times = $data['Times'];
            sort($times, SORT_STRING);

            $data['EntryTime'] = $times[0] ?? '0000';
            $data['ExitTime'] = $times[1] ?? '0000';
            $data['EntryTime2'] = $times[2] ?? '0000';
            $data['ExitTime2'] = $times[3] ?? '0000';

            $attendance[$dateStr] = $data;
        }

        foreach ($sqlRows as $row) {
            $shamsi = LegacyHozoorReport::jalaliDateString($row['date']);

            if (isset($attendance[$shamsi])) {
                continue;
            }

            $attendance[$shamsi] = [
                'CardNo' => 'DB',
                'Date' => $shamsi,
                'EntryTime' => LegacyHozoorReport::normalizeTimeValue($row['vrood']),
                'ExitTime' => LegacyHozoorReport::normalizeTimeValue($row['khoroj']),
            ];
        }

        $totalDays = (int) $fromGregorian->diffInDays($toGregorian);

        for ($i = 0; $i <= $totalDays; $i++) {
            $gregorian = $fromGregorian->addDays($i);
            $shamsi = LegacyHozoorReport::jalaliDateString($gregorian);

            if (! isset($attendance[$shamsi])) {
                $attendance[$shamsi] = [
                    'CardNo' => '',
                    'Date' => $shamsi,
                    'EntryTime' => '0000',
                    'ExitTime' => '0000',
                    'EntryTime2' => '0000',
                    'ExitTime2' => '0000',
                ];
            }
        }

        return $attendance;
    }

    /**
     * A `time` column value as `HH:MM`, matching the Python's
     * `row[1].strftime('%H:%M') if isinstance(row[1], time) else str(row[1])`.
     *
     * A `NULL` becomes the string `"None"` — the Python's `str(None)` — which is
     * a real quirk of the running server, reproduced.
     */
    private function formatTimeColumn(mixed $value): string
    {
        if ($value === null) {
            return 'None';
        }

        $text = (string) $value;

        if (preg_match('/^(\d{1,2}):(\d{2})/', $text, $matches) === 1) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        return $text;
    }

    /**
     * `datetime.strptime(value, "%H:%M")` as a `H:i` string.
     *
     * @throws RuntimeException when the value is not `HH:MM`.
     */
    private function parseClock(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches) !== 1) {
            throw new RuntimeException('time data does not match format');
        }

        return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
    }
}
