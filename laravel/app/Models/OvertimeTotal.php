<?php

namespace App\Models;

use App\Models\Concerns\GuardsDatabaseOwnedTable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * `dbo.ezafe_total_table` — per-user overtime totals, **maintained by triggers**.
 *
 * `trg_CalculateDailyOvertime` and `trg_UpdateTotalOvertime` keep
 * `total_ezafe_time` in step with `ezafe_table`; the Python application only
 * reads this table (`SELECT username, total_ezafe_time FROM ezafe_total_table`).
 *
 * Two columns, no primary key, no identity column — `username` is used as the key
 * for reads only, and writes are refused.
 */
#[Table(name: 'ezafe_total_table', key: 'username', keyType: 'string', incrementing: false, timestamps: false)]
#[Fillable([])]
class OvertimeTotal extends LegacyModel
{
    use GuardsDatabaseOwnedTable;

    public static function ownerTriggerDescription(): string
    {
        return 'trg_CalculateDailyOvertime and trg_UpdateTotalOvertime';
    }

    /**
     * Attached to `ezafe_table`, not to this table — see the trait docblock.
     *
     * @return array<int, string>
     */
    public static function ownerTriggerNames(): array
    {
        return ['trg_CalculateDailyOvertime', 'trg_UpdateTotalOvertime'];
    }
}
