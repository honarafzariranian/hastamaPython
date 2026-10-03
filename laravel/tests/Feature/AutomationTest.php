<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SqlServerGrammar;
use Illuminate\Database\Query\Processors\Processor;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The `/api/automation/*` surface — guards, FastAPI's 422 bodies, the reopen state
 * machine, the upload refusals and the two 500 shapes, all offline.
 *
 * Every test runs without the live database.  The decisions these endpoints make
 * are observable without SQL Server:
 *
 * * the **guard and validation** answers, which are decided before or instead of
 *   any query;
 * * the **success shapes**, which are the controller's own serialization of rows
 *   the fake connection answers;
 * * the **side effects**, which are writes — every statement the controller
 *   issues is recorded with its bindings by {@see FakeAutomationConnection}.
 *
 * The fake is registered through `DB::extend()` and made the default connection,
 * so `DB::connection()` and every Eloquent model resolve to it exactly as they
 * would to SQL Server in production.  It answers from the SQL's own shape rather
 * than from a queue, so a test states what the database said.
 */
final class AutomationTest extends TestCase
{
    private FakeAutomationConnection $db;

    /** Temporary attachment roots created by a test, removed in {@see tearDown()}. */
    private array $attachmentDirs = [];

    /** Real temp files handed to the upload as an `UploadedFile`. */
    private array $uploadFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new FakeAutomationConnection;

        // The controller writes through the query builder (`->table(...)->insert(...)`),
        // which compiles SQL through the connection's grammar.  A bare connection has
        // none, so every builder write would fail before reaching the fake.
        $this->db->setQueryGrammar(new SqlServerGrammar($this->db));

