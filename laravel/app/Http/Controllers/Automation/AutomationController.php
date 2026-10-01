<?php

namespace App\Http\Controllers\Automation;

use App\Http\Controllers\Controller;
use App\Support\Automation\AutomationQuery;
use App\Support\Automation\ConversationCreate;
use App\Support\Automation\PrivateAttachmentStore;
use App\Support\Legacy\LegacyBody;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyParam;
use App\Support\Legacy\LegacyPath;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use App\Support\Legacy\LegacyValidationException;
use App\Support\Legacy\LegacyWhitespace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `/api/automation/*` — internal automation conversations: a subject, a list of
 * participants, messages, attachments, and a request/approve cycle for reopening a
 * conversation that has been closed.
 *
 * Ported from `app/api/routes/automation.py` (handlers) and `app/services/automation.py`
 * (the SQL).  Three rules decide the shape of every method here, and each is the Python's
 * rather than a Laravel convention:
 *
 * * **Validation runs before identity.**  FastAPI validates path parameters, declared
 *   query parameters and the body model before it enters the handler, so
 *   `POST /api/automation/abc/messages` answers `422 int_parsing` to an anonymous
 *   caller rather than `401`.  Every method therefore opens with {@see LegacyPath} and
 *   its body/query declarations, and only then calls {@see self::actor()}.
 * * **Two errors have no `except` at all.**  `get_conversation` and `download_attachment`
 *   wrap their work in `try/finally` with no handler, so any database failure escapes to
 *   Starlette's server-error middleware and answers a **plain-text** `500` — `Internal
 *   Server Error`, not `{"detail": …}`.  Every other handler routes its exceptions
 *   through `_error()`, which maps the Python's three exception types to `403`/`404`/
 *   `422` and everything else to `500` with the module's own Persian message.
 * * **A refusal is a message, not a framework body.**  `LegacyHttpException` renders as
 *   `{"detail": …}` through the callback in `bootstrap/app.php`, which is what the
 *   internal-automation panel reads.
 *
 * ### Ordering inside a rejection
 *
 * FastAPI collects **all** parameter failures into one `detail` list, in the order the
 * parameters were declared, and answers them together: path first, then query, then
 * body.  `POST /api/automation/1/attachments?message_id=abc` with no file is therefore
 * `[query message_id, body file]`, not two round trips — so the upload handler merges
 * the three checks into one `LegacyValidationException` rather than throwing on the first.
 *
 * ### Identity
 *
 * `_actor()` in the Python reads three session values and nothing else: the trimmed
 * username (`401` when empty), `is_admin is True`, `is_master_admin is True`.  The strict
 * comparison matters — a session flag stored as `"1"` must not pass as an administrator —
 * and it is the same rule `CallActor` applies to the call-system routes.
 */
final class AutomationController extends Controller
{
    public function __construct(private readonly PrivateAttachmentStore $attachments) {}

    // ── Conversations ───────────────────────────────────────────────────────

    /**
     * `GET /api/automation` — every conversation the session may see.
     *
     * An ordinary participant sees only conversations they are in; an administrator and
     * a master administrator see all of them, because `_allowed()` is a bypass rather
     * than a filter in the Python too.  The only endpoint whose exception is **logged**
     * — `logger.exception("failed to list internal automation conversations")` — so
     * `report()` is called here and nowhere else in this class.
     */
    public function index(Request $request): JsonResponse
    {
        [$actor, $isAdmin, $isMasterAdmin] = self::actor($request);

        try {
            return response()->json([
                'items' => $this->listConversations($actor, $isAdmin, $isMasterAdmin),
            ]);
        } catch (LegacyHttpException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            throw self::failure();
        }
    }

    /**
     * `POST /api/automation` — create a conversation and its first message, `201`.
     *
     * The body is the {@see ConversationCreate} model, so a malformed request is answered
     * `422` **before** the 401 an anonymous session would otherwise produce — validation
     * is the first thing FastAPI does, and getting it the other way round changes the
     * answer to every unauthenticated write from the front-end's point of view.
     *
     * The creator is always the first participant, and participants are de-duplicated
     * after stripping: `list(dict.fromkeys([username] + [...]))` keeps the first
     * occurrence, so naming yourself is a no-op rather than a duplicate row.
     */
    public function create(Request $request): JsonResponse
    {
        $payload = ConversationCreate::fromRequest($request);

        [$actor] = self::actor($request);

        try {
            $result = $this->createConversation($actor, $payload);

            if ($result === null || empty($result['id'])) {
                // `raise LookupError('گفتگوی ایجادشده قابل بازیابی نیست.')` — the route's
                // own check, mapped by `_error()` to 404.
                throw LegacyHttpException::detail(404, 'گفتگوی ایجادشده قابل بازیابی نیست.');
            }

            return response()->json($result, 201);
        } catch (LegacyHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw self::failure();
        }
    }

    /**
     * `GET /api/automation/{conversation_id}` — one conversation, messages and all.
     *
     * The Python has `try/finally` with **no `except`**: a failure inside `service.get()`
     * escapes to Starlette and answers a plain-text 500.  The 404 for an unknown or
     * invisible conversation is raised *inside* that `try` and is an `HTTPException`, so
     * it still renders as `{"detail": …}` — which is why `LegacyHttpException` is
     * re-thrown rather than converted.
     *
     * An administrator who is not a master administrator and is not a participant gets
     * the conversation **without** `messages` and `attachments` — `service.get()` returns
     * early for that one session shape, and the omission is the point: the ordinary admin
     * UI shows the subject and the status, not the correspondence.
     */
    public function show(Request $request, string $conversationId): Response
    {
        $conversationId = LegacyPath::int($conversationId, 'conversation_id');

        [$actor, $isAdmin, $isMasterAdmin] = self::actor($request);

        try {
            $result = $this->conversation($conversationId, $actor, $isAdmin, $isMasterAdmin);

            if ($result === null) {
                throw LegacyHttpException::detail(404, 'گفتگو پیدا نشد.');
            }

            return response()->json($result);
        } catch (LegacyHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            return self::plainServerError();
        }
    }

    /**
     * `DELETE /api/automation/{conversation_id}` — remove a conversation, `204`.
     *
     * The permission rule is asymmetric and this is it verbatim: a master administrator
     * may delete anything, everyone else must be the creator — and an administrator who
     * is *not* a master is refused **even when they created it** (`not is_master and
     * (is_admin or created_by != username)`).  The stored attachments are removed after
     * the rows, as the Python does, because the cascade has already made the rows
     * unreachable by then.
     */
    public function destroy(Request $request, string $conversationId): Response
    {
        $conversationId = LegacyPath::int($conversationId, 'conversation_id');

        [$actor, $isAdmin, $isMasterAdmin] = self::actor($request);

        try {
            $this->deleteConversation($conversationId, $actor, $isAdmin, $isMasterAdmin);

            return response()->noContent();
        } catch (LegacyHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw self::failure();
        }
    }

    // ── State ───────────────────────────────────────────────────────────────

    /**
     * `POST /api/automation/{conversation_id}/complete` — close a conversation, `204`.
     *
     * There is no existence check: the `UPDATE` simply matches no row for an unknown id
     * and the route still answers `204`, which is what the Python does.  Only `_allowed()`
     * stands between the caller and the write, so a participant can close their own
     * conversation and an administrator can close any of them.
     */
    public function complete(Request $request, string $conversationId): Response
    {
        $conversationId = LegacyPath::int($conversationId, 'conversation_id');

        [$actor, $isAdmin, $isMasterAdmin] = self::actor($request);

        try {
            if (! self::allowed($conversationId, $actor, $isAdmin, $isMasterAdmin)) {
                throw LegacyHttpException::detail(403, 'دسترسی به گفتگو ندارید.');
            }

            DB::connection()->statement(
                'UPDATE automation_conversations SET status = ?, updated_at = SYSUTCDATETIME() WHERE id = ?',
                ['completed', $conversationId],
            );

            return response()->noContent();
        } catch (LegacyHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw self::failure();
        }
    }

    /**
     * `POST /api/automation/{conversation_id}/reopen-request` — ask to reopen, `204`.
     *
     * Two refusals before the insert, both `422`: a conversation nobody else is in
     * cannot be reopened by agreement, and a request that is already pending must not be
     * duplicated.  Both are checked against the *stored* state rather than the request,
     * so replaying the call answers the same refusal.  The request is accompanied by a
     * system message in the thread, which is what makes it visible to the other side.
     */
    public function requestReopen(Request $request, string $conversationId): Response
    {
        $conversationId = LegacyPath::int($conversationId, 'conversation_id');

        [$actor, $isAdmin, $isMasterAdmin] = self::actor($request);

        try {
            if (! self::allowed($conversationId, $actor, $isAdmin, $isMasterAdmin)) {
                throw LegacyHttpException::detail(403, 'دسترسی به گفتگو ندارید.');
            }

            $conversation = DB::connection()
                ->selectOne('SELECT status FROM automation_conversations WHERE id = ?', [$conversationId]);

            if ($conversation === null) {
                throw LegacyHttpException::detail(404, 'گفتگو پیدا نشد.');
            }

            $others = DB::connection()->selectOne(
                'SELECT COUNT(*) AS total FROM automation_participants WHERE conversation_id = ? AND username <> ?',
                [$conversationId, $actor],
            );

            if ((int) $others->total === 0) {
                throw LegacyHttpException::detail(422, 'برای بازگشایی گفتگو، طرف مقابل وجود ندارد.');
            }

            $pending = DB::connection()->selectOne(
                'SELECT id FROM automation_reopen_requests WHERE conversation_id = ? AND requester = ? AND status = ?',
                [$conversationId, $actor, 'pending'],
            );

            if ($pending !== null) {
                throw LegacyHttpException::detail(422, 'درخواست بازگشایی قبلاً ارسال شده است.');
            }

            DB::connection()->transaction(function () use ($conversationId, $actor): void {
                DB::connection()->table('automation_reopen_requests')->insert([
                    'conversation_id' => $conversationId,
                    'requester' => $actor,
                ]);

                DB::connection()->table('automation_messages')->insert([
                    'conversation_id' => $conversationId,
                    'author_username' => $actor,
                    'body' => 'درخواست بازگشایی این گفتگو ارسال شد و منتظر تأیید طرف مقابل است.',
                ]);
            });

            return response()->noContent();
        } catch (LegacyHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw self::failure();
        }
    }

    /**
     * `POST /api/automation/{conversation_id}/reopen-request/{request_id}/approve` —
     * approve a pending request, `204`.
     *
     * The requester may not approve their own request unless they are a master
     * administrator: `if str(row[0]).strip() == username and not is_master`.  A master
     * administrator approving their own request is the documented escape hatch for a
     * conversation whose other side has gone.
     *
     * The approval writes three things in one transaction — the request is resolved, the
     * conversation is reopened, and a system message records who did it.
     */
    public function approveReopen(Request $request, string $conversationId, string $requestId): Response
    {
        $errors = [];
        $conversation = self::path($conversationId, 'conversation_id', $errors);
        $pendingRequest = self::path($requestId, 'request_id', $errors);

        if ($errors !== []) {
            throw new LegacyValidationException($errors);
        }

        [$actor, $isAdmin, $isMasterAdmin] = self::actor($request);

        try {
            if (! self::allowed($conversation, $actor, $isAdmin, $isMasterAdmin)) {
                throw LegacyHttpException::detail(403, 'دسترسی به گفتگو ندارید.');
            }

            $row = DB::connection()->selectOne(
                'SELECT requester FROM automation_reopen_requests WHERE id = ? AND conversation_id = ? AND status = ?',
                [$pendingRequest, $conversation, 'pending'],
            );

            if ($row === null) {
                throw LegacyHttpException::detail(404, 'درخواست بازگشایی پیدا نشد.');
            }

            if (LegacyWhitespace::strip((string) $row->requester) === $actor && ! $isMasterAdmin) {
                throw LegacyHttpException::detail(403, 'درخواست بازگشایی باید توسط طرف مقابل تأیید شود.');
            }

            DB::connection()->transaction(function () use ($pendingRequest, $conversation, $actor): void {
                DB::connection()->statement(
                    'UPDATE automation_reopen_requests SET status = ?, resolved_at = SYSUTCDATETIME() WHERE id = ?',
                    ['approved', $pendingRequest],
                );

                DB::connection()->statement(
                    'UPDATE automation_conversations SET status = ?, updated_at = SYSUTCDATETIME() WHERE id = ?',
                    ['open', $conversation],
                );

                DB::connection()->table('automation_messages')->insert([
                    'conversation_id' => $conversation,
                    'author_username' => $actor,
                    'body' => 'درخواست بازگشایی گفتگو تأیید شد؛ گفتگو دوباره فعال است.',
                ]);
            });

            return response()->noContent();
        } catch (LegacyHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw self::failure();
        }
    }

    // ── Messages and attachments ────────────────────────────────────────────

    /**
     * `POST /api/automation/{conversation_id}/messages` — post a message.
     *
     * The refusal order is the service's and it is observable: an ordinary administrator
     * is refused **before** the conversation is looked at (so an unknown id answers the
     * permission message, not a 500), a non-participant is refused next, and only then is
     * the status read.  A closed conversation answers `403 این گفتگو به پایان رسیده
     * است.`, and a message that is empty or whitespace after stripping answers `422`.
     *
     * The response is the conversation as `service.get()` assembles it for this session,
     * so the caller sees its own message with an `id` already assigned.
     */
    public function addMessage(Request $request, string $conversationId): JsonResponse
    {
        $conversationId = LegacyPath::int($conversationId, 'conversation_id');

        $payload = LegacyBody::validateJson((string) $request->getContent(), [
            'body' => self::messageParam(),
        ]);

        [$actor, $isAdmin, $isMasterAdmin] = self::actor($request);

        try {
            return response()->json(
                $this->createMessage($conversationId, $actor, (string) $payload['body'], $isAdmin, $isMasterAdmin),
            );
        } catch (LegacyHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw self::failure();
        }
    }

    /**
     * `POST /api/automation/{conversation_id}/attachments` — upload a file.
     *
     * Three validations are merged into one `422` (path, then `message_id`, then the
     * file), and only after they all pass does the session matter: `401` for nobody
     * signed in, `403` for an administrator who is not a master administrator — the
     * check that sits in the *route* in the Python, before the bytes are read at all.
     *
     * The bytes are read with the same bound `await file.read(10*1024*1024+1)` used, so
     * an oversized upload is refused by the store rather than by the memory limit; and a
     * refusal from the store is `422`, while a filesystem failure escapes as the plain
     * 500 the Python produces, because the route catches `ValueError` and nothing else.
     *
     * If the row cannot be written the file is removed again — `Path(...).unlink(missing_ok=True)`
     * — so a rejected message id does not leave orphaned bytes behind.
     */
    public function uploadAttachment(Request $request, string $conversationId): Response
    {
        $errors = [];
        $conversation = self::path($conversationId, 'conversation_id', $errors);

        try {
            $params = LegacyQuery::validate($request, [
                'message_id' => AutomationQuery::nullableInt(ge: 1),
            ]);
        } catch (LegacyValidationException $exception) {
            $errors = array_merge($errors, $exception->detail());
            $params = [];
        }

        $file = $request->file('file');

        if ($file === null) {
            $errors[] = [
                'type' => 'missing',
                'loc' => ['body', 'file'],
                'msg' => 'Field required',
                'input' => null,
            ];
        }

        if ($errors !== []) {
            throw new LegacyValidationException($errors);
        }

        [$actor, $isAdmin, $isMasterAdmin] = self::actor($request);

        if ($isAdmin && ! $isMasterAdmin) {
            throw LegacyHttpException::detail(403, 'دسترسی ارسال فایل ندارید.');
        }

        try {
            $payload = self::readUpload($file);
            $metadata = $this->attachments->store(
                $file->getClientOriginalName() ?: 'attachment',
                (string) $file->getClientMimeType(),
                $payload,
            );
        } catch (LegacyHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            return self::plainServerError();
        }

        $metadata['message_id'] = $params['message_id'] ?? null;

        try {
            return response()->json(
                $this->insertAttachment($conversation, $actor, $metadata, $isAdmin, $isMasterAdmin),
            );
        } catch (LegacyHttpException $exception) {
            $this->attachments->forget($metadata['path']);

            throw $exception;
        } catch (Throwable) {
            $this->attachments->forget($metadata['path']);

            throw self::failure();
        }
    }

    /**
     * `GET /api/automation/{conversation_id}/attachments/{attachment_id}` — download.
     *
     * The second of the two handlers with no `except`: an unreachable file, an unreadable
     * file or a failed query escapes to the plain-text 500, while the module's own `404
     * فایل پیدا نشد.` is raised inside the `try` and renders as JSON — for both "no such
     * attachment" and "the row exists but the bytes do not", which the Python keeps as one
     * message.
     *
     * The response headers are Starlette's `FileResponse`: the stored content type (with
     * `; charset=utf-8` appended for `text/*` by the framework, exactly as Starlette
     * appends it), `Content-Disposition` that switches to `filename*` when the name is not
     * ASCII-safe, `Accept-Ranges: bytes`, and an `ETag` of the file's mtime and size.  The
     * one documented difference is the ETag's precision: `st_mtime` carries the fraction
     * and PHP's `filemtime()` does not, so a byte-for-byte diff of that header fails while
     * every other header matches.
     */
    public function downloadAttachment(Request $request, string $conversationId, string $attachmentId): Response
    {
        $errors = [];
        $conversation = self::path($conversationId, 'conversation_id', $errors);
        $attachment = self::path($attachmentId, 'attachment_id', $errors);

        if ($errors !== []) {
            throw new LegacyValidationException($errors);
        }

        [$actor, $isAdmin, $isMasterAdmin] = self::actor($request);

        try {
            $item = $this->attachment($conversation, $attachment, $actor, $isAdmin, $isMasterAdmin);

            if ($item === null) {
                throw LegacyHttpException::detail(404, 'فایل پیدا نشد.');
            }

            $path = $this->attachments->resolve($item['storage_name']);

            if ($path === null) {
                throw LegacyHttpException::detail(404, 'فایل پیدا نشد.');
            }

            return self::fileResponse($path, $item['content_type'], $item['original_name']);
        } catch (LegacyHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            return self::plainServerError();
        }
    }

    // ── Identity ────────────────────────────────────────────────────────────

    /**
     * `_actor(request)` — the trimmed session username and the two strict flags.
     *
     * @return array{0: string, 1: bool, 2: bool} `[username, is_admin, is_master_admin]`
     *
     * @throws LegacyHttpException 401 when the session carries no username.
     */
    private static function actor(Request $request): array
    {
        $username = LegacyWhitespace::strip((string) $request->session()->get('username', ''));

        if ($username === '') {
            throw LegacyHttpException::unauthenticated();
        }

        return [
            $username,
            $request->session()->get('is_admin') === true,
            $request->session()->get('is_master_admin') === true,
        ];
    }

    /**
     * `_allowed(conversation_id, username, is_admin, is_master)`.
     *
     * A master administrator and an administrator both bypass the participant check —
     * `if is_master or is_admin: return True` — which is why an ordinary administrator
     * can read any conversation even though they can neither post to it nor delete it.
     */
    private static function allowed(int $conversationId, string $username, bool $isAdmin, bool $isMasterAdmin): bool
    {
        if ($isMasterAdmin || $isAdmin) {
            return true;
        }

        return self::isParticipant($conversationId, $username);
    }

    /** `_participant(conversation_id, username)` — existence, not identity. */
    private static function isParticipant(int $conversationId, string $username): bool
    {
        return DB::connection()->selectOne(
            'SELECT 1 AS present FROM automation_participants WHERE conversation_id = ? AND username = ?',
            [$conversationId, $username],
        ) !== null;
    }

    // ── Service: conversations ──────────────────────────────────────────────

    /**
     * `AutomationService.list_conversations()`.
     *
     * The two counts are correlated sub-selects rather than joins so that a conversation
     * with no participants still appears, and the ordering is `updated_at DESC, id DESC`
     * — the tiebreak is what makes the list stable when two conversations share a second.
     *
     * @return array<int, array<string, mixed>>
     */
    private function listConversations(string $actor, bool $isAdmin, bool $isMasterAdmin): array
    {
        if ($isMasterAdmin || $isAdmin) {
            $scope = '1=1';
            $bindings = [];
        } else {
            $scope = 'EXISTS (SELECT 1 FROM automation_participants p
                               WHERE p.conversation_id = c.id AND p.username = ?)';
            $bindings = [$actor];
        }

        $rows = DB::connection()->select(
            "SELECT c.id, c.subject, c.created_by, c.created_at, c.updated_at, c.status,
                    (SELECT COUNT(*) FROM automation_participants p WHERE p.conversation_id = c.id) participant_count,
                    (SELECT COUNT(*) FROM automation_messages m WHERE m.conversation_id = c.id) message_count
               FROM automation_conversations c
              WHERE {$scope}
              ORDER BY c.updated_at DESC, c.id DESC",
            $bindings,
        );

        $conversations = [];

        foreach ($rows as $row) {
            $conversation = [
                'id' => (int) $row->id,
                'subject' => (string) $row->subject,
                'created_by' => (string) $row->created_by,
                'created_at' => self::iso($row->created_at),
                'updated_at' => self::iso($row->updated_at),
                'status' => (string) $row->status,
                'participant_count' => (int) $row->participant_count,
                'message_count' => (int) $row->message_count,
            ];

            $conversation['participants'] = self::usernames($conversation['id']);

            $conversations[] = $conversation;
        }

        return $conversations;
    }

    /**
     * `AutomationService.create()` — the conversation, its participants, and the first
     * message, then `get()` on the committed row.
     *
     * The handler's own check runs after pydantic's: a subject of two spaces passes
     * `min_length=2` and fails `not subject.strip()`, which is why this refuses with the
     * module's `422 موضوع و متن معتبر نیست.` where a stricter model would have answered
     * `string_too_short` with a different `loc`.
     *
     * @return array<string, mixed>|null
     */
    private function createConversation(string $actor, ConversationCreate $payload): ?array
    {
        $names = [];

        foreach (array_merge([$actor], $payload->participants) as $participant) {
            $name = LegacyWhitespace::strip($participant);

            if ($name !== '' && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        $subject = LegacyWhitespace::strip($payload->subject);
        $body = LegacyWhitespace::strip($payload->body);

        if ($subject === '' || $body === '' || mb_strlen($subject) > 180 || mb_strlen($body) > 4000) {
            throw LegacyHttpException::detail(422, 'موضوع و متن معتبر نیست.');
        }

        $conversationId = DB::connection()->transaction(function () use ($subject, $actor, $names, $body): int {
            $connection = DB::connection();

            $conversationId = (int) $connection->table('automation_conversations')->insertGetId([
                'subject' => $subject,
                'created_by' => $actor,
            ]);

            foreach ($names as $name) {
                $connection->table('automation_participants')->insert([
                    'conversation_id' => $conversationId,
                    'username' => $name,
                ]);
            }

            $connection->table('automation_messages')->insert([
                'conversation_id' => $conversationId,
                'author_username' => $actor,
                'body' => $body,
            ]);

            return $conversationId;
        });

        return $this->conversation($conversationId, $actor, false, false);
    }

    /**
     * `AutomationService.delete()` — the permission check, the cascade, then the files.
     *
     * The rows go first because `ON DELETE CASCADE` has already removed the participants,
     * messages and attachments with them, and the storage names are read **before** that
     * so the bytes can still be found afterwards.
     */
    private function deleteConversation(int $conversationId, string $actor, bool $isAdmin, bool $isMasterAdmin): void
    {
        $conversation = DB::connection()
            ->selectOne('SELECT created_by FROM automation_conversations WHERE id = ?', [$conversationId]);

        if ($conversation === null) {
            throw LegacyHttpException::detail(404, 'گفتگو پیدا نشد.');
        }

        if (! $isMasterAdmin
            && ($isAdmin || LegacyWhitespace::strip((string) $conversation->created_by) !== $actor)) {
            throw LegacyHttpException::detail(403, 'فقط ایجادکنندهٔ گفتگو می‌تواند آن را حذف کند.');
        }

        $rows = DB::connection()
            ->select('SELECT storage_name FROM automation_attachments WHERE conversation_id = ?', [$conversationId]);

        DB::connection()->transaction(function () use ($conversationId): void {
            DB::connection()->table('automation_conversations')->where('id', $conversationId)->delete();
        });

        foreach ($rows as $row) {
            $this->attachments->delete((string) $row->storage_name);
        }
    }

    /**
     * `AutomationService.get()` — one conversation, or `null` when it is invisible.
     *
     * Key order is the Python's and it is not the order the columns are read in: the
     * SELECT's six columns come first, then `participants`, then `reopen_requests`, and
     * only then — for everyone but a non-participant administrator — `messages` and
     * `attachments`.  `reopen_requests` carries a trailing `updated_at: null` because
     * `_serialized()` writes that key into every row it touches, and a row that never had
     * the column gains it.
     *
     * @return array<string, mixed>|null
     */
    private function conversation(int $conversationId, string $actor, bool $isAdmin, bool $isMasterAdmin): ?array
    {
        if (! self::allowed($conversationId, $actor, $isAdmin, $isMasterAdmin)) {
            return null;
        }

        $row = DB::connection()->selectOne(
            'SELECT id, subject, created_by, created_at, updated_at, status
               FROM automation_conversations WHERE id = ?',
            [$conversationId],
        );

        if ($row === null) {
            return null;
        }

        $result = [
            'id' => (int) $row->id,
            'subject' => (string) $row->subject,
            'created_by' => (string) $row->created_by,
            'created_at' => self::iso($row->created_at),
            'updated_at' => self::iso($row->updated_at),
            'status' => (string) $row->status,
        ];

        $result['participants'] = self::usernames($conversationId);

        $requests = DB::connection()->select(
            'SELECT id, requester, status, created_at
               FROM automation_reopen_requests
              WHERE conversation_id = ? AND status = ?
              ORDER BY id DESC',
            [$conversationId, 'pending'],
        );

        $result['reopen_requests'] = array_map(static fn (object $request): array => [
            'id' => (int) $request->id,
            'requester' => (string) $request->requester,
            'status' => (string) $request->status,
            'created_at' => self::iso($request->created_at),
            'updated_at' => null,
        ], $requests);

        if ($isAdmin && ! $isMasterAdmin && ! self::isParticipant($conversationId, $actor)) {
            return $result;
        }

        $messages = DB::connection()->select(
            'SELECT id, author_username, body, created_at
               FROM automation_messages WHERE conversation_id = ?
              ORDER BY created_at, id',
            [$conversationId],
        );

        $result['messages'] = array_map(static fn (object $message): array => [
            'id' => (int) $message->id,
            'author_username' => (string) $message->author_username,
            'body' => (string) $message->body,
            'created_at' => self::iso($message->created_at),
            'updated_at' => null,
        ], $messages);

        $attachments = DB::connection()->select(
            'SELECT id, message_id, uploaded_by, original_name, content_type, size_bytes, created_at
               FROM automation_attachments WHERE conversation_id = ?
              ORDER BY created_at, id',
            [$conversationId],
        );

        $result['attachments'] = array_map(static fn (object $attachment): array => [
            'id' => (int) $attachment->id,
            'message_id' => $attachment->message_id === null ? null : (int) $attachment->message_id,
            'uploaded_by' => (string) $attachment->uploaded_by,
            'original_name' => (string) $attachment->original_name,
            'content_type' => (string) $attachment->content_type,
            'size_bytes' => (int) $attachment->size_bytes,
            'created_at' => self::iso($attachment->created_at),
            'updated_at' => null,
        ], $attachments);

        return $result;
    }

    // ── Service: messages and attachments ───────────────────────────────────

    /**
     * `AutomationService.add_message()` — the guard order above, then the insert and the
     * conversation's `updated_at` in one transaction.
     *
     * @return array<string, mixed>
     */
    private function createMessage(int $conversationId, string $actor, string $body, bool $isAdmin, bool $isMasterAdmin): array
    {
        if ($isAdmin && ! $isMasterAdmin) {
            throw LegacyHttpException::detail(403, 'دسترسی ارسال پیام ندارید.');
        }

        if (! self::allowed($conversationId, $actor, $isAdmin, $isMasterAdmin)) {
            throw LegacyHttpException::detail(403, 'دسترسی به گفتگو ندارید.');
        }

        $conversation = DB::connection()
            ->selectOne('SELECT status FROM automation_conversations WHERE id = ?', [$conversationId]);

        // The Python indexes `fetchone()[0]` with no null check: a master administrator
        // posting to a conversation that does not exist raises `TypeError`, which
        // `_error()` renders as the module's 500 rather than as a "not found".
        if ($conversation === null) {
            throw LegacyHttpException::detail(500, 'خطا در پردازش گفتگوی خودکار.');
        }

        if ((string) $conversation->status !== 'open') {
            throw LegacyHttpException::detail(403, 'این گفتگو به پایان رسیده است.');
        }

        $body = LegacyWhitespace::strip($body);

        if ($body === '' || mb_strlen($body) > 4000) {
            throw LegacyHttpException::detail(422, 'متن پیام معتبر نیست.');
        }

        DB::connection()->transaction(function () use ($conversationId, $actor, $body): void {
            DB::connection()->table('automation_messages')->insert([
                'conversation_id' => $conversationId,
                'author_username' => $actor,
                'body' => $body,
            ]);

            DB::connection()->statement(
                'UPDATE automation_conversations SET updated_at = SYSUTCDATETIME() WHERE id = ?',
                [$conversationId],
            );
        });

        return $this->conversation($conversationId, $actor, $isAdmin, $isMasterAdmin);
    }

    /**
     * `AutomationService.add_attachment()` — the row for a file already on disk.
     *
     * A `message_id` that is not a message of *this* conversation is a `404`, and the
     * check is against both columns so that another conversation's message id cannot be
     * used to attach a file to this one.
     *
     * @param  array{message_id: int|null, original_name: string, storage_name: string,
     *               content_type: string, size_bytes: int, path: string}  $metadata
     * @return array{id: int, original_name: string}
     */
    private function insertAttachment(int $conversationId, string $actor, array $metadata, bool $isAdmin, bool $isMasterAdmin): array
    {
        if ($isAdmin && ! $isMasterAdmin) {
            throw LegacyHttpException::detail(403, 'دسترسی ارسال فایل ندارید.');
        }

        if (! self::allowed($conversationId, $actor, $isAdmin, $isMasterAdmin)) {
            throw LegacyHttpException::detail(403, 'دسترسی به گفتگو ندارید.');
        }

        if ($metadata['message_id'] !== null) {
            $message = DB::connection()->selectOne(
                'SELECT 1 AS present FROM automation_messages WHERE id = ? AND conversation_id = ?',
                [$metadata['message_id'], $conversationId],
            );

            if ($message === null) {
                throw LegacyHttpException::detail(404, 'پیام مقصد پیدا نشد.');
            }
        }

        $id = (int) DB::connection()->table('automation_attachments')->insertGetId([
            'conversation_id' => $conversationId,
            'message_id' => $metadata['message_id'],
            'uploaded_by' => $actor,
            'original_name' => $metadata['original_name'],
            'storage_name' => $metadata['storage_name'],
            'content_type' => $metadata['content_type'],
            'size_bytes' => $metadata['size_bytes'],
        ]);

        return ['id' => $id, 'original_name' => $metadata['original_name']];
    }

    /**
     * `AutomationService.attachment()` — the download's row, or `null` when the session
     * may not see it.
     *
     * The permission failure and the missing row are the same `null` here, and the route
     * renders both as the one `404 فایل پیدا نشد.`, as the Python does.
     *
     * @return array{id: int, original_name: string, storage_name: string,
     *               content_type: string, size_bytes: int}|null
     */
    private function attachment(int $conversationId, int $attachmentId, string $actor, bool $isAdmin, bool $isMasterAdmin): ?array
    {
        if (! self::allowed($conversationId, $actor, $isAdmin, $isMasterAdmin)) {
            return null;
        }

        $row = DB::connection()->selectOne(
            'SELECT id, original_name, storage_name, content_type, size_bytes
               FROM automation_attachments WHERE id = ? AND conversation_id = ?',
            [$attachmentId, $conversationId],
        );

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'original_name' => (string) $row->original_name,
            'storage_name' => (string) $row->storage_name,
            'content_type' => (string) $row->content_type,
            'size_bytes' => (int) $row->size_bytes,
        ];
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /** The participants of one conversation, `ORDER BY username`, stripped. */
    private static function usernames(int $conversationId): array
    {
        $rows = DB::connection()->select(
            'SELECT username FROM automation_participants WHERE conversation_id = ? ORDER BY username',
            [$conversationId],
        );

        return array_map(
            static fn (object $row): string => LegacyWhitespace::strip((string) $row->username),
            $rows,
        );
    }

    /**
     * One `{int}` path parameter collected into this request's single `detail` list.
     *
     * FastAPI reports a bad path *and* a bad query *and* a bad body together, in the
     * order they were declared — `POST /api/automation/abc/reopen-request/def/approve`
     * answers `[path conversation_id, path request_id]` — so a handler with two path
     * parameters cannot throw on the first one.  `parse()` answers `null` for the value
     * that failed, `error()` answers the detail for it, and `0` is the placeholder the
     * caller never reaches: a recorded error always becomes a `LegacyValidationException`
     * before any of these values is used.
     *
     * @param  array<int, array<string, mixed>>  $errors  Appended to, in place.
     */
    private static function path(string $value, string $name, array &$errors): int
    {
        $parsed = LegacyPath::parse($value);

        if ($parsed === null) {
            $error = LegacyPath::error($value, $name);

            if ($error !== null) {
                $errors[] = $error;
            }

            return 0;
        }

        return $parsed;
    }

    /**
     * `_iso(value)` — a UTC timestamp with a `Z`, or `null` for nothing.
     *
     * The value is passed through the schema-aware serializer first because `pdo_sqlsrv`
     * hands temporal columns back as `DateTime` objects where `pyodbc` handed Python
     * `datetime`s, and `_iso()`'s `isinstance(value, str)` branch would have returned the
     * string unchanged while PHP has no such branch to fall through to.
     */
    private static function iso(mixed $value): ?string
    {
        return LegacySerializer::isoUtcZ(LegacySerializer::value('datetime2', $value));
    }

    /**
     * `_error(exc)` — the three mapped exception types, and the module's own 500.
     *
     * The service methods raise `LegacyHttpException` directly with the status `_error()`
     * would have chosen, so the only case this helper builds is the default one: **any**
     * other throwable becomes `500 {"detail": "خطا در پردازش گفتگوی خودکار."}`.
     *
     * Deliberately without `report()`: FastAPI renders a handled `HTTPException(500)`
     * without a traceback, and only `list_conversations` logged its exception.
     */
    private static function failure(): LegacyHttpException
    {
        return LegacyHttpException::detail(500, 'خطا در پردازش گفتگوی خودکار.');
    }

    /**
     * `body: str = Field(min_length=1, max_length=4000)` on `MessageCreate`.
     *
     * The same declaration as the conversation body's second field, which is why it is
     * spelled here rather than shared: the two models are separate classes in the Python
     * and a change to one is not a change to the other.
     */
    private static function messageParam(): LegacyParam
    {
        return new LegacyParam(
            LegacyParam::STRING,
            required: true,
            minLength: 1,
            maxLength: 4000,
        );
    }

    /**
     * `await file.read(10*1024*1024+1)` — at most one byte past the bound, so an oversized
     * file is recognised by its length rather than by a `stat()` that the multipart parser
     * has already paid for.
     */
    private static function readUpload(UploadedFile $file): string
    {
        $handle = fopen($file->getPathname(), 'rb');

        if ($handle === false) {
            throw new \ErrorException('Unable to read the uploaded file.');
        }

        try {
            $payload = stream_get_contents($handle, PrivateAttachmentStore::READ_LIMIT);
        } finally {
            fclose($handle);
        }

        if ($payload === false) {
            throw new \ErrorException('Unable to read the uploaded file.');
        }

        return $payload;
    }

    /**
     * Starlette's `FileResponse`, header for header.
     *
     * The `Content-Type` is set raw so that the framework appends `; charset=utf-8` for a
     * `text/*` type exactly where Starlette's `init_headers()` appends it.  The
     * `Content-Disposition` switches to RFC 5987 when `quote($name)` would have changed
     * the name — which is the Python's own test — so a Persian filename is readable and an
     * ASCII one stays quoted as it always was.
     */
    private static function fileResponse(string $path, string $contentType, string $originalName): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);

        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->headers->set('Content-Disposition', self::contentDisposition($originalName));
        $response->headers->set('ETag', '"'.md5((string) filemtime($path).'-'.(string) filesize($path)).'"');

        return $response;
    }

    /** `attachment; filename="…"`, or `attachment; filename*=utf-8''…` when quoted. */
    private static function contentDisposition(string $filename): string
    {
        $quoted = str_replace('%2F', '/', rawurlencode($filename));

        if ($quoted !== $filename) {
            return "attachment; filename*=utf-8''{$quoted}";
        }

        return 'attachment; filename="'.$filename.'"';
    }

    /**
     * The unhandled-500 body: `PlainTextResponse("Internal Server Error")`.
     *
     * FastAPI registers render callbacks for `HTTPException` and `RequestValidationError`
     * only, so anything else reaches Starlette's server-error middleware, which answers
     * plain text when `debug` is off.  Returned rather than raised, because raising it
     * would go through Laravel's renderer and come back as JSON.
     */
    private static function plainServerError(): Response
    {
        return response('Internal Server Error', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
