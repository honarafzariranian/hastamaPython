<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyBody;
use App\Support\Legacy\LegacyHozoorReport;
use App\Support\Legacy\LegacyMarkup;
use App\Support\Legacy\LegacyNotificationPublisher;
use App\Support\Legacy\LegacyPythonScalar;
use App\Support\Legacy\LegacyQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use ValueError;

/**
 * The employee request writes — `POST /submit_leave`, `POST /submit_overtime`
 * and `POST /submit_hourly_pass` from `app/main.py`.
 *
 * All three share a shape, and the shape is the contract:
 *
 * * **Auth** is the session username, untrimmed, and a missing one answers
 *   `{"success": false, "message": "User not logged in"}` with status **200** —
 *   not a 401, and not the `error` key.  The front-end branches on `success`.
 * * **Form validation** is FastAPI's `Form(...)`, declared through
 *   {@see LegacyBody} so a missing field is the 422 `detail` list rather than
 *   Laravel's redirect.
 * * **Markup** is rejected on the free-text fields via {@see LegacyMarkup},
 *   before the generic handler, so the operator sees the real reason.
 * * **Success** is `{"success": true, "message": …}`; **failure** is
 *   `{"success": false, "message": …}` — never an envelope, never `error`.
 *
 * Two behaviours are easy to "fix" into bugs and are preserved:
 *
 * * `submit_leave` converts the dates in an `except ValueError` block, so a
 *   date with the *wrong number of parts* raises `TypeError` (not `ValueError`)
 *   and answers **500**, while a non-integer part answers the 200
 *   `تاریخ یا تعداد روزها معتبر نیست.`.  An empty date leaves the Gregorian
 *   variable undefined, which the inner `except Exception` turns into the safe
 *   error message — so an empty `startDate` is a 200, not a 422.
 * * `submit_hourly_pass` reads `request.json()` **outside** its try, so an
 *   unparseable body is a framework 500, while a body that is valid JSON but not
 *   an object is a 200 safe-error (`data.get` raises `AttributeError`, caught by
 *   `except Exception`).  An empty JSON object `{}` is a **success** with no
 *   inserts at all.
 */
final class LeaveWriteController extends Controller
{
    /** `_safe_error_message()` from `app/main.py`. */
    private const SAFE_ERROR = 'خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید.';

    /** The request-kind → notification-label map from `_notify_admins_new_request`. */
    private const NOTIFICATION_LABELS = [
        'leave' => 'مرخصی',
        'overtime' => 'اضافه‌کاری',
        'hourly_pass' => 'پاس ساعتی',
    ];

