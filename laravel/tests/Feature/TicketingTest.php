<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Auth\SessionRegistry;
use App\Support\Automation\PrivateAttachmentStore;
use App\Support\Tickets\TicketService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The ticketing route group: the normalized `/api/tickets/*` surface, the
 * anonymous `/public/support-ticket` form, and the legacy `ticket_table`
 * 410 stubs.
 *
 * The default suite is **offline**, and the reason is the Python's own
 * ordering: FastAPI validates a request's path, query and body *before* the
 * handler's first line, so every guard and validation below is reachable
 * without a session and without a database.  That is most of the contract —
 * the 401s, the 422 bodies, the 403 cross-site refusals, the upload
 * allow-list, the public form's 400s and the legacy 410s.
 *
 * The parts that genuinely need the live `userDB` — the success shapes, the
 * ownership refusals, the notification side effects and the authorised
 * upload+download round-trip — are env-gated on `HASTAMA_DB_TESTS=1` and run
 * inside a transaction that is always rolled back, exactly as
 * `LegacyReadDatabaseTest` does.
 */
final class TicketingTest extends TestCase
{
    /** A valid `TicketCreate` body, for requests that must pass validation. */
    private const VALID_TICKET = [
        'recipient_username' => 'ali',
        'subject' => 'subject',
        'body' => 'body',
    ];

    // ── The anonymous guard matrix ─────────────────────────────────────────

    /**
     * Every `/api/tickets/*` route refuses an anonymous request with the
     * handler's own 401 — FastAPI's `{"detail": …}` body, not the middleware's
     * session-expired envelope and not the admin guard's `success: false`.
     *
     * Each request carries **valid** input, so the refusal is the handler's
     * `_actor` and not a validation error that would have fired first.
     *
     * @return array<string, array{string, string, array<string, mixed>|string|null}>
     */
    public static function anonymousApiRequests(): array
    {
        return [
            'categories' => ['GET', '/api/tickets/categories', null],
            'users' => ['GET', '/api/tickets/users', null],
            'index' => ['GET', '/api/tickets', null],
            'store' => ['POST', '/api/tickets', self::VALID_TICKET],
            'show' => ['GET', '/api/tickets/1', null],
            'messages' => ['POST', '/api/tickets/1/messages', ['body' => 'hello']],
            // `TicketUpdate` is all-optional, so `{}` is a valid body — sent raw
            // because `json_encode([])` is `[]`, a JSON array, not an object.
            'update' => ['PATCH', '/api/tickets/1', '{}'],
            'attachments download' => ['GET', '/api/tickets/1/attachments/1', null],
        ];
    }

    #[DataProvider('anonymousApiRequests')]
    #[Test]
    public function an_anonymous_api_request_is_refused_by_the_handlers_own_401(string $method, string $uri, array|string|null $body): void
    {
        $response = $body === null
            ? $this->json($method, $uri)
            : (is_string($body)
                ? $this->call($method, $uri, [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)
                : $this->json($method, $uri, $body));

        $response->assertStatus(401);
        $response->assertExactJson(['detail' => 'برای ادامه وارد سامانه شوید.']);
    }

    /**
     * The upload route's anonymous 401, with a real file part — the file is
     * validated (and would be refused) before the actor check, so a valid one
     * reaches the guard.
     */
    #[Test]
    public function an_anonymous_upload_is_refused_by_the_handlers_own_401(): void
    {
        $this->post('/api/tickets/1/attachments', [
            'file' => UploadedFile::fake()->create('note.pdf', 1),
        ])
            ->assertStatus(401)
            ->assertExactJson(['detail' => 'برای ادامه وارد سامانه شوید.']);
    }

    // ── FastAPI's 422 bodies ──────────────────────────────────────────────

    /**
     * The query parameters are the Python's declaration, and a request with two
     * bad values reports both — as FastAPI does — in declaration order.
     */
    #[Test]
    public function the_list_query_parameters_are_validated_in_declaration_order(): void
    {
        $this->get('/api/tickets?page=0&page_size=4')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [
                [
                    'type' => 'greater_than_equal',
                    'loc' => ['query', 'page'],
                    'msg' => 'Input should be greater than or equal to 1',
                    'input' => '0',
                    'ctx' => ['ge' => 1],
                ],
                [
                    'type' => 'greater_than_equal',
                    'loc' => ['query', 'page_size'],
                    'msg' => 'Input should be greater than or equal to 5',
                    'input' => '4',
                    'ctx' => ['ge' => 5],
                ],
            ]]);

        $this->get('/api/tickets?page=abc')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.loc', ['query', 'page'])
            ->assertJsonPath('detail.0.input', 'abc');

