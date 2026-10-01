<?php

namespace App\Support\Ticketing;

use App\Support\Legacy\LegacySerializer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Core ticketing domain service.
 *
 * Ported from `app/services/ticketing.py`.  The legacy application stores one
 * conversation as several rows in `ticket_table`.  This service uses the
 * normalized tables from `database/ticketing.sql` and keeps all authorization
 * and lifecycle rules in one place.  Routes should only validate transport
 * data and delegate here.
 *
 * The Python's `ensure_schema` is not reproduced: the normalized schema is
 * already installed on the live database (see `docs/migration/DATABASE_SCHEMA.md`).
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
     * List tickets with filtering, sorting and pagination.
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
     * @return array<int, array<string, mixed>>
     */
    public function categories(): array
    {
        $rows = DB::connection()->select(
            'SELECT id, name, slug, parent_id FROM ticket_categories WHERE is_active = 1 ORDER BY name',
        );

        return array_map(static fn ($row): array => (array) $row, $rows);
    }

    /**
     * One ticket with its messages, attachments and events.
     *
     * @return array<string, mixed>|null
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
            $event['created_at'] = LegacySerializer::isoUtcZ($event['created_at'] ?? null);

            $metadata = $event['metadata'] ?? null;

            if (is_string($metadata)) {
                $decoded = json_decode($metadata, true);
                $event['metadata'] = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
            } else {
                $event['metadata'] = [];
            }

            $events[] = $event;
        }

        $ticket = $this->serializeTicket($ticket);
        $ticket['messages'] = $messages;
        $ticket['attachments'] = $attachments;
        $ticket['events'] = $events;

        return $ticket;
    }

    /**
     * Update a ticket's status, priority, category or assignee.
     *
     * @return array<string, mixed>
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
        $ticket = $this->ticketRow($ticketId, $actor, $isAdmin);

        if ($ticket === null) {
            throw new TicketLookupException('تیکت پیدا نشد.');
        }

        if (! $isAdmin && ($status !== null || $priority !== null || $categoryId !== null || $assignedTo !== null)) {
            if (! in_array($status, ['resolved', 'open'], true) || $priority !== null || $categoryId !== null || $assignedTo !== null) {
                throw new TicketPermissionException('تغییر این مشخصات فقط برای پشتیبانی مجاز است.');
            }
        }

        if ($status !== null) {
            if (! in_array($status, self::TICKET_STATUSES, true)) {
                throw new TicketValidationException('وضعیت تیکت معتبر نیست.');
            }

            if ($status !== $ticket['status'] && ! in_array($status, self::ALLOWED_TRANSITIONS[$ticket['status']] ?? [], true)) {
                throw new TicketValidationException('تغییر وضعیت انتخاب‌شده مجاز نیست.');
            }
        }

        if ($priority !== null && ! in_array($priority, self::TICKET_PRIORITIES, true)) {
            throw new TicketValidationException('اولویت تیکت معتبر نیست.');
        }

        if ($categoryId !== null) {
            $exists = DB::connection()->selectOne(
                'SELECT 1 AS found FROM ticket_categories WHERE id = ? AND is_active = 1',
                [$categoryId],
            );

            if ($exists === null) {
                throw new TicketLookupException('دسته‌بندی تیکت پیدا نشد.');
            }
        }

        if ($assignedTo !== null) {
            $assignedTo = trim($assignedTo) !== '' ? $this->verifyUser($assignedTo) : null;
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
                ? trim((string) ($ticket['requester_username'] ?? ''))
                : trim((string) ($ticket['recipient_username'] ?? ''));

            if ($target !== '' && mb_strtolower($target) !== mb_strtolower($actor)) {
                NotificationPublisher::publish(
                    'وضعیت تیکت تغییر کرد: '.$ticket['subject'],
                    'وضعیت تیکت «'.$ticket['subject'].'» به «'.(self::STATUS_LABELS[$status] ?? $status).'» تغییر کرد.',
                    [$target],
                    in_array($status, ['resolved', 'closed'], true) ? 'success' : 'information',
                    'information',
                    '/user_panel',
                );
            }
        }

        if ($priority !== null && $priority !== $ticket['priority']) {
            $this->recordEvent($ticketId, $actor, 'priority_changed', [
                'from' => $ticket['priority'],
                'to' => $priority,
            ]);

            $requester = trim((string) ($ticket['requester_username'] ?? ''));
            $recipient = trim((string) ($ticket['recipient_username'] ?? ''));
            $target = mb_strtolower($actor) !== mb_strtolower($requester) ? $requester : $recipient;

            if ($target !== '' && mb_strtolower($target) !== mb_strtolower($actor)) {
                NotificationPublisher::publish(
                    'اولویت تیکت تغییر کرد: '.$ticket['subject'],
                    'اولویت تیکت «'.$ticket['subject'].'» به «'.(self::PRIORITY_LABELS[$priority] ?? $priority).'» تغییر کرد.',
                    [$target],
                    in_array($priority, ['high', 'urgent'], true) ? 'warning' : 'information',
                    'information',
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
                    'information',
                    '/admin',
                );
            }
        }

        return $this->getTicket($ticketId, $actor, $isAdmin);
    }

    /**
     * Add a message to a ticket.
     *
     * @return array<string, mixed>
     */
    public function addMessage(
        int $ticketId,
        string $actor,
        bool $isAdmin,
        string $body,
        string $visibility = 'public',
    ): array {
        $body = $this->cleanText($body, 'متن پیام', 1, 4000);

        if (! in_array($visibility, self::MESSAGE_VISIBILITIES, true)) {
            throw new TicketValidationException('نوع پیام معتبر نیست.');
        }

        $ticket = $this->ticketRow($ticketId, $actor, $isAdmin);

        if ($ticket === null) {
            throw new TicketLookupException('تیکت پیدا نشد.');
        }

        if ($visibility === 'internal' && ! $isAdmin) {
            throw new TicketPermissionException('ثبت یادداشت داخلی فقط برای پشتیبانی مجاز است.');
        }

        if ($ticket['status'] === 'closed') {
            throw new TicketValidationException('تیکت بسته‌شده قابل پاسخ نیست.');
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
            $requester = trim((string) ($ticket['requester_username'] ?? ''));
            $recipient = trim((string) ($ticket['recipient_username'] ?? ''));
            $target = mb_strtolower($actor) !== mb_strtolower($requester) ? $requester : $recipient;

            if ($target !== '') {
                NotificationPublisher::publish(
                    'پاسخ جدید به تیکت: '.$ticket['subject'],
                    'کاربر «'.$actor.'» به تیکت «'.$ticket['subject'].'» پاسخ جدید داد.',
                    [$target],
                    'information',
                    'information',
                    '/user_panel',
                );
            }
        }

        return $this->getTicket($ticketId, $actor, $isAdmin);
    }

    /**
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
                trim((string) ($data['requester_username'] ?? '')),
                trim((string) ($data['recipient_username'] ?? '')),
                trim((string) ($data['assigned_to'] ?? '')),
            ];

            if (! in_array($actor, $allowed, true)) {
                return null;
            }
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function serializeTicket(array $row): array
    {
        $ticketId = (int) $row['id'];
        $status = trim((string) ($row['status'] ?? 'new'));
        $priority = trim((string) ($row['priority'] ?? 'normal'));

        $result = $row;
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

    private function verifyUser(string $username): string
    {
        $username = $this->cleanText($username, 'نام کاربری', 1, 255);

        $row = DB::connection()->selectOne(
            'SELECT TOP 1 LTRIM(RTRIM(username)) AS username FROM user_table WHERE LTRIM(RTRIM(username)) = ?',
            [$username],
        );

        if ($row === null) {
            throw new TicketLookupException('کاربر موردنظر پیدا نشد.');
        }

        return trim((string) $row->username);
    }

    private function cleanText(string $value, string $field, int $minimum, int $maximum): string
    {
        $value = str_replace("\x00", '', $value);
        $value = trim($value);

        if (mb_strlen($value) < $minimum) {
            throw new TicketValidationException($field.' الزامی است.');
        }

        if (mb_strlen($value) > $maximum) {
            throw new TicketValidationException('طول '.$field.' بیشتر از حد مجاز است.');
        }

        return $value;
    }
}

class TicketLookupException extends RuntimeException {}
class TicketValidationException extends RuntimeException {}
class TicketPermissionException extends RuntimeException {}
