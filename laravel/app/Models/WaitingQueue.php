<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.waiting_queue` — people waiting to be called (1 row).
 *
 * A row is added by the operator (`added_by`), moves `waiting` → `called` when the
 * number is announced (stamping `called_at`), and the display reads the same rows
 * {@see DisplayQueue} shows.  The existing updates are:
 *
 *     UPDATE dbo.waiting_queue SET status = 'called', called_at = SYSUTCDATETIME() …
 *
 * `department` defaults to «نمونه‌گیری».  Note that `status` is `nvarchar(20)`,
 * i.e. it holds words, and it is not the same vocabulary as
 * {@see QueueTicket} — the two tables are separate queues.
 */
#[Table(name: 'waiting_queue', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['reception_number', 'department', 'added_by', 'status', 'called_at'])]
class WaitingQueue extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    public const STATUS_WAITING = 'waiting';

    public const STATUS_CALLED = 'called';

    /** Default department, as stored in the column default. */
    public const DEFAULT_DEPARTMENT = 'نمونه‌گیری';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'called_at' => 'datetime',
        ];
    }

    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_WAITING);
    }

    /** Oldest first — the order the queue is served in. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('created_at');
    }
}
