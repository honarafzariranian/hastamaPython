<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `dbo.automation_attachments` — files in an automation thread (0 rows).
 *
 * `message_id` is nullable, so a file can be attached to a specific message or to
 * the conversation as a whole; `conversation_id` is always set and carries a real
 * FK.  `storage_name` is the on-disk name (unique per upload) and `original_name`
 * is what the participant sees — the same split used by ticket attachments, and the
 * reason an uploaded filename cannot escape its directory.
 */
#[Table(name: 'automation_attachments', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'conversation_id', 'message_id', 'uploaded_by', 'original_name',
    'storage_name', 'content_type', 'size_bytes',
])]
class AutomationAttachment extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'message_id' => 'integer',
            'size_bytes' => 'integer',
            'conversation_id' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AutomationConversation::class, 'conversation_id', 'id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(AutomationMessage::class, 'message_id', 'id');
    }
}
