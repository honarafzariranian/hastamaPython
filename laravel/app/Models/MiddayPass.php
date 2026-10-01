<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * `dbo.beynpss_table` — "beyn" (midday) hourly passes (1 row).
 *
 * A trigger source for `totalpass_table`.  The mixed-case column names
 * (`exitTime`, `entryTime`) are as stored in the database and are kept verbatim —
 * see {@see MorningPass} for the sibling spellings.
 */
#[Table(name: 'beynpss_table', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['exitTime', 'entryTime', 'date', 'username', 'total_time_beyn'])]
class MiddayPass extends LegacyModel
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
