<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Legacy\LegacyDate;
use App\Support\Legacy\LegacyHozoorReport;
use App\Support\Legacy\LegacyProfileImage;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The user-panel write surface — guards, validation, success shapes and the
 * attendance rules, all offline.
 *
 * The default suite never touches a database.  The guard matrix and the 422
 * bodies are decided *before* the handler's first query, so they need nothing.
 * The success shapes run against {@see FakeWriteConnection}, a programmable
 * stand-in that records every statement and its bindings and answers from the
 * SQL's shape — the same "offline double" pattern `MasterAdminControlTest`
 * established.  The attendance rules are asserted directly against
 * {@see LegacyHozoorReport}, the pure port of the `get_hozoor` calculation.
 *
 * The live-database verification (the real `userDB`, the real Araz Access
 * database) is opt-in through `HASTAMA_DB_TESTS=1`, inside a transaction that
 * is always rolled back — see {@see LegacyReadDatabaseTest}.
 */
final class UserPanelWritesTest extends TestCase
{
    private FakeWriteConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new FakeWriteConnection;

        // The controllers write through the query builder (`->table(...)->update(...)`),
        // which compiles SQL through the connection's grammar.  A bare connection has
        // none, so every builder write would fail before reaching the fake.
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
     * A signed-in ordinary user — the guard identity and the legacy session flags.
     */
    private function asUser(string $username = 'test-user'): static
    {
        $this->actingAs(new User(['username' => $username]));
        $this->registryUser($username);

        return $this->withSession([
            'username' => $username,
            'sid' => 'test-token',
            'sid_iat' => time(),
        ]);
    }

    /**
     * A signed-in administrator.
     */
    private function asAdmin(string $username = 'test-admin'): static
    {
        $this->actingAs(new User(['username' => $username]));
        $this->registryUser($username);

        return $this->withSession([
            'username' => $username,
            'sid' => 'test-token',
            'sid_iat' => time(),
            'is_admin' => true,
        ]);
    }

    /** Switch the registry row to a different user (for the admin's own session). */
    private function registryUser(string $username): void
    {
        $this->db->sessionRows = [(object) ['username' => $username, 'is_active' => 1, 'last_activity' => null]];
    }

    /** Today's Gregorian date in the deployment's wall-clock timezone. */
    private function today(): string
    {
        return CarbonImmutable::now(LegacyDate::timezone())->format('Y-m-d');
    }

    /** Yesterday's Gregorian date in the deployment's wall-clock timezone. */
    private function yesterday(): string
    {
        return CarbonImmutable::now(LegacyDate::timezone())->subDay()->format('Y-m-d');
    }

    // ── Guard matrix ─────────────────────────────────────────────────────────

    /**
     * Every administrator route refuses an anonymous request with the middleware's
     * 401 — the session middleware passes an anonymous request through, and the
     * `admin` guard answers.
     */
    #[Test]
    public function every_admin_route_refuses_an_anonymous_request(): void
    {
        $routes = [
            ['GET', '/get_user_info_final_report_page/ali'],
            ['POST', '/get_hozoor_filtered'],
            ['GET', '/get_shifts/ali/1405/7'],
            ['POST', '/add_shift'],
            ['POST', '/update_shift'],
            ['POST', '/delete_shift/1'],
            ['GET', '/get_hozoor/ali'],
            ['POST', '/sabt_hozoor'],
        ];

        foreach ($routes as [$method, $uri]) {
            $response = $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json']);

            $response->assertStatus(401, "{$method} {$uri}");
            $this->assertSame(['success' => false, 'error' => 'لاگین نکرده‌اید.'], $response->json());
        }
    }

    /**
     * A signed-in non-administrator is refused by the `admin` middleware with its
     * own 403 body — the `error` key, not `message`.
     */
    #[Test]
    public function every_admin_route_refuses_a_non_administrator(): void
    {
        $this->asUser();

        $routes = [
            ['GET', '/get_user_info_final_report_page/ali'],
            ['POST', '/get_hozoor_filtered'],
            ['GET', '/get_shifts/ali/1405/7'],
            ['POST', '/add_shift'],
            ['POST', '/update_shift'],
            ['POST', '/delete_shift/1'],
            ['GET', '/get_hozoor/ali'],
            ['POST', '/sabt_hozoor'],
        ];

        foreach ($routes as [$method, $uri]) {
            $response = $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json']);

            $response->assertStatus(403, "{$method} {$uri}");
            $this->assertSame(['success' => false, 'error' => 'دسترسی مدیریتی ندارید.'], $response->json());
        }
    }

    /**
     * The authenticated writes refuse an anonymous request with the module's own
     * 200 `{"success": false, "message": "User not logged in"}` — not a 401.
     */
    #[Test]
    public function the_authenticated_writes_refuse_an_anonymous_request(): void
    {
        $this->post('/submit_leave')->assertStatus(200)
            ->assertJson(['success' => false, 'message' => 'User not logged in']);

        $this->post('/submit_overtime')->assertStatus(200)
            ->assertJson(['success' => false, 'message' => 'User not logged in']);

        $this->postJson('/submit_hourly_pass')->assertStatus(200)
            ->assertJson(['success' => false, 'message' => 'User not logged in']);
    }

