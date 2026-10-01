<?php

namespace App\Services\Auth;

use App\Support\Legacy\LegacyPassword;
use App\Support\Legacy\PersianText;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The one place that writes credential material to `dbo.user_table`.
 *
 * Both the first-login upgrade and the password-recovery reset replace a legacy
 * credential, and they must do it **identically**: bcrypt into `password_hash`, the
 * plaintext column emptied, `password_changed_at` stamped.  Two hand-written
 * `UPDATE`s would be two chances to get one of the three wrong, so there is one.
 *
 * ## Why this is raw SQL rather than `$user->save()`
 *
 * `password_hash` is `varbinary(64)`.  Binding a PHP string to it through
 * pdo_sqlsrv makes the driver describe the parameter as `nvarchar`, and SQL Server
 * refuses the assignment outright:
 *
 *     SQLSTATE[42000]: Implicit conversion from data type nvarchar to varbinary is
 *     not allowed.  Use the CONVERT function to run this query.
 *
 * That is a 500 on *every* password change, and it is invisible until the first one
 * happens.  The expression below decodes the value from its hexadecimal form
 * explicitly (`CONVERT(…, 2)` is SQL Server's "hex string, no `0x` prefix" style),
 * which was verified against the live server: the hash reads back as a 60-byte PHP
 * string and `password_verify()` accepts it.
 *
 * A neat side effect worth keeping: because the value travels as hex text, the
 * statement is the same shape on any connection, and the three columns are set in a
 * single statement so a reader cannot see a row with the plaintext cleared and no
 * hash.
 */
final class LegacyCredentialWriter
{
    /**
     * The `password_hash` assignment, with one placeholder.
     *
     * `password_hash` is written when and only when a password has just been proven
     * or just been set, so it always moves together with clearing `password`.
     */
    public const HASH_EXPRESSION = 'CONVERT(varbinary(64), CONVERT(varchar(128), ?), 2)';

    /**
     * The shared `SET` clause, in binding order: hash, then timestamp.
     */
    private const SET_CLAUSE = "password = '', password_hash = ".self::HASH_EXPRESSION.', password_changed_at = ?';

    /**
     * Replace the credential of the row with this primary key.
     *
     * Used by the first-login upgrade, where the row has already been located and
     * the password has just been verified.
     */
    public function forUserId(int|string $userId, string $plainPassword): bool
    {
        return DB::update(
            'UPDATE dbo.[user_table] SET '.self::SET_CLAUSE.' WHERE id = ?',
            [bin2hex(LegacyPassword::hash($plainPassword)), now(), $userId],
        ) > 0;
    }

    /**
     * Replace the credential of the named account.
     *
     * Used by the recovery flow, whose only handle on the account is the username
     * carried by the reset request.  The predicate is the legacy one
     * (`LTRIM(RTRIM(username)) = ?`, no yeh/kaf folding), exactly as the Python
     * `UPDATE` used, so it matches the same rows.
     *
     * Returns how many rows changed — `0` means the account vanished between the
     * request being approved and the password being set, which the caller must treat
     * as a failure rather than a success message.
     */
    public function forUsername(string $username, string $plainPassword): int
    {
        return DB::update(
            'UPDATE dbo.[user_table] SET '.self::SET_CLAUSE.' WHERE LTRIM(RTRIM(username)) = ?',
            [bin2hex(LegacyPassword::hash($plainPassword)), now(), PersianText::strip($username)],
        );
    }

    /**
     * Whether the credential column can be written at all.
     *
     * A cheap pre-flight for the recovery flow: the deployment checklist requires
     * `password_hash` to exist, and a missing column would otherwise surface as an
     * opaque 500 after the user has already been told their code was accepted.
     */
    public function isWritable(): bool
    {
        try {
            DB::selectOne('SELECT TOP 1 password_hash FROM dbo.[user_table]');

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
