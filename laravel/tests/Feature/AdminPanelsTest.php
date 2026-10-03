<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Legacy\LegacyDate;
use App\Support\Legacy\LegacyPassword;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The admin-panel surface — guards, validation, success shapes and the
 * report/print behaviour, all offline.
 *
 * The default suite never touches a database.  The guard matrix and the 422
 * bodies are decided *before* the handler's first query, so they need nothing.
 * The success shapes run against {@see FakeAdminConnection}, a programmable
 * stand-in that records every statement and its bindings and answers from the
 * SQL's shape — the same "offline double" pattern `UserPanelWritesTest`
 * established.
 *
 * The live-database verification (the real `userDB`) is opt-in through
 * `HASTAMA_DB_TESTS=1`, inside a transaction that is always rolled back — see
 * {@see LegacyReadDatabaseTest}.
 */
final class AdminPanelsTest extends TestCase
{
    private FakeAdminConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new FakeAdminConnection;
        $this->db->setQueryGrammar(new SqlServerGrammar($this->db));

        DB::extend('legacy', fn () => $this->db);
        config(['database.default' => 'legacy', 'database.connections.legacy' => ['driver' => 'fake']]);
        DB::purge('legacy');

        // The registry check every `legacy.session:optional` request passes
        // through: an active row for the session's own token.
        $this->db->sessionRows = [(object) ['username' => 'test-user', 'is_active' => 1, 'last_activity' => null]];
    }

    protected function tearDown(): void
    {
        DB::purge('legacy');

        parent::tearDown();
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A signed-in administrator — the guard identity and the legacy session flags.
     */
    private function asAdmin(string $username = 'test-admin'): static
    {
        $this->actingAs(new User(['username' => $username]));
        $this->db->sessionRows = [(object) ['username' => $username, 'is_active' => 1, 'last_activity' => null]];

        return $this->withSession([
            'username' => $username,
            'sid' => 'test-token',
            'sid_iat' => time(),
            'is_admin' => true,
        ]);
    }

    /**
     * A signed-in ordinary user.
     */
    private function asUser(string $username = 'test-user'): static
    {
        $this->actingAs(new User(['username' => $username]));
        $this->db->sessionRows = [(object) ['username' => $username, 'is_active' => 1, 'last_activity' => null]];

        return $this->withSession([
            'username' => $username,
            'sid' => 'test-token',
            'sid_iat' => time(),
        ]);
    }

    /** A JSON request, the way the legacy front-end calls these endpoints. */
    private function jsonHeaders(): array
    {
        return ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    }

    /**
     * A form-encoded request that still answers JSON.
     *
     * `add_user` declares `Form(...)` fields, so the body must be form-encoded;
     * a `CONTENT_TYPE: application/json` header would make the request bag empty
     * and every field "missing".
     */
    private function formHeaders(): array
    {
        return ['HTTP_ACCEPT' => 'application/json'];
    }

    // ── Guard matrix ─────────────────────────────────────────────────────────

    /**
     * The `_require_admin` middleware routes refuse an anonymous request with the
     * middleware's 401 — the session middleware passes an anonymous request
     * through, and the `admin` guard answers.
     */
    #[Test]
    public function the_require_admin_routes_refuse_an_anonymous_request(): void
    {
        $routes = [
            ['POST', '/update_user'],
            ['POST', '/update_leave_status'],
            ['GET', '/get_hourly_pass_requests'],
            ['POST', '/change_hourly_pass_status'],
            ['POST', '/get_hourly_pass_report'],
            ['POST', '/update_hourly_pass_status'],
            ['GET', '/get_overtime_requests'],
            ['GET', '/overtime_report'],
            ['POST', '/generate_individual_report'],
        ];

        foreach ($routes as [$method, $uri]) {
            $response = $this->call($method, $uri, [], [], [], $this->jsonHeaders());

            $response->assertStatus(401, "{$method} {$uri}");
            $this->assertSame(['success' => false, 'error' => 'لاگین نکرده‌اید.'], $response->json());
        }
    }

    /**
     * The two payroll routes guard with `get_is_admin_from_session()` —
     * `is_admin is True` only — so an anonymous request is a **403** carrying
     * `دسترسی مجاز نیست.`, not the 401 the `admin` middleware answers.
     */
    #[Test]
    public function the_payroll_routes_refuse_an_anonymous_request_with_the_is_admin_body(): void
    {
        // payroll/save has no pre-handler validation, so the guard answers 403.
        $this->call('POST', '/api/admin/payroll/save', [], [], [], $this->jsonHeaders())
            ->assertStatus(403)
            ->assertExactJson(['success' => false, 'error' => 'دسترسی مجاز نیست.']);

        // payroll/load validates its query parameters first, so a missing one is
        // a 422 before the guard.
        $this->call('GET', '/api/admin/payroll/load', [], [], [], $this->jsonHeaders())
            ->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['query', 'calculation_type']);
    }

    /**
     * FastAPI validates a handler's declared parameters before the handler runs,
     * so on the validation-then-guard routes an anonymous request with a bad
     * parameter is a **422**, not the 401/403 the guard would answer.
     */
    #[Test]
    public function the_validation_routes_answer_422_before_the_guard(): void
    {
        // A missing form field.
        $this->call('POST', '/add_user', [], [], [], $this->jsonHeaders())
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['body', 'name']);

        // A missing query parameter.
        $this->call('GET', '/fetch_user_data', [], [], [], $this->jsonHeaders())
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['query', 'username']);

        // A pydantic model with a missing field — an empty JSON object reaches
        // the field-level `missing`.
        $this->call('POST', '/update_overtime_status', [], [], [], $this->jsonHeaders(), '{}')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['body', 'requestId']);

        // A `data: dict` body that is a JSON array.
        $this->call('POST', '/get_overtime_report', [], [], [], $this->jsonHeaders(), '[1,2,3]')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'dict_type');
    }

    /**
     * A signed-in non-administrator is refused by the `admin` middleware with its
     * own 403 body — the `error` key, not `message`.
     */
    #[Test]
    public function the_require_admin_routes_refuse_a_non_administrator(): void
    {
        $this->asUser();

        $routes = [
            ['POST', '/update_user'],
            ['POST', '/update_leave_status'],
            ['GET', '/get_hourly_pass_requests'],
            ['POST', '/change_hourly_pass_status'],
            ['POST', '/get_hourly_pass_report'],
            ['POST', '/update_hourly_pass_status'],
            ['GET', '/get_overtime_requests'],
            ['GET', '/overtime_report'],
            ['POST', '/generate_individual_report'],
        ];

        foreach ($routes as [$method, $uri]) {
            $response = $this->call($method, $uri, [], [], [], $this->jsonHeaders());

            $response->assertStatus(403, "{$method} {$uri}");
            $this->assertSame(['success' => false, 'error' => 'دسترسی مدیریتی ندارید.'], $response->json());
        }
    }

    /**
     * The report pages and `download_pdf` guard with `_require_auth`, so an
     * anonymous request is a 401.
     */
    #[Test]
    public function the_report_pages_refuse_an_anonymous_request(): void
    {
        $routes = [
            '/leave_report_page',
            '/hourlypass_Report_page',
            '/overtime_report_page',
            '/payroll_report_page',
            '/final_report_page',
            '/download_pdf',
        ];

        foreach ($routes as $uri) {
            $response = $this->call('GET', $uri, [], [], [], $this->jsonHeaders());

            $response->assertStatus(401, "GET {$uri}");
            $this->assertSame(['success' => false, 'error' => 'لاگین نکرده‌اید.'], $response->json());
        }
    }

    // ── Payroll ──────────────────────────────────────────────────────────────

    /**
     * `payroll/save` validates in the Python's order, and each step has its own
     * body.
     */
    #[Test]
    public function payroll_save_validates_in_the_python_order(): void
    {
        $this->asAdmin();

        // 1. A bad calculation_type.
        $this->postJson('/api/admin/payroll/save', ['calculation_type' => 'bogus', 'period_year' => 1405, 'period_month' => '07', 'rows' => []])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'error' => 'نوع محاسبه معتبر نیست.']);

        // 2. An unparsable period_year.
        $this->postJson('/api/admin/payroll/save', ['calculation_type' => 'overtime', 'period_year' => 'abc', 'period_month' => '07', 'rows' => []])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'error' => 'اطلاعات ذخیره‌سازی معتبر نیست.']);

        // 3. An out-of-range year.
        $this->postJson('/api/admin/payroll/save', ['calculation_type' => 'overtime', 'period_year' => 1200, 'period_month' => '07', 'rows' => []])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'error' => 'اطلاعات دوره یا ردیف‌ها معتبر نیست.']);

        // 4. A non-list rows.
        $this->postJson('/api/admin/payroll/save', ['calculation_type' => 'overtime', 'period_year' => 1405, 'period_month' => '07', 'rows' => 'x'])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'error' => 'اطلاعات دوره یا ردیف‌ها معتبر نیست.']);

        // 5. More than 500 rows.
        $rows = array_fill(0, 501, ['username' => 'ali', 'payload' => ['x' => 1]]);
        $this->postJson('/api/admin/payroll/save', ['calculation_type' => 'overtime', 'period_year' => 1405, 'period_month' => '07', 'rows' => $rows])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'error' => 'تعداد ردیف‌ها بیش از حد مجاز است.']);
    }

    /**
     * `payroll/save` upserts one payload per row and answers the saved count.
     *
     * The count is the number of rows that produced an UPDATE or INSERT, which
     * is not the number sent: a row whose username is missing from `user_table`
     * is skipped.
     */
    #[Test]
    public function payroll_save_upserts_and_counts_only_the_saved_rows(): void
    {
        $this->asAdmin();
        $this->db->userExists = false;

        $response = $this->postJson('/api/admin/payroll/save', [
            'calculation_type' => 'overtime',
            'period_year' => 1405,
            'period_month' => '07',
            'rows' => [
                ['username' => 'ghost', 'payload' => ['x' => 1]],
                'not-a-row',
                ['username' => 'ali'],
            ],
        ]);

        $response->assertOk();
        $this->assertSame([
            'success' => true,
            'saved' => 0,
            'message' => 'تغییرات با موفقیت ذخیره شد.',
        ], $response->json());

        // The user-existence check ran for the one row that had a username.
        $this->assertNotEmpty($this->db->queriesContaining('SELECT 1 FROM user_table'));
    }

    /**
     * `payroll/save` with an existing user performs the UPDATE.
     */
    #[Test]
    public function payroll_save_updates_an_existing_row(): void
    {
        $this->asAdmin();
        $this->db->userExists = true;
        $this->db->updateCount = 1;

        $response = $this->postJson('/api/admin/payroll/save', [
            'calculation_type' => 'overtime',
            'period_year' => 1405,
            'period_month' => '07',
            'rows' => [
                ['username' => 'ali', 'payload' => ['x' => 1]],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('saved'));

        $updates = $this->db->queriesContaining('UPDATE dbo.admin_payroll_calculations');
        $this->assertCount(1, $updates);
        $this->assertSame(['{"x":1}', 'test-admin', 'overtime', 1405, '07', 'ali'], $updates[0][1]);
    }

    /**
     * `payroll/load` requires its three query parameters and validates the type
     * and period.
     */
    #[Test]
    public function payroll_load_validates_the_query_and_period(): void
    {
        $this->asAdmin();

        // Missing all three.
        $this->getJson('/api/admin/payroll/load')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['query', 'calculation_type']);

        // A bad calculation_type.
        $this->getJson('/api/admin/payroll/load?calculation_type=bogus&period_year=1405&period_month=07')
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'error' => 'نوع محاسبه معتبر نیست.']);

        // An unparsable year.
        $this->getJson('/api/admin/payroll/load?calculation_type=overtime&period_year=abc&period_month=07')
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'error' => 'دوره معتبر نیست.']);

        // An out-of-range year.
        $this->getJson('/api/admin/payroll/load?calculation_type=overtime&period_year=1700&period_month=07')
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'error' => 'دوره معتبر نیست.']);
    }

    /**
     * `payroll/load` returns the decoded payloads and the `str(datetime)` form
     * of `updated_at`.
     */
    #[Test]
    public function payroll_load_returns_decoded_payloads_and_the_str_datetime_form(): void
    {
        $this->asAdmin();
        $this->db->payrollRows = [
            (object) ['username' => 'ali', 'payload_json' => '{"x":1}', 'updated_at' => '2026-09-30 08:53:41.864'],
            (object) ['username' => 'sara', 'payload_json' => 'not json', 'updated_at' => null],
        ];

        $response = $this->getJson('/api/admin/payroll/load?calculation_type=overtime&period_year=1405&period_month=07');

        $response->assertOk();
        $this->assertSame(['success', 'items'], array_keys($response->json()));

        $items = $response->json('items');
        $this->assertCount(2, $items);

        $this->assertSame('ali', $items[0]['username']);
        $this->assertSame(['x' => 1], $items[0]['payload']);
        // `str(datetime)` — a space, and six digits because the microsecond is
        // non-zero.
        $this->assertSame('2026-09-30 08:53:41.864000', $items[0]['updated_at']);

        // An unparseable payload becomes {}, and a NULL updated_at becomes null.
        $this->assertSame([], $items[1]['payload']);
        $this->assertNull($items[1]['updated_at']);
    }

    // ── User writes ──────────────────────────────────────────────────────────

    /**
     * `add_user` validates the fifteen form fields — a missing **or empty** one
     * is a 422 `missing`, reported for every such field at once.
     */
    #[Test]
    public function add_user_validates_the_form_fields(): void
    {
        $this->asAdmin();

        // Nothing at all.
        $this->post('/add_user', [], [], [], $this->jsonHeaders())
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['body', 'name']);

        // An empty required field is missing too.
        $this->post('/add_user', ['name' => '', 'last_name' => 'x'], [], [], [], $this->jsonHeaders())
            ->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['body', 'name']);

        // A present-but-empty employment_status takes the default, not a failure.
        $fields = array_fill_keys([
            'name', 'last_name', 'department', 'work_hours', 'substitute',
            'username', 'password', 'role', 'hozoorNum',
            'shanbeh', 'yekshanbeh', 'doshanbeh', 'seshanbeh', 'chrshanbeh', 'panjshanbeh',
        ], 'x');
        $fields['username'] = 'ali';
        $fields['role'] = 'user';
        $fields['password'] = 'secret';
        $fields['employment_status'] = '';

        $this->post('/add_user', $fields, [], [], [], $this->jsonHeaders())
            ->assertOk();
    }

    /**
     * `add_user` refuses a bad username, a bad role and an empty password with
     * its own 400 bodies.
     */
    #[Test]
    public function add_user_refuses_a_bad_username_role_and_password(): void
    {
        $this->asAdmin();

        $fields = array_fill_keys([
            'name', 'last_name', 'department', 'work_hours', 'substitute',
            'username', 'password', 'role', 'hozoorNum',
            'shanbeh', 'yekshanbeh', 'doshanbeh', 'seshanbeh', 'chrshanbeh', 'panjshanbeh',
        ], 'x');

        // A one-character username.
        $fields['username'] = 'a';
        $this->post('/add_user', $fields, [], [], [], $this->jsonHeaders())
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'error' => 'نام کاربری باید حداقل ۲ کاراکتر باشد.']);

        // A bad role.
        $fields['username'] = 'ali';
        $fields['role'] = 'superuser';
        $this->post('/add_user', $fields, [], [], [], $this->jsonHeaders())
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'error' => 'نقش معتبر نیست.']);

        // An empty password is a missing form field to FastAPI, so it is the
        // 422 — the handler's own 400 is unreachable behind the form validation.
        $fields['role'] = 'user';
        $fields['password'] = '';
        $this->post('/add_user', $fields, [], [], [], $this->jsonHeaders())
            ->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['body', 'password']);
    }

    /**
     * `add_user` stores the bcrypt hash and answers `null` on success — the
     * handler's `RedirectResponse` is commented out, so FastAPI serialises the
     * fall-through `None`.
     */
    #[Test]
    public function add_user_stores_the_hash_and_answers_null(): void
    {
        $this->asAdmin();
        $this->db->idIsFree = true;

        $fields = array_fill_keys([
            'name', 'last_name', 'department', 'work_hours', 'substitute',
            'username', 'password', 'role', 'hozoorNum',
            'shanbeh', 'yekshanbeh', 'doshanbeh', 'seshanbeh', 'chrshanbeh', 'panjshanbeh',
        ], 'x');
        $fields['username'] = 'ali';
        $fields['role'] = 'user';
        $fields['password'] = 'secret123';

        $response = $this->post('/add_user', $fields, [], [], [], $this->jsonHeaders());

        $response->assertOk();
        $this->assertSame('null', $response->getContent());

        // The INSERT wrote an empty plaintext column and a bcrypt hash.
        $inserts = $this->db->queriesContaining('insert into [user_table]');
        $this->assertNotEmpty($inserts);

        $bindings = $inserts[0][1];
        $this->assertContains('', $bindings, 'the plaintext password column is written as empty');

        $hash = array_values(array_filter(
            $bindings,
            static fn ($value): bool => LegacyPassword::looksLikeBcrypt((string) $value)
        ));
        $this->assertNotEmpty($hash, 'the bcrypt hash is the only credential stored');
    }

    /**
     * `update_user` rewrites the account and revokes sessions when the
     * credential, privilege or status changed.
     */
    #[Test]
    public function update_user_rewrites_the_account_and_revokes_sessions(): void
    {
        $this->asAdmin();

        $response = $this->postJson('/update_user', [
            'current_username' => 'ali',
            'username' => 'ali',
            'password' => 'newpass123',
            'substitute' => 'sara',
            'work_hours' => '8-17',
            'department' => 'IT',
            'employment_status' => 'official',
            'is_active' => 'inactive',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('success'));
        // A password change and an inactive status both revoke.
        $this->assertNotEmpty($this->db->queriesContaining('user_sessions'));

        // The UPDATE set the username, the free-text fields, the status, the
        // active flag and the hash — and cleared the plaintext column.
        $updates = $this->db->queriesContaining('UPDATE user_table');
        $this->assertNotEmpty($updates);
        $this->assertStringContainsString("password = ''", $updates[0][0]);
        $this->assertStringContainsString('password_hash = ?', $updates[0][0]);
    }

    /**
     * `update_user` keeps the current credential when the password is empty.
     */
    #[Test]
    public function update_user_keeps_the_credential_when_the_password_is_empty(): void
    {
        $this->asAdmin();

        $response = $this->postJson('/update_user', [
            'current_username' => 'ali',
            'username' => 'ali',
            'substitute' => 'sara',
            'work_hours' => '8-17',
            'department' => 'IT',
        ]);

        $response->assertOk();
        $this->assertTrue($response->json('success'));

        $updates = $this->db->queriesContaining('UPDATE user_table');
        $this->assertNotEmpty($updates);
        $this->assertStringNotContainsString('password_hash', $updates[0][0]);
    }

    /**
     * `update_user` refuses a missing username and a bad one with its own bodies.
     *
     * `current_username` falls back to `username` when it is absent, so a body
     * that names only `username` is complete; the refusal needs a body that
     * names neither.
     */
    #[Test]
    public function update_user_refuses_a_missing_or_bad_username(): void
    {
        $this->asAdmin();

        $this->call('POST', '/update_user', [], [], [], $this->jsonHeaders(), '{}')
            ->assertStatus(200)
            ->assertExactJson(['success' => false, 'error' => 'نام کاربری الزامی است.']);

        $this->postJson('/update_user', ['current_username' => 'ali', 'username' => 'a'])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'error' => 'نام کاربری باید حداقل ۲ کاراکتر باشد.']);
    }

    /**
     * `fetch_user_data` returns the three name fields, and a missing user is
     * the FastAPI 404.
     */
    #[Test]
    public function fetch_user_data_returns_the_name_fields_and_404s_a_missing_user(): void
    {
        $this->asAdmin();

        $this->db->userTableRows = [(object) ['name' => 'محمد', 'last_name' => 'رضایی', 'department' => 'فناوری اطلاعات']];

        $this->getJson('/fetch_user_data?username=ali')
            ->assertOk()
            ->assertExactJson([
                'name' => 'محمد',
                'last_name' => 'رضایی',
                'department' => 'فناوری اطلاعات',
            ]);

        // A missing user.
        $this->db->userTableRows = [];
        $this->getJson('/fetch_user_data?username=ghost')
            ->assertStatus(404)
            ->assertExactJson(['error' => 'User not found']);
    }

    // ── Leave ────────────────────────────────────────────────────────────────

    /**
     * `update_leave_status` refuses a missing requestId or status with a 200.
     */
    #[Test]
    public function update_leave_status_refuses_incomplete_input_with_a_200(): void
    {
        $this->asAdmin();

        $this->postJson('/update_leave_status', ['requestId' => 1])
            ->assertStatus(200)
            ->assertExactJson(['success' => false, 'message' => 'اطلاعات ناقص ارسال شده است.']);

        $this->postJson('/update_leave_status', ['status' => 'تایید شده'])
            ->assertStatus(200)
            ->assertExactJson(['success' => false, 'message' => 'اطلاعات ناقص ارسال شده است.']);
    }

    /**
     * `update_leave_status` updates the row and notifies the requester.
     */
    #[Test]
    public function update_leave_status_updates_and_notifies(): void
    {
        $this->asAdmin();
        $this->db->updateCount = 1;
        $this->db->notifyUsername = 'ali';

        $response = $this->postJson('/update_leave_status', ['requestId' => 7, 'status' => 'تایید شده']);

        $response->assertOk();
        $response->assertExactJson(['success' => true, 'message' => 'وضعیت با موفقیت به‌روزرسانی شد!']);

        // The UPDATE ran, and the notification was published to the requester.
        $this->assertNotEmpty($this->db->queriesContaining('UPDATE mrkhc_table'));
        $this->assertNotEmpty($this->db->queriesContaining('notifications'));
    }

    /**
     * `generate_individual_report` converts the Jalali range and returns the
     * decided leave rows.
     */
    #[Test]
    public function generate_individual_report_returns_the_decided_rows(): void
    {
        $this->asAdmin();
        $this->db->mrkhcRows = [
            (object) ['start_date' => '2026-09-30', 'end_date' => '2026-10-01', 'days' => '2', 'id' => 5, 'substitute' => 'sara', 'status' => 'تایید شده', 'username' => 'ali'],
        ];

        $response = $this->postJson('/generate_individual_report', [
            'user' => 'ali',
            'fromDate' => '1405/07/08',
            'toDate' => '1405/07/10',
        ]);

        $response->assertOk();
        $reports = $response->json('reports');
        $this->assertCount(1, $reports);
        $this->assertSame('1405/07/08', $reports[0]['start_date']);
        $this->assertSame('1405/07/09', $reports[0]['end_date']);
        $this->assertSame('تایید شده', $reports[0]['status']);
    }

    /**
     * `generate_individual_report` covers everyone when `user` is empty, and
     * refuses a malformed date with a 500.
     */
    #[Test]
    public function generate_individual_report_covers_everyone_without_a_user(): void
    {
        $this->asAdmin();
        $this->db->mrkhcRows = [];

        $this->postJson('/generate_individual_report', [
            'fromDate' => '1405/07/08',
            'toDate' => '1405/07/10',
        ])->assertOk();

        // No username filter was applied.
        $selects = $this->db->queriesContaining('FROM mrkhc_table');
        $this->assertNotEmpty($selects);
        $this->assertStringNotContainsString('WHERE username = ?', $selects[0][0]);
    }

    /**
     * `generate_individual_report` refuses a non-padded date — `fromisoformat`
     * requires a zero-padded `YYYY-MM-DD`.
     */
    #[Test]
    public function generate_individual_report_refuses_a_non_padded_date(): void
    {
        $this->asAdmin();

        $this->postJson('/generate_individual_report', [
            'user' => 'ali',
            'fromDate' => '1405/7/8',
            'toDate' => '1405/07/10',
        ])
            ->assertStatus(500)
            ->assertExactJson(['success' => false, 'message' => 'خطا در دریافت اطلاعات']);
    }

    // ── Hourly pass ──────────────────────────────────────────────────────────

    /**
     * `get_hourly_pass_requests` returns a bare array with the placeholder for
     * a missing date and the pending label for a missing status.
     */
    #[Test]
    public function get_hourly_pass_requests_returns_a_bare_array(): void
    {
        $this->asAdmin();
        $this->db->totalpassRows = [
            (object) ['id' => 1, 'request_date' => '2026-09-30', 'pass_title' => 'کاری', 'pass_duration' => '01:30:00', 'username' => 'ali', 'status' => 'انتظار تایید'],
            (object) ['id' => 2, 'request_date' => null, 'pass_title' => 'شخصی', 'pass_duration' => null, 'username' => 'sara', 'status' => null],
        ];

        $response = $this->getJson('/get_hourly_pass_requests');

        $response->assertOk();
        $this->assertSame(array_values($response->json()), $response->json(), 'a bare JSON array');

        $rows = $response->json();
        $this->assertSame('1405/07/08', $rows[0]['request_date']);
        // `str(time)` — seconds included.
        $this->assertSame('01:30:00', $rows[0]['pass_duration']);

        // A missing date and a missing status.
        $this->assertSame('تاریخ ناموجود', $rows[1]['request_date']);
        $this->assertSame('انتظار تایید', $rows[1]['status']);
        // A NULL pass_duration serialises as the string "None".
        $this->assertSame('None', $rows[1]['pass_duration']);
    }

    /**
     * `change_hourly_pass_status` and `update_hourly_pass_status` update the
     * row, notify the requester, and answer `{"success": true}`.
     */
    #[Test]
    public function the_hourly_pass_status_writes_update_and_notify(): void
    {
        $this->asAdmin();
        $this->db->updateCount = 1;
        $this->db->notifyUsername = 'ali';

        $this->postJson('/change_hourly_pass_status', ['id' => 3, 'status' => 'تایید شده'])
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->postJson('/update_hourly_pass_status', ['id' => 4, 'status' => 'رد شده'])
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertNotEmpty($this->db->queriesContaining('UPDATE totalpass_table'));
        $this->assertNotEmpty($this->db->queriesContaining('notifications'));
    }

    /**
     * `get_hourly_pass_report` returns the decided passes in the range, with
     * the `%H:%M` duration.
     */
    #[Test]
    public function get_hourly_pass_report_returns_the_decided_passes(): void
    {
        $this->asAdmin();
        $this->db->totalpassRows = [
            (object) ['id' => 1, 'request_date' => '2026-09-30', 'pass_title' => 'کاری', 'pass_duration' => '01:30:00', 'status' => 'تایید شده', 'username' => 'ali'],
        ];

        $response = $this->postJson('/get_hourly_pass_report', [
            'username' => 'all_users',
            'start_date' => '1405/07/01',
            'end_date' => '1405/07/30',
        ]);

        $response->assertOk();
        $rows = $response->json();
        $this->assertCount(1, $rows);
        $this->assertSame('1405/07/08', $rows[0]['request_date']);
        // `%H:%M` — no seconds, unlike the requests list.
        $this->assertSame('01:30', $rows[0]['pass_duration']);
    }

    /**
     * `get_hourly_pass_report` refuses a malformed date with a 400 — and an
     * unparseable body is a `json.JSONDecodeError`, which is a `ValueError`
     * subclass, so it answers the same 400.
     */
    #[Test]
    public function get_hourly_pass_report_refuses_a_bad_date_with_a_400(): void
    {
        $this->asAdmin();

        $this->postJson('/get_hourly_pass_report', [
            'username' => 'ali',
            'start_date' => 'not-a-date',
            'end_date' => '1405/07/31',
        ])
            ->assertStatus(400)
            ->assertExactJson(['error' => 'تاریخ وارد شده صحیح نیست. لطفاً فرمت صحیح را وارد کنید.']);

        // An unparseable body — JSONDecodeError is a ValueError.
        $this->withHeaders($this->jsonHeaders())
            ->post('/get_hourly_pass_report', [], [], [], [])
            ->assertStatus(400);
    }

    // ── Overtime ─────────────────────────────────────────────────────────────

    /**
     * `get_overtime_requests` returns a bare array, with the placeholder for a
     * missing date and `00:00` for a missing duration.
     */
    #[Test]
    public function get_overtime_requests_returns_a_bare_array(): void
    {
        $this->asAdmin();
        $this->db->ezafeRows = [
            (object) ['id' => 1, 'overtime_date' => '2026-09-30', 'daily_overtime' => '01:30:00', 'description' => 'کار پروژه', 'username' => 'ali', 'status' => 'انتظار تایید'],
            (object) ['id' => 2, 'overtime_date' => null, 'daily_overtime' => null, 'description' => '', 'username' => 'sara', 'status' => null],
        ];

        $response = $this->getJson('/get_overtime_requests');

        $response->assertOk();
        $rows = $response->json();
        $this->assertSame('1405/07/08', $rows[0]['overtime_date']);
        $this->assertSame('01:30', $rows[0]['daily_overtime']);

        $this->assertSame('تاریخ ناموجود', $rows[1]['overtime_date']);
        $this->assertSame('00:00', $rows[1]['daily_overtime']);
        $this->assertSame('انتظار تایید', $rows[1]['status']);
    }

    /**
     * `update_overtime_status` validates the pydantic model before the guard.
     */
    #[Test]
    public function update_overtime_status_validates_the_model(): void
    {
        // A missing field — before the guard, so even an anonymous request is a 422.
        $this->postJson('/update_overtime_status', ['status' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['body', 'requestId']);

        // A non-integer requestId.
        $this->postJson('/update_overtime_status', ['requestId' => 'abc', 'status' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing');

        // A fractional requestId.
        $this->postJson('/update_overtime_status', ['requestId' => 1.5, 'status' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_from_float');

        // A non-string status.
        $this->postJson('/update_overtime_status', ['requestId' => 5, 'status' => 123])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_type');

        // A numeric string requestId is coerced.
        $this->asAdmin();
        $this->db->updateCount = 1;
        $this->db->notifyUsername = 'ali';
        $this->postJson('/update_overtime_status', ['requestId' => '5', 'status' => 'تایید شده'])
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'وضعیت با موفقیت تغییر کرد!']);
    }

    /**
     * `update_overtime_status` answers 404 when the row matches nothing.
     */
    #[Test]
    public function update_overtime_status_404s_a_missing_row(): void
    {
        $this->asAdmin();
        $this->db->updateCount = 0;

        $this->postJson('/update_overtime_status', ['requestId' => 999, 'status' => 'تایید شده'])
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'هیچ رکوردی برای بروزرسانی پیدا نشد']);
    }

    /**
     * `update_overtime_Indivisual_status` is the same shape with the `id` field
     * and its own messages.
     */
    #[Test]
    public function update_overtime_individual_status_uses_the_id_field(): void
    {
        $this->asAdmin();
        $this->db->updateCount = 1;
        $this->db->notifyUsername = 'ali';

        $this->postJson('/update_overtime_Indivisual_status', ['id' => 5, 'status' => 'تایید شده'])
            ->assertOk()
            ->assertExactJson(['success' => true]);

        // A missing row.
        $this->db->updateCount = 0;
        $this->postJson('/update_overtime_Indivisual_status', ['id' => 999, 'status' => 'تایید شده'])
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'رکوردی برای به‌روزرسانی پیدا نشد.']);
    }

    /**
     * `get_overtime_report` returns the rows with Persian-digit dates and times.
     */
    #[Test]
    public function get_overtime_report_returns_persian_digit_dates(): void
    {
        $this->asAdmin();
        $this->db->ezafeRows = [
            (object) ['id' => 1, 'overtime_date' => '2026-09-30', 'description' => 'کار پروژه', 'status' => 'تایید شده', 'username' => 'ali', 'daily_overtime' => '01:30:00', 'from_time' => '08:00:00', 'to_time' => '17:00:00'],
        ];

        $response = $this->postJson('/get_overtime_report', [
            'username' => 'all_users',
            'start_date' => '1405/07/01',
            'end_date' => '1405/07/30',
        ]);

        $response->assertOk();
        $rows = $response->json();
        $this->assertCount(1, $rows);
        // `convert_to_persian_numbers` — the dates and times carry Persian digits.
        $this->assertSame('۱۴۰۵/۰۷/۰۸', $rows[0]['overtime_date']);
        $this->assertSame('۰۱:۳۰', $rows[0]['daily_overtime']);
        $this->assertSame('۰۸:۰۰', $rows[0]['from_time']);
        $this->assertSame('۱۷:۰۰', $rows[0]['to_time']);
    }

    /**
     * `get_overtime_report` refuses a malformed date with a 400.
     */
    #[Test]
    public function get_overtime_report_refuses_a_bad_date_with_a_400(): void
    {
        $this->asAdmin();

        $this->postJson('/get_overtime_report', [
            'username' => 'ali',
            'start_date' => '1405/13/01',
            'end_date' => '1405/07/31',
        ])
            ->assertStatus(400)
            ->assertExactJson(['error' => 'تاریخ وارد شده صحیح نیست. لطفاً فرمت صحیح را وارد کنید.']);
    }

    /**
     * `GET /overtime_report` answers 500 — the template is missing from this
     * installation, so the Python's `TemplateResponse` raises and its `except`
     * retries the same missing template.
     */
    #[Test]
    public function the_overtime_report_page_500s_because_the_template_is_missing(): void
    {
        $this->asAdmin();
        $this->db->ezafeTotalRows = [(object) ['username' => 'ali', 'total_ezafe_time' => '05:00:00']];

        $this->getJson('/overtime_report')
            ->assertStatus(500);
    }

    // ── Report pages and the PDF ─────────────────────────────────────────────

    /**
     * The four report pages render their documents for a signed-in user.
     */
    #[Test]
    public function the_report_pages_render_their_documents(): void
    {
        $this->asUser();

        $this->get('/leave_report_page')->assertOk()->assertSee('گزارش مرخصی', false);
        $this->get('/hourlypass_Report_page')->assertOk()->assertSee('گزارش پاس ساعتی', false);
        $this->get('/overtime_report_page')->assertOk()->assertSee('گزارش اضافه کاری', false);
        $this->get('/payroll_report_page')->assertOk()->assertSee('گزارش حقوق و دستمزد', false);
    }

    /**
     * `final_report_page` renders the current Jalali month name and year in the
     * title.
     */
    #[Test]
    public function the_final_report_page_carries_the_current_jalali_month(): void
    {
        $this->asUser();

        $today = LegacyDate::today();
        $monthNames = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

        $response = $this->get('/final_report_page');

        $response->assertOk();
        $response->assertSee('گزارش '.$monthNames[$today->month].' ماه '.$today->year.' حضور و غیاب', false);
    }

    /**
     * `download_pdf` answers 503 — the `finalReportUser.html` template is
     * missing from this installation, so the Python refuses before reaching
     * pdfkit.
     */
    #[Test]
    public function download_pdf_answers_503_because_the_template_is_missing(): void
    {
        $this->asUser();

        $this->getJson('/download_pdf')
            ->assertStatus(503)
            ->assertExactJson(['success' => false, 'message' => 'قالب گزارش نهایی در این نصب موجود نیست.']);
    }

    // ── Live-database verification (opt-in) ─────────────────────────────────

    /**
     * The success shapes against the real `userDB`.
     *
     * Opt-in (`HASTAMA_DB_TESTS=1`) and wrapped in a transaction that is always
     * rolled back, exactly as `LegacyReadDatabaseTest` is.
     */
    #[Test]
    public function the_overtime_requests_match_the_live_table(): void
    {
        if (! filter_var((string) env('HASTAMA_DB_TESTS', '0'), FILTER_VALIDATE_BOOLEAN)) {
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
            $this->asAdmin('admin');

            $response = $this->getJson('/get_overtime_requests');

            $response->assertOk();
            $this->assertSame(
                (int) DB::table('ezafe_table')->count(),
                count($response->json())
            );
        } finally {
            DB::rollBack();
        }
    }
}

