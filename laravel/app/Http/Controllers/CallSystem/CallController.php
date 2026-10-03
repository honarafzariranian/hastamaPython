<?php

namespace App\Http\Controllers\CallSystem;

use App\Support\Legacy\CallActor;
use App\Support\Legacy\LegacyBody;
use App\Support\Legacy\LegacyDisplayText;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyPath;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use App\Support\Legacy\PersianText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `/api/calls/*` — the reception call surface, the TV displays and the waiting queue.
 *
 * Ported from `app/api/routes/call_system.py`.  Every route here is one of three kinds,
 * and the kind decides the guards:
 *
 * * **kiosk writes** (`POST /api/calls`, `/repeat`, `/remove`, the waiting queue, the test
 *   buttons…) — `kiosk.write:<bucket>` middleware: the same-site check and the per-IP
 *   limit, then `_actor(admin=True, required=False)`, which is what lets the kiosk work
 *   with no session and records its calls against `guest`.
 * * **administrator writes** (`DELETE /api/calls/recent`) — the same guard, then
 *   `_actor(admin=True, required=True)`, which does refuse.
 * * **reads** (`/display-queue`, `/waiting-queue`, `/audio-status`, `/status`) — no guard
 *   at all, because the displays and the kiosk read them without a session.
 *
 * The guards raise FastAPI's `{"detail": …}` bodies; see `LegacyHttpException`.
 *
 * ### Where this deliberately differs from the Python
 *
 * FastAPI validates the declared **body model before the handler runs**, so a body that
 * fails `CallInput` is a 422 *before* `_guard_kiosk_write` gets a chance to answer 403.
 * Here the middleware runs first, so a cross-site request carrying an invalid body gets
 * 403 where the running server answers 422.  Both are refusals of the same request and
 * neither discloses anything — the same trade-off already recorded for the
 * `master_admin` routes in `MIGRATION_STATUS.md`, and restructuring it would mean
 * validating a pydantic model from inside middleware.
 *
 * `POST /api/calls/remove` reads its body with no `try`/`except` in the Python, so a
 * malformed body raises an unhandled `JSONDecodeError` and FastAPI answers **500**.  Here
 * it is a 422 with the module's own `شماره مورد نظر را وارد کنید.`.  That is a difference
 * in an error path that no caller can depend on, recorded rather than reproduced.
 */
final class CallController extends CallSystemController
{
    /** `Path(__file__).parents[3] / "app/static/audio/sample_call/fa-IR-DilaraNeural"`. */
    private const AUDIO_DIR = 'audio/sample_call/fa-IR-DilaraNeural';

    /** `audio_status` reports against this, whether the files exist or not. */
    private const AUDIO_EXPECTED = 2000;

    /** A file smaller than this is a placeholder, not an audio clip. */
    private const AUDIO_MIN_BYTES = 100;

    // ── Creating calls ───────────────────────────────────────────────────────

    /** `POST /api/calls` */
    public function store(Request $request): JsonResponse
    {
        // The body is a required pydantic model, so it is validated **from the raw body**:
        // a missing or non-object body is reported against `loc: ["body"]`, not against
        // the first field.  See `LegacyBody::validateJson`.
        $body = LegacyBody::validateJson($request->getContent(), [
            'reception_number' => LegacyQuery::requiredString(minLength: 1, maxLength: 50),
            'department' => LegacyQuery::string(default: 'نمونه‌گیری', maxLength: 100),
        ], trim: ['reception_number']);

        $username = CallActor::resolve($request, admin: true, required: false);
        $number = $this->validateReceptionNumber($body['reception_number']);
        $department = LegacyDisplayText::department($body['department']);

        // Built **before** the write, because the Python stamped `now` at the top of the
        // handler and the displays have been receiving that stamp ever since.  Recomputing
        // it after the INSERT would move the timestamp forward by the length of the write.
        $data = $this->receptionCallData($number, $department, isTest: false);

        try {
            $this->record($number, $department, $username);
        } catch (Throwable $exception) {
            report($exception);

            throw LegacyHttpException::detail(500, 'فراخوان انجام نشد. لطفاً دوباره تلاش کنید.');
        }

        $sent = $this->display->send(['type' => 'reception_call', 'data' => $data]);

        return $this->ok([
            'message' => 'فراخوان با موفقیت ارسال شد.',
            'display_count' => $sent,
            'data' => $data,
        ]);
    }

