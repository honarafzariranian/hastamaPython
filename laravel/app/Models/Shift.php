<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;

/**
 * `dbo.shiftha` — the per-month work schedule (4 rows).
 *
 * **Spelling warning.**  This table spells Wednesday `chaharshanbeh`; the
 * `user_table` default-hours columns spell it `chrshanbeh`.  Both spellings are
 * part of the live schema and are reproduced exactly; do not "fix" either one.
 *
 * The schedule is keyed by *Jalali* year and month, not Gregorian, and each
 * weekday column holds the shift text (`varchar(20)`).
 *
 * `created_at` is a `datetime` column with a `getdate()` default, so the model
 * lets Eloquent maintain `created_at` but declares no `updated_at` column:
 * the table does not have one.
 */
#[Table(name: 'shiftha', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'username', 'jalali_year', 'jalali_month', 'start_day', 'end_day',
    'shanbeh', 'yekshanbeh', 'doshanbeh', 'seshanbeh', 'chaharshanbeh',
    'panjshanbeh', 'jomeh', 'title',
])]
class Shift extends LegacyTimestampedModel
{
    /** This table has no `updated_at` column. */
    public const UPDATED_AT = null;

    /**
     * Jalali weekday column names, in calendar order, exactly as the schema
     * spells them.
     *
     * @var array<int, string>
     */
    public const WEEKDAY_COLUMNS = [
        'shanbeh', 'yekshanbeh', 'doshanbeh', 'seshanbeh',
        'chaharshanbeh', 'panjshanbeh', 'jomeh',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jalali_year' => 'integer',
            'jalali_month' => 'integer',
            'start_day' => 'integer',
            'end_day' => 'integer',
        ];
    }
}