        DB::extend('legacy', fn () => $this->db);
        config(['database.default' => 'legacy', 'database.connections.legacy' => ['driver' => 'fake']]);
        DB::purge('legacy');
    }

    protected function tearDown(): void
    {
        DB::purge('legacy');

        foreach ($this->attachmentDirs as $dir) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($dir);
        }

        foreach ($this->uploadFiles as $file) {
            @unlink($file);
        }

        parent::tearDown();
    }

    // ── Route wiring ─────────────────────────────────────────────────────────

    /**
     * Every route this module registers, and the controller action it must reach.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function automationRoutes(): array
    {
        return [
            'automation.index' => ['GET', '/api/automation', 'App\Http\Controllers\Automation\AutomationController@index'],
            'automation.create' => ['POST', '/api/automation', 'App\Http\Controllers\Automation\AutomationController@create'],
            'automation.show' => ['GET', '/api/automation/1', 'App\Http\Controllers\Automation\AutomationController@show'],
            'automation.destroy' => ['DELETE', '/api/automation/1', 'App\Http\Controllers\Automation\AutomationController@destroy'],
            'automation.complete' => ['POST', '/api/automation/1/complete', 'App\Http\Controllers\Automation\AutomationController@complete'],
            'automation.reopen-request' => ['POST', '/api/automation/1/reopen-request', 'App\Http\Controllers\Automation\AutomationController@requestReopen'],
            'automation.approve-reopen' => ['POST', '/api/automation/1/reopen-request/2/approve', 'App\Http\Controllers\Automation\AutomationController@approveReopen'],
            'automation.messages' => ['POST', '/api/automation/1/messages', 'App\Http\Controllers\Automation\AutomationController@addMessage'],
            'automation.attachments' => ['POST', '/api/automation/1/attachments', 'App\Http\Controllers\Automation\AutomationController@uploadAttachment'],
            'automation.attachments.download' => ['GET', '/api/automation/1/attachments/2', 'App\Http\Controllers\Automation\AutomationController@downloadAttachment'],
        ];
    }

    #[DataProvider('automationRoutes')]
    public function test_the_automation_routes_are_wired_to_their_controllers(string $method, string $uri, string $action): void
    {
        $route = collect(Route::getRoutes())->first(static fn ($route): bool => $route->getActionName() === $action);

        $this->assertNotNull($route, "no route is registered for {$uri}");
        $this->assertContains($method, $route->methods());
        $this->assertSame($action, $route->getActionName());
    }

    #[DataProvider('automationRoutes')]
    public function test_every_automation_route_runs_in_the_web_group_with_the_optional_legacy_session(string $method, string $uri): void
    {
        $name = $this->routeNameFor($method, $uri);
        $route = collect(Route::getRoutes())->first(static fn ($route): bool => $route->getName() === $name);

        $this->assertNotNull($route, "no route is registered for {$uri}");
        $this->assertContains('web', $route->gatherMiddleware());
        $this->assertContains('legacy.session:optional', $route->gatherMiddleware());
    }

    /**
     * FastAPI dispatches a static path ahead of any `{param}` sibling, so the
     * literals are registered first — `/complete`, `/reopen-request`,
     * `/messages` and `/attachments` must all precede `/{conversationId}`.
     */
    public function test_the_literal_routes_are_registered_before_their_parameterised_siblings(): void
    {
        $names = collect(Route::getRoutes())
            ->map(static fn ($route): ?string => $route->getName())
            ->filter(static fn (?string $name): bool => str_starts_with($name ?? '', 'automation.'))
            ->values()
            ->all();

        $position = static fn (string $name): int => array_search($name, $names, true);

        $this->assertNotFalse($position('automation.index'));
        $this->assertNotFalse($position('automation.create'));

        foreach (['automation.complete', 'automation.reopen-request', 'automation.approve-reopen', 'automation.messages', 'automation.attachments', 'automation.attachments.download'] as $literal) {
            $this->assertLessThan($position('automation.show'), $position($literal), "{$literal} must be registered before automation.show");
        }
    }

    /**
     * The route name for a method/URI pair, read from the route collection.
     */
    private function routeNameFor(string $method, string $uri): string
    {
        $request = Request::create($uri, $method);
        $route = collect(Route::getRoutes())->first(static fn ($route): bool => $route->matches($request));

        $this->assertNotNull($route, "no route is registered for {$uri}");

        return (string) $route->getName();
    }

    // ── The guard ───────────────────────────────────────────────────────────

    /**
     * Every route, anonymous — the handler's own 401, not the middleware's.
     *
     * Validation runs before identity on the routes that declare parameters, so
     * the anonymous cases there send a valid body/file and still reach the guard.
     *
     * @return array<string, array{0: string, 1: string, 2: array<int, mixed>, 3: array<int, mixed>}>
     */
    public static function authenticatedRoutes(): array
    {
        return [
            'index' => ['GET', '/api/automation', [], []],
            'create' => ['POST', '/api/automation', ['subject' => 'موضوع', 'body' => 'متن'], []],
            'show' => ['GET', '/api/automation/1', [], []],
            'destroy' => ['DELETE', '/api/automation/1', [], []],
            'complete' => ['POST', '/api/automation/1/complete', [], []],
            'reopen-request' => ['POST', '/api/automation/1/reopen-request', [], []],
            'approve-reopen' => ['POST', '/api/automation/1/reopen-request/2/approve', [], []],
            'messages' => ['POST', '/api/automation/1/messages', ['body' => 'سلام'], []],
            'attachments' => ['POST', '/api/automation/1/attachments', [], ['file' => UploadedFile::fake()->create('note.txt', 10, 'text/plain')]],
            'attachments download' => ['GET', '/api/automation/1/attachments/2', [], []],
        ];
    }

    #[DataProvider('authenticatedRoutes')]
    public function test_every_automation_route_refuses_an_anonymous_request(string $method, string $uri, array $body, array $files): void
    {
        // The JSON-body routes must send a JSON body — validation runs first, so
        // the request has to be well-formed enough to reach the guard.
        $response = $body !== []
            ? $this->postJson($uri, $body)
            : $this->call($method, $uri, [], [], $files, ['HTTP_ACCEPT' => 'application/json']);

        $response->assertStatus(401);
        $this->assertSame(['detail' => 'برای ادامه وارد سامانه شوید.'], $response->json());
    }

    /**
     * A signed-in non-participant may not read another user's conversation — the
     * permission failure and the missing row are the same `404 گفتگو پیدا نشد.`.
     */
    public function test_a_non_participant_cannot_read_another_users_conversation(): void
    {
        $this->asUser('sara');

        $this->db->participantPresentRows = [];

        $this->get('/api/automation/1')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'گفتگو پیدا نشد.']);
    }

    /**
     * The session flags are compared strictly: `is_admin` stored as the string
     * `"1"` is not an administrator, so the user is an ordinary participant and
     * may post to their own conversation.
     */
    public function test_the_admin_flags_are_compared_strictly(): void
    {
        $this->actingAs(new User(['username' => 'ali']));
        $this->db->sessionRows = [(object) ['username' => 'ali', 'is_active' => 1, 'last_activity' => null]];

        $this->withSession([
            'username' => 'ali',
            'sid' => 'test-session-token',
            'sid_iat' => time(),
            'is_admin' => '1',
        ]);

        $this->seedOpenConversation();

        $this->postJson('/api/automation/1/messages', ['body' => 'سلام'])
            ->assertStatus(200)
            ->assertJsonPath('id', 1);
    }

    // ── Path and query validation ────────────────────────────────────────────

    /**
     * A non-numeric conversation id is FastAPI's 422 with `loc: ["path", …]`.
     */
    public function test_the_conversation_path_parameter_is_validated(): void
    {
        $this->get('/api/automation/abc')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'int_parsing',
                'loc' => ['path', 'conversation_id'],
                'msg' => 'Input should be a valid integer, unable to parse string as an integer',
                'input' => 'abc',
            ]]]);
    }

    /**
     * A handler with two path parameters cannot throw on the first one: both
     * failures are reported together, conversation id before request id.
     */
    public function test_the_approve_route_reports_both_path_parameters_together(): void
    {
        $this->post('/api/automation/abc/reopen-request/def/approve')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['path', 'conversation_id'])
            ->assertJsonPath('detail.0.input', 'abc')
            ->assertJsonPath('detail.1.loc', ['path', 'request_id'])
            ->assertJsonPath('detail.1.input', 'def');
    }

    /**
     * The download's attachment id is validated the same way.
     */
    public function test_the_attachment_path_parameter_is_validated(): void
    {
        $this->get('/api/automation/1/attachments/abc')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.loc', ['path', 'attachment_id']);
    }

    /**
     * `message_id` is a nullable `int` with `ge=1`: a non-numeric value is
     * `int_parsing`, `0` is `greater_than_equal`, and the empty string is a
     * parse failure rather than an absent parameter.
     */
    public function test_the_message_id_query_parameter_is_validated(): void
    {
        $file = $this->realUpload('note.txt', 'hello', 'text/plain');

        $this->post('/api/automation/1/attachments?message_id=abc', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.loc', ['query', 'message_id'])
            ->assertJsonPath('detail.0.input', 'abc');

        $this->post('/api/automation/1/attachments?message_id=0', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'greater_than_equal')
            ->assertJsonPath('detail.0.msg', 'Input should be greater than or equal to 1')
            ->assertJsonPath('detail.0.ctx', ['ge' => 1]);

        $this->post('/api/automation/1/attachments?message_id=', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.input', '');
    }

    // ── Body validation ─────────────────────────────────────────────────────

    /**
     * The create model reports every field failure in declaration order —
     * `subject`, then `participants`, then `body` — in one `detail` list.
     */
    public function test_the_create_body_reports_every_field_in_declaration_order(): void
    {
        $this->postJson('/api/automation', ['subject' => 1, 'participants' => 2, 'body' => 3])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_type')
            ->assertJsonPath('detail.0.loc', ['body', 'subject'])
            ->assertJsonPath('detail.0.input', 1)
            ->assertJsonPath('detail.1.type', 'list_type')
            ->assertJsonPath('detail.1.loc', ['body', 'participants'])
            ->assertJsonPath('detail.1.input', 2)
            ->assertJsonPath('detail.2.type', 'string_type')
            ->assertJsonPath('detail.2.loc', ['body', 'body'])
            ->assertJsonPath('detail.2.input', 3);
    }

    /**
     * A missing field echoes the **whole body** as `input`, not `null` and not
     * the field's value.
     */
    public function test_a_missing_field_echoes_the_whole_body_as_input(): void
    {
        $this->postJson('/api/automation', ['subject' => 'ab'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['body', 'body'])
            ->assertJsonPath('detail.0.msg', 'Field required')
            ->assertJsonPath('detail.0.input', ['subject' => 'ab']);
    }

    /**
     * An empty body is the model-level `missing` against `loc: ["body"]` alone.
     */
    public function test_an_empty_create_body_is_a_model_level_missing(): void
    {
        $this->call('POST', '/api/automation', [], [], [], ['CONTENT_TYPE' => 'application/json'], '')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'missing',
                'loc' => ['body'],
                'msg' => 'Field required',
                'input' => null,
            ]]]);
    }

    /**
     * A JSON array where an object was declared is `model_attributes_type`.
     */
    public function test_a_non_object_create_body_is_refused(): void
    {
        $this->call('POST', '/api/automation', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[1,2]')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'model_attributes_type')
            ->assertJsonPath('detail.0.loc', ['body'])
            ->assertJsonPath('detail.0.msg', 'Input should be a valid dictionary or object to extract fields from')
            ->assertJsonPath('detail.0.input', [1, 2]);
    }

    /**
     * Unparseable JSON is `json_invalid`, pointing at the offset in the document.
     */
    public function test_an_unparseable_create_body_is_refused(): void
    {
        $this->call('POST', '/api/automation', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{invalid')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'json_invalid')
            ->assertJsonPath('detail.0.loc', ['body', 0])
            ->assertJsonPath('detail.0.msg', 'JSON decode error')
            ->assertJsonPath('detail.0.ctx.error', 'Expecting value');
    }

    /**
     * The length bounds are the Python's: `subject` is 2..180 characters, `body`
     * is 1..4000, and the singular `1 character` is spelled for the body's
     * `min_length=1`.
     */
    public function test_the_create_fields_enforce_their_length_bounds(): void
    {
        $this->postJson('/api/automation', ['subject' => 'a', 'body' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_short')
            ->assertJsonPath('detail.0.loc', ['body', 'subject'])
            ->assertJsonPath('detail.0.msg', 'String should have at least 2 characters')
            ->assertJsonPath('detail.0.ctx', ['min_length' => 2]);

        $this->postJson('/api/automation', ['subject' => 'ab', 'body' => ''])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_short')
            ->assertJsonPath('detail.0.loc', ['body', 'body'])
            ->assertJsonPath('detail.0.msg', 'String should have at least 1 character')
            ->assertJsonPath('detail.0.ctx', ['min_length' => 1]);

        $this->postJson('/api/automation', ['subject' => str_repeat('س', 181), 'body' => 'x'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_long')
            ->assertJsonPath('detail.0.loc', ['body', 'subject'])
            ->assertJsonPath('detail.0.msg', 'String should have at most 180 characters')
            ->assertJsonPath('detail.0.ctx', ['max_length' => 180]);

        $this->postJson('/api/automation', ['subject' => 'ab', 'body' => str_repeat('م', 4001)])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_long')
            ->assertJsonPath('detail.0.loc', ['body', 'body'])
            ->assertJsonPath('detail.0.msg', 'String should have at most 4000 characters')
            ->assertJsonPath('detail.0.ctx', ['max_length' => 4000]);
    }

    /**
     * `participants` is a list: a non-list value is `list_type`, and an explicit
     * `null` is a `list_type` with `"input": null` rather than the default `[]`.
     */
    public function test_the_participants_list_rejects_a_non_list(): void
    {
        $this->postJson('/api/automation', ['subject' => 'ab', 'body' => 'x', 'participants' => 'nope'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'list_type')
            ->assertJsonPath('detail.0.loc', ['body', 'participants'])
            ->assertJsonPath('detail.0.msg', 'Input should be a valid list')
            ->assertJsonPath('detail.0.input', 'nope');

        $this->postJson('/api/automation', ['subject' => 'ab', 'body' => 'x', 'participants' => null])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'list_type')
            ->assertJsonPath('detail.0.input', null);
    }

    /**
     * A bad element is `string_type` reported against `["body","participants",0]`
     * — a three-segment loc no field-level error has.
     */
    public function test_the_participants_list_rejects_a_non_string_element(): void
    {
        $this->postJson('/api/automation', ['subject' => 'ab', 'body' => 'x', 'participants' => [1, 'ok']])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_type')
            ->assertJsonPath('detail.0.loc', ['body', 'participants', 0])
            ->assertJsonPath('detail.0.msg', 'Input should be a valid string')
            ->assertJsonPath('detail.0.input', 1);
    }

    /**
     * The length bound is checked before the elements: 101 integers answer one
     * `too_long` and no `string_type`.
     */
    public function test_the_participants_list_checks_its_length_before_its_elements(): void
    {
        $response = $this->postJson('/api/automation', [
            'subject' => 'ab',
            'body' => 'x',
            'participants' => array_fill(0, 101, 1),
        ]);

        $response->assertStatus(422);

        $detail = $response->json('detail');

        $this->assertCount(1, $detail);
        $this->assertSame('too_long', $detail[0]['type']);
        $this->assertSame(['body', 'participants'], $detail[0]['loc']);
        $this->assertSame('List should have at most 100 items after validation, not 101', $detail[0]['msg']);
        $this->assertSame(['field_type' => 'List', 'max_length' => 100, 'actual_length' => 101], $detail[0]['ctx']);
        $this->assertCount(101, $detail[0]['input']);
    }

    /**
     * The message body is the same declaration as the conversation body's second
     * field: `min_length=1`, `max_length=4000`.
     */
    public function test_the_message_body_is_validated(): void
    {
        // An empty JSON *object* — `postJson([])` would send `[]`, which is the
        // `model_attributes_type` refusal, not a missing field.
        $this->call('POST', '/api/automation/1/messages', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['body', 'body'])
            ->assertJsonPath('detail.0.input', []);

        $this->postJson('/api/automation/1/messages', ['body' => ''])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_short')
            ->assertJsonPath('detail.0.msg', 'String should have at least 1 character');

        $this->postJson('/api/automation/1/messages', ['body' => 123])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_type')
            ->assertJsonPath('detail.0.input', 123);
    }

    /**
     * Validation runs before identity: an anonymous request with a bad path is
     * answered 422, not with the 401 the guard would otherwise produce.
     */
    public function test_validation_runs_before_identity(): void
    {
        $this->post('/api/automation/abc/messages')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.loc', ['path', 'conversation_id']);

        $this->postJson('/api/automation', ['subject' => 'a'])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_short');
    }

    // ── The upload's merged validation ───────────────────────────────────────

    /**
     * The upload merges the three checks into one 422 — path, then `message_id`,
     * then the file — in the order the parameters were declared.
     */
    public function test_the_upload_merges_path_query_and_file_errors_in_order(): void
    {
        $this->post('/api/automation/abc/attachments?message_id=abc')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['path', 'conversation_id'])
            ->assertJsonPath('detail.1.loc', ['query', 'message_id'])
            ->assertJsonPath('detail.2.type', 'missing')
            ->assertJsonPath('detail.2.loc', ['body', 'file'])
            ->assertJsonPath('detail.2.msg', 'Field required')
            ->assertJsonPath('detail.2.input', null);
    }

    /**
     * A bad path and a bad query are reported together even when the file is
     * present and valid.
     */
    public function test_the_upload_reports_a_bad_path_and_query_together(): void
    {
        $file = $this->realUpload('note.txt', 'hello', 'text/plain');

        $this->post('/api/automation/abc/attachments?message_id=abc', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.loc', ['path', 'conversation_id'])
            ->assertJsonPath('detail.1.loc', ['query', 'message_id']);
    }

    /**
     * The upload requires a `file` field.
     */
    public function test_the_upload_requires_a_file(): void
    {
        $this->post('/api/automation/1/attachments')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'missing')
            ->assertJsonPath('detail.0.loc', ['body', 'file']);
    }

    // ── The handler's own refusals ───────────────────────────────────────────

    /**
     * A subject of two spaces passes `min_length=2` and fails the strip check,
     * which is why the route answers the module's 422 rather than pydantic's
     * `string_too_short`.
     */
    public function test_create_refuses_a_blank_subject_after_stripping(): void
    {
        $this->asUser('ali');

        $this->postJson('/api/automation', ['subject' => '  ', 'body' => 'متن'])
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'موضوع و متن معتبر نیست.']);

        $this->postJson('/api/automation', ['subject' => 'موضوع', 'body' => '   '])
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'موضوع و متن معتبر نیست.']);
    }

    /**
     * A message that is empty or whitespace after stripping is the service's
     * own 422.
     */
    public function test_add_message_refuses_a_blank_body(): void
    {
        $this->asUser('ali');

        $this->seedOpenConversation();

        $this->postJson('/api/automation/1/messages', ['body' => '   '])
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'متن پیام معتبر نیست.']);
    }

    /**
     * A conversation nobody else is in cannot be reopened by agreement.
     */
    public function test_request_reopen_refuses_a_conversation_with_no_other_participant(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->statusRows = [(object) ['status' => 'open']];
        $this->db->othersCount = 0;

        $this->post('/api/automation/1/reopen-request')
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'برای بازگشایی گفتگو، طرف مقابل وجود ندارد.']);
    }

    /**
     * A request that is already pending must not be duplicated — the check is
     * against the stored state, so replaying the call answers the same refusal.
     */
    public function test_request_reopen_refuses_a_duplicate_pending_request(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->statusRows = [(object) ['status' => 'open']];
        $this->db->othersCount = 1;
        $this->db->pendingRequestRows = [(object) ['id' => 5]];

        $this->post('/api/automation/1/reopen-request')
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'درخواست بازگشایی قبلاً ارسال شده است.']);
    }

    // ── Permission refusals ─────────────────────────────────────────────────

    /**
     * An ordinary administrator is refused **before** the conversation is looked
     * at, so the refusal is the permission message whatever the id.
     */
    public function test_add_message_refuses_an_ordinary_administrator(): void
    {
        $this->asAdmin('admin');

        $this->postJson('/api/automation/1/messages', ['body' => 'سلام'])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'دسترسی ارسال پیام ندارید.']);

        // An unknown conversation answers the same permission message, not a 500.
        $this->postJson('/api/automation/999/messages', ['body' => 'سلام'])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'دسترسی ارسال پیام ندارید.']);
    }

    /**
     * A non-participant may not post to a conversation they are not in.
     */
    public function test_add_message_refuses_a_non_participant(): void
    {
        $this->asUser('sara');

        $this->db->participantPresentRows = [];

        $this->postJson('/api/automation/1/messages', ['body' => 'سلام'])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'دسترسی به گفتگو ندارید.']);
    }

    /**
     * A closed conversation refuses new messages.
     */
    public function test_add_message_refuses_a_closed_conversation(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->statusRows = [(object) ['status' => 'completed']];

        $this->postJson('/api/automation/1/messages', ['body' => 'سلام'])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'این گفتگو به پایان رسیده است.']);
    }

    /**
     * The upload's own administrator check sits in the route, before the bytes
     * are read at all — so no file is written.
     */
    public function test_upload_attachment_refuses_an_ordinary_administrator(): void
    {
        $this->asAdmin('admin');

        $dir = $this->attachmentDir();

        $file = UploadedFile::fake()->create('note.txt', 10, 'text/plain');

        $this->post('/api/automation/1/attachments', ['file' => $file])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'دسترسی ارسال فایل ندارید.']);

        $this->assertEmpty(glob($dir.'/*'), 'a refused upload must not leave bytes behind');
    }

    /**
     * The delete permission is asymmetric: a master administrator may delete
     * anything, everyone else must be the creator — and an administrator who is
     * not a master is refused **even when they created it**.
     */
    public function test_destroy_refuses_a_non_creator(): void
    {
        $this->asUser('sara');

        $this->db->createdByRows = [(object) ['created_by' => 'ali']];

        $this->delete('/api/automation/1')
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'فقط ایجادکنندهٔ گفتگو می‌تواند آن را حذف کند.']);
    }

    public function test_destroy_refuses_an_ordinary_administrator_who_created_the_conversation(): void
    {
        $this->asAdmin('admin');

        $this->db->createdByRows = [(object) ['created_by' => 'admin']];

        $this->delete('/api/automation/1')
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'فقط ایجادکنندهٔ گفتگو می‌تواند آن را حذف کند.']);
    }

    /**
     * The requester may not approve their own request unless they are a master
     * administrator.
     */
    public function test_approve_reopen_refuses_the_requester(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->requesterRows = [(object) ['requester' => 'ali']];

        $this->post('/api/automation/1/reopen-request/2/approve')
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'درخواست بازگشایی باید توسط طرف مقابل تأیید شود.']);
    }

    /**
     * A non-participant may not close a conversation.
     */
    public function test_complete_refuses_a_non_participant(): void
    {
        $this->asUser('sara');

        $this->db->participantPresentRows = [];

        $this->post('/api/automation/1/complete')
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'دسترسی به گفتگو ندارید.']);
    }

    // ── Not-found refusals ──────────────────────────────────────────────────

    public function test_show_answers_404_for_an_unknown_conversation(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->conversationRows = [];

        $this->get('/api/automation/1')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'گفتگو پیدا نشد.']);
    }

    public function test_destroy_answers_404_for_an_unknown_conversation(): void
    {
        $this->asUser('ali');

        $this->db->createdByRows = [];

        $this->delete('/api/automation/1')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'گفتگو پیدا نشد.']);
    }

    public function test_request_reopen_answers_404_for_an_unknown_conversation(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->statusRows = [];

        $this->post('/api/automation/1/reopen-request')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'گفتگو پیدا نشد.']);
    }

    public function test_approve_reopen_answers_404_for_an_unknown_request(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->requesterRows = [];

        $this->post('/api/automation/1/reopen-request/2/approve')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'درخواست بازگشایی پیدا نشد.']);
    }

    public function test_download_attachment_answers_404_for_an_unknown_attachment(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->downloadRows = [];

        $this->get('/api/automation/1/attachments/2')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'فایل پیدا نشد.']);
    }

    /**
     * The permission failure and the missing row are the same `404 فایل پیدا
     * نشد.` — the one message the Python keeps for both.
     */
    public function test_download_attachment_answers_404_for_a_non_participant(): void
    {
        $this->asUser('sara');

        $this->db->participantPresentRows = [];

        $this->get('/api/automation/1/attachments/2')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'فایل پیدا نشد.']);
    }

    /**
     * A `message_id` that is not a message of *this* conversation is a 404, and
     * the check is against both columns.
     */
    public function test_upload_attachment_answers_404_for_a_message_of_another_conversation(): void
    {
        $this->asUser('ali');

        $dir = $this->attachmentDir();

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->messagePresentRows = [];

        $file = $this->realUpload('note.txt', 'hello', 'text/plain');

        $this->post('/api/automation/1/attachments?message_id=99', ['file' => $file])
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'پیام مقصد پیدا نشد.']);

        $this->assertEmpty(glob($dir.'/*'), 'a rejected message id must not leave orphaned bytes behind');
    }

    /**
     * The route's own check: a created conversation that cannot be recovered is
     * the module's 404.
     */
    public function test_create_answers_404_when_the_created_conversation_cannot_be_recovered(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [];

        $this->postJson('/api/automation', ['subject' => 'موضوع', 'body' => 'متن'])
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'گفتگوی ایجادشده قابل بازیابی نیست.']);
    }

    // ── The _error path ─────────────────────────────────────────────────────

    /**
     * `complete` and `messages` route their failures through `_error()`, which
     * answers the module's JSON 500.
     */
    public function test_complete_answers_the_module_500_when_the_database_fails(): void
    {
        $this->asUser('ali');

        $this->db->failNextQuery = true;

        $this->post('/api/automation/1/complete')
            ->assertStatus(500)
            ->assertExactJson(['detail' => 'خطا در پردازش گفتگوی خودکار.']);
    }

    public function test_add_message_answers_the_module_500_when_the_database_fails(): void
    {
        $this->asUser('ali');

        $this->db->failNextQuery = true;

        $this->postJson('/api/automation/1/messages', ['body' => 'سلام'])
            ->assertStatus(500)
            ->assertExactJson(['detail' => 'خطا در پردازش گفتگوی خودکار.']);
    }

    /**
     * A master administrator posting to a conversation that does not exist is
     * the Python's `TypeError`, rendered by the controller as the module's 500
     * rather than as a "not found".
     */
    public function test_add_message_answers_the_module_500_for_a_master_admin_posting_to_an_unknown_conversation(): void
    {
        $this->asMasterAdmin('master');

        $this->db->statusRows = [];

        $this->postJson('/api/automation/999/messages', ['body' => 'سلام'])
            ->assertStatus(500)
            ->assertExactJson(['detail' => 'خطا در پردازش گفتگوی خودکار.']);
    }

    /**
     * `show` and `download` have no `except` at all: a database failure escapes
     * to a **plain-text** 500, not the JSON envelope.
     */
    public function test_show_answers_a_plain_text_500_when_the_database_fails(): void
    {
        $this->asUser('ali');

        $this->db->failNextQuery = true;

        $response = $this->get('/api/automation/1');

        $response->assertStatus(500);
        $this->assertSame('Internal Server Error', $response->getContent());
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    public function test_download_attachment_answers_a_plain_text_500_when_the_database_fails(): void
    {
        $this->asUser('ali');

        $this->db->failNextQuery = true;

        $response = $this->get('/api/automation/1/attachments/2');

        $response->assertStatus(500);
        $this->assertSame('Internal Server Error', $response->getContent());
        $this->assertStringContainsString('text/plain', (string) $response->headers->get('Content-Type'));
    }

    // ── The 204 answers ──────────────────────────────────────────────────────

    /**
     * `complete` has no existence check: the `UPDATE` simply matches no row for
     * an unknown id and the route still answers 204.
     */
    public function test_complete_answers_204_for_an_unknown_conversation(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];

        $this->post('/api/automation/999/complete')
            ->assertStatus(204)
            ->assertNoContent();

        $updates = $this->db->queriesContaining('UPDATE automation_conversations');
        $this->assertCount(1, $updates);
        $this->assertSame('completed', $updates[0][1][0]);
        $this->assertSame(999, $updates[0][1][1]);
    }

    public function test_destroy_answers_204_and_deletes_the_row(): void
    {
        $this->asMasterAdmin('master');

        $this->db->createdByRows = [(object) ['created_by' => 'someone']];
        $this->db->storageNameRows = [];

        $this->delete('/api/automation/1')
            ->assertStatus(204)
            ->assertNoContent();

        $deletes = $this->db->queriesContaining('delete from');
        $this->assertCount(1, $deletes);
        $this->assertSame(1, $deletes[0][1][0]);
    }

    /**
     * A successful reopen request writes the request and the system message that
     * makes it visible to the other side.
     */
    public function test_request_reopen_answers_204_and_records_the_request_and_the_system_message(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->statusRows = [(object) ['status' => 'open']];
        $this->db->othersCount = 1;
        $this->db->pendingRequestRows = [];

        $this->post('/api/automation/1/reopen-request')
            ->assertStatus(204)
            ->assertNoContent();

        $requests = $this->db->queriesContaining('insert into [automation_reopen_requests]');
        $this->assertCount(1, $requests);
        $this->assertSame(1, $requests[0][1][0]);
        $this->assertSame('ali', $requests[0][1][1]);

        $messages = $this->db->queriesContaining('insert into [automation_messages]');
        $this->assertCount(1, $messages);
        $this->assertSame('درخواست بازگشایی این گفتگو ارسال شد و منتظر تأیید طرف مقابل است.', $messages[0][1][2]);
    }

    /**
     * The approval writes three things in one transaction: the request is
     * resolved, the conversation is reopened, and a system message records who
     * did it.
     */
    public function test_approve_reopen_answers_204_and_resolves_reopens_and_records(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->requesterRows = [(object) ['requester' => 'sara']];

        $this->post('/api/automation/1/reopen-request/2/approve')
            ->assertStatus(204)
            ->assertNoContent();

        $resolved = $this->db->queriesContaining('UPDATE automation_reopen_requests');
        $this->assertCount(1, $resolved);
        $this->assertSame('approved', $resolved[0][1][0]);
        $this->assertSame(2, $resolved[0][1][1]);

        $reopened = $this->db->queriesContaining('UPDATE automation_conversations');
        $this->assertCount(1, $reopened);
        $this->assertSame('open', $reopened[0][1][0]);
        $this->assertSame(1, $reopened[0][1][1]);

        $messages = $this->db->queriesContaining('insert into [automation_messages]');
        $this->assertCount(1, $messages);
        $this->assertSame('درخواست بازگشایی گفتگو تأیید شد؛ گفتگو دوباره فعال است.', $messages[0][1][2]);
    }

    /**
     * A master administrator approving their own request is the documented escape
     * hatch for a conversation whose other side has gone.
     */
    public function test_a_master_administrator_may_approve_their_own_request(): void
    {
        $this->asMasterAdmin('master');

        $this->db->requesterRows = [(object) ['requester' => 'master']];

        $this->post('/api/automation/1/reopen-request/2/approve')
            ->assertStatus(204)
            ->assertNoContent();
    }

    /**
     * The approval only matches a `pending` request — a resolved one is the same
     * 404 as an unknown id.
     */
    public function test_approve_reopen_requires_a_pending_request(): void
    {
        $this->asUser('ali');

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->requesterRows = [];

        $this->post('/api/automation/1/reopen-request/2/approve')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'درخواست بازگشایی پیدا نشد.']);
    }

    // ── Success shapes ──────────────────────────────────────────────────────

    /**
     * An ordinary participant sees only conversations they are in — the list is
     * scoped by an `EXISTS` subquery.
     */
    public function test_index_returns_only_the_callers_own_conversations(): void
    {
        $this->asUser('ali');

        $this->db->conversationRows = [
            (object) ['id' => '1', 'subject' => 'اول', 'created_by' => 'ali', 'created_at' => '2026-09-30 08:53:41.864', 'updated_at' => '2026-09-30 09:00:00', 'status' => 'open', 'participant_count' => '2', 'message_count' => '1'],
        ];
        $this->db->participantRows = [(object) ['username' => 'ali'], (object) ['username' => 'sara']];

        $response = $this->get('/api/automation');

        $response->assertOk();

        $lists = $this->db->queriesContaining('FROM automation_conversations');
        $this->assertNotEmpty($lists);
        $this->assertStringContainsString('EXISTS', $lists[0][0]);
        $this->assertSame('ali', $lists[0][1][0]);

        $items = $response->json('items');
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]['id']);
        $this->assertSame('اول', $items[0]['subject']);
        $this->assertSame('2026-09-30T08:53:41.864000Z', $items[0]['created_at']);
        $this->assertSame('2026-09-30T09:00:00Z', $items[0]['updated_at']);
        $this->assertSame(2, $items[0]['participant_count']);
        $this->assertSame(1, $items[0]['message_count']);
        $this->assertSame(['ali', 'sara'], $items[0]['participants']);
    }

    /**
     * An administrator sees every conversation — `_allowed()` is a bypass, so
     * the scope is `1=1` with no binding.
     */
    public function test_index_returns_every_conversation_for_an_administrator(): void
    {
        $this->asAdmin('admin');

        $this->db->conversationRows = [
            (object) ['id' => '1', 'subject' => 'اول', 'created_by' => 'ali', 'created_at' => '2026-09-30 08:53:41.864', 'updated_at' => '2026-09-30 09:00:00', 'status' => 'open', 'participant_count' => '2', 'message_count' => '1'],
            (object) ['id' => '2', 'subject' => 'دوم', 'created_by' => 'sara', 'created_at' => '2026-09-30 10:00:00', 'updated_at' => '2026-09-30 11:00:00', 'status' => 'completed', 'participant_count' => '1', 'message_count' => '0'],
        ];
        $this->db->participantRows = [(object) ['username' => 'ali']];

        $response = $this->get('/api/automation');

        $response->assertOk();

        $lists = $this->db->queriesContaining('FROM automation_conversations');
        $this->assertNotEmpty($lists);
        $this->assertStringContainsString('1=1', $lists[0][0]);
        $this->assertSame([], $lists[0][1]);

        $this->assertCount(2, $response->json('items'));
    }

    /**
     * A participant reads the full conversation: the six columns, then
     * `participants`, then `reopen_requests`, then `messages` and `attachments`.
     */
    public function test_show_returns_the_full_conversation_for_a_participant(): void
    {
        $this->asUser('ali');

        $this->seedOpenConversation();

        $response = $this->get('/api/automation/1');

        $response->assertOk();
        $this->assertSame([
            'id' => 1,
            'subject' => 'موضوع',
            'created_by' => 'ali',
            'created_at' => '2026-09-30T08:53:41.864000Z',
            'updated_at' => '2026-09-30T09:00:00Z',
            'status' => 'open',
            'participants' => ['ali', 'sara'],
            'reopen_requests' => [],
            'messages' => [
                ['id' => 1, 'author_username' => 'ali', 'body' => 'سلام', 'created_at' => '2026-09-30T08:53:41.864000Z', 'updated_at' => null],
                ['id' => 2, 'author_username' => 'sara', 'body' => 'درخواست جدید', 'created_at' => '2026-09-30T09:30:00Z', 'updated_at' => null],
            ],
            'attachments' => [
                ['id' => 1, 'message_id' => null, 'uploaded_by' => 'ali', 'original_name' => 'note.txt', 'content_type' => 'text/plain', 'size_bytes' => 100, 'created_at' => '2026-09-30T08:53:41.864000Z', 'updated_at' => null],
            ],
        ], $response->json());
    }

    /**
     * An administrator who is not a master and not a participant gets the
     * conversation **without** `messages` and `attachments` — the ordinary admin
     * UI shows the subject and the status, not the correspondence.
     */
    public function test_show_omits_messages_and_attachments_for_a_non_participant_administrator(): void
    {
        $this->asAdmin('admin');

        $this->db->participantPresentRows = [];
        $this->db->conversationRows = [
            (object) ['id' => '1', 'subject' => 'موضوع', 'created_by' => 'ali', 'created_at' => '2026-09-30 08:53:41.864', 'updated_at' => '2026-09-30 09:00:00', 'status' => 'open'],
        ];
        $this->db->participantRows = [(object) ['username' => 'ali'], (object) ['username' => 'sara']];
        $this->db->reopenRequestRows = [(object) ['id' => '3', 'requester' => 'sara', 'status' => 'pending', 'created_at' => '2026-09-30 08:53:41.864']];

        $response = $this->get('/api/automation/1');

        $response->assertOk();
        $this->assertSame('موضوع', $response->json('subject'));
        $this->assertSame(['ali', 'sara'], $response->json('participants'));
        $this->assertSame([
            ['id' => 3, 'requester' => 'sara', 'status' => 'pending', 'created_at' => '2026-09-30T08:53:41.864000Z', 'updated_at' => null],
        ], $response->json('reopen_requests'));
        $this->assertArrayNotHasKey('messages', $response->json());
        $this->assertArrayNotHasKey('attachments', $response->json());
    }

    /**
     * A create stores the conversation, its participants and the first message in
     * one transaction, then answers the conversation as `get()` assembles it.
     * The creator is always the first participant and the list is de-duplicated
     * after stripping.
     */
    public function test_create_stores_the_conversation_and_answers_201(): void
    {
        $this->asUser('ali');

        $this->db->insertGetIdReturn = 7;
        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->conversationRows = [
            (object) ['id' => '7', 'subject' => 'موضوع', 'created_by' => 'ali', 'created_at' => '2026-09-30 08:53:41.864', 'updated_at' => '2026-09-30 09:00:00', 'status' => 'open'],
        ];
        $this->db->participantRows = [(object) ['username' => 'ali'], (object) ['username' => 'sara']];
        $this->db->messageRows = [
            (object) ['id' => '9', 'author_username' => 'ali', 'body' => 'متن اول', 'created_at' => '2026-09-30 08:53:41.864'],
        ];

        $response = $this->postJson('/api/automation', [
            'subject' => 'موضوع',
            'participants' => ['sara', 'ali', '  ', 'sara'],
            'body' => 'متن اول',
        ]);

        $response->assertStatus(201);
        $this->assertSame(7, $response->json('id'));
        $this->assertSame(['ali', 'sara'], $response->json('participants'));

        $conversations = $this->db->queriesContaining('insert into [automation_conversations]');
        $this->assertCount(1, $conversations);
        $this->assertSame('موضوع', $conversations[0][1][0]);
        $this->assertSame('ali', $conversations[0][1][1]);

        // The creator first, then the de-duplicated names — the blank and the
        // repeated 'sara' contribute no row.
        $participants = $this->db->queriesContaining('insert into [automation_participants]');
        $this->assertCount(2, $participants);
        $this->assertSame([7, 'ali'], $participants[0][1]);
        $this->assertSame([7, 'sara'], $participants[1][1]);

        $messages = $this->db->queriesContaining('insert into [automation_messages]');
        $this->assertCount(1, $messages);
        $this->assertSame([7, 'ali', 'متن اول'], $messages[0][1]);
    }

    /**
     * A posted message is stored and the conversation is returned with the new
     * message's `id` already assigned.
     */
    public function test_add_message_stores_the_message_and_returns_the_conversation(): void
    {
        $this->asUser('ali');

        $this->seedOpenConversation();

        // The stored state after the insert: the caller sees its own message with
        // a fresh `id` already assigned, last by `created_at` as `get()` orders.
        $this->db->messageRows[] = (object) ['id' => '3', 'author_username' => 'ali', 'body' => 'پیام جدید', 'created_at' => '2026-09-30 10:00:00'];

        $response = $this->postJson('/api/automation/1/messages', ['body' => 'پیام جدید']);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('id'));

        $messages = $this->db->queriesContaining('insert into [automation_messages]');
        $this->assertCount(1, $messages);
        $this->assertSame([1, 'ali', 'پیام جدید'], $messages[0][1]);

        $updates = $this->db->queriesContaining('UPDATE automation_conversations');
        $this->assertCount(1, $updates);
        $this->assertSame(1, $updates[0][1][0]);

        $this->assertSame('پیام جدید', $response->json('messages.2.body'));
    }

    // ── Upload security and the round-trip ───────────────────────────────────

    /**
     * The store's allow-list is keyed by suffix: a `.exe` named `report.pdf` is
     * refused by the suffix, not by its name.
     */
    public function test_upload_attachment_refuses_a_disallowed_suffix(): void
    {
        $this->asUser('ali');

        $dir = $this->attachmentDir();

        $file = $this->realUpload('report.exe', 'hello', 'application/octet-stream');

        $this->post('/api/automation/1/attachments', ['file' => $file])
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'نوع فایل مجاز نیست.']);

        $this->assertEmpty(glob($dir.'/*'));
    }

    /**
     * The bytes are read with the same bound the Python used, so an oversized
     * upload is refused by its length.
     */
    public function test_upload_attachment_refuses_an_oversized_file(): void
    {
        $this->asUser('ali');

        $dir = $this->attachmentDir();

        $file = UploadedFile::fake()->createWithContent('big.pdf', str_repeat('x', 10 * 1024 * 1024 + 1));

        $this->post('/api/automation/1/attachments', ['file' => $file])
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'حجم فایل باید بین ۱ بایت و ۱۰ مگابایت باشد.']);

        $this->assertEmpty(glob($dir.'/*'));
    }

    /**
     * The declared content type must match the suffix's allowed type.
     */
    public function test_upload_attachment_refuses_a_content_type_mismatch(): void
    {
        $this->asUser('ali');

        $dir = $this->attachmentDir();

        $file = $this->realUpload('report.pdf', 'hello', 'image/png');

        $this->post('/api/automation/1/attachments', ['file' => $file])
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'نوع محتوای فایل معتبر نیست.']);

        $this->assertEmpty(glob($dir.'/*'));
    }

    /**
     * An authorised upload writes the file under the configured root and the row
     * with the store's own storage name; the download then streams the same bytes
     * back with Starlette's headers.
     */
    public function test_an_authorised_upload_then_download_round_trips_the_bytes(): void
    {
        $this->asUser('ali');

        $dir = $this->attachmentDir();

        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->insertGetIdReturn = 42;

        $content = 'The quick brown fox jumps over the lazy dog.';
        $file = $this->realUpload('notes.txt', $content, 'text/plain');

        $response = $this->post('/api/automation/1/attachments', ['file' => $file]);

        $response->assertStatus(200)->assertExactJson(['id' => 42, 'original_name' => 'notes.txt']);

        $written = glob($dir.'/*');
        $this->assertCount(1, $written, 'the upload must be on disk under the configured root');

        $inserts = $this->db->queriesContaining('insert into [automation_attachments]');
        $this->assertCount(1, $inserts);
        $storageName = $inserts[0][1][4];
        $this->assertSame($written[0], $dir.'/'.$storageName);
        $this->assertSame('text/plain', $inserts[0][1][5]);
        $this->assertSame(strlen($content), $inserts[0][1][6]);

        $this->db->downloadRows = [(object) [
            'id' => '42',
            'original_name' => 'notes.txt',
            'storage_name' => $storageName,
            'content_type' => 'text/plain',
            'size_bytes' => (string) strlen($content),
        ]];

        $download = $this->get('/api/automation/1/attachments/42');

        $download->assertStatus(200);
        $this->assertStringStartsWith('text/plain', (string) $download->headers->get('Content-Type'));
        $this->assertSame('bytes', $download->headers->get('Accept-Ranges'));
        $this->assertSame('attachment; filename="notes.txt"', $download->headers->get('Content-Disposition'));
        $this->assertSame('"'.md5((string) filemtime($written[0]).'-'.(string) filesize($written[0])).'"', $download->headers->get('ETag'));
        $this->assertSame($content, $download->streamedContent());
    }

    /**
     * When the row cannot be written the file is removed again, so a rejected
     * request does not leave orphaned bytes behind.
     */
    public function test_upload_attachment_removes_the_file_when_the_row_cannot_be_written(): void
    {
        $this->asUser('sara');

        $dir = $this->attachmentDir();

        $this->db->participantPresentRows = [];

        $file = $this->realUpload('note.txt', 'hello', 'text/plain');

        $this->post('/api/automation/1/attachments', ['file' => $file])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'دسترسی به گفتگو ندارید.']);

        $this->assertEmpty(glob($dir.'/*'), 'a refused upload must not leave bytes behind');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * A signed-in ordinary user — the guard identity and the legacy session
     * flags, with a live registry row for the session's own token.
     */
    private function asUser(string $username = 'ali'): static
    {
        $this->actingAs(new User(['username' => $username]));
        $this->registryUser($username);

        return $this->withSession([
            'username' => $username,
            'sid' => 'test-session-token',
            'sid_iat' => time(),
        ]);
    }

    /**
     * A signed-in administrator.
     */
    private function asAdmin(string $username = 'admin'): static
    {
        $this->actingAs(new User(['username' => $username]));
        $this->registryUser($username);

        return $this->withSession([
            'username' => $username,
            'sid' => 'test-session-token',
            'sid_iat' => time(),
            'is_admin' => true,
        ]);
    }

    /**
     * A signed-in master administrator.
     */
    private function asMasterAdmin(string $username = 'master'): static
    {
        $this->actingAs(new User(['username' => $username]));
        $this->registryUser($username);

        return $this->withSession([
            'username' => $username,
            'sid' => 'test-session-token',
            'sid_iat' => time(),
            'is_admin' => true,
            'is_master_admin' => true,
        ]);
    }

    /** Switch the registry row to the acting user (the middleware's check). */
    private function registryUser(string $username): void
    {
        $this->db->sessionRows = [(object) ['username' => $username, 'is_active' => 1, 'last_activity' => null]];
    }

    /**
     * The stored state of one open conversation with a participant, a message
     * and an attachment — the rows `show()` and `addMessage()` read back.
     */
    private function seedOpenConversation(): void
    {
        $this->db->participantPresentRows = [(object) ['present' => 1]];
        $this->db->statusRows = [(object) ['status' => 'open']];
        $this->db->conversationRows = [
            (object) ['id' => '1', 'subject' => 'موضوع', 'created_by' => 'ali', 'created_at' => '2026-09-30 08:53:41.864', 'updated_at' => '2026-09-30 09:00:00', 'status' => 'open'],
        ];
        $this->db->participantRows = [(object) ['username' => 'ali'], (object) ['username' => 'sara']];
        $this->db->reopenRequestRows = [];
        $this->db->messageRows = [
            (object) ['id' => '1', 'author_username' => 'ali', 'body' => 'سلام', 'created_at' => '2026-09-30 08:53:41.864'],
            (object) ['id' => '2', 'author_username' => 'sara', 'body' => 'درخواست جدید', 'created_at' => '2026-09-30 09:30:00'],
        ];
        $this->db->attachmentRows = [
            (object) ['id' => '1', 'message_id' => null, 'uploaded_by' => 'ali', 'original_name' => 'note.txt', 'content_type' => 'text/plain', 'size_bytes' => '100', 'created_at' => '2026-09-30 08:53:41.864'],
        ];
    }

    /**
     * A real temporary directory for the private attachment store, configured as
     * `hastama.ticketing_private_dir` so the store writes outside the project.
     */
    private function attachmentDir(): string
    {
        $dir = sys_get_temp_dir().'/hastama-automation-'.uniqid();
        mkdir($dir, 0777, true);
        $this->attachmentDirs[] = $dir;

        config(['hastama.ticketing_private_dir' => $dir]);

        return $dir;
    }

    /**
     * A real `UploadedFile` over a real temp file, with the client MIME type the
     * multipart part would have declared — the fake factory's reported MIME does
     * not reach `getClientMimeType()`, so a content-type mismatch needs this.
     */
    private function realUpload(string $name, string $content, string $mime): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'hastama-upload-');
        file_put_contents($path, $content);
        $this->uploadFiles[] = $path;

        return new UploadedFile($path, $name, $mime, null, true);
    }
}

