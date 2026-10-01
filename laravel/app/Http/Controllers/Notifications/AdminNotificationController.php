<?php

namespace App\Http\Controllers\Notifications;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyPagination;
use App\Support\Legacy\LegacyPath;
use App\Support\Legacy\LegacyQuery;
use App\Support\Notifications\NotificationActor;
use App\Support\Notifications\NotificationInput;
use App\Support\Notifications\NotificationQuery;
use App\Support\Notifications\NotificationSerializer;
use App\Support\Notifications\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The administrator's notification surface — `app/api/routes/notifications.py`.
 *
 * Every handler here is `_actor(request, admin=True)`, and the guard runs **in the
 * handler** rather than in middleware: the module's own `_actor` answers FastAPI's
 * `{"detail": …}` bodies, which are not the bodies `EnsureAdmin` produces.  See
 * `NotificationActor`.
 *
 * Two behaviours of the Python are reproduced deliberately, because the running
 * front-end has learned them:
 *
 * * **`GET /api/admin/notifications` with any filter answers `items: []` while
 *   `total` is correct.**  The item query's `admin_read_at` subquery carries a `?`
 *   in the SELECT list — *before* the WHERE clause's placeholders — but the params
 *   are built as `params + [username, offset, limit]`, so with an active filter the
 *   bindings shift: `n.status = <username>` matches nothing.  The COUNT query is
 *   bound correctly, which is why the two disagree.  A search filter shifts them
 *   differently (`content LIKE <username>`), so title-only matches still come back.
 *   Verified against the live server: `?status=published` → `{"items":[],"total":2}`;
 *   `?search=anonymous` (a content-only term) → `{"items":[],"total":2}`.  The port
 *   keeps the Python's statement shape and parameter order, so the misbinding
 *   reproduces itself.
 * * **`DELETE /api/admin/notifications/delete-all` is unreachable.**  The Python
 *   registers `DELETE /admin/notifications/{notification_id}` *before* the
 *   `delete-all` route, so `delete-all` arrives as a `{notification_id}` segment,
 *   fails the `int` path validation and answers **422** `int_parsing`.  The port
 *   registers the same order, so the route exists in the listing and is shadowed —
 *   exactly as the running server answers it.
 */
final class AdminNotificationController extends Controller
{
    private const STATUSES = ['draft', 'scheduled', 'published', 'archived'];

    /** `type` filter members. */
    private const TYPES = ['general', 'announcement', 'system', 'warning', 'information', 'success', 'reminder'];

    /** `priority` filter members. */
    private const PRIORITIES = ['normal', 'important', 'high', 'critical'];

    /** The sixteen `notifications` columns, in table order — never `SELECT *`. */
    private const NOTIFICATION_COLUMNS = 'n.id, n.title, n.content, n.type, n.priority, n.status, n.target_type,
        n.action_label, n.action_url, n.created_by, n.created_at, n.updated_at, n.published_at,
        n.scheduled_at, n.archived_at, n.push_tag';

    public function __construct(private readonly NotificationService $service) {}

