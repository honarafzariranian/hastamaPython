<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Legacy\LegacySchema;
use Illuminate\Database\Connection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Tests\TestCase;

/**
 * The master-admin settings surface, offline.
 *
 * Every test runs without the live database.  The two things these endpoints
 * do are both observable without SQL Server:
 *
 * * the **HTTP contract** (guard, validation, status codes, body shapes),
 *   which is decided before or instead of any query; and
 * * the **side effects**, which are writes.  The writes go through the real
 *   `AuditLogger` and the real support services into a
 *   {@see FakeSettingsConnection} that records every statement and its bindings.
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
final class MasterAdminSettingsTest extends TestCase
{
    private FakeSettingsConnection $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new FakeSettingsConnection;

        DB::extend('legacy', fn () => $this->db);
        config(['database.default' => 'legacy', 'database.connections.legacy' => ['driver' => 'fake']]);
        DB::purge('legacy');

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
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function routeActions(): array
    {
        return [
            'ma-settings.tickets.index' => ['/master-admin/api/tickets', 'GET', 'App\Http\Controllers\MasterAdmin\TicketController@index'],
            'ma-settings.tickets.stats' => ['/master-admin/api/tickets/stats', 'GET', 'App\Http\Controllers\MasterAdmin\TicketController@stats'],
            'ma-settings.tickets.show' => ['/master-admin/api/tickets/1', 'GET', 'App\Http\Controllers\MasterAdmin\TicketController@show'],
            'ma-settings.tickets.update' => ['/master-admin/api/tickets/1', 'PATCH', 'App\Http\Controllers\MasterAdmin\TicketController@update'],
            'ma-settings.tickets.reply' => ['/master-admin/api/tickets/1/reply', 'POST', 'App\Http\Controllers\MasterAdmin\TicketController@reply'],
            'ma-settings.tickets.destroy' => ['/master-admin/api/tickets/1', 'DELETE', 'App\Http\Controllers\MasterAdmin\TicketController@destroy'],
            'ma-settings.tickets.categories' => ['/master-admin/api/tickets/categories/all', 'GET', 'App\Http\Controllers\MasterAdmin\TicketController@categories'],
            'ma-settings.tickets.users' => ['/master-admin/api/tickets/users/all', 'GET', 'App\Http\Controllers\MasterAdmin\TicketController@users'],
            'ma-settings.config.index' => ['/master-admin/api/config', 'GET', 'App\Http\Controllers\MasterAdmin\SystemConfigController@index'],
            'ma-settings.config.store' => ['/master-admin/api/config', 'POST', 'App\Http\Controllers\MasterAdmin\SystemConfigController@store'],
            'ma-settings.lan-access.index' => ['/master-admin/api/lan-access', 'GET', 'App\Http\Controllers\MasterAdmin\LanAccessController@index'],
            'ma-settings.lan-access.store' => ['/master-admin/api/lan-access', 'POST', 'App\Http\Controllers\MasterAdmin\LanAccessController@store'],
            'ma-settings.lan-access.selftest' => ['/master-admin/api/lan-access/selftest', 'POST', 'App\Http\Controllers\MasterAdmin\LanAccessController@selftest'],
            'ma-settings.outage.index' => ['/master-admin/api/outage', 'GET', 'App\Http\Controllers\MasterAdmin\OutageController@index'],
            'ma-settings.outage.store' => ['/master-admin/api/outage', 'POST', 'App\Http\Controllers\MasterAdmin\OutageController@store'],
            'ma-settings.outage.check' => ['/master-admin/api/outage/check', 'POST', 'App\Http\Controllers\MasterAdmin\OutageController@check'],
            'ma-settings.outage.manual' => ['/master-admin/api/outage/manual', 'POST', 'App\Http\Controllers\MasterAdmin\OutageController@manual'],
            'ma-settings.iran-access.index' => ['/master-admin/api/iran-access', 'GET', 'App\Http\Controllers\MasterAdmin\IranAccessController@index'],
            'ma-settings.iran-access.store' => ['/master-admin/api/iran-access', 'POST', 'App\Http\Controllers\MasterAdmin\IranAccessController@store'],
            'ma-settings.iran-access.check' => ['/master-admin/api/iran-access/check', 'POST', 'App\Http\Controllers\MasterAdmin\IranAccessController@check'],
            'ma-settings.iran-access.refresh' => ['/master-admin/api/iran-access/refresh', 'POST', 'App\Http\Controllers\MasterAdmin\IranAccessController@refresh'],
            'ma-settings.iran-access.counters-reset' => ['/master-admin/api/iran-access/counters/reset', 'POST', 'App\Http\Controllers\MasterAdmin\IranAccessController@resetCounters'],
            'ma-settings.login-experience.index' => ['/master-admin/api/login-experience', 'GET', 'App\Http\Controllers\MasterAdmin\LoginExperienceController@index'],
            'ma-settings.login-experience.store' => ['/master-admin/api/login-experience', 'POST', 'App\Http\Controllers\MasterAdmin\LoginExperienceController@store'],
            'ma-settings.printers.index' => ['/master-admin/api/printers', 'GET', 'App\Http\Controllers\MasterAdmin\PrinterController@index'],
        ];
    }

    #[DataProvider('routeActions')]
    public function test_the_settings_routes_are_wired_to_their_controllers(string $uri, string $method, string $action): void
    {
        $request = Request::create($uri, $method);
        $route = collect(Route::getRoutes())->first(static fn ($route): bool => $route->matches($request));

        $this->assertNotNull($route, "no route is registered for {$uri}");
        $this->assertContains($method, $route->methods());
        $this->assertSame($action, $route->getActionName());
    }

    // ── The guard ───────────────────────────────────────────────────────────

    /**
     * Every settings route, anonymous.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function settingsRoutes(): array
    {
        return [
            'tickets index' => ['GET', '/master-admin/api/tickets'],
            'tickets stats' => ['GET', '/master-admin/api/tickets/stats'],
            'tickets show' => ['GET', '/master-admin/api/tickets/1'],
            'tickets update' => ['PATCH', '/master-admin/api/tickets/1'],
            'tickets reply' => ['POST', '/master-admin/api/tickets/1/reply'],
            'tickets destroy' => ['DELETE', '/master-admin/api/tickets/1'],
            'tickets categories' => ['GET', '/master-admin/api/tickets/categories/all'],
            'tickets users' => ['GET', '/master-admin/api/tickets/users/all'],
            'config index' => ['GET', '/master-admin/api/config'],
            'config store' => ['POST', '/master-admin/api/config'],
            'lan-access index' => ['GET', '/master-admin/api/lan-access'],
            'lan-access store' => ['POST', '/master-admin/api/lan-access'],
            'lan-access selftest' => ['POST', '/master-admin/api/lan-access/selftest'],
            'outage index' => ['GET', '/master-admin/api/outage'],
            'outage store' => ['POST', '/master-admin/api/outage'],
            'outage check' => ['POST', '/master-admin/api/outage/check'],
            'outage manual' => ['POST', '/master-admin/api/outage/manual'],
            'iran-access index' => ['GET', '/master-admin/api/iran-access'],
            'iran-access store' => ['POST', '/master-admin/api/iran-access'],
            'iran-access check' => ['POST', '/master-admin/api/iran-access/check'],
            'iran-access refresh' => ['POST', '/master-admin/api/iran-access/refresh'],
            'iran-access counters reset' => ['POST', '/master-admin/api/iran-access/counters/reset'],
            'login-experience index' => ['GET', '/master-admin/api/login-experience'],
            'login-experience store' => ['POST', '/master-admin/api/login-experience'],
            'printers index' => ['GET', '/master-admin/api/printers'],
        ];
    }

    #[DataProvider('settingsRoutes')]
    public function test_every_settings_route_refuses_an_anonymous_request_with_the_fastapi_body(string $method, string $uri): void
    {
        $response = $this->call($method, $uri, [], [], [], ['HTTP_ACCEPT' => 'application/json']);

        $response->assertStatus(401);
        $this->assertSame(['detail' => 'ورود لازم است.'], $response->json());
    }

    #[DataProvider('settingsRoutes')]
    public function test_every_settings_route_refuses_a_non_master_admin(string $method, string $uri): void
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

    // ── Validation ──────────────────────────────────────────────────────────

    /**
     * The tickets list query parameters are validated with FastAPI's 422 body.
     */
    public function test_the_tickets_list_validates_its_query_parameters(): void
    {
        $this->asMasterAdmin();

        $this->get('/master-admin/api/tickets?per_page=1')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'greater_than_equal')
            ->assertJsonPath('detail.0.loc', ['query', 'per_page']);

        $this->get('/master-admin/api/tickets?per_page=101')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'less_than_equal')
            ->assertJsonPath('detail.0.loc', ['query', 'per_page']);

        $this->get('/master-admin/api/tickets?page=0')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'greater_than_equal')
            ->assertJsonPath('detail.0.loc', ['query', 'page']);

        $this->get('/master-admin/api/tickets?search='.str_repeat('a', 101))
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_long')
            ->assertJsonPath('detail.0.loc', ['query', 'search']);
    }

    /**
     * The ticket path parameter is a declared `int` — a non-numeric id is
     * FastAPI's 422 with `loc: ["path", "ticket_id"]`.
     */
    public function test_the_ticket_path_parameter_is_validated(): void
    {
        $this->asMasterAdmin();

        $this->get('/master-admin/api/tickets/abc')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'int_parsing',
                'loc' => ['path', 'ticket_id'],
                'msg' => 'Input should be a valid integer, unable to parse string as an integer',
                'input' => 'abc',
            ]]]);
    }

    /**
     * The printers `refresh` parameter is a declared `bool`.
     */
    public function test_the_printers_refresh_parameter_is_validated(): void
    {
        $this->asMasterAdmin();

        $this->get('/master-admin/api/printers?refresh=maybe')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'bool_parsing')
            ->assertJsonPath('detail.0.loc', ['query', 'refresh']);
    }

    // ── Success shapes ──────────────────────────────────────────────────────

    /**
     * The tickets list returns the envelope the Python produced.
     */
    public function test_the_tickets_list_returns_the_envelope(): void
    {
        $this->asMasterAdmin();

        $this->db->count = 2;
        $this->db->rows = [
            (object) [
                'id' => '1', 'requester_username' => 'user1', 'recipient_username' => 'ali',
                'subject' => 'Test ticket', 'status' => 'open', 'priority' => 'high',
                'category_id' => '1', 'category_name' => 'General', 'assigned_to' => null,
                'created_at' => '2026-09-30 08:53:41.864', 'updated_at' => '2026-09-30 08:53:41.864',
                'last_message_at' => '2026-09-30 08:53:41.864', 'sla_due_at' => '2027-01-01 08:53:41.864',
                'last_message_preview' => 'Hello', 'last_responder' => 'user1',
            ],
        ];
        $this->db->filterCounts = ['open' => 1, 'new' => 1];

        $response = $this->get('/master-admin/api/tickets');

        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json('data');

        $this->assertSame(2, $data['total']);
        $this->assertSame(1, $data['page']);
        $this->assertSame(20, $data['page_size']);
        $this->assertSame(1, $data['pages']);
        $this->assertCount(1, $data['items']);

        $item = $data['items'][0];
        $this->assertSame(1, $item['id']);
        $this->assertSame('HT-00000001', $item['ticket_number']);
        $this->assertSame('open', $item['status']);
        $this->assertSame('باز', $item['status_label']);
        $this->assertSame('high', $item['priority']);
        $this->assertSame('زیاد', $item['priority_label']);
        $this->assertSame('healthy', $item['sla_state']);
    }

    /**
     * The tickets stats returns the three counts.
     */
    public function test_the_tickets_stats_returns_the_counts(): void
    {
        $this->asMasterAdmin();

        $this->db->count = 5;
        $this->db->openCount = 3;
        $this->db->rows = [];

        $response = $this->get('/master-admin/api/tickets/stats');

        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json('data');

        $this->assertSame(5, $data['total']);
        $this->assertSame(3, $data['open']);
        $this->assertArrayHasKey('by_status', $data);
        $this->assertArrayHasKey('by_priority', $data);
    }

    /**
     * The ticket detail returns the ticket with its messages, attachments and events.
     */
    public function test_the_ticket_detail_returns_the_full_ticket(): void
    {
        $this->asMasterAdmin();

        $this->db->rows = [
            (object) [
                'id' => '1', 'legacy_parent_id' => null, 'requester_username' => 'user1',
                'recipient_username' => 'ali', 'subject' => 'Test', 'status' => 'open',
                'priority' => 'normal', 'category_id' => '1', 'category_name' => 'General',
                'assigned_to' => null, 'created_at' => '2026-09-30 08:53:41.864',
                'updated_at' => '2026-09-30 08:53:41.864', 'last_message_at' => '2026-09-30 08:53:41.864',
                'first_response_at' => null, 'resolved_at' => null, 'closed_at' => null,
                'sla_due_at' => null,
            ],
        ];
        $this->db->messageRows = [
            (object) [
                'id' => '1', 'author_username' => 'user1', 'body' => 'Hello',
                'visibility' => 'public', 'created_at' => '2026-09-30 08:53:41.864',
                'edited_at' => null, 'attachment_count' => 0,
            ],
        ];
        $this->db->attachmentRows = [];
        $this->db->eventRows = [
            (object) [
                'id' => '1', 'actor_username' => 'user1', 'event_type' => 'created',
                'metadata' => '{"message_id":1}', 'created_at' => '2026-09-30 08:53:41.864',
            ],
        ];

        $response = $this->get('/master-admin/api/tickets/1');

        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json('data');

        $this->assertSame(1, $data['id']);
        $this->assertSame('HT-00000001', $data['ticket_number']);
        $this->assertCount(1, $data['messages']);
        $this->assertCount(0, $data['attachments']);
        $this->assertCount(1, $data['events']);
        $this->assertSame(['message_id' => 1], $data['events'][0]['metadata']);
    }

    /**
     * A missing ticket is the FastAPI `detail` 404.
     */
    public function test_a_missing_ticket_answers_the_fastapi_not_found_body(): void
    {
        $this->asMasterAdmin();

        $this->db->rows = [];

        $this->get('/master-admin/api/tickets/999')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'تیکت پیدا نشد.']);
    }

    /**
     * The ticket categories list.
     */
    public function test_the_ticket_categories_list(): void
    {
        $this->asMasterAdmin();

        $this->db->rows = [
            (object) ['id' => '1', 'name' => 'General', 'slug' => 'general', 'parent_id' => null],
        ];

        $response = $this->get('/master-admin/api/tickets/categories/all');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('General', $response->json('data.0.name'));
    }

    /**
     * The ticket users list.
     */
    public function test_the_ticket_users_list(): void
    {
        $this->asMasterAdmin();

        $this->db->rows = [
            (object) ['username' => 'ali', 'name' => 'Ali', 'last_name' => 'Rezaee', 'department' => 'IT'],
        ];

        $response = $this->get('/master-admin/api/tickets/users/all');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('ali', $response->json('data.0.username'));
        $this->assertSame('Ali Rezaee', $response->json('data.0.name'));
    }

    // ── Config ──────────────────────────────────────────────────────────────

    /**
     * The config list returns every row of `system_config`.
     */
    public function test_the_config_list_returns_every_row(): void
    {
        $this->asMasterAdmin();

        $this->db->rows = [
            (object) [
                'config_key' => 'captcha_enabled', 'config_value' => '1',
                'description' => 'Enable captcha', 'updated_by' => 'ali',
                'updated_at' => '2026-09-30 08:53:41.864',
            ],
        ];

        $response = $this->get('/master-admin/api/config');

        $response->assertOk()->assertJson(['success' => true]);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('captcha_enabled', $response->json('data.0.config_key'));
    }

    /**
     * The config write validates the key against the allow-list.
     */
    public function test_the_config_write_rejects_a_disallowed_key(): void
    {
        $this->asMasterAdmin();

        $this->postJson('/master-admin/api/config', ['key' => 'disallowed_key', 'value' => '1'])
            ->assertStatus(400)
            ->assertExactJson(['detail' => 'کلید تنظیم مجاز نیست.']);
    }

    /**
     * The config write requires a non-empty key.
     */
    public function test_the_config_write_requires_a_key(): void
    {
        $this->asMasterAdmin();

        $this->postJson('/master-admin/api/config', ['key' => '', 'value' => '1'])
            ->assertStatus(400)
            ->assertExactJson(['detail' => 'کلید الزامی است.']);
    }

    /**
     * The config write upserts the value and logs the action.
     */
    public function test_the_config_write_upserts_and_logs(): void
    {
        $this->asMasterAdmin();

        $this->db->updateCount = 1;

        $response = $this->postJson('/master-admin/api/config', [
            'key' => 'captcha_enabled',
            'value' => '0',
        ]);

        $response->assertOk()->assertExactJson(['success' => true]);

        $inserts = $this->recordedQueriesContaining('admin_actions');
        $this->assertNotEmpty($inserts, 'the config write must be audited');
        $this->assertSame('update_config', $inserts[0][1][2]);
    }

    // ── LAN access ──────────────────────────────────────────────────────────

    /**
     * The LAN access status returns the state shape.
     */
    public function test_the_lan_access_status_returns_the_state_shape(): void
    {
        $this->asMasterAdmin();

        $response = $this->get('/master-admin/api/lan-access');

        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json('data');

        $this->assertArrayHasKey('enabled', $data);
        $this->assertArrayHasKey('running', $data);
        $this->assertArrayHasKey('address', $data);
        $this->assertArrayHasKey('port', $data);
        $this->assertArrayHasKey('url', $data);
        $this->assertArrayHasKey('detected_address', $data);
    }

    /**
     * The LAN access selftest returns the result shape.
     */
    public function test_the_lan_access_selftest_returns_the_result_shape(): void
    {
        $this->asMasterAdmin();

        $response = $this->postJson('/master-admin/api/lan-access/selftest');

        $response->assertOk();

        $data = $response->json('data');

        $this->assertArrayHasKey('ok', $data);
        $this->assertArrayHasKey('address', $data);
        $this->assertArrayHasKey('port', $data);
        $this->assertArrayHasKey('http_status', $data);
        $this->assertArrayHasKey('message', $data);
    }

    // ── Outage ──────────────────────────────────────────────────────────────

    /**
     * The outage status returns the state shape.
     */
    public function test_the_outage_status_returns_the_state_shape(): void
    {
        $this->asMasterAdmin();

        $response = $this->get('/master-admin/api/outage');

        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json('data');

        $this->assertArrayHasKey('enabled', $data);
        $this->assertArrayHasKey('active', $data);
        $this->assertArrayHasKey('manual', $data);
        $this->assertArrayHasKey('targets', $data);
        $this->assertArrayHasKey('interval_seconds', $data);
        $this->assertArrayHasKey('threshold', $data);
    }

    /**
     * The outage check returns the probe result.
     */
    public function test_the_outage_check_returns_the_probe_result(): void
    {
        $this->asMasterAdmin();

        $response = $this->postJson('/master-admin/api/outage/check');

        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json('data');

        $this->assertArrayHasKey('probe_online', $data);
        $this->assertArrayHasKey('detail', $data);
    }

    /**
     * The outage manual endpoint validates the body.
     */
    public function test_the_outage_manual_endpoint_requires_a_json_body(): void
    {
        $this->asMasterAdmin();

        $this->post('/master-admin/api/outage/manual', [], ['CONTENT_TYPE' => 'application/json'])
            ->assertStatus(400)
            ->assertExactJson(['detail' => 'بدنه درخواست نامعتبر است.']);
    }

    // ── Iran access ─────────────────────────────────────────────────────────

    /**
     * The Iran access status returns the state shape.
     */
    public function test_the_iran_access_status_returns_the_state_shape(): void
    {
        $this->asMasterAdmin();

        $response = $this->get('/master-admin/api/iran-access');

        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json('data');

        $this->assertArrayHasKey('enabled', $data);
        $this->assertArrayHasKey('enforcing', $data);
        $this->assertArrayHasKey('list_loaded', $data);
        $this->assertArrayHasKey('ranges_ipv4', $data);
        $this->assertArrayHasKey('ranges_ipv6', $data);
    }

    /**
     * The Iran access check returns the verdict.
     */
    public function test_the_iran_access_check_returns_the_verdict(): void
    {
        $this->asMasterAdmin();

        $response = $this->postJson('/master-admin/api/iran-access/check', ['ip' => '127.0.0.1']);

        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json('data');

        $this->assertArrayHasKey('ip', $data);
        $this->assertArrayHasKey('kind', $data);
        $this->assertArrayHasKey('allowed', $data);
        $this->assertArrayHasKey('label', $data);
    }

    /**
     * The Iran access store's empty-message check is dead code.
     *
     * The Python's `_clean_text` returns `(cleaned or fallback)[:limit]`, so
     * an empty message becomes the default message — the `if not message:`
     * check never fires.  Reproduced here: the endpoint answers 200 with the
     * default message persisted.
     */
    public function test_the_iran_access_store_reproduces_the_dead_code_message_check(): void
    {
        $this->asMasterAdmin();

        $response = $this->postJson('/master-admin/api/iran-access', [
            'enabled' => true,
            'message' => '',
        ]);

        $response->assertOk();

        $data = $response->json('data');

        $this->assertNotEmpty($data['message']);
    }

    // ── Login experience ────────────────────────────────────────────────────

    /**
     * The login experience status returns the state shape.
     */
    public function test_the_login_experience_status_returns_the_state_shape(): void
    {
        $this->asMasterAdmin();

        $response = $this->get('/master-admin/api/login-experience');

        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json('data');

        $this->assertArrayHasKey('loader_enabled', $data);
        $this->assertArrayHasKey('loader_seconds', $data);
        $this->assertArrayHasKey('captcha_ttl_seconds', $data);
        $this->assertArrayHasKey('captcha_notice', $data);
    }

    /**
     * The login experience store validates the seconds.
     */
    public function test_the_login_experience_store_validates_the_seconds(): void
    {
        $this->asMasterAdmin();

        $this->postJson('/master-admin/api/login-experience', [
            'loader_seconds' => 0,
        ])
            ->assertStatus(400)
            ->assertJsonPath('detail', 'مدت نمایش لودر باید بین 1 و 15 ثانیه باشد.');
    }

    // ── Printers ────────────────────────────────────────────────────────────

    /**
     * The printers endpoint returns the payload shape.
     */
    public function test_the_printers_endpoint_returns_the_payload_shape(): void
    {
        $this->asMasterAdmin();

        $response = $this->get('/master-admin/api/printers');

        $response->assertOk()->assertJson(['success' => true]);

        $data = $response->json('data');

        $this->assertArrayHasKey('platform', $data);
        $this->assertArrayHasKey('printers', $data);
        $this->assertArrayHasKey('default_printer', $data);
        $this->assertArrayHasKey('label_printer', $data);
        $this->assertArrayHasKey('enumerated', $data);
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Sign in as a master administrator, with the session flags the login
     * flow sets and a live registry row (the fake connection's `sessionRows`).
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
     * The declared SQL types, transcribed from `docs/migration/DATABASE_SCHEMA.md`.
     */
    private function seedSchemaTypes(): void
    {
        $property = new ReflectionProperty(LegacySchema::class, 'types');

        $property->setValue(null, [
            'tickets' => [
                'id' => 'bigint', 'legacy_parent_id' => 'int', 'requester_username' => 'nvarchar',
                'recipient_username' => 'nvarchar', 'subject' => 'nvarchar', 'status' => 'varchar',
                'priority' => 'varchar', 'category_id' => 'int', 'assigned_to' => 'nvarchar',
                'created_at' => 'datetime2', 'updated_at' => 'datetime2', 'last_message_at' => 'datetime2',
                'first_response_at' => 'datetime2', 'resolved_at' => 'datetime2', 'closed_at' => 'datetime2',
                'sla_due_at' => 'datetime2',
            ],
            'ticket_messages' => [
                'id' => 'bigint', 'ticket_id' => 'bigint', 'author_username' => 'nvarchar',
                'body' => 'nvarchar', 'visibility' => 'varchar', 'legacy_message_id' => 'int',
                'created_at' => 'datetime2', 'edited_at' => 'datetime2',
            ],
            'ticket_attachments' => [
                'id' => 'bigint', 'ticket_id' => 'bigint', 'message_id' => 'bigint',
                'uploaded_by' => 'nvarchar', 'original_name' => 'nvarchar', 'storage_name' => 'varchar',
                'content_type' => 'varchar', 'size_bytes' => 'bigint', 'created_at' => 'datetime2',
            ],
            'ticket_events' => [
                'id' => 'bigint', 'ticket_id' => 'bigint', 'actor_username' => 'nvarchar',
                'event_type' => 'varchar', 'metadata' => 'nvarchar', 'created_at' => 'datetime2',
            ],
            'ticket_categories' => [
                'id' => 'int', 'name' => 'nvarchar', 'slug' => 'varchar',
                'parent_id' => 'int', 'is_active' => 'bit', 'created_at' => 'datetime2',
            ],
            'user_table' => [
                'id' => 'int', 'username' => 'nvarchar', 'name' => 'nvarchar', 'last_name' => 'nvarchar',
                'department' => 'nvarchar', 'work_hours' => 'nvarchar', 'substitute' => 'nvarchar',
                'role' => 'nchar', 'hozoor_num' => 'nchar', 'is_active' => 'nvarchar',
                'last_login' => 'datetime2', 'failed_login_count' => 'int',
                'password_changed_at' => 'datetime2', 'customer_id' => 'nvarchar',
                'customer_name' => 'nvarchar',
            ],
            'system_config' => [
                'config_key' => 'varchar', 'config_value' => 'nvarchar', 'description' => 'nvarchar',
                'updated_by' => 'nvarchar', 'updated_at' => 'datetime2',
            ],
            'admin_actions' => [
                'id' => 'bigint', 'action_id' => 'varchar', 'admin_username' => 'nvarchar',
                'action' => 'varchar', 'target_username' => 'nvarchar', 'target_type' => 'varchar',
                'target_id' => 'nvarchar', 'description' => 'nvarchar', 'before_data' => 'nvarchar',
                'after_data' => 'nvarchar', 'ip_address' => 'varchar', 'request_id' => 'varchar',
                'created_at' => 'datetime2',
            ],
            'notifications' => [
                'id' => 'bigint', 'title' => 'nvarchar', 'content' => 'nvarchar',
                'type' => 'varchar', 'priority' => 'varchar', 'status' => 'varchar',
                'target_type' => 'varchar', 'action_url' => 'nvarchar', 'created_by' => 'nvarchar',
                'created_at' => 'datetime2', 'updated_at' => 'datetime2', 'published_at' => 'datetime2',
                'scheduled_at' => 'datetime2', 'archived_at' => 'datetime2', 'push_tag' => 'nvarchar',
            ],
            'notification_targets' => [
                'notification_id' => 'bigint', 'target_value' => 'nvarchar',
            ],
            'user_notifications' => [
                'id' => 'bigint', 'notification_id' => 'bigint', 'username' => 'nvarchar',
                'delivered_at' => 'datetime2', 'read_at' => 'datetime2', 'dismissed_at' => 'datetime2',
                'updated_at' => 'datetime2',
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
 */
class FakeSettingsConnection extends Connection
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $queries = [];

    /** The row count behind a plain `COUNT(*)` with no filter. */
    public int $count = 0;

    /** The row count behind the "open tickets" `COUNT(*)`. */
    public int $openCount = 0;

    /**
     * Filtered counts, keyed by the value of the filter's first binding.
     *
     * @var array<string, int>
     */
    public array $filterCounts = [];

    /** The rows behind the main `SELECT`s and the single-row lookups. */
    public array $rows = [];

    /** The rows behind the ticket detail's message `SELECT`. */
    public array $messageRows = [];

    /** The rows behind the ticket detail's attachment `SELECT`. */
    public array $attachmentRows = [];

    /** The rows behind the ticket detail's event `SELECT`. */
    public array $eventRows = [];

    /** The rows behind the list's `GROUP BY status` tally. */
    public array $statusCounts = [];

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

    public function affectingStatement($query, $bindings = []): int
    {
        $this->queries[] = [$query, $bindings];

        return 1;
    }

    /**
     * The canned answer for one statement, matched on the SQL's own shape.
     *
     * @param  array<int, mixed>  $bindings
     */
    private function respond(string $sql, array $bindings = []): mixed
    {
        // A **bare** `SELECT COUNT(*)` is a count.  A `COUNT(*)` inside a larger
        // SELECT is not: the message list carries
        // `(SELECT COUNT(*) FROM ticket_attachments …)` in its column list, and
        // matching on the substring alone answered it with a count object, so the
        // detail's messages came back empty.
        if (preg_match('/^SELECT\s+COUNT\(\*\)/i', $sql) === 1) {
            return (object) ['total' => $bindings === [] ? $this->openCountFor($sql) : $this->countFor($bindings)];
        }

        // The list's per-status tally.
        if (stripos($sql, 'GROUP BY status') !== false) {
            return $this->statusCounts;
        }

        $table = $this->mainFromTable($sql);

        if ($table !== null) {
            return match ($table) {
                'ticket_attachments' => $this->attachmentRows,
                'ticket_messages' => $this->messageRows,
                'ticket_events' => $this->eventRows,
                default => $this->rows,
            };
        }

        return match (true) {
            stripos($sql, 'user_sessions') !== false => $this->sessionRows,
            stripos($sql, 'user_table') !== false => $this->rows,
            stripos($sql, 'system_config') !== false => $this->rows,
            stripos($sql, 'notifications') !== false => $this->rows,
            stripos($sql, 'SELECT') !== false => $this->rows,
            default => null,
        };
    }

    /**
     * The table the statement itself selects from.
     *
     * Both the message and the attachment queries reference the *other* table
     * inside a subquery, and in the message query that subquery's `FROM` appears
     * in the string **before** the statement's own — so the first `FROM` in the
     * raw SQL is the wrong one.  Parenthesised groups are stripped first, which
     * removes every subquery's `FROM` and leaves the statement's own.
     */
    private function mainFromTable(string $sql): ?string
    {
        $stripped = $sql;

        while (($without = (string) preg_replace('/\([^()]*\)/', '', $stripped)) !== $stripped) {
            $stripped = $without;
        }

        if (preg_match('/FROM\s+(ticket_attachments|ticket_messages|ticket_events|ticket_categories|tickets)\b/i', $stripped, $match) === 1) {
            return strtolower($match[1]);
        }

        return null;
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

    /**
     * The answer for the "open tickets" count — the query has no bindings but
     * a `WHERE status NOT IN (...)` clause, so it needs its own field.
     */
    private function openCountFor(string $sql): int
    {
        return stripos($sql, 'status NOT IN') !== false ? $this->openCount : $this->count;
    }
}
