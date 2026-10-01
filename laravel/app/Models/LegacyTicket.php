<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.ticket_table` — the **legacy** ticket store (1 row).
 *
 * Kept because the newest ticketing feature migrates old rows forward: the new
 * `tickets` table carries `legacy_parent_id` and the new `ticket_messages` table
 * carries a unique `legacy_message_id`, so this table's threads are read during
 * that migration and are still reachable from the UI.
 *
 * Column names are the legacy camelCase ones (`ticketTitle`,
 * `ticketDescription`, `Parent_id`) and the key is `id` — an identity column.
 * `is_read` is `varchar(max)` holding `0`/`1`, not a bit.
 */
#[Table(name: 'ticket_table', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable([
    'ticketTitle', 'ticketDescription', 'username', 'ticket_date',
    'ticket_status', 'target_username', 'Parent_id', 'is_read',
])]
class LegacyTicket extends LegacyModel
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ticket_date' => 'datetime',
            'Parent_id' => 'integer',
        ];
    }

    /** The thread a reply belongs to; `null` marks a root ticket. */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('Parent_id');
    }

    /** Replies to a given root ticket. */
    public function scopeRepliesTo(Builder $query, int $parentId): Builder
    {
        return $query->where('Parent_id', $parentId);
    }
}
