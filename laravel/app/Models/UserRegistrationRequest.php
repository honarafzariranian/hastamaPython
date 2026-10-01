<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.user_registration_requests` — self-registration awaiting approval
 * (0 rows today, schema and endpoints are live).
 *
 * A stranger may submit the "register" form; nothing is created in `user_table`
 * until an administrator approves, and a rejection keeps the reason on the row
 * (`rejection_reason`).  The password is already hashed with bcrypt into
 * `password_hash varbinary(64)` at submission time, so the plaintext never
 * reaches the database at all — unlike the 15 legacy accounts.
 *
 * `request_id` follows the same `HST-YYYYMMDD-HEX8` shape used by the recovery
 * flow and is validated with a regex before it is accepted.
 */
#[Table(name: 'user_registration_requests', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'request_id', 'first_name', 'last_name', 'father_name', 'national_id',
    'mobile', 'username', 'password_hash', 'department', 'work_hours',
    'substitute', 'status', 'rejection_reason', 'reviewed_by', 'reviewed_at',
    'created_ip', 'created_user_agent',
])]
#[Hidden(['password_hash'])]
class UserRegistrationRequest extends LegacyTimestampedModel
{
    /** Waiting for an administrator. */
    public const STATUS_PENDING = 'pending';

    /** Approved; the account has been created in `user_table`. */
    public const STATUS_APPROVED = 'approved';

    /** Refused; `rejection_reason` explains why. */
    public const STATUS_REJECTED = 'rejected';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reviewed_at' => 'datetime',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /** Full name in the shape the admin list displays it. */
    public function fullName(): string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? ''));
    }
}
