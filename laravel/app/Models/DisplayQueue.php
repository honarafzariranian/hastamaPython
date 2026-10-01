<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.display_queue` — the slots the TV display shows (1 row).
 *
 * The display renders a fixed strip of numbered boxes; `slot_position` says which
 * box, and the unique-per-position ordering is what keeps the layout stable when a
 * call is completed.  `reception_number` is the number being shown and `called_by`
 * the operator who sent it.
 *
 * `department` defaults to «نمونه‌گیری», matching {@see ReceptionCall}.
 */
#[Table(name: 'display_queue', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['reception_number', 'department', 'called_by', 'slot_position'])]
class DisplayQueue extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    /** Default department, as stored in the column default. */
    public const DEFAULT_DEPARTMENT = 'نمونه‌گیری';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'slot_position' => 'integer',
        ];
    }

    /** The display order of the slots. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('slot_position');
    }
}
