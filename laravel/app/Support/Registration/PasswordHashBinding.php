<?php

namespace App\Support\Registration;

use App\Services\Auth\LegacyCredentialWriter;
use Illuminate\Support\Facades\DB;

/**
 * The `[expression, parameter]` pair for one `password_hash` value.
 *
 * `user_registration_requests.password_hash` is `varbinary(64)` and pdo_sqlsrv
 * describes a bound PHP string as `nvarchar`, which SQL Server refuses to assign
 * — {@see LegacyCredentialWriter::HASH_EXPRESSION} with a **hex-encoded** value
 * is the verified fix used by every credential write in this codebase
 * (`CONVERT(varbinary(64), CONVERT(varchar(128), ?, 2))`, style 2 = hex → bytes,
 * and 120 hex characters fit the `varchar(128)` intermediate for a 60-byte
 * bcrypt string).
 *
 * The offline suite runs on sqlite, which has no `CONVERT()` — preparing that
 * expression would throw "no such function: CONVERT" and every submit test would
 * fail for a reason that has nothing to do with the contract under test.  So the
 * expression is chosen per driver, and the fallback binds the bcrypt string
 * itself, which is what the Python's own `INSERT` passed to this column (the
 * value reached SQL Server as text and was stored as its bytes).
 *
 * The byte-for-byte result on SQL Server is identical either way; only the
 * offline test database sees the string, and only this class knows the branch
 * exists.
 */
final class PasswordHashBinding
{
    /**
     * @return array{0: string, 1: mixed} `[expression, parameter]`, ready to be
     *                                     spliced into an `INSERT` and passed to
     *                                     `DB::insert`.
     */
    public static function for(string $passwordHash): array
    {
        if (DB::connection()->getDriverName() === 'sqlsrv') {
            return [LegacyCredentialWriter::HASH_EXPRESSION, bin2hex($passwordHash)];
        }

        return ['?', $passwordHash];
    }
}
