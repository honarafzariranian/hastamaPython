<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use App\Support\Notifications\NotificationInput;
use App\Support\Notifications\NotificationStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\TestCase;

/**
 * The notification centre — guards, validation, success shapes and the SSE contract.
 *
 * The default suite is offline: the guard matrix, the 422 bodies and the SSE frame
 * logic never touch the database, because in the Python every one of them is decided
 * *before* the handler's first query.  The success shapes need the real `userDB` and
 * are opt-in (`HASTAMA_DB_TESTS=1`), inside a transaction that is always rolled back —
 * the same contract `LegacyReadDatabaseTest` established.
 *
 * The cases that matter are the ones where a plausible implementation is wrong:
 *
 * * the admin list's **parameter-order bug** — a filtered list answers `items: []`
 *   while `total` is correct, because the `admin_read_at` subquery's placeholder
 *   precedes the WHERE placeholders while the bindings put the filter values first;
 * * `DELETE /api/admin/notifications/delete-all` is **unreachable** — the
 *   `{notification_id}` route is registered first and answers 422 `int_parsing`;
 * * the per-row handlers are **not public** — `_owned_update` calls `_actor`, so an
 *   anonymous request is a 401 even though the route inventory says "none (public)";
 * * pydantic's `value_error` carries `ctx: {"error": {}}`, not the message, and a
 *   `Literal` field reports `literal_error` for *any* non-member input.
 */
final class NotificationsTest extends TestCase
{
    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A signed-in administrator — the guard identity and the legacy session flags.
     *
     * Offline the registry lookup fails open (there is no `user_sessions` table on the
     * sqlite connection), which is the documented behaviour of `SessionRegistry`.
     */
    private function asAdmin(): static
    {
        $this->actingAs(new User(['username' => 'test-admin']));

        return $this->withSession([
            'username' => 'test-admin',
            'sid' => 'test-token',
            'sid_iat' => time(),
            'is_admin' => true,
        ]);
    }

    /** A signed-in ordinary user. */
    private function asUser(): static
    {
        $this->actingAs(new User(['username' => 'test-user']));

        return $this->withSession([
            'username' => 'test-user',
            'sid' => 'test-token',
            'sid_iat' => time(),
        ]);
    }