/**
 * A programmable stand-in for the SQL Server connection.
 *
 * Every statement the application issues is recorded with its bindings, and the
 * answer is derived from the SQL's own shape rather than from a queue — so a
 * test states what the database said, not which order the queries happened to
 * run in.  One row set per query shape keeps the answers unambiguous: the
 * participants table alone answers three different statements.
 */
class FakeAutomationConnection extends Connection
{
    /** @var array<int, array{0: string, 1: array<int, mixed>}> */
    public array $queries = [];

    /** The rows behind the conversation list and detail SELECTs. */
    public array $conversationRows = [];

    /** The rows behind the participants SELECT. */
    public array $participantRows = [];

    /** The rows behind the messages SELECT. */
    public array $messageRows = [];

    /** The rows behind the attachments SELECT. */
    public array $attachmentRows = [];

    /** The rows behind the reopen-requests SELECT. */
    public array $reopenRequestRows = [];

    /** The rows behind the status-only SELECT. */
    public array $statusRows = [];

    /** The rows behind the created-by SELECT. */
    public array $createdByRows = [];

    /** The rows behind the requester SELECT. */
    public array $requesterRows = [];

    /** The rows behind the pending-request SELECT. */
    public array $pendingRequestRows = [];

    /** The rows behind the storage-name SELECT. */
    public array $storageNameRows = [];

