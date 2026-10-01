<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use App\Support\Registration\ApprovedAccountWriter;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The self-registration surface — the public probes and the admin workflow —
 * ported from `app/api/routes/registration.py`.
 *
 * Everything here runs **offline** by default.  The database-dependent paths
 * are covered two ways:
 *
 * * the **error paths** are asserted directly, because offline the database
 *   queries fail and the Python's own `except` blocks answer — a dead database
 *   reading as "this id is free" on `check-national-id` is a real, preserved
 *   bug and is asserted as such;
 * * the **success paths** are asserted against a mocked `DB` facade, so the
 *   exact rows the controller hands to the credential writer can be checked
 *   without a live SQL Server.
 *
 * The end-to-end submit → approve flow against the real `userDB` lives behind
 * `HASTAMA_DB_TESTS=1` at the bottom, for the same reason
 * `LegacyReadDatabaseTest` is opt-in.
 */
final class RegistrationTest extends TestCase
{
    // ── Static option data ───────────────────────────────────────────────

    #[Test]
    public function the_departments_and_work_schedules_are_the_static_lists(): void
    {
        $departments = $this->get('/registration/departments');

        $departments->assertOk();
        $departments->assertJson(['success' => true]);
        $this->assertSame(
            ['بیوشیمی', 'هورمون', 'میکروب', 'مولکولی', 'مدیریت', 'پذیرش', 'نمونه گیری',
                'جوابدهی', 'حسابداری', 'ایمونولوژی', 'خدمات', 'فناوری', 'هماتولوژی'],
            $departments->json('data')
        );

        $schedules = $this->get('/registration/work-schedules');

        $schedules->assertOk();
        $schedules->assertJson(['success' => true]);
        $this->assertCount(11, $schedules->json('data'));
        // The first label's typo — a Latin `0` where the pattern says Persian —
        // is part of what the running server answers.
        $this->assertSame('۱۶:۰۰ - ۰۹:۰0 (صبح)', $schedules->json('data.0.label'));
        $this->assertSame('16:00 - 09:00', $schedules->json('data.0.value'));
    }

    // ── Guard matrix ─────────────────────────────────────────────────────

    #[Test]
    public function the_admin_routes_reject_an_anonymous_caller_with_401(): void
    {
        $this->get('/registration/admin/requests')->assertStatus(401);
        $this->get('/registration/admin/requests/HST-20261001-ABCDEF01')->assertStatus(401);
        $this->post('/registration/admin/requests/HST-20261001-ABCDEF01/approve')->assertStatus(401);
        $this->post('/registration/admin/requests/HST-20261001-ABCDEF01/reject')->assertStatus(401);
    }

    #[Test]
    public function the_admin_routes_reject_a_non_admin_caller_with_403(): void
    {
        $this->signInAsUser();

        $this->get('/registration/admin/requests')->assertStatus(403);
        $this->post('/registration/admin/requests/HST-20261001-ABCDEF01/approve')->assertStatus(403);
    }

    #[Test]
    public function the_active_users_route_guards_itself_for_anonymous_and_non_admin_callers(): void
    {
        // Both refusals are the same 403 body — the handler's own guard, not
        // the middleware's anonymous 401.
        $this->get('/registration/active-users')->assertStatus(403);
        $this->get('/registration/active-users')->assertJson([
            'success' => false,
            'error' => 'دسترسی مدیریتی ندارید.',
        ]);

        $this->signInAsUser();

        $this->get('/registration/active-users')->assertStatus(403);
    }

    #[Test]
    public function the_public_probes_are_reachable_without_a_session(): void
    {
        // The static ones answer 200; the database-backed ones answer their
        // own bodies (offline, a database failure) — never a 401/403.
        foreach (['/registration/check-username?q=someuser', '/registration/check-national-id?q=0012345678'] as $path) {
            $response = $this->get($path);

            $response->assertStatus(200);
        }
    }

    // ── Query validation ─────────────────────────────────────────────────

