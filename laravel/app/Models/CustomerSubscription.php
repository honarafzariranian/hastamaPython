<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.customer_subscriptions` — customer plans (1 row).
 *
 * The linkage to accounts is by **string identity, not a foreign key**:
 * `user_table.customer_id` / `customer_name` are matched against
 * `customer_id` / `customer_code` / `customer_name` here, with the comparison done
 * as `LTRIM(RTRIM(COALESCE(col, ''))) = ?` because both sides carry legacy
 * padding.  The audit calls this out as one of the places a `JOIN` will not work
 * the way it looks like it should, so the model exposes {@see self::linkPredicate()}
 * instead of a relation.
 *
 * `max_users` gates how many accounts a customer may hold, and `price` is the only
 * `decimal` column in the feature (no precision is declared in the schema, so it
 * is read as a string and never used in arithmetic here).
 */
#[Table(name: 'customer_subscriptions', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'customer_id', 'customer_code', 'customer_name', 'contact_name',
    'contact_email', 'contact_phone', 'plan_name', 'subscription_status',
    'purchased_at', 'starts_at', 'expires_at', 'max_users', 'price', 'currency',
    'payment_method', 'payment_reference', 'invoice_number', 'notes',
])]
class CustomerSubscription extends LegacyTimestampedModel
{
    /** The three states the admin form offers, verbatim from `master-admin.js`. */
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_EXPIRED = 'expired';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'purchased_at' => 'datetime',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'max_users' => 'integer',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('subscription_status', self::STATUS_ACTIVE);
    }

    public function scopeForCustomer(Builder $query, string $customerKey): Builder
    {
        return $query->where(function (Builder $inner) use ($customerKey): void {
            foreach (self::identityColumns() as $column) {
                $inner->orWhereRaw(
                    "LTRIM(RTRIM(COALESCE({$column}, ''))) = ?",
                    [$customerKey],
                );
            }
        });
    }

    /**
     * The columns a `user_table` row may identify its customer by.
     *
     * @return array<int, string>
     */
    public static function identityColumns(): array
    {
        return ['customer_code', 'customer_id'];
    }
}
