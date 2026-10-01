<?php

namespace App\Http\Controllers\CallSystem;

use App\Models\QueueTicket;
use App\Support\Http\LegacyOrigin;
use App\Support\Legacy\CallActor;
use App\Support\Legacy\LegacyBody;
use App\Support\Legacy\LegacyDisplayText;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyPath;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use App\Support\Legacy\LegacyWhitespace;
use App\Support\Legacy\PersianText;
use App\Support\Legacy\QueuePii;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `/api/queue/*` — the ticket queue: taking a number, listing it, calling it, and the
 * kiosk's edit flow.
 *
 * Ported from `app/api/routes/call_system.py` (lines 1096–1742).  Four kinds of guard
 * appear here, and each one sits at the point the Python put it:
 *
 * * **`POST /queue/take`** — `kiosk.write:queue-take` middleware, because the Python's
 *   `_guard_kiosk_write` is the same check the other kiosk writes use and the bucket is
 *   its own budget.
 * * **`/queue/call`, `/queue/call-next`, `/queue/complete`, both deletes** — not
 *   CSRF-exempt, so `VerifyLegacyCsrf` runs first and the handler then repeats
 *   `origin_is_same_site` exactly as the Python does.  The comment in the source is the
 *   reason: *"still enforce Origin when present so a leaked session cookie cannot drive
 *   cross-site state changes"* — a token can be replayed from another site, an Origin
 *   cannot.
 * * **`/queue/ticket/{n}`** — {@see QueuePii::assert} inside the handler, after path and
 *   body validation, because FastAPI validates before the handler runs and middleware
 *   would have answered `403` where the running server answers `422`.
 * * **`/queue/list` and `/queue/stats`** — no guard: the TV displays and the kiosk read
 *   them without a session, and `list` strips the PII fields unless the session is an
 *   administrator.
 *
 * ### Ordering, and why it is spelled out
 *
 * FastAPI validates a request's path parameters and body model **before** the handler's
 * first line; every in-handler check below therefore runs *after* validation.  The port
 * reproduces that by opening each method with {@see LegacyPath} and `LegacyBody`, then
 * the guard, then the database work.  Getting it the other way round is not a detail:
 *
 * ```
 * GET  /api/queue/ticket/abc   -> 422 int_parsing   (cross-site referer and all)
 * GET  /api/queue/ticket/5     -> 403 cross-site     (guard, because the path is fine)
 * ```
 *
 * ### The continuous ticket number
 *
 * `ticket_number` is one ever-growing stream: it does not reset at midnight and does not
 * reset when the server restarts, because the queue is a stream rather than a day.  The
 * allocator is the Python's statement verbatim, lock included, and it refuses to run
 * outside a transaction — see {@see QueueTicket::nextNumber()}.
 */
final class QueueController extends CallSystemController
{
    // ── Taking a ticket ──────────────────────────────────────────────────────

