<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Legacy\LegacySchema;
use Carbon\CarbonImmutable;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The master-admin control plane's **write** surface, offline.
 *
 * Every test runs without the live database.  That is possible — and worth the
 * effort — because the two things these endpoints do are both observable
 * without SQL Server:
 *
 * * the **HTTP contract** (guard, validation, status codes, body shapes), which
 *   is decided before or instead of any query; and
 * * the **side effects**, which are writes.  The writes go through the real
 *   `AuditLogger`, the real `SessionRegistry` and the real Eloquent models —
 *   all three are `final`, so they cannot be mocked — into a
 *   {@see FakeLegacyConnection} that records every statement and its bindings.
 *   Asserting the recorded `INSERT INTO admin_actions` is a stronger check than
 *   asserting that a spy was called: it proves the real logging path ran, with
 *   the real values, in the real order.
 *
 * The fake connection is registered through `DB::extend()` and made the default
 * connection, so `DB::connection()` and every Eloquent model resolve to it
 * exactly as they would to SQL Server in production.
 *
 * `LegacySchema`'s type map is seeded from `docs/migration/DATABASE_SCHEMA.md`
 * via reflection: `pdo_sqlsrv` returns every scalar as a string, and the
 * serializer needs the declared SQL type to coerce `id` back to an integer and
 * `is_active` back to a boolean the way `pyodbc` did.
 */
final class MasterAdminControlTest extends TestCase
{
    private FakeLegacyConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new FakeLegacyConnection;

        DB::extend('legacy', fn () => $this->db);
        config(['database.default' => 'legacy', 'database.connections.legacy' => ['driver' => 'fake']]);
        DB::purge('legacy');

        // The registry check every master-admin request passes through: an
        // active row for the session's own token, so `legacy.session:optional`
        // permits the request exactly as it would against the live table.
        $this->db->sessionRows = [(object) ['username' => 'ali', 'is_active' => 1, 'last_activity' => null]];

