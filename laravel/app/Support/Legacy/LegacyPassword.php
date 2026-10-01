<?php

namespace App\Support\Legacy;

/**
 * Password verification for the legacy credential columns.
 *
 * This is a direct port of `app/core/password_utils.py::verify_password`, and the
 * order of the checks is part of the contract — a row can legitimately hold its
 * credential in either column, in either format:
 *
 * | # | Where | Format | Live rows |
 * |---|---|---|---|
 * | 1 | `password_hash` | bcrypt (`$2a$`/`$2b$`/`$2y$`) | **1 of 16** |
 * | 2 | `password` | bcrypt (installs predating the hash column) | 0 |
 * | 3 | `password_hash` | SHA-512, raw digest or 128-char hex | 0 |
 * | 4 | `password` | **plaintext** | **15 of 16** |
 *
 * Path 4 is the migration's headline risk: 15 of the 16 live accounts store a
 * plaintext password in `nchar(10)`.  The brief's inspection rule and the
 * confirmed migration decision are both honoured by *keeping* the plaintext
 * comparison (so nobody is locked out at cutover) and upgrading the row to bcrypt
 * on the first successful login — see `needsPasswordUpgrade()` on the model.  No
 * row is ever rewritten without a successful authentication first.
 *
 * Every comparison is constant-time (`hash_equals`) or delegated to bcrypt, so
 * neither the format nor the matching prefix can be probed by timing.
 */
final class LegacyPassword
{
    /** bcrypt cost, matching `bcrypt.gensalt(rounds=12)` in the Python app. */
    public const BCRYPT_ROUNDS = 12;

    /**
     * bcrypt prefixes any implementation may have produced.
     *
     * @var array<int, string>
     */
    private const BCRYPT_PREFIXES = ['$2a$', '$2b$', '$2y$'];

    /**
     * Verify a password against the two legacy columns.
     *
     * `$storedPassword` is the `password` column, already trimmed of `nchar`
     * padding by the model, and `$storedHash` the `password_hash` column as raw
     * bytes or null.  `$provided` is the login attempt; it is trimmed here, because
     * the Python application trimmed it (`provided_password = str(...).strip()`),
     * and an untrimmed attempt would fail against every legacy row.
     */
    public static function verify(?string $storedPassword, ?string $storedHash, ?string $provided): bool
    {
        if ($provided === null) {
            return false;
        }

        // The Python implementation strips the attempt before every comparison
        // (`provided_password = str(...).strip()`), including before bcrypt.
        $attempt = PersianText::strip($provided);

        // 1. / 3. The hash column wins whenever it holds anything.
        if ($storedHash !== null && $storedHash !== '') {
            if (self::looksLikeBcrypt($storedHash)) {
                return self::verifyBcrypt($attempt, $storedHash);
            }

            $digest = hash('sha512', $attempt, true);
            $candidates = [$digest, strtolower(hash('sha512', $attempt))];

            foreach ($candidates as $candidate) {
                if (strlen($storedHash) === strlen($candidate) && hash_equals($storedHash, $candidate)) {
                    return true;
                }
            }

            // The hash column exists but matched nothing: the Python
            // implementation returns here rather than falling through, so a
            // half-migrated row cannot be authenticated by its stale plaintext.
            return false;
        }

        $stored = PersianText::strip($storedPassword ?? '');

        // 2. A bcrypt hash that ended up in the plaintext column.
        if (self::looksLikeBcrypt($stored)) {
            return self::verifyBcrypt($attempt, $stored);
        }

        // 3b. A SHA-512 digest that ended up in the plaintext column.
        if (self::isSha512Hex($stored)) {
            return hash_equals(mb_strtolower($stored), hash('sha512', $attempt));
        }

        // 4. Legacy plaintext — constant time.
        if ($stored === '' && $storedPassword === null) {
            return false;
        }

        return hash_equals($stored, $attempt);
    }

    /**
     * Whether a stored value is a bcrypt hash, in either column, bytes or text.
     */
    public static function looksLikeBcrypt(?string $value): bool
    {
        if ($value === null || strlen($value) < 4) {
            return false;
        }

        return in_array(substr($value, 0, 4), self::BCRYPT_PREFIXES, true);
    }

    /** Whether a stored value is a 128-character hexadecimal SHA-512 digest. */
    public static function isSha512Hex(?string $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-fA-F]{128}$/', $value) === 1;
    }

    /**
     * Whether this row still holds a credential we must migrate.
     *
     * Mirrors `is_legacy_plaintext()`: true for a non-empty `password` value that
     * is neither bcrypt nor a SHA-512 digest, and only when `password_hash` is
     * empty.  This is the predicate the deployment checklist ("no plaintext
     * password column") is written against.
     */
    public static function isLegacyPlaintext(?string $storedPassword, ?string $storedHash): bool
    {
        if ($storedHash !== null && $storedHash !== '') {
            return false;
        }

        $raw = PersianText::strip($storedPassword ?? '');

        if ($raw === '' || self::looksLikeBcrypt($raw)) {
            return false;
        }

        return ! self::isSha512Hex($raw);
    }

    /**
     * Hash a password for storage in `password_hash`.
     *
     * Returns raw bcrypt bytes, which is what the column (`varbinary(64)`) holds —
     * the single hashed row in the live table is a 60-byte bcrypt value, so PHP's
     * 60-byte output is byte-compatible and the two implementations can verify
     * each other's hashes.
     */
    public static function hash(string $password): string
    {
        // `password_hash` is called directly rather than through Laravel's `Hash`
        // facade so the output is provably the same shape Python produced: a
        // 60-byte `$2y$…` string with cost 12, verified above with
        // `password_verify` and therefore interchangeable with `bcrypt.checkpw`.
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_ROUNDS]);
    }

    /** Whether a stored hash was made with a cost factor we no longer accept. */
    public static function needsRehash(string $storedHash): bool
    {
        return password_needs_rehash($storedHash, PASSWORD_BCRYPT, ['cost' => self::BCRYPT_ROUNDS]);
    }

    private static function verifyBcrypt(string $attempt, string $hash): bool
    {
        // PHP's password_verify understands the $2a$ / $2b$ / $2y$ variants, and
        // accepts the raw bytes bcrypt produces.
        return password_verify($attempt, $hash);
    }
}