    /**
     * `GET /api/admin/notification-targets` — the audience picker's options.
     *
     * The three keys are the Python's: every user (RTRIM'd columns), then the
     * sorted unique non-empty departments and roles.  The sort is a **string** sort
     * because Python's `sorted()` orders by code point and the values are Persian.
     */
    public function targetOptions(Request $request): JsonResponse
    {
        NotificationActor::resolve($request, admin: true);

        $rows = DB::connection()->select(
            'SELECT RTRIM(username) username, RTRIM(COALESCE(name, \'\')) name,
                    RTRIM(COALESCE(last_name, \'\')) last_name, RTRIM(COALESCE(department, \'\')) department,
                    RTRIM(COALESCE(role, \'\')) role
             FROM user_table ORDER BY name, username'
        );

        $users = [];
        $departments = [];
        $roles = [];

        foreach ($rows as $row) {
            $users[] = [
                'username' => $row->username,
                'name' => $row->name,
                'last_name' => $row->last_name,
                'department' => $row->department,
                'role' => $row->role,
            ];

            if ($row->department !== '') {
                $departments[$row->department] = true;
            }

            if ($row->role !== '') {
                $roles[$row->role] = true;
            }
        }

        $departments = array_keys($departments);
        $roles = array_keys($roles);
        sort($departments, SORT_STRING);
        sort($roles, SORT_STRING);

        return response()->json([
            'users' => $users,
            'departments' => $departments,
            'roles' => $roles,
        ]);
    }

    /**
     * `GET /api/admin/notifications` — the paginated admin list with its stats block.
     *
     * The envelope is the Python's own shape — `page_size`, not the `per_page` the
     * master-admin module uses — and the item query is the Python's statement, with
     * the parameter-order bug intact (see the class docblock).
     */
    public function index(Request $request): JsonResponse
    {
        $params = NotificationQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'page_size' => LegacyQuery::int(default: 15, ge: 5, le: 100),
            'search' => LegacyQuery::string(default: '', maxLength: 100),
            'status' => LegacyQuery::string(default: ''),
            'type' => LegacyQuery::string(default: ''),
            'priority' => LegacyQuery::string(default: ''),
        ], 'sort', ['newest', 'oldest', 'title'], 'newest');

        NotificationActor::resolve($request, admin: true);

        $page = $params['page'];
        $pageSize = $params['page_size'];
        $search = $params['search'];

        $this->service->publishDue();

        $clauses = ['1=1'];
        $bindings = [];

        if ($search !== '') {
            $clauses[] = '(n.title LIKE ? OR n.content LIKE ?)';
            $pattern = '%'.trim($search).'%';
            $bindings[] = $pattern;
            $bindings[] = $pattern;
        }

        foreach (['status' => self::STATUSES, 'type' => self::TYPES, 'priority' => self::PRIORITIES] as $field => $allowed) {
            $value = $params[$field];

            if (in_array($value, $allowed, true)) {
                $clauses[] = "n.{$field} = ?";
                $bindings[] = $value;
            }
        }

        $where = implode(' AND ', $clauses);
        $order = match ($params['sort']) {
            'oldest' => 'n.created_at ASC',
            'title' => 'n.title ASC',
            default => 'n.created_at DESC',
        };

        $connection = DB::connection();

        $total = (int) array_values((array) $connection->selectOne(
            "SELECT COUNT(*) FROM notifications n WHERE {$where}",
            $bindings
        ))[0];

        // The statement is the Python's, bug included: the `admin_read_at` subquery's
        // placeholder precedes the WHERE placeholders, while the bindings put the
        // filter values first.  See the class docblock.
        $rows = $connection->select(
            'SELECT '.self::NOTIFICATION_COLUMNS.",
                (SELECT STRING_AGG(t.target_value, N'||') FROM notification_targets t WHERE t.notification_id=n.id) target_values,
                (SELECT MIN(un.read_at) FROM user_notifications un WHERE un.notification_id=n.id AND RTRIM(un.username)=RTRIM(?)) admin_read_at
             FROM notifications n
             WHERE {$where}
             ORDER BY {$order} OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
            array_merge($bindings, [
                $request->session()->get('username'),
                LegacyPagination::offset($page, $pageSize),
                $pageSize,
            ])
        );

        $stats = NotificationSerializer::stats($connection->selectOne(
            "SELECT COUNT(*) total,
                SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) published,
                SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) draft,
                SUM(CASE WHEN status='scheduled' THEN 1 ELSE 0 END) scheduled,
                SUM(CASE WHEN status='archived' THEN 1 ELSE 0 END) archived FROM notifications"
        ));

        return response()->json([
            'items' => NotificationSerializer::rows($rows),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'pages' => LegacyPagination::pages($total, $pageSize),
            'stats' => $stats,
        ]);
    }

