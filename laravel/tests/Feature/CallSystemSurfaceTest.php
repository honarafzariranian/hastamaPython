<?php

namespace Tests\Feature;

use App\Broadcasting\DisplayBroadcaster;
use App\Broadcasting\DisplayChannel;
use App\Events\DisplayMessage;
use App\Http\Middleware\GuardKioskWrite;
use App\Support\Http\LegacyOrigin;
use App\Support\Legacy\LegacyBody;
use App\Support\Legacy\LegacyDisplayText;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use App\Support\Legacy\LegacyValidationException;
use App\Support\Legacy\PersianText;
use App\Support\Legacy\QueuePii;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The call system's guards, its two validators and its route wiring.
 *
 * Everything here is offline.  The parts that need the real `userDB` are in
 * `CallSystemDatabaseTest`, and the parts that need the real FastAPI server were verified
 * by diffing both servers' responses over HTTP — see `MIGRATION_STATUS.md` for the
 * comparison table, which is stronger evidence than a test could be because the expected
 * bytes came from the running application rather than from this repository.
 *
 * The cases worth having tests for are the ones where a plausible implementation is wrong:
 *
 * * the same-site rule **allows a missing `Origin`** but refuses `Origin: null` — a
 *   "stricter" reading would break every non-browser client, and a "looser" one would
 *   accept a sandboxed iframe;
 * * pydantic reports a missing body against `loc: ["body"]`, not against the first field;
 * * pydantic **pluralises**: `min_length=1` really does say "1 character";
 * * pydantic runs its length constraints **before** the `.strip()` transform, so
 *   `"  "` passes `min_length=1` and reaches the handler as `""`.
 */
final class CallSystemSurfaceTest extends TestCase
{
    /** `strftime("%Y-%m-%dT%H:%M:%S")` — no fraction, no `Z`. */
    private const BROADCAST_STAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/';

    // ── The same-site rule ───────────────────────────────────────────────────

    /** @return array<string, array{string|null, string, bool}> */
    public static function originCases(): array
    {
        return [
            // `origin`, `host`, expected.  Captured from `origin_is_same_site`.
            'no origin is allowed' => [null, '127.0.0.1:8000', true],
            'empty origin is allowed' => ['', '127.0.0.1:8000', true],
            'the same host and port' => ['http://127.0.0.1:8000', '127.0.0.1:8000', true],
            'a different scheme still matches' => ['https://127.0.0.1', '127.0.0.1:8000', true],
            'the port is not compared' => ['http://lan-host:9000', 'lan-host:8000', true],
            'case is folded' => ['http://LAN-host', 'lan-host:8000', true],
            'a different host is refused' => ['http://evil.example', '127.0.0.1:8000', false],
            'the sandboxed null origin is refused' => ['null', '127.0.0.1:8000', false],
            'an origin with no authority is refused' => ['not-a-url', '127.0.0.1:8000', false],
        ];
    }

    #[DataProvider('originCases')]
    #[Test]
    public function the_same_site_rule_matches_the_python(?string $origin, string $host, bool $expected): void
    {
        $request = Request::create('/', 'POST', [], [], [], ['HTTP_HOST' => $host]);

        if ($origin !== null) {
            $request->headers->set('origin', $origin);
        }

        $this->assertSame($expected, LegacyOrigin::isSameSite($request));
    }

    // ── The display-text check ───────────────────────────────────────────────

    #[Test]
    public function display_text_keeps_persian_and_the_zero_width_joiner(): void
    {
        // U+200C is inside «نمونه‌گیری»; rejecting it would refuse the default department.
        $this->assertSame('نمونه‌گیری', LegacyDisplayText::clean('نمونه‌گیری', maxLength: 100, field: 'بخش'));
        $this->assertSame('نمونه‌گیری', LegacyDisplayText::department('نمونه‌گیری'));
    }

    #[Test]
    public function an_empty_department_falls_back_to_the_default(): void
    {
        $this->assertSame('نمونه‌گیری', LegacyDisplayText::department(''));
        $this->assertSame('نمونه‌گیری', LegacyDisplayText::department(null));
        $this->assertSame('نمونه‌گیری', LegacyDisplayText::department('   '));
    }

