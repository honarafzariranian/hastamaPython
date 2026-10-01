<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `dbo.automation_conversations` — internal support threads (4 rows).
 *
 * The "internal automation" feature is a small messaging workspace between staff
 * and the automation team, deliberately separate from the ticketing tables: its
 * own participants list ({@see AutomationParticipant}), its own attachments and its
 * own reopen requests.
 *
 * `status` moves `open` → `closed`, and a closed conversation can be reopened only
 * through {@see AutomationReopenRequest} — that approval step is existing
 * behaviour, not a new restriction.
 */
#[Table(name: 'automation_conversations', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['subject', 'created_by', 'status'])]
class AutomationConversation extends LegacyTimestampedModel
{
    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public function messages(): HasMany
    {
        return $this->hasMany(AutomationMessage::class, 'conversation_id', 'id');
    }

    public function participants(): HasMany
    {
        return $this->hasMany(AutomationParticipant::class, 'conversation_id', 'id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AutomationAttachment::class, 'conversation_id', 'id');
    }

    public function reopenRequests(): HasMany
    {
        return $this->hasMany(AutomationReopenRequest::class, 'conversation_id', 'id');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
