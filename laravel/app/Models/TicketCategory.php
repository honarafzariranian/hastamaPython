<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `dbo.ticket_categories` — the ticket classification tree (4 rows).
 *
 * Self-referencing through `parent_id` with a real FK
 * (`FK_ticket_categories_parent`), and `slug` is globally unique.  Deactivating a
 * category is `is_active = 0`; existing tickets keep pointing at it, so nothing
 * filters by `is_active` for display, only for the picker.
 */
#[Table(name: 'ticket_categories', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['name', 'slug', 'parent_id', 'is_active'])]
class TicketCategory extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id', 'id');
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'category_id', 'id');
    }

    /** Top-level categories, i.e. the ones the picker shows first. */
    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    /** Categories offered in the picker. */
    public function scopeSelectable(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