    /** @return array<string, array{string}> */
    public static function rejectedDisplayText(): array
    {
        return [
            'a tag' => ['<script>'],
            'a closing tag' => ['a > b'],
            'a bare ampersand' => ['a & b'],
            'a character outside the allowed set' => ['salaam!'],
        ];
    }

    #[DataProvider('rejectedDisplayText')]
    #[Test]
    public function display_text_refuses_markup_with_the_pythons_message(string $value): void
    {
        try {
            LegacyDisplayText::clean($value, maxLength: 100, field: 'بخش');
        } catch (LegacyHttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame(['detail' => 'بخش شامل کاراکترهای غیرمجاز است.'], $exception->body());

            return;
        }

        $this->fail('the value was expected to be refused');
    }

    /**
     * An escaped entity survives the markup check and is still refused.
     *
     * `"&" in text and "&amp;" not in text` really does exempt `&amp;` from the `<`/`>`/`&`
     * test, and it is tempting to stop reading there and let the value through — but the
     * punctuation whitelist on the next line has neither `&` nor `;`, so the same message
     * fires a statement later.  Verified by calling the source of truth directly:
     *
     *     clean_display_text("a &amp; b", max_length=100, field="بخش")
     *     ValueError: بخش شامل کاراکترهای غیرمجاز است.
     *
     * The branch is real and observable only through the *message it does not change*,
     * which is why it is pinned rather than deleted.
     */
    #[Test]
    public function an_escaped_ampersand_still_fails_the_punctuation_whitelist(): void
    {
        try {
            LegacyDisplayText::clean('a &amp; b', maxLength: 100, field: 'بخش');
        } catch (LegacyHttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame(['detail' => 'بخش شامل کاراکترهای غیرمجاز است.'], $exception->body());

            return;
        }

        $this->fail('the value was expected to be refused');
    }

    #[Test]
    public function display_text_refuses_an_over_long_value_with_the_pythons_message(): void
    {
        try {
            LegacyDisplayText::clean(str_repeat('a', 101), maxLength: 100, field: 'بخش');
        } catch (LegacyHttpException $exception) {
            $this->assertSame(['detail' => 'بخش نباید بیش از 100 کاراکتر باشد.'], $exception->body());

            return;
        }

        $this->fail('the value was expected to be refused');
    }

    /** `\x00-\x08` are stripped rather than rejected, so a control character is invisible. */
    #[Test]
    public function control_characters_are_stripped_not_refused(): void
    {
        $this->assertSame('ab', LegacyDisplayText::stripControl("a\x00b\x08"));
        $this->assertSame('a b', LegacyDisplayText::stripControl("a\x00 b"));
    }

    // ── The pydantic body ────────────────────────────────────────────────────

    /**
     * The three model-level failures, as the **bytes** the running server sends.
     *
     * Captured from FastAPI with `curl` and compared against the port over HTTP.  Stored as
     * JSON rather than as arrays because two of the three values cannot survive an array
     * comparison: `json_invalid` carries `input: {}`, and `(object) []` is never `===`
     * another `(object) []` in PHP.  Encoding with the port's own flags compares what the
     * caller actually receives, key order included.
     *
     * @return array<string, array{string, string}>
     */
    public static function bodyModelCases(): array
    {
        return [
            'no body at all' => [
                '',
                '{"type":"missing","loc":["body"],"msg":"Field required","input":null}',
            ],
            'an array where an object was declared' => [
                '[]',
                '{"type":"model_attributes_type","loc":["body"],'
                .'"msg":"Input should be a valid dictionary or object to extract fields from","input":[]}',
            ],
            'unparseable json' => [
                'not json',
                '{"type":"json_invalid","loc":["body",0],"msg":"JSON decode error",'
                .'"input":{},"ctx":{"error":"Expecting value"}}',
            ],
            // An empty object is an object: `array_is_list([])` is `true` in PHP, so the
            // natural `! is_array($decoded) || array_is_list($decoded)` test classified `{}`
            // as a JSON array and answered against `loc: ["body"]` instead of the field.
            'an empty object' => [
                '{}',
                '{"type":"missing","loc":["body","reception_number"],"msg":"Field required","input":{}}',
            ],
            // `null` is not a type error: FastAPI reads it as no body at all.
            'a literal null body' => [
                'null',
                '{"type":"missing","loc":["body"],"msg":"Field required","input":null}',
            ],
            // A missing field echoes the **model's** input back, not `null`.
            'a body missing every field' => [
                '{"department":"x"}',
                '{"type":"missing","loc":["body","reception_number"],"msg":"Field required",'
                .'"input":{"department":"x"}}',
            ],
            // ...and an empty object in a *field* stays `{}` rather than degrading to `[]`.
            'an object where a string was declared' => [
                '{"reception_number":{}}',
                '{"type":"string_type","loc":["body","reception_number"],'
                .'"msg":"Input should be a valid string","input":{}}',
            ],
        ];
    }

