<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `dbo.automation_messages` — messages inside an internal automation thread
 * (6 rows).
 *
 * `body` is `nvarchar(4000)` and there is no visibility flag here (unlike
 * {@see TicketMessage}): everyone in `automation_participants` sees every message.
 * Attachments hang off a message through
 * {@see AutomationAttachment::$message_id}, which is nullable so a file can also be
 * attached to the conversation as a whole.
 */
#[Table(name: 'automation_messages', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['conversation_id', 'author_username', 'body'])]
class AutomationMessage extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AutomationConversation::class, 'conversation_id', 'id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AutomationAttachment::class, 'message_id', 'id');
    }
}
