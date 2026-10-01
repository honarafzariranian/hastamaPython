<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Support\Legacy\LegacyPagination;
use App\Support\Legacy\LegacyPath;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * The optional customer-subscription feature: summary, list, detail and update.
 *
 * Ported from `subscriptions_summary`, `list_subscriptions`,
 * `get_subscription_detail` and `update_subscription` in
 * `app/api/routes/master_admin.py`.
 *
 * Three behaviours of the Python are load-bearing and easy to "fix" into bugs:
 *
 * * **The list paginates in PHP, not in SQL.**  The Python selected every row
 *   (`ORDER BY expires_at DESC, id DESC`), filtered and sliced in the handler,
 *   and `total` is the count *after* filtering.  A port that pushed the filters
 *   into the `WHERE` clause would answer the same numbers only while the filters
 *   were exact-match — the `search` predicate is a case-folded substring test
 *   across five columns and `status=expiring` is a computed range, and neither
 *   is expressible in the SQL the Python ran.
 * * **`status` is overwritten by the computed status.**  `_subscription_row`
 *   replaces the stored `subscription_status` with the computed one
 *   (`active`/`expired`/`upcoming`/`unknown`) and adds `computed_status`,
 *   `remaining_days` and `seats_used`.  The stored value survives nowhere in the
 *   response.
 * * **The update's `price` validation is dead code.**  `price` is not in the
 *   `allowed` set, so it can never appear in `updates` and the
 *   `Decimal(str(updates["price"]))` branch can never run.  It is reproduced
 *   anyway, so the two validations stay in the Python's order.
 *
 * One deliberate divergence: a body that is not a JSON object.  The Python
 * called `await request.json()` outside its `try`, so invalid JSON (or a scalar
 * body, where `key in data` raises `TypeError`) propagated to Starlette and
 * answered a plain-text 500.  Here the same condition throws and Laravel's
 * exception handler renders its own JSON 500 — the status is the same, the body
 * is the framework's rather than Starlette's.
 */
final class SubscriptionController extends MasterAdminController
{
    /** The twenty-one columns every subscription query selects. */
    private const COLUMNS = 'id, customer_id, customer_code, customer_name, contact_name,
        contact_email, contact_phone, plan_name, subscription_status, purchased_at, starts_at,
        expires_at, max_users, price, currency, payment_method, payment_reference,
        invoice_number, notes, created_at, updated_at';

    /** The fields `update_subscription` accepts, in the Python's set order. */
    private const ALLOWED_UPDATES = [
        'customer_id', 'customer_code', 'customer_name', 'contact_name', 'contact_email',
        'contact_phone', 'plan_name', 'subscription_status', 'starts_at', 'expires_at',
        'max_users', 'payment_method', 'payment_reference', 'invoice_number', 'notes',
    ];

    /** The fields that must be present and non-blank when they are sent at all. */
    private const REQUIRED_WHEN_PRESENT = [
        'customer_id', 'customer_name', 'plan_name', 'starts_at', 'expires_at',
    ];

    /** `GET /master-admin/api/subscriptions/summary` */
    public function summary(): JsonResponse
    {
        try {
            if (! $this->tableReady()) {
                return $this->ok([
                    'success' => true,
                    'setup_needed' => true,
                    'data' => [
                        'total_customers' => 0,
                        'active_subscriptions' => 0,
                        'expiring_soon' => 0,
                        'total_seats_used' => 0,
                        'total_seats' => 0,
                    ],
                ]);
            }

            $prepared = $this->preparedRows();

            $active = array_values(array_filter(
                $prepared,
                static fn (array $row): bool => $row['status'] === 'active',
            ));

            $expiringSoon = 0;

            foreach ($active as $row) {
                $remaining = $row['remaining_days'];

                if ($remaining !== null && $remaining >= 0 && $remaining <= 30) {
                    $expiringSoon++;
                }
            }

            $seatsUsed = 0;
            $seats = 0;

            foreach ($prepared as $row) {
                $seatsUsed += (int) ($row['seats_used'] ?? 0);
                $seats += (int) ($row['max_users'] ?? 0);
            }

            return $this->ok([
                'success' => true,
                'setup_needed' => false,
                'data' => [
                    'total_customers' => $this->countCustomers($prepared),
                    'active_subscriptions' => count($active),
                    'expiring_soon' => $expiringSoon,
                    'total_seats_used' => $seatsUsed,
                    'total_seats' => $seats,
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'subscriptions.summary');
        }
    }

    /**
     * `GET /master-admin/api/subscriptions`
     *
     * The Python signature, declared as such: two bounded integers and two
     * `Optional[str] = None` filters.  The filtering and the pagination both run
     * in PHP over the full result set — see the class docblock.
     */
    public function index(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 50, ge: 1, le: 200),
            'search' => LegacyQuery::nullableString(),
            'status' => LegacyQuery::nullableString(),
        ]);

