<?php

namespace App\Models;

use App\Support\Legacy\LegacyRequestStatus;
use App\Support\Legacy\PersianText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.ezafe_table` — overtime requests (2 rows).
 *
 * A user-submitted range (`from_time` … `to_time`) with its own status; this is a
 * **trigger source** for `ezafe_total_table` (via `trg_CalculateDailyOvertime` and
 * `trg_UpdateTotalOvertime`), which is why the total is read from
 * {@see OvertimeTotal} and never summed in PHP.
 *
 * The audit also records the counterpart fact, because it is easy to get wrong:
 * the *live* overtime figure shown during the day does not come from here.  It is
 * `max(0, now − work_end)` computed by `presence_summary` — a **zero-minute**
 * threshold, with no 10-minute grace period anywhere in this system — and there is
 * no "karaneh" calculation type at all.
 */
#[Table(name: 'ezafe_table', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['overtime_date', 'from_time', 'to_time', 'description', 'status', 'username', 'daily_overtime'])]
class OvertimeRequest extends LegacyModel
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'overtime_date' => 'date',
        ];
    }

    /** Only approved rows are aggregated into totals and payroll. */
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
}