/**
 * A programmable stand-in for the legacy SQL Server connection.
 *
 * Every statement is recorded with its bindings, and the answer is derived from
 * the SQL's own shape — the same pattern `UserPanelWritesTest`'s
 * `FakeWriteConnection` established.  The row sets are public properties so each
 * test can arrange exactly the rows its query will see.
 */
class FakeAdminConnection extends Connection
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $queries = [];

    /** The session row behind the registry's `SELECT`. */
    public array $sessionRows = [];

    /** The rows behind `user_table` selects. */
    public array $userTableRows = [];

    /** The rows behind `admin_payroll_calculations` selects. */
    public array $payrollRows = [];

    /** The rows behind `mrkhc_table` selects. */
    public array $mrkhcRows = [];

    /** The rows behind `totalpass_table` selects. */
    public array $totalpassRows = [];

    /** The rows behind `ezafe_table` selects. */
    public array $ezafeRows = [];

    /** The rows behind `ezafe_total_table` selects. */
    public array $ezafeTotalRows = [];

    /** The username the notification's owner lookup returns. */
    public string $notifyUsername = 'requester';

    /** The row count behind `UPDATE` / `DELETE`. */
    public int $updateCount = 1;

    /** The id behind `insertGetId`. */
    public int $insertGetIdReturn = 100;

    /** The count behind the notification fan-out. */
    public int $affectingStatementCount = 1;

    /** Whether the payroll user-existence check finds the user. */
    public bool $userExists = true;

    /** Whether the add_user id check finds the id free. */
    public bool $idIsFree = true;

    /** The columns `getColumns('user_table')` reports. */
    public array $userTableColumns = [
        'id', 'username', 'password', 'name', 'last_name', 'department', 'work_hours',
        'substitute', 'role', 'hozoor_num', 'shanbeh', 'yekshanbeh', 'doshanbeh',
        'seshanbeh', 'chrshanbeh', 'panjshanbeh', 'is_active', 'employment_status',
        'password_hash', 'last_login', 'failed_login_count', 'password_changed_at',
    ];

    private ?SchemaBuilder $schemaBuilder = null;

    public function __construct()
    {
        parent::__construct(new \PDO('sqlite::memory:'), 'userDB', '', ['driver' => 'sqlite']);
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        $this->queries[] = [$query, $bindings];

        $result = $this->respond($query, $bindings);

        return is_array($result) ? $result : [];
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true): ?object
    {
        $this->queries[] = [$query, $bindings];

        $result = $this->respond($query, $bindings);

        if (is_object($result)) {
            return $result;
        }

        if (is_array($result)) {
            return $result[0] ?? null;
        }

        return null;
    }

    public function insert($query, $bindings = []): bool
    {
        $this->queries[] = [$query, $bindings];

        return $this->respond($query, $bindings) ?? true;
    }

    public function insertGetId($query, $bindings = [], $sequence = null): int
    {
        $this->queries[] = [$query, $bindings];

        return $this->insertGetIdReturn;
    }

    public function update($query, $bindings = []): int
    {
        $this->queries[] = [$query, $bindings];

        return $this->updateCount;
    }

    public function delete($query, $bindings = []): int
    {
        $this->queries[] = [$query, $bindings];

        return $this->updateCount;
    }

    public function affectingStatement($query, $bindings = []): int
    {
        $this->queries[] = [$query, $bindings];

        return $this->affectingStatementCount;
    }

    /**
     * A schema builder that reports the `user_table` columns, so the two
     * handlers that branch on the column list exercise the production path.
     */
    public function getSchemaBuilder(): SchemaBuilder
    {
        if ($this->schemaBuilder === null) {
            $connection = $this;
            $columns = $this->userTableColumns;

            $this->schemaBuilder = new class($connection, $columns) extends SchemaBuilder
            {
                public function __construct($connection, private array $columns)
                {
                    parent::__construct($connection);
                }

                public function getColumns($table): array
                {
                    if ($table === 'user_table' || $table === 'dbo.user_table') {
                        return array_map(
                            static fn (string $name): array => ['name' => $name, 'type_name' => 'nvarchar'],
                            $this->columns
                        );
                    }

                    return [];
                }
            };
        }

        return $this->schemaBuilder;
    }

    /**
     * Every recorded query whose SQL contains the given substring.
     *
     * @return array<int, array{0: string, 1: array<int, mixed>}>
     */
    public function queriesContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (array $query): bool => stripos($query[0], $needle) !== false
        ));
    }

    /**
     * The canned answer for one statement, matched on the SQL's own shape.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function respond(string $sql, array $bindings = []): mixed
    {
        $lower = strtolower($sql);

        // `user_sessions`: the registry's SELECT vs the revoke's UPDATE.
        if (str_contains($lower, 'user_sessions')) {
            return str_starts_with($lower, 'update') ? $this->updateCount : $this->sessionRows;
        }

        // add_user's id allocation check.
        if (str_contains($sql, 'SELECT 1 FROM user_table WHERE id =')) {
            return $this->idIsFree ? null : [(object) ['id' => 1]];
        }

        // payroll/save's user-existence check.
        if (str_contains($sql, 'SELECT 1 FROM user_table WHERE LTRIM(RTRIM(username))')) {
            return $this->userExists ? [(object) ['1' => 1]] : null;
        }

        // fetch_user_data.
        if (str_contains($sql, 'SELECT name, last_name, department FROM user_table')) {
            return $this->userTableRows;
        }

        // user_table INSERT / UPDATE.
        if (str_contains($sql, 'user_table')) {
            return str_starts_with($lower, 'insert') ? true : $this->updateCount;
        }

        // admin_payroll_calculations.
        if (str_contains($sql, 'admin_payroll_calculations')) {
            if (str_starts_with($lower, 'update')) {
                return $this->updateCount;
            }

            if (str_starts_with($lower, 'insert')) {
                return true;
            }

            return $this->payrollRows;
        }

        // The notification's owner lookup.
        if (str_contains($sql, 'SELECT TOP 1 LTRIM(RTRIM(username))')) {
            return $this->notifyUsername !== ''
                ? [(object) ['username' => $this->notifyUsername]]
                : [];
        }

        // mrkhc_table.
        if (str_contains($sql, 'mrkhc_table')) {
            return str_starts_with($lower, 'update') ? $this->updateCount : $this->mrkhcRows;
        }

        // totalpass_table.
        if (str_contains($sql, 'totalpass_table')) {
            return str_starts_with($lower, 'update') ? $this->updateCount : $this->totalpassRows;
        }

        // ezafe_table.
        if (str_contains($sql, 'ezafe_table')) {
            return str_starts_with($lower, 'update') ? $this->updateCount : $this->ezafeRows;
        }

        // ezafe_total_table.
        if (str_contains($sql, 'ezafe_total_table')) {
            return $this->ezafeTotalRows;
        }

        // The notification fan-out and its two inserts.
        if (str_contains($sql, 'user_notifications')) {
            return $this->affectingStatementCount;
        }

        if (str_contains($sql, 'notification_targets')) {
            return true;
        }

        if (str_contains($sql, 'notifications') && str_starts_with($lower, 'insert')) {
            return $this->insertGetIdReturn;
        }

        return null;
    }
}
