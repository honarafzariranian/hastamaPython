<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.slides` — images shown on the display's slideshow (4 rows).
 *
 * As with ticket attachments, only the metadata lives here: `filename` is the
 * stored name on disk and `original_name` is what the operator uploaded, so the
 * original name never becomes a filesystem path.
 *
 * `is_active` is a real `bit` and `sort_order` controls playback order; the admin
 * toggle writes `is_active` through `POST /api/call-system/slides/{id}/toggle`,
 * which is why the model exposes {@see self::scopeActive()} and
 * {@see self::scopeOrdered()} separately — the admin list needs to see inactive
 * slides too.
 */
#[Table(name: 'slides', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['filename', 'original_name', 'is_active', 'sort_order'])]
class Slide extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** What the display plays. */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** Playback order. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}
