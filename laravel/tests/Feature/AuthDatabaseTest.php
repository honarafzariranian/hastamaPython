<?php

namespace Tests\Feature;

use App\Auth\LegacyUserProvider;
use App\Models\User;
use App\Services\Auth\SessionRegistry;
use App\Services\Settings\SystemSettings;
use App\Support\Legacy\LegacyCaptcha;
use App\Support\Legacy\LegacyPassword;
use App\Support\Legacy\PersianText;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The end-to-end login against the **real** `userDB`.
 *
 * This is the Phase 4 acceptance test, and it is the only test in the suite that
 * writes to the live database — which is why it does two things:
 *
 * 1. it is **opt-in** (`HASTAMA_DB_TESTS=1`), so an ordinary `php artisan test` run
 *    stays offline;
 * 2. every write happens inside a transaction that is **always rolled back**, so the
 *    live rows and the session registry are left bit-for-bit as they were found.
 *
 * The rollback is not a convenience, it is the point: the login flow is required to
 * *upgrade* a legacy plaintext credential to bcrypt, and the only honest way to test
 * that is to let it rewrite a real row and then undo it.  An assertion on a fixture
 * would prove nothing about the accounts that actually exist.
 *
 * Run it deliberately:
 *     HASTAMA_DB_TESTS=1 php artisan test --filter=AuthDatabaseTest
 */
