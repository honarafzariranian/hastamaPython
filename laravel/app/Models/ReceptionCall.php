<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.reception_calls` — the call log behind the TV display (3 rows).
 *
 * Every "now serving" event is one row here, which is what makes the display and
 * its history possible.  `is_test` marks a rehearsal call so a test announcement
 * can be pushed to the display without polluting the real log — the display code
 * uses it to decide whether to play the chime.
 *
 * `called_by` is the receptionist's username and `department` defaults to
 * «نمونه‌گیری», the same default as the queue tables.  This table has no
 * `created_at`/`updated_at`; `called_at` is the timestamp.
 */
#[Table(name: 'reception_calls', key: 'id', keyType: 'int', incrementing: true, timestamps: false)]
#[Fillable(['reception_number', 'department', 'called_by', 'is_test', 'called_at'])]
class ReceptionCall extends LegacyModel
{
    /** Default department, as stored in the column default. */
    public const DEFAULT_DEPARTMENT = 'نمونه‌گیری';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_test' => 'boolean',
            'called_at' => 'datetime',
        ];
    }

    /** Real calls only — what the operator's history list shows by default. */
    public function scopeReal(Builder $query): Builder
    {
        return $query->where('is_test', false);
    }

    /** The most recent calls first. */
    public function scopeRecent(Builder $query, int $limit = 20): Builder
    {
        return $query->orderByDesc('called_at')->limit($limit);
    }
}