    #[DataProvider('bodyModelCases')]
    #[Test]
    public function a_missing_or_unsuitable_body_is_reported_against_the_body(string $raw, string $expected): void
    {
        try {
            LegacyBody::validateJson($raw, ['reception_number' => LegacyQuery::requiredString(minLength: 1)]);
        } catch (LegacyValidationException $exception) {
            $detail = $exception->detail();

            $this->assertCount(1, $detail);
            $this->assertSame(
                $expected,
                json_encode($detail[0], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            );

            return;
        }

        $this->fail('the body was expected to be rejected');
    }

    /**
     * pydantic's length messages are pluralised, verified against the running server.
     *
     * `min_length=1` says "1 character" — singular.  A naive `characters` here would be
     * wrong for the one bound the kiosk actually hits.
     */
    #[Test]
    public function length_messages_are_pluralised(): void
    {
        $detail = $this->bodyRejections(
            ['reception_number' => ''],
            ['reception_number' => LegacyQuery::requiredString(minLength: 1)],
        );

        $this->assertSame('String should have at least 1 character', $detail[0]['msg']);
        $this->assertSame(['min_length' => 1], $detail[0]['ctx']);

        $detail = $this->bodyRejections(
            ['reception_number' => str_repeat('a', 51)],
            ['reception_number' => LegacyQuery::requiredString(maxLength: 50)],
        );

        $this->assertSame('String should have at most 50 characters', $detail[0]['msg']);
        $this->assertSame(['max_length' => 50], $detail[0]['ctx']);
    }

    /** A declared `str` is not coerced: a number and an explicit `null` are both `string_type`. */
    #[Test]
    public function a_non_string_body_field_is_a_string_type_failure(): void
    {
        foreach ([123, null, true] as $value) {
            $detail = $this->bodyRejections(
                ['reception_number' => $value],
                ['reception_number' => LegacyQuery::requiredString()],
            );

            $this->assertSame('string_type', $detail[0]['type']);
            $this->assertSame(['body', 'reception_number'], $detail[0]['loc']);
            $this->assertSame('Input should be a valid string', $detail[0]['msg']);
        }
    }

    /**
     * The constraints run **before** the `.strip()` transform — pydantic's `mode="after"`.
     *
     * Two spaces satisfy `min_length=1`, so the empty value reaches the handler, where its
     * own "please enter a reception number" message fires.  Enforcing the minimum after
     * trimming would answer pydantic's `string_too_short` instead, and the two are different
     * bodies.
     */
    #[Test]
    public function length_constraints_run_before_the_trim_transform(): void
    {
        $body = LegacyBody::validateJson(
            json_encode(['reception_number' => '  ']),
            ['reception_number' => LegacyQuery::requiredString(minLength: 1, maxLength: 50)],
            trim: ['reception_number'],
        );

        $this->assertSame('', $body['reception_number']);

        // And the handler's own check is what refuses it — a 422 with the module's wording.
        $this->postJson('/api/calls', ['reception_number' => '  '])
            ->assertStatus(422)
            ->assertExactJson(['detail' => 'لطفاً شماره پذیرش را وارد کنید.']);
    }

    #[Test]
    public function a_declared_default_is_used_when_the_field_is_absent(): void
    {
        $body = LegacyBody::validateJson(
            json_encode(['reception_number' => '12']),
            [
                'reception_number' => LegacyQuery::requiredString(maxLength: 50),
                'department' => LegacyQuery::string(default: 'نمونه‌گیری', maxLength: 100),
            ],
        );

        $this->assertSame('12', $body['reception_number']);
        $this->assertSame('نمونه‌گیری', $body['department']);
    }

    // ── The timestamp ────────────────────────────────────────────────────────

    /** `_iso()` — `Z`-suffixed, and six digits only when there is a fraction to show. */
    #[Test]
    public function the_stored_row_timestamp_carries_the_utc_marker(): void
    {
        $this->assertSame('2026-09-30T08:53:41.864000Z', LegacySerializer::isoUtcZ('2026-09-30 08:53:41.864'));
        $this->assertSame('2026-09-30T08:53:41Z', LegacySerializer::isoUtcZ('2026-09-30 08:53:41'));
        $this->assertNull(LegacySerializer::isoUtcZ(null));
        $this->assertNull(LegacySerializer::isoUtcZ(''));
    }

    // ── The guards, through the HTTP kernel ──────────────────────────────────

    /**
     * A cross-site write is refused, with the body **the running server actually sends**.
     *
     * The CSRF layer answers first, because every call-system path is on its exempt-prefix
     * list and `VerifyLegacyCsrf` enforces `Origin` on a non-reading, exempt request.  Both
     * servers were diffed over HTTP to settle which layer a browser sees: FastAPI answers
     * `{"success":false,"error":"CSRF token mismatch."}` for this exact request, and so
     * does the port.  Asserting the kiosk guard's `detail` envelope here would have been a
     * test of a branch no HTTP client can reach.
     */
    #[Test]
    public function a_cross_site_kiosk_write_is_refused_with_the_legacy_body(): void
    {
        $this->postJson('/api/calls', ['reception_number' => '1'], ['Origin' => 'http://evil.example'])
            ->assertStatus(403)
            ->assertExactJson(['success' => false, 'error' => 'CSRF token mismatch.']);
    }

    /**
     * The kiosk guard's own cross-site refusal, called directly.
     *
     * This branch is **unreachable over HTTP** while CSRF answers first, and it is in the
     * source of truth for the same reason: the guard is the module's own contract and the
     * framework's middleware order is not part of it.  Invoking the class keeps the ported
     * wording pinned instead of leaving dead-looking code untested.
     */
    #[Test]
    public function the_kiosk_guard_refuses_a_cross_site_origin_with_the_pythons_body(): void
    {
        $request = Request::create('/api/calls', 'POST', [], [], [], ['HTTP_HOST' => '127.0.0.1']);
        $request->headers->set('origin', 'http://evil.example');

        try {
            (new GuardKioskWrite)->handle($request, static fn () => response()->json(['ok' => true]));
        } catch (LegacyHttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(['detail' => 'درخواست از مبدأ مجاز نیست.'], $exception->body());

            return;
        }

        $this->fail('the request was expected to be refused');
    }

    /**
     * The per-IP limit, exercised by pre-loading the counter.
     *
     * Minting 120 real requests would send 120 writes to the live `reception_calls` table,
     * which is not something a test should do to prove a counter.  Filling the bucket and
     * then making **one** request tests the same branch with no side effects.
     */
    #[Test]
    public function the_kiosk_write_limit_refuses_the_request_after_the_bucket_is_full(): void
    {
        $key = 'hastama.call-system.kiosk.calls-create:127.0.0.1';

        RateLimiter::clear($key);

        for ($i = 0; $i < GuardKioskWrite::LIMIT; $i++) {
            RateLimiter::hit($key, GuardKioskWrite::WINDOW_SECONDS);
        }

        $this->postJson('/api/calls', ['reception_number' => '1'])
            ->assertStatus(429)
            ->assertExactJson(['detail' => 'تعداد درخواست‌ها بیش از حد مجاز است.']);

        RateLimiter::clear($key);
    }

    /** Each bucket has its own budget, because the Python keyed on `bucket:ip`. */
    #[Test]
    public function the_limit_is_per_bucket(): void
    {
        RateLimiter::clear('hastama.call-system.kiosk.calls-create:127.0.0.1');

        for ($i = 0; $i < GuardKioskWrite::LIMIT; $i++) {
            RateLimiter::hit('hastama.call-system.kiosk.calls-create:127.0.0.1', 60);
        }

        // A different bucket is untouched: pressing the reset button does not consume the
        // budget for taking a call.  Probed through its own route rather than by posting to
        // `/api/calls/reset-display`, which would clear the *live* display queue — a test
        // must not send a real write just to prove a counter.
        Route::post('/tmp-kiosk-probe', fn () => response()->json(['ok' => true]))
            ->middleware('kiosk.write:reset-display');

        $this->postJson('/tmp-kiosk-probe')->assertOk();

        RateLimiter::clear('hastama.call-system.kiosk.calls-create:127.0.0.1');
    }

    // ── The queue-PII guard ──────────────────────────────────────────────────

    /**
     * The three-branch rule, called directly the way `AuthSurfaceTest` calls its guards.
     *
     * `/api/queue/ticket/{n}` is the real consumer and arrives with the queue routes.  Going
     * through the kernel would put the CSRF layer in front of this guard — which is what
     * production does, but it answers first on an exempt prefix and would hide every branch
     * here.  A hand-built request carrying a real session store is the smallest thing that
     * exercises the rule, and `setLaravelSession` is the same call the auth suite makes.
     */
    #[Test]
    public function the_queue_pii_guard_covers_all_three_browser_contexts(): void
    {
        // 1. An Origin is enforced whenever one is present — including for an administrator,
        //    because the path is CSRF-exempt and a leaked cookie must not be drivable from
        //    another site.
        foreach ([null, true] as $admin) {
            $refusal = $this->piiRefusal(
                static fn (Request $request) => $request->headers->set('origin', 'http://evil.example'),
                $admin,
            );

            $this->assertSame(403, $refusal?->getStatusCode());
            $this->assertSame(['detail' => 'درخواست از مبدأ مجاز نیست.'], $refusal?->body());
        }

        // A same-site Origin passes.
        $this->assertNull($this->piiRefusal(
            static fn (Request $request) => $request->headers->set('origin', 'http://127.0.0.1:8000'),
        ));

        // 2. With no Origin, a same-site Referer is accepted — the kiosk's own navigation.
        $this->assertNull($this->piiRefusal(
            static fn (Request $request) => $request->headers->set('referer', 'http://127.0.0.1:8000/ticket'),
        ));

        // ...and a cross-site one is not.
        $crossSite = $this->piiRefusal(
            static fn (Request $request) => $request->headers->set('referer', 'http://evil.example/x'),
        );

        $this->assertSame(403, $crossSite?->getStatusCode());

        // 3. With neither header, only an authenticated administrator gets in: a script
        //    holding a session cookie still can, a non-browser anonymous client cannot.
        $anonymous = $this->piiRefusal(static fn () => null);

        $this->assertSame(403, $anonymous?->getStatusCode());
        $this->assertSame(['detail' => 'درخواست از مبدأ مجاز نیست.'], $anonymous?->body());
        $this->assertNull($this->piiRefusal(static fn () => null, true));

        // The flag is compared with `===`: a string `"1"` is a value the legacy session could
        // plausibly hold, and it must not pass as an administrator.
        $stringFlag = $this->piiRefusal(static fn () => null, '1');

        $this->assertSame(403, $stringFlag?->getStatusCode());
    }

    /**
     * The guard is reached from the **handler**, end to end.
     *
     * There is deliberately no `queue.pii` alias: FastAPI validates a handler's declared
     * parameters before calling it and `_guard_queue_pii` is the handler's first line, so
     * as route middleware the guard would have answered `403` to a malformed ticket number
     * the running server answers `422` for.  This asserts the property that matters
     * instead — that the real route carries the guard — by driving it through the kernel,
     * which a direct call to {@see QueuePii} cannot do.
     *
     * A cross-site `Origin` is refused **after** the path segment validates, which is the
     * ordering the running server shows:
     *
     *     GET /api/queue/ticket/abc -H 'Origin: evil'  -> 422  (path first)
     *     GET /api/queue/ticket/5   -H 'Origin: evil'  -> 403  (guard second)
     */
    #[Test]
    public function the_queue_ticket_route_enforces_the_pii_guard_in_the_handler(): void
    {
        $this->get('/api/queue/ticket/5', ['Origin' => 'http://evil.example'])
            ->assertStatus(403)
            ->assertExactJson(['detail' => 'درخواست از مبدأ مجاز نیست.']);

        // The path is validated before the guard, so a malformed segment is a 422 even
        // from the same hostile origin — the ordering above, asserted from both sides.
        $this->get('/api/queue/ticket/abc', ['Origin' => 'http://evil.example'])
            ->assertStatus(422);
    }

    /**
     * Run the guard over a request shaped by `$shape` and answer its refusal, if any.
     *
     * The session store is shared between calls (it is the application's), so `is_admin` is
     * cleared first: without that, the administrator case would leak into every assertion
     * after it and the anonymous branch would stop being tested.
     *
     * @param  mixed  $isAdmin  `true`, `'1'` (the wrong type), or `null` for no flag at all.
     */
    private function piiRefusal(Closure $shape, mixed $isAdmin = null): ?LegacyHttpException
    {
        $session = $this->app['session']->driver();
        $session->forget('is_admin');

        if ($isAdmin !== null) {
            $session->put('is_admin', $isAdmin);
        }

        $request = Request::create('/api/queue/ticket/1', 'GET', [], [], [], ['HTTP_HOST' => '127.0.0.1:8000']);
        $request->setLaravelSession($session);
        $shape($request);

        // The guard spends budget on every request it lets through; the branch tests below
        // make a handful of them, so the counter is reset to keep this helper's own limit
        // from ever becoming the thing under test.
        RateLimiter::clear('hastama.call-system.pii.probe:127.0.0.1');

        try {
            QueuePii::assert($request, 'probe');
        } catch (LegacyHttpException $exception) {
            return $exception;
        }

        return null;
    }

    /** The guard's own limit is lower than the kiosk's, because the payload is PII. */
    #[Test]
    public function the_queue_pii_limit_is_tighter_than_the_kiosk_limit(): void
    {
        $this->assertSame(30, QueuePii::LIMIT);
        $this->assertLessThan(GuardKioskWrite::LIMIT, QueuePii::LIMIT);
        $this->assertSame(60, QueuePii::WINDOW_SECONDS);
    }

    // ── Broadcasting ─────────────────────────────────────────────────────────

    /** The event carries the legacy object verbatim, on both display channels. */
    #[Test]
    public function a_display_message_broadcasts_the_legacy_payload_unchanged(): void
    {
        $payload = ['type' => 'reception_call', 'data' => ['number' => '12', 'persian_number' => '۱۲']];

        $event = new DisplayMessage($payload);

        $this->assertSame($payload, $event->broadcastWith());
        $this->assertSame(DisplayChannel::EVENT, $event->broadcastAs());
        $this->assertSame(
            [DisplayChannel::TV, DisplayChannel::PREVIEW],
            array_map(static fn ($channel) => $channel->name, $event->broadcastOn()),
        );
    }

    #[Test]
    public function the_broadcaster_publishes_and_reports_a_count(): void
    {
        Event::fake([DisplayMessage::class]);

        $sent = app(DisplayBroadcaster::class)->send([
            'type' => 'reset_display',
            'data' => [],
        ]);

        Event::assertDispatched(DisplayMessage::class, fn (DisplayMessage $event) => $event->message['type'] === 'reset_display');

        // An integer, not a constant: `counts()` reads Reverb.  A missing Reverb is a real
        // possibility (the process is separate) and must answer 0 rather than throw.
        $this->assertIsInt($sent);
    }

    /**
     * With Reverb unreachable the count degrades to `0` instead of failing the request.
     *
     * This is the deterministic branch: pointing the connection at a closed port is the
     * only way to test the failure without stopping the Reverb process the display
     * verification depends on.  A `0` is the honest answer — the displays connect *through*
     * Reverb, so if it cannot be reached there is no connected display.
     */
    #[Test]
    public function an_unreachable_reverb_reports_zero_rather_than_throwing(): void
    {
        config([
            'broadcasting.connections.reverb.options.host' => '127.0.0.1',
            'broadcasting.connections.reverb.options.port' => 1,
        ]);

        $counts = app(DisplayBroadcaster::class)->counts();

        $this->assertSame(['connected' => 0, 'real' => 0, 'preview' => 0], $counts);
    }

    // ── Route wiring ─────────────────────────────────────────────────────────

    /**
     * Every call-system route exists at the path the running front-end calls, with the guard
     * the Python applied.
     *
     * `route:list` cannot show a missing middleware, and a kiosk endpoint that lost its
     * `kiosk.write` guard is a CSRF-exempt write with no origin check at all — the exact
     * hole `VerifyLegacyCsrf` exists to close, one layer up.
     */
    #[Test]
    public function the_call_system_routes_carry_the_pythons_guards(): void
    {
        $expected = [
            'api/calls' => ['POST', 'kiosk.write:calls-create'],
            'api/calls/repeat' => ['POST', 'kiosk.write:calls-repeat'],
            'api/calls/test-display' => ['POST', 'kiosk.write:calls-test'],
            'api/calls/test-voice' => ['POST', 'kiosk.write:calls-test'],
            'api/calls/audio-activated' => ['POST', 'kiosk.write:calls-test'],
            'api/calls/test-audio' => ['POST', 'kiosk.write:calls-test'],
            'api/calls/reset-display' => ['POST', 'kiosk.write:reset-display'],
            'api/calls/refresh-display' => ['POST', 'kiosk.write:refresh-display'],
            'api/calls/remove' => ['POST', 'kiosk.write:calls-remove'],
            'api/calls/recent' => ['DELETE', 'kiosk.write:calls-recent-clear'],
            'api/calls/waiting-queue' => ['POST', 'kiosk.write:calls-waiting-add'],
            'api/calls/waiting-queue/{itemId}' => ['DELETE', 'kiosk.write:calls-waiting-del'],
            'api/calls/waiting-queue/{itemId}/call' => ['POST', 'kiosk.write:calls-waiting-call'],
        ];

        foreach ($expected as $uri => [$method, $middleware]) {
            $route = collect(Route::getRoutes())->first(
                static fn ($route) => $route->uri() === $uri && in_array($method, $route->methods(), true)
            );

            $this->assertNotNull($route, "{$method} /{$uri} must be registered");
            $this->assertContains(
                $middleware,
                $route->gatherMiddleware(),
                "{$method} /{$uri} must carry {$middleware}",
            );
        }
    }

    /** The reads the displays and the kiosk use must stay unguarded — they have no session. */
    #[Test]
    public function the_display_reads_have_no_guard(): void
    {
        foreach (['api/calls/audio-status', 'api/calls/display-queue', 'api/calls/status'] as $uri) {
            $route = collect(Route::getRoutes())->first(static fn ($route) => $route->uri() === $uri);

            $this->assertNotNull($route);

            // `gatherMiddleware()` answers an array, and the guard is registered *with its
            // bucket* (`kiosk.write:calls-create`), so the joined string is what has to be
            // searched — `assertNotContains` would compare whole elements and pass either way.
            $this->assertStringNotContainsString('kiosk.write', implode('|', $route->gatherMiddleware()));
        }
    }

    /** The kiosk's Persian digits and the module's own default are one place each. */
    #[Test]
    public function persian_digits_are_the_modules(): void
    {
        $this->assertSame('۱۲۳', PersianText::toPersianDigits('123'));
        $this->assertSame('۱۲۳', PersianText::toPersianDigits('۱۲۳'));
    }

    /** @return array<int, array<string, mixed>> */
    private function bodyRejections(array $body, array $spec): array
    {
        try {
            LegacyBody::validate($body, $spec);
        } catch (LegacyValidationException $exception) {
            return $exception->detail();
        }

        $this->fail('the body was expected to be rejected');
    }
}
