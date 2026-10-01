<?php

namespace App\Support\Registration;

use App\Services\Auth\LegacyCredentialWriter;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The `INSERT INTO user_table` half of `approve_registration`
 * (`app/api/routes/registration.py`).
 *
 * The approval path is the one place in the registration surface that writes a
 * credential, so it is separated from the controller for three reasons:
 *
 * * **The hash needs the `CONVERT` trick.**  `password_hash` is `varbinary(64)`
 *   and pdo_sqlsrv describes a bound PHP string as `nvarchar`, which SQL Server
 *   refuses to assign — {@see LegacyCredentialWriter::HASH_EXPRESSION} is the
 *   verified fix, and it belongs next to the only other place that writes the
 *   column.
 * * **The branch is the Python's.**  `approve_registration` asks
 *   `get_user_table_columns()` whether `password_hash` exists and inserts
 *   differently when it does not (a pre-migration schema stores no hash at all
 *   on this path — the plaintext column stays `''`).  The probe here is
 *   `SELECT password_hash FROM user_table WHERE 1 = 0`, which answers the same
 *   question on any connection instead of `INFORMATION_SCHEMA`, which is
 *   SQL-Server-only and would silently take the *legacy* branch — inserting
 *   without the hash — on the offline sqlite used by the test suite.
 * * **`password_changed_at` is deliberately not stamped.**  The Python does not
 *   touch it here (the recovery flow does), and inventing a value would make the
 *   two servers disagree about a column the account screen renders.
 *
 * The class is non-final so a test can bind a recorder with
 * `$this->app->instance(ApprovedAccountWriter::class, …)` and assert what the
 * controller handed over without needing a live SQL Server.  The table name is
 * unprefixed, as every statement in `registration.py` is — the `dbo.` prefix in
 * some of the Laravel code is a different module's convention.
 */
class ApprovedAccountWriter
{
    /**
     * Insert the new account, mirroring the two `VALUES` clauses of the Python
     * (`password_hash` branch first, as there).
     *
     * @param  string  $passwordHash  The raw 60-byte bcrypt string as returned by
     *                                `LegacyPassword::hash`, hex-encoded on the way
     *                                into the `varbinary` column.
     */
    public function create(
        int $nextId,
        string $username,
        string $passwordHash,
        string $firstName,
        string $lastName,
        string $department,
        string $substitute,
        string $workHours,
    ): void {
        if ($this->hasPasswordHashColumn()) {
            DB::insert(
                'INSERT INTO user_table (
                    id, username, password, password_hash, name, last_name,
                    department, substitute, work_hours, role, hozoor_num,
                    shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh,
                    is_active
                ) VALUES (?, ?, ?, '.LegacyCredentialWriter::HASH_EXPRESSION.',
                    ?, ?, ?, ?, ?, \'user\', \'\', \'\', \'\', \'\', \'\', \'\', \'\', \'active\')',
                [
                    $nextId,
                    $username,
                    '',
                    bin2hex($passwordHash),
                    $firstName,
                    $lastName,
                    $department,
                    $substitute,
                    $workHours,
                ],
            );

            return;
        }

        // The pre-migration schema: no hash column at all, so the plaintext
        // column carries `''` exactly as the Python's second branch wrote it.
        DB::insert(
            'INSERT INTO user_table (
                id, username, password, name, last_name,
                department, substitute, work_hours, role, hozoor_num,
                shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh,
                is_active
            ) VALUES (?, ?, \'\', ?, ?, ?, ?, ?, \'user\', \'\', \'\', \'\', \'\', \'\', \'\', \'\', \'active\')',
            [$nextId, $username, $firstName, $lastName, $department, $substitute, $workHours],
        );
    }

    /**
     * `get_user_table_columns()` — does this installation have the hash column?
     *
     * A missing *table* also lands here (the probe throws), which is the right
     * answer for the branch decision: the caller's own `SELECT` fails first in
     * that case and the request is already on its way to the 500.
     */
    private function hasPasswordHashColumn(): bool
    {
        try {
            DB::select('SELECT password_hash FROM user_table WHERE 1 = 0');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