    /** `POST /api/calls/repeat` */
    public function repeat(Request $request): JsonResponse
    {
        $username = CallActor::resolve($request, admin: true, required: false);

        $row = DB::connection()->selectOne(
            'SELECT TOP 1 reception_number, department, called_at
             FROM reception_calls WHERE is_test = 0 ORDER BY called_at DESC'
        );

        if ($row === null) {
            throw LegacyHttpException::detail(404, 'فراخوانی برای تکرار وجود ندارد.');
        }

        // Trimmed but **not** re-validated: the value came out of the database, and the
        // Python trusted it the same way.  A row that predates the length check is
        // repeated verbatim rather than refused.
        $number = trim((string) $row->reception_number);
        $department = trim((string) $row->department);

        $data = $this->receptionCallData($number, $department, isTest: false);

        $this->record($number, $department, $username);

        $sent = $this->display->send(['type' => 'reception_call', 'data' => $data]);

        return $this->ok([
            'message' => 'آخرین فراخوان تکرار شد.',
            'display_count' => $sent,
            'data' => $data,
        ]);
    }

    // ── Test buttons ─────────────────────────────────────────────────────────

    /** `POST /api/calls/test-display` */
    public function testDisplay(Request $request): JsonResponse
    {
        CallActor::resolve($request, admin: true, required: false);

        $data = $this->receptionCallData(
            '۱۲۳',
            'نمونه‌گیری',
            isTest: true,
            messageOverride: 'این یک پیام آزمایشی است.',
            voiceOverride: 'این یک پیام آزمایشی است.',
        );

        return $this->ok([
            'message' => 'پیام آزمایشی ارسال شد.',
            'display_count' => $this->display->send(['type' => 'reception_call', 'data' => $data]),
        ]);
    }

    /**
     * `POST /api/calls/test-voice`
     *
     * A voice-only event: every visible field is empty, so the display shows nothing and
     * only speaks.  The success message carries a **leading space** (`" تست صدا ارسال
     * شد."`), which is in the running application and is reproduced rather than tidied —
     * the kiosk prints it verbatim.
     */
    public function testVoice(Request $request): JsonResponse
    {
        CallActor::resolve($request, admin: true, required: false);

        $data = $this->receptionCallData(
            '',
            '',
            isTest: true,
            messageOverride: '',
            voiceOverride: 'این یک پیام آزمایشی است.',
        );

        return $this->ok([
            'message' => ' تست صدا ارسال شد.',
            'display_count' => $this->display->send(['type' => 'reception_call', 'data' => $data]),
        ]);
    }

    /**
     * `POST /api/calls/audio-activated`
     *
     * The legacy raw WebSocket let the TV send one client event back upstream:
     * `{"type":"audio_activated"}`. Laravel's display transport is Reverb,
     * so the browser reports the activation through a same-site kiosk write and
     * the server rebroadcasts the original event object to both channels.
     */
    public function audioActivated(Request $request): JsonResponse
    {
        CallActor::resolve($request, admin: true, required: false);

        return $this->ok([
            'message' => 'فعال‌سازی صدا ثبت شد.',
            'display_count' => $this->display->send(['type' => 'audio_activated', 'data' => []]),
        ]);
    }

