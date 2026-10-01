<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.system_errors` — the grouped error log (0 rows, schema only).
 *
 * Errors are **aggregated**, not appended: a repeated failure bumps
 * `occurrences` and `last_seen` on the existing row rather than inserting another,
 * which is why the table is empty on a healthy system and why the model has
 * `first_seen` / `last_seen` instead of `created_at` / `updated_at`.
 *
 * `error_id` is the correlation key shared with {@see AuditLog::error_id}, and
 * `request_id` / `session_id` carry the same values as in the audit trail so an
 * incident can be reconstructed from either side.
 */
#[Table(name: 'system_errors', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable([
    'error_id', 'error_type', 'severity', 'message', 'detail', 'endpoint',
    'method', 'username', 'ip_address', 'request_id', 'session_id', 'status',
    'occurrences', 'first_seen', 'last_seen', 'resolved_by', 'resolved_at',
])]
class SystemError extends LegacyModel
{
    /** Awaiting triage — the column default. */
    public const STATUS_OPEN = 'open';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurrences' => 'integer',
            'first_seen' => 'datetime',
            'last_seen' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /** Group lookup used when deciding whether to bump or insert. */
    public function scopeForType(Builder $query, string $errorType): Builder
    {
        return $query->where('error_type', $errorType);
    }
}
