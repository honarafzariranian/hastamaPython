<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.password_reset_requests` — the admin-approved password reset workflow
 * (7 rows).
 *
 * There is **no email and no SMS anywhere in this flow**.  A user asks for a
 * reset, an administrator approves it, and only then is an 8-character
 * `A–Z0–9` recovery code generated (`secrets.choice`) and shown to the user.  The
 * code is valid for 60 minutes by default
 * (`system_config.password_reset_code_ttl_minutes`) and compared with
 * `hmac.compare_digest`; when `HASTAMA_HMAC_SECRET` is empty the flow **fails
 * closed** rather than degrading.  `code_attempts` / `max_attempts` (default 5)
 * are the brute-force guard.
 *
 * The audit's correction is worth repeating: there is **no 5-digit verification
 * code** in this application.  Login uses a 6-character CAPTCHA plus
 * username/password, and the recovery code above is 8 characters.
 */
#[Table(name: 'password_reset_requests', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'request_id', 'username', 'ip_address', 'user_agent', 'status',
    'recovery_code', 'code_expires_at', 'code_attempts', 'max_attempts',
    'approved_by', 'approved_at', 'completed_at',
])]
#[Hidden(['recovery_code'])]
class PasswordResetRequest extends LegacyTimestampedModel
{
    /** Waiting for an administrator to look at it. */
    public const STATUS_PENDING = 'pending';

    /** Approved; the recovery code now exists and is valid until it expires. */
    public const STATUS_APPROVED = 'approved';

    /** Refused by an administrator. */
    public const STATUS_REJECTED = 'rejected';

    /** The user has set a new password with the code. */
    public const STATUS_COMPLETED = 'completed';

    /** Length of the generated recovery code, as the Python app produces it. */
    public const RECOVERY_CODE_LENGTH = 8;

    /** Default attempt allowance before the code is burnt. */
    public const DEFAULT_MAX_ATTEMPTS = 5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'code_expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'completed_at' => 'datetime',
            'code_attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeForUser(Builder $query, string $username): Builder
    {
        return $query->where('username', $username);
    }

    /** Whether the code is still usable: approved, unexpired, attempts left. */
    public function codeIsUsable(): bool
    {
        return $this->status === self::STATUS_APPROVED
            && $this->recovery_code !== null
            && $this->code_expires_at !== null
            && $this->code_expires_at->isFuture()
            && $this->code_attempts < $this->max_attempts;
    }
}
