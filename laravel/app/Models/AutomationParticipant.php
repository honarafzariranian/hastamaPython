<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `dbo.automation_participants` — who is in an automation thread (8 rows).
 *
 * Membership is the authorisation rule for the whole feature: a user may read a
 * conversation only if their username appears here.  There is a real FK to the
 * conversation and an index on `(username, conversation_id)`, which is exactly the
 * lookup "which threads am I in".
 *
 * Composite primary key `(conversation_id, username)`, so `conversation_id` is used
 * as the Eloquent key for reads while the unique index keeps the real guarantee.
 */
#[Table(name: 'automation_participants', key: 'conversation_id', keyType: 'int', incrementing: false, timestamps: false)]
#[Fillable(['conversation_id', 'username'])]
class AutomationParticipant extends LegacyModel
{
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AutomationConversation::class, 'conversation_id', 'id');
    }

    /** The threads one user belongs to. */
    public function scopeForUser(Builder $query, string $username): Builder
    {
        return $query->where('username', $username);
    }
}