    /**
     * `POST /api/admin/notifications/read-all` — mark the admin's whole visible inbox read.
     *
     * `updated` is the UPDATE's rowcount, which counts every matched row — a row
     * that was already read is matched again (`COALESCE` keeps its `read_at`), so the
     * number is "rows touched", not "rows newly read".  Verified against the live
     * server: a second call answers the same `updated` as the first.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $username = NotificationActor::resolve($request, admin: true);

        $changed = DB::transaction(function () use ($username) {
            return DB::connection()->update(
                "UPDATE un
                 SET read_at=COALESCE(un.read_at,SYSUTCDATETIME()), updated_at=SYSUTCDATETIME()
                 FROM user_notifications un
                 INNER JOIN notifications n ON n.id=un.notification_id
                 WHERE RTRIM(un.username)=?
                   AND un.dismissed_at IS NULL
                   AND n.status IN ('published','archived')",
                [$username]
            );
        });

        return response()->json(['success' => true, 'updated' => max(0, $changed)]);
    }

    /**
     * `POST /api/admin/notifications` — create a notification (201).
     *
     * The response echoes the **requested** status, not the stored one — a `draft`
     * create stores `draft`, a `scheduled` create stores `scheduled`, and both echo
     * what the caller sent.  `delivered` is the fan-out count, `0` unless the
     * notification was published on the way in.
     */
    public function store(Request $request): JsonResponse
    {
        $input = NotificationInput::validate($request->getContent());
        $actor = NotificationActor::resolve($request, admin: true);
        $targets = $this->service->validateTarget($input);
        $scheduledAt = $this->service->parseSchedule($input['scheduled_at']);
        $initialStatus = $input['status'] === 'scheduled' ? 'scheduled' : 'draft';

        $result = DB::transaction(function () use ($input, $actor, $targets, $initialStatus, $scheduledAt) {
            $id = (int) DB::table('notifications')->insertGetId([
                'title' => $input['title'],
                'content' => $input['content'],
                'type' => $input['type'],
                'priority' => $input['priority'],
                'status' => $initialStatus,
                'target_type' => $input['target_type'],
                'action_label' => $input['action_label'],
                'action_url' => $input['action_url'],
                'created_by' => $actor,
                'scheduled_at' => $scheduledAt,
            ], 'id');

            foreach ($targets as $target) {
                DB::connection()->insert(
                    'INSERT INTO notification_targets (notification_id,target_value) VALUES (?,?)',
                    [$id, $target]
                );
            }

            $delivered = $input['status'] === 'published' ? $this->service->publish($id) : 0;

            return ['id' => $id, 'delivered' => $delivered];
        });

        return response()->json([
            'success' => true,
            'id' => $result['id'],
            'status' => $input['status'],
            'delivered' => $result['delivered'],
        ], 201);
    }

    /**
     * `PUT /api/admin/notifications/{notification_id}` — edit a draft or scheduled one.
     *
     * A published or archived notification is refused with 409 before anything is
     * written; the targets are replaced wholesale, exactly as the Python's
     * delete-then-insert did.
     */
    public function update(string $notificationId, Request $request): JsonResponse
    {
        $id = LegacyPath::int($notificationId, 'notification_id');
        $input = NotificationInput::validate($request->getContent());
        NotificationActor::resolve($request, admin: true);
        $targets = $this->service->validateTarget($input);

        $delivered = DB::transaction(function () use ($id, $input, $targets) {
            $row = DB::connection()->selectOne('SELECT status FROM notifications WHERE id=?', [$id]);

            if ($row === null) {
                throw LegacyHttpException::detail(404, 'اعلان پیدا نشد.');
            }

            if (in_array(trim((string) $row->status), ['published', 'archived'], true)) {
                throw LegacyHttpException::detail(409, 'فقط پیش‌نویس یا اعلان زمان‌بندی‌شده قابل ویرایش است.');
            }

            $storedStatus = $input['status'] === 'scheduled' ? 'scheduled' : 'draft';

            DB::connection()->update(
                'UPDATE notifications SET title=?,content=?,type=?,priority=?,status=?,target_type=?,
                 action_label=?,action_url=?,scheduled_at=?,updated_at=SYSUTCDATETIME() WHERE id=?',
                [
                    $input['title'],
                    $input['content'],
                    $input['type'],
                    $input['priority'],
                    $storedStatus,
                    $input['target_type'],
                    $input['action_label'],
                    $input['action_url'],
                    $this->service->parseSchedule($input['scheduled_at']),
                    $id,
                ]
            );

            DB::connection()->delete('DELETE FROM notification_targets WHERE notification_id=?', [$id]);

            foreach ($targets as $target) {
                DB::connection()->insert(
                    'INSERT INTO notification_targets (notification_id,target_value) VALUES (?,?)',
                    [$id, $target]
                );
            }

            return $input['status'] === 'published' ? $this->service->publish($id) : 0;
        });

        return response()->json([
            'success' => true,
            'id' => $id,
            'status' => $input['status'],
            'delivered' => $delivered,
        ]);
    }

