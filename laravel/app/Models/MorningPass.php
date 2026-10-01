<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * `dbo.avalpss_table` — "aval" (morning) hourly passes (5 rows).
 *
 * This is a **trigger source**: each inserted row feeds `totalpass_table` and the
 * pass totals, so application code writes here and lets the database do the
 * bookkeeping.
 *
 * **Column spelling warning.**  This table uses all-lowercase names
 * (`officialtime`, `entrytime`), while the sibling `beynpss_table` uses
 * `exitTime` / `entryTime` and `akhrpss_table` uses `officialTime` / `exitTime`.
 * The three spellings were read from the live schema and are reproduced exactly;
 * a "tidy-up" here would break the tables.
 */
#[Table(name: 'avalpss_table', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['officialtime', 'entrytime', 'date', 'username', 'total_time_aval'])]
class MorningPass extends LegacyModel
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
