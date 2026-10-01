<?php

namespace App\Support\Tickets;

use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacySerializer;
use App\Support\Legacy\LegacyWhitespace;
use App\Support\Ticketing\NotificationPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Core ticketing domain service — the **normalized** generation.
 *
 * Ported from `TicketService` in `app/services/ticketing.py`, which the
 * `/api/tickets/*` routes in `app/api/routes/ticketing.py` and
 * `POST /public/support-ticket` in `app/api/routes/auth.py` both delegate to.
 * The legacy `ticket_table` thread model that `app/main.py` serves is a
 * different generation and is **not** here — see `LegacyTicketController`.
 *
 * This class exists alongside `App\Support\Ticketing\TicketService` (the
 * master-admin surface's port of the same Python file) because that one lacks
 * the three methods this surface needs — `createTicket`, `addAttachment` and
 * the `attachment` download lookup — and because two of its choices are
 * observable in these routes' responses: it leaves `category_id` and the
 * message/attachment/event ids as the driver's strings, where `pyodbc` (what
 * the running server uses) returned integers, and it decodes
 * `ticket_events.metadata` to a PHP array, which re-encodes an empty object
 * as `[]` where the Python's `json.loads` published `{}`.
 *
 * The Python's `ensure_schema` is not reproduced: the normalized schema is
 * already installed on the live database (see `docs/migration/DATABASE_SCHEMA.md`).
 *
 * Every mutating method runs inside a transaction, which is what the Python's
 * per-request connection did — `conn.commit()` at the end of the handler and
 * `rollback()` on the way out of an exception — so a ticket, its first message,
 * its event row and its notification either all land or none do.
 */
final class TicketService
{
    public const TICKET_STATUSES = [
        'new', 'open', 'in_progress', 'waiting_for_user', 'waiting_for_support', 'resolved', 'closed',
    ];

    public const TICKET_PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    public const MESSAGE_VISIBILITIES = ['public', 'internal'];

    public const STATUS_LABELS = [
        'new' => 'جدید',
        'open' => 'باز',
        'in_progress' => 'در حال بررسی',
        'waiting_for_user' => 'در انتظار کاربر',
        'waiting_for_support' => 'در انتظار پشتیبانی',
        'resolved' => 'حل‌شده',
        'closed' => 'بسته‌شده',
    ];

    public const PRIORITY_LABELS = [
        'low' => 'کم',
        'normal' => 'عادی',
        'high' => 'زیاد',
        'urgent' => 'فوری',
    ];

    /**
     * Explicit transitions prevent a closed conversation from accidentally
     * behaving like an active ticket.  Reopening is intentional and auditable.
     */
    public const ALLOWED_TRANSITIONS = [
        'new' => ['open', 'in_progress', 'waiting_for_support', 'resolved', 'closed'],
        'open' => ['in_progress', 'waiting_for_user', 'waiting_for_support', 'resolved', 'closed'],
        'in_progress' => ['open', 'waiting_for_user', 'waiting_for_support', 'resolved', 'closed'],
        'waiting_for_user' => ['open', 'in_progress', 'resolved', 'closed'],
        'waiting_for_support' => ['open', 'in_progress', 'resolved', 'closed'],
        'resolved' => ['open', 'closed'],
        'closed' => ['open'],
    ];

    /**
     * `categories()` — the active categories, for the ticket form's dropdown.
     *
     * The Python returns `_dict_rows` without `_serialize`, so `pyodbc`'s types
     * are the contract: `id` and `parent_id` are integers.  `pdo_sqlsrv` hands
     * back strings for both, which is why the row goes through
     * {@see LegacySerializer::row} rather than a bare cast.
     *
     * @return array<int, array<string, mixed>>
     */
    public function categories(): array
    {
        $rows = DB::connection()->select(
            'SELECT id, name, slug, parent_id FROM ticket_categories WHERE is_active = 1 ORDER BY name',
        );

        return array_map(
            static fn ($row): array => LegacySerializer::row('ticket_categories', $row) ?? [],
            $rows,
        );
    }

