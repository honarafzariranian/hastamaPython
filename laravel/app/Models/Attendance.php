<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * `dbo.hozoor` — the manual attendance book (59 rows).
 *
 * `id` is an `IDENTITY` column but the table has **no** declared primary key,
 * and it carries a unique index on `(username, date)`
 * (`UX_hozoor_username_date`).  Eloquent therefore gets an explicit key name so
 * `find()` and `update()` work, while the unique index remains the real
 * integrity guarantee.
 *
 * `vrood` (arrival) and `khoroj` (departure) are `time` columns.  They are kept
 * as raw strings on purpose: the Python application formats them at the point of
 * display (`format_time_value`), and casting them to `Carbon` in the model would
 * silently change how they are compared.
 */
#[Table(name: 'hozoor', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['username', 'date', 'vrood', 'khoroj'])]
class Attendance extends LegacyModel
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
