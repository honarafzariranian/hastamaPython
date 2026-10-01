<?php

namespace Tests\Unit;

use App\Support\Legacy\LegacyPassword;
use PHPUnit\Framework\TestCase;

/**
 * Password verification, including the path that matters most.
 *
 * **15 of the 16 live accounts store a plaintext password in `nchar(10)`.**  The
 * migration decision was to keep verifying it (so nobody is locked out at cutover)
 * and upgrade the row to bcrypt on the first successful login.  If the plaintext
 * path breaks, the entire user base loses access — so it is tested directly, with
 * the padding the `nchar(10)` column really returns.
 */
class LegacyPasswordTest extends TestCase
{
    private const PLAINTEXT_FIXTURE = 'a1b2c3';

    public function test_plaintext_path_accepts_the_password_despite_nchar_padding(): void
    {
        // What the driver hands back for `nchar(10)`: the value plus its padding.
        $stored = str_pad(self::PLAINTEXT_FIXTURE, 10);

        $this->assertTrue(LegacyPassword::verify($stored, null, self::PLAINTEXT_FIXTURE));
        $this->assertFalse(LegacyPassword::verify($stored, null, 'wrong'));
        $this->assertFalse(LegacyPassword::verify($stored, null, ''));
        $this->assertFalse(LegacyPassword::verify($stored, null, null));
    }

    public function test_a_provided_password_is_trimmed_like_the_python_application(): void
    {
        $this->assertTrue(LegacyPassword::verify('abc123', null, '  abc123  '));
    }

    public function test_bcrypt_in_the_hash_column(): void
    {
        $hash = password_hash('correct horse', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertTrue(LegacyPassword::verify('', $hash, 'correct horse'));
        $this->assertFalse(LegacyPassword::verify('', $hash, 'wrong horse'));
    }

    public function test_a_present_hash_column_does_not_fall_through_to_stale_plaintext(): void
    {
        /*
         * The Python implementation returns after the hash branch, so a row that
         * has a `password_hash` cannot be authenticated by whatever is still sitting
         * in the legacy `password` column.  Half-migrated rows must not open a second
         * door.
         */
        $hash = password_hash('the real one', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertFalse(LegacyPassword::verify('stale-plaintext', $hash, 'stale-plaintext'));
        $this->assertTrue(LegacyPassword::verify('stale-plaintext', $hash, 'the real one'));
    }

    public function test_bcrypt_that_ended_up_in_the_plaintext_column(): void
    {
        $hash = password_hash('legacy-install', PASSWORD_BCRYPT, ['cost' => 4]);

        $this->assertTrue(LegacyPassword::verify($hash, null, 'legacy-install'));
        $this->assertFalse(LegacyPassword::verify($hash, null, 'something else'));
    }

    public function test_sha512_digest_in_either_shape(): void
    {
        $password = 'sha-era-password';
        $hex = hash('sha512', $password);
        $raw = hash('sha512', $password, true);

        $this->assertTrue(LegacyPassword::verify('', $hex, $password));
        $this->assertTrue(LegacyPassword::verify('', $raw, $password));
        $this->assertTrue(LegacyPassword::verify($hex, null, $password));
        $this->assertFalse(LegacyPassword::verify('', $hex, 'nope'));
    }

    public function test_hashes_are_interchangeable_with_the_python_implementation(): void
    {
        /*
         * The single hashed live row is a 60-byte bcrypt value produced by Python's
         * `bcrypt.hashpw`.  PHP must produce something the same shape, and must be
         * able to verify Python's, otherwise the one already-migrated account breaks.
         */
        $hash = LegacyPassword::hash('round-trip');

        $this->assertSame(60, strlen($hash));
        $this->assertTrue(LegacyPassword::looksLikeBcrypt($hash));
        $this->assertTrue(password_verify('round-trip', $hash));

        // A hash produced with Python's `$2b$` prefix must be accepted.
        $pythonStyle = '$2b$12$'.substr($hash, 7);
        $this->assertTrue(LegacyPassword::looksLikeBcrypt($pythonStyle));
    }

    public function test_legacy_plaintext_predicate_identifies_the_rows_that_need_migrating(): void
    {
        $this->assertTrue(LegacyPassword::isLegacyPlaintext(str_pad('a1b2c3', 10), null));
        $this->assertTrue(LegacyPassword::isLegacyPlaintext('a1b2c3', ''));

        // Already hashed → nothing to do.
        $this->assertFalse(LegacyPassword::isLegacyPlaintext('a1b2c3', password_hash('a1b2c3', PASSWORD_BCRYPT)));
        // Already a digest → the SHA-512 path handles it.
        $this->assertFalse(LegacyPassword::isLegacyPlaintext(hash('sha512', 'x'), null));
        // Empty password column → no credential to migrate.
        $this->assertFalse(LegacyPassword::isLegacyPlaintext(str_repeat(' ', 10), null));
        $this->assertFalse(LegacyPassword::isLegacyPlaintext(null, null));
    }
}