        $this->seedSchemaTypes();
    }

    protected function tearDown(): void
    {
        DB::purge('legacy');

        parent::tearDown();
    }

    // ── Route wiring ─────────────────────────────────────────────────────────

    /**
     * Every route this phase adds, and the controller action it must reach.
     *
     * Route **names** are asserted rather than paths alone so a later rename
     * cannot silently change which handler answers a legacy path.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function routeActions(): array
    {
        return [
            'ma-control.subscriptions.summary' => ['/master-admin/api/subscriptions/summary', 'GET', 'App\Http\Controllers\MasterAdmin\SubscriptionController@summary'],
            'ma-control.subscriptions.index' => ['/master-admin/api/subscriptions', 'GET', 'App\Http\Controllers\MasterAdmin\SubscriptionController@index'],
            'ma-control.subscriptions.show' => ['/master-admin/api/subscriptions/1', 'GET', 'App\Http\Controllers\MasterAdmin\SubscriptionController@show'],
            'ma-control.subscriptions.update' => ['/master-admin/api/subscriptions/1', 'PATCH', 'App\Http\Controllers\MasterAdmin\SubscriptionController@update'],
            'ma-control.audit-logs.destroy' => ['/master-admin/api/audit-logs/HST-1', 'DELETE', 'App\Http\Controllers\MasterAdmin\AuditLogWriteController@destroy'],
            'ma-control.users.toggle-status' => ['/master-admin/api/users/ali/toggle-status', 'POST', 'App\Http\Controllers\MasterAdmin\UserActionController@toggleStatus'],
            'ma-control.users.change-role' => ['/master-admin/api/users/ali/change-role', 'POST', 'App\Http\Controllers\MasterAdmin\UserActionController@changeRole'],
            'ma-control.sessions.terminate' => ['/master-admin/api/sessions/abc/terminate', 'POST', 'App\Http\Controllers\MasterAdmin\SessionActionController@terminate'],
            'ma-control.sessions.destroy' => ['/master-admin/api/sessions/abc', 'DELETE', 'App\Http\Controllers\MasterAdmin\SessionActionController@destroy'],
            'ma-control.sessions.terminate-all' => ['/master-admin/api/sessions/terminate-all', 'POST', 'App\Http\Controllers\MasterAdmin\SessionActionController@terminateAll'],
            'ma-control.sessions.destroy-all' => ['/master-admin/api/sessions', 'DELETE', 'App\Http\Controllers\MasterAdmin\SessionActionController@destroyAll'],
            'ma-control.password-resets.index' => ['/master-admin/api/password-resets', 'GET', 'App\Http\Controllers\MasterAdmin\PasswordResetController@index'],
            'ma-control.password-resets.approve' => ['/master-admin/api/password-resets/HST-1/approve', 'POST', 'App\Http\Controllers\MasterAdmin\PasswordResetController@approve'],
            'ma-control.password-resets.reject' => ['/master-admin/api/password-resets/HST-1/reject', 'POST', 'App\Http\Controllers\MasterAdmin\PasswordResetController@reject'],
            'ma-control.password-resets.destroy' => ['/master-admin/api/password-resets/HST-1', 'DELETE', 'App\Http\Controllers\MasterAdmin\PasswordResetController@destroy'],
            'ma-control.security.index' => ['/master-admin/api/security', 'GET', 'App\Http\Controllers\MasterAdmin\SecurityEventController@index'],
            'ma-control.security.resolve' => ['/master-admin/api/security/HST-1/resolve', 'POST', 'App\Http\Controllers\MasterAdmin\SecurityEventController@resolve'],
            'ma-control.security.destroy' => ['/master-admin/api/security/HST-1', 'DELETE', 'App\Http\Controllers\MasterAdmin\SecurityEventController@destroy'],
            'ma-control.errors.index' => ['/master-admin/api/errors', 'GET', 'App\Http\Controllers\MasterAdmin\SystemErrorController@index'],
            'ma-control.errors.resolve' => ['/master-admin/api/errors/HST-1/resolve', 'POST', 'App\Http\Controllers\MasterAdmin\SystemErrorController@resolve'],
            'ma-control.errors.destroy' => ['/master-admin/api/errors/HST-1', 'DELETE', 'App\Http\Controllers\MasterAdmin\SystemErrorController@destroy'],
            'ma-control.admin-actions.index' => ['/master-admin/api/admin-actions', 'GET', 'App\Http\Controllers\MasterAdmin\AdminActionController@index'],
            'ma-control.admin-actions.destroy' => ['/master-admin/api/admin-actions/HST-1', 'DELETE', 'App\Http\Controllers\MasterAdmin\AdminActionController@destroy'],
        ];
    }

    #[DataProvider('routeActions')]
    public function test_the_control_routes_are_wired_to_their_controllers(string $uri, string $method, string $action): void
    {
        // Matched with a real request rather than by comparing URI strings,
        // because a parameterised route never equals its own resolved path.
        $request = Request::create($uri, $method);
        $route = collect(Route::getRoutes())->first(static fn ($route): bool => $route->matches($request));

        $this->assertNotNull($route, "no route is registered for {$uri}");
        $this->assertContains($method, $route->methods());
        $this->assertSame($action, $route->getActionName());
    }

    // ── The guard ───────────────────────────────────────────────────────────

    /**
     * Every control route, anonymous.
     *
     * The guard answers FastAPI's `detail` shape — not the `error` key the
     * `main.py` helpers use — and it answers before the session registry runs,
     * which is the ordering the read half's tests already pin.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function controlRoutes(): array
    {
        return [
            'subscriptions summary' => ['GET', '/master-admin/api/subscriptions/summary'],
            'subscriptions index' => ['GET', '/master-admin/api/subscriptions'],
            'subscription detail' => ['GET', '/master-admin/api/subscriptions/1'],
            'subscription update' => ['PATCH', '/master-admin/api/subscriptions/1'],
            'audit log delete' => ['DELETE', '/master-admin/api/audit-logs/HST-1'],
            'user toggle' => ['POST', '/master-admin/api/users/ali/toggle-status'],
            'user role' => ['POST', '/master-admin/api/users/ali/change-role'],
            'session terminate' => ['POST', '/master-admin/api/sessions/abc/terminate'],
            'session delete' => ['DELETE', '/master-admin/api/sessions/abc'],
            'sessions terminate all' => ['POST', '/master-admin/api/sessions/terminate-all'],
            'sessions delete all' => ['DELETE', '/master-admin/api/sessions'],
            'password resets index' => ['GET', '/master-admin/api/password-resets'],
            'password reset approve' => ['POST', '/master-admin/api/password-resets/HST-1/approve'],
            'password reset reject' => ['POST', '/master-admin/api/password-resets/HST-1/reject'],
            'password reset delete' => ['DELETE', '/master-admin/api/password-resets/HST-1'],
            'security index' => ['GET', '/master-admin/api/security'],
            'security resolve' => ['POST', '/master-admin/api/security/HST-1/resolve'],
            'security delete' => ['DELETE', '/master-admin/api/security/HST-1'],
            'errors index' => ['GET', '/master-admin/api/errors'],
            'error resolve' => ['POST', '/master-admin/api/errors/HST-1/resolve'],
            'error delete' => ['DELETE', '/master-admin/api/errors/HST-1'],
            'admin actions index' => ['GET', '/master-admin/api/admin-actions'],
            'admin action delete' => ['DELETE', '/master-admin/api/admin-actions/HST-1'],
        ];
    }

    #[DataProvider('controlRoutes')]
    public function test_every_control_route_refuses_an_anonymous_request_with_the_fastapi_body(string $method, string $uri): void
    {
        $response = $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertStatus(401);
        $this->assertSame(['detail' => 'ورود لازم است.'], $response->json());
    }

    #[DataProvider('controlRoutes')]
    public function test_every_control_route_refuses_a_non_master_admin(string $method, string $uri): void
    {
        $this->actingAs(new User(['username' => 'ordinary']));

        $response = $this->withSession([
            'username' => 'ordinary',
            'is_admin' => true,
            'is_master_admin' => false,
        ])->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertStatus(403);
        $this->assertSame(['detail' => 'دسترسی مدیریت اصلی لازم است.'], $response->json());
    }

    /**
     * The refusal is audited — an ordinary administrator probing the control
     * plane is exactly the event the operator wants to see.
     *
     * Asserted on the real `AuditLogger`'s write rather than on a spy, because
     * the class is `final` and cannot be mocked; the recorded insert proves the
     * real logging path ran with the real values.
     */
    public function test_the_non_master_admin_refusal_writes_a_high_severity_security_event(): void
    {
        $this->actingAs(new User(['username' => 'ordinary']));

        $this->withSession([
            'username' => 'ordinary',
            'is_admin' => true,
            'is_master_admin' => false,
        ])->get('/master-admin/api/subscriptions');

        $events = $this->recordedQueriesContaining('security_events');
        $this->assertNotEmpty($events, 'the refusal must be written to security_events');

        $bindings = $events[0][1];
        $this->assertSame('AUTHORIZATION', $bindings[1]);
        $this->assertSame('high', $bindings[2]);
        $this->assertSame('ordinary', $bindings[3]);
    }

    // ── Validation ──────────────────────────────────────────────────────────

    /**
     * A rejected query parameter is FastAPI's 422 body, not Laravel's 302
     * redirect — the status the admin UI's `fetch()` calls branch on.
     */
    public function test_the_subscription_list_validates_its_query_parameters(): void
    {
        $this->asMasterAdmin();

        $this->get('/master-admin/api/subscriptions?page=0')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'greater_than_equal',
                'loc' => ['query', 'page'],
                'msg' => 'Input should be greater than or equal to 1',
                'input' => '0',
                'ctx' => ['ge' => 1],
            ]]]);

        $this->get('/master-admin/api/subscriptions?per_page=201')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'less_than_equal')
            ->assertJsonPath('detail.0.loc', ['query', 'per_page']);

        $this->get('/master-admin/api/subscriptions?page=abc')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.input', 'abc');

        // Both bounds are inclusive.
        $this->db->objectId = null;
        $this->get('/master-admin/api/subscriptions?page=1&per_page=200')->assertOk();
    }

    /**
     * The subscription id is a declared `int` path parameter, so a non-numeric
     * id is a 422 with `loc: ["path", "subscription_id"]` — not a 404 and not a
     * 500.
     */
    public function test_the_subscription_detail_validates_its_path_parameter(): void
    {
        $this->asMasterAdmin();

        $this->get('/master-admin/api/subscriptions/abc')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'int_parsing',
                'loc' => ['path', 'subscription_id'],
                'msg' => 'Input should be a valid integer, unable to parse string as an integer',
                'input' => 'abc',
            ]]]);
    }

    /**
     * Every paginated list validates the same two bounds.
     *
     * PHPUnit 12 requires every dataset to be an array of arguments, so each
     * path is wrapped.
     *
     * @return array<string, array{string}>
     */
    public static function paginatedLists(): array
    {
        return [
            'subscriptions' => ['/master-admin/api/subscriptions'],
            'password resets' => ['/master-admin/api/password-resets'],
            'security events' => ['/master-admin/api/security'],
            'errors' => ['/master-admin/api/errors'],
            'admin actions' => ['/master-admin/api/admin-actions'],
        ];
    }

    #[DataProvider('paginatedLists')]
    public function test_the_paginated_lists_validate_their_query_parameters(string $path): void
    {
        $this->asMasterAdmin();

        $this->get("{$path}?page=0")->assertStatus(422)->assertJsonPath('detail.0.type', 'greater_than_equal');
        $this->get("{$path}?per_page=201")->assertStatus(422)->assertJsonPath('detail.0.type', 'less_than_equal');
        $this->get("{$path}?page=abc")->assertStatus(422)->assertJsonPath('detail.0.type', 'int_parsing');
    }

    // ── Subscriptions ───────────────────────────────────────────────────────

    /** A missing subscriptions table is not an error — it is `setup_needed`. */
    public function test_the_subscription_summary_reports_zero_when_the_table_is_missing(): void
    {
        $this->asMasterAdmin();
        $this->db->objectId = null;

        $this->get('/master-admin/api/subscriptions/summary')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'setup_needed' => true,
                'data' => [
                    'total_customers' => 0,
                    'active_subscriptions' => 0,
                    'expiring_soon' => 0,
                    'total_seats_used' => 0,
                    'total_seats' => 0,
                ],
            ]);
    }

    /**
     * The five counters, computed the way the Python computed them — including
     * the seat usage, which is a best-effort count that swallows its own
     * failures.
     */
    public function test_the_subscription_summary_computes_its_counters(): void
    {
        $this->asMasterAdmin();
        $this->db->objectId = '12345';
        $this->db->rows = $this->subscriptionRows();
        $this->db->userTableColumns = [
            (object) ['column_name' => 'customer_code'],
            (object) ['column_name' => 'customer_id'],
            (object) ['column_name' => 'is_active'],
        ];
        $this->db->seatCount = 3;

        $this->get('/master-admin/api/subscriptions/summary')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'setup_needed' => false,
                'data' => [
                    'total_customers' => 2,
                    'active_subscriptions' => 1,
                    'expiring_soon' => 1,
                    'total_seats_used' => 6,
                    'total_seats' => 15,
                ],
            ]);
    }

    /**
     * The list: PHP-side filtering and pagination over the full result set, the
     * computed status replacing the stored one, and the driver's strings
     * coerced back to the JSON types `pyodbc` produced.
     */
    public function test_the_subscription_list_filters_and_paginates_in_php(): void
    {
        $this->asMasterAdmin();
        $this->db->objectId = '12345';
        $this->db->rows = $this->subscriptionRows();
        $this->db->userTableColumns = [(object) ['column_name' => 'customer_code']];
        $this->db->seatCount = 1;

        $response = $this->get('/master-admin/api/subscriptions');

        $response->assertOk()->assertJson(['success' => true]);

        // The envelope's seven keys, in the published order.
        $this->assertSame(
            ['success', 'setup_needed', 'data', 'total', 'page', 'per_page', 'pages'],
            array_keys($response->json()),
        );

        $this->assertSame(2, $response->json('total'));
        $this->assertSame(1, $response->json('page'));
        $this->assertSame(50, $response->json('per_page'));
        $this->assertSame(1, $response->json('pages'));

        $rows = $response->json('data');
        $this->assertCount(2, $rows);

        // Ordered by expires_at DESC: the October row first.
        $this->assertSame(1, $rows[0]['id'], 'the driver returns id as a string; the API must return an integer');
        $this->assertSame('active', $rows[0]['status'], 'the stored status is replaced by the computed one');
        $this->assertSame('active', $rows[0]['computed_status']);
        $this->assertSame(15, $rows[0]['remaining_days']);
        $this->assertSame(1, $rows[0]['seats_used']);
        $this->assertSame(10, $rows[0]['max_users']);
        $this->assertSame(100.5, $rows[0]['price'], 'a decimal column serialises as a float');
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{6})?$/',
            $rows[0]['expires_at'],
        );

        $this->assertSame(2, $rows[1]['id']);
        $this->assertSame('expired', $rows[1]['status']);
        $this->assertSame(-10, $rows[1]['remaining_days']);

        // The search predicate is a case-folded substring test across five columns.
        $searched = $this->get('/master-admin/api/subscriptions?search=acme');
        $searched->assertOk();
        $this->assertSame(1, $searched->json('total'));
        $this->assertSame(1, $searched->json('data.0.id'));

        // An empty filter behaves like no filter, because the search box clears
        // to '' on every keystroke.
        $this->get('/master-admin/api/subscriptions?search=')->assertOk()->assertJsonPath('total', 2);

        // `status` is an exact match on the computed status.
        $this->get('/master-admin/api/subscriptions?status=active')->assertOk()->assertJsonPath('total', 1);
        $this->get('/master-admin/api/subscriptions?status=expired')->assertOk()->assertJsonPath('total', 1);

        // `expiring` is a computed range, not a stored value.
        $expiring = $this->get('/master-admin/api/subscriptions?status=expiring');
        $expiring->assertOk();
        $this->assertSame(1, $expiring->json('total'));
        $this->assertSame(1, $expiring->json('data.0.id'));

        // Pagination slices the filtered list in PHP.
        $page2 = $this->get('/master-admin/api/subscriptions?per_page=1&page=2');
        $page2->assertOk();
        $this->assertSame(2, $page2->json('total'), 'total is the count after filtering, before slicing');
        $this->assertSame(2, $page2->json('pages'));
        $this->assertCount(1, $page2->json('data'));
        $this->assertSame(2, $page2->json('data.0.id'));
    }

    /** The detail view attaches the linked users, best-effort. */
    public function test_the_subscription_detail_attaches_the_linked_users(): void
    {
        $this->asMasterAdmin();
        $this->db->objectId = '12345';
        $this->db->rows = [$this->subscriptionRows()[0]];
        $this->db->usersRows = [(object) [
            'id' => '1', 'username' => 'ali', 'name' => 'Ali', 'last_name' => 'A',
            'department' => 'Lab', 'role' => 'admin     ', 'is_active' => 'active', 'last_login' => null,
        ]];

        $response = $this->get('/master-admin/api/subscriptions/1');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertFalse($response->json('setup_needed'));

        $data = $response->json('data');
        $this->assertSame(1, $data['id']);
        $this->assertSame('active', $data['status']);
        $this->assertCount(1, $data['users']);
        $this->assertSame('ali', $data['users'][0]['username']);
        $this->assertSame(1, $data['users'][0]['id']);
    }

    /** A missing subscription is the FastAPI `detail` 404. */
    public function test_a_missing_subscription_answers_the_fastapi_not_found_body(): void
    {
        $this->asMasterAdmin();
        $this->db->objectId = '12345';
        $this->db->rows = [];

        $this->get('/master-admin/api/subscriptions/999')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'اشتراک یافت نشد.']);
    }

    /** A missing table answers `setup_needed` with a null data payload. */
    public function test_the_subscription_detail_reports_setup_needed_with_a_null_data(): void
    {
        $this->asMasterAdmin();
        $this->db->objectId = null;

        $this->get('/master-admin/api/subscriptions/1')
            ->assertOk()
            ->assertExactJson(['success' => true, 'setup_needed' => true, 'data' => null]);
    }

    /**
     * The update's validation order and messages, verbatim from the Python.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidUpdates(): array
    {
        return [
            'no allowed fields' => [[], 'اطلاعاتی برای ویرایش ارسال نشده است.'],
            'blank customer_id' => [['customer_id' => '  '], 'فیلد customer_id الزامی است.'],
            'blank customer_name' => [['customer_name' => ''], 'فیلد customer_name الزامی است.'],
            'blank plan_name' => [['plan_name' => ' '], 'فیلد plan_name الزامی است.'],
            'blank starts_at' => [['starts_at' => null], 'فیلد starts_at الزامی است.'],
            'blank expires_at' => [['expires_at' => '  '], 'فیلد expires_at الزامی است.'],
            'non-numeric max_users' => [['max_users' => 'abc'], 'ظرفیت کاربران معتبر نیست.'],
            'negative max_users' => [['max_users' => -1], 'ظرفیت کاربران معتبر نیست.'],
            'null max_users' => [['max_users' => null], 'ظرفیت کاربران معتبر نیست.'],
            'non-numeric starts_at' => [['starts_at' => '30/09/2026'], 'تاریخ واردشده معتبر نیست.'],
            'impossible starts_at' => [['starts_at' => '2026-02-30'], 'تاریخ واردشده معتبر نیست.'],
            'non-numeric expires_at' => [['expires_at' => 'not-a-date'], 'تاریخ واردشده معتبر نیست.'],
            'invalid payment_method' => [['payment_method' => 'crypto'], 'روش پرداخت معتبر نیست.'],
        ];
    }

    #[DataProvider('invalidUpdates')]
    public function test_the_subscription_update_validates_the_body(array $body, string $message): void
    {
        $this->asMasterAdmin();

        $this->patchJson('/master-admin/api/subscriptions/1', $body)
            ->assertStatus(400)
            ->assertExactJson(['detail' => $message]);
    }

    /** A missing table is the Python's 409, not a 500. */
    public function test_the_subscription_update_reports_the_table_missing(): void
    {
        $this->asMasterAdmin();
        $this->db->objectId = null;

        $this->patchJson('/master-admin/api/subscriptions/1', ['customer_name' => 'New Name'])
            ->assertStatus(409)
            ->assertExactJson(['detail' => 'جدول مشتریان آماده نیست.']);
    }

    /** A missing row is the 404, and nothing is written. */
    public function test_a_missing_subscription_update_answers_404(): void
    {
        $this->asMasterAdmin();
        $this->db->objectId = '12345';
        $this->db->rows = [];

        $this->patchJson('/master-admin/api/subscriptions/999', ['customer_name' => 'New Name'])
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'اشتراک یافت نشد.']);

        $this->assertSame([], $this->recordedQueriesContaining('UPDATE dbo.customer_subscriptions'));
    }

    /**
     * The success path: the row is updated, the linked users are synchronised,
     * and the answer is the Python's own message.
     */
    public function test_the_subscription_update_saves_and_synchronizes(): void
    {
        $this->asMasterAdmin();
        $this->db->objectId = '12345';
        $this->db->rows = [(object) ['customer_id' => 'C1', 'customer_name' => 'Old Name']];
        $this->db->updateCount = 1;

        $this->patchJson('/master-admin/api/subscriptions/1', [
            'customer_name' => 'New Name',
            'max_users' => 20,
            'starts_at' => '2026-01-01',
            'expires_at' => '2027-01-01',
            'payment_method' => 'cash',
        ])->assertOk()->assertExactJson(['success' => true, 'message' => 'اطلاعات مشتری ذخیره شد.']);

        // The assignments, in the allowed-fields order, with the id last.
        $updates = $this->recordedQueriesContaining('UPDATE dbo.customer_subscriptions');
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('customer_name = ?, starts_at = ?, expires_at = ?, max_users = ?, payment_method = ?', $updates[0][0]);
        $this->assertStringContainsString('updated_at = SYSUTCDATETIME()', $updates[0][0]);
        $this->assertSame(['New Name', '2026-01-01', '2027-01-01', 20, 'cash', 1], $updates[0][1]);

        // The user-table sync, with the old customer id as the predicate.
        $syncs = $this->recordedQueriesContaining('UPDATE user_table');
        $this->assertCount(1, $syncs);
        $this->assertSame(['C1', 'New Name', 'C1'], $syncs[0][1]);
    }

    /**
     * A body that is not a JSON object is a 500, as in the Python — where
     * `await request.json()` (or `key in data` for a scalar body) raised
     * outside the `try` and Starlette answered a plain-text 500.  Laravel's
     * exception handler renders its own JSON 500 here; the status is the same,
     * the body is the framework's.
     */
    public function test_the_subscription_update_rejects_a_body_that_is_not_a_json_object(): void
    {
        $this->asMasterAdmin();

        $this->call('PATCH', '/master-admin/api/subscriptions/1', [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'not json')
            ->assertStatus(500);
    }

    // ── Audit logs ──────────────────────────────────────────────────────────

    /**
     * Deleting an audit row writes the `admin_actions` entry only when a row
     * actually went — the feed records what happened, not what was attempted.
     */
    public function test_deleting_an_audit_log_writes_an_admin_action_only_when_a_row_went(): void
    {
        $this->asMasterAdmin();

        $this->db->deleteCount = 1;
        $this->delete('/master-admin/api/audit-logs/HST-1')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('ali', $inserts[0][1][1]);
        $this->assertSame('delete_audit_log', $inserts[0][1][2]);
        $this->assertSame('audit_log', $inserts[0][1][4]);
        $this->assertSame('HST-1', $inserts[0][1][5]);
        $this->assertSame('حذف رکورد لاگ حسابرسی', $inserts[0][1][6]);

        $this->db->deleteCount = 0;
        $this->delete('/master-admin/api/audit-logs/HST-1')
            ->assertOk()
            ->assertExactJson(['success' => false]);

        $this->assertCount(1, $this->recordedQueriesContaining('admin_actions'), 'no second action for a deletion that deleted nothing');
    }

    // ── Users ───────────────────────────────────────────────────────────────

    /**
     * Disabling an account revokes the sessions it already holds — a signed
     * cookie cannot be revoked otherwise — and the response carries the number.
     */
    public function test_toggling_a_user_off_revokes_their_sessions_and_audits_it(): void
    {
        $this->asMasterAdmin();
        $this->db->rows = [(object) ['is_active' => 'active']];
        $this->db->updateCount = 2;

        $this->postJson('/master-admin/api/users/ali/toggle-status')
            ->assertOk()
            ->assertExactJson(['success' => true, 'new_status' => 'disabled', 'sessions_revoked' => 2]);

        // The revocation, by the trimmed username, credited to the admin.
        $revokes = $this->recordedQueriesContaining('terminated_by');
        $this->assertCount(1, $revokes);
        $this->assertSame('ali', $revokes[0][1][2], 'terminated_by is the admin');
        $this->assertSame('ali', $revokes[0][1][3], 'the username predicate is the trimmed username');

        // The audit pair, with the before/after snapshot.
        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('toggle_user_status', $inserts[0][1][2]);
        $this->assertSame('ali', $inserts[0][1][3]);
        $this->assertSame('user', $inserts[0][1][4]);
        $this->assertSame('تغییر وضعیت به disabled', $inserts[0][1][6]);
        $this->assertSame('{"is_active":"active"}', $inserts[0][1][7]);
        $this->assertSame('{"is_active":"disabled","sessions_revoked":2}', $inserts[0][1][8]);
    }

    /**
     * Enabling an account revokes nothing — the asymmetry is the Python's.
     */
    public function test_toggling_a_user_back_on_revokes_nothing(): void
    {
        $this->asMasterAdmin();
        $this->db->rows = [(object) ['is_active' => 'disabled']];

        $this->postJson('/master-admin/api/users/ali/toggle-status')
            ->assertOk()
            ->assertExactJson(['success' => true, 'new_status' => 'active', 'sessions_revoked' => 0]);

        $this->assertSame([], $this->recordedQueriesContaining('terminated_by'));
    }

    /** A NULL `is_active` reads as active, exactly as the Python's `or "active"` did. */
    public function test_a_null_is_active_reads_as_active(): void
    {
        $this->asMasterAdmin();
        $this->db->rows = [(object) ['is_active' => null]];

        $this->postJson('/master-admin/api/users/ali/toggle-status')
            ->assertOk()
            ->assertJsonPath('new_status', 'disabled');
    }

    /** A missing user is the FastAPI 404, and nothing is written. */
    public function test_a_missing_user_toggle_answers_404(): void
    {
        $this->asMasterAdmin();
        $this->db->rows = [];

        $this->postJson('/master-admin/api/users/ghost/toggle-status')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'کاربر یافت نشد.']);

        $this->assertSame([], $this->recordedQueriesContaining('admin_actions'));
    }

    /**
     * The role must be exactly `user` or `admin` after the Python's
     * `str(...).strip().lower()` — a missing key, a null and a number are all
     * rejected with the one message.
     *
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function invalidRoles(): array
    {
        return [
            'missing role' => [[]],
            'null role' => [['role' => null]],
            'numeric role' => [['role' => 123]],
            'unknown role' => [['role' => 'superadmin']],
            'mixed case unknown' => [['role' => 'Admin2']],
        ];
    }

    #[DataProvider('invalidRoles')]
    public function test_changing_a_role_validates_the_role(array $body): void
    {
        $this->asMasterAdmin();

        $this->postJson('/master-admin/api/users/ali/change-role', $body)
            ->assertStatus(400)
            ->assertExactJson(['detail' => 'نقش معتبر نیست.']);
    }

    /**
     * A role change revokes in both directions and quotes the **raw** stored
     * role — `nchar(10)` padding and all — in the audit description.
     */
    public function test_changing_a_role_revokes_sessions_and_quotes_the_raw_old_role(): void
    {
        $this->asMasterAdmin();
        $oldRole = 'user      ';
        $this->db->rows = [(object) ['role' => $oldRole]];
        $this->db->updateCount = 1;

        $this->postJson('/master-admin/api/users/ali/change-role', ['role' => 'admin'])
            ->assertOk()
            ->assertExactJson(['success' => true, 'sessions_revoked' => 1]);

        $revokes = $this->recordedQueriesContaining('terminated_by');
        $this->assertCount(1, $revokes);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('change_role', $inserts[0][1][2]);
        // The raw stored role — `nchar(10)` padding and all — is quoted in both
        // the description and the before-snapshot.
        $this->assertSame("تغییر نقش از {$oldRole} به admin", $inserts[0][1][6]);
        $this->assertSame('{"role":"user      "}', $inserts[0][1][7]);
        $this->assertSame('{"role":"admin","sessions_revoked":1}', $inserts[0][1][8]);
    }

    /** A missing user is the FastAPI 404. */
    public function test_a_missing_user_role_change_answers_404(): void
    {
        $this->asMasterAdmin();
        $this->db->rows = [];

        $this->postJson('/master-admin/api/users/ghost/change-role', ['role' => 'admin'])
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'کاربر یافت نشد.']);
    }

    /** A body that is not a JSON object is a 500, as in the Python. */
    public function test_changing_a_role_rejects_a_body_that_is_not_a_json_object(): void
    {
        $this->asMasterAdmin();

        $this->call('POST', '/master-admin/api/users/ali/change-role', [], [], [], ['HTTP_ACCEPT' => 'application/json'], 'not json')
            ->assertStatus(500);
    }

    // ── Sessions ────────────────────────────────────────────────────────────

    /**
     * Terminating a session reports the revocation, and the audit row is
     * written only when a session was actually closed.
     */
    public function test_terminating_a_session_reports_the_revocation(): void
    {
        $this->asMasterAdmin();

        $this->db->updateCount = 1;
        $this->postJson('/master-admin/api/sessions/abc/terminate')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('terminate_session', $inserts[0][1][2]);
        $this->assertSame('session', $inserts[0][1][4]);
        $this->assertSame('abc', $inserts[0][1][5]);
        $this->assertSame('خاتمه اجباری نشست', $inserts[0][1][6]);

        $this->db->updateCount = 0;
        $this->postJson('/master-admin/api/sessions/abc/terminate')
            ->assertOk()
            ->assertExactJson(['success' => false]);

        $this->assertCount(1, $this->recordedQueriesContaining('admin_actions'));
    }

    /** Deleting a session record removes it from the history too. */
    public function test_deleting_a_session_record_reports_the_deletion(): void
    {
        $this->asMasterAdmin();

        $this->db->deleteCount = 1;
        $this->delete('/master-admin/api/sessions/abc')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('delete_session_record', $inserts[0][1][2]);
        $this->assertSame('حذف رکورد نشست', $inserts[0][1][6]);

        $this->db->deleteCount = 0;
        $this->delete('/master-admin/api/sessions/abc')
            ->assertOk()
            ->assertExactJson(['success' => false]);
    }

    /**
     * Terminating every session keeps the master administrators — the operator
     * who pressed the button keeps the control plane they would need to undo it.
     */
    public function test_terminating_all_sessions_keeps_the_master_administrators(): void
    {
        $this->asMasterAdmin();
        $this->db->updateCount = 3;

        $this->postJson('/master-admin/api/sessions/terminate-all')
            ->assertOk()
            ->assertExactJson(['success' => true, 'terminated' => 3, 'kept_usernames' => ['ali']]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('terminate_all_sessions', $inserts[0][1][2]);
        $this->assertSame('*', $inserts[0][1][5]);
        $this->assertSame('خاتمه گروهی همه نشست‌های فعال (3 نشست)', $inserts[0][1][6]);
        $this->assertSame('{"terminated":3,"kept_usernames":["ali"]}', $inserts[0][1][8]);
    }

    /** Deleting every session record is the destructive counterpart. */
    public function test_deleting_all_sessions_reports_the_count(): void
    {
        $this->asMasterAdmin();
        $this->db->deleteCount = 7;

        $this->delete('/master-admin/api/sessions')
            ->assertOk()
            ->assertExactJson(['success' => true, 'deleted' => 7]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('delete_all_session_records', $inserts[0][1][2]);
        $this->assertSame('حذف گروهی همه رکوردهای نشست (7 رکورد)', $inserts[0][1][6]);
        $this->assertSame('{"deleted":7}', $inserts[0][1][8]);
    }

    // ── Password resets ─────────────────────────────────────────────────────

    /** The list paginates and serialises the attempt counters as integers. */
    public function test_the_password_reset_list_paginates(): void
    {
        $this->asMasterAdmin();
        $this->db->count = 2;
        $this->db->filterCounts = ['approved' => 1];
        $this->db->rows = [
            (object) [
                'request_id' => 'HST-20260930-0A1B2C3D', 'username' => 'ali', 'ip_address' => '127.0.0.1',
                'status' => 'pending', 'code_attempts' => '0', 'max_attempts' => '5',
                'approved_by' => null, 'approved_at' => null, 'completed_at' => null,
                'created_at' => '2026-09-30 08:53:41.864',
            ],
            (object) [
                'request_id' => 'HST-20260929-1B2C3D4E', 'username' => 'reza', 'ip_address' => '127.0.0.1',
                'status' => 'approved', 'code_attempts' => '2', 'max_attempts' => '5',
                'approved_by' => 'ali', 'approved_at' => '2026-09-29 10:00:00', 'completed_at' => null,
                'created_at' => '2026-09-29 09:00:00',
            ],
        ];

        $response = $this->get('/master-admin/api/password-resets');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(['success', 'data', 'total', 'page', 'per_page', 'pages'], array_keys($response->json()));
        $this->assertSame(2, $response->json('total'));

        $rows = $response->json('data');
        $this->assertSame('HST-20260930-0A1B2C3D', $rows[0]['request_id']);
        $this->assertSame(0, $rows[0]['code_attempts']);
        $this->assertSame(5, $rows[0]['max_attempts']);
        $this->assertSame('2026-09-30T08:53:41.864000', $rows[0]['created_at']);
        $this->assertNull($rows[0]['approved_by']);
        $this->assertArrayNotHasKey('recovery_code', $rows[0], 'the code digest must never be published');

        // The status filter is an exact match.
        $this->get('/master-admin/api/password-resets?status=approved')->assertOk()->assertJsonPath('total', 1);
    }

    /**
     * Approval returns the one-time code **once**, to the administrator, and
     * writes the audit row.
     */
    public function test_approving_a_password_reset_returns_the_one_time_code(): void
    {
        config(['hastama.recovery.hmac_secret' => 'test-secret']);
        $this->asMasterAdmin();
        $this->db->updateCount = 1;

        $response = $this->postJson('/master-admin/api/password-resets/HST-20260930-0A1B2C3D/approve');

        $response->assertOk()->assertJson(['success' => true]);

        $code = $response->json('code');
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{8}$/', $code);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $response->json('expires'));

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('approve_password_reset', $inserts[0][1][2]);
        $this->assertSame('password_reset', $inserts[0][1][4]);
        $this->assertSame('HST-20260930-0A1B2C3D', $inserts[0][1][5]);
        $this->assertSame('تأیید درخواست بازیابی رمز عبور', $inserts[0][1][6]);
    }

    /** An unknown or already-processed request is a 200 failure envelope, not an error. */
    public function test_approving_an_already_processed_request_writes_no_admin_action(): void
    {
        config(['hastama.recovery.hmac_secret' => 'test-secret']);
        $this->asMasterAdmin();
        $this->db->updateCount = 0;

        $this->postJson('/master-admin/api/password-resets/HST-20260930-0A1B2C3D/approve')
            ->assertOk()
            ->assertExactJson(['success' => false, 'message' => 'درخواست یافت نشد یا قبلاً پردازش شده است.']);

        $this->assertSame([], $this->recordedQueriesContaining('admin_actions'));
    }

    /**
     * Without `HASTAMA_HMAC_SECRET` the flow fails closed — the code is stored
     * as an HMAC digest, so without a key the digest would certify nothing.
     *
     * `app.debug` is switched off as well: the service's development fallback
     * (an ephemeral per-process key) is deliberately active in the testing
     * environment, and the fail-closed path is the one the deployment runs.
     */
    public function test_approving_without_the_hmac_secret_fails_closed(): void
    {
        config(['hastama.recovery.hmac_secret' => '', 'app.debug' => false]);
        $this->asMasterAdmin();

        $this->postJson('/master-admin/api/password-resets/HST-20260930-0A1B2C3D/approve')
            ->assertOk()
            ->assertExactJson([
                'success' => false,
                'message' => 'کلید HASTAMA_HMAC_SECRET تنظیم نشده است؛ بازیابی رمز عبور غیرفعال است.',
                'code_unavailable' => true,
            ]);
    }

    /** Rejecting reports the result and audits only a real rejection. */
    public function test_rejecting_a_password_reset_reports_the_result(): void
    {
        $this->asMasterAdmin();

        $this->db->updateCount = 1;
        $this->postJson('/master-admin/api/password-resets/HST-20260930-0A1B2C3D/reject')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('reject_password_reset', $inserts[0][1][2]);
        $this->assertSame('رد درخواست بازیابی رمز عبور', $inserts[0][1][6]);

        $this->db->updateCount = 0;
        $this->postJson('/master-admin/api/password-resets/HST-20260930-0A1B2C3D/reject')
            ->assertOk()
            ->assertExactJson(['success' => false]);
    }

    /** Deleting a request removes the row — and with it the code digest. */
    public function test_deleting_a_password_reset_reports_the_result(): void
    {
        $this->asMasterAdmin();

        $this->db->deleteCount = 1;
        $this->delete('/master-admin/api/password-resets/HST-20260930-0A1B2C3D')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('delete_password_reset', $inserts[0][1][2]);
        $this->assertSame('حذف رکورد درخواست بازیابی رمز عبور', $inserts[0][1][6]);

        $this->db->deleteCount = 0;
        $this->delete('/master-admin/api/password-resets/HST-20260930-0A1B2C3D')
            ->assertOk()
            ->assertExactJson(['success' => false]);
    }

    // ── Security events ─────────────────────────────────────────────────────

    /** The list paginates with the two exact-match filters. */
    public function test_the_security_event_list_paginates(): void
    {
        $this->asMasterAdmin();
        $this->db->count = 1;
        $this->db->filterCounts = ['high' => 1, 'low' => 0, 'resolved' => 0];
        $this->db->rows = [(object) [
            'event_id' => 'HST-20260930-0A1B2C3D', 'event_type' => 'AUTHORIZATION', 'severity' => 'high',
            'username' => 'ordinary', 'ip_address' => '127.0.0.1',
            'description' => 'non-master admin attempted to access master-admin API',
            'status' => 'open', 'resolved_by' => null, 'resolved_at' => null,
            'created_at' => '2026-09-30 08:53:41.864',
        ]];

        $response = $this->get('/master-admin/api/security');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, $response->json('total'));

        $row = $response->json('data.0');
        $this->assertSame('HST-20260930-0A1B2C3D', $row['event_id']);
        $this->assertSame('AUTHORIZATION', $row['event_type']);
        $this->assertSame('2026-09-30T08:53:41.864000', $row['created_at']);

        $this->get('/master-admin/api/security?severity=high')->assertOk()->assertJsonPath('total', 1);
        $this->get('/master-admin/api/security?severity=low')->assertOk()->assertJsonPath('total', 0);
        $this->get('/master-admin/api/security?status=resolved')->assertOk()->assertJsonPath('total', 0);
    }

    /**
     * Resolving an event succeeds even when the id does not exist — the Python
     * never checked `rowcount` — and the audit row is written unconditionally.
     */
    public function test_resolving_a_security_event_succeeds_even_for_an_unknown_id(): void
    {
        $this->asMasterAdmin();

        $this->postJson('/master-admin/api/security/HST-19700101-DEADBEEF/resolve', ['status' => 'closed'])
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $updates = $this->recordedQueriesContaining('UPDATE security_events');
        $this->assertCount(1, $updates);
        $this->assertSame(['closed', 'ali', 'HST-19700101-DEADBEEF'], $updates[0][1]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('resolve_security_event', $inserts[0][1][2]);
        $this->assertSame('security_event', $inserts[0][1][4]);
        $this->assertSame('HST-19700101-DEADBEEF', $inserts[0][1][5]);
        $this->assertSame('تغییر وضعیت به closed', $inserts[0][1][6]);
    }

    /**
     * The body is optional: a non-JSON request — the ordinary way to call this —
     * defaults the new status to `resolved`.
     */
    public function test_resolving_a_security_event_defaults_the_status_to_resolved(): void
    {
        $this->asMasterAdmin();

        $this->post('/master-admin/api/security/HST-1/resolve')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $updates = $this->recordedQueriesContaining('UPDATE security_events');
        $this->assertCount(1, $updates);
        $this->assertSame('resolved', $updates[0][1][0]);
    }

    /** Deleting a security event reports the result and audits only a real deletion. */
    public function test_deleting_a_security_event_reports_the_result(): void
    {
        $this->asMasterAdmin();

        $this->db->deleteCount = 1;
        $this->delete('/master-admin/api/security/HST-1')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('delete_security_event', $inserts[0][1][2]);
        $this->assertSame('حذف رکورد رویداد امنیتی', $inserts[0][1][6]);

        $this->db->deleteCount = 0;
        $this->delete('/master-admin/api/security/HST-1')
            ->assertOk()
            ->assertExactJson(['success' => false]);
    }

    // ── System errors ───────────────────────────────────────────────────────

    /** The list paginates with the three exact-match filters. */
    public function test_the_error_list_paginates(): void
    {
        $this->asMasterAdmin();
        $this->db->count = 1;
        $this->db->filterCounts = ['application' => 1, 'high' => 0];
        $this->db->rows = [(object) [
            'error_id' => 'HST-20260930-0A1B2C3D', 'error_type' => 'application', 'severity' => 'medium',
            'message' => 'boom', 'endpoint' => '/api/x', 'method' => 'GET', 'username' => null,
            'ip_address' => '127.0.0.1', 'occurrences' => '3', 'status' => 'open',
            'resolved_at' => null, 'first_seen' => '2026-09-30 08:53:41.864', 'last_seen' => '2026-09-30 09:53:41.864',
        ]];

        $response = $this->get('/master-admin/api/errors');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, $response->json('total'));

        $row = $response->json('data.0');
        $this->assertSame('HST-20260930-0A1B2C3D', $row['error_id']);
        $this->assertSame(3, $row['occurrences'], 'the driver returns occurrences as a string');

        // Ordered by first_seen DESC, unlike every other list's created_at.
        $this->get('/master-admin/api/errors?error_type=application')->assertOk()->assertJsonPath('total', 1);
        $this->get('/master-admin/api/errors?severity=high')->assertOk()->assertJsonPath('total', 0);
    }

    /** Resolving an error succeeds even for an unknown id, and audits it. */
    public function test_resolving_an_error_succeeds_even_for_an_unknown_id(): void
    {
        $this->asMasterAdmin();

        $this->postJson('/master-admin/api/errors/HST-19700101-DEADBEEF/resolve', ['status' => 'closed'])
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $updates = $this->recordedQueriesContaining('UPDATE system_errors');
        $this->assertCount(1, $updates);
        $this->assertSame(['closed', 'ali', 'HST-19700101-DEADBEEF'], $updates[0][1]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('resolve_error', $inserts[0][1][2]);
        $this->assertSame('system_error', $inserts[0][1][4]);
        $this->assertSame('تغییر وضعیت خطا به closed', $inserts[0][1][6]);
    }

    /** Deleting an error reports the result and audits only a real deletion. */
    public function test_deleting_an_error_reports_the_result(): void
    {
        $this->asMasterAdmin();

        $this->db->deleteCount = 1;
        $this->delete('/master-admin/api/errors/HST-1')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(1, $inserts);
        $this->assertSame('delete_system_error', $inserts[0][1][2]);
        $this->assertSame('حذف رکورد خطای سیستم', $inserts[0][1][6]);

        $this->db->deleteCount = 0;
        $this->delete('/master-admin/api/errors/HST-1')
            ->assertOk()
            ->assertExactJson(['success' => false]);
    }

    // ── Admin actions ───────────────────────────────────────────────────────

    /** The list paginates with the admin partial match and the exact action match. */
    public function test_the_admin_action_list_paginates(): void
    {
        $this->asMasterAdmin();
        $this->db->count = 1;
        $this->db->filterCounts = ['%al%' => 1, 'change_role' => 0];
        $this->db->rows = [(object) [
            'action_id' => 'HST-20260930-0A1B2C3D', 'admin_username' => 'ali', 'action' => 'toggle_user_status',
            'target_username' => 'reza', 'target_type' => 'user', 'target_id' => null,
            'description' => 'تغییر وضعیت به disabled', 'ip_address' => '127.0.0.1',
            'created_at' => '2026-09-30 08:53:41.864',
        ]];

        $response = $this->get('/master-admin/api/admin-actions');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertSame(1, $response->json('total'));
        $this->assertSame('toggle_user_status', $response->json('data.0.action'));

        $this->get('/master-admin/api/admin-actions?admin_username=al')->assertOk()->assertJsonPath('total', 1);
        $this->get('/master-admin/api/admin-actions?action=change_role')->assertOk()->assertJsonPath('total', 0);
    }

    /**
     * Deleting an admin action writes another one — the feed loses the entry but
     * keeps the fact.
     */
    public function test_deleting_an_admin_action_reports_the_result(): void
    {
        $this->asMasterAdmin();

        $this->db->deleteCount = 1;
        $this->delete('/master-admin/api/admin-actions/HST-1')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertCount(2, $inserts, 'the delete itself is audited');
        $this->assertSame('delete_admin_action', $inserts[1][1][2]);
        $this->assertSame('admin_action', $inserts[1][1][4]);
        $this->assertSame('حذف رکورد عملیات مدیریتی', $inserts[1][1][6]);

        $this->db->deleteCount = 0;
        $this->delete('/master-admin/api/admin-actions/HST-1')
            ->assertOk()
            ->assertExactJson(['success' => false]);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Sign in as a master administrator, with the session flags the login flow
     * sets and a live registry row (the fake connection's `sessionRows`).
     */
    private function asMasterAdmin(): static
    {
        $this->actingAs(new User(['username' => 'ali']));

        return $this->withSession([
            'username' => 'ali',
            'sid' => 'test-session-token',
            'is_admin' => true,
            'is_master_admin' => true,
        ]);
    }

    /**
     * Every recorded statement whose SQL contains `$needle`.
     *
     * @return array<int, array{0: string, 1: array<int, mixed>}>
     */
    private function recordedQueriesContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->db->queries,
            static fn (array $query): bool => stripos($query[0], $needle) !== false,
        ));
    }

    /**
     * Two subscription fixtures relative to today, so the computed statuses do
     * not depend on the date the suite happens to run.
     *
     * @return array<int, object>
     */
    private function subscriptionRows(): array
    {
        $today = CarbonImmutable::now('UTC')->startOfDay();

        return [
            (object) [
                'id' => '1', 'customer_id' => '1', 'customer_code' => 'C1', 'customer_name' => 'Acme',
                'contact_name' => 'Ali', 'contact_email' => 'a@acme.com', 'contact_phone' => '021',
                'plan_name' => 'Gold', 'subscription_status' => 'active',
                'purchased_at' => $today->subDays(200)->format('Y-m-d H:i:s'),
                'starts_at' => $today->subDays(100)->format('Y-m-d H:i:s'),
                'expires_at' => $today->addDays(15)->format('Y-m-d H:i:s'),
                'max_users' => '10', 'price' => '100.50', 'currency' => 'IRR',
                'payment_method' => 'cash', 'payment_reference' => 'P1', 'invoice_number' => 'I1',
                'notes' => null,
                'created_at' => $today->subDays(200)->format('Y-m-d H:i:s'),
                'updated_at' => $today->subDays(100)->format('Y-m-d H:i:s'),
            ],
            (object) [
                'id' => '2', 'customer_id' => '2', 'customer_code' => 'C2', 'customer_name' => 'Beta',
                'contact_name' => 'Reza', 'contact_email' => 'b@beta.com', 'contact_phone' => '021',
                'plan_name' => 'Silver', 'subscription_status' => 'active',
                'purchased_at' => $today->subDays(200)->format('Y-m-d H:i:s'),
                'starts_at' => $today->subDays(100)->format('Y-m-d H:i:s'),
                'expires_at' => $today->subDays(10)->format('Y-m-d H:i:s'),
                'max_users' => '5', 'price' => '50', 'currency' => 'IRR',
                'payment_method' => 'check', 'payment_reference' => 'P2', 'invoice_number' => 'I2',
                'notes' => null,
                'created_at' => $today->subDays(200)->format('Y-m-d H:i:s'),
                'updated_at' => $today->subDays(100)->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * The declared SQL types, transcribed from `docs/migration/DATABASE_SCHEMA.md`.
     *
     * Seeded into `LegacySchema`'s cache because the serializer cannot ask the
     * fake connection for them — and the whole point of the layer is that
     * `pdo_sqlsrv` hands back strings where `pyodbc` handed back integers,
     * booleans and datetimes.
     */
    private function seedSchemaTypes(): void
    {
        $property = new ReflectionProperty(LegacySchema::class, 'types');

        $property->setValue(null, [
            'customer_subscriptions' => [
                'id' => 'bigint', 'customer_id' => 'nvarchar', 'customer_code' => 'nvarchar',
                'customer_name' => 'nvarchar', 'contact_name' => 'nvarchar', 'contact_email' => 'nvarchar',
                'contact_phone' => 'nvarchar', 'plan_name' => 'nvarchar', 'subscription_status' => 'nvarchar',
                'purchased_at' => 'datetime2', 'starts_at' => 'datetime2', 'expires_at' => 'datetime2',
                'max_users' => 'int', 'price' => 'decimal', 'currency' => 'char',
                'payment_method' => 'nvarchar', 'payment_reference' => 'nvarchar',
                'invoice_number' => 'nvarchar', 'notes' => 'nvarchar', 'created_at' => 'datetime2',
                'updated_at' => 'datetime2',
            ],
            'user_table' => [
                'id' => 'int', 'username' => 'nvarchar', 'name' => 'nvarchar', 'last_name' => 'nvarchar',
                'department' => 'nvarchar', 'work_hours' => 'nvarchar', 'substitute' => 'nvarchar',
                'role' => 'nchar', 'hozoor_num' => 'nchar', 'is_active' => 'nvarchar',
                'last_login' => 'datetime2', 'failed_login_count' => 'int',
                'password_changed_at' => 'datetime2', 'customer_id' => 'nvarchar',
                'customer_name' => 'nvarchar',
            ],
            'user_sessions' => [
                'id' => 'bigint', 'session_key' => 'nvarchar', 'username' => 'nvarchar',
                'ip_address' => 'varchar', 'user_agent' => 'nvarchar', 'login_at' => 'datetime2',
                'last_activity' => 'datetime2', 'logout_at' => 'datetime2', 'is_active' => 'bit',
                'terminated_by' => 'nvarchar',
            ],
            'password_reset_requests' => [
                'id' => 'bigint', 'request_id' => 'varchar', 'username' => 'nvarchar',
                'ip_address' => 'varchar', 'user_agent' => 'nvarchar', 'status' => 'varchar',
                'recovery_code' => 'varchar', 'code_expires_at' => 'datetime2', 'code_attempts' => 'int',
                'max_attempts' => 'int', 'approved_by' => 'nvarchar', 'approved_at' => 'datetime2',
                'completed_at' => 'datetime2', 'created_at' => 'datetime2', 'updated_at' => 'datetime2',
            ],
            'security_events' => [
                'id' => 'bigint', 'event_id' => 'varchar', 'event_type' => 'varchar',
                'severity' => 'varchar', 'username' => 'nvarchar', 'ip_address' => 'varchar',
                'description' => 'nvarchar', 'metadata' => 'nvarchar', 'status' => 'varchar',
                'resolved_by' => 'nvarchar', 'resolved_at' => 'datetime2', 'created_at' => 'datetime2',
            ],
            'system_errors' => [
                'id' => 'bigint', 'error_id' => 'varchar', 'error_type' => 'varchar',
                'severity' => 'varchar', 'message' => 'nvarchar', 'detail' => 'nvarchar',
                'endpoint' => 'varchar', 'method' => 'varchar', 'username' => 'nvarchar',
                'ip_address' => 'varchar', 'request_id' => 'varchar', 'session_id' => 'nvarchar',
                'status' => 'varchar', 'occurrences' => 'int', 'first_seen' => 'datetime2',
                'last_seen' => 'datetime2', 'resolved_by' => 'nvarchar', 'resolved_at' => 'datetime2',
            ],
            'admin_actions' => [
                'id' => 'bigint', 'action_id' => 'varchar', 'admin_username' => 'nvarchar',
                'action' => 'varchar', 'target_username' => 'nvarchar', 'target_type' => 'varchar',
                'target_id' => 'nvarchar', 'description' => 'nvarchar', 'before_data' => 'nvarchar',
                'after_data' => 'nvarchar', 'ip_address' => 'varchar', 'request_id' => 'varchar',
                'created_at' => 'datetime2',
            ],
            'audit_logs' => [
                'id' => 'bigint', 'event_id' => 'varchar', 'event_type' => 'varchar',
                'action' => 'varchar', 'username' => 'nvarchar', 'role' => 'varchar',
                'module' => 'varchar', 'resource_type' => 'varchar', 'resource_id' => 'nvarchar',
                'request_id' => 'varchar', 'session_id' => 'nvarchar', 'ip_address' => 'varchar',
                'user_agent' => 'nvarchar', 'status' => 'varchar', 'severity' => 'varchar',
                'before_data' => 'nvarchar', 'after_data' => 'nvarchar', 'metadata' => 'nvarchar',
                'error_id' => 'varchar', 'created_at' => 'datetime2',
            ],
        ]);
    }
}

/**
 * A programmable stand-in for the SQL Server connection.
 *
 * Every statement the application issues is recorded with its bindings, and the
 * answer is derived from the SQL rather than from a queue — so a test states
 * what the database said, not which order the queries happened to run in.
 *
 * The PDO is real but never used: the base constructor wires up the query
 * grammar from the driver name, and every execution method is overridden.
 */
class FakeLegacyConnection extends Connection
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $queries = [];

    /** The `OBJECT_ID()` result — `null` stands for "the table is not there". */
    public ?string $objectId = '12345';

    /** The row count behind a plain `COUNT(*)` with no filter. */
    public int $count = 0;

    /**
     * Filtered counts, keyed by the value of the filter's first binding.
     *
     * The fake does not evaluate SQL, so a `COUNT(*) ... WHERE status = ?`
     * cannot know its own answer — the test states it here, and the responder
     * looks the binding up.  An unknown filter value falls back to {@see $count},
     * which is also the answer for an unfiltered count.
     *
     * @var array<string, int>
     */
    public array $filterCounts = [];

    /** The row count behind the per-row seat-usage `COUNT(*) ... FROM user_table`. */
    public int $seatCount = 0;

    /** The rows behind the main `SELECT`s and the single-row lookups. */
    public array $rows = [];

    /** The rows behind the subscription detail's linked-user `SELECT`. */
    public array $usersRows = [];

    /** The columns `INFORMATION_SCHEMA` reports for `user_table`. */
    public array $userTableColumns = [];

    /** The session row behind the registry's `SELECT`. */
    public array $sessionRows = [];

    public int $updateCount = 1;

    public int $deleteCount = 1;

    public function __construct()
    {
        parent::__construct(new PDO('sqlite::memory:'), 'userDB', '', ['driver' => 'sqlite']);
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

        return true;
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

    /**
     * The canned answer for one statement, matched on the SQL's own shape.
     *
     * The order is the precedence: the seat-usage count must be recognised
     * before the generic `COUNT(*)`, and the linked-user select before the
     * single-row `user_table` lookups.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function respond(string $sql, array $bindings = []): mixed
    {
        return match (true) {
            stripos($sql, 'OBJECT_ID') !== false => $this->objectId !== null
                ? (object) ['object_id' => $this->objectId]
                : null,
            stripos($sql, 'INFORMATION_SCHEMA') !== false => $this->userTableColumns,
            stripos($sql, 'COUNT(*)') !== false && stripos($sql, 'user_table') !== false => (object) ['total' => $this->seatCount],
            stripos($sql, 'COUNT(*)') !== false => (object) ['total' => $this->countFor($bindings)],
            stripos($sql, 'user_sessions') !== false => $this->sessionRows,
            stripos($sql, 'customer_id') !== false && stripos($sql, 'user_table') !== false => $this->usersRows,
            stripos($sql, 'user_table') !== false => $this->rows,
            stripos($sql, 'customer_subscriptions') !== false => $this->rows,
            stripos($sql, 'SELECT') !== false => $this->rows,
            default => null,
        };
    }

    /**
     * The answer for a `COUNT(*)` — the filtered count when the filter's value
     * is one the test declared, the plain count otherwise.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function countFor(array $bindings): int
    {
        if ($bindings === []) {
            return $this->count;
        }

        $needle = $bindings[0];

        return $this->filterCounts[is_string($needle) ? $needle : ''] ?? $this->count;
    }
}
