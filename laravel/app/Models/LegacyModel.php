<?php

namespace App\Models;

use App\Models\Concerns\TrimsLegacyStrings;
use Illuminate\Database\Eloquent\Model;

/**
 * Base model for the legacy `userDB` tables.
 *
 * Read `docs/migration/DATABASE_SCHEMA.md` before changing anything here.  Three
 * rules apply to every model in this directory, and they exist because the
 * database is *not* a Laravel database:
 *
 * 1. **No managed timestamps by default.**  `user_table`, `hozoor`, `shiftha`,
 *    `mrkhc_table`, `ezafe_table` and most route-scoped tables have no
 *    `created_at` / `updated_at` columns at all, and the ones that do fill them
 *    with `SYSUTCDATETIME()` defaults rather than application writes.  A Laravel
 *    `save()` that silently appended `where updated_at is not null` style work
 *    — or worse, wrote to a column that does not exist — would be an immediate
 *    runtime failure.  Tables that *do* own `created_at` / `updated_at` extend
 *    {@see LegacyTimestampedModel} instead, which turns the behaviour back on
 *    deliberately.
 * 2. **Trailing space padding is trimmed on read.**  See
 *    {@see TrimsLegacyStrings}; the live data genuinely contains
 *    `'admin     '` in `user_table.role`.
 * 3. **Mass assignment is closed unless a model opens it.**  Laravel's default
 *    `$guarded = ['*']` stays in force, and each model declares a `#[Fillable]`
 *    list covering its writable columns.  A column missing from that list is
 *    reported by `php artisan hastama:schema-check`, so the list cannot silently
 *    drift away from the table.
 */
abstract class LegacyModel extends Model
{
    use TrimsLegacyStrings;

    /**
     * Legacy tables are not timestamped unless they say so.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * The next integer for a table whose primary key is **not** an identity
     * column.
     *
     * `user_table.id`, `leave_report`, `ezafe_total_table` and the
     * `totalpass_table` family are plain `int` columns; the Python application
     * allocates their keys itself.  Reproducing `MAX(key) + 1` here keeps that
     * behaviour available while making the requirement obvious.
     *
     * **This is only the value; the caller must hold the table.**  A bare
     * `SELECT MAX(id)` followed by an `INSERT` in two separate transactions is a
     * duplicate-key race, which is why callers run inside `DB::transaction()`
     * and take the number with an explicit `WITH (UPDLOCK, HOLDLOCK)` query.
     * Laravel's `lockForUpdate()` is **not** usable for that on SQL Server: the
     * SQL Server grammar compiles it to an empty string, so no lock is taken.
     */
    public static function nextKeyValue(): int
    {
        $model = new static;

        return (int) $model->newQuery()->max($model->getKeyName()) + 1;
    }

    /**
     * Whether this table's key is allocated by the application.
     */
    public function keyIsAllocatedByApplication(): bool
    {
        return ! $this->getIncrementing();
    }
}