    /**
     * The admin list validates `page`/`per_page` with FastAPI's 422 body —
     * not Laravel's 302 redirect, which is what `$request->validate()` would
     * have produced for a request that does not send `Accept: application/json`.
     */
    #[Test]
    public function the_admin_list_validates_its_query_parameters(): void
    {
        $this->signInAsAdmin();

        $this->get('/registration/admin/requests?page=0')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'greater_than_equal',
                'loc' => ['query', 'page'],
                'msg' => 'Input should be greater than or equal to 1',
                'input' => '0',
                'ctx' => ['ge' => 1],
            ]]]);

        $this->get('/registration/admin/requests?per_page=101')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'less_than_equal')
            ->assertJsonPath('detail.0.loc', ['query', 'per_page']);

        $this->get('/registration/admin/requests?page=abc')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing');
    }

    // ── Offline error paths (the database is absent) ─────────────────────

    #[Test]
    public function check_username_reports_a_database_failure(): void
    {
        $this->get('/registration/check-username?q=someuser')
            ->assertOk()
            ->assertExactJson(['available' => false, 'message' => 'خطا در بررسی.']);
    }

    /**
     * The preserved bug: `check-national-id`'s `except` answers
     * `available: true`, so a database outage reads as "this id is free for
     * the taking".  The front-end branches on the flag, so the bug is
     * reproduced and pinned.
     */
    #[Test]
    public function check_national_id_reports_a_database_failure_as_available(): void
    {
        $this->get('/registration/check-national-id?q=0012345679')
            ->assertOk()
            ->assertExactJson(['available' => true]);
    }

    #[Test]
    public function the_status_probe_rejects_a_malformed_request_id(): void
    {
        $this->get('/registration/status/not-a-valid-id')
            ->assertOk()
            ->assertExactJson(['found' => false, 'message' => 'درخواست یافت نشد.']);
    }

    #[Test]
    public function submit_rejects_an_invalid_json_body_with_400(): void
    {
        $this->post('/registration/submit', [], ['CONTENT_TYPE' => 'application/json'])
            ->assertStatus(400);

        $this->postJson('/registration/submit', ['first_name' => 'Ali'])
            ->assertStatus(400)
            ->assertJson(['success' => false]);
    }

    #[Test]
    public function submit_rejects_validation_errors_with_the_module_envelope(): void
    {
        $response = $this->postJson('/registration/submit', [
            'first_name' => '',
            'last_name' => '',
            'username' => 'ab',
            'password' => 'short',
            'department' => '',
        ]);

        $response->assertStatus(400);
        $response->assertJson(['success' => false]);

        $errors = $response->json('errors');

        $this->assertContains('نام الزامی است.', $errors);
        $this->assertContains('رمز عبور باید حداقل ۸ کاراکتر باشد.', $errors);
        $this->assertContains('بخش فعالیت الزامی است.', $errors);
    }

    // ── Success paths (database facade mocked) ───────────────────────────

    #[Test]
    public function check_username_answers_available_when_the_database_agrees(): void
    {
        DB::shouldReceive('selectOne')->andReturn(null);

        $this->get('/registration/check-username?q=someuser')
            ->assertOk()
            ->assertExactJson(['available' => true, 'message' => 'نام کاربری قابل استفاده است.']);
    }

    /**
     * The submit success shape, and the rule that matters most on this
     * endpoint: the password is hashed and stored, and **never** appears in
     * the response — not even hashed.
     */
    #[Test]
    public function submit_stores_the_request_and_never_returns_the_password(): void
    {
        DB::shouldReceive('selectOne')->andReturn(null);
        DB::shouldReceive('insert')->once()->andReturn(1);

        $response = $this->postJson('/registration/submit', [
            'first_name' => 'علی',
            'last_name' => 'رضایی',
            'username' => 'alireza',
            'password' => 'Sup3rSecret!',
            'national_id' => '0012345678',
            'mobile' => '09123456789',
            'department' => 'بیوشیمی',
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertNotNull($response->json('request_id'));

        $content = $response->getContent();
        $this->assertStringNotContainsString('Sup3rSecret!', $content);
        $this->assertStringNotContainsString('password', $content);
    }

    /**
     * The approval writes the new account through the credential writer —
     * the one place a credential is created — and the test asserts exactly
     * what the controller handed over, using an offline double in place of
     * the real writer (which needs a live SQL Server for its `CONVERT`).
     */
    #[Test]
    public function approve_writes_the_account_through_the_credential_writer(): void
    {
        DB::shouldReceive('selectOne')->andReturnUsing(function (string $sql): ?object {
            if (str_contains($sql, 'user_registration_requests')) {
                return (object) [
                    'request_id' => 'HST-20261001-ABCDEF01',
                    'first_name' => 'علی',
                    'last_name' => 'رضایی',
                    'father_name' => null,
                    'national_id' => '0012345678',
                    'mobile' => '09123456789',
                    'username' => 'alireza',
                    'password_hash' => '2y$12$abcdefghijklmnopqrstuuV123456789012345678901234567890',
                    'department' => 'بیوشیمی',
                    'work_hours' => '16:00 - 09:00',
                    'substitute' => null,
                    'status' => 'pending',
                ];
            }

            if (str_contains($sql, 'LTRIM(RTRIM(username))')) {
                return null;
            }

            if (str_contains($sql, 'MAX(id)')) {
                return (object) ['next_id' => 42];
            }

            return null;
        });
        DB::shouldReceive('update')->once()->andReturn(1);
        DB::shouldReceive('transaction')->andReturnUsing(fn (callable $closure) => $closure());

        $writer = Mockery::mock(ApprovedAccountWriter::class);
        $writer->shouldReceive('create')
            ->once()
            ->with(
                42,
                'alireza',
                '2y$12$abcdefghijklmnopqrstuuV123456789012345678901234567890',
                'علی',
                'رضایی',
                'بیوشیمی',
                null,
                '16:00 - 09:00'
            )
            ->andReturnNull();
        $this->app->instance(ApprovedAccountWriter::class, $writer);

        $this->signInAsAdmin();

        $response = $this->postJson('/registration/admin/requests/HST-20261001-ABCDEF01/approve');

        $response->assertOk();
        $response->assertExactJson([
            'success' => true,
            'message' => 'حساب کاربری alireza با موفقیت ایجاد شد.',
        ]);

        $content = $response->getContent();
        $this->assertStringNotContainsString('2y$12$', $content);
        $this->assertStringNotContainsString('password', $content);
    }

    #[Test]
    public function approve_rejects_a_missing_or_already_processed_request_with_404(): void
    {
        DB::shouldReceive('selectOne')->andReturn(null);

        $this->signInAsAdmin();

        $this->postJson('/registration/admin/requests/HST-20261001-ABCDEF01/approve')
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'درخواست یافت نشد یا قبلاً پردازش شده.']);
    }

    #[Test]
    public function reject_requires_a_reason(): void
    {
        $this->signInAsAdmin();

        $this->postJson('/registration/admin/requests/HST-20261001-ABCDEF01/reject', ['reason' => ''])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'دلیل رد الزامی است.']);
    }

    // ── Rate limiting ────────────────────────────────────────────────────

    /**
     * The submit limiter is 5 requests / 10 minutes per IP, and a denial is
     * repeatable (the Python records a hit only while *under* the limit).
     */
    #[Test]
    public function submit_is_rate_limited_after_five_attempts(): void
    {
        DB::shouldReceive('selectOne')->andReturn(null);
        DB::shouldReceive('insert')->andReturn(1);

        $payload = [
            'first_name' => 'علی',
            'last_name' => 'رضایی',
            'username' => 'alireza',
            'password' => 'Sup3rSecret!',
            'department' => 'بیوشیمی',
        ];

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/registration/submit', $payload)->assertOk();
        }

        $this->postJson('/registration/submit', $payload)
            ->assertStatus(429)
            ->assertJson(['success' => false]);
    }

    // ── Live database (opt-in) ──────────────────────────────────────────

    /**
     * The end-to-end submit → approve flow against the real `userDB`.
     *
     * Opt-in for the same reason `LegacyReadDatabaseTest` is: an ordinary run
     * must stay offline, and the live database is not a fixture.  Every write
     * happens inside a transaction that is always rolled back.
     */
    #[Test]
    public function the_live_submit_and_approve_flow_creates_the_account(): void
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

        DB::beginTransaction();

        try {
            $username = 'liveuser'.bin2hex(random_bytes(3));

            $submit = $this->postJson('/registration/submit', [
                'first_name' => 'علی',
                'last_name' => 'رضایی',
                'username' => $username,
                'password' => 'Sup3rSecret!',
                'department' => 'بیوشیمی',
            ]);

            $submit->assertOk();
            $requestId = $submit->json('request_id');
            $this->assertNotNull($requestId);

            $this->signInAsAdmin();

            $approve = $this->postJson("/registration/admin/requests/{$requestId}/approve");

            $approve->assertOk();
            $approve->assertJson(['success' => true]);

            $this->assertDatabaseHas('user_table', ['username' => $username]);
        } finally {
            DB::rollBack();
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Establish the session an administrator's browser would hold: the
     * Laravel guard identity, the legacy session flags, and a registry token
     * (the `legacy.session:optional` middleware fails open when the registry
     * is unreachable, which is what allows the offline suite to run).
     */
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
        $user = new User();
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
