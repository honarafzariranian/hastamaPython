<?php

namespace App\Models;

use App\Support\Legacy\LegacyRequestStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `dbo.automation_reopen_requests` — requests to reopen a closed thread (0 rows).
 *
 * Closing a conversation is meant to be final; a participant who needs it again
 * files one of these, and an administrator resolves it (`resolved_at`).  The
 * `status` vocabulary is the module's own: `pending` by default, and terminal once
 * resolved — this is *not* the Persian approval vocabulary used by the leave and
 * pass queues ({@see LegacyRequestStatus}).
 */
#[Table(name: 'automation_reopen_requests', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['conversation_id', 'requester', 'status', 'resolved_at'])]
class AutomationReopenRequest extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'conversation_id' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AutomationConversation::class, 'conversation_id', 'id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }
}
