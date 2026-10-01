<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `dbo.admin_payroll_calculations` — stored payroll snapshots (54 rows).
 *
 * This table is a **cache of computed output**, not a calculator.  The Python
 * application's `PAYROLL_CALCULATION_TYPES` had exactly four members —
 * `overtime`, `comprehensive`, `hourly`, `summary` — and `POST
 * /api/admin/payroll/save` wrote the computed payload while `GET
 * /api/admin/payroll/load` read it back.  There is no payroll formula in the
 * database and, in particular, **no "karaneh" calculation type anywhere in this
 * system**; the audit records that explicitly because it is a common assumption
 * about Iranian payroll systems.
 *
 * `payload_json` is `nvarchar(max)` holding a JSON document with the exact shape
 * the report screen expects, so it is cast to `array` and stored verbatim.  The
 * unique index `UQ_admin_payroll_row` on
 * `(calculation_type, period_year, period_month, username)` is what makes
 * re-saving a period an update rather than a duplicate.
 */
#[Table(name: 'admin_payroll_calculations', key: 'id', keyType: 'int', incrementing: true, timestamps: true)]
#[Fillable([
    'calculation_type', 'period_year', 'period_month', 'username',
    'payload_json', 'saved_by',
])]
class AdminPayrollCalculation extends LegacyTimestampedModel
{
    /** The four and only four calculation types. */
    public const TYPES = ['overtime', 'comprehensive', 'hourly', 'summary'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'period_year' => 'integer',
        ];
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('calculation_type', $type);
    }

    /** The natural key of a stored row; used for the save-then-load contract. */
    public function scopeForPeriod(Builder $query, int $year, string $month, string $username): Builder
    {
        return $query
            ->where('period_year', $year)
            ->where('period_month', $month)
            ->where('username', $username);
    }

    /** Read a stored payload, or null when nothing has been saved for that period. */
    public static function payloadFor(string $type, int $year, string $month, string $username): ?array
    {
        $row = static::query()->forPeriod($year, $month, $username)->ofType($type)->first();

        return $row?->payload_json;
    }

    /**
     * Persist a payload, replacing any earlier snapshot for the same period — the
     * behaviour of the existing save endpoint.
     */
    public static function store(string $type, int $year, string $month, string $username, array $payload, string $savedBy): self
    {
        return static::updateOrCreate(
            [
                'calculation_type' => $type,
                'period_year' => $year,
                'period_month' => $month,
                'username' => $username,
            ],
            [
                'payload_json' => $payload,
                'saved_by' => $savedBy,
            ],
        );
    }
}
