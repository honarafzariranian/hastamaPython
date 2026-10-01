<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.audit_logs` — the append-only audit trail (3,509 rows).
 *
 * Retention is operator-configurable (`system_config.audit_retention_days` is
 * 365) and is enforced by a purge job, not by the database, so the model keeps
 * `created_at` and has no `updated_at` (the table has none).
 *
 * `before_data` / `after_data` / `metadata` are `nvarchar(max)` holding
 * `json.dumps(...)` output; they are cast to arrays so callers never have to
 * remember to decode.  A malformed value decodes to `null` rather than throwing,
 * matching the Python behaviour.  `event_id` (`varchar(32)`) and `request_id` are
 * the correlation keys the control centre searches by.
 */
#[Table(name: 'audit_logs', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'event_id', 'event_type', 'action', 'username', 'role', 'module',
    'resource_type', 'resource_id', 'request_id', 'session_id', 'ip_address',
    'user_agent', 'status', 'severity', 'before_data', 'after_data', 'metadata',
    'error_id',
])]
class AuditLog extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    /** The only value the column default allows. */
    public const STATUS_SUCCESS = 'success';

    /** Severity vocabulary used by the control-centre filters. */
    public const SEVERITIES = ['info', 'warning', 'error', 'critical'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before_data' => 'array',
            'after_data' => 'array',
            'metadata' => 'array',
        ];
    }

    public function scopeForUser(Builder $query, string $username): Builder
    {
        return $query->where('username', $username);
    }

    public function scopeSeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    /** Correlate every entry belonging to one request. */
    public function scopeForRequest(Builder $query, string $requestId): Builder
    {
        return $query->where('request_id', $requestId);
    }
}
