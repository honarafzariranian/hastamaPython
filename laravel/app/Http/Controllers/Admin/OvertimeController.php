<?php

namespace App\Http\Controllers\Admin;

use App\Support\Legacy\LegacyDate;
use App\Support\Legacy\LegacyHozoorReport;
use App\Support\Legacy\LegacyStatusUpdatePayload;
use App\Support\Legacy\LegacyValidationException;
use App\Support\Legacy\PersianText;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;
use ValueError;

/**
 * The overtime approvals — `GET /get_overtime_requests`,
 * `POST /update_overtime_status`, `POST /update_overtime_Indivisual_status`,
 * `GET /overtime_report` and `POST /get_overtime_report` from `app/main.py`.
 *
 * ### Validation order
 *
 * FastAPI validates a handler's declared body before the handler runs, so the
 * two pydantic-model routes and the `data: dict` route carry only
 * `legacy.session:optional` and validate in the handler, before `_require_admin`:
 *
 * * `update_overtime_status` — `OvertimeUpdateRequest(requestId: int, status: str)`;
 * * `update_overtime_Indivisual_status` — `OvertimeStatusUpdate(id: int, status: str)`;
 * * `get_overtime_report` — `data: dict`, so a JSON array is a 422 `dict_type`.
 *
 * The other two guard with `_require_admin` as their first action and carry the
 * `admin` middleware.
 *
 * ### A real bug, reproduced
 *
 * `GET /overtime_report` renders `overtime_report.html`, which **does not exist
 * in this installation** (it is not in `app/templates` and not in
 * `docs/migration/TEMPLATE_INVENTORY.md`).  The first `TemplateResponse` raises
 * inside the `try`; the `except` retries the same missing template; the second
 * failure escapes, so the route answers **500** — for a database failure and for
 * a success alike, because the render is reached either way.  The port
 * reproduces the 500 by rendering the missing view.
 */
final class OvertimeController extends AdminPanelController
{
    /**
     * `GET /get_overtime_requests` — every overtime request.
     *
     * A **bare array**; an empty table is `[]`.  A missing date renders as
     * `تاریخ ناموجود` and a missing duration as `00:00`; a missing status
     * becomes `انتظار تایید`.  A failure is a **200** carrying
     * `{"error": …, "message": …}` — the handler's `JSONResponse` had no
     * `status_code`, so a database error answered success.
     */
    public function requests(): JsonResponse
    {
        try {
            $rows = DB::connection()->select(
                'SELECT id, overtime_date, daily_overtime, description, username, status FROM ezafe_table'
            );

            $requestsData = [];

            foreach ($rows as $row) {
                $requestsData[] = [
                    'id' => $row->id,
                    'overtime_date' => $this->overtimeDateShamsi($row->overtime_date),
                    'daily_overtime' => $this->overtimeDuration($row->daily_overtime),
                    'description' => $row->description,
                    'username' => $row->username,
                    'status' => $row->status ?: 'انتظار تایید',
                ];
            }

            return response()->json($requestsData);
        } catch (Throwable) {
            return response()->json([
                'error' => 'خطا در دریافت داده‌ها',
                'message' => 'خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید.',
            ]);
        }
    }

    /**
     * `POST /update_overtime_status` — move one overtime request to a new status.
     *
     * The body is validated by `OvertimeUpdateRequest` before the guard (see the
     * class docblock).  A row that is updated notifies the requester; a row that
     * matches nothing is a 404.
     */
    public function updateStatus(Request $request): JsonResponse
    {
        $payload = LegacyStatusUpdatePayload::overtimeRequest((string) $request->getContent());

        $authError = $this->requireAdmin($request);

        if ($authError !== null) {
            return $authError;
        }

        try {
            $updated = DB::connection()->update(
                'UPDATE ezafe_table SET status = ? WHERE id = ?',
                [$payload['status'], $payload['requestId']]
            );

            if ($updated) {
                $this->notifyRequesterStatus('ezafe_table', $payload['requestId'], $payload['status'], 'اضافه‌کاری');
            }

            if ($updated === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'هیچ رکوردی برای بروزرسانی پیدا نشد',
                ], 404);
            }