    /**
     * `POST /submit_leave` — a leave request for the signed-in user.
     *
     * The dates are Jalali (`YYYY/MM/DD`) and are stored as Gregorian `date`
     * columns; `days` may carry Persian digits.  The substitute name is shown in
     * the leave reports, so markup is refused at the source.
     */
    public function submitLeave(Request $request): JsonResponse
    {
        $username = $request->session()->get('username');

        if (! $username) {
            return response()->json(['success' => false, 'message' => 'User not logged in']);
        }

        $params = LegacyBody::validate($request->request->all(), [
            'startDate' => LegacyQuery::requiredString(),
            'endDate' => LegacyQuery::requiredString(),
            'days' => LegacyQuery::requiredString(),
            'substitute' => LegacyQuery::requiredString(),
        ]);

        try {
            $substitute = LegacyMarkup::rejectMarkup($params['substitute'], 100, 'جانشین');
        } catch (ValueError $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()]);
        }

        $startDateGregorian = null;
        $endDateGregorian = null;

        try {
            if ($params['startDate'] !== '') {
                $startDateGregorian = LegacyHozoorReport::jalaliDateFromString($params['startDate'])->toGregorian()->format('Y-m-d');
            }

            if ($params['endDate'] !== '') {
                $endDateGregorian = LegacyHozoorReport::jalaliDateFromString($params['endDate'])->toGregorian()->format('Y-m-d');
            }

            $daysInt = LegacyPythonScalar::pyInt(LegacyPythonScalar::persianToEnglishDigits($params['days']));
        } catch (ValueError) {
            return response()->json(['success' => false, 'message' => 'تاریخ یا تعداد روزها معتبر نیست.']);
        }

        // A `TypeError` from the date conversion (wrong number of parts) is not a
        // `ValueError`, so the Python let it escape to a 500.  It is thrown before
        // this point and is deliberately not caught here.

        try {
            // The Python's empty-date path: the Gregorian variable is never
            // assigned, and the inner `except Exception` answers the safe message.
            if ($startDateGregorian === null || $endDateGregorian === null) {
                throw new \RuntimeException('start_date_gregorian');
            }

            DB::transaction(function () use ($startDateGregorian, $endDateGregorian, $daysInt, $substitute, $username): void {
                DB::connection()->table('mrkhc_table')->insert([
                    'start_date' => $startDateGregorian,
                    'end_date' => $endDateGregorian,
                    'days' => $daysInt,
                    'substitute' => $substitute,
                    'username' => $username,
                ]);

                $this->notifyAdmins('leave', $username, "تعداد روز: {$daysInt}");
            });

            return response()->json(['success' => true, 'message' => 'مرخصی با موفقیت ثبت شد!']);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => self::SAFE_ERROR]);
        }
    }

    /**
     * `POST /submit_overtime` — an overtime request for the signed-in user.
     *
     * The three free-text fields are all required (an empty one is a failure),
     * and the date has its own `فرمت تاریخ نامعتبر است` message for a part count
     * that is not three — distinct from the safe error a bad value produces.
     */
    public function submitOvertime(Request $request): JsonResponse
    {
        $username = $request->session()->get('username');

        if (! $username) {
            return response()->json(['success' => false, 'message' => 'User not logged in']);
        }

        $params = LegacyBody::validate($request->request->all(), [
            'overtimeDate' => LegacyQuery::requiredString(),
            'fromTime' => LegacyQuery::requiredString(),
            'toTime' => LegacyQuery::requiredString(),
            'description' => LegacyQuery::requiredString(),
        ]);

        try {
            $description = LegacyMarkup::rejectMarkup($params['description'], 500, 'شرح اضافه‌کاری', required: true);
            $fromTime = LegacyMarkup::rejectMarkup($params['fromTime'], 20, 'ساعت شروع', required: true);
            $toTime = LegacyMarkup::rejectMarkup($params['toTime'], 20, 'ساعت پایان', required: true);
        } catch (ValueError $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()]);
        }

        $shamsiParts = explode('/', $params['overtimeDate']);

        if (count($shamsiParts) !== 3) {
            return response()->json(['success' => false, 'message' => 'فرمت تاریخ نامعتبر است']);
        }

        try {
            $gregorianDate = LegacyHozoorReport::jalaliDateFromString($params['overtimeDate'])->toGregorian()->format('Y-m-d');

            DB::transaction(function () use ($gregorianDate, $fromTime, $toTime, $description, $username): void {
                DB::connection()->table('ezafe_table')->insert([
                    'overtime_date' => $gregorianDate,
                    'from_time' => $fromTime,
                    'to_time' => $toTime,
                    'description' => $description,
                    'username' => $username,
                    'status' => 'انتظار تایید',
                ]);

                $this->notifyAdmins('overtime', $username, "شرح: {$description}");
            });

            return response()->json(['success' => true, 'message' => 'اضافه‌کار با موفقیت ثبت شد!']);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => self::SAFE_ERROR]);
        }
    }

    /**
     * `POST /submit_hourly_pass` — up to three hourly-pass rows for the signed-in user.
     *
     * The body is a JSON object with any subset of `officialTime`, `entryTime`,
     * `exitTime` and a Jalali `date`.  Each pair that is present produces one row
     * in its own table (`avalpss` / `beynpss` / `akhrpss`) **and** one
     * `totalpass_table` row — the approval queue the admin panel reads.  The pass
     * duration is computed server-side and always ends in `:00`.
     */
    public function submitHourlyPass(Request $request): JsonResponse
    {
        $username = $request->session()->get('username');

        if (! $username) {
            return response()->json(['success' => false, 'message' => 'User not logged in']);
        }

        // `await request.json()` is outside the Python's try: an unparseable body
        // is a framework 500, not the safe-error a processing failure produces.
        $body = json_decode((string) $request->getContent());

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('The request body is not valid JSON.');
        }

        // Valid JSON that is not an object: `data.get` raises `AttributeError`,
        // which the `except Exception` turns into the safe error message.
        $data = is_object($body) ? get_object_vars($body) : null;

        if (! is_array($data)) {
            return response()->json(['success' => false, 'message' => self::SAFE_ERROR]);
        }

        $officialTime = $data['officialTime'] ?? null;
        $entryTime = $data['entryTime'] ?? null;
        $exitTime = $data['exitTime'] ?? null;
        $date = $data['date'] ?? null;

        try {
            if ($date) {
                $date = LegacyHozoorReport::jalaliDateFromString((string) $date)->toGregorian()->format('Y-m-d');
            }

            if ($officialTime) {
                $officialTime = LegacyPythonScalar::persianToEnglishDigits((string) $officialTime);
            }

            if ($entryTime) {
                $entryTime = LegacyPythonScalar::persianToEnglishDigits((string) $entryTime);
            }

            if ($exitTime) {
                $exitTime = LegacyPythonScalar::persianToEnglishDigits((string) $exitTime);
            }

            $passRequests = [];

            if ($officialTime && $entryTime) {
                DB::connection()->table('avalpss_table')->insert([
                    'officialTime' => $officialTime,
                    'entryTime' => $entryTime,
                    'date' => $date,
                    'username' => $username,
                ]);
                $passRequests[] = ['avalpss', self::passDuration($officialTime, $entryTime)];
            }

            if ($entryTime && $exitTime) {
                DB::connection()->table('beynpss_table')->insert([
                    'entryTime' => $entryTime,
                    'exitTime' => $exitTime,
                    'date' => $date,
                    'username' => $username,
                ]);
                $passRequests[] = ['beynpss', self::passDuration($entryTime, $exitTime)];
            }

            if ($officialTime && $exitTime) {
                DB::connection()->table('akhrpss_table')->insert([
                    'officialTime' => $officialTime,
                    'exitTime' => $exitTime,
                    'date' => $date,
                    'username' => $username,
                ]);
                $passRequests[] = ['akhrpss', self::passDuration($officialTime, $exitTime)];
            }

            foreach ($passRequests as [$passTitle, $passDuration]) {
                DB::connection()->table('totalpass_table')->insert([
                    'username' => $username,
                    'request_date' => $date,
                    'pass_title' => $passTitle,
                    'pass_duration' => $passDuration,
                    'status' => 'انتظار تایید',
                ]);
            }

            if ($passRequests !== []) {
                $titles = implode('، ', array_column($passRequests, 0));
                $this->notifyAdmins('hourly_pass', $username, 'نوع پاس: '.$titles);
            }

            return response()->json(['success' => true]);
        } catch (Throwable $exception) {
            return response()->json(['success' => false, 'message' => self::SAFE_ERROR]);
        }
    }

    /**
     * `_notify_admins_new_request()` — publish a notification to every admin.
     */
    private function notifyAdmins(string $kind, string $username, string $details): void
    {
        $label = self::NOTIFICATION_LABELS[$kind] ?? 'جدید';

        LegacyNotificationPublisher::publishToAdmins(
            "درخواست جدید {$label}",
            trim('کاربر «'.trim($username)."» یک درخواست {$label} ثبت کرد. {$details}"),
            'information',
            'important',
            '/admin',
        );
    }

    /**
     * `_pass_duration()` — the duration between two `HH:MM` values as `HH:MM:00`.
     *
     * An end before a start is read as crossing midnight.  A value that is not
     * `HH:MM` raises, which the caller turns into the safe error message.
     *
     * @throws ValueError when either value is not `HH:MM`.
     */
    private static function passDuration(string $startValue, string $endValue): string
    {
        $start = self::parseClock($startValue);
        $end = self::parseClock($endValue);

        if ($end < $start) {
            $end += 24 * 3600;
        }

        $seconds = $end - $start;

        return sprintf('%02d:%02d:00', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    /**
     * `datetime.strptime(value, "%H:%M")` as seconds since midnight.
     *
     * @throws ValueError when the value is not `HH:MM`.
     */
    private static function parseClock(string $value): int
    {
        $value = trim($value);

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches) !== 1) {
            throw new ValueError('time data does not match format');
        }

        return ((int) $matches[1]) * 3600 + ((int) $matches[2]) * 60;
    }
}
