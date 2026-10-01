<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `dbo.notifications` — announcements (2 rows).
 *
 * The enum vocabularies below are **not invented**: they are the `Literal[...]`
 * types of the Pydantic model in `app/api/routes/notifications.py`, i.e. exactly
 * what the live endpoints accept.  Validity matters here because the fan-out SQL
 * and the admin filters switch on these strings.
 *
 * Fan-out is the important behaviour: publishing expands a notification into one
 * `user_notifications` row per matching account ({@see UserNotification}), using
 * `target_type`:
 *
 * | `target_type` | Who receives it |
 * |---|---|
 * | `all` | every account |
 * | `selected` | the usernames listed in `notification_targets` |
 * | `role` | every account whose `role` matches a listed value |
 * | `department` | every account whose `department` matches a listed value |
 *
 * `action_url` is constrained to an **internal** link (must start with `/` and not
 * `//`), which is a real security rule from the existing validator and not a
 * stylistic preference.
 *
 * Scheduling is the other live behaviour: a `scheduled` notification is published
 * by the one-second APScheduler sweep when `scheduled_at` falls due.
 */
#[Table(name: 'notifications', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'title', 'content', 'type', 'priority', 'status', 'target_type',
    'action_label', 'action_url', 'created_by', 'published_at',
    'scheduled_at', 'archived_at', 'push_tag',
])]
class Notification extends LegacyTimestampedModel
{
    /** Accepted `type` values. */
    public const TYPES = ['general', 'announcement', 'system', 'warning', 'information', 'success', 'reminder'];

    /** Accepted `priority` values. */
    public const PRIORITIES = ['normal', 'important', 'high', 'critical'];

    /** `draft`, `scheduled` and `published` are settable; `archived` is a state. */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    /** Statuses that are visible to recipients. */
    public const VISIBLE_STATUSES = [self::STATUS_PUBLISHED, self::STATUS_ARCHIVED];

    /** Accepted `target_type` values. */
    public const TARGET_ALL = 'all';

    public const TARGET_SELECTED = 'selected';

    public const TARGET_ROLE = 'role';

    public const TARGET_DEPARTMENT = 'department';

    public const TARGET_TYPES = [self::TARGET_ALL, self::TARGET_SELECTED, self::TARGET_ROLE, self::TARGET_DEPARTMENT];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /** One notification's recipient rows. */
    public function recipients(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'notification_id', 'id');
    }

    /** The listed targets for the non-`all` fan-out modes. */
    public function targets(): HasMany
    {
        return $this->hasMany(NotificationTarget::class, 'notification_id', 'id');
    }

    /** Anything a recipient is allowed to see. */
    public function scopeVisible(Builder $query): Builder
    {
        return $query->whereIn('status', self::VISIBLE_STATUSES);
    }

    /**
     * Notifications the scheduler should publish right now: scheduled, due, and
     * not yet published.
     */
    public function scopeDue(Builder $query, ?\DateTimeInterface $at = null): Builder
    {
        return $query
            ->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', $at ?? now());
    }

    /** Whether this notification carries an internal call-to-action link. */
    public function hasInternalAction(): bool
    {
        $url = (string) $this->action_url;

        return $url !== '' && str_starts_with($url, '/') && ! str_starts_with($url, '//');
    }
}
