<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use App\Support\Araz\DeviceConfig;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Araz T7 surface — the device/configuration endpoints and the
 * machine-to-machine `bridge-sync` — ported from `app/api/routes/araz_api.py`.
 *
 * Everything here runs **offline** and **without the real device**.  The
 * device endpoints are exercised against `127.0.0.1:1`, which refuses the
 * connection immediately, so the connector's own failure path answers:
 * `/test` turns it into `connected: false`, and the other four let it
 * propagate as a 500, exactly as the Python does.  No record is fabricated.
 *
 * The `bridge-sync` tests are the security contract: the fail-closed 503,
 * the constant-time secret comparison, the rate limit, and the body
 * validation order.
 */
final class ArazTest extends TestCase
{
    /** The secret the bridge-sync tests configure. */
    private const SECRET = 'bridge-secret-'.'test';

    protected function setUp(): void
    {
        parent::setUp();

        // The device config and the bridge secret are process state; reset
        // both so one test's override cannot leak into the next.
        DeviceConfig::resetToDefaults();
        unset($_ENV['ARAZ_BRIDGE_SECRET'], $_SERVER['ARAZ_BRIDGE_SECRET']);
    }

    protected function tearDown(): void
    {
        unset($_ENV['ARAZ_BRIDGE_SECRET'], $_SERVER['ARAZ_BRIDGE_SECRET']);
        DeviceConfig::resetToDefaults();

        parent::tearDown();
    }

    // ── Guard matrix ─────────────────────────────────────────────────────

    #[Test]
    public function the_device_and_config_routes_reject_an_anonymous_caller_with_401(): void
    {
        $this->get('/api/araz/config')->assertStatus(401);
        $this->post('/api/araz/config')->assertStatus(401);
        $this->get('/api/araz/test')->assertStatus(401);
        $this->get('/api/araz/time')->assertStatus(401);
        $this->post('/api/araz/time/sync')->assertStatus(401);
        $this->get('/api/araz/records')->assertStatus(401);
        $this->post('/api/araz/sync')->assertStatus(401);
    }

    #[Test]
    public function the_device_and_config_routes_reject_a_non_admin_caller_with_403(): void
    {
        $this->signInAsUser();

        $this->get('/api/araz/config')->assertStatus(403);
        $this->get('/api/araz/test')->assertStatus(403);
        $this->post('/api/araz/sync')->assertStatus(403);
    }

    // ── Configuration ────────────────────────────────────────────────────

    #[Test]
    public function an_admin_reads_the_default_device_configuration(): void
    {
        $this->signInAsAdmin();

        $this->get('/api/araz/config')
            ->assertOk()
            ->assertExactJson([
                'ip' => '192.168.3.200',
                'port' => 1001,
                'device_number' => 1,
                'timeout' => 10.0,
            ]);
    }

    #[Test]
    public function an_admin_updates_the_device_configuration(): void
    {
        $this->signInAsAdmin();

        $this->postJson('/api/araz/config', ['ip' => '127.0.0.1', 'port' => 1])
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->get('/api/araz/config')
            ->assertOk()
            ->assertJson(['ip' => '127.0.0.1', 'port' => 1]);
    }

    #[Test]
    public function the_config_update_validates_its_field_types(): void
    {
        $this->signInAsAdmin();

        $this->postJson('/api/araz/config', ['port' => 'not-a-number'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.loc', ['body', 'port']);

        $this->postJson('/api/araz/config', ['timeout' => 'not-a-number'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['body', 'timeout']);
    }

    // ── Device endpoints against an unreachable device ────────────────────

    /**
     * Point the connector at a port that refuses the connection, then assert
     * each endpoint's distinct failure contract.
     */
    #[Test]
    public function the_device_endpoints_surface_the_connection_failure(): void
    {
        $this->signInAsAdmin();

        $this->postJson('/api/araz/config', ['ip' => '127.0.0.1', 'port' => 1, 'timeout' => 1])->assertOk();

        // /test is the one endpoint that catches it and answers 200.
        $this->get('/api/araz/test')
            ->assertOk()
            ->assertJson([
                'connected' => false,
                'ip' => '127.0.0.1',
                'port' => 1,
                'device_time' => null,
            ])
            ->assertJsonPath('error', fn ($error): bool => is_string($error) && $error !== '');

        // The other four let the ConnectionError propagate, as the Python does.
        $this->get('/api/araz/time')->assertStatus(500);
        $this->post('/api/araz/time/sync')->assertStatus(500);
        $this->get('/api/araz/records')->assertStatus(500);
        $this->post('/api/araz/sync')->assertStatus(500);
    }

    // ── bridge-sync: the fail-closed secret ─────────────────────────────

    /**
     * With `ARAZ_BRIDGE_SECRET` unset the endpoint refuses to sync at all —
     * a 503, not a 401 and not a silent accept.  This is the fail-closed
     * behaviour the Python's `if not BRIDGE_SECRET` establishes.
     */
    #[Test]
    public function bridge_sync_fails_closed_when_no_secret_is_configured(): void
    {
        $this->postJson('/api/araz/bridge-sync', [
            'records' => [],
            'secret' => 'anything',
        ])
            ->assertStatus(503)
            ->assertExactJson(['success' => false, 'error' => 'ARAZ_BRIDGE_SECRET not configured']);
    }

    #[Test]
    public function bridge_sync_rejects_a_bad_secret_with_401(): void
    {
        $this->configureSecret();

        $this->postJson('/api/araz/bridge-sync', [
            'records' => [],
            'secret' => 'wrong-secret',
        ])
            ->assertStatus(401)
            ->assertExactJson(['detail' => 'Invalid bridge secret']);
    }

    // ── bridge-sync: the accepted contract ──────────────────────────────

    #[Test]
    public function bridge_sync_accepts_a_valid_secret_with_no_records(): void
    {
        $this->configureSecret();

        $this->postJson('/api/araz/bridge-sync', [
            'records' => [],
            'secret' => self::SECRET,
        ])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'synced' => 0,
                'skipped' => 0,
                'failed' => 0,
                'message' => 'No records provided',
            ]);
    }

    /**
     * Offline, the batch reaches the database and fails there (no `user_table`
     * on the test connection) — the endpoint's own real error path, answering
     * the generic database error and never the driver's message.
     */
    #[Test]
    public function bridge_sync_reports_the_database_error_for_a_batch_it_cannot_apply(): void
    {
        $this->configureSecret();

        $this->postJson('/api/araz/bridge-sync', [
            'records' => [
                ['username' => 'admin', 'tarikh' => '1404/06/06', 'vorood' => '08:30', 'khorooj' => '17:00'],
            ],
            'secret' => self::SECRET,
        ])
            ->assertOk()
            ->assertExactJson([
                'success' => false,
                'synced' => 0,
                'skipped' => 0,
                'failed' => 0,
                'message' => 'Database error while applying bridge records',
            ]);
    }

    // ── bridge-sync: body validation ────────────────────────────────────

    /**
     * FastAPI validates the body before the handler runs, so a malformed body
     * is a 422 even when the secret is configured — and even when it is not.
     */
    #[Test]
    public function bridge_sync_validates_its_body(): void
    {
        $this->configureSecret();

        // records is required.
        $this->postJson('/api/araz/bridge-sync', ['secret' => self::SECRET])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['body', 'records']);

        // records must be a list.
        $this->postJson('/api/araz/bridge-sync', ['records' => 'not-a-list', 'secret' => self::SECRET])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'list_type');