        $this->get('/api/tickets?page_size=101')
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'less_than_equal')
            ->assertJsonPath('detail.0.ctx', ['le' => 100]);

        // `max_length` counts characters, not bytes — the text is Persian.
        $this->get('/api/tickets?search='.str_repeat('ی', 101))
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_long')
            ->assertJsonPath('detail.0.loc', ['query', 'search'])
            ->assertJsonPath('detail.0.ctx', ['max_length' => 100]);
    }

    /**
     * A bad path segment is refused before the handler, with `path` in `loc`
     * where the query parameters carry `query`.
     */
    #[Test]
    public function a_non_integer_ticket_id_is_a_422_path_failure(): void
    {
        $this->get('/api/tickets/abc')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'int_parsing',
                'loc' => ['path', 'ticket_id'],
                'msg' => 'Input should be a valid integer, unable to parse string as an integer',
                'input' => 'abc',
            ]]]);
    }

    /**
     * `TicketCreate`'s field failures, in the model's declaration order, with
     * `input` echoing the whole body for a missing field.
     */
    #[Test]
    public function the_create_body_reports_every_missing_field_against_the_whole_body(): void
    {
        // Sent raw: `json_encode([])` is `[]`, a JSON array, which FastAPI
        // reports as `model_attributes_type` rather than a missing field.
        $this->call('POST', '/api/tickets', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [
                [
                    'type' => 'missing',
                    'loc' => ['body', 'recipient_username'],
                    'msg' => 'Field required',
                    'input' => [],
                ],
                [
                    'type' => 'missing',
                    'loc' => ['body', 'subject'],
                    'msg' => 'Field required',
                    'input' => [],
                ],
                [
                    'type' => 'missing',
                    'loc' => ['body', 'body'],
                    'msg' => 'Field required',
                    'input' => [],
                ],
            ]]);
    }

    /**
     * The `strip_text` field validator runs **after** the length constraints,
     * so a two-space `recipient_username` passes `min_length=1` and is then
     * refused by the validator — a `value_error`, not a `string_too_short`.
     */
    #[Test]
    public function a_whitespace_only_field_is_refused_by_the_strip_validator(): void
    {
        $this->postJson('/api/tickets', [
            'recipient_username' => '  ',
            'subject' => 'ab',
            'body' => 'ab',
        ])
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'value_error',
                'loc' => ['body', 'recipient_username'],
                'msg' => 'Value error, این فیلد الزامی است.',
                'input' => '  ',
                // FastAPI's `jsonable_encoder` has no representation for the
                // `ValueError` itself, so `ctx.error` reaches the wire as {}.
                'ctx' => ['error' => []],
            ]]]);
    }

    /**
     * The length bounds run on the raw string, before the strip — so a
     * one-character subject is `string_too_short`, and the singular
     * `1 character` of `min_length=1` is what the running server answers.
     */
    #[Test]
    public function the_length_bounds_run_before_the_strip(): void
    {
        $this->postJson('/api/tickets', [
            'recipient_username' => 'u',
            'subject' => 'a',
            'body' => 'ab',
        ])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_short')
            ->assertJsonPath('detail.0.loc', ['body', 'subject'])
            ->assertJsonPath('detail.0.msg', 'String should have at least 2 characters')
            ->assertJsonPath('detail.0.ctx', ['min_length' => 2]);

        // The singular `1 character` belongs to `recipient_username`'s
        // `min_length=1`; `body` is bounded at 2.
        $this->postJson('/api/tickets', [
            'recipient_username' => '',
            'subject' => 'ab',
            'body' => 'ab',
        ])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_short')
            ->assertJsonPath('detail.0.loc', ['body', 'recipient_username'])
            ->assertJsonPath('detail.0.msg', 'String should have at least 1 character');
    }

    /**
     * `category_id: int | None` — pydantic's lax integer parse, verified
     * against the running server.
     */
    #[Test]
    public function category_id_accepts_the_lax_integer_grammar_and_refuses_the_rest(): void
    {
        // A numeric string, an integral float and a bool are all integers.
        foreach (['5', 2.0, true] as $accepted) {
            $this->postJson('/api/tickets', array_merge(self::VALID_TICKET, ['category_id' => $accepted]))
                ->assertStatus(401, 'category_id '.var_export($accepted, true).' must pass validation')
                ->assertExactJson(['detail' => 'برای ادامه وارد سامانه شوید.']);
        }

        // A fractional float is `int_from_float`, echoing the value as received.
        $this->postJson('/api/tickets', array_merge(self::VALID_TICKET, ['category_id' => 1.5]))
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'int_from_float',
                'loc' => ['body', 'category_id'],
                'msg' => 'Input should be a valid integer, got a number with a fractional part',
                'input' => 1.5,
            ]]]);

        // A non-numeric string is `int_parsing`.
        $this->postJson('/api/tickets', array_merge(self::VALID_TICKET, ['category_id' => 'abc']))
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.msg', 'Input should be a valid integer, unable to parse string as an integer');

        // Below the `ge=1` bound, echoing the value **as received**.
        $this->postJson('/api/tickets', array_merge(self::VALID_TICKET, ['category_id' => 0]))
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'greater_than_equal',
                'loc' => ['body', 'category_id'],
                'msg' => 'Input should be greater than or equal to 1',
                'input' => 0,
                'ctx' => ['ge' => 1],
            ]]]);
    }

    /**
     * `MessageCreate` and `TicketUpdate` — the two smaller models.
     */
    #[Test]
    public function the_message_and_update_models_are_validated(): void
    {
        $this->call('POST', '/api/tickets/1/messages', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [[
                'type' => 'missing',
                'loc' => ['body', 'body'],
                'msg' => 'Field required',
                'input' => [],
            ]]]);

        $this->postJson('/api/tickets/1/messages', ['body' => '  '])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'value_error')
            ->assertJsonPath('detail.0.msg', 'Value error, متن پیام الزامی است.');

        $this->postJson('/api/tickets/1/messages', ['body' => 'hi', 'visibility' => str_repeat('v', 17)])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_long')
            ->assertJsonPath('detail.0.loc', ['body', 'visibility'])
            ->assertJsonPath('detail.0.ctx', ['max_length' => 16]);

        // `TicketUpdate` is all-optional: `{}` is a valid body.
        $this->call('PATCH', '/api/tickets/1', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(401)
            ->assertExactJson(['detail' => 'برای ادامه وارد سامانه شوید.']);

        $this->patchJson('/api/tickets/1', ['status' => str_repeat('s', 33)])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_too_long')
            ->assertJsonPath('detail.0.loc', ['body', 'status'])
            ->assertJsonPath('detail.0.ctx', ['max_length' => 32]);

        // An explicit `null` is accepted by `str | None` and refused by a plain
        // `str` with a default — the two optional kinds disagree.
        $this->patchJson('/api/tickets/1', ['status' => null])
            ->assertStatus(401)
            ->assertExactJson(['detail' => 'برای ادامه وارد سامانه شوید.']);

        $this->postJson('/api/tickets', array_merge(self::VALID_TICKET, ['priority' => null]))
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'string_type')
            ->assertJsonPath('detail.0.loc', ['body', 'priority']);
    }

    /**
     * The upload's three validations are merged into one `422` — path, then
     * `message_id`, then the file — which is the order FastAPI reports them in.
     */
    #[Test]
    public function the_upload_validates_path_query_and_file_in_one_detail_list(): void
    {
        // No file part at all: all three failures are reported together, path
        // first — captured from the running FastAPI server.
        $this->post('/api/tickets/abc/attachments?message_id=0')
            ->assertStatus(422)
            ->assertExactJson(['detail' => [
                [
                    'type' => 'int_parsing',
                    'loc' => ['path', 'ticket_id'],
                    'msg' => 'Input should be a valid integer, unable to parse string as an integer',
                    'input' => 'abc',
                ],
                [
                    'type' => 'greater_than_equal',
                    'loc' => ['query', 'message_id'],
                    'msg' => 'Input should be greater than or equal to 1',
                    'input' => '0',
                    'ctx' => ['ge' => 1],
                ],
                [
                    'type' => 'missing',
                    'loc' => ['body', 'file'],
                    'msg' => 'Field required',
                    'input' => null,
                ],
            ]]);

        // With a valid file part, only the path and query failures remain.
        $this->post('/api/tickets/abc/attachments?message_id=0', [
            'file' => UploadedFile::fake()->create('note.pdf', 1),
        ])
            ->assertStatus(422)
            ->assertExactJson(['detail' => [
                [
                    'type' => 'int_parsing',
                    'loc' => ['path', 'ticket_id'],
                    'msg' => 'Input should be a valid integer, unable to parse string as an integer',
                    'input' => 'abc',
                ],
                [
                    'type' => 'greater_than_equal',
                    'loc' => ['query', 'message_id'],
                    'msg' => 'Input should be greater than or equal to 1',
                    'input' => '0',
                    'ctx' => ['ge' => 1],
                ],
            ]]);

        // A present-but-invalid `message_id` alone.
        $this->post('/api/tickets/1/attachments?message_id=abc', [
            'file' => UploadedFile::fake()->create('note.pdf', 1),
        ])
            ->assertStatus(422)
            ->assertJsonPath('detail.0.type', 'int_parsing')
            ->assertJsonPath('detail.0.loc', ['query', 'message_id']);
    }

    // ── The upload allow-list ─────────────────────────────────────────────

    /**
     * What the store does with a **traversal filename** and a **magic-byte
     * mismatch** — the two cases where a stricter implementation would refuse
     * and the running server does not.
     *
     * The Python's `store_private_attachment` performs **no magic-byte check**:
     * it reads the multipart filename and content type and nothing else (the
     * signature check lives in `app/main.py` and `app/api/routes/call_system.py`,
     * on other routes).  A `.pdf` that is really a PNG is accepted when it
     * declares `application/pdf`, and the traversal in the filename is
     * neutralised by `pathlib`'s basename rather than refused — both reproduced
     * here because both are what the running server does.
     *
     * The store's three refusals (extension, content type, size) are **not**
     * reachable anonymously: the route reads the actor before it reads the
     * bytes, so they are exercised in the live-database section below.
     */
    #[Test]
    public function the_store_sanitises_the_filename_and_performs_no_signature_check(): void
    {
        // Kept off the real private root: the store writes real bytes.
        $root = sys_get_temp_dir().'/ticketing-store-test';
        config(['hastama.ticketing_private_dir' => $root]);

        $store = new PrivateAttachmentStore;

        // `Path(str(name).replace("\\", "/")).name` — the traversal is stripped,
        // not refused, and the stored name is the sanitised one.
        $metadata = $store->store('../../etc/passwd.pdf', 'application/pdf', 'bytes');

        $this->assertSame('passwd.pdf', $metadata['original_name']);
        $this->assertSame('application/pdf', $metadata['content_type']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}\.pdf$/', $metadata['storage_name']);

        // A `.pdf` whose bytes are not a PDF: accepted, because the Python
        // validates the extension and the declared type and nothing else.  The
        // declared type matches the extension, so only the bytes could give it
        // away — and nothing checks them.
        $fake = $store->store('fake.pdf', 'application/pdf', 'not-a-pdf-at-all');

        $this->assertSame('fake.pdf', $fake['original_name']);
        $this->assertSame('application/pdf', $fake['content_type']);

        // The storage name is a random hex digest under the private root, so it
        // can never address a file outside it.
        $this->assertNull($store->resolve('../../etc/passwd'));
        $this->assertNotNull($store->resolve($metadata['storage_name']));

        // Cleanup the two bytes this test wrote.
        foreach (glob($root.'/*') ?: [] as $stored) {
            @unlink($stored);
        }
        @rmdir($root);
    }

    // ── The same-origin check ─────────────────────────────────────────────

    /**
     * `_assert_same_origin` compares the whole origin against `base_url`, and
     * runs after the body validation but before the actor — so a cross-site
     * write with a valid body and no session is a 403, not a 401.
     */
    #[Test]
    public function a_cross_site_origin_is_refused_before_the_actor_check(): void
    {
        $this->postJson('/api/tickets', self::VALID_TICKET, ['Origin' => 'http://evil.example'])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'درخواست از مبدأ مجاز نیست.']);

        $this->postJson('/api/tickets/1/messages', ['body' => 'hi'], ['Origin' => 'http://evil.example'])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'درخواست از مبدأ مجاز نیست.']);

        $this->call('PATCH', '/api/tickets/1', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://evil.example'], '{}')
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'درخواست از مبدأ مجاز نیست.']);

        $this->post('/api/tickets/1/attachments', [
            'file' => UploadedFile::fake()->create('note.pdf', 1),
        ], ['Origin' => 'http://evil.example'])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'درخواست از مبدأ مجاز نیست.']);
    }

    /**
     * An absent `Origin` is allowed — a non-browser client, or a same-origin
     * navigation, does not send one — so the request reaches the actor check.
     */
    #[Test]
    public function a_missing_origin_is_allowed_through_to_the_actor_check(): void
    {
        $this->postJson('/api/tickets', self::VALID_TICKET)
            ->assertStatus(401)
            ->assertExactJson(['detail' => 'برای ادامه وارد سامانه شوید.']);
    }

    // ── The legacy 410 stubs ──────────────────────────────────────────────

    /**
     * Every legacy `ticket_table` handler is a `410 Gone` stub whose `return`
     * is the first statement — no session is read, no query runs.  The two
     * body shapes are both live.
     *
     * @return array<string, array{string, string}>
     */
    public static function legacyRoutes(): array
    {
        return [
            'get_ticket_requests_admin' => ['GET', '/get_ticket_requests_admin'],
            'delete-ticket' => ['POST', '/delete-ticket'],
            'update_ticket' => ['POST', '/update_ticket'],
            'get_ticket_requests' => ['GET', '/get_ticket_requests'],
            'update_ticket_status' => ['POST', '/update_ticket_status'],
            'add_ticket_response' => ['POST', '/add_ticket_response'],
            'add_ticket_response_userpanel' => ['POST', '/add_ticket_response_userpanel'],
            'mark_ticket_as_read' => ['POST', '/mark_ticket_as_read/1'],
        ];
    }

    #[DataProvider('legacyRoutes')]
    #[Test]
    public function a_legacy_ticket_route_answers_410_with_the_success_key(string $method, string $uri): void
    {
        $this->json($method, $uri)
            ->assertStatus(410)
            ->assertExactJson(['success' => false, 'error' => 'این مسیر قدیمی تیکت منسوخ شده است.']);
    }

    /**
     * The two detail views never had the `success` key at all.
     */
    #[Test]
    public function the_legacy_detail_views_answer_410_without_the_success_key(): void
    {
        $this->get('/get_ticket_details/1')
            ->assertStatus(410)
            ->assertExactJson(['error' => 'این مسیر قدیمی تیکت منسوخ شده است.']);

        $this->get('/get_ticket_details_payam/1')
            ->assertStatus(410)
            ->assertExactJson(['error' => 'این مسیر قدیمی تیکت منسوخ شده است.']);
    }

    // ── The anonymous public form ─────────────────────────────────────────

    /**
     * `POST /public/support-ticket` — the field refusals, in the Python's
     * order, each a `400 {"success": false, "message": …}`.
     */
    #[Test]
    public function the_public_form_refuses_each_missing_field_in_order(): void
    {
        $this->call('POST', '/public/support-ticket', [], [], [], ['CONTENT_TYPE' => 'application/json'], 'not json')
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'داده نامعتبر.']);

        // An empty object — sent raw, because `json_encode([])` is `[]`, a JSON
        // array, which is the 500 above rather than a missing field.
        $this->call('POST', '/public/support-ticket', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'لطفاً نام و نام خانوادگی را وارد کنید.']);

        $this->postJson('/public/support-ticket', ['full_name' => 'نام'])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'لطفاً شماره تماس را وارد کنید.']);

        $this->postJson('/public/support-ticket', ['full_name' => str_repeat('ن', 201), 'phone' => '09123456789'])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'نام بیش از حد طولانی است.']);

        $this->postJson('/public/support-ticket', ['full_name' => 'نام', 'phone' => '0912345678901234567890'])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'شماره تماس نامعتبر است.']);

        $this->postJson('/public/support-ticket', [
            'full_name' => 'نام',
            'phone' => '09123456789',
            'description' => str_repeat('ت', 2001),
        ])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'توضیحات بیش از حد طولانی است.']);
    }

    /**
     * A body that parses to a non-object is the Python's `AttributeError`:
     * `body.get(...)` on a list, and Starlette's plain-text 500.
     */
    #[Test]
    public function a_non_object_body_to_the_public_form_is_a_plain_text_500(): void
    {
        $this->call('POST', '/public/support-ticket', [], [], [], ['CONTENT_TYPE' => 'application/json'], '[1, 2]')
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');

        $this->call('POST', '/public/support-ticket', [], [], [], ['CONTENT_TYPE' => 'application/json'], '5')
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * A non-string `full_name` is the same `AttributeError` path.
     */
    #[Test]
    public function a_non_string_full_name_to_the_public_form_is_a_plain_text_500(): void
    {
        $this->postJson('/public/support-ticket', ['full_name' => 5, 'phone' => '09123456789'])
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'text/plain; charset=utf-8');
    }

    /**
     * Offline, the rate-limit count query has no `ticket_messages` table to
     * read, and the Python **fails closed**: the request is refused with a 503
     * rather than allowed through unthrottled.
     */
    #[Test]
    public function the_public_form_fails_closed_when_the_rate_limit_store_is_unavailable(): void
    {
        // The fail-closed 503 is only observable when the rate-limit query
        // cannot run — which, offline, is the missing table.  With the live
        // database on, the query succeeds and the request proceeds.
        if ($this->liveDatabaseEnabled()) {
            $this->markTestSkipped('The fail-closed path needs the rate-limit store to be unavailable.');
        }

        $this->postJson('/public/support-ticket', [
            'full_name' => 'نام و نام خانوادگی',
            'phone' => '09123456789',
        ])
            ->assertStatus(503)
            ->assertExactJson(['success' => false, 'message' => 'سرویس موقتاً در دسترس نیست. لطفاً کمی بعد تلاش کنید.']);
    }

    // ── Live-database verification (HASTAMA_DB_TESTS=1) ───────────────────
    //
    // The success shapes, the ownership refusals, the notification side effects
    // and the authorised upload+download round-trip need the real `userDB`.
    // Every test runs inside a transaction that is always rolled back, so the
    // rows are left exactly as they were found.  The one thing a rollback cannot
    // undo is a file on disk, so the round-trip test points the private root at
    // a temporary directory and removes it afterwards.

    /** The temporary upload root, when the live-database section is running. */
    private string $privateDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->liveDatabaseEnabled()) {
            return;
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

        // The registry lookup is cached; a stale `false` from another test would
        // turn every request into a 401 and the failure would look like a bug.
        $this->app['cache']->flush();

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        if (DB::connection()->transactionLevel() > 0) {
            DB::rollBack();
        }

        if ($this->privateDir !== '') {
            foreach (glob($this->privateDir.'/*') ?: [] as $stored) {
                @unlink($stored);
            }

            @rmdir($this->privateDir);
        }

        parent::tearDown();
    }

    /** Whether the live-database section is enabled. */
    private function liveDatabaseEnabled(): bool
    {
        return filter_var((string) env('HASTAMA_DB_TESTS', '0'), FILTER_VALIDATE_BOOL);
    }

    /** Skip unless `HASTAMA_DB_TESTS=1`. */
    private function requireLiveDatabase(): void
    {
        if (! $this->liveDatabaseEnabled()) {
            $this->markTestSkipped('Live-database verification is opt-in: set HASTAMA_DB_TESTS=1 to run it.');
        }
    }

    /**
     * Establish the session a browser would hold: the Laravel guard identity, the
     * legacy session flags, and an **active registry row** — which is what makes
     * `legacy.session:optional` permit the request.
     */
    private function signIn(User $user, bool $isMasterAdmin = false): static
    {
        $this->username = $user->username();
        $this->token = app(SessionRegistry::class)->newToken();

        $this->assertTrue(
            app(SessionRegistry::class)->register($this->token, $this->username, '127.0.0.1', 'TicketingTest'),
            'the session registry row must be written for the request to be permitted',
        );

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

    /** An ordinary (non-admin) user. */
    private function ordinaryUser(): User
    {
        return User::query()->whereRaw('LTRIM(RTRIM(LOWER(role))) <> ?', ['admin'])->first()
            ?? User::query()->first();
    }

    /** A master administrator. */
    private function masterUser(): User
    {
        return User::query()->whereRaw("LTRIM(RTRIM(LOWER(role))) = 'admin'")->first()
            ?? User::query()->first();
    }

    // ── Reads ─────────────────────────────────────────────────────────────

    #[Test]
    public function an_authenticated_user_can_list_categories(): void
    {
        $this->requireLiveDatabase();
        $this->signIn($this->ordinaryUser());

        $this->get('/api/tickets/categories')
            ->assertOk()
            ->assertJson(['items' => (new TicketService)->categories()]);
    }

    /**
     * The recipient picker is the master admins minus the caller — the same
     * rule `create_ticket` enforces on the write.
     */
    #[Test]
    public function the_recipient_picker_offers_only_master_admins_and_never_the_caller(): void
    {
        $this->requireLiveDatabase();
        $user = $this->ordinaryUser();
        $this->signIn($user);

        $response = $this->get('/api/tickets/users')->assertOk();

        $usernames = array_column($response->json('items'), 'username');

        $this->assertNotContains($user->username(), $usernames);

        $masters = array_map(
            static fn ($name): string => mb_strtolower(trim((string) $name)),
            (array) config('hastama.master_admin_usernames', ['ali']),
        );

        foreach ($usernames as $username) {
            $this->assertContains(mb_strtolower($username), $masters);
        }
    }

    /**
     * The list envelope's eight keys, in the published order, with the
     * visibility rules applied.
     */
    #[Test]
    public function an_authenticated_user_sees_only_their_own_tickets(): void
    {
        $this->requireLiveDatabase();
        $user = $this->ordinaryUser();
        $this->signIn($user);

        // A ticket the user owns, and one they have nothing to do with.
        (new TicketService)->createTicket($user->username(), false, 'ali', 'subject', 'body');
        (new TicketService)->createTicket('someone-else', false, 'ali', 'other', 'body');

        $response = $this->get('/api/tickets')->assertOk();

        $this->assertSame(
            ['items', 'total', 'page', 'page_size', 'pages', 'counts', 'status_labels', 'priority_labels'],
            array_keys($response->json()),
        );

        $requesters = array_column($response->json('items'), 'requester_username');
        $this->assertContains($user->username(), $requesters);
        $this->assertNotContains('someone-else', $requesters);
    }

    // ── Writes ────────────────────────────────────────────────────────────

    /**
     * `POST /api/tickets` — **201**, with the ticket's published shape.
     */
    #[Test]
    public function an_authenticated_user_can_open_a_ticket(): void
    {
        $this->requireLiveDatabase();
        $user = $this->ordinaryUser();
        $this->signIn($user);

        $this->postJson('/api/tickets', [
            'recipient_username' => 'ali',
            'subject' => 'subject',
            'body' => 'body',
            'priority' => 'high',
            'category_id' => 1,
        ])
            ->assertStatus(201)
            ->assertJson([
                'status' => 'new',
                'status_label' => 'جدید',
                'priority' => 'high',
                'priority_label' => 'زیاد',
                'sla_state' => 'healthy',
            ])
            // The number is the zero-padded identity id, whatever the seed.
            ->assertJsonStructure(['ticket_number'])
            ->assertJsonPath('requester_username', $user->username())
            ->assertJsonPath('recipient_username', 'ali')
            ->assertJsonPath('messages.0.body', 'body')
            ->assertJsonPath('events.0.event_type', 'created');

        // The notification side effect: a row for the recipient.
        $this->assertDatabaseHas('notifications', [
            'title' => 'تیکت جدید: subject',
            'created_by' => 'سامانه',
        ]);
    }

    /**
     * The recipient must be a master administrator — the route's own 403,
     * which fires before the service's identical check.
     */
    #[Test]
    public function a_ticket_to_a_non_master_recipient_is_refused(): void
    {
        $this->requireLiveDatabase();
        $user = $this->ordinaryUser();
        $this->signIn($user);

        $this->postJson('/api/tickets', [
            'recipient_username' => $this->ordinaryUser()->username(),
            'subject' => 'subject',
            'body' => 'body',
        ])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'تیکت‌های پشتیبانی فقط برای مدیر اصلی سامانه ارسال می‌شوند.']);
    }

    /**
     * A ticket the actor may not see is the same 404 as one that does not exist.
     */
    #[Test]
    public function a_non_participant_cannot_read_someone_elses_ticket(): void
    {
        $this->requireLiveDatabase();
        $user = $this->ordinaryUser();
        $this->signIn($user);

        $ticket = (new TicketService)->createTicket('someone-else', false, 'ali', 'subject', 'body');

        $this->get('/api/tickets/'.$ticket['id'])
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'تیکت پیدا نشد.']);
    }

    /**
     * A reply moves the conversation on, writes its event, and notifies the
     * other participant.
     */
    #[Test]
    public function a_reply_updates_the_status_and_notifies_the_other_participant(): void
    {
        $this->requireLiveDatabase();
        $user = $this->ordinaryUser();
        $this->signIn($user);

        $ticket = (new TicketService)->createTicket($user->username(), false, 'ali', 'subject', 'body');

        $this->postJson('/api/tickets/'.$ticket['id'].'/messages', ['body' => 'a reply'])
            ->assertOk()
            ->assertJsonPath('status', 'waiting_for_support')
            ->assertJsonPath('messages.1.body', 'a reply')
            ->assertJsonPath('events.1.event_type', 'reply_added');

        // The notification side effect: a row for the recipient, `ali`.
        $this->assertDatabaseHas('notifications', [
            'title' => 'پاسخ جدید به تیکت: subject',
        ]);
    }

    /**
     * An internal note is support-only.
     */
    #[Test]
    public function an_internal_note_is_refused_for_a_non_admin(): void
    {
        $this->requireLiveDatabase();
        $user = $this->ordinaryUser();
        $this->signIn($user);

        $ticket = (new TicketService)->createTicket($user->username(), false, 'ali', 'subject', 'body');

        $this->postJson('/api/tickets/'.$ticket['id'].'/messages', [
            'body' => 'internal note',
            'visibility' => 'internal',
        ])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'ثبت یادداشت داخلی فقط برای پشتیبانی مجاز است.']);
    }

    /**
     * A status change must follow the allowed transitions, and writes its event
     * and notification.
     */
    #[Test]
    public function a_status_change_follows_the_transitions_and_is_audited(): void
    {
        $this->requireLiveDatabase();
        $user = $this->ordinaryUser();

        $ticket = (new TicketService)->createTicket($user->username(), false, 'ali', 'subject', 'body');

        // A non-admin may not move a status to anything but `resolved`/`open` —
        // the permission check fires before the transition check.
        $this->signIn($user);
        $this->patchJson('/api/tickets/'.$ticket['id'], ['status' => 'waiting_for_user'])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'تغییر این مشخصات فقط برای پشتیبانی مجاز است.']);

        // An admin may, but only along the allowed transitions: `new` →
        // `waiting_for_user` is not one.
        $this->signIn($this->masterUser(), isMasterAdmin: true);
        $this->patchJson('/api/tickets/'.$ticket['id'], ['status' => 'waiting_for_user'])
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'تغییر وضعیت انتخاب‌شده مجاز نیست.']);

        // `new` → `open` is.
        $this->patchJson('/api/tickets/'.$ticket['id'], ['status' => 'open'])
            ->assertOk()
            ->assertJsonPath('status', 'open')
            ->assertJsonPath('events.1.event_type', 'status_changed');

        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket['id'],
            'event_type' => 'status_changed',
        ]);
    }

    // ── Attachments, authorised ───────────────────────────────────────────

    /**
     * The store's three refusals, which fire **after** the actor check — so
     * they need a session, unlike the missing-file refusal above.
     */
    #[Test]
    public function the_store_refuses_a_bad_extension_content_type_and_size(): void
    {
        $this->requireLiveDatabase();
        $this->signIn($this->ordinaryUser());

        $this->post('/api/tickets/1/attachments', [
            'file' => UploadedFile::fake()->create('malware.exe', 10),
        ])
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'نوع فایل مجاز نیست.']);

        // A real file with content and a mismatched declared type — the fake
        // `create()` produces 0-byte files, which the size bound would refuse
        // first.
        $path = tempnam(sys_get_temp_dir(), 'ticket');
        file_put_contents($path, 'content');
        $file = new UploadedFile($path, 'note.pdf', 'text/plain', null, true);

        $this->post('/api/tickets/1/attachments', ['file' => $file])
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'نوع محتوای فایل معتبر نیست.']);

        // One byte past the 10 MB bound, which the read limit is sized to
        // catch.
        $bigPath = tempnam(sys_get_temp_dir(), 'ticket');
        file_put_contents($bigPath, str_repeat('x', 10 * 1024 * 1024 + 1));
        $big = new UploadedFile($bigPath, 'big.pdf', 'application/pdf', null, true);

        $this->post('/api/tickets/1/attachments', ['file' => $big])
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'حجم فایل باید بین ۱ بایت و ۱۰ مگابایت باشد.']);

        @unlink($path);
        @unlink($bigPath);
    }

    /**
     * The authorised round-trip: a real temp file uploaded to a ticket the user
     * owns, then downloaded back byte for byte.
     *
     * The private root is pointed at a temporary directory because a database
     * rollback cannot undo a file write.
     */
    #[Test]
    public function an_authorised_user_can_upload_and_download_an_attachment(): void
    {
        $this->requireLiveDatabase();

        $this->privateDir = sys_get_temp_dir().'/ticketing-roundtrip-'.bin2hex(random_bytes(4));
        mkdir($this->privateDir, 0777, true);
        config(['hastama.ticketing_private_dir' => $this->privateDir]);

        $user = $this->ordinaryUser();
        $this->signIn($user);

        $ticket = (new TicketService)->createTicket($user->username(), false, 'ali', 'subject', 'body');

        $file = UploadedFile::fake()->createWithContent('note.pdf', 'ticket-attachment-bytes');

        $upload = $this->post('/api/tickets/'.$ticket['id'].'/attachments', ['file' => $file])
            ->assertOk()
            ->assertJson([
                'original_name' => 'note.pdf',
            ])
            ->assertJsonStructure(['id', 'download_url', 'original_name']);

        $attachmentId = $upload->json('id');

        // The event and the notification side effects.
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket['id'],
            'event_type' => 'attachment_added',
        ]);
        $this->assertDatabaseHas('notifications', [
            'title' => 'پیوست جدید به تیکت: subject',
        ]);

        // A BinaryFileResponse streams the bytes rather than loading them into
        // the content, so the headers are what assert the round-trip.
        $this->get('/api/tickets/'.$ticket['id'].'/attachments/'.$attachmentId)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="note.pdf"')
            ->assertHeader('Content-Length', (string) strlen('ticket-attachment-bytes'));
    }

    /**
     * A non-participant's download is the same 404 as a missing file.
     */
    #[Test]
    public function a_non_participant_cannot_download_an_attachment(): void
    {
        $this->requireLiveDatabase();

        $this->privateDir = sys_get_temp_dir().'/ticketing-roundtrip-'.bin2hex(random_bytes(4));
        mkdir($this->privateDir, 0777, true);
        config(['hastama.ticketing_private_dir' => $this->privateDir]);

        $owner = $this->ordinaryUser();
        $ticket = (new TicketService)->createTicket($owner->username(), false, 'ali', 'subject', 'body');

        // Stored through the real store, so the bytes are on disk for the
        // download that is about to be refused.
        $file = UploadedFile::fake()->createWithContent('note.pdf', 'bytes');
        $metadata = $this->storeFor($file);

        (new TicketService)->addAttachment($ticket['id'], $owner->username(), false, [
            'message_id' => null,
            'original_name' => 'note.pdf',
            'storage_name' => $metadata,
            'content_type' => 'application/pdf',
            'size_bytes' => strlen('bytes'),
        ]);

        // A different user, who is not a participant.
        $this->signIn($this->ordinaryUser());

        $this->get('/api/tickets/'.$ticket['id'].'/attachments/1')
            ->assertStatus(404)
            ->assertExactJson(['detail' => 'فایل پیدا نشد.']);
    }

    // ── The public form, authorised by the database ───────────────────────

    /**
     * A valid anonymous request creates the ticket and answers with its id.
     */
    #[Test]
    public function a_valid_anonymous_request_creates_a_ticket(): void
    {
        $this->requireLiveDatabase();

        $this->postJson('/public/support-ticket', [
            'full_name' => 'نام و نام خانوادگی',
            'phone' => '09123456789',
            'description' => 'توضیحات',
        ])
            ->assertOk()
            ->assertJson([
                'success' => true,
                'message' => 'درخواست شما با موفقیت ثبت شد. مدیر سامانه در اسرع وقت با شما تماس خواهد گرفت.',
            ])
            ->assertJsonStructure(['ticket_id']);

        // The ticket exists, addressed to the hardcoded `ali`, with the IP in
        // the body — the rate-limit query's needle.
        $this->assertDatabaseHas('tickets', [
            'requester_username' => '__anonymous__',
            'recipient_username' => 'ali',
            'priority' => 'high',
        ]);

        // The audit side effect.
        $this->assertDatabaseHas('audit_logs', [
            'event_type' => 'SUPPORT',
            'action' => 'anonymous_ticket_created',
            'username' => '__anonymous__',
        ]);
    }

    /** Store a file and return its metadata, for the download-ownership test. */
    private function storeFor(UploadedFile $file): string
    {
        $metadata = app(PrivateAttachmentStore::class)->store(
            $file->getClientOriginalName() ?: 'attachment',
            (string) $file->getClientMimeType(),
            (string) $file->getContent(),
        );

        return $metadata['storage_name'];
    }
}
