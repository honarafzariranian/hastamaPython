<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.admin_actions` — the human-readable admin activity feed (133 rows).
 *
 * Distinct from {@see AuditLog}, which is the machine-readable trail: this table
 * is what the control centre renders, with `description` already written in
 * Persian and `before_data` / `after_data` holding the JSON snapshot of the
 * change.  Both are written for the same operation; neither replaces the other.
 *
 * `action_id` (`varchar(32)`) and `request_id` correlate the feed entry with the
 * audit entries raised for the same request.
 */
#[Table(name: 'admin_actions', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'action_id', 'admin_username', 'action', 'target_username', 'target_type',
    'target_id', 'description', 'before_data', 'after_data', 'ip_address',
    'request_id',
])]
class AdminAction extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
        ];
    }

    public function scopeByAdmin(Builder $query, string $username): Builder
    {
        return $query->where('admin_username', $username);
    }

    public function scopeAboutUser(Builder $query, string $username): Builder
    {
        return $query->where('target_username', $username);
    }
}
