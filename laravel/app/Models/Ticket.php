<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `dbo.tickets` — the current ticketing feature (3 rows).
 *
 * The status machine is enforced by the application
 * (`app/services/ticketing.py::ALLOWED_TRANSITIONS`), not by the database, so it
 * is transcribed here in full: a transition that is not in the table below must be
 * refused, because the original code refused it — reopening is deliberate and
 * auditable, and a closed conversation must not silently behave as active.
 *
 * Two columns link the new table to the legacy one, and both are **unique**, which
 * is what makes the forward migration idempotent:
 * `legacy_parent_id` (→ `ticket_table.id`) and, on
 * {@see TicketMessage}, `legacy_message_id`.
 *
 * `sla_due_at`, `first_response_at`, `resolved_at` and `closed_at` are the
 * timestamps the inbox sorts and colours by; `last_message_at` drives the
 * conversation ordering.
 */
#[Table(name: 'tickets', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'legacy_parent_id', 'requester_username', 'recipient_username', 'subject',
    'status', 'priority', 'category_id', 'assigned_to', 'last_message_at',
    'first_response_at', 'resolved_at', 'closed_at', 'sla_due_at',
])]
class Ticket extends LegacyTimestampedModel
{
    /** Accepted statuses, in the order the service defines them. */
    public const STATUSES = [
        'new', 'open', 'in_progress', 'waiting_for_user',
        'waiting_for_support', 'resolved', 'closed',
    ];

    /** Accepted priorities. */
    public const PRIORITIES = ['low', 'normal', 'high', 'urgent'];

    /** Persian labels exactly as the UI renders them. */
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
     * The only transitions the existing service allows.
     *
     * @var array<string, array<int, string>>
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'sla_due_at' => 'datetime',
            'legacy_parent_id' => 'integer',
            'category_id' => 'integer',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id', 'id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class, 'ticket_id', 'id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'ticket_id', 'id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id', 'id');
    }

    /** Whether a status change is allowed by the existing state machine. */
    public static function transitionAllowed(string $from, string $to): bool
    {
        if ($from === $to) {
            return true;
        }

        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    /** Conversations that are still being worked on. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', ['resolved', 'closed']);
    }

    /** Admin-side filter: tickets assigned to nobody. */
    public function scopeUnassigned(Builder $query): Builder
    {
        return $query->whereNull('assigned_to');
    }
}
