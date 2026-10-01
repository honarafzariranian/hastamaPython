<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.push_subscriptions` — Web Push endpoints per browser (3 rows).
 *
 * One row per browser/device: `endpoint` is unique across the whole table
 * (`UQ_push_subscriptions_endpoint`), so re-subscribing the same browser must
 * update the existing row rather than insert — the unique index will refuse the
 * duplicate otherwise.  `p256dh` and `auth` are the browser's keys, required by
 * the Web Push protocol.
 *
 * `disabled_at` is the soft-off switch used when the push service answers 404/410:
 * the row is kept (so the user's other devices are unaffected) and skipped on
 * subsequent sends.  `last_used_at` records the last successful delivery.
 */
#[Table(name: 'push_subscriptions', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'username', 'endpoint', 'p256dh', 'auth', 'user_agent', 'last_used_at',
    'disabled_at',
])]
class PushSubscription extends LegacyTimestampedModel
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /** Endpoints that should receive a push right now. */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->whereNull('disabled_at');
    }

    public function scopeForUser(Builder $query, string $username): Builder
    {
        return $query->where('username', $username);
    }
}
