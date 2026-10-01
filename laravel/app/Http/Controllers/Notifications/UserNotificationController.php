<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyPagination;
use App\Support\Legacy\LegacyPath;
use App\Support\Legacy\LegacyQuery;
use App\Support\Notifications\NotificationActor;
use App\Support\Notifications\NotificationQuery;
use App\Support\Notifications\NotificationSerializer;
use App\Support\Notifications\NotificationService;
use App\Support\Notifications\NotificationStream;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed-in user's notification surface — `app/api/routes/notifications.py`.
 *
 * Every handler is `_actor(request)` (or `_actor(request, admin=True)` for the
 * admin-stream alias), so the guard is `NotificationActor`, not middleware.
 *
 * Three things about this half of the module are easy to get wrong:
 *
 * * **`GET /api/notifications` is authenticated, not administrator-only.**  The route
 *   inventory says "admin session", but the handler calls `_actor(request)` — the
 *   same guard as the user's own list — and the Python comment above it describes an
 *   admin-only filter that was never implemented (the `is_admin` it computes is never
 *   used).  The port reproduces the behaviour, not the comment: any signed-in user
 *   gets their own inbox.
 * * **the per-row handlers are not public.**  The inventory's "none (public)" rows are
 *   the generator not tracing into `_owned_update`, which calls `_actor(request)` on
 *   the way past — an anonymous request is answered 401 `{"detail": "برای ادامه وارد
 *   سامانه شوید."}` before any SQL runs.  No middleware is added; the handler owns the
 *   answer, exactly as the Python did.
 * * **the two SSE endpoints are real streams.**  `StreamedResponse` with the Python's
 *   headers, `id:`/`data:` frames keyed on the notification id, a `: heartbeat` comment
 *   every two seconds, and the client disconnect handled — see `NotificationStream`.
 */
final class UserNotificationController extends Controller
{
    /** The user-facing column set: the notification's own columns plus the delivery state. */
    private const INBOX_COLUMNS = 'n.id,n.title,n.content,n.type,n.priority,n.action_label,n.action_url,
        n.created_by,n.published_at,un.delivered_at,un.read_at';

    public function __construct(private readonly NotificationService $service) {}

