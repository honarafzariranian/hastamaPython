<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * `dbo.akhrpss_table` — "akhr" (evening) hourly passes (0 rows).
 *
 * A trigger source for `totalpass_table`.  Column names are as stored; see
 * {@see MorningPass} for the sibling spellings.
 */
#[Table(name: 'akhrpss_table', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['officialTime', 'exitTime', 'date', 'username', 'total_time_akhr'])]
class EveningPass extends LegacyModel
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }
}
