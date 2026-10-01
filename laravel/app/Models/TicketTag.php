<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `dbo.ticket_tags` — free-form ticket labels (0 rows).
 *
 * Both `name` and `slug` are unique.  Tickets are attached through
 * {@see TicketTagRelation}, which carries its own FK to this table.
 */
#[Table(name: 'ticket_tags', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['name', 'slug'])]
class TicketTag extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    public function relations(): HasMany
    {
        return $this->hasMany(TicketTagRelation::class, 'tag_id', 'id');
    }
}