        // a record field must be a string.
        $this->postJson('/api/araz/bridge-sync', [
            'records' => [['username' => 123, 'tarikh' => '1404/06/06', 'vorood' => '08:30', 'khorooj' => '17:00']],
            'secret' => self::SECRET,
        ])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_type')
            ->assertJsonPath('detail.0.loc', ['body', 'records', 0, 'username']);
    }

    // ── bridge-sync: rate limiting ───────────────────────────────────────

    /**
     * 60 requests / 60 seconds per IP.  The 61st is refused, and a denial is
     * repeatable (the limiter records a hit only while *under* the limit).
     */
    #[Test]
    public function bridge_sync_is_rate_limited(): void
    {
        $this->configureSecret();

        $payload = ['records' => [], 'secret' => self::SECRET];

        for ($i = 0; $i < 60; $i++) {
            $this->postJson('/api/araz/bridge-sync', $payload)->assertOk();
        }

        $this->postJson('/api/araz/bridge-sync', $payload)
            ->assertStatus(429)
            ->assertExactJson(['detail' => 'Too many bridge-sync requests']);
    }

    // ── Live database (opt-in) ──────────────────────────────────────────

    /**
     * A real bridge-sync batch against the live `userDB`: a known active
     * user's record is written to `hozoor`, an unknown user is counted as
     * failed.  Opt-in for the same reason `LegacyReadDatabaseTest` is.
     */
    #[Test]
    public function the_live_bridge_sync_writes_known_users(): void
    {
        if (! filter_var((string) env('HASTAMA_DB_TESTS', '0'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Live-database verification is opt-in: set HASTAMA_DB_TESTS=1 to run it.');
        }

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
        $this->app['cache']->flush();

        $this->configureSecret();

        $known = DB::table('user_table')
            ->whereRaw("ISNULL(is_active, 'active') = 'active'")
            ->value('username');

        $this->assertNotNull($known, 'the live data is expected to hold at least one active account');

        DB::beginTransaction();

        try {
            $response = $this->postJson('/api/araz/bridge-sync', [
                'records' => [
                    ['username' => $known, 'tarikh' => '1404/06/06', 'vorood' => '08:30', 'khorooj' => '17:00'],
                    ['username' => 'no-such-user-'.bin2hex(random_bytes(4)), 'tarikh' => '1404/06/06', 'vorood' => '08:30', 'khorooj' => '17:00'],
                ],
                'secret' => self::SECRET,
            ]);

            $response->assertOk();
            $this->assertSame(1, $response->json('synced'));
            $this->assertSame(1, $response->json('failed'));
            $this->assertFalse($response->json('success'));
        } finally {
            DB::rollBack();
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function configureSecret(): void
    {
        $_ENV['ARAZ_BRIDGE_SECRET'] = self::SECRET;
        $_SERVER['ARAZ_BRIDGE_SECRET'] = self::SECRET;
    }

    private function signInAsAdmin(): static
    {
        return $this->signIn('admin', true);
    }

    private function signInAsUser(): static
    {
        return $this->signIn('staffuser', false);
    }

    private function signIn(string $username, bool $isAdmin): static
    {
        $user = new User;
        $user->setAttribute('username', $username);
        $user->setAttribute('role', $isAdmin ? 'admin' : 'user');

        $this->actingAs($user);

        return $this->withSession([
            'username' => $username,
            'is_admin' => $isAdmin,
            SessionRegistry::SESSION_TOKEN_KEY => 'test-token-'.bin2hex(random_bytes(4)),
        ]);
    }
}
