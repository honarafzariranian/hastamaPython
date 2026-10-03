<?php

namespace App\Http\Controllers\Admin;

use App\Support\Legacy\LegacyPythonScalar;
use App\Support\Legacy\LegacyQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;
use ValueError;

/**
 * The administrator payroll calculations — `POST /api/admin/payroll/save` and
 * `GET /api/admin/payroll/load` from `app/main.py`.
 *
 * Both routes guard with `get_is_admin_from_session()` — `is_admin is True`
 * only, answering 403 `دسترسی مجاز نیست.` — which is **not** the
 * `_require_admin` guard the `admin` middleware reproduces (that one also
 * checks the username and answers `دسترسی مدیریتی ندارید.`).  The guard is
 * therefore kept in the handler and the routes carry only
 * `legacy.session:optional`; see {@see AdminPanelController::requireIsAdmin}.
 *
 * `payroll/load` additionally validates its three query parameters **before**
 * the guard, because FastAPI resolves a handler's declared `Query(...)`
 * parameters before the handler body runs — an anonymous request with a
 * missing `period_year` is a 422, not the 403 the guard would answer.
 *
 * `_ensure_payroll_calculations_table()` is a no-op here: the table exists
 * (`docs/migration/DATABASE_SCHEMA.md`) and the migration must not create
 * objects in `userDB`, the same rule `ensure_employment_status_column()`
 * follows.
 */
final class PayrollController extends AdminPanelController
{
    /** `PAYROLL_CALCULATION_TYPES` in `app/main.py`. */
    private const CALCULATION_TYPES = ['overtime', 'comprehensive', 'hourly', 'summary'];

