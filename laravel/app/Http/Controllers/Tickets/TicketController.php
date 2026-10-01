<?php

namespace App\Http\Controllers\Tickets;

use App\Http\Controllers\Controller;
use App\Support\Automation\PrivateAttachmentStore;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyParam;
use App\Support\Legacy\LegacyPath;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacyValidationException;
use App\Support\Legacy\LegacyWhitespace;
use App\Support\Tickets\TicketPayload;
use App\Support\Tickets\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `/api/tickets/*` — the normalized helpdesk: categories, the recipient picker,
 * the ticket list, and the conversation routes (read, create, reply, update,
 * attach, download).
 *
 * Ported from `app/api/routes/ticketing.py`.  Two generations of tickets
 * coexist in this application and this controller serves only the **new** one
 * (`tickets` / `ticket_messages` / `ticket_events` / `ticket_attachments`);
 * the legacy `ticket_table` thread model is `LegacyTicketController`.
 *
 * ### Auth — what the inventory got wrong
 *
 * `ROUTE_INVENTORY.md` marks every `/api/tickets/*` row "master-admin session",
 * but that column is auto-generated noise (row 13, `POST /login_user` →
 * "master-admin session", proves it — that endpoint is public).  The Python
 * handlers enforce only `_actor(request)` — any logged-in user — plus row-level
 * ownership, and `user-panel-script.js` calls `/api/tickets` as an ordinary
 * user.  Master-admin middleware would break the live user panel, so these
 * routes carry `legacy.session:optional` and the handler's own `_actor` answers
 * the anonymous request with `401 {"detail":"برای ادامه وارد سامانه شوید."}`.
 * The ownership checks stay in the handlers, where the Python put them.
 *
 * ### Ordering, and why it is spelled out
 *
 * FastAPI validates a request's path parameters, query parameters and body
 * model **before** the handler's first line, and reports every failure in one
 * `detail` list in that order.  Each handler below therefore opens with
 * {@see LegacyPath} / {@see LegacyQuery} / {@see TicketPayload}, then runs the
 * guard, then the database work.  Getting it the other round is not a detail:
 *
 * ```
 * POST /api/tickets  {"subject":"x"}            -> 422 missing body      (no session needed)
 * POST /api/tickets  {"subject":"x"}  + bad IP  -> 403 cross-site        (origin checked after)
 * POST /api/tickets  valid body, no session     -> 401                  (guard)
 * ```
 *
 * ### The same-origin check is not `LegacyOrigin`
 *
 * `_assert_same_origin` compares the **whole** origin against `base_url` —
 * scheme, host and port — where the call-system's `LegacyOrigin` compares
 * hosts only.  `https://lan-host` is the same site as `http://lan-host:8000`
 * for a kiosk call and a different origin for a ticket write, so the check is
 * reproduced here rather than reused.
 */
final class TicketController extends Controller
{
    public function __construct(private readonly PrivateAttachmentStore $attachments) {}

    // ── Reads ────────────────────────────────────────────────────────────

    /**
     * `GET /api/tickets/categories` — the active categories.
     */
    public function categories(Request $request): JsonResponse
    {
        $this->actor($request);

        return response()->json(['items' => (new TicketService)->categories()]);
    }

