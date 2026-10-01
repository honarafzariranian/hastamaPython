<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use App\Support\Legacy\LegacyDate;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Phase 5 read endpoints against the **real** `userDB`.
 *
 * This is the acceptance test for the read surface, and it is opt-in
 * (`HASTAMA_DB_TESTS=1`) for the same reason `AuthDatabaseTest` is: an ordinary
 * `php artisan test` run must stay offline, and the live database is not a fixture.
 * Every test runs inside a transaction that is always rolled back, so the rows and the
 * session registry are left exactly as they were found.
 *
 * What it is really checking is **shape parity with the running FastAPI server**.  The
 * expected values were not invented — they were captured by calling the Python
 * `_serialize()` and the same SQL against the live database with `pyodbc`, which is
 * what produced the running endpoints' bodies.  The cases that would pass with a
 * plausible-but-wrong implementation and are therefore worth the database round trip:
 *
 * * `id` must be an **integer** `319`, not the string `"319"` that `pdo_sqlsrv` hands
 *   back;
 * * `is_active` must be **`false`**, not `"0"`;
 * * `role` must still be **`'admin     '`**, padding and all;
 * * `created_at` must be `2026-09-30T08:53:41.864000` — `T` separator, six digits —
 *   not `2026-09-30 08:53:41.864`;
 * * `GET /master-admin/api/audit-logs/{event_id}` must answer **200**, where the
 *   Python implementation raises `IndexError` and answers 500.
 *
 * Run it deliberately:
 *     HASTAMA_DB_TESTS=1 php artisan test --filter=LegacyReadDatabaseTest
 */
