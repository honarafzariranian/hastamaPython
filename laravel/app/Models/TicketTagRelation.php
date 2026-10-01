<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `dbo.ticket_tag_relations` — the ticket ↔ tag pivot (0 rows).
 *
 * Composite primary key `(tag_id, ticket_id)` with FKs to both sides.  Eloquent
 * cannot address a composite key, so `ticket_id` is declared as the key for reads
 * and the unique index stays the real guarantee; rows are attached and detached,
 * never updated in place.
 */
#[Table(name: 'ticket_tag_relations', key: 'ticket_id', keyType: 'int', incrementing: false, timestamps: true)]
#[Fillable(['ticket_id', 'tag_id'])]
class TicketTagRelation extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column — only `created_at`. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_id' => 'integer',
            'tag_id' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    public function tag(): BelongsTo
    {
        return $this->belongsTo(TicketTag::class, 'tag_id', 'id');
    }
}
