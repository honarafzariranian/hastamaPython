<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `dbo.ticket_attachments` — uploaded files on tickets (0 rows, feature live).
 *
 * The row holds **metadata only**; the bytes live on disk under a private
 * directory and are served through an authorised download route.  `storage_name`
 * is the name on disk and `original_name` is what the user sees, which is
 * deliberate: the original filename is never used for the path, so it cannot
 * traverse out of the upload directory.
 *
 * There is a real FK to the parent ticket (`FK_ticket_attachments_ticket`) as well
 * as an optional FK to the message it hangs off, so deleting a ticket's rows in
 * the right order matters.
 */
#[Table(name: 'ticket_attachments', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'ticket_id', 'message_id', 'uploaded_by', 'original_name', 'storage_name',
    'content_type', 'size_bytes',
])]
class TicketAttachment extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'message_id' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class, 'ticket_id', 'id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'message_id', 'id');
    }

    /** Size in a form the UI can print. */
    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;

        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return $unit === 'B' ? "{$bytes} B" : number_format($bytes, 1)." {$unit}";
            }

            $bytes /= 1024;
        }

        return "{$bytes} GB";
    }
}
