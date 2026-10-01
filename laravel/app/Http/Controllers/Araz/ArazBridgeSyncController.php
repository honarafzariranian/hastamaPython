<?php

namespace App\Http\Controllers\Araz;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\Araz\BridgeSecret;
use App\Support\Araz\JalaliDate;
use App\Support\Http\ClientAddress;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyValidationException;
use DateTimeImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;
use ValueError;

/**
 * `POST /api/araz/bridge-sync` — the machine-to-machine endpoint the Araz bridge
 * agent pushes attendance records to, ported from `bridge_sync` in
 * `app/api/routes/araz_api.py`.
 *
 * ## The authentication contract
 *
 * The bridge agent is a script on the PC the device whitelists; it has no
 * browser session, so this endpoint carries **no session middleware** and
 * authenticates the request itself.  The scheme is the Python's, and it is
 * simpler than the inventory's "HMAC" label suggests — there is no request
 * signing and no digest of the payload:
 *
 * * the client puts the shared secret in the `secret` body field;
 * * the server compares it against `ARAZ_BRIDGE_SECRET` with
 *   `hmac.compare_digest` — a **constant-time** string comparison, which is
 *   the only sense in which this is an HMAC;
 * * when `ARAZ_BRIDGE_SECRET` is unset the endpoint **fails closed** with
 *   **503** rather than accepting unauthenticated records.
 *
 * The order is the Python's and is load-bearing — FastAPI validates the body
 * *before* the handler runs, so a malformed body is a 422 even when no secret
 * is configured:
 *
 *     422 body  →  503 no secret  →  429 rate limit  →  401 bad secret
 *     →  200 empty  →  413 too many  →  200 applied
 *
 * The rate limit is 60 requests / 60 seconds per IP and, like the
 * registration limiter, records a hit only while the caller is *under* the
 * limit, so a denied request does not extend the window.
 */
final class ArazBridgeSyncController extends Controller
{
    /** `MAX_BRIDGE_RECORDS` — the bridge pushes one day of records per call. */
    private const MAX_BRIDGE_RECORDS = 5000;

    /** The per-IP limit and window, as the Python's `limiter.allow` call. */
    private const RATE_LIMIT = 60;

    private const RATE_WINDOW = 60;

    public function __construct(private readonly AuditLogger $audit) {}

