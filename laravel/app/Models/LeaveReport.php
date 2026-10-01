<?php

namespace App\Models;

use App\Models\Concerns\GuardsDatabaseOwnedTable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * `dbo.leave_report` — the leave summary, **maintained by a trigger**.
 *
 * `trg_UpdateLeaveReport` rewrites this table whenever a `mrkhc_table` row
 * changes, so that:
 *
 *     total_days     = SUM(days) over that user's approved leaves
 *     remaining_days = 30 − total_days
 *
 * The 30 is hard-coded inside the trigger; there is no configuration row for the
 * entitlement, and `MIGRATION_AUDIT.md` records that explicitly.  A `SELECT` of
 * this table is the only access the Python application performs (verified by
 * grep), so every write through this model is refused.
 *
 * The table has no primary key and no identity column.  `username` is marked as
 * the key so `find()` and `whereKey()` work; it is not a real constraint and this
 * model never writes, so that is safe.
 */
#[Table(name: 'leave_report', key: 'username', keyType: 'string', incrementing: false, timestamps: false)]
#[Fillable([])]
class LeaveReport extends LegacyModel
{
    use GuardsDatabaseOwnedTable;

    public static function ownerTriggerDescription(): string
    {
        return 'trg_UpdateLeaveReport';
    }

    /**
     * Attached to `mrkhc_table`, not to this table — see the trait docblock.
     *
     * @return array<int, string>
     */
    public static function ownerTriggerNames(): array
    {
        return ['trg_UpdateLeaveReport'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_days' => 'integer',
            'remaining_days' => 'integer',
        ];
    }
}