    /**
     * `POST /api/queue/take` — the kiosk touchscreen issues the next number.
     *
     * The body is read leniently: `await request.json()` inside a `try`, so anything
     * unparseable leaves `service` at its default and `patient` empty.  This is the one
     * endpoint where a visitor's details are stored, and both halves are truncated to the
     * column widths rather than refused — a long name is clipped, not rejected.
     */
    public function take(Request $request): JsonResponse
    {
        $body = $this->jsonObject($request);

        $service = QueueTicket::DEFAULT_SERVICE;

        if (self::isTruthy($body['service'] ?? null)) {
            $service = trim(self::pythonStr($body['service'])) ?: QueueTicket::DEFAULT_SERVICE;
        }

        $patient = isset($body['patient']) && is_array($body['patient']) && ! array_is_list($body['patient'])
            ? $body['patient']
            : [];

        $fields = $this->patientValues($patient);

        try {
            [$id, $number] = DB::connection()->transaction(function () use ($service, $fields): array {
                // The date comes from the database's clock, not the application's: the
                // Python read `CAST(SYSUTCDATETIME() AS DATE)` and used that value, so a
                // host whose clock drifted would still agree with its own rows.
                $today = DB::connection()
                    ->selectOne('SELECT CAST(SYSUTCDATETIME() AS DATE) AS today')
                    ->today;

                $number = QueueTicket::nextNumber();

                $id = DB::connection()->table('queue_tickets')->insertGetId([
                    'ticket_number' => $number,
                    'ticket_date' => $today,
                    'status' => QueueTicket::STATUS_WAITING,
                    'service' => $service,
                    'patient_name' => $fields['name'],
                    'patient_age' => $fields['age'],
                    'patient_national_id' => $fields['national_id'],
                    'patient_phone' => $fields['phone'],
                    'insurance_base' => $fields['insurance_base'],
                    'insurance_extra' => $fields['insurance_extra'],
                ]);

                return [(int) $id, $number];
            });
        } catch (Throwable $exception) {
            report($exception);

            throw LegacyHttpException::detail(500, 'نوبت ثبت نشد. لطفاً دوباره تلاش کنید.');
        }

        // Counted **after** the commit, and over every date: the kiosk's queue is the whole
        // waiting list, because a number taken yesterday that nobody called is still a
        // person standing in the room.  The Python opened a second connection for this;
        // one connection sees the same committed row.
        $waiting = (int) DB::connection()
            ->selectOne("SELECT COUNT(*) AS total FROM queue_tickets WHERE status = 'waiting'")
            ->total;

        $persian = PersianText::toPersianDigits((string) $number);

        $this->display->send([
            'type' => 'queue_ticket_taken',
            'data' => [
                'ticket_number' => $number,
                'persian_number' => $persian,
                'service' => $service,
                'waiting_count' => $waiting,
                'timestamp' => $this->broadcastTimestamp(),
            ],
        ]);

        return $this->ok([
            'message' => 'نوبت '.$persian.' ثبت شد.',
            'ticket' => [
                'id' => $id,
                'number' => $number,
                'persian_number' => $persian,
                'service' => $service,
                'waiting_count' => $waiting,
            ],
        ]);
    }

    // ── Reading the queue ────────────────────────────────────────────────────