        try {
            if (! $this->tableReady()) {
                return $this->ok(LegacyPagination::setupNotRun());
            }

            $rows = $this->preparedRows();

            // `if search:` — the empty string is falsy and adds no filter.
            if (is_string($params['search']) && $params['search'] !== '') {
                $needle = mb_strtolower($params['search'], 'UTF-8');

                $rows = array_values(array_filter($rows, function (array $row) use ($needle): bool {
                    $haystack = mb_strtolower(implode(' ', [
                        (string) ($row['customer_name'] ?? ''),
                        (string) ($row['customer_code'] ?? ''),
                        (string) ($row['customer_id'] ?? ''),
                        (string) ($row['plan_name'] ?? ''),
                        (string) ($row['contact_email'] ?? ''),
                    ]), 'UTF-8');

                    return str_contains($haystack, $needle);
                }));
            }

            // `if status:` — same truthiness rule, and `expiring` is a computed
            // range rather than a stored value.
            if (is_string($params['status']) && $params['status'] !== '') {
                $normalized = mb_strtolower($params['status'], 'UTF-8');

                if ($normalized === 'expiring') {
                    $rows = array_values(array_filter($rows, function (array $row): bool {
                        if ($row['status'] !== 'active') {
                            return false;
                        }

                        $remaining = $row['remaining_days'];

                        return $remaining !== null && $remaining >= 0 && $remaining <= 30;
                    }));
                } else {
                    $rows = array_values(array_filter(
                        $rows,
                        fn (array $row): bool => mb_strtolower((string) $row['status'], 'UTF-8') === $normalized,
                    ));
                }
            }

            $total = count($rows);
            $page = $params['page'];
            $perPage = $params['per_page'];

            // Python's `rows[offset:offset + per_page]` — a slice of the filtered
            // list, not a SQL `OFFSET`.
            $rows = array_slice($rows, ($page - 1) * $perPage, $perPage);

            return $this->ok(LegacyPagination::withSetupNeeded($rows, $total, $page, $perPage, false));
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'subscriptions.index');
        }
    }

    /**
     * `GET /master-admin/api/subscriptions/{subscription_id}`
     *
     * The path parameter is a declared `int` in the Python, so a non-numeric id
     * is FastAPI's 422 with `loc: ["path", "subscription_id"]` — reproduced by
     * {@see LegacyPath::int}, which runs outside the `try` so the rejection is
     * not swallowed by the handler's own 500.
     */
    public function show(string $subscriptionId): JsonResponse
    {
        $id = LegacyPath::int($subscriptionId, 'subscription_id');

        try {
            if (! $this->tableReady()) {
                return $this->ok(['success' => true, 'setup_needed' => true, 'data' => null]);
            }

            $row = DB::selectOne(
                'SELECT '.self::COLUMNS.' FROM dbo.customer_subscriptions WHERE id = ?',
                [$id]
            );

            if ($row === null) {
                return $this->notFound('اشتراک یافت نشد.');
            }

            $data = $this->subscriptionRow($row);

            // The linked-user list is best-effort: a failure here must not take
            // down the subscription detail, exactly as in the Python.
            try {
                $data['users'] = LegacySerializer::rows('user_table', DB::select(
                    'SELECT id, username, name, last_name, department, role, is_active, last_login
                     FROM user_table WHERE LTRIM(RTRIM(customer_id)) = ?
                     ORDER BY name, last_name, username',
                    [trim((string) ($data['customer_id'] ?? ''))]
                ));
            } catch (Throwable) {
                $data['users'] = [];
            }

            return $this->ok(['success' => true, 'setup_needed' => false, 'data' => $data]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'subscriptions.show');
        }
    }

    /**
     * `PATCH /master-admin/api/subscriptions/{subscription_id}`
     *
     * The validation order is the Python's and every message is verbatim:
     * allowed-fields-only → non-empty required fields → `max_users` → dates →
     * (dead) `price` → `payment_method`.  Only then does the handler touch the
     * database, where a missing table is 409 and a missing row is 404.
     *
     * The success body is `{"success": true, "message": "اطلاعات مشتری ذخیره شد."}`
     * and the failure body is a **different** 500 message
     * (`ذخیره اطلاعات مشتری ناموفق بود.`) from every other endpoint in this
     * module — two strings, one module, both live.
     */
    public function update(string $subscriptionId, Request $request): JsonResponse
    {
        $id = LegacyPath::int($subscriptionId, 'subscription_id');

        $decoded = json_decode((string) $request->getContent(), true);

        // The Python's `await request.json()` ran outside the `try`; invalid JSON
        // (or a scalar body, where `key in data` raises `TypeError`) answered a
        // 500 from Starlette rather than the handler's own envelope.
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('The request body is not a JSON object.');
        }

        $updates = [];

        foreach (self::ALLOWED_UPDATES as $key) {
            if (array_key_exists($key, $decoded)) {
                $updates[$key] = $decoded[$key];
            }
        }

        if ($updates === []) {
            return $this->detail(400, 'اطلاعاتی برای ویرایش ارسال نشده است.');
        }

        foreach (self::REQUIRED_WHEN_PRESENT as $key) {
            if (array_key_exists($key, $updates) && trim($this->pythonOrString($updates[$key])) === '') {
                return $this->detail(400, "فیلد {$key} الزامی است.");
            }
        }

        if (array_key_exists('max_users', $updates)) {
            $maxUsers = $this->pythonToInt($updates['max_users']);

            if ($maxUsers === null || $maxUsers < 0) {
                return $this->detail(400, 'ظرفیت کاربران معتبر نیست.');
            }

            $updates['max_users'] = $maxUsers;
        }

        foreach (['starts_at', 'expires_at'] as $key) {
            if (array_key_exists($key, $updates)) {
                $date = $this->pythonDate($this->pythonStr($updates[$key]));

                if ($date === null) {
                    return $this->detail(400, 'تاریخ واردشده معتبر نیست.');
                }

                $updates[$key] = $date;
            }
        }

        // Dead in the Python too: `price` is not in the allowed set, so it can
        // never reach here.  Kept so the validation order cannot drift.
        if (array_key_exists('price', $updates) && $updates['price'] !== null && $updates['price'] !== '') {
            if (! is_numeric((string) $updates['price'])) {
                return $this->detail(400, 'مبلغ واردشده معتبر نیست.');
            }
        }

        if (array_key_exists('payment_method', $updates)
            && ! in_array($updates['payment_method'], ['cash', 'check', 'installment'], true)) {
            return $this->detail(400, 'روش پرداخت معتبر نیست.');
        }

        try {
            if (! $this->tableReady()) {
                return $this->detail(409, 'جدول مشتریان آماده نیست.');
            }

            $previous = DB::selectOne(
                'SELECT customer_id, customer_name FROM dbo.customer_subscriptions WHERE id = ?',
                [$id]
            );

            if ($previous === null) {
                return $this->notFound('اشتراک یافت نشد.');
            }

            $assignments = implode(', ', array_map(
                static fn (string $key): string => "{$key} = ?",
                array_keys($updates),
            ));

            DB::update(
                "UPDATE dbo.customer_subscriptions SET {$assignments}, updated_at = SYSUTCDATETIME() WHERE id = ?",
                array_merge(array_values($updates), [$id])
            );

            $oldCustomerId = trim((string) ($previous->customer_id ?? ''));
            $oldCustomerName = trim((string) ($previous->customer_name ?? ''));
            $newCustomerId = trim(array_key_exists('customer_id', $updates)
                ? $this->pythonStr($updates['customer_id'])
                : $oldCustomerId);
            $newCustomerName = trim(array_key_exists('customer_name', $updates)
                ? $this->pythonStr($updates['customer_name'])
                : $oldCustomerName);

            // The user-table sync is best-effort: the Python logged a warning and
            // still reported success when it failed.
            try {
                DB::update(
                    'UPDATE user_table SET customer_id = ?, customer_name = ? WHERE LTRIM(RTRIM(customer_id)) = ?',
                    [$newCustomerId, $newCustomerName, $oldCustomerId]
                );
            } catch (Throwable $exception) {
                Log::warning("Could not synchronize subscription users for id {$id}", [
                    'exception' => $exception::class,
                ]);
            }

            return $this->ok(['success' => true, 'message' => 'اطلاعات مشتری ذخیره شد.']);
        } catch (Throwable $exception) {
            // Not the module's generic 500: this handler has its own message.
            Log::error('master-admin subscriptions.update failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'ذخیره اطلاعات مشتری ناموفق بود.',
            ], 500);
        }
    }

    /**
     * Whether the optional subscriptions migration has been installed.
     *
     * The Python's `OBJECT_ID(N'dbo.customer_subscriptions', N'U')` — a missing
     * table makes `OBJECT_ID` return NULL, which is the whole check.
     */
    private function tableReady(): bool
    {
        $row = DB::selectOne("SELECT OBJECT_ID(N'dbo.customer_subscriptions', N'U') AS object_id");

        return $row !== null && $row->object_id !== null;
    }

    /**
     * Every subscription row, computed, in the list's order.
     *
     * @return array<int, array<string, mixed>>
     */
    private function preparedRows(): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return array_map(
            fn (array $row): array => $this->subscriptionRow($row, $today),
            LegacySerializer::rows('customer_subscriptions', DB::select(
                'SELECT '.self::COLUMNS.' FROM dbo.customer_subscriptions ORDER BY expires_at DESC, id DESC'
            )),
        );
    }

    /**
     * `_subscription_row`: the computed status, the remaining days and the
     * best-effort seat usage, then the serialisation.
     *
     * @param  object|array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function subscriptionRow(object|array $row, ?CarbonImmutable $today = null): array
    {
        $row = (array) $row;
        $today ??= CarbonImmutable::now('UTC')->startOfDay();

        [$status, $remaining] = $this->subscriptionStatus($row, $today);

        // `status` is overwritten in place — the stored value is gone — and the
        // three computed keys are appended after the twenty-one columns.
        $row['computed_status'] = $status;
        $row['status'] = $status;
        $row['remaining_days'] = $remaining;
        $row['seats_used'] = $this->seatUsage($row);

        return LegacySerializer::row('customer_subscriptions', $row);
    }

    /**
     * `_subscription_status`: `(status, remaining_days)`.
     *
     * @param  array<string, mixed>  $row
     * @return array{0: string, 1: int|null}
     */
    private function subscriptionStatus(array $row, CarbonImmutable $today): array
    {
        $start = $this->subscriptionValue($row['starts_at'] ?? null);
        $expires = $this->subscriptionValue($row['expires_at'] ?? null);

        if ($expires === null) {
            return ['unknown', null];
        }

        // `(expires - today).days` — both are UTC midnights, so the timestamp
        // difference is an exact multiple of a day.
        $remaining = intdiv($expires->getTimestamp() - $today->getTimestamp(), 86400);

        if ($start !== null && $today->getTimestamp() < $start->getTimestamp()) {
            return ['upcoming', $remaining];
        }

        if ($remaining < 0) {
            return ['expired', $remaining];
        }

        return ['active', $remaining];
    }

    /**
     * `_subscription_value`: a date out of whatever the driver returned.
     *
     * `pyodbc` gave a `datetime`/`date`; `pdo_sqlsrv` gives a string, so the
     * string branch is the live one.  Python 3.10's `fromisoformat` accepted the
     * ISO shapes and nothing else — Carbon is deliberately laxer (slashes,
     * month names), so the shape is checked before parsing.
     */
    private function subscriptionValue(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        if (! is_string($value)) {
            return null;
        }

        $candidate = str_replace('Z', '+00:00', trim($value));

        if (preg_match('/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})?)?$/', $candidate) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($candidate, 'UTC');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * `_subscription_seat_usage`: best-effort active-user count.
     *
     * Zero unless `user_table` carries at least one of the identity columns and
     * the row carries a value for one — and any failure at all is zero, because
     * the Python wrapped the whole computation in `except Exception`.
     */
    private function seatUsage(array $row): int
    {
        try {
            $columns = [];

            foreach (DB::select(
                "SELECT LOWER(COLUMN_NAME) AS column_name FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'user_table'"
            ) as $result) {
                $columns[] = strtolower((string) $result->column_name);
            }

            $identityColumns = array_values(array_intersect(
                ['customer_code', 'customer_id', 'customer_name', 'email', 'phone'],
                $columns,
            ));

            $value = trim((string) $this->firstNonEmpty([
                $row['customer_code'] ?? null,
                $row['customer_id'] ?? null,
            ]));

            if ($identityColumns === [] || $value === '') {
                return 0;
            }

            $predicates = implode(' OR ', array_map(
                static fn (string $column): string => "LTRIM(RTRIM(COALESCE({$column}, ''))) = ?",
                $identityColumns,
            ));

            $active = in_array('is_active', $columns, true)
                ? " AND ISNULL(is_active, 'active') = 'active'"
                : '';

            $count = DB::selectOne(
                "SELECT COUNT(*) AS total FROM user_table WHERE ({$predicates}){$active}",
                array_fill(0, count($identityColumns), $value),
            );

            return (int) ($count->total ?? 0);
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * The distinct-customer count behind `total_customers`.
     *
     * Python's `len({r.get("customer_code") or r.get("customer_id") or r.get("customer_name") ...})`
     * — the first non-empty of the three, per row, deduplicated.
     *
     * @param  array<int, array<string, mixed>>  $prepared
     */
    private function countCustomers(array $prepared): int
    {
        $customers = [];

        foreach ($prepared as $row) {
            $customers[] = $this->firstNonEmpty([
                $row['customer_code'] ?? null,
                $row['customer_id'] ?? null,
                $row['customer_name'] ?? null,
            ]) ?? '';
        }

        return count(array_unique($customers));
    }

    /**
     * The first value that is neither null nor the empty string.
     *
     * Python's `a or b or c` on strings: only `''` and `None` fall through —
     * `'0'` is truthy in Python, so it must survive here too.
     *
     * @param  array<int, mixed>  $values
     */
    private function firstNonEmpty(array $values): ?string
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Python's `str(value)` for the scalars a JSON body can carry.
     *
     * Only the emptiness and the first ten characters are ever observable (the
     * required-field check and the date check), so a non-empty placeholder for
     * arrays and objects is faithful where it matters.
     */
    private function pythonStr(mixed $value): string
    {
        if ($value === null) {
            return 'None';
        }

        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return 'Array';
    }

    /**
     * Python's `str(updates[key] or "")` — the falsy JSON values become `''`.
     */
    private function pythonOrString(mixed $value): string
    {
        if ($value === null || $value === false || $value === 0 || $value === 0.0 || $value === '' || $value === []) {
            return '';
        }

        return $this->pythonStr($value);
    }

    /**
     * Python's `int(value)` — `null` when the value is not an integer literal.
     *
     * A float truncates (`int(5.7) == 5`), a string may carry surrounding
     * whitespace and a sign, and anything else is the `TypeError` the Python
     * caught as a 400.
     */
    private function pythonToInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_float($value)) {
            return (int) $value;
        }

        if (is_string($value) && preg_match('/^\s*[+-]?\d+\s*$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    /**
     * Python's `date.fromisoformat(str(value)[:10])` — `null` on `ValueError`.
     *
     * The first ten characters must be a real `YYYY-MM-DD`; `2026-13-45` and
     * `2026-02-30` are rejections, not truncations.
     */
    private function pythonDate(string $value): ?string
    {
        $candidate = substr($value, 0, 10);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $candidate) !== 1) {
            return null;
        }

        if (! checkdate((int) substr($candidate, 5, 2), (int) substr($candidate, 8, 2), (int) substr($candidate, 0, 4))) {
            return null;
        }

        return $candidate;
    }

    /**
     * FastAPI's `HTTPException` body for this module's own 400/409 refusals.
     *
     * The base class owns the 404 and the 500; these two statuses are specific
     * to the subscription update and live here rather than in a shared helper
     * only two controllers would use.
     */
    private function detail(int $status, string $message): JsonResponse
    {
        return response()->json(['detail' => $message], $status);
    }
}