final class AuthDatabaseTest extends TestCase
{
    /** The seeded CAPTCHA, so the credential check is what the request exercises. */
    private const CAPTCHA = 'ABCDEF';

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var((string) env('HASTAMA_DB_TESTS', '0'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Live-database verification is opt-in: set HASTAMA_DB_TESTS=1 to run it.');
        }

        // `phpunit.xml` redirects DB_DATABASE to `:memory:` for the offline suite;
        // inheriting that here would fail with "database :memory:" rather than
        // anything that looks like a connection problem.
        config([
            'database.default' => 'sqlsrv',
            'database.connections.sqlsrv.host' => '127.0.0.1',
            'database.connections.sqlsrv.port' => '1433',
            'database.connections.sqlsrv.database' => 'userDB',
            'database.connections.sqlsrv.username' => null,
            'database.connections.sqlsrv.password' => null,
        ]);

        DB::purge('sqlsrv');
        DB::setDefaultConnection('sqlsrv');

        // Everything this test does is undone.
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    // ── Wiring ───────────────────────────────────────────────────────────────

    #[Test]
    public function the_registered_provider_is_the_legacy_one(): void
    {
        // The single most important wiring assertion in the migration: Laravel's
        // stock `EloquentUserProvider` would call `Hash::check()` against the
        // plaintext column and reject 15 of the 16 live accounts.
        $this->assertInstanceOf(LegacyUserProvider::class, Auth::createUserProvider('users'));
    }

    #[Test]
    public function a_live_plaintext_account_verifies_through_the_provider(): void
    {
        $user = $this->plaintextAccount();
        $plain = $user->legacyPassword();

        $provider = Auth::createUserProvider('users');

        $this->assertTrue($provider->validateCredentials($user, ['password' => $plain]));
        $this->assertFalse($provider->validateCredentials($user, ['password' => $plain.'x']));
        $this->assertFalse($provider->validateCredentials($user, ['password' => '']));
        $this->assertNull($provider->retrieveByCredentials(['password' => $plain]));

        // The padded, letter-folded lookup must find the same row as the model's
        // own accessor — including the accounts typed with an Arabic yeh.
        $found = $provider->retrieveByCredentials(['username' => $user->username()]);
        $this->assertInstanceOf(User::class, $found);
        $this->assertSame($user->getKey(), $found->getKey());
    }

    // ── Login ────────────────────────────────────────────────────────────────

    #[Test]
    public function a_real_legacy_account_logs_in_and_has_its_credential_upgraded(): void
    {
        $user = $this->plaintextAccount();
        $plain = $user->legacyPassword();
        $username = $user->username();

        $this->assertNull($user->storedHash(), 'the fixture must start as plaintext');

        $response = $this->withSession($this->captchaSession())
            ->postJson('/login_user', [
                'username' => $username,
                'password' => $plain,
                'captcha' => self::CAPTCHA,
            ]);

        $response->assertOk()->assertJson(['success' => true]);

        $expected = match (true) {
            $this->isMasterAdmin($username) => '/master-admin',
            $user->isAdmin() => '/admin/dashboard',
            default => '/user_panel',
        };

        $this->assertSame($expected, $response->json('redirect'));

        // The session identity the Vue client reads from `/api/me`.
        $this->assertSame($username, session('username'));
        $this->assertSame(
            $user->isAdmin(),
            session('is_admin'),
            'the admin flag must be decided at login, as the guards assume',
        );
        $this->assertNotSame('', (string) session(SessionRegistry::SESSION_TOKEN_KEY));

        // The revocable half of the session now exists.
        $this->assertDatabaseHas('user_sessions', [
            'username' => $username,
            'is_active' => 1,
        ]);

        // And the credential is no longer plaintext.
        $after = User::query()->whereKey($user->getKey())->first();
        $this->assertNotNull($after);
        $this->assertSame('', PersianText::strip($after->getAttribute('password')), 'the plaintext column must be emptied');
        $this->assertNotNull($after->storedHash());
        $this->assertTrue(LegacyPassword::looksLikeBcrypt($after->storedHash()));
        $this->assertTrue($after->verifyPassword($plain), 'the new hash must verify the password that was proven');
        $this->assertFalse($after->verifyPassword($plain.'x'));
        $this->assertNotNull($after->getAttribute('password_changed_at'));
    }

    #[Test]
    public function a_second_login_uses_the_upgraded_hash_without_touching_the_row_again(): void
    {
        $user = $this->plaintextAccount();
        $plain = $user->legacyPassword();

        $this->withSession($this->captchaSession())->postJson('/login_user', [
            'username' => $user->username(),
            'password' => $plain,
            'captcha' => self::CAPTCHA,
        ])->assertOk()->assertJson(['success' => true]);

        $upgraded = User::query()->whereKey($user->getKey())->first();
        $firstHash = $upgraded->storedHash();
        $this->assertNotNull($firstHash);

        // A fresh browser session: the CAPTCHA is single-use, so a new one is seeded.
        $this->flushSession();

        $this->withSession($this->captchaSession())->postJson('/login_user', [
            'username' => $user->username(),
            'password' => $plain,
            'captcha' => self::CAPTCHA,
        ])->assertOk()->assertJson(['success' => true]);

        $second = User::query()->whereKey($user->getKey())->first();

        $this->assertSame(
            $firstHash,
            $second->storedHash(),
            'a row whose credential is already modern must not be rewritten on every login',
        );
    }

    #[Test]
    public function a_wrong_password_is_refused_with_the_generic_message(): void
    {
        $user = $this->plaintextAccount();

        $response = $this->withSession($this->captchaSession())
            ->postJson('/login_user', [
                'username' => $user->username(),
                'password' => 'definitely-not-the-password',
                'captcha' => self::CAPTCHA,
            ]);

        $response->assertOk()->assertJson([
            'success' => false,
            'message' => 'نام کاربری یا رمز عبور اشتباه است',
        ]);

        // Nothing was rewritten and the failure was counted.
        $after = User::query()->whereKey($user->getKey())->first();
        $this->assertNull($after->storedHash(), 'a failed login must never upgrade a credential');
        $this->assertGreaterThan(0, (int) $after->getAttribute('failed_login_count'));
    }

    #[Test]
    public function an_unknown_username_is_indistinguishable_from_a_wrong_password(): void
    {
        $user = $this->plaintextAccount();

        $unknown = $this->withSession($this->captchaSession())
            ->postJson('/login_user', [
                'username' => 'no-such-account-'.bin2hex(random_bytes(3)),
                'password' => 'whatever',
                'captcha' => self::CAPTCHA,
            ]);

        $known = $this->withSession($this->captchaSession())
            ->postJson('/login_user', [
                'username' => $user->username(),
                'password' => 'wrong-'.$user->legacyPassword(),
                'captcha' => self::CAPTCHA,
            ]);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->json(), $unknown->json());
    }