    /**
     * `list_tickets()` — the paginated, filtered ticket list.
     *
     * The visibility rules are the Python's, in the Python's order: an
     * administrator sees everything except the anonymous password-recovery
     * tickets unless they are a master administrator, and an ordinary user sees
     * only the tickets they requested, receive or are assigned.  The `counts`
     * breakdown is scoped to those visibility clauses alone — not to the search
     * or status filters — which is what the admin dashboard's chips show.
     *
     * @return array<string, mixed>
     */
    public function listTickets(
        string $actor,
        bool $isAdmin,
        int $page = 1,
        int $pageSize = 20,
        string $search = '',
        string $status = '',
        string $priority = '',
        string $assignee = '',
        string $sort = 'newest',
        bool $isMasterAdmin = false,
    ): array {
        $page = max(1, $page);
        $pageSize = min(100, max(5, $pageSize));

        $visibilityClauses = ['1=1'];
        $visibilityParams = [];

        if (! $isAdmin) {
            $visibilityClauses[] = '(t.requester_username = ? OR t.recipient_username = ? OR t.assigned_to = ?)';
            $visibilityParams = array_merge($visibilityParams, [$actor, $actor, $actor]);
        }

        // تیکت‌های بازیابی رمز (ناشناس) فقط برای مدیر اصلی قابل مشاهده است
        if ($isAdmin && ! $isMasterAdmin) {
            $visibilityClauses[] = "t.requester_username != '__anonymous__'";
        }

        $clauses = $visibilityClauses;
        $params = $visibilityParams;

        $search = trim($search);

        if ($search !== '') {
            $query = '%'.$search.'%';
            $searchForId = str_starts_with($search, 'HT-') ? substr($search, 3) : $search;

            $clauses[] = '(t.subject LIKE ? OR t.requester_username LIKE ? OR t.recipient_username LIKE ? '
                .'OR t.id = TRY_CONVERT(BIGINT, ?) OR EXISTS '
                .'(SELECT 1 FROM ticket_messages sm WHERE sm.ticket_id = t.id AND sm.body LIKE ?))';

            $params = array_merge($params, [$query, $query, $query, $searchForId, $query]);
        }

        if (in_array($status, self::TICKET_STATUSES, true)) {
            $clauses[] = 't.status = ?';
            $params[] = $status;
        }

        if (in_array($priority, self::TICKET_PRIORITIES, true)) {
            $clauses[] = 't.priority = ?';
            $params[] = $priority;
        }

        $assignee = trim($assignee);

        if ($assignee !== '' && $isAdmin) {
            $clauses[] = 't.assigned_to = ?';
            $params[] = $assignee;
        }

        $where = implode(' AND ', $clauses);

        $order = match ($sort) {
            'oldest' => 't.updated_at ASC, t.id ASC',
            'priority' => "CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END, t.updated_at DESC",
            default => 't.updated_at DESC, t.id DESC',
        };

        $total = (int) DB::connection()->selectOne(
            "SELECT COUNT(*) AS total FROM tickets t WHERE {$where}",
            $params,
        )->total;

        $rows = DB::connection()->select(
            "SELECT t.id, t.requester_username, t.recipient_username, t.subject, t.status, t.priority,
                    t.category_id, c.name category_name, t.assigned_to, t.created_at, t.updated_at,
                    t.last_message_at, t.sla_due_at,
                    (SELECT TOP 1 tm.body FROM ticket_messages tm WHERE tm.ticket_id = t.id AND tm.visibility = 'public' ORDER BY tm.created_at DESC, tm.id DESC) last_message_preview,
                    (SELECT TOP 1 tm.author_username FROM ticket_messages tm WHERE tm.ticket_id = t.id AND tm.visibility = 'public' ORDER BY tm.created_at DESC, tm.id DESC) last_responder
             FROM tickets t LEFT JOIN ticket_categories c ON c.id = t.category_id
             WHERE {$where} ORDER BY {$order} OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
            array_merge($params, [($page - 1) * $pageSize, $pageSize]),
        );

        $items = array_map(fn ($row): array => $this->serializeTicket((array) $row), $rows);

        $countRows = DB::connection()->select(
            'SELECT status, COUNT(*) count FROM tickets t WHERE '.implode(' AND ', $visibilityClauses).' GROUP BY status',
            $visibilityParams,
        );

        $counts = [];

        foreach ($countRows as $row) {
            $counts[$row->status] = (int) $row->count;
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
            'pages' => max(1, intdiv($total + $pageSize - 1, $pageSize)),
            'counts' => $counts,
            'status_labels' => self::STATUS_LABELS,
            'priority_labels' => self::PRIORITY_LABELS,
        ];
    }