final class LegacyReadDatabaseTest extends TestCase
{
    /** A `datetime2` serialised by Python's `isoformat()`: `T`, then six digits or none. */
    private const ISOFORMAT = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{6})?$/';

    private string $token = '';

    /** The trimmed username of whoever `asMaster()`/`asUser()` signed in. */
    private string $username = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! filter_var((string) env('HASTAMA_DB_TESTS', '0'), FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Live-database verification is opt-in: set HASTAMA_DB_TESTS=1 to run it.');
        }

        // `phpunit.xml` points the default connection at an in-memory database.  The
        // real one has to be named explicitly, exactly as `AuthDatabaseTest` does.
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

        // The registry lookup is cached; a stale `false` from another test would turn
        // every request into a 401 and the failure would look like a routing bug.
        $this->app['cache']->flush();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    // ── Control plane: dashboard ─────────────────────────────────────────────

    /**
     * The eleven counters, in the order the Python built them.
     *
     * The order is asserted because the admin UI renders the stat cards from the
     * object's insertion order in the same way it renders table columns.
     */
    #[Test]
    public function the_dashboard_stats_are_eleven_integer_counters(): void
    {
        $response = $this->asMaster()->get('/master-admin/api/dashboard/stats');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $stats = $response->json('data');

        $this->assertSame([
            'total_users',
            'active_users',
            'online_sessions',
            'logins_today',
            'failed_logins_today',
            'pending_password_resets',
            'open_security_events',
            'open_errors',
            'open_tickets',
            'admin_count',
            'events_today',
        ], array_keys($stats));

        foreach ($stats as $key => $value) {
            $this->assertIsInt($value, "{$key} must serialise as an integer, not the driver's string");
            $this->assertGreaterThanOrEqual(0, $value, "{$key} must not be negative");
        }

        // Tied to the live row counts, so a wrong table or a missing WHERE shows up.
        $this->assertSame((int) DB::table('user_table')->count(), $stats['total_users']);
        $this->assertSame(
            (int) DB::table('user_table')->whereRaw("ISNULL(is_active,'active') = 'active'")->count(),
            $stats['active_users'],
        );
        $this->assertSame(
            (int) DB::table('user_table')->whereRaw("LTRIM(RTRIM(LOWER(role))) = 'admin'")->count(),
            $stats['admin_count'],
        );
        $this->assertSame((int) DB::table('user_sessions')->where('is_active', 1)->count(), $stats['online_sessions']);
    }

    /**
     * The activity feed, with the timestamp shape the Python `isoformat()` produced.
     */
    #[Test]
    public function the_activity_feed_serialises_timestamps_the_way_isoformat_did(): void
    {
        $response = $this->asMaster()->get('/master-admin/api/dashboard/activity?limit=5');

        $response->assertOk()->assertJson(['success' => true]);

        $rows = $response->json('data');

        $this->assertNotEmpty($rows, 'the live audit log is expected to have entries');
        $this->assertLessThanOrEqual(5, count($rows));

        foreach ($rows as $row) {
            $this->assertMatchesRegularExpression(self::ISOFORMAT, $row['created_at']);
            $this->assertIsString($row['event_id']);
            // The exact columns the Python selected, no more and no fewer.
            $this->assertSame([
                'event_id', 'event_type', 'action', 'username', 'module',
                'resource_type', 'resource_id', 'status', 'severity', 'created_at', 'ip_address',
            ], array_keys($row));
        }

        // Descending by `created_at`, which is what the dashboard renders.
        $timestamps = array_column($rows, 'created_at');
        $sorted = $timestamps;
        rsort($sorted);
        $this->assertSame($sorted, $timestamps);
    }

    /**
     * A `limit` outside `1..200` is a FastAPI 422 — not a silent clamp, and not
     * Laravel's 302 redirect.
     *
     * The bodies are asserted, not just the status, because they were the interesting
     * part: `$request->validate()` answers **302** for any request that does not send
     * `Accept: application/json`, and the legacy front-end calls these endpoints with
     * `fetch()` defaults.  It would have seen a redirect to the page it was already on
     * where the running server has always answered `422` with this exact `detail` list.
     * Both `detail` entries below are transcribed from a live `curl` against
     * 127.0.0.1:5000.
     */
    #[Test]
    public function the_activity_limit_is_validated(): void
    {
        $this->asMaster()->get('/master-admin/api/dashboard/activity?limit=0')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'greater_than_equal',
                'loc' => ['query', 'limit'],
                'msg' => 'Input should be greater than or equal to 1',
                'input' => '0',
                'ctx' => ['ge' => 1],
            ]]]);

        $this->asMaster()->get('/master-admin/api/dashboard/activity?limit=201')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'less_than_equal',
                'loc' => ['query', 'limit'],
                'msg' => 'Input should be less than or equal to 200',
                'input' => '201',
                'ctx' => ['le' => 200],
            ]]]);

        // A non-numeric value is a parse failure, not a bound failure — and the
        // declared `type` differs, which is what a machine-readable body is for.
        $this->asMaster()->get('/master-admin/api/dashboard/activity?limit=abc')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.input', 'abc');

        // Both bounds are inclusive.
        $this->asMaster()->get('/master-admin/api/dashboard/activity?limit=1')->assertOk();
        $this->asMaster()->get('/master-admin/api/dashboard/activity?limit=200')->assertOk();
    }

    // ── Control plane: users ─────────────────────────────────────────────────

    /**
     * The user list, including the three coercions that only the live driver can
     * demonstrate.
     */
    #[Test]
    public function the_user_list_coerces_its_column_types_and_keeps_the_padding(): void
    {
        $response = $this->asMaster()->get('/master-admin/api/users?per_page=50');

        $response->assertOk()->assertJson(['success' => true]);

        // The list envelope's six keys, in the published order.
        $this->assertSame(
            ['success', 'data', 'total', 'page', 'per_page', 'pages'],
            array_keys($response->json()),
        );

        $this->assertSame((int) DB::table('user_table')->count(), $response->json('total'));
        $this->assertSame(1, $response->json('page'));
        $this->assertSame(50, $response->json('per_page'));

        $rows = $response->json('data');
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $this->assertIsInt($row['id'], 'the driver returns `id` as a string; the API must return an integer');
            $this->assertIsInt($row['failed_login_count']);
            $this->assertSame(13, count($row), 'exactly the thirteen selected columns');
        }

        // The administrator row: `role` is `nchar(10)`, so the padding is real data.
        $admin = collect($rows)->firstWhere('username', 'admin');
        $this->assertNotNull($admin, 'the live data is expected to contain the `admin` account');
        $this->assertSame('admin     ', $admin['role'], 'nchar padding must survive serialisation');
        $this->assertSame('active', $admin['is_active']);
        $this->assertMatchesRegularExpression(self::ISOFORMAT, $admin['last_login']);
    }

    /** The username filter is a LIKE across three columns. */
    #[Test]
    public function the_user_list_filters_by_search_and_role(): void
    {
        $admin = User::query()->whereRaw("LTRIM(RTRIM(LOWER(role))) = 'admin'")->first();
        $this->assertNotNull($admin);

        $byRole = $this->asMaster()->get('/master-admin/api/users?role=admin');
        $byRole->assertOk();
        $this->assertSame(
            (int) DB::table('user_table')->whereRaw("LTRIM(RTRIM(LOWER(role))) = 'admin'")->count(),
            $byRole->json('total'),
        );

        $bySearch = $this->asMaster()->get('/master-admin/api/users?search='.urlencode($admin->username()));
        $bySearch->assertOk();
        $this->assertGreaterThanOrEqual(1, $bySearch->json('total'));

        // An empty filter must behave like no filter, because the search box clears to
        // `''` on every keystroke.
        $empty = $this->asMaster()->get('/master-admin/api/users?search=');
        $empty->assertOk();
        $this->assertSame((int) DB::table('user_table')->count(), $empty->json('total'));
    }

    /** The user detail view, and the two fields it deliberately withholds. */
    #[Test]
    public function the_user_detail_view_attaches_history_without_leaking_credentials(): void
    {
        $response = $this->asMaster()->get('/master-admin/api/users/admin');

        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json('data');

        foreach (['id', 'username', 'name', 'last_name', 'department', 'role', 'work_hours',
            'substitute', 'hozoor_num', 'is_active', 'last_login', 'failed_login_count',
            'password_changed_at', 'recent_audit', 'sessions', 'password_resets'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        $this->assertIsInt($data['id']);
        $this->assertIsArray($data['recent_audit']);
        $this->assertIsArray($data['sessions']);
        $this->assertIsArray($data['password_resets']);

        // The password columns must never appear, whatever the SELECT was changed to.
        $this->assertArrayNotHasKey('password', $data);
        $this->assertArrayNotHasKey('password_hash', $data);

        foreach ($data['sessions'] as $session) {
            $this->assertArrayNotHasKey('session_key', $session, 'the detail view must not publish the session key');
            $this->assertIsInt($session['id']);
            $this->assertIsBool($session['is_active'], 'the `bit` column must serialise as a boolean');
        }

        foreach ($data['password_resets'] as $reset) {
            $this->assertArrayNotHasKey('recovery_code', $reset, 'the recovery digest must never be published');
            $this->assertIsInt($reset['code_attempts']);
        }
    }

    /** A missing user is the FastAPI `detail` 404, not an envelope. */
    #[Test]
    public function a_missing_user_answers_the_fastapi_not_found_body(): void
    {
        $response = $this->asMaster()->get('/master-admin/api/users/no-such-user-'.bin2hex(random_bytes(4)));

        $response->assertStatus(404);
        $this->assertSame(['detail' => 'کاربر یافت نشد.'], $response->json());
    }

    // ── Control plane: audit logs ────────────────────────────────────────────

    /** The audit list, and its nine filters. */
    #[Test]
    public function the_audit_log_list_paginates_and_filters(): void
    {
        $response = $this->asMaster()->get('/master-admin/api/audit-logs?per_page=10');

        $response->assertOk()->assertJson(['success' => true]);

        $this->assertSame((int) DB::table('audit_logs')->count(), $response->json('total'));
        $this->assertLessThanOrEqual(10, count($response->json('data')));
        $this->assertSame(10, $response->json('per_page'));

        foreach ($response->json('data') as $row) {
            $this->assertMatchesRegularExpression(self::ISOFORMAT, $row['created_at']);
            $this->assertSame([
                'event_id', 'event_type', 'action', 'username', 'role', 'module',
                'resource_type', 'resource_id', 'request_id', 'session_id',
                'ip_address', 'status', 'severity', 'created_at',
            ], array_keys($row));
        }

        // A filter that must reduce the set: `event_type` is an exact match.
        $filtered = $this->asMaster()->get('/master-admin/api/audit-logs?event_type=AUTHENTICATION&per_page=5');
        $filtered->assertOk();
        $this->assertSame(
            (int) DB::table('audit_logs')->where('event_type', 'AUTHENTICATION')->count(),
            $filtered->json('total'),
        );

        // A filter that must match nothing.
        $none = $this->asMaster()->get('/master-admin/api/audit-logs?event_type=NO_SUCH_TYPE');
        $none->assertOk();
        $this->assertSame(0, $none->json('total'));
        $this->assertSame([], $none->json('data'));
        $this->assertSame(1, $none->json('pages'), 'an empty result is still one page');
    }

    /**
     * The audit-log **detail** endpoint, which the Python implementation could not
     * serve.
     *
     * This is the regression test for the defect: the Python code asked an exhausted
     * cursor for its rows and raised `IndexError`, so every request answered HTTP 500
     * `{"success": false, "message": "خطای داخلی سرور"}`.  Reproduced against the live
     * database before the port (`RAISED IndexError: list index out of range`, on a row
     * that exists), then fixed here.
     */
    #[Test]
    public function the_audit_event_detail_returns_the_event_instead_of_crashing(): void
    {
        $eventId = $this->asMaster()->get('/master-admin/api/audit-logs?per_page=1')->json('data.0.event_id');
        $this->assertIsString($eventId);

        $response = $this->asMaster()->get('/master-admin/api/audit-logs/'.urlencode($eventId));

        $response->assertOk()->assertJson(['success' => true]);

        $event = $response->json('data');

        $this->assertSame($eventId, $event['event_id']);
        $this->assertMatchesRegularExpression(self::ISOFORMAT, $event['created_at']);

        // The five columns the list omits are present here.
        $this->assertSame([
            'event_id', 'event_type', 'action', 'username', 'role', 'module', 'resource_type',
            'resource_id', 'request_id', 'session_id', 'ip_address', 'user_agent', 'status',
            'severity', 'before_data', 'after_data', 'metadata', 'error_id', 'created_at',
        ], array_keys($event));

        // And the 404 is unchanged for an event that does not exist.
        $missing = $this->asMaster()->get('/master-admin/api/audit-logs/HST-19700101-DEADBEEF');
        $missing->assertStatus(404);
        $this->assertSame(['detail' => 'رویداد یافت نشد.'], $missing->json());
    }

    // ── Control plane: sessions, health, search ───────────────────────────────

    /** The session list publishes `session_key`, because the terminate action needs it. */
    #[Test]
    public function the_session_list_serialises_bits_as_booleans_and_ids_as_integers(): void
    {
        $response = $this->asMaster()->get('/master-admin/api/sessions?per_page=10');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame((int) DB::table('user_sessions')->count(), $response->json('total'));

        foreach ($response->json('data') as $row) {
            $this->assertIsInt($row['id']);
            $this->assertIsBool($row['is_active']);
            $this->assertArrayHasKey('session_key', $row);
            $this->assertMatchesRegularExpression(self::ISOFORMAT, $row['login_at']);
        }

        // The six truthy and six falsy spellings pydantic accepted.  Each request is
        // signed in, and signing in **registers a session row** — so the expected count
        // cannot be captured once and reused.  It is read from the database after the
        // request instead, inside the same transaction: that is exactly the set of rows
        // the query saw, and it makes the assertion independent of how many sessions
        // the helper has created so far.
        foreach (['true', '1', 'on', 't', 'yes', 'y'] as $spelling) {
            $filtered = $this->asMaster()->get("/master-admin/api/sessions?active_only={$spelling}");

            $filtered->assertOk();
            $this->assertSame(
                (int) DB::table('user_sessions')->where('is_active', 1)->count(),
                $filtered->json('total'),
                "active_only={$spelling} must be understood as `true`",
            );
            // The filter is load-bearing, not ignored: this installation has far more
            // historical sessions than active ones.
            $this->assertLessThan(
                (int) DB::table('user_sessions')->count(),
                $filtered->json('total'),
                'the active-only filter must exclude the closed sessions',
            );
        }

        foreach (['false', '0', 'off', 'f', 'no', 'n'] as $spelling) {
            $unfiltered = $this->asMaster()->get("/master-admin/api/sessions?active_only={$spelling}");

            $unfiltered->assertOk();
            $this->assertSame(
                (int) DB::table('user_sessions')->count(),
                $unfiltered->json('total'),
                "active_only={$spelling} must be understood as `false`",
            );
        }

        // And the spellings pydantic rejected are rejected here too — as FastAPI's 422
        // body, not Laravel's 302 redirect.  `active_only=` (empty) is the one a browser
        // produces by accident, and the running server answers 422 for it.
        foreach (['', 'maybe', '2'] as $invalid) {
            $this->asMaster()
                ->get("/master-admin/api/sessions?active_only={$invalid}")
                ->assertStatus(422)
                ->assertJsonPath('detail.0.type', 'bool_parsing')
                ->assertJsonPath('detail.0.loc', ['query', 'active_only']);
        }
    }

    /**
     * The health panel: always 200, with per-probe failure sentinels.
     */
    #[Test]
    public function the_system_health_panel_reports_each_probe_independently(): void
    {
        $response = $this->asMaster()->get('/master-admin/api/system-health');

        $response->assertOk()->assertJson(['success' => true]);

        $health = $response->json('data');

        $this->assertSame('healthy', $health['database']['status']);
        $this->assertIsNumeric($health['database']['latency_ms']);

        $this->assertSame(
            (int) DB::table('user_sessions')->where('is_active', 1)->count(),
            $health['active_sessions'],
        );

        $this->assertIsInt($health['audit_events_today']);
        $this->assertGreaterThanOrEqual(0, $health['audit_events_today']);

        // `datetime.now(timezone.utc).isoformat()` — six digits *and* an offset.
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}\+00:00$/',
            $health['server_time_utc'],
        );
    }

    /** Global search: the `results` key, the four types, and the empty-query case. */
    #[Test]
    public function the_global_search_returns_a_flat_typed_result_list(): void
    {
        $empty = $this->asMaster()->get('/master-admin/api/search?q=');
        $empty->assertOk();
        $this->assertSame(['success' => true, 'results' => []], $empty->json());

        $whitespace = $this->asMaster()->get('/master-admin/api/search?q=%20%20');
        $whitespace->assertOk();
        $this->assertSame([], $whitespace->json('results'), 'a whitespace-only query is still an empty search');

        // A term that must exist: an administrator's username.
        $response = $this->asMaster()->get('/master-admin/api/search?q=admin');

        $response->assertOk()->assertJson(['success' => true]);

        $results = $response->json('results');
        $this->assertNotEmpty($results);

        foreach ($results as $result) {
            $this->assertContains($result['type'], ['user', 'audit', 'security', 'error']);
            $this->assertArrayHasKey('title', $result);
            $this->assertArrayHasKey('subtitle', $result);
            $this->assertArrayHasKey('link', $result);
        }

        $this->assertContains('user', array_column($results, 'type'));
    }

    // ── User panel ───────────────────────────────────────────────────────────

    /**
     * The recipient picker, and the rule that the caller excludes themselves.
     */
    #[Test]
    public function the_user_picker_excludes_the_caller_and_keeps_the_value_label_shape(): void
    {
        $response = $this->asUser()->get('/get_users');

        $response->assertOk()->assertJson(['success' => true]);

        $users = $response->json('users');
        $this->assertNotEmpty($users);

        // The handler both excludes the caller and drops a row whose username trims
        // to nothing, so the expected set carries both predicates.
        $this->assertSame(
            (int) DB::table('user_table')
                ->whereRaw('LTRIM(RTRIM(username)) <> ?', [$this->signedInUsername()])
                ->whereRaw("LTRIM(RTRIM(username)) <> ''")
                ->count(),
            count($users),
            'every other account must be offered, and only those',
        );

        foreach ($users as $user) {
            $this->assertSame(['value', 'label'], array_keys($user));
            $this->assertNotSame($this->signedInUsername(), $user['value']);
            $this->assertSame(trim($user['value']), $user['value'], 'the username is trimmed of its padding');
        }
    }

    /**
     * The receiver list is a **bare array** — no envelope at all.
     */
    #[Test]
    public function the_receiver_list_is_a_bare_array_restricted_to_master_admins(): void
    {
        $response = $this->asUser()->get('/get_receivers');

        $response->assertOk();
        $this->assertIsArray($response->json());
        $this->assertSame(array_values($response->json()), $response->json(), 'a bare JSON array, not an object');

        $masters = array_map(
            static fn ($name): string => mb_strtolower(trim((string) $name)),
            (array) config('hastama.master_admin_usernames', []),
        );

        foreach ($response->json() as $username) {
            $this->assertContains(mb_strtolower($username), $masters, 'only master admins may receive a request');
        }
    }

    /** The signed-in user's own profile block. */
    #[Test]
    public function the_profile_block_returns_the_five_columns_for_the_session_user(): void
    {
        $response = $this->asUser()->get('/get_user_info');

        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertSame(
            ['name', 'last_name', 'department', 'work_hours', 'substitute'],
            array_keys($response->json('data')),
        );
    }

    /**
     * The report endpoint's ownership check — the only thing standing between a
     * signed-in user and every other employee's profile.
     */
    #[Test]
    public function the_report_endpoint_enforces_ownership_and_allows_an_admin_through(): void
    {
        // Sign in **first**: `signedInUsername()` is whoever the last `asUser()`/
        // `asMaster()` established, and reading it before a sign-in yields `''` — which
        // this endpoint treats as "no username provided" and answers 200/`success:false`.
        $this->asUser();
        $self = $this->signedInUsername();
        $this->assertNotSame('', $self);

        $own = $this->get('/get_user_info_report?username='.urlencode($self));
        $own->assertOk();
        $this->assertTrue($own->json('success'));

        $other = DB::table('user_table')
            ->whereRaw('LTRIM(RTRIM(username)) <> ?', [$self])
            ->value('username');
        $this->assertNotNull($other, 'the live data is expected to hold more than one account');

        $forbidden = $this->asUser()->get('/get_user_info_report?username='.urlencode(trim($other)));
        $forbidden->assertStatus(403);
        $this->assertSame(['success' => false, 'message' => 'دسترسی غیرمجاز'], $forbidden->json());

        // An administrator is not subject to the ownership rule.
        $admin = $this->asMaster()->get('/get_user_info_report?username='.urlencode(trim($other)));
        $admin->assertOk();
        $this->assertTrue($admin->json('success'));

        // A user that does not exist is a 200 with `success: false`, not a 404.
        $missing = $this->asUser()->get('/get_user_info_report?username=no-such-user-xyz');
        $missing->assertStatus(403, 'the ownership check runs before the lookup, as in the Python');

        $missingAsAdmin = $this->asMaster()->get('/get_user_info_report?username=no-such-user-xyz');
        $missingAsAdmin->assertOk();
        $this->assertSame(['success' => false, 'message' => 'اطلاعات کاربر پیدا نشد'], $missingAsAdmin->json());
    }

    /** The missing required parameter is a 422, and a present-but-empty one is not. */
    #[Test]
    public function the_report_endpoint_requires_the_username_parameter(): void
    {
        // FastAPI resolves a required `Query(...)` before the handler body runs, so the
        // missing parameter is reported as a 422 rather than the handler's own 401 —
        // even for a caller with no session at all.  Declared through `LegacyQuery`, so
        // the rejection is FastAPI's body rather than Laravel's 302 redirect.
        $this->asUser()->get('/get_user_info_report')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'missing',
                'loc' => ['query', 'username'],
                'msg' => 'Field required',
                'input' => null,
            ]]]);

        // Present-but-empty is *not* missing: `Query(...)` requires the parameter to
        // exist, and the handler has its own body for the empty string.
        $empty = $this->asUser()->get('/get_user_info_report?username=');
        $empty->assertOk();
        $this->assertSame(['success' => false, 'message' => 'نام کاربری ارائه نشده است'], $empty->json());
    }

    /**
     * Active shifts, and the Jalali window test behind them.
     */
    #[Test]
    public function the_active_shifts_are_selected_by_the_jalali_day_window(): void
    {
        $response = $this->asMaster()->get('/get_active_shifts');

        $response->assertOk()->assertJson(['success' => true]);

        $today = LegacyDate::today();

        $this->assertSame((string) $today->day, $response->json('today'), '`today` is a string, unlike `year`');
        $this->assertSame($today->year, $response->json('year'));
        $this->assertSame($today->month, $response->json('month'));

        $this->assertIsArray($response->json('shifts'));

        // Tied to the same predicate the query uses, so a wrong Jalali month shows up.
        $this->assertSame(
            (int) DB::table('shiftha')
                ->where('jalali_year', $today->year)
                ->where('jalali_month', $today->month)
                ->where('start_day', '<=', $today->day)
                ->where('end_day', '>=', $today->day)
                ->count(),
            count($response->json('shifts')),
        );

        foreach ($response->json('shifts') as $shift) {
            $this->assertIsInt($shift['id']);
            $this->assertIsInt($shift['jalali_year']);
            $this->assertIsInt($shift['start_day']);
            // Nullable text columns became `''`, because the renderer concatenates them.
            $this->assertIsString($shift['title']);
            $this->assertIsString($shift['shanbeh']);
        }
    }

    /** The user's own leave rows, with both dates converted to Jalali. */
    #[Test]
    public function the_users_own_leave_rows_are_converted_to_jalali(): void
    {
        $response = $this->asUser()->get('/get_leave_info');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertIsArray($response->json('data'));

        foreach ($response->json('data') as $leave) {
            $this->assertSame(['start_date', 'end_date', 'days', 'status'], array_keys($leave));
            $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2}$|^نامعتبر$#', $leave['start_date']);
            $this->assertMatchesRegularExpression('#^\d{4}/\d{2}/\d{2}$|^نامعتبر$#', $leave['end_date']);
            $this->assertIsString($leave['status']);
            $this->assertNotSame('', $leave['status'], 'a NULL status becomes the pending label, never an empty string');
        }
    }

    /**
     * The admin review list: a **bare array**, with the `%Y-%m-%d` separator and the
     * `id` the user's own view does not carry.
     */
    #[Test]
    public function the_admin_leave_review_list_is_a_bare_array_with_ids_and_dashed_dates(): void
    {
        $response = $this->asMaster()->get('/get_leave_requests');

        $response->assertOk();
        $this->assertIsArray($response->json());
        $this->assertSame(array_values($response->json()), $response->json());

        $rows = $response->json();
        $this->assertSame((int) DB::table('mrkhc_table')->count(), count($rows));

        foreach ($rows as $row) {
            $this->assertIsInt($row['id']);
            $this->assertSame(
                ['id', 'start_date', 'end_date', 'days', 'substitute', 'username', 'status'],
                array_keys($row),
            );
            $this->assertMatchesRegularExpression('#^\d{4}-\d{2}-\d{2}$|^تاریخ ناموجود$#', $row['start_date']);
            // `days` is nullable, and a NULL must stay null rather than becoming 0.
            $this->assertTrue($row['days'] === null || is_int($row['days']));
        }

        // A non-admin cannot reach it at all.
        $this->asUser()->get('/get_leave_requests')->assertStatus(403);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Sign in as a master administrator, with a live registry row. */
    private function asMaster(): static
    {
        $master = User::query()->whereRaw("LTRIM(RTRIM(LOWER(role))) = 'admin'")->first()
            ?? User::query()->first();

        $this->assertNotNull($master);

        return $this->signIn($master, isMasterAdmin: true);
    }

    /** Sign in as an ordinary user, with a live registry row. */
    private function asUser(): static
    {
        // `role` is `nchar(10)`, so the comparison has to be trimmed on both sides.
        // The literal is **bound** rather than inlined: the parameterless raw form
        // (`... <> 'admin'`) is what `asMaster()` uses and it works, but the variant
        // with an adjacent empty-string literal failed on the live driver with
        // `SQLSTATE[42000] Incorrect syntax near 'admin'`, so the test helper binds the
        // value instead of relying on the quoting.
        $user = User::query()->whereRaw('LTRIM(RTRIM(LOWER(role))) <> ?', ['admin'])->first()
            ?? User::query()->first();

        $this->assertNotNull($user);

        return $this->signIn($user, isMasterAdmin: false);
    }

    /**
     * Establish the session a browser would hold: the Laravel guard identity, the
     * legacy session flags, and an **active registry row**.
     *
     * The registry row is what makes `legacy.session:optional` permit the request —
     * exactly as the legacy middleware required a live `user_sessions` entry — and it
     * lives inside the transaction that is rolled back.
     */
    private function signIn(User $user, bool $isMasterAdmin): static
    {
        $this->username = $user->username();
        $this->token = app(SessionRegistry::class)->newToken();

        $this->assertTrue(
            app(SessionRegistry::class)->register($this->token, $this->username, '127.0.0.1', 'LegacyReadDatabaseTest'),
            'the session registry row must be written for the request to be permitted',
        );

        // `actingAs` rather than `Auth::login()`: the test helper sets the guard user
        // the way a request would find it, without needing a request to exist yet.
        $this->actingAs($user);

        return $this->withSession([
            'username' => $this->username,
            SessionRegistry::SESSION_TOKEN_KEY => $this->token,
            SessionRegistry::SESSION_ISSUED_KEY => time(),
            'is_admin' => $user->isAdmin(),
            'is_master_admin' => $isMasterAdmin,
            'role' => $user->role(),
        ]);
    }

    /** The trimmed username of whoever is signed in. */
    private function signedInUsername(): string
    {
        return $this->username;
    }
}