    /**
     * `POST /api/admin/payroll/save` — upsert one payload per row.
     *
     * The body is a JSON object with a `calculation_type`, a Jalali
     * `period_year`, a `period_month` and a `rows` list.  Each row carries a
     * `username` and a `payload` object; rows that are not objects, or whose
     * username / payload is missing, are **skipped**, not refused.  For every
     * calculation type except `summary` the username must also exist in
     * `user_table`.
     *
     * The validation order is the Python's, and each step has its own body:
     *
     * 1. a bad `calculation_type` → 400 `نوع محاسبه معتبر نیست.`;
     * 2. an unparsable `period_year` → 400 `اطلاعات ذخیره‌سازی معتبر نیست.`;
     * 3. an out-of-range year, an empty month or a non-list `rows` → 400
     *    `اطلاعات دوره یا ردیف‌ها معتبر نیست.`;
     * 4. more than 500 rows → 400 `تعداد ردیف‌ها بیش از حد مجاز است.`.
     *
     * A success is `{"success": true, "saved": N, "message": …}`; the count is
     * the number of rows that produced an UPDATE or an INSERT, which is **not**
     * the number of rows sent — skipped rows do not increment it.
     */
    public function save(Request $request): JsonResponse
    {
        if ($this->isAdminSession($request) !== true) {
            return $this->requireIsAdmin($request);
        }

        try {
            $data = json_decode((string) $request->getContent());

            if (json_last_error() !== JSON_ERROR_NONE || ! is_object($data)) {
                throw new \RuntimeException('The request body is not a JSON object.');
            }

            $calculationType = trim((string) ($data->calculation_type ?? ''));

            if (! in_array($calculationType, self::CALCULATION_TYPES, true)) {
                return response()->json(['success' => false, 'error' => 'نوع محاسبه معتبر نیست.'], 400);
            }

            $periodYear = LegacyPythonScalar::pyInt(
                LegacyPythonScalar::persianToEnglishDigits($this->pythonStr($data->period_year ?? null))
            );
            $periodMonth = trim((string) ($data->period_month ?? ''));
            $rows = $data->rows ?? null;

            if ($periodYear < 1300 || $periodYear > 1600 || $periodMonth === '' || ! is_array($rows)) {
                return response()->json(['success' => false, 'error' => 'اطلاعات دوره یا ردیف‌ها معتبر نیست.'], 400);
            }

            if (count($rows) > 500) {
                return response()->json(['success' => false, 'error' => 'تعداد ردیف‌ها بیش از حد مجاز است.'], 400);
            }

            $actor = $this->actor($request);
            $connection = DB::connection();
            $saved = 0;

            foreach ($rows as $row) {
                if (! is_object($row)) {
                    continue;
                }

                $username = trim((string) ($row->username ?? ''));
                $payload = $row->payload ?? null;

                if ($username === '' || ! is_object($payload)) {
                    continue;
                }

                if ($calculationType !== 'summary') {
                    $exists = $connection->selectOne(
                        'SELECT 1 FROM user_table WHERE LTRIM(RTRIM(username)) = ?',
                        [$username]
                    );

                    if ($exists === null) {
                        continue;
                    }
                }

                $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                $updated = $connection->update(
                    'UPDATE dbo.admin_payroll_calculations
                     SET payload_json = ?, saved_by = ?, updated_at = SYSUTCDATETIME()
                     WHERE calculation_type = ? AND period_year = ? AND period_month = ? AND username = ?',
                    [$payloadJson, $actor, $calculationType, $periodYear, $periodMonth, $username]
                );

                if ($updated === 0) {
                    $connection->insert(
                        'INSERT INTO dbo.admin_payroll_calculations
                            (calculation_type, period_year, period_month, username, payload_json, saved_by)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [$calculationType, $periodYear, $periodMonth, $username, $payloadJson, $actor]
                    );
                }

                $saved++;
            }

            return response()->json([
                'success' => true,
                'saved' => $saved,
                'message' => 'تغییرات با موفقیت ذخیره شد.',
            ]);
        } catch (ValueError) {
            // `int(_payroll_ascii_digits(...))` — a `period_year` that is not an
            // integer after the Persian-digit translation.
            return response()->json(['success' => false, 'error' => 'اطلاعات ذخیره‌سازی معتبر نیست.'], 400);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'error' => 'خطا در ذخیره تغییرات.',
                // `str(exc) if DEBUG else None` — DEBUG is false in production.
                'detail' => null,
            ], 500);
        }
    }

    /**
     * `GET /api/admin/payroll/load` — every saved calculation for one period.
     *
     * The three query parameters are required and validated before the guard
     * (see the class docblock).  A bad `calculation_type` is 400
     * `نوع محاسبه معتبر نیست.`; an unparsable year or an out-of-range one is
     * 400 `دوره معتبر نیست.`.
     *
     * Each item's `payload` is the stored JSON **decoded**, so a payload that
     * was saved as an object comes back as an object and one saved as a list
     * comes back as a list.  `updated_at` is serialised the way the Python
     * serialised it — `str(datetime)`, a space-separated `Y-m-d H:i:s` with a
     * six-digit fraction only when the microsecond is non-zero — which is a
     * different string from both `isoformat()` and the raw `pdo_sqlsrv` value.
     */
    public function load(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'calculation_type' => LegacyQuery::requiredString(),
            'period_year' => LegacyQuery::requiredString(),
            'period_month' => LegacyQuery::requiredString(),
        ]);

        if ($this->isAdminSession($request) !== true) {
            return $this->requireIsAdmin($request);
        }

        $calculationType = $params['calculation_type'];

        if (! in_array($calculationType, self::CALCULATION_TYPES, true)) {
            return response()->json(['success' => false, 'error' => 'نوع محاسبه معتبر نیست.'], 400);
        }

        try {
            $year = LegacyPythonScalar::pyInt(
                LegacyPythonScalar::persianToEnglishDigits($this->pythonStr($params['period_year']))
            );
            $month = trim($params['period_month']);

            if ($year < 1300 || $year > 1600 || $month === '') {
                return response()->json(['success' => false, 'error' => 'دوره معتبر نیست.'], 400);
            }

            $rows = DB::connection()->select(
                'SELECT username, payload_json, updated_at
                 FROM dbo.admin_payroll_calculations
                 WHERE calculation_type = ? AND period_year = ? AND period_month = ?
                 ORDER BY username',
                [$calculationType, $year, $month]
            );

            $items = [];

            foreach ($rows as $row) {
                $payloadJson = $row->payload_json ?? '';

                if ($payloadJson === null || $payloadJson === '') {
                    $payloadJson = '{}';
                }

                // Decoded, not passed through: the Python's `json.loads(...)`
                // answers `{}` for a payload that will not parse, and the
                // decoded value keeps its own JSON type on the way back out.
                $payload = json_decode((string) $payloadJson);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    $payload = [];
                }

                $items[] = [
                    'username' => trim((string) $row->username),
                    'payload' => $payload,
                    'updated_at' => self::pythonStrDateTime($row->updated_at ?? null),
                ];
            }

            return response()->json(['success' => true, 'items' => $items]);
        } catch (ValueError) {
            return response()->json(['success' => false, 'error' => 'دوره معتبر نیست.'], 400);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'error' => 'خطا در بازیابی تغییرات.',
                'detail' => null,
            ], 500);
        }
    }

    /**
     * `str(get_user_from_session(request) or "admin").strip()` — the session
     * username, or the literal `admin` when there is none.
     */
    private function actor(Request $request): string
    {
        $username = trim((string) $request->session()->get('username', ''));

        return $username === '' ? 'admin' : $username;
    }

    /**
     * `str(value or "")` — Python's `str()` on a scalar, with a falsy value
     * becoming the empty string first.
     *
     * The float case matters: `str(1405.0)` is `"1405.0"` in Python, and
     * `json_encode` is the one PHP conversion that reproduces it.
     */
    private function pythonStr(mixed $value): string
    {
        if (! $value) {
            return '';
        }

        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => (string) json_encode($value),
            is_bool($value) => $value ? 'True' : 'False',
            default => '',
        };
    }

    /**
     * `str(datetime)` — the space-separated form Python's `str()` produces.
     *
     * `datetime.isoformat()` uses a `T` and is what {@see LegacySerializer}
     * emits; this handler used `str()`, which uses a space and omits the
     * fraction entirely when the microsecond is zero.  `pyodbc` handed the
     * running server a `datetime`, so the wire value was
     * `2026-09-30 08:53:41.864000`; `pdo_sqlsrv` hands back the string
     * `2026-09-30 08:53:41.864`, which is re-formatted here.
     */
    private static function pythonStrDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = is_string($value) ? trim($value) : '';

        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})(?:\.(\d+))?$/', $raw, $matches) !== 1) {
            return $raw;
        }

        $fraction = (int) str_pad(substr($matches[3] ?? '', 0, 6), 6, '0');
        $base = $matches[1].' '.$matches[2];

        return $fraction === 0 ? $base : $base.'.'.sprintf('%06d', $fraction);
    }
}