    /**
     * `GET /api/notifications` — the user's own inbox, paginated.
     *
     * The envelope is the Python's — note it carries `unread` and **no** `page_size`,
     * where the admin list carries `page_size` and no `unread`.  The dead `is_admin`
     * computation is deliberately not reproduced; see the class docblock.
     */
    public function index(Request $request): JsonResponse
    {
        $params = NotificationQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'page_size' => LegacyQuery::int(default: 12, ge: 5, le: 50),
            'search' => LegacyQuery::string(default: '', maxLength: 100),
        ], 'state', ['all', 'unread', 'read'], 'all');

        $username = NotificationActor::resolve($request);

        $this->service->publishDue();

        $page = $params['page'];
        $pageSize = $params['page_size'];
        $state = $params['state'];
        $search = $params['search'];

        $clauses = ['RTRIM(un.username)=?', 'un.dismissed_at IS NULL', "n.status IN ('published','archived')"];
        $bindings = [$username];

        if ($state === 'unread') {
            $clauses[] = 'un.read_at IS NULL';
        } elseif ($state === 'read') {
            $clauses[] = 'un.read_at IS NOT NULL';
        }

        if ($search !== '') {
            $clauses[] = '(n.title LIKE ? OR n.content LIKE ?)';
            $pattern = '%'.trim($search).'%';
            $bindings[] = $pattern;
            $bindings[] = $pattern;
        }

        $where = implode(' AND ', $clauses);
        $connection = DB::connection();

        $total = (int) array_values((array) $connection->selectOne(
            "SELECT COUNT(*) FROM user_notifications un JOIN notifications n ON n.id=un.notification_id WHERE {$where}",
            $bindings
        ))[0];

        $rows = $connection->select(
            'SELECT '.self::INBOX_COLUMNS.'
             FROM user_notifications un JOIN notifications n ON n.id=un.notification_id
             WHERE {$where} ORDER BY un.delivered_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY',
            array_merge($bindings, [LegacyPagination::offset($page, $pageSize), $pageSize])
        );

        $unread = (int) array_values((array) $connection->selectOne(
            'SELECT COUNT(*) FROM user_notifications WHERE RTRIM(username)=? AND read_at IS NULL AND dismissed_at IS NULL',
            [$username]
        ))[0];

        return response()->json([
            'items' => NotificationSerializer::rows($rows),
            'total' => $total,
            'unread' => $unread,
            'page' => $page,
            'pages' => LegacyPagination::pages($total, $pageSize),
        ]);
    }

    /**
     * `GET /api/notifications/unread-count` — the bell badge number.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $username = NotificationActor::resolve($request);

        $this->service->publishDue();

        $count = (int) array_values((array) DB::connection()->selectOne(
            "SELECT COUNT(*) FROM user_notifications un JOIN notifications n ON n.id=un.notification_id
             WHERE RTRIM(un.username)=? AND un.read_at IS NULL AND un.dismissed_at IS NULL
               AND n.status IN ('published','archived')",
            [$username]
        ))[0];

        return response()->json(['unread' => $count]);
    }

    /**
     * `GET /api/notifications/poll` — the compact snapshot for the in-panel centre.
     *
     * `TOP 50`, newest first, and — unlike the list — no `action_label` in the column
     * set.  The Python's comment is the contract: this endpoint intentionally never
     * reads the ticket or attendance tables.
     */
    public function poll(Request $request): JsonResponse
    {
        $username = NotificationActor::resolve($request);

        $rows = DB::connection()->select(
            "SELECT TOP 50 n.id,n.title,n.content,n.type,n.priority,n.action_url,n.created_by,
                    n.published_at,un.delivered_at,un.read_at
             FROM user_notifications un JOIN notifications n ON n.id=un.notification_id
             WHERE RTRIM(un.username)=? AND un.dismissed_at IS NULL
               AND n.status IN ('published','archived')
             ORDER BY un.delivered_at DESC, n.id DESC",
            [$username]
        );

        return response()->json(['notifications' => NotificationSerializer::rows($rows)]);
    }

    /**
     * `GET /api/notifications/stream` — the user's Server-Sent Events stream.
     *
     * The frame contract is the Python's, and the front-end's `EventSource` parses it:
     *
     * ```
     * id: 10011
     * data: {"id":10011,"title":…,…}
     *
     * : heartbeat
     * ```
     *
     * The first cycle emits nothing unless the client sent `Last-Event-ID` (a replay of
     * everything newer than it, oldest first); after that, each cycle emits the rows
     * that are new since the previous one, then a heartbeat, then sleeps two seconds.
     * The loop ends when the client goes away — the Python's `GeneratorExit`.
     */
    public function stream(Request $request): StreamedResponse
    {
        $username = NotificationActor::resolve($request);

        return $this->streamResponse($username, $request);
    }

    /**
     * `GET /api/notifications/admin-stream` — the same stream behind the admin guard.
     *
     * The Python checks `_actor(request, admin=True)` and then delegates to the user's
     * stream unchanged, so the content is the caller's own inbox; the alias exists for
     * the control centre's bell.
     */
    public function adminStream(Request $request): StreamedResponse
    {
        NotificationActor::resolve($request, admin: true);

        return $this->streamResponse(NotificationActor::resolve($request), $request);
    }

    /**
     * Build the SSE response for one signed-in user.
     */
    private function streamResponse(string $username, Request $request): StreamedResponse
    {
        // `int(request.headers.get("last-event-id") or 0)` — a non-integer header is 0.
        $lastEventId = 0;
        $header = trim((string) $request->headers->get('last-event-id', ''));

        if ($header !== '' && preg_match('/^[+-]?\d+$/', $header) === 1) {
            $lastEventId = (int) $header;
        }

        return new StreamedResponse(function () use ($username, $lastEventId) {
            $seen = null;

            while (true) {
                $rows = DB::connection()->select(
                    'SELECT TOP 100 '.self::INBOX_COLUMNS.'
                     FROM user_notifications un
                     JOIN notifications n ON n.id=un.notification_id
                     WHERE RTRIM(un.username)=? AND un.dismissed_at IS NULL
                       AND n.status IN (\'published\',\'archived\')
                     ORDER BY un.delivered_at DESC,n.id DESC',
                    [$username]
                );

                $items = NotificationSerializer::rows($rows);

                foreach (NotificationStream::diff($items, $seen, $lastEventId) as $item) {
                    echo NotificationStream::frame($item);
                }

                $seen = NotificationStream::seen($items);

                echo NotificationStream::heartbeat();

                if (ob_get_level() > 0) {
                    ob_flush();
                }

                flush();

                if (connection_aborted()) {
                    break;
                }

                sleep(2);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * `GET /api/notifications/{notification_id}` — one notification from the user's inbox.
     *
     * The 404 is the ownership check: the row must belong to the signed-in user, not be
     * dismissed, and be published or archived.
     */
    public function show(string $notificationId, Request $request): JsonResponse
    {
        $id = LegacyPath::int($notificationId, 'notification_id');
        $username = NotificationActor::resolve($request);

        $row = DB::connection()->selectOne(
            'SELECT '.self::INBOX_COLUMNS.'
             FROM user_notifications un JOIN notifications n ON n.id=un.notification_id
             WHERE n.id=? AND RTRIM(un.username)=? AND un.dismissed_at IS NULL
               AND n.status IN (\'published\',\'archived\')',
            [$id, $username]
        );

        if ($row === null) {
            throw LegacyHttpException::detail(404, 'اعلان برای شما پیدا نشد.');
        }

        return response()->json(NotificationSerializer::row($row));
    }

    /**
     * `POST /api/notifications/{notification_id}/read` — read for this user only.
     */
    public function markRead(string $notificationId, Request $request): JsonResponse
    {
        return $this->ownedUpdate($notificationId, $request, 'read_at=COALESCE(read_at,SYSUTCDATETIME())');
    }

    /**
     * `POST /api/notifications/{notification_id}/unread` — unread for this user only.
     */
    public function markUnread(string $notificationId, Request $request): JsonResponse
    {
        return $this->ownedUpdate($notificationId, $request, 'read_at=NULL');
    }

    /**
     * `DELETE /api/notifications/{notification_id}` — dismiss for this user only.
     */
    public function dismiss(string $notificationId, Request $request): JsonResponse
    {
        return $this->ownedUpdate($notificationId, $request, 'dismissed_at=SYSUTCDATETIME()');
    }

    /**
     * `_owned_update(request, notification_id, expression)` — the three per-row handlers.
     *
     * They share one UPDATE and one 404: the row must exist for this user and not be
     * dismissed.  Note there is no join to `notifications` and no status check — the
     * Python trusted the fan-out to have only ever created rows for published
     * notifications, and the port does not add a check the running server does not have.
     */
    private function ownedUpdate(string $notificationId, Request $request, string $expression): JsonResponse
    {
        $id = LegacyPath::int($notificationId, 'notification_id');
        $username = NotificationActor::resolve($request);

        $changed = DB::transaction(function () use ($id, $username, $expression) {
            return DB::connection()->update(
                "UPDATE user_notifications SET {$expression},updated_at=SYSUTCDATETIME()
                 WHERE notification_id=? AND RTRIM(username)=? AND dismissed_at IS NULL",
                [$id, $username]
            );
        });

        if ($changed === 0) {
            throw LegacyHttpException::detail(404, 'اعلان برای شما پیدا نشد.');
        }

        return response()->json(['success' => true]);
    }

    /**
     * `POST /api/notifications/read-all` — mark the user's whole inbox read.
     *
     * Like the admin's read-all, `updated` is the UPDATE's rowcount — every non-dismissed
     * row is matched, including the ones already read.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $username = NotificationActor::resolve($request);

        $changed = DB::transaction(function () use ($username) {
            return DB::connection()->update(
                'UPDATE user_notifications SET read_at=COALESCE(read_at,SYSUTCDATETIME()),updated_at=SYSUTCDATETIME()
                 WHERE RTRIM(username)=? AND dismissed_at IS NULL',
                [$username]
            );
        });

        return response()->json(['success' => true, 'updated' => max(0, $changed)]);
    }
}