    public function sync(Request $request): JsonResponse
    {
        // 1. Body validation — FastAPI does this before the handler runs.
        $body = $this->validateBody($request);

        // 2. Fail closed when no secret is configured.
        $configured = BridgeSecret::get();

        if ($configured === '') {
            Log::error('bridge-sync rejected: ARAZ_BRIDGE_SECRET is not configured');

            return response()->json([
                'success' => false,
                'error' => 'ARAZ_BRIDGE_SECRET not configured',
            ], 503);
        }

        // 3. Rate limit, per IP.
        $key = 'hastama.araz.bridge-sync:'.ClientAddress::for($request);

        if (RateLimiter::tooManyAttempts($key, self::RATE_LIMIT)) {
            throw LegacyHttpException::detail(429, 'Too many bridge-sync requests');
        }

        RateLimiter::hit($key, self::RATE_WINDOW);

        // 4. Constant-time secret comparison.
        if (! hash_equals($configured, $body['secret'])) {
            $this->audit->logEvent('SECURITY', 'bridge_sync_rejected', [
                'module' => 'araz',
                'status' => 'failure',
                'severity' => 'high',
                'ip_address' => ClientAddress::for($request),
                'user_agent' => ClientAddress::userAgent($request),
                'metadata' => ['reason' => 'invalid_secret'],
            ]);

            throw LegacyHttpException::detail(401, 'Invalid bridge secret');
        }

        // 5. An empty batch is a successful no-op.
        if ($body['records'] === []) {
            return response()->json([
                'success' => true,
                'synced' => 0,
                'skipped' => 0,
                'failed' => 0,
                'message' => 'No records provided',
            ]);
        }

        // 6. The batch cap.
        if (count($body['records']) > self::MAX_BRIDGE_RECORDS) {
            throw LegacyHttpException::detail(
                413,
                'Too many records in one batch (max '.self::MAX_BRIDGE_RECORDS.')'
            );
        }

        // 7. Apply the records.
        $result = $this->applyRecords($body['records']);

        // A batch-level failure answers the Python's generic database error,
        // never the driver's message, and skips the audit event.
        if (! $result['ok']) {
            return response()->json([
                'success' => false,
                'synced' => $result['synced'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
                'message' => 'Database error while applying bridge records',
            ]);
        }

        $msg = "Synced: {$result['synced']}, Skipped: {$result['skipped']}, Failed: {$result['failed']}";

        if ($result['errors'] !== []) {
            $msg .= ' Errors: '.implode('; ', array_slice($result['errors'], 0, 5));
        }

        $this->audit->logEvent('INTEGRATION', 'bridge_sync', [
            'module' => 'araz',
            'status' => $result['failed'] === 0 ? 'success' : 'partial',
            'severity' => $result['failed'] === 0 ? 'low' : 'medium',
            'ip_address' => ClientAddress::for($request),
            'metadata' => [
                'synced' => $result['synced'],
                'skipped' => $result['skipped'],
                'failed' => $result['failed'],
            ],
        ]);

        Log::info("Bridge sync: {$msg}");

        return response()->json([
            'success' => $result['failed'] === 0,
            'synced' => $result['synced'],
            'skipped' => $result['skipped'],
            'failed' => $result['failed'],
            'message' => $msg,
        ]);
    }

    /**
     * Apply the batch inside one transaction, as the Python's `conn` did.
     *
     * A per-record failure is counted and skipped (the Python's inner
     * `try/except`), so one bad record does not fail the batch; a failure of
     * the batch itself — the known-users query, or a broken connection —
     * rolls everything back and answers the Python's generic database error,
     * never the driver's message.
     *
     * @param  array<int, array<string, string>>  $records
     * @return array{ok: bool, synced: int, skipped: int, failed: int, errors: array<int, string>}
     */
    private function applyRecords(array $records): array
    {
        $synced = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        try {
            DB::transaction(function () use ($records, &$synced, &$skipped, &$failed, &$errors): void {
                $knownUsers = $this->knownUsers();

                foreach ($records as $record) {
                    try {
                        $outcome = $this->applyRecord($record, $knownUsers);

                        if ($outcome === 'synced') {
                            $synced++;
                        } else {
                            $skipped++;
                        }
                    } catch (Throwable $exception) {
                        $failed++;
                        $errors[] = "{$record['username']}@{$record['tarikh']}: ".$exception::class;
                        Log::warning('Bridge sync record error: '.$exception::class.': '.$exception->getMessage());
                    }
                }
            });
        } catch (Throwable $exception) {
            Log::error('Bridge sync failed: '.$exception->getMessage());

            return [
                'ok' => false,
                'synced' => $synced,
                'skipped' => $skipped,
                'failed' => $failed,
                'errors' => $errors,
            ];
        }

        return [
            'ok' => true,
            'synced' => $synced,
            'skipped' => $skipped,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * The active usernames, lower-cased — only known employees may be written.
     *
     * @return array<int, string>
     */
    private function knownUsers(): array
    {
        $rows = DB::select(
            "SELECT LTRIM(RTRIM(username)) AS username FROM user_table WHERE ISNULL(is_active, 'active') = 'active'"
        );

        $known = [];

        foreach ($rows as $row) {
            $known[] = mb_strtolower(trim((string) $row->username));
        }

        return $known;
    }

    /**
     * Apply one record.  Returns 'synced' or 'skipped'.
     *
     * @param  array<string, string>  $record
     * @param  array<int, string>  $knownUsers
     *
     * @throws Throwable when the record is rejected, exactly as the Python's
     *                   per-record `try/except` caught it.
     */
    private function applyRecord(array $record, array $knownUsers): string
    {
        $username = trim($record['username']);

        if ($username === '' || mb_strlen($username) > 50) {
            throw new ValueError("invalid username: '{$record['username']}'");
        }

        if (! in_array(mb_strtolower($username), $knownUsers, true)) {
            throw new ValueError("unknown user: {$username}");
        }

        $parts = explode('/', str_replace("\u{200F}", '', $record['tarikh']));

        if (count($parts) !== 3) {
            throw new ValueError("Bad date format: {$record['tarikh']}");
        }

        $year = self::pyInt($parts[0]);
        $month = self::pyInt($parts[1]);
        $day = self::pyInt($parts[2]);

        $gregorian = JalaliDate::fromJalali($year, $month, $day);

        $gregorianYear = (int) $gregorian->format('Y');

        if (! ($gregorianYear >= 2015 && $gregorianYear <= 2100)) {
            throw new ValueError("date out of range: {$record['tarikh']}");
        }

        $vorood = self::parseTime($record['vorood']);
        $khorooj = self::parseTime($record['khorooj']);

        $date = $gregorian->format('Y-m-d');

        $existing = DB::selectOne(
            'SELECT id FROM hozoor WHERE username = ? AND [date] = ?',
            [$username, $date]
        );

        if ($existing) {
            $updates = [];
            $params = [];

            if ($record['vorood'] !== '' && $record['vorood'] !== '00:00') {
                $updates[] = 'vrood = ?';
                $params[] = $vorood;
            }

            if ($record['khorooj'] !== '' && $record['khorooj'] !== '00:00') {
                $updates[] = 'khoroj = ?';
                $params[] = $khorooj;
            }

            if ($updates === []) {
                return 'skipped';
            }

            $params[] = $existing->id;
            DB::update('UPDATE hozoor SET '.implode(', ', $updates).' WHERE id = ?', $params);

            return 'synced';
        }

        DB::insert(
            'INSERT INTO hozoor (username, [date], vrood, khoroj) VALUES (?, ?, ?, ?)',
            [$username, $date, $vorood, $khorooj]
        );

        return 'synced';
    }

    /**
     * Python's `int(str)` for a trimmed string, or a `ValueError` when it would
     * fail — the per-record `try/except` turns that into a counted failure.
     */
    private static function pyInt(string $value): int
    {
        $value = trim($value);

        if (preg_match('/^[+-]?\d+$/', $value) !== 1) {
            throw new ValueError("invalid literal for int(): '{$value}'");
        }

        return (int) $value;
    }

    /**
     * `datetime.strptime(value.strip(), "%H:%M")` — a strict `HH:MM` parse.
     */
    private static function parseTime(string $value): string
    {
        $value = trim($value);

        $time = DateTimeImmutable::createFromFormat('!H:i', $value);

        if ($time === false) {
            throw new ValueError("time data '{$value}' does not match format '%H:%M'");
        }

        return $time->format('H:i:s');
    }

    /**
     * Validate `BridgeSyncRequest` the way pydantic did, before the handler
     * runs.
     *
     * @return array{records: array<int, array<string, string>>, secret: string}
     *
     * @throws LegacyValidationException
     */
    private function validateBody(Request $request): array
    {
        $decoded = json_decode($request->getContent());

        if (! is_object($decoded)) {
            throw new LegacyValidationException([self::bodyError(
                'model_attributes_type',
                'Input should be a valid dictionary or object to extract fields from',
                $decoded,
            )]);
        }

        $body = (array) $decoded;

        if (! array_key_exists('records', $body)) {
            throw new LegacyValidationException([
                $this->error('missing', 'records', 'Field required', $decoded),
            ]);
        }

        $records = $body['records'];

        if (! is_array($records)) {
            throw new LegacyValidationException([
                $this->error('list_type', 'records', 'Input should be a valid list', $records),
            ]);
        }

        $validated = [];

        foreach ($records as $index => $record) {
            if (! is_object($record) && ! is_array($record)) {
                throw new LegacyValidationException([
                    self::bodyError(
                        'model_attributes_type',
                        'Input should be a valid dictionary or object to extract fields from',
                        $record,
                        [],
                        [$index],
                    ),
                ]);
            }

            $fields = (array) $record;
            $validatedRecord = [];

            foreach (['username', 'tarikh', 'vorood', 'khorooj'] as $field) {
                if (! array_key_exists($field, $fields)) {
                    throw new LegacyValidationException([
                        $this->recordError('missing', $index, $field, 'Field required', $record),
                    ]);
                }

                if (! is_string($fields[$field])) {
                    throw new LegacyValidationException([
                        $this->recordError('string_type', $index, $field, 'Input should be a valid string', $fields[$field]),
                    ]);
                }

                $validatedRecord[$field] = $fields[$field];
            }

            $validated[] = $validatedRecord;
        }

        $secret = '';

        if (array_key_exists('secret', $body)) {
            if (! is_string($body['secret'])) {
                throw new LegacyValidationException([
                    $this->error('string_type', 'secret', 'Input should be a valid string', $body['secret']),
                ]);
            }

            $secret = $body['secret'];
        }

        return ['records' => $validated, 'secret' => $secret];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @param  array<int, mixed>  $locSuffix
     * @return array<string, mixed>
     */
    private static function bodyError(
        string $type,
        string $message,
        mixed $input,
        array $ctx = [],
        array $locSuffix = [],
    ): array {
        $error = ['type' => $type, 'loc' => array_merge(['body'], $locSuffix), 'msg' => $message, 'input' => $input];

        if ($ctx !== []) {
            $error['ctx'] = $ctx;
        }

        return $error;
    }

    /**
     * A field inside one record of the `records` list — FastAPI's `loc` is the
     * whole path: `body → records → index → field`.
     *
     * @return array<string, mixed>
     */
    private function recordError(
        string $type,
        int $index,
        string $field,
        string $message,
        mixed $input,
    ): array {
        return [
            'type' => $type,
            'loc' => ['body', 'records', $index, $field],
            'msg' => $message,
            'input' => $input,
        ];
    }

    /**
     * @param  array<string, mixed>  $ctx
     * @return array<string, mixed>
     */
    private function error(
        string $type,
        string $field,
        string $message,
        mixed $input,
        array $ctx = [],
    ): array {
        $error = [
            'type' => $type,
            'loc' => ['body', $field],
            'msg' => $message,
            'input' => $input,
        ];

        if ($ctx !== []) {
            $error['ctx'] = $ctx;
        }

        return $error;
    }
}
