<?php

namespace App\Models;

use App\Support\Legacy\PersianText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.user_sessions` — the server-side session registry (303 rows).
 *
 * The Python application kept this table because its own session handling had to
 * be revocable from the admin panel: every login inserts a row, every request
 * advances `last_activity`, and an admin can terminate a user's sessions in bulk.
 * The migration keeps the table as the single source of truth for "who is signed
 * in" even though Laravel has its own session store, so the existing control
 * centre keeps working and a session can be revoked across both stacks during the
 * side-by-side period.
 *
 * `is_active` is a real `bit`.  `session_key` is `nvarchar(255)` and **not** a
 * hash of Laravel's session id, so the bridge between the two stores is explicit
 * (Phase 4) rather than assumed.
 *
 * Four of the 303 rows match no row in `user_table` even after trimming and
 * normalising — orphans left by deleted accounts.  Reads must tolerate that, so
 * the model exposes no relation to {@see User}.
 */
#[Table(name: 'user_sessions', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable([
    'session_key', 'username', 'ip_address', 'user_agent', 'login_at',
    'last_activity', 'logout_at', 'is_active', 'terminated_by',
])]
class UserSession extends LegacyModel
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'login_at' => 'datetime',
            'last_activity' => 'datetime',
            'logout_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /** Sessions that have not been logged out or revoked. */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * One user's sessions, case-insensitively and padding-insensitively.
     *
     * The Python revocation helper compared with `LOWER(LTRIM(RTRIM(username)))`,
     * and it used only `LTRIM`/`RTRIM` — **not** the yeh/kaf folding, which is why
     * the 4 orphan rows described above exist.  The same predicate is reproduced
     * here so revocation keeps matching exactly the same rows.
     */
    public function scopeForUser(Builder $query, string $username): Builder
    {
        return $query->whereRaw(
            'LOWER(LTRIM(RTRIM(username))) = ?',
            [mb_strtolower(PersianText::trim($username), 'UTF-8')],
        );
    }
}