    /**
     * `GET /api/tickets/users` — the recipient picker.
     *
     * Only master administrators may receive a support ticket, so the list is
     * the master admins minus the caller — the same rule `create_ticket`
     * enforces on the write.
     */
    public function users(Request $request): JsonResponse
    {
        [$actor] = $this->actor($request);

        $rows = DB::connection()->select(
            'SELECT LTRIM(RTRIM(username)) username,
                    LTRIM(RTRIM(COALESCE(name, \'\'))) name,
                    LTRIM(RTRIM(COALESCE(last_name, \'\'))) last_name,
                    LTRIM(RTRIM(COALESCE(department, \'\'))) department
             FROM user_table ORDER BY name, username',
        );

        $masters = array_map(
            static fn ($name): string => mb_strtolower(trim((string) $name)),
            (array) config('hastama.master_admin_usernames', ['ali']),
        );

        $users = [];

        foreach ($rows as $row) {
            $username = LegacyWhitespace::strip((string) ($row->username ?? ''));

            if (mb_strtolower($username) === mb_strtolower($actor)
                || ! in_array(mb_strtolower($username), $masters, true)) {
                continue;
            }

            $name = LegacyWhitespace::strip(implode(' ', [
                LegacyWhitespace::strip((string) ($row->name ?? '')),
                LegacyWhitespace::strip((string) ($row->last_name ?? '')),
            ]));

            $users[] = [
                'username' => $username,
                'name' => $name,
                'department' => LegacyWhitespace::strip((string) ($row->department ?? '')),
            ];
        }

        return response()->json(['items' => $users]);
    }

    /**
     * `GET /api/tickets` — the paginated list.
     *
     * The seven query parameters are the Python's declaration, in the Python's
     * order, so a request with two bad values reports both — as FastAPI does.
     */
    public function index(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'page_size' => LegacyQuery::int(default: 20, ge: 5, le: 100),
            'search' => LegacyQuery::string(default: '', maxLength: 100),
            'status' => LegacyQuery::string(default: '', maxLength: 32),
            'priority' => LegacyQuery::string(default: '', maxLength: 16),
            'assignee' => LegacyQuery::string(default: '', maxLength: 255),
            'sort' => LegacyQuery::string(default: 'newest', maxLength: 16),
        ]);

        [$actor, $isAdmin, $isMasterAdmin] = $this->actor($request);

        return response()->json(
            (new TicketService)->listTickets(
                $actor,
                $isAdmin,
                $params['page'],
                $params['page_size'],
                $params['search'],
                $params['status'],
                $params['priority'],
                $params['assignee'],
                $params['sort'],
                isMasterAdmin: $isMasterAdmin,
            ),
        );
    }

    /**
     * `GET /api/tickets/{ticket_id}` — one conversation.
     *
     * A ticket the actor may not see is the same `404 تیکت پیدا نشد.` as one
     * that does not exist — the ownership check is inside the service's row
     * lookup, exactly as the Python's `_ticket_row` does it.
     */
    public function show(Request $request, string $ticketId): JsonResponse
    {
        $ticketId = LegacyPath::int($ticketId, 'ticket_id');

        [$actor, $isAdmin] = $this->actor($request);

        $ticket = (new TicketService)->getTicket($ticketId, $actor, $isAdmin);

        if ($ticket === null) {
            throw LegacyHttpException::detail(404, 'تیکت پیدا نشد.');
        }

        return response()->json($ticket);
    }

    // ── Writes ───────────────────────────────────────────────────────────

    /**
     * `POST /api/tickets` — open a conversation.  **201.**
     *
     * The order is the Python's: the body model, then the same-origin check,
     * then the actor, then the recipient's master-admin rule (403), then the
     * priority allow-list (422), then the service — whose own checks re-run
     * against the post-strip lengths.
     */
    public function store(Request $request): JsonResponse
    {
        $payload = TicketPayload::create((string) $request->getContent());

        $this->assertSameOrigin($request);

        [$actor, $isAdmin] = $this->actor($request);

        if (! in_array(mb_strtolower($payload['recipient_username']), $this->masterAdminUsernames(), true)) {
            throw LegacyHttpException::detail(403, 'تیکت‌های پشتیبانی فقط برای مدیر اصلی سامانه ارسال می‌شوند.');
        }

        if (! in_array($payload['priority'], TicketService::TICKET_PRIORITIES, true)) {
            throw LegacyHttpException::detail(422, 'اولویت تیکت معتبر نیست.');
        }

        try {
            $ticket = (new TicketService)->createTicket(
                $actor,
                $isAdmin,
                $payload['recipient_username'],
                $payload['subject'],
                $payload['body'],
                $payload['priority'],
                $payload['category_id'],
            );
        } catch (Throwable $exception) {
            throw $this->domainError($exception);
        }

        return response()->json($ticket, 201);
    }

    /**
     * `POST /api/tickets/{ticket_id}/messages` — a reply or an internal note.
     */
    public function storeMessage(Request $request, string $ticketId): JsonResponse
    {
        $ticketId = LegacyPath::int($ticketId, 'ticket_id');

        $payload = TicketPayload::message((string) $request->getContent());

        $this->assertSameOrigin($request);

        [$actor, $isAdmin] = $this->actor($request);

        if (! in_array($payload['visibility'], TicketService::MESSAGE_VISIBILITIES, true)) {
            throw LegacyHttpException::detail(422, 'نوع پیام معتبر نیست.');
        }

        try {
            $ticket = (new TicketService)->addMessage(
                $ticketId,
                $actor,
                $isAdmin,
                $payload['body'],
                $payload['visibility'],
            );
        } catch (Throwable $exception) {
            throw $this->domainError($exception);
        }

        return response()->json($ticket);
    }

    /**
     * `PATCH /api/tickets/{ticket_id}` — change status, priority, category or
     * assignee.
     *
     * `TicketUpdate` is an all-optional model, so `{}` is a valid body that the
     * service answers with the unchanged ticket — no UPDATE, no event, no
     * notification.
     */
    public function update(Request $request, string $ticketId): JsonResponse
    {
        $ticketId = LegacyPath::int($ticketId, 'ticket_id');

        $payload = TicketPayload::update((string) $request->getContent());

        $this->assertSameOrigin($request);

        [$actor, $isAdmin] = $this->actor($request);

        try {
            $ticket = (new TicketService)->updateTicket(
                $ticketId,
                $actor,
                $isAdmin,
                $payload['status'],
                $payload['priority'],
                $payload['category_id'],
                $payload['assigned_to'],
            );
        } catch (Throwable $exception) {
            throw $this->domainError($exception);
        }

        return response()->json($ticket);
    }

    // ── Attachments ──────────────────────────────────────────────────────

    /**
     * `POST /api/tickets/{ticket_id}/attachments` — store a file.
     *
     * Three validations are merged into one `422` — path, then `message_id`,
     * then the file — and only after they all pass does the session matter,
     * which is where FastAPI puts the file parameter.  The bytes are read with
     * the same bound `await file.read(10*1024*1024+1)` used, so an oversized
     * upload is refused by the store rather than by the memory limit.
     *
     * The store's validation is the Python's `store_private_attachment`
     * exactly — see {@see PrivateAttachmentStore}: an extension allow-list, a
     * 1..10 MB size bound, the declared content type, and a sanitised file
     * name.  **The Python performs no magic-byte check**, and neither does this
     * port: a `.pdf` that is really a PNG is accepted when it declares
     * `application/pdf`.
     *
     * If the row cannot be written the file is removed again —
     * `Path(...).unlink(missing_ok=True)` — so a refused message id does not
     * leave orphaned bytes behind.
     */
    public function storeAttachment(Request $request, string $ticketId): JsonResponse|Response
    {
        $errors = [];
        $ticketId = $this->pathInt($ticketId, 'ticket_id', $errors);

        try {
            $params = LegacyQuery::validate($request, [
                'message_id' => new LegacyParam(
                    LegacyParam::INT,
                    nullable: true,
                    default: null,
                    ge: 1,
                ),
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

        $this->assertSameOrigin($request);

        [$actor, $isAdmin] = $this->actor($request);

        try {
            $payload = $this->readUpload($file);
            $metadata = $this->attachments->store(
                $file->getClientOriginalName() ?: 'attachment',
                (string) $file->getClientMimeType(),
                $payload,
            );
        } catch (LegacyHttpException $exception) {
            throw $exception;
        } catch (Throwable) {
            // A filesystem failure is not a `ValueError` in Python either: it
            // escapes the route's `except ValueError` and answers a plain-text
            // 500.  PHP's warnings are escalated to `ErrorException` by the
            // framework, so a failed write throws too.
            return response('Internal Server Error', 500, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        $metadata['message_id'] = $params['message_id'];

        try {
            return response()->json(
                (new TicketService)->addAttachment($ticketId, $actor, $isAdmin, $metadata),
            );
        } catch (Throwable $exception) {
            $this->attachments->forget($metadata['path']);

            throw $this->domainError($exception);
        }
    }

    /**
     * `GET /api/tickets/{ticket_id}/attachments/{attachment_id}` — download.
     *
     * The path is validated as a pair, so a request with two bad segments
     * reports both.  The storage name comes from the database row — never from
     * the client — and {@see PrivateAttachmentStore::resolve} refuses a name
     * that is not a file directly under the private root, which is the
     * Python's `if root not in path.parents or not path.is_file(): 404`.
     *
     * The response headers are Starlette's `FileResponse`: the stored content
     * type (with `; charset=utf-8` appended for `text/*` by the framework,
     * exactly as Starlette appends it), a `Content-Disposition` that switches
     * to `filename*` when the name is not ASCII-safe, `Accept-Ranges: bytes`,
     * and an `ETag` of the file's mtime and size.
     */
    public function downloadAttachment(Request $request, string $ticketId, string $attachmentId): BinaryFileResponse
    {
        $errors = [];
        $ticketId = $this->pathInt($ticketId, 'ticket_id', $errors);
        $attachmentId = $this->pathInt($attachmentId, 'attachment_id', $errors);

        if ($errors !== []) {
            throw new LegacyValidationException($errors);
        }

        [$actor, $isAdmin] = $this->actor($request);

        $service = new TicketService;

        $attachment = $service->attachment($ticketId, $attachmentId, $actor, $isAdmin);

        if ($attachment === null) {
            throw LegacyHttpException::detail(404, 'فایل پیدا نشد.');
        }

        $path = $this->attachments->resolve($attachment['storage_name']);

        if ($path === null) {
            throw LegacyHttpException::detail(404, 'فایل پیدا نشد.');
        }

        return $this->fileResponse($path, $attachment['content_type'], $attachment['original_name']);
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * `_actor(request)` — the trimmed session username and the two strict flags.
     *
     * `is_admin is True` is compared with `=== true`, not truthiness: a session
     * flag that arrived as the string `"1"` must not pass as an administrator.
     *
     * @return array{0: string, 1: bool, 2: bool}
     *
     * @throws LegacyHttpException 401 when the session carries no username.
     */
    private function actor(Request $request): array
    {
        $username = trim((string) $request->session()->get('username', ''));

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
     * `_assert_same_origin(request)` — the ticketing module's own origin check.
     *
     * The whole origin is compared against `base_url`, scheme and port
     * included, after both are stripped of a trailing `/`.  An absent `Origin`
     * is allowed — a non-browser client, or a same-origin navigation, does not
     * send one.
     *
     * @throws LegacyHttpException 403 cross-site.
     */
    private function assertSameOrigin(Request $request): void
    {
        $origin = (string) $request->headers->get('origin', '');

        if ($origin !== '' && rtrim($origin, '/') !== rtrim($request->getSchemeAndHttpHost(), '/')) {
            throw LegacyHttpException::detail(403, 'درخواست از مبدأ مجاز نیست.');
        }
    }

    /**
     * `_domain_error(exc)` — the Python's mapping, with the service's refusals
     * already carrying their status.
     *
     * The service raises the Python's `PermissionError`/`LookupError`/
     * `ValueError` as {@see LegacyHttpException} with the status `_domain_error`
     * would have chosen (403/404/422); anything else is the module's own 500.
     *
     * @throws LegacyHttpException
     */
    private function domainError(Throwable $exception): LegacyHttpException
    {
        if ($exception instanceof LegacyHttpException) {
            return $exception;
        }

        return LegacyHttpException::detail(500, 'خطا در پردازش تیکت.');
    }

    /**
     * A declared `{int}` path parameter, merged into a shared error list.
     *
     * FastAPI reports a bad path *and* a bad body in one `detail` list, path
     * error first, and the download route is the one where both can fail at
     * once.
     *
     * @param  array<int, array<string, mixed>>  $errors  Appended to, in place.
     */
    private function pathInt(string $value, string $name, array &$errors): int
    {
        $parsed = LegacyPath::parse($value);

        if ($parsed === null) {
            $errors[] = LegacyPath::error($value, $name);

            // Unreachable: the caller throws when `$errors` is non-empty.  The
            // return keeps the static analyser happy and the value honest.
            return 0;
        }

        return $parsed;
    }

    /**
     * `await file.read(10*1024*1024+1)` — at most one byte past the bound, so an
     * oversized file is recognised by its length rather than by a `stat()` that
     * the multipart parser has already paid for.
     */
    private function readUpload(UploadedFile $file): string
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
     * The `Content-Type` is set raw so that the framework appends
     * `; charset=utf-8` for a `text/*` type exactly where Starlette's
     * `init_headers()` appends it.  The `Content-Disposition` switches to
     * RFC 5987 when `quote($name)` would have changed the name — which is the
     * Python's own test — so a Persian filename is readable and an ASCII one
     * stays quoted as it always was.
     */
    private function fileResponse(string $path, string $contentType, string $originalName): BinaryFileResponse
    {
        $response = new BinaryFileResponse($path);

        $response->headers->set('Content-Type', $contentType);
        $response->headers->set('Accept-Ranges', 'bytes');
        $response->headers->set('Content-Disposition', $this->contentDisposition($originalName));
        $response->headers->set('ETag', '"'.md5((string) filemtime($path).'-'.(string) filesize($path)).'"');

        return $response;
    }

    /** `attachment; filename="…"`, or `attachment; filename*=utf-8''…` when quoted. */
    private function contentDisposition(string $filename): string
    {
        $quoted = str_replace('%2F', '/', rawurlencode($filename));

        if ($quoted !== $filename) {
            return "attachment; filename*=utf-8''{$quoted}";
        }

        return 'attachment; filename="'.$filename.'"';
    }

    /** @return array<int, string> */
    private function masterAdminUsernames(): array
    {
        $names = [];

        foreach ((array) config('hastama.master_admin_usernames', ['ali']) as $name) {
            $name = trim((string) $name);

            if ($name !== '') {
                $names[] = mb_strtolower($name);
            }
        }

        return $names;
    }
}
