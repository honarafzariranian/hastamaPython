<?php

namespace App\Models;

use App\Support\Legacy\LegacyRequestStatus;
use App\Support\Legacy\PersianText;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.mrkhc_table` — leave requests (4 rows).
 *
 * This is a **trigger source**: inserting or updating a row makes
 * `trg_UpdateLeaveReport` recompute `leave_report` for that user against the
 * hard-coded 30-day entitlement.  Application code therefore writes *here* and
 * never touches `leave_report` (see {@see LeaveReport}).
 *
 * The status column is `nvarchar(max)` with a default of `N'انتظار تایید'`, and
 * the trigger's own predicate is `status = N'تایید شده'`.  Those Persian strings
 * are the state machine, so they are referenced through
 * {@see LegacyRequestStatus} rather than retyped.
 */
#[Table(name: 'mrkhc_table', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['start_date', 'end_date', 'days', 'substitute', 'username', 'status'])]
class LeaveRequest extends LegacyModel
{
    /** Status the trigger treats as approved, and reports aggregate on. */
    public const STATUS_APPROVED = LegacyRequestStatus::APPROVED;

    /** Default status for a new request, as stored in the column default. */
    public const STATUS_PENDING = LegacyRequestStatus::PENDING;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'days' => 'integer',
        ];
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', LegacyRequestStatus::APPROVED);
    }

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

    /** The requester's name, trimmed of `nchar(10)` padding. */
    public function requester(): string
    {
        return PersianText::trim($this->getAttribute('username'));
    }
}