    #[Test]
    public function a_disabled_account_cannot_log_in_even_with_the_right_password(): void
    {
        $user = $this->plaintextAccount();
        $plain = $user->legacyPassword();

        // Changed inside the transaction, so the live row is untouched.
        DB::table('user_table')
            ->where('id', $user->getKey())
            ->update(['is_active' => 'disabled']);

        $this->assertSame(
            'disabled',
            PersianText::strip(User::query()->whereKey($user->getKey())->first()->getAttribute('is_active')),
        );

        $this->withSession($this->captchaSession())
            ->postJson('/login_user', [
                'username' => $user->username(),
                'password' => $plain,
                'captcha' => self::CAPTCHA,
            ])
            ->assertOk()
            ->assertJson(['success' => false, 'message' => 'نام کاربری یا رمز عبور اشتباه است']);

        $this->assertNull(
            User::query()->whereKey($user->getKey())->first()->storedHash(),
            'a disabled account must not have its credential rewritten',
        );
    }

    #[Test]
    public function a_wrong_captcha_stops_the_attempt_before_the_credentials_are_checked(): void
    {
        $user = $this->plaintextAccount();
        $plain = $user->legacyPassword();

        // Whether the CAPTCHA is required is a `system_config` decision; if the
        // operator has turned it off there is nothing to assert here.
        if (! app(SystemSettings::class)->captchaEnabled()) {
            $this->markTestSkipped('the CAPTCHA is disabled on this installation');
        }

        $response = $this->withSession($this->captchaSession())
            ->postJson('/login_user', [
                'username' => $user->username(),
                'password' => $plain,
                'captcha' => 'ZZZZZZ1',
            ]);

        $response->assertOk();
        $this->assertFalse($response->json('success'));
        $this->assertTrue($response->json('captcha_error'));
        $this->assertSame('mismatch', $response->json('captcha_reason'));

        // The credentials were correct, and the row still proves the check never ran:
        // an oracle that validates a password before the CAPTCHA is a password oracle.
        $this->assertNull(User::query()->whereKey($user->getKey())->first()->storedHash());
    }

    // ── Logout ───────────────────────────────────────────────────────────────

    #[Test]
    public function logout_revokes_the_registry_row_it_created(): void
    {
        $user = $this->plaintextAccount();
        $plain = $user->legacyPassword();

        $this->withSession($this->captchaSession())->postJson('/login_user', [
            'username' => $user->username(),
            'password' => $plain,
            'captcha' => self::CAPTCHA,
        ])->assertJson(['success' => true]);

        $token = (string) session(SessionRegistry::SESSION_TOKEN_KEY);
        $this->assertNotSame('', $token);

        $this->get('/logout')->assertRedirect('/login');

        $row = DB::table('user_sessions')->where('session_key', $token)->first();

        $this->assertNotNull($row, 'the session row is retained as history');
        $this->assertFalse((bool) $row->is_active);
        $this->assertNotNull($row->logout_at);
        $this->assertSame('self', trim((string) $row->terminated_by));
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A live account that still holds a legacy plaintext credential, preferring the
     * account most accounts look like (a plain user, not an administrator).
     */
    private function plaintextAccount(): User
    {
        $candidates = User::query()->get()->filter(
            static fn (User $user): bool => LegacyPassword::isLegacyPlaintext(
                $user->getAttribute('password'),
                $user->storedHash(),
            ),
        );

        $this->assertNotEmpty(
            $candidates,
            'the live data is expected to still contain plaintext credentials; if the migration has been completed, this test has nothing left to protect',
        );

        return $candidates->first(fn (User $user): bool => ! $user->isAdmin()) ?? $candidates->first();
    }

    /**
     * The session state a browser would be in just after fetching a CAPTCHA.
     *
     * @return array<string, mixed>
     */
    private function captchaSession(): array
    {
        return [
            LegacyCaptcha::SESSION_KEY => self::CAPTCHA,
            LegacyCaptcha::SESSION_TS_KEY => time(),
            LegacyCaptcha::SESSION_ATTEMPTS_KEY => 0,
        ];
    }

    private function isMasterAdmin(string $username): bool
    {
        $masters = array_map(
            static fn ($name): string => mb_strtolower(trim((string) $name)),
            (array) config('hastama.master_admin_usernames', []),
        );

        return in_array(mb_strtolower(PersianText::strip($username)), $masters, true);
    }
}