    /** `POST /api/calls/test-audio?number=…` */
    public function testAudio(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'number' => LegacyQuery::int(default: 1),
        ]);

        CallActor::resolve($request, admin: true, required: false);

        $number = $params['number'];

        // The Python signature declares no bound on `number`, so this is a handler check —
        // and it is a 422 with the module's own message rather than pydantic's.
        if ($number < 1 || $number > self::AUDIO_EXPECTED) {
            throw LegacyHttpException::detail(422, 'شماره باید بین ۱ تا ۲۰۰۰ باشد.');
        }

        $audioAvailable = $this->audioFileExists($number);
        $department = 'نمونه‌گیری';
        $persian = PersianText::toPersianDigits((string) $number);

        $data = $this->receptionCallData(
            (string) $number,
            $department,
            isTest: true,
            extra: ['audio_available' => $audioAvailable],
            messageOverride: 'تست صدا -- فراخوان شماره '.$persian,
            voiceOverride: 'شماره '.$persian.'، لطفاً به بخش '.$department.' مراجعه کنید.',
        );

        return $this->ok([
            'message' => 'تست صدا ارسال شد.'.($audioAvailable ? '' : ' (فایل صوتی موجود نیست)'),
            'display_count' => $this->display->send(['type' => 'reception_call', 'data' => $data]),
            'audio_available' => $audioAvailable,
        ]);
    }

    /**
     * `GET /api/calls/audio-status`
     *
     * Deliberately publishes **no path**: the Python used to return the absolute
     * filesystem location of the audio directory, and this endpoint is reachable without
     * a session, so it leaked the server's internal layout.  The count is all the display
     * needs, and the fixed `total_expected` (2000) is what the client divides by.
     */
    public function audioStatus(): JsonResponse
    {
        $directory = $this->legacyStaticPath(self::AUDIO_DIR);

        if (! is_dir($directory)) {
            return $this->ok([
                'total_files' => 0,
                'total_expected' => self::AUDIO_EXPECTED,
                'exists' => false,
            ]);
        }

        $count = 0;

        foreach (glob($directory.'/*.mp3') ?: [] as $file) {
            if (is_file($file) && filesize($file) > self::AUDIO_MIN_BYTES) {
                $count++;
            }
        }

        return $this->ok([
            'total_files' => $count,
            'total_expected' => self::AUDIO_EXPECTED,
            'exists' => true,
        ]);
    }

    // ── The display ──────────────────────────────────────────────────────────

    /** `GET /api/calls/display-queue` — what is on the wall right now. */
    public function displayQueue(): JsonResponse
    {
        return $this->ok(['queue' => $this->displayQueue->all()]);
    }

    /**
     * `POST /api/calls/reset-display` — clear the wall, and the state behind it.
     *
     * Both halves matter: the broadcast blanks the screens that are listening, the table
     * delete stops a screen that reloads from painting the numbers back.
     */
    public function resetDisplay(): JsonResponse
    {
        $this->displayQueue->clear();

        return $this->ok([
            'message' => 'تمام شماره‌ها از نمایشگر پاک شد.',
            'display_count' => $this->display->send(['type' => 'reset_display', 'data' => []]),
        ]);
    }

    /** `POST /api/calls/refresh-display` — make every kiosk browser reload its page. */
    public function refreshDisplay(Request $request): JsonResponse
    {
        CallActor::resolve($request, admin: true, required: false);

        $sent = $this->display->send(['type' => 'refresh_display', 'data' => []]);

        // `real_displays` excludes the management page's own preview iframe: an operator
        // pressing this wants to know how many *screens* reacted, not how many sockets.
        return $this->ok([
            'message' => 'دستور رفرش به نمایشگر ارسال شد.',
            'display_count' => $sent,
            'real_displays' => $this->display->counts()['real'],
        ]);
    }

    /** `POST /api/calls/remove` — take one number off the wall. */
    public function removeCall(Request $request): JsonResponse
    {
        CallActor::resolve($request, admin: true, required: false);

        $body = $this->jsonObject($request);
        $number = trim((string) ($body['number'] ?? ''));

        if ($number === '') {
            throw LegacyHttpException::detail(422, 'شماره مورد نظر را وارد کنید.');
        }

        $this->displayQueue->remove($number);

        $persian = PersianText::toPersianDigits($number);

        return $this->ok([
            'message' => 'شماره '.$persian.' از نمایشگر حذف شد.',
            'display_count' => $this->display->send([
                'type' => 'remove_call',
                'data' => ['number' => $number, 'persian_number' => $persian],
            ]),
        ]);
    }

    /** `GET /api/calls/recent` */
    public function recent(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'limit' => LegacyQuery::int(default: 20),
        ]);

        CallActor::resolve($request, admin: true, required: false);

        // `min(max(limit, 1), 100)` — clamped, not rejected.  The Python declared `limit`
        // as a plain `int`, so an out-of-range value was silently narrowed rather than
        // refused, and the console's "rows" selector relies on that.
        $limit = max(1, min(100, $params['limit']));

        // `TOP (?)` needs an **integer** binding: the SQL Server driver rejects a string
        // there with "The number of rows … must be an integer".
        $rows = DB::connection()->select(
            'SELECT TOP (?) reception_number, department, called_by, called_at
             FROM reception_calls WHERE is_test = 0 ORDER BY called_at DESC',
            [$limit],
        );

        $calls = [];

        foreach ($rows as $row) {
            $call = LegacySerializer::row('reception_calls', $row);

            // In-place assignment, then an append — so `persian_number` stays last and
            // `called_at` keeps its column position, which is the order the console
            // renders its table columns from.
            $call['called_at'] = LegacySerializer::isoUtcZ($call['called_at'] ?? null);
            $call['persian_number'] = PersianText::toPersianDigits((string) $call['reception_number']);

            $calls[] = $call;
        }

        return $this->ok(['calls' => $calls]);
    }

    /**
     * `DELETE /api/calls/recent` — wipe the call history.
     *
     * The one call-system route that genuinely requires an administrator: it is
     * destructive and it is not something a kiosk screen has any reason to do.
     */
    public function clearRecent(Request $request): JsonResponse
    {
        CallActor::resolve($request, admin: true, required: true);

        $deleted = DB::connection()->delete('DELETE FROM reception_calls');

        return $this->ok([
            'deleted_count' => max(0, $deleted),
            'message' => 'تاریخچه فراخوان‌ها پاک شد.',
        ]);
    }

    /** `GET /api/calls/status` — how many screens are listening. */
    public function status(): JsonResponse
    {
        $counts = $this->display->counts();

        return $this->ok([
            'connected_displays' => $counts['connected'],
            'real_displays' => $counts['real'],
            'preview_displays' => $counts['preview'],
        ]);
    }

    // ── The waiting queue ────────────────────────────────────────────────────

    /**
     * `GET /api/calls/waiting-queue`
     *
     * `status = 'waiting'` only, and ordered by `id` rather than by position: this queue is
     * a list of people physically present, so the order is arrival order and nothing ever
     * renumbers it.
     *
     * Key order is the Python's, including the two `pop`s that move `created_at` and
     * `called_at` to the end when they are re-formatted.
     */
    public function waitingQueue(): JsonResponse
    {
        $rows = DB::connection()->select(
            "SELECT id, reception_number, department, added_by, status, created_at, called_at
             FROM waiting_queue WHERE status = 'waiting' ORDER BY id ASC"
        );

        $items = [];

        foreach ($rows as $row) {
            $item = LegacySerializer::row('waiting_queue', $row);

            unset($item['created_at'], $item['called_at']);

            $item['persian_number'] = PersianText::toPersianDigits((string) $item['reception_number']);
            $item['created_at'] = LegacySerializer::isoUtcZ($row->created_at ?? null);
            $item['called_at'] = LegacySerializer::isoUtcZ($row->called_at ?? null);

            $items[] = $item;
        }

        return $this->ok(['items' => $items]);
    }

    /** `POST /api/calls/waiting-queue` */
    public function addToWaitingQueue(Request $request): JsonResponse
    {
        $username = CallActor::resolve($request, admin: true, required: false);

        $body = $this->requiredJsonObject($request);

        // `str(body.get("number", ""))` — a number sent as a JSON integer is stringified
        // rather than refused, which is what the kiosk's own form does when a barcode
        // scanner types into it.
        $number = $this->validateReceptionNumber((string) ($body['number'] ?? ''));
        $department = LegacyDisplayText::department($body['department'] ?? 'نمونه‌گیری');

        // `insertGetId` runs `INSERT …; select scope_identity() as id` on SQL Server, which
        // is the `SELECT SCOPE_IDENTITY()` the Python issued by hand.
        $id = DB::table('waiting_queue')->insertGetId([
            'reception_number' => $number,
            'department' => $department,
            'added_by' => $username,
        ]);

        return $this->ok([
            'message' => 'شماره '.PersianText::toPersianDigits($number).' به صف اضافه شد.',
            'id' => (int) $id,
        ]);
    }

    /**
     * `DELETE /api/calls/waiting-queue/{item_id}`
     *
     * Note the identity check is **not** `_actor`: the Python read the session directly and
     * answered `{"success": false, "error": "لاگین نکرده‌اید."}` with a 401 — the only
     * `success`-shaped refusal in the module, and the shape the kiosk's fetch wrapper
     * recognises.
     */
    public function removeFromWaitingQueue(Request $request, string $itemId): JsonResponse
    {
        // FastAPI validates the declared `item_id: int` **before** it calls the handler, so
        // this runs ahead of the session check below — binding it to `int` instead made
        // PHP raise a `TypeError` and answer 500 with a stack trace.
        $itemId = LegacyPath::int($itemId, 'item_id');

        $username = $request->session()->get('username');

        if (! $username) {
            return response()->json(['success' => false, 'error' => 'لاگین نکرده‌اید.'], 401);
        }

        DB::connection()->delete('DELETE FROM waiting_queue WHERE id = ?', [$itemId]);

        return $this->ok(['message' => 'از صف حذف شد.']);
    }

    /** `POST /api/calls/waiting-queue/{item_id}/call` — call the next person. */
    public function callFromWaitingQueue(Request $request, string $itemId): JsonResponse
    {
        $itemId = LegacyPath::int($itemId, 'item_id');

        $username = CallActor::resolve($request, admin: true, required: false);

        $row = DB::connection()->selectOne(
            "SELECT reception_number, department FROM waiting_queue
             WHERE id = ? AND status = 'waiting'",
            [$itemId],
        );

        if ($row === null) {
            throw LegacyHttpException::detail(404, 'مورد مورد نظر یافت نشد.');
        }

        $number = trim((string) $row->reception_number);
        $department = trim((string) $row->department);

        DB::connection()->update(
            "UPDATE waiting_queue SET status = 'called', called_at = SYSUTCDATETIME() WHERE id = ?",
            [$itemId],
        );

        $this->record($number, $department, $username);

        $data = $this->receptionCallData($number, $department, isTest: false);

        return $this->ok([
            'message' => 'فراخوان شماره '.PersianText::toPersianDigits($number).' ارسال شد.',
            'display_count' => $this->display->send(['type' => 'reception_call', 'data' => $data]),
        ]);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    /**
     * The write every call path shares: history row, then the display queue.
     *
     * Kept together because they are one operation in the Python — `_queue_remove` before
     * `_queue_add` is what makes a repeated call move to the front instead of appearing
     * twice, and splitting them across handlers is how that invariant gets lost.
     */
    private function record(string $number, string $department, string $username): void
    {
        DB::connection()->insert(
            'INSERT INTO reception_calls (reception_number, department, called_by, is_test, called_at)
             VALUES (?, ?, ?, 0, SYSUTCDATETIME())',
            [$number, $department, $username],
        );

        $this->displayQueue->remove($number);
        $this->displayQueue->add($number, $department, $username);
    }

    /** `{number:04d}.mp3`, present and not a placeholder. */
    private function audioFileExists(int $number): bool
    {
        $file = $this->legacyStaticPath(self::AUDIO_DIR.'/'.sprintf('%04d', $number).'.mp3');

        return is_file($file) && filesize($file) > self::AUDIO_MIN_BYTES;
    }
}
