<?php

namespace App\Models;

use App\Support\Legacy\LegacyPassword;
use App\Support\Legacy\PersianText;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The legacy account table, `dbo.user_table`.
 *
 * Everything unusual about this table comes straight from the live database and
 * is recorded in `MIGRATION_AUDIT.md` §13:
 *
 * * `id` is a plain `int` with **no** `IDENTITY`, so new rows must allocate their
 *   own key ({@see LegacyModel::nextKeyValue()} inside a locked transaction).
 * * `username` is the login name; the index on it (`IX_user_table_username`) is
 *   **not unique**, and 11 of the 16 usernames carry trailing spaces.
 * * Seven usernames were typed with the Arabic yeh `ي`, two with the Persian
 *   `ی`.  `username` comparisons therefore go through
 *   {@see PersianText::normalizedColumnExpression()}.
 * * `password` is `nchar(10)` **plaintext** and is populated for all 16 rows;
 *   `password_hash` (bcrypt, `varbinary(64)`) holds a value for exactly one row.
 *   Verification is delegated to {@see LegacyPassword} so the order of checks is
 *   the same one the Python application used.
 * * The weekday column is spelled `chrshanbeh`, *not* `chaharshanbeh`; `shiftha`
 *   uses the other spelling.  Both are kept verbatim.
 * * `is_active` is `nvarchar(10)` and holds `'active'` for every row today.
 */