    /**
     * `get_ticket()` — one ticket with its messages, attachments and events.
     *
     * @return array<string, mixed>|null `null` when the ticket does not exist
     *                                   **or** the actor may not see it — the
     *                                   two are one answer in the Python.
     */
    public function getTicket(int $ticketId, string $actor, bool $isAdmin): ?array
    {
        $ticket = $this->ticketRow($ticketId, $actor, $isAdmin);

        if ($ticket === null) {
            return null;
        }

        $isAdminFlag = $isAdmin ? 1 : 0;

        $messageRows = DB::connection()->select(
            'SELECT m.id, m.author_username, m.body, m.visibility, m.created_at, m.edited_at,
                    (SELECT COUNT(*) FROM ticket_attachments a WHERE a.message_id = m.id) attachment_count
             FROM ticket_messages m WHERE m.ticket_id = ?
             AND (? = 1 OR m.visibility = \'public\') ORDER BY m.created_at ASC, m.id ASC',
            [$ticketId, $isAdminFlag],
        );

        $messages = [];

        foreach ($messageRows as $message) {
            $message = (array) $message;
            // `pyodbc` returned the identity and the COUNT as integers.
            $message['id'] = (int) $message['id'];
            $message['attachment_count'] = (int) $message['attachment_count'];
            $message['created_at'] = LegacySerializer::isoUtcZ($message['created_at'] ?? null);
            $message['edited_at'] = LegacySerializer::isoUtcZ($message['edited_at'] ?? null);
            $messages[] = $message;
        }

        $attachmentRows = DB::connection()->select(
            'SELECT a.id, a.message_id, a.uploaded_by, a.original_name, a.content_type, a.size_bytes, a.created_at
             FROM ticket_attachments a WHERE a.ticket_id = ?
             AND (? = 1 OR a.message_id IS NULL OR EXISTS (
                 SELECT 1 FROM ticket_messages am
                 WHERE am.id = a.message_id AND am.visibility = \'public\'
             ))
             ORDER BY a.created_at ASC, a.id ASC',
            [$ticketId, $isAdminFlag],
        );

        $attachments = [];

        foreach ($attachmentRows as $attachment) {
            $attachment = (array) $attachment;
            $attachment['id'] = (int) $attachment['id'];
            $attachment['message_id'] = $attachment['message_id'] === null ? null : (int) $attachment['message_id'];
            $attachment['size_bytes'] = (int) $attachment['size_bytes'];
            $attachment['created_at'] = LegacySerializer::isoUtcZ($attachment['created_at'] ?? null);
            $attachment['download_url'] = "/api/tickets/{$ticketId}/attachments/{$attachment['id']}";
            $attachments[] = $attachment;
        }

        $eventRows = DB::connection()->select(
            'SELECT id, actor_username, event_type, metadata, created_at
             FROM ticket_events WHERE ticket_id = ? ORDER BY created_at ASC, id ASC',
            [$ticketId],
        );

        $events = [];

        foreach ($eventRows as $event) {
            $event = (array) $event;
            $event['id'] = (int) $event['id'];
            $event['created_at'] = LegacySerializer::isoUtcZ($event['created_at'] ?? null);
            $event['metadata'] = self::decodeMetadata($event['metadata'] ?? null);
            $events[] = $event;
        }

        $ticket = $this->serializeTicket($ticket);
        $ticket['messages'] = $messages;
        $ticket['attachments'] = $attachments;
        $ticket['events'] = $events;

        return $ticket;
    }

    /**
     * `create_ticket()` — open a conversation, with its first message, its
     * `created` event and the recipient's notification.
     *
     * The recipient must be a master administrator (checked here **and** in the
     * route, which refuses first), must exist, and must not be the sender.  The
     * SLA deadline is 48 hours out, taken from the database's clock.
     *
     * @return array<string, mixed> The new ticket, as `get_ticket` sees it.
     */
    public function createTicket(
        string $actor,
        bool $isAdmin,
        string $recipientUsername,
        string $subject,
        string $body,
        string $priority = 'normal',
        ?int $categoryId = null,
    ): array {
        return DB::connection()->transaction(function () use ($actor, $isAdmin, $recipientUsername, $subject, $body, $priority, $categoryId): array {
            if (! in_array(mb_strtolower($recipientUsername), self::masterAdminUsernames(), true)) {
                throw LegacyHttpException::detail(403, 'تیکت‌های پشتیبانی فقط برای مدیر اصلی سامانه ارسال می‌شوند.');
            }

            $recipient = $this->verifyUser($recipientUsername);

            if (mb_strtolower($recipient) === mb_strtolower($actor)) {
                throw LegacyHttpException::detail(422, 'ارسال تیکت برای خودتان مجاز نیست.');
            }

            // Re-checked after the pydantic strip: `" a"` is two characters long
            // and passes `min_length=2`, and is then one character long here.
            $subject = $this->cleanText($subject, 'موضوع', 2, 180);
            $body = $this->cleanText($body, 'توضیحات', 2, 4000);

            if (! in_array($priority, self::TICKET_PRIORITIES, true)) {
                throw LegacyHttpException::detail(422, 'اولویت تیکت معتبر نیست.');
            }

            if ($categoryId !== null) {
                $exists = DB::connection()->selectOne(
                    'SELECT 1 AS found FROM ticket_categories WHERE id = ? AND is_active = 1',
                    [$categoryId],
                );

                if ($exists === null) {
                    throw LegacyHttpException::detail(404, 'دسته‌بندی تیکت پیدا نشد.');
                }
            }

            $ticketId = DB::connection()->table('tickets')->insertGetId([
                'requester_username' => $actor,
                'recipient_username' => $recipient,
                'subject' => $subject,
                'status' => 'new',
                'priority' => $priority,
                'category_id' => $categoryId,
                'sla_due_at' => DB::raw('DATEADD(HOUR, 48, SYSUTCDATETIME())'),
            ]);

            $messageId = DB::connection()->table('ticket_messages')->insertGetId([
                'ticket_id' => $ticketId,
                'author_username' => $actor,
                'body' => $body,
                'visibility' => 'public',
            ]);

            $this->recordEvent($ticketId, $actor, 'created', ['message_id' => $messageId]);

            NotificationPublisher::publish(
                'تیکت جدید: '.$subject,
                'کاربر «'.$actor.'» برای شما تیکت جدیدی با موضوع «'.$subject.'» ثبت کرد.',
                [$recipient],
                'information',
                'normal',
                '/user_panel',
            );

            return $this->getTicket($ticketId, $actor, $isAdmin);
        });
    }

    /**
     * `add_message()` — post a reply or an internal note.
     *
     * Only a support reply moves the conversation into the user's queue; a reply
     * from either participant without admin privileges waits for support, even
     * when the ticket was admin-created.  A closed ticket refuses every message,
     * and an internal note is support-only.
     *
     * @return array<string, mixed> The ticket after the write.
     */
    public function addMessage(
        int $ticketId,
        string $actor,
        bool $isAdmin,
        string $body,
        string $visibility = 'public',
    ): array {
        return DB::connection()->transaction(function () use ($ticketId, $actor, $isAdmin, $body, $visibility): array {
            $body = $this->cleanText($body, 'متن پیام', 1, 4000);

            if (! in_array($visibility, self::MESSAGE_VISIBILITIES, true)) {
                throw LegacyHttpException::detail(422, 'نوع پیام معتبر نیست.');
            }

            $ticket = $this->ticketRow($ticketId, $actor, $isAdmin);

            if ($ticket === null) {
                throw LegacyHttpException::detail(404, 'تیکت پیدا نشد.');
            }

            if ($visibility === 'internal' && ! $isAdmin) {
                throw LegacyHttpException::detail(403, 'ثبت یادداشت داخلی فقط برای پشتیبانی مجاز است.');
            }

            if ($ticket['status'] === 'closed') {
                throw LegacyHttpException::detail(422, 'تیکت بسته‌شده قابل پاسخ نیست.');
            }

            $messageId = DB::connection()->table('ticket_messages')->insertGetId([
                'ticket_id' => $ticketId,
                'author_username' => $actor,
                'body' => $body,
                'visibility' => $visibility,
            ]);

            if ($visibility === 'internal') {
                $eventType = 'internal_note_added';
            } else {
                $eventType = 'reply_added';

                // Only a support/admin reply puts the conversation in the user's
                // queue; a reply from either participant without admin privileges
                // must wait for support, even when the ticket was admin-created.
                $nextStatus = $isAdmin ? 'waiting_for_user' : 'waiting_for_support';

                DB::connection()->update(
                    'UPDATE tickets SET status = ?, updated_at = SYSUTCDATETIME(), last_message_at = SYSUTCDATETIME(),
                     first_response_at = CASE WHEN first_response_at IS NULL AND ? = 1 THEN SYSUTCDATETIME() ELSE first_response_at END
                     WHERE id = ?',
                    [$nextStatus, ($isAdmin || $actor !== $ticket['requester_username']) ? 1 : 0, $ticketId],
                );
            }

            $this->recordEvent($ticketId, $actor, $eventType, [
                'message_id' => $messageId,
                'visibility' => $visibility,
            ]);

            if ($visibility === 'public') {
                $requester = LegacyWhitespace::strip((string) ($ticket['requester_username'] ?? ''));
                $recipient = LegacyWhitespace::strip((string) ($ticket['recipient_username'] ?? ''));
                $target = mb_strtolower($actor) !== mb_strtolower($requester) ? $requester : $recipient;

                if ($target !== '') {
                    NotificationPublisher::publish(
                        'پاسخ جدید به تیکت: '.$ticket['subject'],
                        'کاربر «'.$actor.'» به تیکت «'.$ticket['subject'].'» پاسخ جدید داد.',
                        [$target],
                        'information',
                        'normal',
                        '/user_panel',
                    );
                }
            }

            return $this->getTicket($ticketId, $actor, $isAdmin);
        });
    }

    /**
     * `update_ticket()` — change a ticket's status, priority, category or
     * assignee, with the event rows and notifications each change writes.
     *
     * An ordinary user may only move the ticket to `resolved` or `open`, and
     * only when nothing else is being changed; every other field is support's.
     * A status change must follow {@see ALLOWED_TRANSITIONS}.  With nothing to
     * change the ticket is returned as-is — no UPDATE, no event, no notification.
     *
     * @return array<string, mixed> The ticket after the write.
     */
    public function updateTicket(
        int $ticketId,
        string $actor,
        bool $isAdmin,
        ?string $status = null,
        ?string $priority = null,
        ?int $categoryId = null,
        ?string $assignedTo = null,
    ): array {
        return DB::connection()->transaction(function () use ($ticketId, $actor, $isAdmin, $status, $priority, $categoryId, $assignedTo): array {
            $ticket = $this->ticketRow($ticketId, $actor, $isAdmin);

            if ($ticket === null) {
                throw LegacyHttpException::detail(404, 'تیکت پیدا نشد.');
            }

            if (! $isAdmin && ($status !== null || $priority !== null || $categoryId !== null || $assignedTo !== null)) {
                if (! in_array($status, ['resolved', 'open'], true) || $priority !== null || $categoryId !== null || $assignedTo !== null) {
                    throw LegacyHttpException::detail(403, 'تغییر این مشخصات فقط برای پشتیبانی مجاز است.');
                }
            }

            if ($status !== null) {
                if (! in_array($status, self::TICKET_STATUSES, true)) {
                    throw LegacyHttpException::detail(422, 'وضعیت تیکت معتبر نیست.');
                }

                if ($status !== $ticket['status'] && ! in_array($status, self::ALLOWED_TRANSITIONS[$ticket['status']] ?? [], true)) {
                    throw LegacyHttpException::detail(422, 'تغییر وضعیت انتخاب‌شده مجاز نیست.');
                }
            }

            if ($priority !== null && ! in_array($priority, self::TICKET_PRIORITIES, true)) {
                throw LegacyHttpException::detail(422, 'اولویت تیکت معتبر نیست.');
            }

            if ($categoryId !== null) {
                $exists = DB::connection()->selectOne(
                    'SELECT 1 AS found FROM ticket_categories WHERE id = ? AND is_active = 1',
                    [$categoryId],
                );

                if ($exists === null) {
                    throw LegacyHttpException::detail(404, 'دسته‌بندی تیکت پیدا نشد.');
                }
            }

            // An empty string assigns nobody: `if assigned_to else None`.
            if ($assignedTo !== null) {
                $assignedTo = LegacyWhitespace::strip($assignedTo) !== '' ? $this->verifyUser($assignedTo) : null;
            }

            $assignments = [];
            $params = [];

            if ($status !== null) {
                $assignments[] = 'status = ?';
                $params[] = $status;

                if ($status === 'resolved') {
                    $assignments[] = 'resolved_at = SYSUTCDATETIME()';
                } elseif ($status === 'closed') {
                    $assignments[] = 'closed_at = SYSUTCDATETIME()';
                } elseif ($status === 'open') {
                    $assignments[] = 'resolved_at = NULL';
                    $assignments[] = 'closed_at = NULL';
                }
            }

            if ($priority !== null) {
                $assignments[] = 'priority = ?';
                $params[] = $priority;
            }

            if ($categoryId !== null) {
                $assignments[] = 'category_id = ?';
                $params[] = $categoryId;
            }

            if ($assignedTo !== null) {
                $assignments[] = 'assigned_to = ?';
                $params[] = $assignedTo;
            }

            if ($assignments === []) {
                return $this->getTicket($ticketId, $actor, $isAdmin);
            }

            $assignments[] = 'updated_at = SYSUTCDATETIME()';

            DB::connection()->update(
                'UPDATE tickets SET '.implode(', ', $assignments).' WHERE id = ?',
                array_merge($params, [$ticketId]),
            );

            if ($status !== null && $status !== $ticket['status']) {
                $this->recordEvent($ticketId, $actor, 'status_changed', [
                    'from' => $ticket['status'],
                    'to' => $status,
                ]);

                $target = $isAdmin
                    ? LegacyWhitespace::strip((string) ($ticket['requester_username'] ?? ''))
                    : LegacyWhitespace::strip((string) ($ticket['recipient_username'] ?? ''));

                if ($target !== '' && mb_strtolower($target) !== mb_strtolower($actor)) {
                    NotificationPublisher::publish(
                        'وضعیت تیکت تغییر کرد: '.$ticket['subject'],
                        'وضعیت تیکت «'.$ticket['subject'].'» به «'.(self::STATUS_LABELS[$status] ?? $status).'» تغییر کرد.',
                        [$target],
                        in_array($status, ['resolved', 'closed'], true) ? 'success' : 'information',
                        'normal',
                        '/user_panel',
                    );
                }
            }

            if ($priority !== null && $priority !== $ticket['priority']) {
                $this->recordEvent($ticketId, $actor, 'priority_changed', [
                    'from' => $ticket['priority'],
                    'to' => $priority,
                ]);

                $requester = LegacyWhitespace::strip((string) ($ticket['requester_username'] ?? ''));
                $recipient = LegacyWhitespace::strip((string) ($ticket['recipient_username'] ?? ''));
                $target = mb_strtolower($actor) !== mb_strtolower($requester) ? $requester : $recipient;

                if ($target !== '' && mb_strtolower($target) !== mb_strtolower($actor)) {
                    NotificationPublisher::publish(
                        'اولویت تیکت تغییر کرد: '.$ticket['subject'],
                        'اولویت تیکت «'.$ticket['subject'].'» به «'.(self::PRIORITY_LABELS[$priority] ?? $priority).'» تغییر کرد.',
                        [$target],
                        in_array($priority, ['high', 'urgent'], true) ? 'warning' : 'information',
                        'normal',
                        '/user_panel',
                    );
                }
            }

            if ($assignedTo !== null && $assignedTo !== ($ticket['assigned_to'] ?? null)) {
                $this->recordEvent($ticketId, $actor, 'assigned', ['assignee' => $assignedTo]);

                if ($assignedTo !== '' && mb_strtolower($assignedTo) !== mb_strtolower($actor)) {
                    NotificationPublisher::publish(
                        'تیکت به شما واگذار شد: '.$ticket['subject'],
                        'تیکت «'.$ticket['subject'].'» برای بررسی به شما واگذار شد.',
                        [$assignedTo],
                        'information',
                        'normal',
                        '/admin',
                    );
                }
            }

            return $this->getTicket($ticketId, $actor, $isAdmin);
        });
    }

    /**
     * `add_attachment()` — record a stored file against a ticket.
     *
     * The bytes are already on disk (the route stored them before calling here);
     * this writes the row, the `attachment_added` event and — when the file is
     * attached to a public message or to no message at all — the other
     * participant's notification.  A closed ticket refuses the change.
     *
     * @param  array<string, mixed>  $metadata  The store's return value, with
     *                                          `message_id` merged in by the route.
     * @return array<string, mixed> `{id, download_url, original_name}`
     */
    public function addAttachment(int $ticketId, string $actor, bool $isAdmin, array $metadata): array
    {
        return DB::connection()->transaction(function () use ($ticketId, $actor, $isAdmin, $metadata): array {
            $ticket = $this->ticketRow($ticketId, $actor, $isAdmin);

            if ($ticket === null) {
                throw LegacyHttpException::detail(404, 'تیکت پیدا نشد.');
            }

            if ($ticket['status'] === 'closed') {
                throw LegacyHttpException::detail(422, 'تیکت بسته‌شده قابل تغییر نیست.');
            }

            $messageVisibility = 'public';

            if ($metadata['message_id'] !== null) {
                $messageRow = DB::connection()->selectOne(
                    'SELECT visibility FROM ticket_messages WHERE id = ? AND ticket_id = ?',
                    [$metadata['message_id'], $ticketId],
                );

                if ($messageRow === null) {
                    throw LegacyHttpException::detail(404, 'پیام مقصد پیوست پیدا نشد.');
                }

                $messageVisibility = trim((string) $messageRow->visibility);
            }

            $attachmentId = DB::connection()->table('ticket_attachments')->insertGetId([
                'ticket_id' => $ticketId,
                'message_id' => $metadata['message_id'],
                'uploaded_by' => $actor,
                'original_name' => $metadata['original_name'],
                'storage_name' => $metadata['storage_name'],
                'content_type' => $metadata['content_type'],
                'size_bytes' => $metadata['size_bytes'],
            ]);

            $this->recordEvent($ticketId, $actor, 'attachment_added', ['attachment_id' => $attachmentId]);

            if ($messageVisibility === 'public') {
                $requester = LegacyWhitespace::strip((string) ($ticket['requester_username'] ?? ''));
                $recipient = LegacyWhitespace::strip((string) ($ticket['recipient_username'] ?? ''));
                $target = mb_strtolower($actor) !== mb_strtolower($requester) ? $requester : $recipient;

                if ($target !== '') {
                    NotificationPublisher::publish(
                        'پیوست جدید به تیکت: '.$ticket['subject'],
                        'یک فایل جدید از طرف «'.$actor.'» در تیکت «'.$ticket['subject'].'» ارسال شد.',
                        [$target],
                        'information',
                        'normal',
                        '/user_panel',
                    );
                }
            }

            return [
                'id' => $attachmentId,
                'download_url' => "/api/tickets/{$ticketId}/attachments/{$attachmentId}",
                'original_name' => $metadata['original_name'],
            ];
        });
    }

    /**
     * `attachment()` — the download lookup.
     *
     * `null` when the ticket is not accessible, when the attachment is not on it,
     * or when it hangs off an internal message the actor may not see — the
     * download route answers one `404 فایل پیدا نشد.` for all three.
     *
     * @return array<string, mixed>|null
     */
    public function attachment(int $ticketId, int $attachmentId, string $actor, bool $isAdmin): ?array
    {
        if ($this->ticketRow($ticketId, $actor, $isAdmin) === null) {
            return null;
        }

        $isAdminFlag = $isAdmin ? 1 : 0;

        $row = DB::connection()->selectOne(
            'SELECT a.id, a.original_name, a.storage_name, a.content_type, a.size_bytes
             FROM ticket_attachments a WHERE a.id = ? AND a.ticket_id = ?
             AND (? = 1 OR a.message_id IS NULL OR EXISTS (
                 SELECT 1 FROM ticket_messages am
                 WHERE am.id = a.message_id AND am.visibility = \'public\'
             ))',
            [$attachmentId, $ticketId, $isAdminFlag],
        );

        if ($row === null) {
            return null;
        }

        $data = (array) $row;

        return [
            'id' => (int) $data['id'],
            'original_name' => $data['original_name'],
            'storage_name' => $data['storage_name'],
            'content_type' => $data['content_type'],
            'size_bytes' => (int) $data['size_bytes'],
        ];
    }

    /**
     * `_ticket_row()` — the ticket with the actor's row-level ownership check.
     *
     * An administrator sees every ticket; anybody else must be the requester, the
     * recipient or the assignee, compared exactly (the Python's `in` on a set of
     * trimmed strings — case-sensitively).
     *
     * @return array<string, mixed>|null
     */
    private function ticketRow(int $ticketId, string $actor, bool $isAdmin): ?array
    {
        $row = DB::connection()->selectOne(
            'SELECT t.id, t.legacy_parent_id, t.requester_username, t.recipient_username,
                    t.subject, t.status, t.priority, t.category_id, c.name category_name,
                    t.assigned_to, t.created_at, t.updated_at, t.last_message_at,
                    t.first_response_at, t.resolved_at, t.closed_at, t.sla_due_at
             FROM tickets t LEFT JOIN ticket_categories c ON c.id = t.category_id
             WHERE t.id = ?',
            [$ticketId],
        );

        if ($row === null) {
            return null;
        }

        $data = (array) $row;

        if (! $isAdmin) {
            $allowed = [
                LegacyWhitespace::strip((string) ($data['requester_username'] ?? '')),
                LegacyWhitespace::strip((string) ($data['recipient_username'] ?? '')),
                LegacyWhitespace::strip((string) ($data['assigned_to'] ?? '')),
            ];

            if (! in_array($actor, $allowed, true)) {
                return null;
            }
        }

        return $data;
    }

    /**
     * `_serialize_ticket()` — the ticket's published shape.
     *
     * The base row is coerced through {@see LegacySerializer::row} so the types
     * are `pyodbc`'s — `id` and `category_id` integers, not the driver's strings
     * — and the seven timestamps are then re-stamped with {@see
     * LegacySerializer::isoUtcZ}, which is the ticketing module's `_iso()`:
     * `T` separator, six digits or none, and a `Z` suffix.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function serializeTicket(array $row): array
    {
        $result = LegacySerializer::row('tickets', $row) ?? [];

        $ticketId = (int) ($row['id'] ?? 0);

        // `str(row.get("status") or "new").strip()` — the default applies to a
        // null or empty value, and the strip happens after, so a whitespace-only
        // status serialises as an empty string rather than as the default.
        $rawStatus = $row['status'] ?? null;
        $status = LegacyWhitespace::strip((string) ($rawStatus === null || $rawStatus === '' ? 'new' : $rawStatus));

        $rawPriority = $row['priority'] ?? null;
        $priority = LegacyWhitespace::strip((string) ($rawPriority === null || $rawPriority === '' ? 'normal' : $rawPriority));

        $result['id'] = $ticketId;
        $result['ticket_number'] = $this->ticketNumber($ticketId);
        $result['status'] = $status;
        $result['status_label'] = self::STATUS_LABELS[$status] ?? $status;
        $result['priority'] = $priority;
        $result['priority_label'] = self::PRIORITY_LABELS[$priority] ?? $priority;

        foreach (['created_at', 'updated_at', 'last_message_at', 'first_response_at', 'resolved_at', 'closed_at', 'sla_due_at'] as $key) {
            if (array_key_exists($key, $result)) {
                $result[$key] = LegacySerializer::isoUtcZ($result[$key]);
            }
        }

        $due = $result['sla_due_at'] ?? null;

        if (is_string($due) && $due !== '') {
            try {
                $dueDt = CarbonImmutable::parse(str_replace('Z', '+00:00', $due));
                $result['sla_state'] = $dueDt < CarbonImmutable::now('UTC') ? 'overdue' : 'healthy';
            } catch (Throwable) {
                $result['sla_state'] = 'unknown';
            }
        } else {
            $result['sla_state'] = 'none';
        }

        return $result;
    }

    private function ticketNumber(int $ticketId): string
    {
        return 'HT-'.str_pad((string) $ticketId, 8, '0', STR_PAD_LEFT);
    }

    /**
     * `_event()` — one `ticket_events` row.
     *
     * @param  array<string, mixed>  $metadata
     */
    private function recordEvent(int $ticketId, string $actor, string $eventType, array $metadata): void
    {
        DB::connection()->table('ticket_events')->insert([
            'ticket_id' => $ticketId,
            'actor_username' => $actor,
            'event_type' => $eventType,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * `_verify_user()` — the trimmed, existing username, or a `404`.
     */
    private function verifyUser(string $username): string
    {
        $username = $this->cleanText($username, 'نام کاربری', 1, 255);

        $row = DB::connection()->selectOne(
            'SELECT TOP 1 LTRIM(RTRIM(username)) AS username FROM user_table WHERE LTRIM(RTRIM(username)) = ?',
            [$username],
        );

        if ($row === null) {
            throw LegacyHttpException::detail(404, 'کاربر موردنظر پیدا نشد.');
        }

        return LegacyWhitespace::strip((string) $row->username);
    }

    /**
     * `clean_text()` — strip, then the length bounds, in characters.
     *
     * The minimum is checked **after** the strip, which is why a subject of
     * `" a"` passes pydantic's `min_length=2` and is then refused here.
     */
    private function cleanText(string $value, string $field, int $minimum, int $maximum): string
    {
        $value = str_replace("\x00", '', $value);
        $value = LegacyWhitespace::strip($value);

        $length = mb_strlen($value);

        if ($length < $minimum) {
            throw LegacyHttpException::detail(422, $field.' الزامی است.');
        }

        if ($length > $maximum) {
            throw LegacyHttpException::detail(422, 'طول '.$field.' بیشتر از حد مجاز است.');
        }

        return $value;
    }

    /**
     * `_master_admin_usernames()` — the case-folded allow-list, from
     * `MASTER_ADMIN_USERNAMES` (default `ali`).
     *
     * @return array<int, string>
     */
    private static function masterAdminUsernames(): array
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

    /**
     * `json.loads(event["metadata"] or "{}")` — decoded to a JSON **object**,
     * not a PHP array.
     *
     * The distinction is observable: an empty object reaches the wire as `{}`
     * here and `[]` if it were decoded to a PHP array.  A `null` or empty column
     * becomes `{}`, and undecodable text becomes `{}` too — the Python's
     * `except (TypeError, ValueError)` branch.
     */
    private static function decodeMetadata(mixed $value): mixed
    {
        $text = ($value === null || $value === '') ? '{}' : (string) $value;

        $decoded = json_decode($text);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : new \stdClass;
    }
}
