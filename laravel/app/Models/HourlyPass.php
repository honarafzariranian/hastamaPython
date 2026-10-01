<?php

namespace App\Models;

use App\Support\Legacy\LegacyRequestStatus;
use App\Support\Legacy\PersianText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.totalpass_table` — the hourly-pass **approval queue** (6 rows).
 *
 * Read the whole docblock before changing this model; an earlier draft of the
 * migration got it wrong.
 *
 * The six triggers on `avalpss_table` / `beynpss_table` / `akhrpss_table` compute
 * the *durations*, but the question "which pass is the admin looking at right
 * now?" is answered by this table, and the application writes it itself:
 *
 *     INSERT INTO totalpass_table (username, request_date, pass_title, pass_duration, status)
 *     VALUES (?, ?, ?, ?, N'انتظار تایید')          -- hourly-pass submission
 *
 *     UPDATE totalpass_table SET status = ? WHERE id = ?   -- admin approval
 *
 * The source comment on the insert is explicit — «این جدول صف تأیید مدیریت است؛
 * بدون آن، درخواست تازه در پنل مدیر دیده نمیشود» (*this table is the admin
 * approval queue; without it a new request is invisible in the admin panel*) — so
 * this model is writable and carries no database-owned guard.
 *
 * `pass_duration` is a `time` value, returned as a string exactly as the Python
 * application received it; formatting stays a presentation concern.
 */
#[Table(name: 'totalpass_table', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['username', 'request_date', 'pass_title', 'pass_duration', 'status'])]
class HourlyPass extends LegacyModel
{
    /** The three pass kinds, spelled as the Python application spells them. */
    public const TITLE_MORNING = 'avalpss';

    public const TITLE_MIDDAY = 'beynpss';

    public const TITLE_EVENING = 'akhrpss';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'request_date' => 'date',
        ];
    }

    /** Only approved passes count towards a user's total. */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', LegacyRequestStatus::APPROVED);
    }

    /** The queue the admin panel shows. */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', LegacyRequestStatus::PENDING);
    }

    public function scopeForUser(Builder $query, string $username): Builder
    {
        return $query->whereRaw(
            'LOWER(LTRIM(RTRIM(COALESCE(username, \'\')))) = ?',
            [PersianText::foldUsername($username)],
        );
    }

    /** The pass kind as the application records it, trimmed of padding. */
    public function title(): string
    {
        return PersianText::trim($this->getAttribute('pass_title'));
    }
}