    /** Switch to the live `userDB` and open the transaction that is always rolled back. */
    private function liveDatabase(): void
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
    }

    protected function tearDown(): void
    {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }

        // Restore the test default connection, so an offline test that runs after a
        // live-database one does not inherit the sqlsrv default (which would turn the
        // registry's fail-open into a real lookup with no row).
        DB::setDefaultConnection('sqlite');
        DB::purge('sqlite');

        parent::tearDown();
    }

    // ── The guard matrix ─────────────────────────────────────────────────────

    /**
     * Every read refuses an anonymous request with the module's own 401 body —
     * `_actor`'s `{"detail": "برای ادامه وارد سامانه شوید."}`, not the registry
     * middleware's "session expired" body and not `EnsureAdmin`'s `error` key.
     */
    #[Test]
    public function every_read_refuses_an_anonymous_request_with_the_modules_own_401_body(): void
    {
        $paths = [
            '/api/admin/notification-targets',
            '/api/admin/notifications',
            '/api/notifications',
            '/api/notifications/unread-count',
            '/api/notifications/poll',
            '/api/notifications/stream',
            '/api/notifications/admin-stream',
            '/api/notifications/10010',
        ];

        foreach ($paths as $path) {
            $response = $this->get($path);

            $response->assertStatus(401);
            $this->assertSame(
                ['detail' => 'برای ادامه وارد سامانه شوید.'],
                $response->json(),
                "{$path} must answer the _actor 401 body",
            );
        }
    }

    /**
     * The administrator surface refuses a signed-in non-administrator with the module's
     * own 403 body — `_actor`'s `{"detail": "دسترسی مدیریت لازم است."}`, which is not
     * the body `EnsureAdmin` would have produced.
     */
    #[Test]
    public function the_admin_surface_refuses_a_non_admin_with_the_modules_own_403_body(): void
    {
        $this->asUser();

        $gets = [
            '/api/admin/notification-targets',
            '/api/admin/notifications',
            '/api/notifications/admin-stream',
        ];

        foreach ($gets as $path) {
            $response = $this->get($path);

            $response->assertStatus(403);
            $this->assertSame(
                ['detail' => 'دسترسی مدیریت لازم است.'],
                $response->json(),
                "{$path} must answer the _actor 403 body",
            );
        }

        $posts = [
            '/api/admin/notifications/10010/publish',
            '/api/admin/notifications/10010/read',
            '/api/admin/notifications/10010/unread',
            '/api/admin/notifications/10010/archive',
        ];

        foreach ($posts as $path) {
            $response = $this->post($path);

            $response->assertStatus(403);
            $this->assertSame(
                ['detail' => 'دسترسی مدیریت لازم است.'],
                $response->json(),
                "{$path} must answer the _actor 403 body",
            );
        }

        $response = $this->delete('/api/admin/notifications/10010');
        $response->assertStatus(403);
        $this->assertSame(['detail' => 'دسترسی مدیریت لازم است.'], $response->json());
    }

    /**
     * The per-row handlers are not public: `_owned_update` calls `_actor` on the way
     * past, so an anonymous request is a 401 before any SQL runs — even though the
     * route inventory records them as "none (public)".
     */
    #[Test]
    public function the_per_row_handlers_refuse_an_anonymous_request(): void
    {
        $read = $this->post('/api/notifications/10010/read');
        $read->assertStatus(401);
        $this->assertSame(['detail' => 'برای ادامه وارد سامانه شوید.'], $read->json());

        $this->post('/api/notifications/10010/unread')->assertStatus(401);
        $this->delete('/api/notifications/10010')->assertStatus(401);
    }

    /**
     * An unsafe method from a request with no CSRF token is refused — but which refusal
     * depends on the environment, and the split is the Python's.
     *
     * In production the `_CSRFMiddleware` answers **403** `{"success": false, "error":
     * "CSRF token mismatch."}` ahead of the handler's own guard.  In the test suite
     * Laravel skips CSRF entirely (`PreventRequestForgery::runningUnitTests()`), so the
     * same request reaches the handler and is answered by `_actor`'s **401** instead.
     * Both are the same boundary; the test asserts what this environment produces.
     */
    #[Test]
    public function an_unsafe_method_without_a_csrf_token_is_refused(): void
    {
        $response = $this->post('/api/admin/notifications/read-all');

        $response->assertStatus(401);
        $this->assertSame(
            ['detail' => 'برای ادامه وارد سامانه شوید.'],
            $response->json(),
        );
    }

    // ── Query validation ─────────────────────────────────────────────────────

    /**
     * A rejected query parameter is FastAPI's 422 — `{"detail":[{type,loc,msg,input,ctx}]}`
     * with `loc: ["query",…]` — never Laravel's 302 redirect.
     */
    #[Test]
    public function a_rejected_query_parameter_answers_fastapis_422(): void
    {
        $cases = [
            '/api/admin/notifications?page=abc' => [
                'type' => 'int_parsing',
                'loc' => ['query', 'page'],
                'msg' => 'Input should be a valid integer, unable to parse string as an integer',
                'input' => 'abc',
            ],
            '/api/admin/notifications?page=0' => [
                'type' => 'greater_than_equal',
                'loc' => ['query', 'page'],
                'msg' => 'Input should be greater than or equal to 1',
                'input' => '0',
                'ctx' => ['ge' => 1],
            ],
            '/api/admin/notifications?page_size=3' => [
                'type' => 'greater_than_equal',
                'loc' => ['query', 'page_size'],
                'msg' => 'Input should be greater than or equal to 5',
                'input' => '3',
                'ctx' => ['ge' => 5],
            ],
            '/api/admin/notifications?page_size=101' => [
                'type' => 'less_than_equal',
                'loc' => ['query', 'page_size'],
                'msg' => 'Input should be less than or equal to 100',
                'input' => '101',
                'ctx' => ['le' => 100],
            ],
            '/api/admin/notifications?sort=nope' => [
                'type' => 'literal_error',
                'loc' => ['query', 'sort'],
                'msg' => "Input should be 'newest', 'oldest' or 'title'",
                'input' => 'nope',
                'ctx' => ['expected' => "'newest', 'oldest' or 'title'"],
            ],
            '/api/notifications?state=nope' => [
                'type' => 'literal_error',
                'loc' => ['query', 'state'],
                'msg' => "Input should be 'all', 'unread' or 'read'",
                'input' => 'nope',
                'ctx' => ['expected' => "'all', 'unread' or 'read'"],
            ],
            '/api/notifications?page_size=4' => [
                'type' => 'greater_than_equal',
                'loc' => ['query', 'page_size'],
                'msg' => 'Input should be greater than or equal to 5',
                'input' => '4',
                'ctx' => ['ge' => 5],
            ],
        ];

        foreach ($cases as $path => $expected) {
            $response = $this->get($path);

            $response->assertStatus(422);
            $this->assertSame(
                ['detail' => [$expected]],
                $response->json(),
                "{$path} must answer the declared 422 body",
            );
        }
    }

    /**
     * `search` is `Query("", max_length=100)` on both lists — a 101-character search
     * is a `string_too_long`, on the admin list and the user list alike.
     */
    #[Test]
    public function a_too_long_search_is_rejected_on_both_lists(): void
    {
        $needle = str_repeat('a', 101);

        foreach (['/api/admin/notifications', '/api/notifications'] as $path) {
            $response = $this->get($path.'?search='.$needle);

            $response->assertStatus(422);
            $this->assertSame(['detail' => [[
                'type' => 'string_too_long',
                'loc' => ['query', 'search'],
                'msg' => 'String should have at most 100 characters',
                'input' => $needle,
                'ctx' => ['max_length' => 100],
            ]]], $response->json());
        }
    }

    // ── Path validation ──────────────────────────────────────────────────────

    /**
     * A non-integer `{notification_id}` is FastAPI's path 422 — `loc: ["path",…]` —
     * and it is what makes `DELETE /api/admin/notifications/delete-all` unreachable.
     */
    #[Test]
    public function a_non_integer_notification_id_is_a_path_422(): void
    {
        $response = $this->post('/api/admin/notifications/abc/publish');

        $response->assertStatus(422);
        $this->assertSame(['detail' => [[
            'type' => 'int_parsing',
            'loc' => ['path', 'notification_id'],
            'msg' => 'Input should be a valid integer, unable to parse string as an integer',
            'input' => 'abc',
        ]]], $response->json());
    }

    /**
     * `delete-all` is swallowed by the `{notification_id}` route registered ahead of
     * it — the running server answers 422 `int_parsing`, and so does the port.
     */
    #[Test]
    public function delete_all_is_unreachable_behind_the_notification_id_route(): void
    {
        $this->asAdmin();

        $response = $this->delete('/api/admin/notifications/delete-all');

        $response->assertStatus(422);
        $this->assertSame(['detail' => [[
            'type' => 'int_parsing',
            'loc' => ['path', 'notification_id'],
            'msg' => 'Input should be a valid integer, unable to parse string as an integer',
            'input' => 'delete-all',
        ]]], $response->json());
    }

    // ── Body validation ─────────────────────────────────────────────────────

    /**
     * The pydantic model's field failures, captured from the running server —
     * including the two surprises: `ctx.error` is `{}` (FastAPI's `jsonable_encoder`
     * mangles the `ValueError`), and a `Literal` field reports `literal_error` for
     * any non-member input, `null` included.
     */
    #[Test]
    public function the_body_model_rejects_with_fastapis_exact_422_bodies(): void
    {
        $cases = [
            'a JSON array where an object was declared' => [
                'body' => [],
                'expected' => [[
                    'type' => 'model_attributes_type', 'loc' => ['body'],
                    'msg' => 'Input should be a valid dictionary or object to extract fields from',
                    'input' => [],
                ]],
            ],
            'a missing content' => [
                'body' => ['title' => 'ab'],
                'expected' => [[
                    'type' => 'missing', 'loc' => ['body', 'content'], 'msg' => 'Field required',
                    'input' => ['title' => 'ab'],
                ]],
            ],
            'a one-character title' => [
                'body' => ['title' => 'a', 'content' => 'xy'],
                'expected' => [[
                    'type' => 'string_too_short', 'loc' => ['body', 'title'],
                    'msg' => 'String should have at least 2 characters', 'input' => 'a',
                    'ctx' => ['min_length' => 2],
                ]],
            ],
            'a whitespace-only title' => [
                'body' => ['title' => '  ', 'content' => 'xy'],
                'expected' => [[
                    'type' => 'value_error', 'loc' => ['body', 'title'],
                    'msg' => 'Value error, این فیلد الزامی است.', 'input' => '  ',
                    'ctx' => ['error' => []],
                ]],
            ],
            'a non-string title' => [
                'body' => ['title' => 5, 'content' => 'xy'],
                'expected' => [[
                    'type' => 'string_type', 'loc' => ['body', 'title'],
                    'msg' => 'Input should be a valid string', 'input' => 5,
                ]],
            ],
            'a too-long title' => [
                'body' => ['title' => str_repeat('a', 181), 'content' => 'xy'],
                'expected' => [[
                    'type' => 'string_too_long', 'loc' => ['body', 'title'],
                    'msg' => 'String should have at most 180 characters',
                    'input' => str_repeat('a', 181), 'ctx' => ['max_length' => 180],
                ]],
            ],
            'an unknown type' => [
                'body' => ['title' => 'ab', 'content' => 'xy', 'type' => 'nope'],
                'expected' => [[
                    'type' => 'literal_error', 'loc' => ['body', 'type'],
                    'msg' => "Input should be 'general', 'announcement', 'system', 'warning', 'information', 'success' or 'reminder'",
                    'input' => 'nope',
                    'ctx' => ['expected' => "'general', 'announcement', 'system', 'warning', 'information', 'success' or 'reminder'"],
                ]],
            ],
            'a null type' => [
                'body' => ['title' => 'ab', 'content' => 'xy', 'type' => null],
                'expected' => [[
                    'type' => 'literal_error', 'loc' => ['body', 'type'],
                    'msg' => "Input should be 'general', 'announcement', 'system', 'warning', 'information', 'success' or 'reminder'",
                    'input' => null,
                    'ctx' => ['expected' => "'general', 'announcement', 'system', 'warning', 'information', 'success' or 'reminder'"],
                ]],
            ],
            'an external action_url' => [
                'body' => ['title' => 'ab', 'content' => 'xy', 'action_url' => 'https://x.com'],
                'expected' => [[
                    'type' => 'value_error', 'loc' => ['body', 'action_url'],
                    'msg' => 'Value error, فقط پیوند داخلی با / مجاز است.', 'input' => 'https://x.com',
                    'ctx' => ['error' => []],
                ]],
            ],
            'a protocol-relative action_url' => [
                'body' => ['title' => 'ab', 'content' => 'xy', 'action_url' => '//evil.com'],
                'expected' => [[
                    'type' => 'value_error', 'loc' => ['body', 'action_url'],
                    'msg' => 'Value error, فقط پیوند داخلی با / مجاز است.', 'input' => '//evil.com',
                    'ctx' => ['error' => []],
                ]],
            ],
            'a whitespace-only action_url' => [
                'body' => ['title' => 'ab', 'content' => 'xy', 'action_url' => '  '],
                'expected' => [[
                    'type' => 'value_error', 'loc' => ['body', 'action_url'],
                    'msg' => 'Value error, فقط پیوند داخلی با / مجاز است.', 'input' => '  ',
                    'ctx' => ['error' => []],
                ]],
            ],
            'a targets that is not a list' => [
                'body' => ['title' => 'ab', 'content' => 'xy', 'targets' => 'abc'],
                'expected' => [[
                    'type' => 'list_type', 'loc' => ['body', 'targets'],
                    'msg' => 'Input should be a valid list', 'input' => 'abc',
                ]],
            ],
            'a targets item that is not a string' => [
                'body' => ['title' => 'ab', 'content' => 'xy', 'targets' => [1]],
                'expected' => [[
                    'type' => 'string_type', 'loc' => ['body', 'targets', 0],
                    'msg' => 'Input should be a valid string', 'input' => 1,
                ]],
            ],
            'a too-long action_label' => [
                'body' => ['title' => 'ab', 'content' => 'xy', 'action_label' => str_repeat('a', 81)],
                'expected' => [[
                    'type' => 'string_too_long', 'loc' => ['body', 'action_label'],
                    'msg' => 'String should have at most 80 characters',
                    'input' => str_repeat('a', 81), 'ctx' => ['max_length' => 80],
                ]],
            ],
        ];

        foreach ($cases as $label => $case) {
            $response = $this->asAdmin()->call(
                'POST',
                '/api/admin/notifications',
                [],
                [],
                [],
                ['CONTENT_TYPE' => 'application/json'],
                json_encode($case['body'])
            );

            $response->assertStatus(422, $label);
            $this->assertSame(['detail' => $case['expected']], $response->json(), $label);
        }
    }

    /**
     * The three model-level failures: no body at all, the literal `null`, and
     * unparseable JSON — all reported against `loc: ["body"]` alone.
     */
    #[Test]
    public function the_model_level_failures_are_reported_against_the_body_alone(): void
    {
        $this->asAdmin();

        $noBody = $this->post('/api/admin/notifications');
        $noBody->assertStatus(422);
        $this->assertSame(
            ['detail' => [['type' => 'missing', 'loc' => ['body'], 'msg' => 'Field required', 'input' => null]]],
            $noBody->json(),
        );

        $nullBody = $this->call('POST', '/api/admin/notifications', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'null');
        $nullBody->assertStatus(422);
        $this->assertSame(
            ['detail' => [['type' => 'missing', 'loc' => ['body'], 'msg' => 'Field required', 'input' => null]]],
            $nullBody->json(),
        );

        $badJson = $this->call('POST', '/api/admin/notifications', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json');
        $badJson->assertStatus(422);
        // The `input` is an empty JSON object — FastAPI's `jsonable_encoder` rendered
        // the `stdClass` placeholder as `{}`, which a decoded comparison cannot tell
        // apart from `[]`, so the raw bytes are asserted here.
        $this->assertStringContainsString('"input":{}', $badJson->getContent());
        $this->assertStringContainsString('"ctx":{"error":"Expecting value"}', $badJson->getContent());
    }

    /**
     * An empty `action_url` and an explicit `null` are both valid and both become
     * `null` — the validator's `value or None`.
     */
    #[Test]
    public function an_empty_or_null_action_url_is_valid_and_becomes_null(): void
    {
        $empty = NotificationInput::validate(json_encode(['title' => 'ab', 'content' => 'xy', 'action_url' => '']));
        $this->assertNull($empty['action_url']);

        $null = NotificationInput::validate(json_encode(['title' => 'ab', 'content' => 'xy', 'action_url' => null]));
        $this->assertNull($null['action_url']);

        // A leading space is stripped, not rejected.
        $stripped = NotificationInput::validate(json_encode(['title' => 'ab', 'content' => 'xy', 'action_url' => ' /user_panel']));
        $this->assertSame('/user_panel', $stripped['action_url']);
    }

    // ── The handler-level refusals ───────────────────────────────────────────

    /**
     * `_validate_target`'s two 422s are `{"detail": "…"}` — a single string, not the
     * pydantic list — and they fire before the handler touches the database.
     */
    #[Test]
    public function the_handler_level_refusals_are_single_string_422_bodies(): void
    {
        $this->asAdmin();

        $noTargets = $this->postJson('/api/admin/notifications', [
            'title' => 'عنوان',
            'content' => 'محتوا',
            'target_type' => 'selected',
            'targets' => [],
        ]);
        $noTargets->assertStatus(422);
        $this->assertSame(['detail' => 'حداقل یک مخاطب انتخاب کنید.'], $noTargets->json());

        $past = $this->postJson('/api/admin/notifications', [
            'title' => 'عنوان',
            'content' => 'محتوا',
            'status' => 'scheduled',
            'scheduled_at' => '2020-01-01T00:00:00Z',
        ]);
        $past->assertStatus(422);
        $this->assertSame(['detail' => 'زمان انتشار باید در آینده باشد.'], $past->json());

        $badFormat = $this->postJson('/api/admin/notifications', [
            'title' => 'عنوان',
            'content' => 'محتوا',
            'status' => 'scheduled',
            'scheduled_at' => 'not-a-date',
        ]);
        $badFormat->assertStatus(422);
        $this->assertSame(['detail' => 'زمان‌بندی نامعتبر است.'], $badFormat->json());
    }

    // ── The SSE contract ─────────────────────────────────────────────────────

    /**
     * The stream is a real `StreamedResponse` with the Python's headers — not a JSON
     * envelope and not a polling loop.  The test framework wraps the response without
     * sending it, so the infinite generator never runs here.
     */
    #[Test]
    public function the_stream_is_a_streamed_response_with_the_pythons_headers(): void
    {
        $response = $this->asUser()->get('/api/notifications/stream');

        $response->assertStreamed();
        // The `; charset=utf-8` suffix is Laravel's, and it is also what the running
        // Starlette server sends — verified against `127.0.0.1:5000`.
        $this->assertSame('text/event-stream; charset=utf-8', $response->headers->get('Content-Type'));
        // Symfony's ResponseHeaderBag normalises the Cache-Control the Python set
        // verbatim — it reorders the directives and appends `private`, so the wire
        // value is `must-revalidate, no-cache, no-store, private` where the running
        // server sends `no-cache, no-store, must-revalidate`.  The directives are
        // the same; the spelling is the framework's.
        $this->assertSame('must-revalidate, no-cache, no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame('no', $response->headers->get('X-Accel-Buffering'));
    }

    /**
     * The admin-stream alias is the same response behind the admin guard.
     */
    #[Test]
    public function the_admin_stream_is_the_same_stream_behind_the_admin_guard(): void
    {
        $response = $this->asAdmin()->get('/api/notifications/admin-stream');

        $response->assertStreamed();
        $this->assertSame('text/event-stream; charset=utf-8', $response->headers->get('Content-Type'));
    }

    /**
     * An event frame is `id: <notification id>\ndata: <json>\n\n`, and the heartbeat
     * is a comment frame — the two shapes the front-end's `EventSource` parses.
     */
    #[Test]
    public function an_event_frame_is_the_pythons_id_and_data_pair(): void
    {
        $item = [
            'id' => 10011,
            'title' => 'تیکت جدید',
            'read_at' => null,
        ];

        $this->assertSame(
            'id: 10011'."\n"
            .'data: '.json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n",
            NotificationStream::frame($item),
        );
    }

    #[Test]
    public function the_heartbeat_is_a_comment_frame(): void
    {
        $this->assertSame(": heartbeat\n\n", NotificationStream::heartbeat());
    }

    /**
     * The first cycle is silent unless the client sent `Last-Event-ID`; a resume then
     * replays every newer row **oldest first**.
     */
    #[Test]
    public function the_first_cycle_is_silent_unless_the_client_resumes(): void
    {
        $items = [
            ['id' => 3, 'title' => 'c'],
            ['id' => 2, 'title' => 'b'],
            ['id' => 1, 'title' => 'a'],
        ];

        $this->assertSame([], NotificationStream::diff($items, null, 0));

        $emit = NotificationStream::diff($items, null, 1);
        $this->assertSame([2, 3], array_column($emit, 'id'));
    }

    /**
     * A later cycle emits only the rows that are new since the previous one, oldest
     * first — and `seen` is replaced, not unioned, so a row that left the `TOP 100`
     * and came back is re-emitted.
     */
    #[Test]
    public function a_later_cycle_emits_only_new_rows_and_replaces_the_seen_set(): void
    {
        $emit = NotificationStream::diff([
            ['id' => 5, 'title' => 'e'],
            ['id' => 4, 'title' => 'd'],
        ], NotificationStream::seen([['id' => 5, 'title' => 'e']]), 0);
        $this->assertSame([4], array_column($emit, 'id'));

        // 4 fell off the TOP 100 and came back: `seen` was replaced by {5}, so 4 is
        // "new" again and is re-emitted.
        $emit = NotificationStream::diff([
            ['id' => 5, 'title' => 'e'],
            ['id' => 4, 'title' => 'd'],
        ], NotificationStream::seen([['id' => 5, 'title' => 'e']]), 0);
        $this->assertSame([4], array_column($emit, 'id'));
    }

    // ── Success shapes against the live database ─────────────────────────────

    /**
     * The admin list's envelope — `page_size`, not the master-admin module's
     * `per_page` — and the stats block with its integer counters.
     */
    #[Test]
    public function the_admin_list_envelope_matches_the_python(): void
    {
        $this->liveDatabase();
        $this->signInAsAdminOnLiveDb();

        $response = $this->get('/api/admin/notifications');

        $response->assertOk();
        $payload = $response->json();

        $this->assertArrayHasKey('items', $payload);
        $this->assertArrayHasKey('total', $payload);
        $this->assertArrayHasKey('page', $payload);
        $this->assertArrayHasKey('page_size', $payload);
        $this->assertArrayHasKey('pages', $payload);
        $this->assertArrayHasKey('stats', $payload);

        $this->assertSame(1, $payload['page']);
        $this->assertSame(15, $payload['page_size']);
        foreach (['total', 'published', 'draft', 'scheduled', 'archived'] as $key) {
            $this->assertTrue(
                $payload['stats'][$key] === null || is_int($payload['stats'][$key]),
                "stats.{$key} must be an int or null",
            );
        }

        if ($payload['items'] !== []) {
            $this->assertIsInt($payload['items'][0]['id']);
            $this->assertMatchesRegularExpression(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{6})?Z$/',
                $payload['items'][0]['created_at'],
            );
        }
    }

    /**
     * The parameter-order bug, reproduced: a filtered admin list answers `items: []`
     * while `total` is correct, because the `admin_read_at` subquery's placeholder
     * precedes the WHERE placeholders while the bindings put the filter values first.
     */
    #[Test]
    public function a_filtered_admin_list_answers_an_empty_items_list_with_a_correct_total(): void
    {
        $this->liveDatabase();
        $this->signInAsAdminOnLiveDb();

        $this->seedPublishedNotificationFor('ali');

        $response = $this->get('/api/admin/notifications?status=published');

        $response->assertOk();
        $payload = $response->json();

        $this->assertSame([], $payload['items'], 'the shifted bindings must match no rows');
        $this->assertGreaterThanOrEqual(1, $payload['total'], 'the COUNT query is bound correctly');

        // Without a filter the same table lists rows.
        $unfiltered = $this->get('/api/admin/notifications')->json();
        $this->assertNotEmpty($unfiltered['items']);
    }

    /**
     * The user list's envelope — it carries `unread` and **no** `page_size`, where the
     * admin list carries `page_size` and no `unread`.
     */
    #[Test]
    public function the_user_list_envelope_matches_the_python(): void
    {
        $this->liveDatabase();
        $this->signInAsAdminOnLiveDb();

        $id = $this->seedPublishedNotificationFor('ali');

        $response = $this->get('/api/notifications');

        $response->assertOk();
        $payload = $response->json();

        $this->assertArrayHasKey('items', $payload);
        $this->assertArrayHasKey('total', $payload);
        $this->assertArrayHasKey('unread', $payload);
        $this->assertArrayHasKey('page', $payload);
        $this->assertArrayHasKey('pages', $payload);
        $this->assertArrayNotHasKey('page_size', $payload);

        $this->assertContains($id, array_column($payload['items'], 'id'));
    }

    /**
     * The unread count, the poll snapshot and the single-notification detail.
     */
    #[Test]
    public function the_unread_count_poll_and_detail_match_the_python(): void
    {
        $this->liveDatabase();
        $this->signInAsAdminOnLiveDb();

        $id = $this->seedPublishedNotificationFor('ali');

        $count = $this->get('/api/notifications/unread-count');
        $count->assertOk();
        $this->assertIsInt($count->json()['unread']);

        $poll = $this->get('/api/notifications/poll');
        $poll->assertOk();
        $this->assertArrayHasKey('notifications', $poll->json());
        $this->assertContains($id, array_column($poll->json()['notifications'], 'id'));

        $detail = $this->get('/api/notifications/'.$id);
        $detail->assertOk();
        $this->assertSame($id, $detail->json()['id']);
        $this->assertArrayHasKey('delivered_at', $detail->json());
        $this->assertArrayHasKey('read_at', $detail->json());

        $missing = $this->get('/api/notifications/99999999');
        $missing->assertStatus(404);
        $this->assertSame(['detail' => 'اعلان برای شما پیدا نشد.'], $missing->json());
    }

    /**
     * The audience picker's three keys.
     */
    #[Test]
    public function the_target_options_answer_users_departments_and_roles(): void
    {
        $this->liveDatabase();
        $this->signInAsAdminOnLiveDb();

        $response = $this->get('/api/admin/notification-targets');

        $response->assertOk();
        $payload = $response->json();

        $this->assertArrayHasKey('users', $payload);
        $this->assertArrayHasKey('departments', $payload);
        $this->assertArrayHasKey('roles', $payload);

        $this->assertSame($payload['departments'], $this->sortedStrings($payload['departments']));
        $this->assertSame($payload['roles'], $this->sortedStrings($payload['roles']));

        if ($payload['users'] !== []) {
            $this->assertSame(
                ['username', 'name', 'last_name', 'department', 'role'],
                array_keys($payload['users'][0]),
            );
        }
    }

    /**
     * Create → update → publish → read → unread → dismiss, the whole lifecycle.
     */
    #[Test]
    public function the_notification_lifecycle_matches_the_python(): void
    {
        $this->liveDatabase();
        $this->signInAsAdminOnLiveDb();

        // Create a draft.
        $created = $this->postJson('/api/admin/notifications', [
            'title' => ' عنوان آزمایشی ',
            'content' => ' محتوای آزمایشی ',
            'target_type' => 'selected',
            'targets' => ['ali', 'ali', ' aslnia '],
        ]);
        $created->assertStatus(201);
        $id = $created->json()['id'];
        $this->assertSame('draft', $created->json()['status']);
        $this->assertSame(0, $created->json()['delivered']);

        // The title and content were stripped by the model's field validator.
        $stored = DB::table('notifications')->where('id', $id)->first();
        $this->assertSame('عنوان آزمایشی', $stored->title);
        $this->assertSame('محتوای آزمایشی', $stored->content);

        // Update it to published — the fan-out reaches both targets.
        $updated = $this->putJson('/api/admin/notifications/'.$id, [
            'title' => ' ویرایش شده ',
            'content' => ' محتوای ویرایش‌شده ',
            'status' => 'published',
            'target_type' => 'selected',
            'targets' => ['ali', 'aslnia'],
        ]);
        $updated->assertOk();
        $this->assertSame('published', $updated->json()['status']);
        $this->assertSame(2, $updated->json()['delivered']);

        // The targets were replaced wholesale.
        $targetValues = DB::table('notification_targets')
            ->where('notification_id', $id)
            ->pluck('target_value')
            ->all();
        $this->assertSame(['ali', 'aslnia'], $targetValues);

        // Read, then unread, for the signed-in admin.
        $this->post('/api/admin/notifications/'.$id.'/read')->assertOk();
        $this->assertNotNull(
            DB::table('user_notifications')->where('notification_id', $id)->where('username', 'ali')->value('read_at'),
        );

        $this->post('/api/admin/notifications/'.$id.'/unread')->assertOk();
        $this->assertNull(
            DB::table('user_notifications')->where('notification_id', $id)->where('username', 'ali')->value('read_at'),
        );

        // The user's own read-state transitions.
        $this->post('/api/notifications/'.$id.'/read')->assertOk();
        $this->assertNotNull(
            DB::table('user_notifications')->where('notification_id', $id)->where('username', 'ali')->value('read_at'),
        );

        // Dismiss hides it from the user's inbox.
        $this->delete('/api/notifications/'.$id)->assertOk();
        $this->assertNotNull(
            DB::table('user_notifications')->where('notification_id', $id)->where('username', 'ali')->value('dismissed_at'),
        );
        $this->get('/api/notifications/'.$id)->assertStatus(404);
    }

    /**
     * A published notification cannot be updated (409) and an archived one cannot be
     * published (409); a missing row is a 404 everywhere.
     */
    #[Test]
    public function the_write_refusals_match_the_python(): void
    {
        $this->liveDatabase();
        $this->signInAsAdminOnLiveDb();

        $id = $this->seedPublishedNotificationFor('ali');

        $update = $this->putJson('/api/admin/notifications/'.$id, [
            'title' => 'ویرایش',
            'content' => 'محتوای ویرایش',
        ]);
        $update->assertStatus(409);
        $this->assertSame(['detail' => 'فقط پیش‌نویس یا اعلان زمان‌بندی‌شده قابل ویرایش است.'], $update->json());

        $this->post('/api/admin/notifications/'.$id.'/archive')->assertOk();

        $publish = $this->post('/api/admin/notifications/'.$id.'/publish');
        $publish->assertStatus(409);
        $this->assertSame(['detail' => 'اعلان بایگانی‌شده قابل انتشار نیست.'], $publish->json());

        $this->post('/api/admin/notifications/99999999/publish')->assertStatus(404);
        $this->assertSame(['detail' => 'اعلان پیدا نشد.'], $this->post('/api/admin/notifications/99999999/publish')->json());

        $this->delete('/api/admin/notifications/99999999')->assertStatus(404);
        $this->post('/api/admin/notifications/99999999/archive')->assertStatus(404);
        $this->post('/api/admin/notifications/99999999/read')->assertStatus(404);
        $this->post('/api/notifications/99999999/read')->assertStatus(404);
    }

    /**
     * `read-all` counts every matched row, not only the newly-read ones — the UPDATE's
     * rowcount, which a second call reproduces.
     */
    #[Test]
    public function read_all_counts_every_matched_row(): void
    {
        $this->liveDatabase();
        $this->signInAsAdminOnLiveDb();

        $this->seedPublishedNotificationFor('ali');

        $first = $this->post('/api/admin/notifications/read-all');
        $first->assertOk();
        $firstUpdated = $first->json()['updated'];
        $this->assertGreaterThanOrEqual(1, $firstUpdated);

        $second = $this->post('/api/admin/notifications/read-all');
        $second->assertOk();
        $this->assertSame($firstUpdated, $second->json()['updated']);
    }

    // ── Live-database helpers ───────────────────────────────────────────────

    /**
     * Sign in as the live `ali` administrator, with a registry row inside the
     * transaction that is rolled back.
     *
     * `ali` is used rather than "the first admin" because the seeded notifications are
     * addressed to that account — the user list, the unread count and the detail view
     * are all per-user, and a different admin would see an empty inbox.
     */
    private function signInAsAdminOnLiveDb(): void
    {
        $user = User::query()->whereRaw("LTRIM(RTRIM(LOWER(role))) = 'admin'")->whereRaw('RTRIM(username) = ?', ['ali'])->first();
        $this->assertNotNull($user, 'the live database must hold the ali admin account');

        $this->actingAs($user);

        $token = app(SessionRegistry::class)->newToken();
        $this->assertTrue(
            app(SessionRegistry::class)->register($token, $user->username(), '127.0.0.1', 'NotificationsTest'),
            'the session registry row must be written for the request to be permitted',
        );

        $this->withSession([
            'username' => $user->username(),
            SessionRegistry::SESSION_TOKEN_KEY => $token,
            SessionRegistry::SESSION_ISSUED_KEY => time(),
            'is_admin' => true,
        ]);
    }

    /**
     * Insert one published notification addressed to `$username` (with its fan-out row)
     * and return its id.
     */
    private function seedPublishedNotificationFor(string $username): int
    {
        $id = (int) DB::table('notifications')->insertGetId([
            'title' => 'اعلان آزمایشی',
            'content' => 'محتوای آزمایشی',
            'type' => 'general',
            'priority' => 'normal',
            'status' => 'published',
            'target_type' => 'selected',
            'action_label' => null,
            'action_url' => null,
            'created_by' => 'NotificationsTest',
        ]);

        DB::table('notification_targets')->insert([
            'notification_id' => $id,
            'target_value' => $username,
        ]);

        DB::table('user_notifications')->insert([
            'notification_id' => $id,
            'username' => $username,
        ]);

        return $id;
    }

    /**
     * @param  array<int, string>  $values
     * @return array<int, string>
     */
    private function sortedStrings(array $values): array
    {
        $sorted = $values;
        sort($sorted, SORT_STRING);

        return $sorted;
    }
}
