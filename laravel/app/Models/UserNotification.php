<?php

namespace App\Models;

use App\Support\Legacy\PersianText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `dbo.user_notifications` — one delivery of a notification to one user (2 rows).
 *
 * The inbox is built from these rows, and every state transition has its own
 * column rather than a status string:
 *
 * | Column | Meaning |
 * |---|---|
 * | `delivered_at` | set when fan-out created the row (**not null** by default) |
 * | `read_at` | the user opened it |
 * | `dismissed_at` | the user removed it from the inbox |
 *
 * Unread means `read_at IS NULL AND dismissed_at IS NULL`; the unread counter and
 * the badge use exactly that predicate.  `username` is `nvarchar(255)` and
 * comparisons in the existing SQL are `RTRIM(un.username) = ?`, so
 * {@see self::scopeForUser()} reproduces that.
 *
 * The table has `updated_at` but **no** `created_at` (`delivered_at` plays that
 * role), so only the update stamp is managed.
 */
#[Table(name: 'user_notifications', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable(['notification_id', 'username', 'delivered_at', 'read_at', 'dismissed_at'])]
class UserNotification extends LegacyTimestampedModel
{
    /** This table has no `created_at` column. */
    public const CREATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'dismissed_at' => 'datetime',
        ];
    }

    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class, 'notification_id', 'id');
    }

    public function scopeForUser(Builder $query, string $username): Builder
    {
        return $query->whereRaw(
            'RTRIM(username) = ?',
            [PersianText::trim($username)],
        );
    }

    /** What the unread badge counts. */
    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at')->whereNull('dismissed_at');
    }

    /** Still present in the inbox. */
    public function scopeInbox(Builder $query): Builder
    {
        return $query->whereNull('dismissed_at');
    }

    public function isUnread(): bool
    {
        return $this->read_at === null && $this->dismissed_at === null;
    }
}