    /**
     * The check-in / check-out / today endpoints refuse an anonymous request with
     * `_attendance_actor`'s 401 body.
     */
    #[Test]
    public function the_attendance_actor_endpoints_refuse_an_anonymous_request(): void
    {
        $this->postJson('/sabt_hozoor_checkin')->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'ورود به سامانه الزامی است.']);

        $this->postJson('/sabt_hozoor_checkout')->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'ورود به سامانه الزامی است.']);

        $this->get('/get_hozoor_today')->assertStatus(401)
            ->assertJson(['success' => false, 'message' => 'ورود به سامانه الزامی است.']);
    }

    /**
     * A non-administrator may not check another user in or out — the in-handler
     * ownership check answers 403.
     */
    #[Test]
    public function a_non_administrator_cannot_attend_another_user(): void
    {
        $this->asUser('test-user');

        $this->postJson('/sabt_hozoor_checkin', ['username' => 'someone-else'])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'دسترسی ثبت حضور کاربر دیگر مجاز نیست.']);

        $this->postJson('/sabt_hozoor_checkout', ['username' => 'someone-else'])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'message' => 'دسترسی ثبت خروج کاربر دیگر مجاز نیست.']);
    }

    /**
     * The employment-status handler's own guard: `is_admin is True` only, with a
     * 403 body of `دسترسی مجاز نیست.` — not the `admin` middleware's message.
     */
    #[Test]
    public function the_employment_status_guard_is_the_handlers_own(): void
    {
        // A non-admin session is refused by the handler, not the middleware.
        $this->asUser();

        $this->post('/api/admin/employment-status', ['username' => 'ali', 'employment_status' => 'official'])
            ->assertStatus(403)
            ->assertJson(['success' => false, 'error' => 'دسترسی مجاز نیست.']);
    }

    // ── 422 validation ───────────────────────────────────────────────────────

    /**
     * A missing form field is FastAPI's 422 `detail` list, not Laravel's 302
     * redirect — the status the front-end's `fetch()` calls branch on.
     */
    #[Test]
    public function submit_leave_rejects_a_missing_form_field(): void
    {
        $this->asUser();

        $this->post('/submit_leave', ['startDate' => '1405/07/01', 'endDate' => '1405/07/02', 'days' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['body', 'substitute'])
            ->assertJsonPath('detail.0.msg', 'Field required');
    }

    #[Test]
    public function submit_overtime_rejects_a_missing_form_field(): void
    {
        $this->asUser();

        $this->post('/submit_overtime', ['overtimeDate' => '1405/07/01', 'fromTime' => '08:00', 'toTime' => '17:00'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['body', 'description']);
    }

    /**
     * `get_hozoor` declares `start_date` and `end_date` as required query
     * parameters, so a missing one is a 422 with `loc: ["query", …]`.
     */
    #[Test]
    public function get_hozoor_rejects_a_missing_query_parameter(): void
    {
        $this->asAdmin();

        $this->get('/get_hozoor/ali?start_date=1405/07/01')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['query', 'end_date']);
    }

    /**
     * `get_shifts` declares `year` and `month` as integers, so a non-numeric
     * segment is a 422 with `loc: ["path", …]`.
     */
    #[Test]
    public function get_shifts_rejects_a_non_integer_path_parameter(): void
    {
        $this->asAdmin();

        $this->get('/get_shifts/ali/abc/7')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.loc', ['path', 'year']);
    }

    /**
     * `delete_shift` declares `shift_id` as an integer.
     */
    #[Test]
    public function delete_shift_rejects_a_non_integer_path_parameter(): void
    {
        $this->asAdmin();

        $this->post('/delete_shift/abc')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.loc', ['path', 'shift_id']);
    }

    /**
     * The upload endpoint requires a `file` field.
     */
    #[Test]
    public function the_upload_requires_a_file(): void
    {
        $this->asUser();

        $this->post('/upload-profile-image')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['body', 'file']);
    }

    // ── Success shapes ───────────────────────────────────────────────────────

    /**
     * `submit_leave` stores the Gregorian conversion of the Jalali dates and
     * answers the success message.
     */
    #[Test]
    public function submit_leave_stores_the_request(): void
    {
        $this->asUser('test-user');

        $this->db->adminUsernames = ['admin-one'];

        $this->post('/submit_leave', [
            'startDate' => '1405/07/01',
            'endDate' => '1405/07/02',
            'days' => '۱',
            'substitute' => 'جانشین',
        ])->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'مرخصی با موفقیت ثبت شد!']);

        $inserts = $this->db->queriesContaining('mrkhc_table');
        $this->assertNotEmpty($inserts);
        $this->assertSame('test-user', $inserts[0][1][4]);
        $this->assertSame(1, $inserts[0][1][2]);

        // The notification was published to the admins.
        $this->assertNotEmpty($this->db->queriesContaining('notifications'));
    }

    /**
     * `submit_overtime` stores the request with the pending status.
     */
    #[Test]
    public function submit_overtime_stores_the_request(): void
    {
        $this->asUser('test-user');

        $this->db->adminUsernames = ['admin-one'];

        $this->post('/submit_overtime', [
            'overtimeDate' => '1405/07/01',
            'fromTime' => '08:00',
            'toTime' => '17:00',
            'description' => 'شرح',
        ])->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'اضافه‌کار با موفقیت ثبت شد!']);

        $inserts = $this->db->queriesContaining('ezafe_table');
        $this->assertNotEmpty($inserts);
        $this->assertSame('انتظار تایید', $inserts[0][1][5]);
    }

    /**
     * `submit_hourly_pass` with all three times produces three pass rows and
     * three `totalpass_table` rows, and answers a bare `{"success": true}`.
     */
    #[Test]
    public function submit_hourly_pass_stores_three_passes(): void
    {
        $this->asUser('test-user');

        $this->db->adminUsernames = ['admin-one'];

        $this->postJson('/submit_hourly_pass', [
            'officialTime' => '08:00',
            'entryTime' => '08:30',
            'exitTime' => '17:00',
            'date' => '1405/07/01',
        ])->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertCount(1, $this->db->queriesContaining('avalpss_table'));
        $this->assertCount(1, $this->db->queriesContaining('beynpss_table'));
        $this->assertCount(1, $this->db->queriesContaining('akhrpss_table'));

        $total = $this->db->queriesContaining('totalpass_table');
        $this->assertCount(3, $total);
        $this->assertSame('avalpss', $total[0][1][2]);
        $this->assertSame('00:30:00', $total[0][1][3]);
        $this->assertSame('beynpss', $total[1][1][2]);
        $this->assertSame('akhrpss', $total[2][1][2]);
    }

    /**
     * An empty JSON object to `submit_hourly_pass` is a success with no inserts.
     */
    #[Test]
    public function submit_hourly_pass_with_an_empty_object_is_a_success(): void
    {
        $this->asUser('test-user');

        // An empty JSON *object* — the Python's `data.get(...)` on `{}` answers
        // success; an empty array would be a framework 500.  `postJson()` types
        // its data as `array`, so the raw body goes through `call()`.
        $this->call('POST', '/submit_hourly_pass', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertEmpty($this->db->queriesContaining('totalpass_table'));
    }

    /**
     * `get_hozoor` returns the merged report with the per-day status.
     */
    #[Test]
    public function get_hozoor_returns_the_merged_report(): void
    {
        $this->asAdmin();

        $this->db->userTableRows = [(object) [
            'hozoor_num' => '12345',
            'work_hours' => '08:00-17:00',
            'shanbeh' => '08:00-17:00',
            'yekshanbeh' => '08:00-17:00',
            'doshanbeh' => '08:00-17:00',
            'seshanbeh' => '08:00-17:00',
            'chrshanbeh' => '08:00-17:00',
            'panjshanbeh' => '08:00-17:00',
        ]];

        $this->db->shifthaRows = [];
        $this->db->hozoorRows = [];

        $response = $this->get('/get_hozoor/ali?start_date=1405/07/01&end_date=1405/07/01');

        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('1405-07-01', $data[0]['Date']);
        $this->assertSame('غیبت', $data[0]['Status']);
        $this->assertSame('08:00', $data[0]['WorkStart']);
        $this->assertSame('17:00', $data[0]['WorkEnd']);
    }

    /**
     * `get_hozoor_filtered` returns a bare JSON array.
     */
    #[Test]
    public function get_hozoor_filtered_returns_a_bare_array(): void
    {
        $this->asAdmin();

        $this->db->hozoorRows = [(object) [
            'date' => '2026-09-23',
            'vrood' => '08:30:00',
            'khoroj' => '17:00:00',
        ]];

        // The Python is `@app.post("/get_hozoor_filtered")` with a JSON body.
        $this->postJson('/get_hozoor_filtered', [
            'username' => 'ali',
            'from_date' => '1405/07/01',
            'to_date' => '1405/07/02',
        ])->assertStatus(200)
            ->assertExactJson([
                ['tarikh' => '1405/07/01', 'vorood' => '08:30', 'khorooj' => '17:00'],
            ]);
    }

    /**
     * `sabt_hozoor` inserts a new row and answers the insert message.
     */
    #[Test]
    public function sabt_hozoor_inserts_a_new_row(): void
    {
        $this->asAdmin();

        $this->db->hozoorRows = [];

        $response = $this->postJson('/sabt_hozoor', [
            'username' => 'ali',
            'tarikh' => '1405/07/01',
            'vorood' => '08:30',
            'khorooj' => '17:00',
        ])->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'اطلاعات با موفقیت ثبت شد']);

        $inserts = $this->db->queriesContaining('hozoor');
        $this->assertNotEmpty($inserts);
    }

    /**
     * `sabt_hozoor` updates an existing row and answers the update message.
     */
    #[Test]
    public function sabt_hozoor_updates_an_existing_row(): void
    {
        $this->asAdmin();

        $this->db->hozoorRows = [(object) ['date' => '2026-09-23', 'vrood' => '08:00:00', 'khoroj' => '17:00:00']];

        $this->postJson('/sabt_hozoor', [
            'username' => 'ali',
            'tarikh' => '1405/07/01',
            'vorood' => '08:30',
            'khorooj' => '17:30',
        ])->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'اطلاعات قبلی با موفقیت به‌روزرسانی شد']);
    }

    /**
     * `sabt_hozoor_checkin` stamps the server time and returns the status payload.
     */
    #[Test]
    public function sabt_hozoor_checkin_stamps_the_entry(): void
    {
        $this->asUser('test-user');
        $this->registryUser('test-user');

        $this->db->userTableRows = [(object) ['username' => 'test-user']];
        $this->db->hozoorRows = [];

        $response = $this->postJson('/sabt_hozoor_checkin', ['username' => 'test-user'])
            ->assertStatus(200);

        $this->assertTrue($response->json('success'));
        $this->assertSame('ورود با موفقیت ثبت شد.', $response->json('message'));
        $this->assertSame('checked_in', $response->json('data.status'));
        $this->assertNotNull($response->json('data.check_in'));
        $this->assertNull($response->json('data.check_out'));
    }

    /**
     * A second check-in for the same day is a 409.
     */
    #[Test]
    public function sabt_hozoor_checkin_refuses_a_second_entry(): void
    {
        $this->asUser('test-user');
        $this->registryUser('test-user');

        $this->db->userTableRows = [(object) ['username' => 'test-user']];
        $this->db->hozoorRows = [(object) ['date' => $this->today(), 'vrood' => '08:00:00', 'khoroj' => null]];

        $this->postJson('/sabt_hozoor_checkin', ['username' => 'test-user'])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => 'ورود امروز قبلاً ثبت شده است.']);
    }

    /**
     * An entry left open from a previous day is a 409 with the previous-day message.
     */
    #[Test]
    public function sabt_hozoor_checkin_refuses_when_a_previous_day_entry_is_open(): void
    {
        $this->asUser('test-user');
        $this->registryUser('test-user');

        $this->db->userTableRows = [(object) ['username' => 'test-user']];
        $this->db->hozoorRows = [(object) ['date' => $this->yesterday(), 'vrood' => '08:00:00', 'khoroj' => null]];

        $this->postJson('/sabt_hozoor_checkin', ['username' => 'test-user'])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => 'ورود فعالی از روز قبل بدون ثبت خروج مانده است؛ ابتدا خروج را ثبت کنید.']);
    }

    /**
     * `sabt_hozoor_checkout` stamps the exit against the open entry.
     */
    #[Test]
    public function sabt_hozoor_checkout_stamps_the_exit(): void
    {
        $this->asUser('test-user');
        $this->registryUser('test-user');

        $this->db->userTableRows = [(object) ['username' => 'test-user']];
        $this->db->hozoorRows = [(object) ['date' => '2026-09-23', 'vrood' => '08:00:00', 'khoroj' => null]];

        $response = $this->postJson('/sabt_hozoor_checkout', ['username' => 'test-user'])
            ->assertStatus(200);

        $this->assertTrue($response->json('success'));
        $this->assertSame('checked_out', $response->json('data.status'));
        $this->assertSame('08:00', $response->json('data.check_in'));
        $this->assertNotNull($response->json('data.check_out'));
    }

    /**
     * A check-out with no open entry is a 409.
     */
    #[Test]
    public function sabt_hozoor_checkout_refuses_without_an_open_entry(): void
    {
        $this->asUser('test-user');
        $this->registryUser('test-user');

        $this->db->userTableRows = [(object) ['username' => 'test-user']];
        $this->db->hozoorRows = [];

        $this->postJson('/sabt_hozoor_checkout', ['username' => 'test-user'])
            ->assertStatus(409)
            ->assertJson(['success' => false, 'message' => 'کاربر هیچ ورود فعالی ندارد.']);
    }

    /**
     * `get_hozoor_today` returns the caller's own status and schedule.
     */
    #[Test]
    public function get_hozoor_today_returns_the_caller_status(): void
    {
        $this->asUser('test-user');
        $this->registryUser('test-user');

        $this->db->userTableRows = [(object) [
            'work_hours' => '08:00-17:00',
            'shanbeh' => '08:00-17:00',
            'yekshanbeh' => '08:00-17:00',
            'doshanbeh' => '08:00-17:00',
            'seshanbeh' => '08:00-17:00',
            'chrshanbeh' => '08:00-17:00',
            'panjshanbeh' => '08:00-17:00',
        ]];
        $this->db->hozoorRows = [];

        $response = $this->get('/get_hozoor_today')->assertStatus(200);

        $this->assertTrue($response->json('success'));
        $this->assertSame('test-user', $response->json('data.users.0.username'));
        $this->assertSame('not_checked_in', $response->json('data.users.0.status'));
    }

    /**
     * `add_shift` stores the shift and answers the success message.
     */
    #[Test]
    public function add_shift_stores_the_shift(): void
    {
        $this->asAdmin();

        $this->db->shifthaRows = [];
        $this->db->overlapCount = 0;

        $this->postJson('/add_shift', [
            'username' => 'ali',
            'jalali_year' => 1405,
            'jalali_month' => 7,
            'start_day' => 1,
            'end_day' => 5,
            'shanbeh' => '08:00-17:00',
        ])->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'شیفت با موفقیت ثبت شد']);

        $this->assertNotEmpty($this->db->queriesContaining('shiftha'));
    }

    /**
     * `add_shift` refuses an overlapping range.
     */
    #[Test]
    public function add_shift_refuses_an_overlap(): void
    {
        $this->asAdmin();

        $this->db->shifthaRows = [(object) [
            'username' => 'ali',
            'jalali_year' => 1405,
            'jalali_month' => 7,
            'start_day' => 1,
            'end_day' => 10,
        ]];
        $this->db->overlapCount = 1;

        $this->postJson('/add_shift', [
            'username' => 'ali',
            'jalali_year' => 1405,
            'jalali_month' => 7,
            'start_day' => 5,
            'end_day' => 15,
        ])->assertStatus(200)
            ->assertJson(['success' => false, 'message' => 'این بازه با یک بازه‌ی تعریف‌شده‌ی دیگر برای همین ماه همپوشانی دارد']);
    }

    /**
     * `update_shift` refuses an unknown id.
     */
    #[Test]
    public function update_shift_refuses_an_unknown_id(): void
    {
        $this->asAdmin();

        $this->db->shifthaRows = [];

        $this->postJson('/update_shift', [
            'id' => 999,
            'start_day' => 1,
            'end_day' => 5,
        ])->assertStatus(200)
            ->assertJson(['success' => false, 'message' => 'شیفت مورد نظر پیدا نشد']);
    }

    /**
     * `delete_shift` deletes and answers the success message.
     */
    #[Test]
    public function delete_shift_deletes_the_row(): void
    {
        $this->asAdmin();

        $this->db->deleteCount = 1;

        $this->post('/delete_shift/1')
            ->assertStatus(200)
            ->assertJson(['success' => true, 'message' => 'شیفت حذف شد']);

        // The SQL Server grammar brackets identifiers: `delete from [shiftha]`.
        $this->assertNotEmpty($this->db->queriesContaining('shiftha'));
    }

    /**
     * `get_shifts` returns the user's shifts for the month.
     */
    #[Test]
    public function get_shifts_returns_the_months_shifts(): void
    {
        $this->asAdmin();

        $this->db->shifthaRows = [(object) [
            'id' => 1,
            'start_day' => 1,
            'end_day' => 5,
            'shanbeh' => '08:00-17:00',
            'yekshanbeh' => null,
            'doshanbeh' => null,
            'seshanbeh' => null,
            'chaharshanbeh' => null,
            'panjshanbeh' => null,
            'jomeh' => null,
            'title' => 'شیفت صبح',
        ]];

        $response = $this->get('/get_shifts/ali/1405/7')->assertStatus(200);

        $this->assertTrue($response->json('success'));
        $shifts = $response->json('shifts');
        $this->assertCount(1, $shifts);
        $this->assertSame(1, $shifts[0]['id']);
        $this->assertSame('08:00-17:00', $shifts[0]['shanbeh']);
        $this->assertSame('', $shifts[0]['yekshanbeh']);
    }

    /**
     * `get_user_info_final_report_page` returns the three name columns.
     */
    #[Test]
    public function the_final_report_page_returns_the_name_block(): void
    {
        $this->asAdmin();

        $this->db->userTableRows = [(object) ['name' => 'علی', 'last_name' => 'رضایی', 'department' => 'فناوری']];

        $this->get('/get_user_info_final_report_page/ali')
            ->assertStatus(200)
            ->assertExactJson(['name' => 'علی', 'last_name' => 'رضایی', 'department' => 'فناوری']);
    }

    /**
     * A missing user is a **500**, not a 404 — the Python's `raise HTTPException(404)`
     * is swallowed by its own `except Exception` and re-raised as 500.
     */
    #[Test]
    public function the_final_report_page_answers_500_for_a_missing_user(): void
    {
        $this->asAdmin();

        $this->db->userTableRows = [];

        $this->get('/get_user_info_final_report_page/ghost')
            ->assertStatus(500)
            ->assertJson(['detail' => 'خطای سرور']);
    }

    /**
     * `employment-status` updates the column and answers the new value.
     */
    #[Test]
    public function employment_status_updates_the_column(): void
    {
        $this->asAdmin();

        $this->db->updateCount = 1;

        $this->postJson('/api/admin/employment-status', ['username' => 'ali', 'employment_status' => 'unofficial'])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'employment_status' => 'unofficial']);

        $updates = $this->db->queriesContaining('user_table');
        $this->assertNotEmpty($updates);
        $this->assertSame('unofficial', $updates[0][1][0]);
    }

    /**
     * An invalid employment status is a 400.
     */
    #[Test]
    public function employment_status_rejects_an_invalid_value(): void
    {
        $this->asAdmin();

        $this->postJson('/api/admin/employment-status', ['username' => 'ali', 'employment_status' => 'bogus'])
            ->assertStatus(400)
            ->assertJson(['success' => false, 'error' => 'وضعیت استخدام معتبر نیست.']);
    }

    /**
     * A user that does not exist is a 404.
     */
    #[Test]
    public function employment_status_answers_404_for_a_missing_user(): void
    {
        $this->asAdmin();

        $this->db->updateCount = 0;

        $this->postJson('/api/admin/employment-status', ['username' => 'ghost', 'employment_status' => 'official'])
            ->assertStatus(404)
            ->assertJson(['success' => false, 'error' => 'کاربر پیدا نشد.']);
    }

    // ── Attendance rules (the pure port) ─────────────────────────────────────

    /**
     * An on-time entry and exit produce the two confirmation parts and the full
     * scheduled duration as worked hours.
     */
    #[Test]
    public function an_on_time_day_is_confirmed_at_both_ends(): void
    {
        $result = LegacyHozoorReport::finalizeDay(
            ['EntryTime' => '0800', 'ExitTime' => '1700'],
            2,
            '08:00',
            '17:00'
        );

        $this->assertSame('تایید سامانه در ورود, تایید سامانه در خروج', $result['Status']);
        $this->assertSame('', $result['CalculatedTime']);
        $this->assertSame('09:00', $result['WorkedHours']);
    }

    /**
     * A late arrival adds the delay duration; the exit on time confirms.
     */
    #[Test]
    public function a_late_arrival_reports_the_delay(): void
    {
        $result = LegacyHozoorReport::finalizeDay(
            ['EntryTime' => '0830', 'ExitTime' => '1700'],
            2,
            '08:00',
            '17:00'
        );

        $this->assertStringContainsString('ورود با تاخیر', $result['Status']);
        $this->assertStringContainsString('مدت زمان تاخیر: 00:30', $result['CalculatedTime']);
        $this->assertStringContainsString('تایید سامانه در خروج', $result['Status']);
    }

    /**
     * An early departure reports the early-exit duration.
     */
    #[Test]
    public function an_early_departure_reports_the_early_exit(): void
    {
        $result = LegacyHozoorReport::finalizeDay(
            ['EntryTime' => '0800', 'ExitTime' => '1600'],
            2,
            '08:00',
            '17:00'
        );

        $this->assertStringContainsString('تایید سامانه در ورود', $result['Status']);
        $this->assertStringContainsString('خروج زودهنگام', $result['Status']);
        $this->assertStringContainsString('مدت زمان خروج زودهنگام: 01:00', $result['CalculatedTime']);
    }

    /**
     * Overtime is only reported when it exceeds ten minutes, and only the first
     * overtime is added to the worked total.
     */
    #[Test]
    public function overtime_over_ten_minutes_is_reported_and_added(): void
    {
        $result = LegacyHozoorReport::finalizeDay(
            ['EntryTime' => '0800', 'ExitTime' => '1730'],
            2,
            '08:00',
            '17:00'
        );

        $this->assertStringContainsString('اضافه کاری', $result['Status']);
        $this->assertStringContainsString('مدت زمان اضافه کاری: 00:30', $result['CalculatedTime']);
        // The Python's exit-overtime branch reports the overtime but does not add
        // it to the worked total — the worked hours stay at the scheduled duration.
        $this->assertSame('09:00', $result['WorkedHours']);
    }

    /**
     * Ten minutes of overtime exactly is **not** reported — the bound is strict.
     */
    #[Test]
    public function ten_minutes_of_overtime_is_not_reported(): void
    {
        $result = LegacyHozoorReport::finalizeDay(
            ['EntryTime' => '0800', 'ExitTime' => '1710'],
            2,
            '08:00',
            '17:00'
        );

        $this->assertStringNotContainsString('اضافه کاری', $result['Status']);
        $this->assertSame('09:00', $result['WorkedHours']);
    }

    /**
     * An early start (before the work start) reports the early-start duration and
     * the full scheduled duration.
     */
    #[Test]
    public function an_early_start_reports_the_early_start(): void
    {
        $result = LegacyHozoorReport::finalizeDay(
            ['EntryTime' => '0730', 'ExitTime' => '1700'],
            2,
            '08:00',
            '17:00'
        );

        $this->assertStringContainsString('شروع زودهنگام', $result['Status']);
        $this->assertStringContainsString('مدت زمان شروع زودهنگام: 00:30', $result['CalculatedTime']);
        $this->assertSame('09:00', $result['WorkedHours']);
    }

    /**
     * A day with no punches is **تعطیل** on a Friday (Jalali weekday 6).
     */
    #[Test]
    public function a_day_without_punches_is_closed_on_a_friday(): void
    {
        $result = LegacyHozoorReport::finalizeDay(
            ['EntryTime' => '0000', 'ExitTime' => '0000'],
            6,
            '08:00',
            '17:00'
        );

        $this->assertSame('تعطیل', $result['Status']);
        $this->assertSame('', $result['CalculatedTime']);
        $this->assertSame('00:00', $result['WorkedHours']);
    }

    /**
     * A day with no punches is **غیبت** on any other day.
     */
    #[Test]
    public function a_day_without_punches_is_absent_on_a_weekday(): void
    {
        $result = LegacyHozoorReport::finalizeDay(
            ['EntryTime' => '0000', 'ExitTime' => '0000'],
            2,
            '08:00',
            '17:00'
        );

        $this->assertSame('غیبت', $result['Status']);
    }

    /**
     * A `shiftha` range covering the day beats the weekly default.
     */
    #[Test]
    public function a_covering_shift_range_beats_the_weekly_default(): void
    {
        $shiftRows = [[
            'jalali_year' => '1405',
            'jalali_month' => '7',
            'start_day' => '1',
            'end_day' => '10',
            'shanbeh' => '09:00-18:00',
            'yekshanbeh' => null,
            'doshanbeh' => null,
            'seshanbeh' => null,
            'chaharshanbeh' => null,
            'panjshanbeh' => null,
            'jomeh' => null,
        ]];

        $hours = LegacyHozoorReport::resolveWorkHours(
            $shiftRows,
            [0 => '08:00-17:00', 1 => '08:00-17:00', 2 => '08:00-17:00', 3 => '08:00-17:00', 4 => '08:00-17:00', 5 => '08:00-17:00'],
            '08:00-17:00',
            1405,
            7,
            5,
            0
        );

        $this->assertSame('09:00-18:00', $hours);
    }

    /**
     * The first covering range wins even when its own value for that weekday is
     * empty — it `break`s, it does not continue to the next range.
     */
    #[Test]
    public function the_first_covering_range_wins_even_when_its_value_is_empty(): void
    {
        $shiftRows = [
            [
                'jalali_year' => '1405',
                'jalali_month' => '7',
                'start_day' => '1',
                'end_day' => '10',
                'shanbeh' => null,
                'yekshanbeh' => null,
                'doshanbeh' => null,
                'seshanbeh' => null,
                'chaharshanbeh' => null,
                'panjshanbeh' => null,
                'jomeh' => null,
            ],
            [
                'jalali_year' => '1405',
                'jalali_month' => '7',
                'start_day' => '1',
                'end_day' => '10',
                'shanbeh' => '10:00-19:00',
                'yekshanbeh' => null,
                'doshanbeh' => null,
                'seshanbeh' => null,
                'chaharshanbeh' => null,
                'panjshanbeh' => null,
                'jomeh' => null,
            ],
        ];

        $hours = LegacyHozoorReport::resolveWorkHours(
            $shiftRows,
            [0 => '08:00-17:00', 1 => '08:00-17:00', 2 => '08:00-17:00', 3 => '08:00-17:00', 4 => '08:00-17:00', 5 => '08:00-17:00'],
            '08:00-17:00',
            1405,
            7,
            5,
            0
        );

        // The first range's empty value means the weekly default is used, not the
        // second range's value.
        $this->assertSame('08:00-17:00', $hours);
    }

    /**
     * A weekly default without a `-` falls back to the zero range.
     */
    #[Test]
    public function a_weekly_default_without_a_range_falls_back_to_zero(): void
    {
        $hours = LegacyHozoorReport::resolveWorkHours(
            [],
            [0 => '08:00-17:00', 1 => null, 2 => null, 3 => null, 4 => null, 5 => null],
            null,
            1405,
            7,
            5,
            1
        );

        $this->assertSame('00:00-00:00', $hours);
    }

    /**
     * The work window is sorted, so a reversed stored range is reported ascending.
     */
    #[Test]
    public function a_reversed_work_window_is_sorted(): void
    {
        $this->assertSame(['08:00', '17:00'], LegacyHozoorReport::workStartEnd('17:00-08:00'));
        $this->assertSame(['00:00', '00:00'], LegacyHozoorReport::workStartEnd('not-a-range'));
    }

    /**
     * `normalizeTimeValue` keeps the four-digit shape and maps the empty words.
     */
    #[Test]
    public function normalize_time_value_matches_the_python(): void
    {
        $this->assertSame('0830', LegacyHozoorReport::normalizeTimeValue('08:30'));
        $this->assertSame('0830', LegacyHozoorReport::normalizeTimeValue('08:30:00'));
        $this->assertSame('0000', LegacyHozoorReport::normalizeTimeValue(null));
        $this->assertSame('0000', LegacyHozoorReport::normalizeTimeValue('none'));
        $this->assertSame('0000', LegacyHozoorReport::normalizeTimeValue(''));
    }

    // ── Profile image validation ─────────────────────────────────────────────

    /**
     * The extension allow-list is matched case-insensitively.
     */
    #[Test]
    public function the_extension_allow_list_is_case_insensitive(): void
    {
        $this->assertSame('.jpg', LegacyProfileImage::extensionOf('photo.JPG'));
        $this->assertSame('.jpg', LegacyProfileImage::extensionOf('photo.jpg'));
        $this->assertSame('.png', LegacyProfileImage::extensionOf('photo.png'));
        $this->assertSame('', LegacyProfileImage::extensionOf('noextension'));
    }

    /**
     * The magic-byte detector maps each signature, and `.jpeg` is **not** matched —
     * the JPEG signature maps to `.jpg` unconditionally, so a valid `.jpeg` is
     * refused.  This is the Python's bug, reproduced.
     */
    #[Test]
    public function the_magic_byte_detector_reproduces_the_jpeg_bug(): void
    {
        $jpeg = "\xFF\xD8\xFF\xE0".str_repeat('x', 20);
        $png = "\x89PNG\r\n\x1a\n".str_repeat('x', 20);
        $gif = 'GIF89a'.str_repeat('x', 20);
        $webp = 'RIFF'.str_repeat('x', 4).'WEBP'.str_repeat('x', 20);

        $this->assertSame('.jpg', LegacyProfileImage::detectExtension($jpeg));
        $this->assertSame('.png', LegacyProfileImage::detectExtension($png));
        $this->assertSame('.gif', LegacyProfileImage::detectExtension($gif));
        $this->assertSame('.webp', LegacyProfileImage::detectExtension($webp));
        $this->assertNull(LegacyProfileImage::detectExtension('not an image'));

        // A `.jpeg` file: the extension is allowed, but the detector returns
        // `.jpg`, so the comparison `detected != file_ext` refuses it.
        $this->assertTrue(LegacyProfileImage::isAllowedExtension('.jpeg'));
        $this->assertNotSame('.jpeg', LegacyProfileImage::detectExtension($jpeg));
    }

    /**
     * The username is reduced to a conservative charset for the filename.
     */
    #[Test]
    public function the_username_is_sanitised_for_the_filename(): void
    {
        $this->assertSame('ali', LegacyProfileImage::sanitizeUsername('ali'));
        $this->assertSame('al_i', LegacyProfileImage::sanitizeUsername('al/i'));
        $this->assertSame('user', LegacyProfileImage::sanitizeUsername(''));
        $this->assertSame('a_1', LegacyProfileImage::sanitizeUsername('a 1'));

        $filename = LegacyProfileImage::buildFilename('ali', '.jpg');
        $this->assertSame('ali.jpg', $filename);
    }

    /**
     * A traversal filename is caught by the directory check.
     *
     * The check models the delete handler's `realpath(file_path).startswith(
     * real_uploads + os.sep)`: a path that escapes the directory (`../`) does not
     * match, while a path inside a subdirectory still does — the Python's
     * `startswith` is a prefix test, not an exact-directory test.
     */
    #[Test]
    public function a_traversal_filename_is_caught_by_the_directory_check(): void
    {
        $uploadDir = LegacyProfileImage::uploadDir();

        $this->assertTrue(LegacyProfileImage::isWithinUploadDir($uploadDir.'/ali.jpg', $uploadDir));
        $this->assertFalse(LegacyProfileImage::isWithinUploadDir($uploadDir.'/../secret.jpg', $uploadDir));
        $this->assertTrue(LegacyProfileImage::isWithinUploadDir($uploadDir.'/sub/ali.jpg', $uploadDir));
    }

    /**
     * The upload refuses a disallowed extension with a redirect to `/user_panel`.
     */
    #[Test]
    public function the_upload_refuses_a_disallowed_extension(): void
    {
        $this->asUser('test-user');

        $file = UploadedFile::fake()->create('photo.gif', 100);
        // A `.gif` is allowed; use a `.txt` to test the refusal.
        $file = UploadedFile::fake()->create('notes.txt', 100);

        $this->post('/upload-profile-image', ['file' => $file])
            ->assertStatus(303)
            ->assertRedirect('/user_panel');
    }

    /**
     * The upload refuses a magic-byte mismatch.
     */
    #[Test]
    public function the_upload_refuses_a_magic_byte_mismatch(): void
    {
        $this->asUser('test-user');

        // A `.png` extension with JPEG contents.
        $file = UploadedFile::fake()->create('photo.png', 100, 'image/png');
        $file = UploadedFile::fake()->createWithContent('photo.png', "\xFF\xD8\xFF\xE0".str_repeat('x', 100));

        $this->post('/upload-profile-image', ['file' => $file])
            ->assertStatus(303)
            ->assertRedirect('/user_panel');
    }

    /**
     * The upload refuses an oversized file.
     */
    #[Test]
    public function the_upload_refuses_an_oversized_file(): void
    {
        $this->asUser('test-user');

        $file = UploadedFile::fake()->create('photo.jpg', LegacyProfileImage::MAX_FILE_SIZE + 1);

        $this->post('/upload-profile-image', ['file' => $file])
            ->assertStatus(303)
            ->assertRedirect('/user_panel');
    }

    /**
     * An unauthenticated upload is redirected to `/login`.
     */
    #[Test]
    public function the_upload_refuses_an_anonymous_request(): void
    {
        $file = UploadedFile::fake()->create('photo.jpg', 100);

        $this->post('/upload-profile-image', ['file' => $file])
            ->assertStatus(303)
            ->assertRedirect('/login');
    }

    /**
     * The delete endpoint removes the stored image and redirects to `/user_panel`.
     */
    #[Test]
    public function the_delete_removes_the_stored_image(): void
    {
        $this->asUser('test-user');
        $this->registryUser('test-user');

        $this->db->userTableRows = [(object) ['profile_image' => 'test-user.jpg']];
        $this->db->updateCount = 1;

        $this->post('/delete-profile-image')
            ->assertStatus(303)
            ->assertRedirect('/user_panel');

        // The SQL Server grammar brackets identifiers: `update [user_table] set …`.
        $this->assertNotEmpty($this->db->queriesContaining('user_table'));
    }

    /**
     * The delete endpoint refuses an anonymous request with a redirect to `/login`.
     */
    #[Test]
    public function the_delete_refuses_an_anonymous_request(): void
    {
        $this->post('/delete-profile-image')
            ->assertStatus(303)
            ->assertRedirect('/login');
    }
}

