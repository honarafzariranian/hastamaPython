<?php

namespace App\Models\Concerns;

use App\Models\HourlyPass;
use LogicException;

/**
 * Make a model refuse every write, because the database owns it.
 *
 * Two tables in `userDB` are written *only* by triggers:
 *
 * | Table | Owning trigger(s) | What it recomputes |
 * |---|---|---|
 * | `leave_report` | `trg_UpdateLeaveReport` | `total_days` / `remaining_days` from approved `mrkhc_table` rows, against the hard-coded 30-day entitlement |
 * | `ezafe_total_table` | `trg_CalculateDailyOvertime` + `trg_UpdateTotalOvertime` | `total_ezafe_time` from `ezafe_table` |
 *
 * A grep of the whole Python application confirms it only ever `SELECT`s from
 * these two; every write goes through the trigger of the source table.  Writing
 * them from PHP would be overwritten on the next change to the source row, so the
 * guard turns "do not write this table" from a comment into a runtime failure.
 *
 * **Not covered here, deliberately:** `totalpass_table`.  An earlier draft of the
 * migration treated it as trigger-owned too, but reading the application shows
 * otherwise:
 *
 *     INSERT INTO totalpass_table (username, request_date, pass_title, pass_duration, status)
 *     VALUES (?, ?, ?, ?, N'انتظار تایید')          -- on hourly-pass submission
 *
 *     UPDATE totalpass_table SET status = ? WHERE id = ?   -- on admin approval
 *
 * with the source comment «این جدول صف تأیید مدیریت است؛ بدون آن، درخواست تازه در
 * پنل مدیر دیده نمی‌شود» — *this table is the admin approval queue; without it a
 * new request is invisible in the admin panel*.  It is therefore an
 * application-owned table and {@see HourlyPass} is writable.
 *
 * The guard covers Eloquent writes only; it cannot see a hand-written
 * `DB::table('leave_report')->insert(...)`, so these two tables are never touched
 * through the query builder.
 */
trait GuardsDatabaseOwnedTable
{
    /**
     * Why this table may not be written, quoted in the exception message.
     */
    abstract public static function ownerTriggerDescription(): string;

    /**
     * The exact trigger names that maintain this table.
     *
     * Needed because the triggers live on the **source** table, not on this one:
     * `trg_UpdateLeaveReport` is attached to `mrkhc_table` and writes
     * `leave_report`.  Verified against the live server — both guarded tables have
     * zero triggers of their own — so `hastama:schema-check` asserts these names
     * exist *somewhere* in the database instead of looking them up on this table.
     * If a trigger is ever renamed or dropped, the check fails rather than the guard
     * quietly protecting a table nobody maintains any more.
     *
     * @return array<int, string>
     */
    abstract public static function ownerTriggerNames(): array;

    /**
     * @param  array<string, mixed>  $options
     */
    public function save(array $options = []): bool
    {
        throw $this->databaseOwnedWriteException('save');
    }

    public function delete(): bool
    {
        throw $this->databaseOwnedWriteException('delete');
    }

    private function databaseOwnedWriteException(string $operation): LogicException
    {
        return new LogicException(sprintf(
            'Refusing to %s %s: the table is maintained by %s and may only be read. '
            .'Write the source table instead; see docs/migration/DATABASE_TRIGGERS.md.',
            $operation,
            static::class,
            static::ownerTriggerDescription(),
        ));
    }
}