    /**
     * `POST /api/admin/notifications/{notification_id}/publish` — fan out and publish.
     */
    public function publish(string $notificationId, Request $request): JsonResponse
    {
        $id = LegacyPath::int($notificationId, 'notification_id');
        NotificationActor::resolve($request, admin: true);

        $delivered = DB::transaction(fn () => $this->service->publish($id));

        return response()->json(['success' => true, 'delivered' => $delivered]);
    }

    /**
     * `POST /api/admin/notifications/{notification_id}/read` — read for this admin only.
     *
     * The 404 is the rowcount check: the admin has no `user_notifications` row for a
     * notification that was never fanned out to them (a `selected` audience that did
     * not include them), so the UPDATE matches nothing.
     */
    public function markRead(string $notificationId, Request $request): JsonResponse
    {
        return $this->markReadState($notificationId, $request, 'read_at=COALESCE(un.read_at,SYSUTCDATETIME())');
    }

    /**
     * `POST /api/admin/notifications/{notification_id}/unread` — unread for this admin only.
     */
    public function markUnread(string $notificationId, Request $request): JsonResponse
    {
        return $this->markReadState($notificationId, $request, 'read_at=NULL');
    }

    /**
     * The two admin read-state handlers share one UPDATE; only the expression differs.
     */
    private function markReadState(string $notificationId, Request $request, string $expression): JsonResponse
    {
        $id = LegacyPath::int($notificationId, 'notification_id');
        $username = NotificationActor::resolve($request, admin: true);

        $changed = DB::transaction(function () use ($id, $username, $expression) {
            return DB::connection()->update(
                "UPDATE un
                 SET {$expression}, updated_at=SYSUTCDATETIME()
                 FROM user_notifications un
                 INNER JOIN notifications n ON n.id=un.notification_id
                 WHERE un.notification_id=? AND RTRIM(un.username)=?
                   AND un.dismissed_at IS NULL
                   AND n.status IN ('published','archived')",
                [$id, $username]
            );
        });

        if ($changed === 0) {
            throw LegacyHttpException::detail(404, 'اعلان برای شما پیدا نشد.');
        }

        return response()->json(['success' => true]);
    }

    /**
     * `DELETE /api/admin/notifications/{notification_id}` — delete one.
     *
     * Registered **before** `delete-all`, which is what makes the latter unreachable
     * — see the class docblock.
     */
    public function destroy(string $notificationId, Request $request): JsonResponse
    {
        $id = LegacyPath::int($notificationId, 'notification_id');
        NotificationActor::resolve($request, admin: true);

        $deleted = DB::transaction(function () use ($id) {
            return DB::connection()->delete('DELETE FROM notifications WHERE id=?', [$id]);
        });

        if ($deleted === 0) {
            throw LegacyHttpException::detail(404, 'اعلان پیدا نشد.');
        }

        return response()->json(['success' => true]);
    }

    /**
     * `POST /api/admin/notifications/{notification_id}/archive` — archive one.
     */
    public function archive(string $notificationId, Request $request): JsonResponse
    {
        $id = LegacyPath::int($notificationId, 'notification_id');
        NotificationActor::resolve($request, admin: true);

        $changed = DB::transaction(function () use ($id) {
            return DB::connection()->update(
                "UPDATE notifications SET status='archived',archived_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=?",
                [$id]
            );
        });

        if ($changed === 0) {
            throw LegacyHttpException::detail(404, 'اعلان پیدا نشد.');
        }

        return response()->json(['success' => true]);
    }

    /**
     * `DELETE /api/admin/notifications/delete-all` — **unreachable**, ported anyway.
     *
     * The Python registers this route after `DELETE /admin/notifications/{notification_id}`,
     * so every request to it is swallowed by the `{notification_id}` route and answered
     * 422 `int_parsing` before this handler can run.  The port keeps the same order;
     * the handler exists so the route listing matches the Python's router.
     */
    public function deleteAll(Request $request): JsonResponse
    {
        NotificationActor::resolve($request, admin: true);

        $deleted = DB::transaction(function () {
            return DB::connection()->delete('DELETE FROM notifications');
        });

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }
}