/**
 * A programmable stand-in for the SQL Server connection.
 *
 * Every statement the application issues is recorded with its bindings, and the
 * answer is derived from the SQL rather than from a queue — so a test states
 * what the database said, not which order the queries happened to run in.
 */
class FakeWriteConnection extends Connection
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $queries = [];

    public function __construct()
    {
        parent::__construct(new \PDO('sqlite::memory:'), 'userDB', '', ['driver' => 'sqlite']);
    }

    /** The session row behind the registry's `SELECT`. */
    public array $sessionRows = [];

    /** The rows behind `user_table` selects. */
    public array $userTableRows = [];

    /** The rows behind `shiftha` selects. */
    public array $shifthaRows = [];

    /** The rows behind `hozoor` selects. */
    public array $hozoorRows = [];

    /** The usernames the notification's admin query returns. */
    public array $adminUsernames = [];

    /** The row count behind `UPDATE` / `DELETE`. */
    public int $updateCount = 1;

    public int $deleteCount = 1;

    /** The count behind a `COUNT(*)` on `shiftha` (the overlap check). */
    public int $overlapCount = 0;

    /** The id behind `insertGetId`. */
    public int $insertGetIdReturn = 100;

    /** The rows behind the notification fan-out. */
    public int $affectingStatementCount = 1;

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

        return true;
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

        return $this->deleteCount;
    }

    public function affectingStatement($query, $bindings = []): int
    {
        $this->queries[] = [$query, $bindings];

        return $this->affectingStatementCount;
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
        // `compileExists` produces `select top 1 1 [exists] from …` on SQL Server
        // (and `select exists(select 1 from …) as "exists"` elsewhere), and
        // `Builder::exists()` reads the `exists` key of the first row.  Answering
        // it with the table's rows makes that read an undefined-key error, so the
        // existence answer is derived from the rows the statement's own table has.
        if (preg_match('/\[exists\]|as\s+"?exists"?\b/i', $sql) === 1) {
            return [(object) ['exists' => $this->existsAnswer($sql)]];
        }

        return match (true) {
            str_contains($sql, 'user_sessions') => $this->sessionRows,
            str_contains($sql, 'notifications') && str_contains($sql, 'INSERT') => $this->insertGetIdReturn,
            str_contains($sql, 'notification_targets') => true,
            str_contains($sql, 'user_notifications') => $this->affectingStatementCount,
            str_contains($sql, "role, ''") => array_map(
                static fn ($name): object => (object) ['username' => $name, 'role' => 'admin'],
                $this->adminUsernames
            ),
            // `hozoor` is matched as a whole word: the `user_table` reads select
            // the `hozoor_num` *column*, and a plain substring test would claim
            // those rows for the `hozoor` table.
            stripos($sql, 'count(*)') !== false && str_contains($sql, 'shiftha') => [(object) ['aggregate' => $this->overlapCount]],
            str_contains($sql, 'shiftha') => $this->shifthaRows,
            preg_match('/\bhozoor\b/', $sql) === 1 => $this->hozoorRows,
            str_contains($sql, 'mrkhc_table') => true,
            str_contains($sql, 'ezafe_table') => true,
            str_contains($sql, 'avalpss_table') => true,
            str_contains($sql, 'beynpss_table') => true,
            str_contains($sql, 'akhrpss_table') => true,
            str_contains($sql, 'totalpass_table') => true,
            str_contains($sql, 'user_table') => $this->userTableRows,
            default => null,
        };
    }

    /**
     * Whether the table an `exists (select 1 from <table> …)` statement queried has
     * any rows — the answer `Builder::exists()` reads from the `exists` key.
     */
    private function existsAnswer(string $sql): bool
    {
        return match (true) {
            str_contains($sql, 'user_sessions') => $this->sessionRows !== [],
            str_contains($sql, 'shiftha') => $this->shifthaRows !== [],
            preg_match('/\bhozoor\b/', $sql) === 1 => $this->hozoorRows !== [],
            str_contains($sql, 'user_table') => $this->userTableRows !== [],
            default => false,
        };
    }
}