    /**
     * `GET /api/queue/list?status=waiting`
     *
     * The queue is continuous: `waiting` and `called` come from **any** date so that a
     * number taken yesterday is still in the queue, while a finished status is scoped to
     * today.  `all` is the open queue plus today's finished rows.
     *
     * Patient PII is included only for an administrator session (the call-management
     * console).  The five columns withheld are `_QUEUE_PII_FIELDS`'s five — **not**
     * `patient_age`, which the Python never listed, so the kiosk's counter has always
     * shown an age beside a ticket with no name.  See
     * {@see QueueTicket::LIST_HIDDEN_COLUMNS}.
     *
     * Key order is the Python's, and it is not the SELECT's: `persian_number` is appended,
     * then each of `created_at`/`called_at`/`completed_at`/`ticket_date` is popped and
     * re-assigned, which moves it to the end.  Reproducing that ordering is why the row is
     * assembled rather than serialised in one pass.
     */
    public function index(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'status' => LegacyQuery::string(default: 'waiting'),
        ]);

        $status = $params['status'];

        $today = 'ticket_date = CAST(SYSUTCDATETIME() AS DATE)';

        if ($status === 'all') {
            $scope = "status IN ('waiting', 'called') OR {$today}";
            $bindings = [];
        } elseif ($status === QueueTicket::STATUS_WAITING || $status === QueueTicket::STATUS_CALLED) {
            $scope = 'status = ?';
            $bindings = [$status];
        } else {
            $scope = "status = ? AND {$today}";
            $bindings = [$status];
        }

        $rows = DB::connection()->select(
            "SELECT id, ticket_number, ticket_date, status, service,
                    patient_name, patient_age, patient_national_id, patient_phone,
                    insurance_base, insurance_extra, called_for,
                    called_at, completed_at, created_at
             FROM queue_tickets
             WHERE {$scope}
             ORDER BY ticket_number ASC",
            $bindings,
        );

        $includePii = $request->session()->get('is_admin') === true;

        $tickets = [];

        foreach ($rows as $row) {
            $ticket = LegacySerializer::row('queue_tickets', $row) ?? [];

            // Order of operations is the Python's, and each move is visible in the JSON.
            $ticket['persian_number'] = PersianText::toPersianDigits((string) $ticket['ticket_number']);

            foreach (['created_at', 'called_at', 'completed_at'] as $column) {
                $value = LegacySerializer::isoUtcZ($ticket[$column] ?? null);
                unset($ticket[$column]);
                $ticket[$column] = $value;
            }

            $date = (string) ($ticket['ticket_date'] ?? '');
            unset($ticket['ticket_date']);
            $ticket['ticket_date'] = $date;

            if (! $includePii) {
                foreach (QueueTicket::LIST_HIDDEN_COLUMNS as $column) {
                    unset($ticket[$column]);
                }
            }

            $tickets[] = $ticket;
        }

        return $this->ok(['tickets' => $tickets]);
    }

    /**
     * `GET /api/queue/stats` — the open queue at any date, plus today's finished rows.
     *
     * No guard, and no session: this is what the kiosk's counter paints.  `SUM(CASE …)`
     * answers NULL for a bucket no row falls into, which `int(... or 0)` in the Python
     * turned into `0`.
     */
    public function stats(): JsonResponse
    {
        $row = DB::connection()->selectOne(
            "SELECT
                SUM(CASE WHEN status IN ('waiting', 'called') THEN 1 ELSE 0 END) AS total,
                SUM(CASE WHEN status = 'waiting' THEN 1 ELSE 0 END) AS waiting,
                SUM(CASE WHEN status = 'called' THEN 1 ELSE 0 END) AS called,
                SUM(CASE WHEN status = 'completed'
                          AND ticket_date = CAST(SYSUTCDATETIME() AS DATE)
                         THEN 1 ELSE 0 END) AS completed
             FROM queue_tickets",
        );

        return $this->ok([
            'stats' => [
                'total' => (int) ($row->total ?? 0),
                'waiting' => (int) ($row->waiting ?? 0),
                'called' => (int) ($row->called ?? 0),
                'completed' => (int) ($row->completed ?? 0),
            ],
        ]);
    }

    // ── Calling ──────────────────────────────────────────────────────────────

    /**
     * `POST /api/queue/call/{ticket_id}?department=پذیرش` — call one waiting or already
     * called ticket, for reception or sample collection.
     *
     * Note the lookup accepts `called` as well as `waiting`: re-calling the person who is
     * already at the desk must not be a 404, only a re-broadcast.
     */
    public function callTicket(Request $request, string $ticketId): JsonResponse
    {
        $ticketId = LegacyPath::int($ticketId, 'ticket_id');

        // FastAPI validates the query model before the handler, so `department` is resolved
        // first — it carries no constraints, so this never refuses; the refusal is
        // `_clean_department`'s, which the Python calls after the origin and actor checks.
        $params = LegacyQuery::validate($request, [
            'department' => LegacyQuery::string(default: 'پذیرش'),
        ]);

        $this->requireSameSite($request);

        // The Python discards `_actor`'s return in this handler: it is the authorisation,
        // not a value anything stores — unlike `/queue/take`, whose row is stamped `guest`.
        CallActor::resolve($request, admin: true, required: false);

        $department = LegacyDisplayText::department($params['department']);

        $row = DB::connection()->selectOne(
            "SELECT ticket_number FROM queue_tickets
             WHERE id = ? AND status IN ('waiting', 'called')",
            [$ticketId],
        );

        if ($row === null) {
            throw LegacyHttpException::detail(404, 'نوبت یافت نشد.');
        }

        $number = trim((string) $row->ticket_number);

        DB::connection()->update(
            "UPDATE queue_tickets SET status = 'called', called_for = ?, called_at = SYSUTCDATETIME()
             WHERE id = ?",
            [$department, $ticketId],
        );

        $sent = $this->display->send([
            'type' => 'reception_call',
            'data' => $this->queueCallData($number, $department),
        ]);

        return $this->ok([
            'message' => 'نوبت '.PersianText::toPersianDigits($number).' فراخوان شد.',
            'display_count' => $sent,
        ]);
    }

    /**
     * `POST /api/queue/call-next?department=پذیرش` — the oldest waiting number, from
     * anywhere in the continuous queue.
     *
     * Unlike `call`, this one carries a `ticket` object back, and its `number` is an
     * **integer** where the broadcast's is a string.  Both are the Python's.
     */
    public function callNext(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'department' => LegacyQuery::string(default: 'پذیرش'),
        ]);

        $this->requireSameSite($request);

        // The Python discards `_actor`'s return here: it is the authorisation, not a value
        // this handler stores — unlike `/queue/take`, whose row is stamped `guest`.
        CallActor::resolve($request, admin: true, required: false);

        $department = LegacyDisplayText::department($params['department']);

        $row = DB::connection()->selectOne(
            "SELECT TOP 1 id, ticket_number FROM queue_tickets
             WHERE status = 'waiting'
             ORDER BY ticket_number ASC",
        );

        if ($row === null) {
            throw LegacyHttpException::detail(404, 'نوبتی در انتظار فراخوان نیست.');
        }

        $ticketId = (int) $row->id;
        $number = trim((string) $row->ticket_number);

        DB::connection()->update(
            "UPDATE queue_tickets SET status = 'called', called_for = ?, called_at = SYSUTCDATETIME()
             WHERE id = ?",
            [$department, $ticketId],
        );

        $sent = $this->display->send([
            'type' => 'reception_call',
            'data' => $this->queueCallData($number, $department),
        ]);

        return $this->ok([
            'message' => 'نوبت '.PersianText::toPersianDigits($number).' فراخوان شد.',
            'ticket' => [
                'id' => $ticketId,
                'number' => (int) $number,
                'persian_number' => PersianText::toPersianDigits($number),
                'department' => $department,
            ],
            'display_count' => $sent,
        ]);
    }

    /**
     * `POST /api/queue/complete/{ticket_id}` — mark a called ticket finished.
     *
     * Deliberately **no** 404: the Python updates `WHERE id = ? AND status = 'called'` and
     * returns success whether or not anything matched.  Marking an already-completed
     * ticket complete is idempotent, and answering 404 for it would break the console's
     * batch pass over a stale list.
     */
    public function complete(Request $request, string $ticketId): JsonResponse
    {
        $ticketId = LegacyPath::int($ticketId, 'ticket_id');

        $this->requireSameSite($request);

        CallActor::resolve($request, admin: true, required: false);

        DB::connection()->update(
            "UPDATE queue_tickets SET status = 'completed', completed_at = SYSUTCDATETIME()
             WHERE id = ? AND status = 'called'",
            [$ticketId],
        );

        return $this->ok(['message' => 'نوبت تکمیل شد.']);
    }

    // ── Deleting ─────────────────────────────────────────────────────────────

    /**
     * `DELETE /api/queue/{ticket_id}` — remove one waiting ticket, from any date.
     *
     * `administrator` is required here (`_actor(..., required=True)`) — unlike the calls
     * above — because deleting somebody's place in the queue is not something an
     * unattended kiosk has any reason to do.
     */
    public function destroy(Request $request, string $ticketId): JsonResponse
    {
        $ticketId = LegacyPath::int($ticketId, 'ticket_id');

        $this->requireSameSite($request);

        CallActor::resolve($request, admin: true);

        $deleted = DB::connection()->delete(
            "DELETE FROM queue_tickets WHERE id = ? AND status = 'waiting'",
            [$ticketId],
        );

        if ($deleted === 0) {
            throw LegacyHttpException::detail(404, 'فقط نوبت‌های در انتظار را می‌توان حذف کرد.');
        }

        return $this->ok(['message' => 'نوبت از صف حذف شد.']);
    }

    /**
     * `DELETE /api/queue` — clear every waiting ticket, from any date.
     *
     * `deleted_count` is in the body because the console confirms with a number, and the
     * message embeds it **with Latin digits** — the Python interpolated an `int`, and
     * `to_persian_numbers` was never applied.  The kiosk's own screens Persianise; this
     * endpoint has always answered with `42 نوبت از صف حذف شد.` and still does.
     */
    public function destroyAll(Request $request): JsonResponse
    {
        $this->requireSameSite($request);

        CallActor::resolve($request, admin: true);

        $deleted = max(0, DB::connection()->delete("DELETE FROM queue_tickets WHERE status = 'waiting'"));

        return $this->ok([
            'deleted_count' => $deleted,
            'message' => $deleted.' نوبت از صف حذف شد.',
        ]);
    }

    // ── The kiosk edit flow (اصلاح پذیرش) ───────────────────────────────────

    /**
     * `GET /api/queue/ticket/{ticket_number}` — the details behind a printed number.
     *
     * Carries a name, a national id, a phone number and the insurance fields, so
     * {@see QueuePii} runs first — after path validation, which is where FastAPI puts it.
     *
     * The number is continuous and therefore effectively unique, but old rows are
     * day-scoped and a number may repeat across days.  The sort resolves that: an open
     * ticket wins, then the newest, so the edit lands on the row that is actually live.
     */
    public function showTicket(Request $request, string $ticketNumber): JsonResponse
    {
        $ticketNumber = LegacyPath::int($ticketNumber, 'ticket_number');

        QueuePii::assert($request, 'queue-ticket-read');

        $row = DB::connection()->selectOne(
            'SELECT TOP 1 id, ticket_number, ticket_date, status, service,
                    patient_name, patient_age, patient_national_id, patient_phone,
                    insurance_base, insurance_extra
             FROM queue_tickets
             WHERE ticket_number = ?
             ORDER BY CASE WHEN status IN (\'waiting\', \'called\') THEN 0 ELSE 1 END,
                      created_at DESC,
                      id DESC',
            [$ticketNumber],
        );

        if ($row === null) {
            throw LegacyHttpException::detail(404, 'نوبتی با این شماره یافت نشد.');
        }

        $ticket = LegacySerializer::row('queue_tickets', $row) ?? [];

        // `ticket_date` is popped and re-assigned, then `persian_number` appended — the
        // two moves the Python makes, in that order.
        $date = (string) ($ticket['ticket_date'] ?? '');
        unset($ticket['ticket_date']);
        $ticket['ticket_date'] = $date;
        $ticket['persian_number'] = PersianText::toPersianDigits((string) $ticket['ticket_number']);

        return $this->ok(['ticket' => $ticket]);
    }

    /**
     * `PUT /api/queue/ticket/{ticket_number}` — edit the patient details of a waiting
     * ticket.
     *
     * `EditTicketRequest` is an all-optional pydantic model, so a body is **required** and
     * an absent one is a `missing` error against `["body"]`, while `{}` passes validation
     * and is then refused by the handler with `400 هیچ فیلدی برای اصلاح ارسال نشد.`  The
     * two refusals have different statuses and different shapes, and both are reachable
     * from the kiosk's save button.
     *
     * Every value is stripped and then clipped to its column width — strip first, clip
     * second, because the Python wrote `.strip()[:200]` and not the other way round.
     */
    public function editTicket(Request $request, string $ticketNumber): JsonResponse
    {
        $ticketNumber = LegacyPath::int($ticketNumber, 'ticket_number');

        $body = LegacyBody::validateJson($request->getContent(), [
            'patient_name' => LegacyQuery::nullableString(),
            'patient_age' => LegacyQuery::nullableString(),
            'patient_national_id' => LegacyQuery::nullableString(),
            'patient_phone' => LegacyQuery::nullableString(),
            'insurance_base' => LegacyQuery::nullableString(),
            'insurance_extra' => LegacyQuery::nullableString(),
        ]);

        QueuePii::assert($request, 'queue-ticket-write');

        $row = DB::connection()->selectOne(
            'SELECT TOP 1 id, status FROM queue_tickets
             WHERE ticket_number = ?
             ORDER BY CASE WHEN status IN (\'waiting\', \'called\') THEN 0 ELSE 1 END,
                      created_at DESC,
                      id DESC',
            [$ticketNumber],
        );

        if ($row === null) {
            throw LegacyHttpException::detail(404, 'نوبتی با این شماره یافت نشد.');
        }

        if ((string) $row->status !== QueueTicket::STATUS_WAITING) {
            throw LegacyHttpException::detail(400, 'فقط نوبت‌های در انتظار قابل اصلاح هستند.');
        }

        // Column widths, in the declaration order the Python built its UPDATE from.
        $limits = [
            'patient_name' => 200,
            'patient_age' => 3,
            'patient_national_id' => 10,
            'patient_phone' => 11,
            'insurance_base' => 100,
            'insurance_extra' => 100,
        ];

        $sets = [];
        $values = [];

        foreach ($limits as $column => $limit) {
            if ($body[$column] === null) {
                continue;
            }

            $sets[] = "{$column} = ?";
            // `.strip()[:N]` — strip, then clip.  The order is observable for a value that
            // is long *and* padded, and the Python wrote it this way round.
            $values[] = mb_substr(LegacyWhitespace::strip($body[$column]), 0, $limit);
        }

        if ($sets === []) {
            throw LegacyHttpException::detail(400, 'هیچ فیلدی برای اصلاح ارسال نشد.');
        }

        $values[] = (int) $row->id;

        DB::connection()->update(
            'UPDATE queue_tickets SET '.implode(', ', $sets).' WHERE id = ?',
            $values,
        );

        return $this->ok(['message' => 'اطلاعات نوبت با موفقیت اصلاح شد.']);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * `origin_is_same_site(request)` — the check the Python repeats inline in five of
     * these handlers.
     *
     * It answers `{"detail": …}` with a 403, which is `LegacyHttpException::crossSite()`.
     * No log line, because the source does not log here — the two guards that *are*
     * factored out do.
     */
    private function requireSameSite(Request $request): void
    {
        if (! LegacyOrigin::isSameSite($request)) {
            throw LegacyHttpException::crossSite();
        }
    }

    /**
     * The `reception_call` payload for a *queue* ticket.
     *
     * It differs from a reception call in two ways the display reads: `message` and `voice`
     * are the **same** string (a reception call speaks the number, this one leads with
     * «نوبت»), and `is_queue_ticket` is `true`, which is how the screen decides whether to
     * show a department or a plain number.
     *
     * `number` is a **string** here, unlike `take()`'s `ticket_number` integer — the Python
     * built it with `str(row[0]).strip()` and published that.
     */
    private function queueCallData(string $number, string $department): array
    {
        $persian = PersianText::toPersianDigits($number);
        $sentence = 'نوبت '.$persian.'، لطفاً به بخش '.$department.' مراجعه کنید.';

        return $this->receptionCallData(
            $number,
            $department,
            isTest: false,
            extra: ['is_queue_ticket' => true],
            messageOverride: $sentence,
            voiceOverride: $sentence,
        );
    }

    /**
     * `str(x or "").strip()[:N]` for each patient field.
     *
     * Truncate, never reject: a visitor typing a long name into the kiosk gets a clipped
     * record rather than an error screen, and the column widths are what decide where the
     * clip happens.
     *
     * @return array{name: string, age: string, national_id: string, phone: string, insurance_base: string, insurance_extra: string}
     */
    private function patientValues(array $patient): array
    {
        $clip = fn (mixed $value, int $limit): string => mb_substr(
            LegacyWhitespace::strip(self::pythonString($value)),
            0,
            $limit,
        );

        return [
            'name' => $clip($patient['name'] ?? null, 200),
            'age' => $clip($patient['age'] ?? null, 3),
            'national_id' => $clip($patient['national_id'] ?? null, 10),
            'phone' => $clip($patient['phone'] ?? null, 11),
            'insurance_base' => $clip($patient['insurance_base'] ?? null, 100),
            'insurance_extra' => $clip($patient['insurance_extra'] ?? null, 100),
        ];
    }

    /**
     * `bool(x)` in Python — which does **not** agree with PHP about the string `"0"`.
     *
     * `if body.get("service")` is what the Python wrote, and Python reads `"0"` as a
     * non-empty string and so as true, while PHP reads it as false.  A service or a field
     * numbered `0` would therefore fall back to the default here and keep its value there.
     * The only other disagreement PHP has with Python is the empty array, handled below.
     */
    private static function isTruthy(mixed $value): bool
    {
        if (is_string($value)) {
            return $value !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return $value != 0;
        }

        return $value !== null;
    }

    /** `str(x or "")` — Python's truthiness first, then Python's `str()`. */
    private static function pythonString(mixed $value): string
    {
        return self::isTruthy($value) ? self::pythonStr($value) : '';
    }

    /**
     * `str()` for the scalars a JSON body can carry.
     *
     * `str(True)` is `"True"`, not `"1"` — PHP's cast would silently change a stored
     * value.  An array has no Python `str()` worth reproducing here (it would be a repr of
     * arbitrary structure into a column), so it stringifies as empty.
     */
    private static function pythonStr(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }

        if (is_array($value)) {
            return '';
        }

        return (string) $value;
    }
}
