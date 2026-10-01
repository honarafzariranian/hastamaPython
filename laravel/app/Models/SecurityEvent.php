<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.security_events` — security findings raised by the application (4 rows).
 *
 * Distinct from {@see AuditLog}: an audit entry records *that an action happened*,
 * a security event records *something that needs looking at* — a blocked login, a
 * lockout, a rejected CSRF token — and carries a `status` that an operator closes
 * by resolving it (`resolved_by` / `resolved_at`).  Retention is
 * `system_config.security_retention_days` (730).
 *
 * The table has no relation to `user_table`: `username` is `nvarchar(255)` and may
 * name an account that no longer exists, or nobody at all.
 */
#[Table(name: 'security_events', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'event_id', 'event_type', 'severity', 'username', 'ip_address',
    'description', 'metadata', 'status', 'resolved_by', 'resolved_at',
])]
class SecurityEvent extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    /** Awaiting triage — the column default. */
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
