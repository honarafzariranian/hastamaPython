<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `dbo.ticket_events` — the per-ticket activity log (19 rows).
 *
 * An append-only list of what happened to a conversation (`event_type` +
 * `actor_username`), shown as the ticket's history strip.  `metadata` is
 * `nvarchar(2000)` holding a small JSON object and is cast to `array`.
 *
 * Like the other ticket tables it has `created_at` only.
 */
#[Table(name: 'ticket_events', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['ticket_id', 'actor_username', 'event_type', 'metadata'])]
class TicketEvent extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }
}
