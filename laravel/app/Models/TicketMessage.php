<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `dbo.ticket_messages` — the conversation body of a ticket (19 rows).
 *
 * `visibility` is the authorisation switch and it is load-bearing:
 * `internal` messages are staff notes and must never be returned to the
 * requester, while `public` ones are.  The Python service applies exactly that
 * filter, so queries that feed a user-facing screen go through
 * {@see self::scopeVisibleToUser()}.
 *
 * `legacy_message_id` is unique and maps a migrated `ticket_table` row onto its
 * new home, which is what makes the forward migration repeatable.
 */
#[Table(name: 'ticket_messages', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'ticket_id', 'author_username', 'body', 'visibility', 'legacy_message_id',
    'edited_at',
])]
class TicketMessage extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column; edits are stamped in `edited_at`. */
    public const UPDATED_AT = null;

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_INTERNAL = 'internal';

    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_INTERNAL];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'legacy_message_id' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class, 'message_id', 'id');
    }

    /** What a requester is permitted to read. */
    public function scopeVisibleToUser(Builder $query): Builder
    {
        return $query->where('visibility', self::VISIBILITY_PUBLIC);
    }
}