    /** The rows behind the `SELECT 1 AS present` check on participants. */
    public array $participantPresentRows = [];

    /** The rows behind the `SELECT 1 AS present` check on messages. */
    public array $messagePresentRows = [];

    /** The count behind the "other participants" `COUNT(*)`. */
    public int $othersCount = 0;

    /** The rows behind the download's attachment SELECT. */
    public array $downloadRows = [];

    /** The session rows behind the registry's `user_sessions` SELECT. */
    public array $sessionRows = [];

    /** The id behind `insertGetId`. */
    public int $insertGetIdReturn = 100;

    public int $updateCount = 1;

    public int $deleteCount = 1;

    public int $affectingStatementCount = 1;

    /** When true, the next SELECT throws — the `_error()` path. */
    public bool $failNextQuery = false;

    public function __construct()
    {
        parent::__construct(new PDO('sqlite::memory:'), 'userDB', '', ['driver' => 'sqlite']);

        // The builder's `insertGetId` goes through the post processor, whose
        // default reads `lastInsertId()` off the real PDO — which has inserted
        // nothing into the sqlite stand-in.  Route it through the connection's
        // own `insertGetId`, which is this fake's.
        $this->postProcessor = new class extends Processor
        {
            public function processInsertGetId(Builder $query, $sql, $values, $sequence = null)
            {
                return $query->getConnection()->insertGetId($sql, $values, $sequence);
            }
        };
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

        // Only a single-row lookup fails on demand: the session registry's
        // middleware answers its `user_sessions` check with a plain `select`
        // (via `first()`), and a failure there is swallowed by the registry's
        // own fail-open — it must not consume the flag the controller needs.
        if ($this->failNextQuery) {
            $this->failNextQuery = false;

            throw new \RuntimeException('Simulated database failure.');
        }

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
     * The controller's `UPDATE` statements go through `Connection::statement()`,
     * which prepares and executes on the real PDO — the fake has no tables.
     * Record and answer instead.
     */
    public function statement($query, $bindings = [])
    {
        $this->queries[] = [$query, $bindings];

        return true;
    }

    /**
     * Every recorded query whose SQL contains the given substring.
     *
     * The SQL Server grammar wraps identifiers in brackets
     * (`update [automation_conversations] set …`), so the needle is the bare
     * table name — a bracket-wrapped identifier still contains it.
     *
     * @return array<int, array{0: string, 1: array<int, mixed>}>
     */
    public function queriesContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->queries,
            static fn (array $query): bool => stripos($query[0], $needle) !== false,
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
        // `Builder::exists()` reads the `exists` key of the first row.  A bare
        // object is discarded by `select()`, which returns `[]` for non-arrays.
        if (preg_match('/\[exists\]|as\s+"?exists"?\b/i', $sql) === 1) {
            return [(object) ['exists' => $this->existsAnswer($sql)]];
        }

        return match (true) {
            str_contains($sql, 'user_sessions') => $this->sessionRows,
            str_contains($sql, 'SELECT c.id') => $this->conversationRows,
            str_contains($sql, 'SELECT id, subject, created_by') => $this->conversationRows,
            str_contains($sql, 'SELECT status FROM automation_conversations') => $this->statusRows,
            str_contains($sql, 'SELECT created_by FROM automation_conversations') => $this->createdByRows,
            str_contains($sql, 'SELECT username FROM automation_participants') => $this->participantRows,
            str_contains($sql, '1 AS present') && str_contains($sql, 'automation_participants') => $this->participantPresentRows,
            str_contains($sql, '1 AS present') && str_contains($sql, 'automation_messages') => $this->messagePresentRows,
            str_contains($sql, 'COUNT(*)') && str_contains($sql, 'automation_participants') => (object) ['total' => $this->othersCount],
            str_contains($sql, 'SELECT id, requester, status, created_at') => $this->reopenRequestRows,
            str_contains($sql, 'SELECT requester FROM automation_reopen_requests') => $this->requesterRows,
            str_contains($sql, 'SELECT id FROM automation_reopen_requests') => $this->pendingRequestRows,
            str_contains($sql, 'SELECT id, author_username, body, created_at') => $this->messageRows,
            str_contains($sql, 'SELECT id, message_id, uploaded_by') => $this->attachmentRows,
            str_contains($sql, 'SELECT id, original_name, storage_name') => $this->downloadRows,
            str_contains($sql, 'SELECT storage_name FROM automation_attachments') => $this->storageNameRows,
            default => null,
        };
    }

    /**
     * Whether the table an `exists (select 1 from <table> …)` statement queried
     * has any rows — the answer `Builder::exists()` reads from the `exists` key.
     */
    private function existsAnswer(string $sql): bool
    {
        return match (true) {
            str_contains($sql, 'automation_participants') => $this->participantPresentRows !== [],
            str_contains($sql, 'automation_messages') => $this->messagePresentRows !== [],
            default => false,
        };
    }
}