#[Table(name: 'user_table', key: 'id', keyType: 'int', incrementing: false, timestamps: false)]
#[Fillable([
    'username', 'password', 'name', 'last_name', 'department', 'work_hours',
    'substitute', 'role', 'hozoor_num', 'shanbeh', 'yekshanbeh', 'doshanbeh',
    'seshanbeh', 'chrshanbeh', 'panjshanbeh', 'profile_image',
    'employment_status', 'is_active', 'password_hash', 'last_login',
    'failed_login_count', 'password_changed_at', 'customer_id', 'customer_name',
])]
#[Hidden(['password', 'password_hash'])]
class User extends LegacyModel implements Authenticatable
{
    /** Roles present in the live data: `admin` (3 users) and `user` (13). */
    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    /**
     * Account states that block a login.  Transcribed from the Python
     * application (`app/api/routes/auth.py`), which allowed everything except
     * these:
     *
     *     status = str(user[4] or "active").strip().lower()
     *     return status not in {"disabled", "inactive", "locked", "0", "false"}
     *
     * Note that this is *not* "must equal active": an unexpected value still
     * permits the login, which matters because `is_active` is free-form
     * `nvarchar(10)`.
     *
     * @var array<int, string>
     */
    public const BLOCKING_STATUSES = ['disabled', 'inactive', 'locked', '0', 'false'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_login' => 'datetime',
            'password_changed_at' => 'datetime',
            'failed_login_count' => 'integer',
        ];
    }

    // ── Identity ─────────────────────────────────────────────────────────────

    /**
     * The login name as stored, without padding.
     *
     * `strip()` rather than `trim()` because the Python application strips both
     * ends of every username it reads, and a comparison that kept a leading space
     * would fail to find a real account.
     */
    public function username(): string
    {
        return PersianText::strip($this->getAttribute('username'));
    }

    /** The comparison form of the login name (letters folded, lower-cased). */
    public function foldedUsername(): string
    {
        return PersianText::foldUsername($this->getAttribute('username'));
    }

    /** Display name, falling back to the username when the row is unnamed. */
    public function displayName(): string
    {
        $full = PersianText::strip(
            PersianText::strip($this->getAttribute('name')).' '.PersianText::strip($this->getAttribute('last_name')),
        );

        return $full !== '' ? $full : $this->username();
    }

    /** The role, trimmed of `nchar(10)` padding and lower-cased. */
    public function role(): string
    {
        return mb_strtolower(PersianText::strip($this->getAttribute('role')), 'UTF-8');
    }

    public function isAdmin(): bool
    {
        return $this->role() === self::ROLE_ADMIN;
    }

    /** The attendance card number, trimmed of its padding. */
    public function hozoorNumber(): string
    {
        return PersianText::strip($this->getAttribute('hozoor_num'));
    }

    /** Whether this account may authenticate at all. */
    public function isLoginAllowed(): bool
    {
        $status = mb_strtolower(PersianText::strip($this->getAttribute('is_active')), 'UTF-8');

        if ($status === '') {
            return true; // NULL / empty means "active", exactly as in the Python app.
        }

        return ! in_array($status, self::BLOCKING_STATUSES, true);
    }

    // ── Password ─────────────────────────────────────────────────────────────

    /** The legacy plaintext password column, trimmed of its `nchar(10)` padding. */
    public function legacyPassword(): string
    {
        return PersianText::strip($this->getAttribute('password'));
    }

    /** The raw bcrypt/SHA-512 value in `password_hash`, or null when absent. */
    public function storedHash(): ?string
    {
        $value = $this->getAttribute('password_hash');

        if ($value === null || $value === '') {
            return null;
        }

        return is_string($value) ? $value : null;
    }

    /** Whether this row still needs its password upgraded on next login. */
    public function needsPasswordUpgrade(): bool
    {
        return $this->storedHash() === null
            || LegacyPassword::isSha512Hex($this->legacyPassword())
            || LegacyPassword::isLegacyPlaintext($this->getAttribute('password'), $this->storedHash());
    }

    /**
     * Verify a password against this row.
     *
     * The single place in the application that decides whether a credential is
     * correct.  Order and behaviour are those of
     * `app/core/password_utils.py::verify_password`, including the constant-time
     * comparison for the legacy plaintext case.
     */
    public function verifyPassword(?string $provided): bool
    {
        return LegacyPassword::verify($this->legacyPassword(), $this->storedHash(), $provided);
    }

    // ── Authentication contract ──────────────────────────────────────────────

    /**
     * The credential column.
     *
     * Reported as `password` because that is the legacy column, and the value is
     * the **plaintext** one.  Laravel's stock `EloquentUserProvider` would call
     * `Hash::check()` against it and always fail, which is why the migration
     * registers its own provider (`App\Auth\LegacyUserProvider`) instead of
     * relying on `Hash`.  Nothing in the application calls this method to make an
     * authorisation decision — {@see verifyPassword()} does.
     */
    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return $this->legacyPassword();
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->getAttribute('id');
    }

    /**
     * There is no "remember me" in the legacy application, and `user_table` has
     * no `remember_token` column, so the remember-token surface stays inert.
     */
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void
    {
        // Intentionally inert: the legacy session model has no remember token.
    }

    public function getRememberTokenName(): ?string
    {
        return null;
    }

    // ── Queries ──────────────────────────────────────────────────────────────

    /**
     * Accounts the list and attendance screens show, matching the existing
     * `ISNULL(is_active, 'active') = 'active'` predicate.  SQL Server ignores
     * trailing spaces in `=` comparisons, so the padding needs no special
     * handling here — unlike the `REPLACE(…)` form used for usernames.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereRaw("ISNULL(is_active, 'active') = 'active'");
    }

    public function scopeAdmins(Builder $query): Builder
    {
        return $query->whereRaw("LTRIM(RTRIM(LOWER(COALESCE(role, '')))) = 'admin'");
    }

    /**
     * Look a user up by username, tolerating padding, case and the Arabic/Persian
     * yeh and kaf spellings — the same lookup the Python application performs.
     */
    public function scopeWhereUsername(Builder $query, string $username): Builder
    {
        $expression = PersianText::normalizedColumnExpression('username');

        return $query->whereRaw(
            "LOWER({$expression}) = ?",
            [PersianText::foldUsername($username)],
        );
    }
}