            return response()->json(['success' => true, 'message' => 'وضعیت با موفقیت تغییر کرد!']);
        } catch (Throwable) {
            return response()->json(['success' => false, 'message' => 'خطا در بروزرسانی وضعیت'], 500);
        }
    }

    /**
     * `POST /update_overtime_Indivisual_status` — the second overtime status write.
     *
     * The same shape as {@see updateStatus} with the `id` field, a different 404
     * message, no message on success, and a different 500 body.
     */
    public function updateIndividualStatus(Request $request): JsonResponse
    {
        $payload = LegacyStatusUpdatePayload::overtimeIndividual((string) $request->getContent());

        $authError = $this->requireAdmin($request);

        if ($authError !== null) {
            return $authError;
        }

        try {
            $updated = DB::connection()->update(
                'UPDATE ezafe_table SET status = ? WHERE id = ?',
                [$payload['status'], $payload['id']]
            );

            if ($updated) {
                $this->notifyRequesterStatus('ezafe_table', $payload['id'], $payload['status'], 'اضافه‌کاری');
            }

            if ($updated === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'رکوردی برای به‌روزرسانی پیدا نشد.',
                ], 404);
            }

            return response()->json(['success' => true]);
        } catch (Throwable) {
            return response()->json(['success' => false, 'message' => 'خطا در به‌روزرسانی وضعیت.'], 500);
        }
    }

    /**
     * `GET /overtime_report` — the org-wide overtime totals.
     *
     * The template `overtime_report.html` is missing from this installation, so
     * the Python answers 500 here (see the class docblock).  The query still
     * runs first, exactly as the Python's did.
     */
    public function report()
    {
        $rows = DB::connection()->select('SELECT username, total_ezafe_time FROM ezafe_total_table');

        $reports = [];

        foreach ($rows as $index => $row) {
            $reports[] = [
                'username' => $row->username,
                'total_ezafe_time' => $row->total_ezafe_time,
                'row_number' => $index + 1,
            ];
        }

        // Rendering the missing view raises, which is the 500 the Python answers.
        return view('overtime_report', ['reports' => $reports]);
    }

    /**
     * `POST /get_overtime_report` — every overtime row in a Jalali range.
     *
     * `username: "all_users"` covers everyone; any other value (including a
     * missing one) filters to that username.  The dates are parsed with
     * `JalaliDate(*map(int, ….split('/')))`, so Persian digits are folded first
     * and a non-padded or malformed date is refused.  The dates and times are
     * published with **Persian digits** (`convert_to_persian_numbers`).
     *
     * A `ValueError` or `TypeError` from the date parse is a 400; a database
     * failure is a framework 500.
     */
    public function reportData(Request $request): JsonResponse
    {
        $data = $this->parseDictBody($request);

        $authError = $this->requireAdmin($request);

        if ($authError !== null) {
            return $authError;
        }

        $username = $data->username ?? null;

        try {
            $startDate = $this->jalaliReportDate($data->start_date ?? '');
            $endDate = $this->jalaliReportDate($data->end_date ?? '');

            if ($username === 'all_users') {
                $rows = DB::connection()->select(
                    'SELECT id, overtime_date, description, status, username, daily_overtime, from_time, to_time
                     FROM ezafe_table
                     WHERE overtime_date BETWEEN ? AND ?',
                    [$startDate, $endDate]
                );
            } else {
                $rows = DB::connection()->select(
                    'SELECT id, overtime_date, description, status, username, daily_overtime, from_time, to_time
                     FROM ezafe_table
                     WHERE username = ? AND overtime_date BETWEEN ? AND ?',
                    [$username, $startDate, $endDate]
                );
            }

            $result = [];

            foreach ($rows as $row) {
                $dailyOvertime = $this->timeToHi($row->daily_overtime);
                $fromTime = $this->timeToHi($row->from_time);
                $toTime = $this->timeToHi($row->to_time);

                $result[] = [
                    'id' => $row->id,
                    'overtime_date' => PersianText::toPersianDigits($this->jalaliSlashes($row->overtime_date)),
                    'description' => $row->description,
                    'status' => $row->status,
                    'username' => $row->username,
                    'daily_overtime' => $dailyOvertime === null ? null : PersianText::toPersianDigits($dailyOvertime),
                    'from_time' => $fromTime === null ? null : PersianText::toPersianDigits($fromTime),
                    'to_time' => $toTime === null ? null : PersianText::toPersianDigits($toTime),
                ];
            }

            return response()->json($result);
        } catch (ValueError|\TypeError) {
            return response()->json([
                'error' => 'تاریخ وارد شده صحیح نیست. لطفاً فرمت صحیح را وارد کنید.',
            ], 400);
        } catch (Throwable) {
            throw new \RuntimeException('The overtime report query failed.');
        }
    }

    /**
     * `data: dict` — the request body must be a JSON object, validated the way
     * FastAPI validates a bare `dict` parameter before the handler runs.
     *
     * * an empty body, or the literal `null`, is `missing` against `["body"]`;
     * * a JSON array or scalar is `dict_type` — "Input should be a valid
     * *   dictionary" — which is **not** the `model_attributes_type` a pydantic
     *   model answers;
     * * unparseable JSON is `json_invalid`.
     *
     * @throws LegacyValidationException
     */
    private function parseDictBody(Request $request): object
    {
        $decoded = json_decode((string) $request->getContent());

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new LegacyValidationException([[
                'type' => 'json_invalid',
                'loc' => ['body', 0],
                'msg' => 'JSON decode error',
                'input' => new stdClass,
                'ctx' => ['error' => json_last_error_msg()],
            ]]);
        }

        // The literal `null` is "no body" to FastAPI — the same `missing` as an
        // empty request.
        if ($decoded === null) {
            throw new LegacyValidationException([[
                'type' => 'missing',
                'loc' => ['body'],
                'msg' => 'Field required',
                'input' => null,
            ]]);
        }

        if (! is_object($decoded)) {
            throw new LegacyValidationException([[
                'type' => 'dict_type',
                'loc' => ['body'],
                'msg' => 'Input should be a valid dictionary',
                'input' => $decoded,
            ]]);
        }

        return $decoded;
    }

    /**
     * `JalaliDate(*map(int, convert_farsi_to_english(str(value)).split('/')))`
     * for the report endpoint.
     *
     * Persian digits are folded first; the value is then split on `/` and each
     * part is `int()`-ed.  The order is the Python's and it is observable:
     * `map(int, …)` runs before the `JalaliDate` call, so a part that is not an
     * integer is a `ValueError` even when the number of parts is also wrong.
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
     * `JalaliDate(row.overtime_date).strftime('%Y/%m/%d')`.
     *
     * `pyodbc` handed the running server a `date` object, which `JalaliDate`
     * accepts; `pdo_sqlsrv` hands back a string, which it does not, so the
     * string is parsed to reproduce the conversion.  A `NULL` raised `TypeError`
     * in the Python — a framework 500 — and does the same here.
     */
    private function jalaliSlashes(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw new \Error('The date must be a date.');
        }

        return LegacyDate::fromGregorian(
            $value instanceof \DateTimeInterface ? $value : (string) $value
        )->format('%Y/%m/%d');
    }

    /**
     * `isinstance(overtime_date, (datetime, date))` → Jalali, else the
     * placeholder — the list endpoint's branch.
     *
     * `pyodbc` handed back a `date` object or `None`; `pdo_sqlsrv` hands back a
     * string, which is parsed to reproduce the conversion the running server
     * performed.
     */
    private function overtimeDateShamsi(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return LegacyDate::fromGregorian($value)->format('%Y/%m/%d');
        }

        if (is_string($value) && trim($value) !== '') {
            try {
                return LegacyDate::fromGregorian(trim($value))->format('%Y/%m/%d');
            } catch (\InvalidArgumentException) {
                return 'تاریخ ناموجود';
            }
        }

        return 'تاریخ ناموجود';
    }

    /**
     * `isinstance(daily_overtime, (time, datetime))` → `%H:%M`, else `00:00` —
     * the list endpoint's branch.
     */
    private function overtimeDuration(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('H:i');
        }

        $hi = $this->timeToHi($value);

        return $hi ?? '00:00';
    }

    /**
     * `time.strftime('%H:%M')` — a `time` as `HH:MM`, or `null` for a NULL.
     *
     * `pyodbc` handed the running server a `time` object; `pdo_sqlsrv` hands
     * back the `HH:MM:SS` string, which is re-formatted to the same `HH:MM`.
     */
    private function timeToHi(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = is_string($value) ? trim($value) : '';

        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $raw, $matches) === 1) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        return null;
    }
}
