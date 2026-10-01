<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureMasterAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyLegacyCsrf;
use App\Models\User;
use App\Services\Auth\SessionRegistry;
use App\Support\Http\LegacyCsrfCookie;
use App\Support\Legacy\LegacyCaptcha;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * The authentication surface: everything that can be verified without the live
 * database.
 *
 * The end-to-end login — which needs `dbo.user_table` — lives in
 * `AuthDatabaseTest` behind `HASTAMA_DB_TESTS=1`.  Splitting them this way keeps the
 * default suite runnable on any machine while still asserting the parts that need no
 * data: the response contracts, the guards, the CAPTCHA image, and the CSRF bridge.
 *
 * A note on CSRF: Laravel **skips** CSRF validation while running tests
 * (`PreventRequestForgery::runningUnitTests()`), so a `post()` here proves nothing
 * about the control.  That is why the middleware is exercised directly, through a
 * subclass that turns the bypass back off instead of pretending the HTTP layer
 * covers it.
 */
final class AuthSurfaceTest extends TestCase
{
    /** A middleware with the framework's test bypass switched off. */
    private function csrf(): VerifyLegacyCsrf
    {
        return new class($this->app, $this->app['encrypter']) extends VerifyLegacyCsrf
        {
            protected function runningUnitTests()
            {
                return false;
            }

            public function exceptFor(Request $request): bool
            {
                return $this->inExceptArray($request);
            }

            public function tokensAccept(Request $request): bool
            {
                return $this->tokensMatch($request);
            }
        };
    }

    // ── CSRF ─────────────────────────────────────────────────────────────────

    /**
     * The bridge is actually installed in the `web` group.
     *
     * This is the assertion that would have caught the wiring failure that reached
     * a live server: `Middleware::web(replace: …)` matches by **exact class name**,
     * and Laravel 13's `web` group contains `PreventRequestForgery`, not the
     * deprecated `ValidateCsrfToken` alias of it.  Keying the replacement on the
     * alias is silently ignored, so the stock middleware kept running, no legacy
     * exemption applied, and every submission from the running front-end was
     * answered `419 Page Expired`.
     *
     * Nothing in the test suite can see that through a request — the framework
     * switches CSRF validation off while running tests — so it is asserted on the
     * configured stack itself.
     */
    #[Test]
    public function the_csrf_bridge_is_the_middleware_in_the_web_group(): void
    {
        $group = $this->app->make(Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertContains(VerifyLegacyCsrf::class, $group, 'the web group must use the legacy-aware CSRF middleware');
        $this->assertNotContains(
            PreventRequestForgery::class,
            $group,
            'the stock middleware must have been *replaced*, not merely supplemented',
        );
    }

    #[Test]
    public function the_security_header_middleware_is_appended_last(): void
    {
        $group = $this->app->make(Kernel::class)->getMiddlewareGroups()['web'];

        $this->assertSame(SecurityHeaders::class, end($group), 'it is appended, so a route can still override a header');
    }

    /**
     * A rejected request answers the legacy body, not Laravel's 419 page.
     *
     * The middleware converts the rejection itself: `Handler::prepareException()`
     * replaces the `TokenMismatchException` with a generic `HttpException(419)`
     * before any `render` callback runs, so relying on a callback produced an HTML
     * "Page Expired" — or a stack trace with `APP_DEBUG` on — from an endpoint that
     * is documented to answer `{"success": false, "error": …}` with HTTP 403.
     */
    #[Test]
    public function an_unsafe_request_without_a_token_is_rejected_with_the_legacy_body(): void
    {
        // A session is attached because a real browser request always has one; the
        // token is simply absent from it.
        $request = Request::create('/api/session/destroy', 'POST');
        $request->setLaravelSession($this->app['session']->driver());

        $response = $this->csrf()->handle($request, fn () => response('unreachable'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(VerifyLegacyCsrf::REJECTION_BODY, $response->getData(true));
    }

    #[Test]
    public function a_419_anywhere_else_is_also_answered_with_the_legacy_body(): void
    {
        // The safety net in `bootstrap/app.php`, which guards on the status because
        // by then the original exception class is gone.
        $this->app['router']->get('/_test/csrf', function (Request $request) {
            throw new HttpException(419, 'CSRF token mismatch.');
        });

        $response = $this->get('/_test/csrf');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(VerifyLegacyCsrf::REJECTION_BODY, $response->json());
    }

    #[Test]
    public function the_legacy_header_is_accepted_when_it_matches_the_session(): void
    {
        $this->assertTrue($this->csrf()->tokensAccept($this->requestWithLegacyToken('legacy-token-value')));
    }

    #[Test]
    public function the_legacy_header_is_refused_when_it_does_not_match(): void
    {
        $this->assertFalse($this->csrf()->tokensAccept($this->requestWithLegacyToken('a-different-value')));
    }

    #[Test]
    public function an_absent_legacy_header_is_refused(): void
    {
        $this->assertFalse($this->csrf()->tokensAccept($this->requestWithLegacyToken(null)));
    }

    /**
     * The exempt prefixes are matched as prefixes, not as whole URIs.
     *
     * `Str::is('/api/queue/take', '/api/queue/take/5')` is false, so a translation
     * of the legacy list into framework `except` patterns would have stopped
     * exempting every nested path the day one was added.
     */
    #[Test]
    public function the_kiosk_and_public_paths_are_exempt_by_prefix(): void
    {
        $exempt = [
            '/api/queue/take',
            '/api/queue/take/5',
            '/api/calls/status',
            '/api/araz/bridge/status',
            '/login_user',
            '/forgot_password',
            '/reset_password',
            '/captcha/refresh',
            '/registration/status/ali',
            '/public/support-ticket',
        ];

        foreach ($exempt as $path) {
            $this->assertTrue(
                $this->csrf()->exceptFor(Request::create($path, 'POST')),
                "expected {$path} to be CSRF exempt",
            );
        }
    }

    #[Test]
    public function an_ordinary_authenticated_endpoint_is_not_exempt(): void
    {
        $this->assertFalse($this->csrf()->exceptFor(Request::create('/api/session/destroy', 'POST')));
        $this->assertFalse($this->csrf()->exceptFor(Request::create('/api/me', 'GET')));
    }

    /**
     * An exemption is not a free pass: a cross-site write to an exempt path is
     * still refused, with the body the legacy middleware returned.
     */
    #[Test]
    public function a_cross_site_write_to_an_exempt_path_is_refused(): void
    {
        $request = $this->exemptRequest('/login_user', 'https://evil.example', 'hastama.ir');

        $response = $this->csrf()->handle($request, fn () => response('unreachable'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(VerifyLegacyCsrf::REJECTION_BODY, $response->getData(true));
    }

    #[Test]
    public function a_same_site_write_to_an_exempt_path_is_allowed(): void
    {
        $request = $this->exemptRequest('/login_user', 'http://hastama.ir', 'hastama.ir');

        $response = $this->csrf()->handle($request, fn () => response('reached'));

        $this->assertSame('reached', $response->getContent());
    }

    #[Test]
    public function a_write_with_no_origin_is_allowed_for_the_lan_integrations(): void
    {
        // The Araz bridge agent and the documented `curl` recipes send no Origin;
        // the Python middleware allowed those too.
        $request = $this->exemptRequest('/api/araz/bridge/status');

        $response = $this->csrf()->handle($request, fn () => response('reached'));

        $this->assertSame('reached', $response->getContent());
    }

    #[Test]
    public function an_opaque_origin_is_refused(): void
    {
        $request = $this->exemptRequest('/login_user', 'null');

        $response = $this->csrf()->handle($request, fn () => response('unreachable'));

        $this->assertSame(403, $response->getStatusCode());
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    #[Test]
    public function the_admin_guard_answers_with_the_legacy_error_key(): void
    {
        // Anonymous: the guard must answer 401 with the key `error`, not `message`.
        // That inconsistency is real and the running front-end depends on it.
        $response = $this->app->make(EnsureAdmin::class)->handle($this->guardRequest(), fn () => response('ok'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['success' => false, 'error' => 'لاگین نکرده‌اید.'], $response->getData(true));
    }

    #[Test]
    public function a_non_admin_session_is_refused_by_the_admin_guard(): void
    {
        auth()->guard('web')->setUser(new User(['username' => 'ordinary']));

        $response = $this->app->make(EnsureAdmin::class)->handle(
            $this->guardRequest(['is_admin' => false]),
            fn () => response('ok'),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('دسترسی مدیریتی ندارید.', $response->getData(true)['error']);
    }

    #[Test]
    public function an_admin_session_passes_the_admin_guard(): void
    {
        auth()->guard('web')->setUser(new User(['username' => 'ali']));

        $response = $this->app->make(EnsureAdmin::class)->handle(
            $this->guardRequest(['is_admin' => true]),
            fn () => response('ok'),
        );

        $this->assertSame('ok', $response->getContent());
    }

    #[Test]
    public function the_master_admin_guard_answers_with_the_detail_key(): void
    {
        // The Python code raised `HTTPException`, which FastAPI rendered as
        // `{"detail": …}` — a different key again from the admin guard's.
        $response = $this->app->make(EnsureMasterAdmin::class)->handle($this->guardRequest(), fn () => response('ok'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['detail' => 'ورود لازم است.'], $response->getData(true));
    }

    #[Test]
    public function an_ordinary_admin_cannot_reach_the_control_plane(): void
    {
        auth()->guard('web')->setUser(new User(['username' => 'ordinary']));

        $response = $this->app->make(EnsureMasterAdmin::class)->handle(
            $this->guardRequest(['is_admin' => true, 'is_master_admin' => false]),
            fn () => response('ok'),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('دسترسی مدیریت اصلی لازم است.', $response->getData(true)['detail']);
    }

    #[Test]
    public function a_master_admin_session_passes_the_control_plane_guard(): void
    {
        auth()->guard('web')->setUser(new User(['username' => 'ali']));

        $response = $this->app->make(EnsureMasterAdmin::class)->handle(
            $this->guardRequest(['is_admin' => true, 'is_master_admin' => true]),
            fn () => response('ok'),
        );

        $this->assertSame('ok', $response->getContent());
    }

    // ── Session bootstrap ────────────────────────────────────────────────────

    #[Test]
    public function the_login_path_serves_the_spa_shell(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('<div id="app"></div>', false);
    }

    #[Test]
    public function the_csrf_endpoint_mints_a_token_and_publishes_the_readable_cookie(): void
    {
        $response = $this->get('/api/csrf-token');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $token = $response->json('csrf_token');
        $this->assertIsString($token);
        $this->assertSame(64, strlen($token), 'the legacy token is 32 random bytes as hex');

        $cookie = $this->cookieFrom($response, LegacyCsrfCookie::NAME);
        $this->assertNotNull($cookie, 'the readable csrf_token cookie must be set');
        $this->assertSame($token, $cookie->getValue());
        $this->assertFalse($cookie->isHttpOnly(), 'the front-end reads this cookie on purpose');
        $this->assertSame('lax', strtolower($cookie->getSameSite() ?? 'lax'));

        // The lifetime is in seconds everywhere the operator can see it, and
        // Laravel's helper wants minutes: passing seconds through produced a
        // 20-day cookie instead of an 8-hour one.
        $expected = LegacyCsrfCookie::lifetimeSeconds();
        $this->assertSame(28800, $expected);
        $this->assertEqualsWithDelta(
            $expected,
            $cookie->getExpiresTime() - time(),
            5.0,
            'the cookie must expire in SESSION_MAX_AGE_SECONDS, not in that many minutes',
        );
    }

    #[Test]
    public function the_public_config_answers_the_keys_the_login_page_reads_as_strings(): void
    {
        $response = $this->get('/api/system-config');

        $response->assertOk();
        $response->assertJson(['success' => true]);

        $data = $response->json('data');

        foreach ([
            'captcha_enabled',
            'idle_timeout_enabled',
            'idle_timeout_seconds',
            'login_loader_enabled',
            'login_loader_seconds',
            'login_loader_title',
            'login_loader_message',
            'login_captcha_notice',
            'login_captcha_ttl_seconds',
        ] as $key) {
            $this->assertArrayHasKey($key, $data, "missing public setting {$key}");
        }

        // The existing JavaScript compares these as strings
        // (`res.data.idle_timeout_enabled !== '0'`), so the types are part of the
        // contract rather than an oversight.
        foreach (['captcha_enabled', 'idle_timeout_enabled', 'idle_timeout_seconds', 'login_loader_enabled', 'login_loader_seconds', 'login_captcha_notice', 'login_captcha_ttl_seconds'] as $key) {
            $this->assertIsString($data[$key], "{$key} must stay a string");
        }
    }

    #[Test]
    public function an_anonymous_session_is_not_identified_by_the_me_endpoint(): void
    {
        $response = $this->getJson('/api/me');

        $response->assertStatus(401);
        $response->assertJson(['success' => false, 'unauthenticated' => true]);
    }

    #[Test]
    public function logout_ends_the_session_and_returns_to_the_login_page(): void
    {
        $this->get('/logout')->assertRedirect('/login');
    }

    // ── CAPTCHA ──────────────────────────────────────────────────────────────

    #[Test]
    public function the_captcha_image_is_a_png_that_must_not_be_cached(): void
    {
        if (! function_exists('imagepng')) {
            $this->markTestSkipped('the gd extension is not loaded');
        }

        $response = $this->get('/captcha');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));

        $bytes = $response->getContent();
        $this->assertGreaterThan(500, strlen($bytes));
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8), 'the body is PNG, not an error page');
    }

    #[Test]
    public function the_captcha_code_never_appears_in_the_response_body(): void
    {
        if (! function_exists('imagepng')) {
            $this->markTestSkipped('the gd extension is not loaded');
        }

        $response = $this->get('/captcha');
        $stored = session(LegacyCaptcha::SESSION_KEY);

        $this->assertIsString($stored);
        $this->assertSame(6, strlen($stored));
        $this->assertStringNotContainsString($stored, $response->getContent());
    }

    #[Test]
    public function the_captcha_alphabet_excludes_the_ambiguous_glyphs(): void
    {
        $this->assertSame('ABCDEFGHJKLMNPQRSTUVWXYZ', LegacyCaptcha::ALPHABET);
        $this->assertSame(24, strlen(LegacyCaptcha::ALPHABET));
    }

    #[Test]
    public function a_refresh_returns_a_data_uri_and_replaces_the_stored_code(): void
    {
        if (! function_exists('imagepng')) {
            $this->markTestSkipped('the gd extension is not loaded');
        }

        $response = $this->post('/captcha/refresh');

        $response->assertOk();
        $this->assertStringStartsWith('data:image/png;base64,', (string) $response->json('image'));

        $decoded = base64_decode(substr((string) $response->json('image'), strlen('data:image/png;base64,')), true);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr((string) $decoded, 0, 8));
    }

    #[Test]
    public function the_captcha_status_reports_no_code_before_one_is_issued(): void
    {
        $this->getJson('/captcha/status')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'valid' => false,
                'has_captcha' => false,
                'remaining_seconds' => 0,
            ]);
    }

    /**
     * The validation rules, exercised on the service the controller uses.
     *
     * The session store is the real one, so this covers "the code is cleared on
     * success" and "an expired code reports `expired` rather than `mismatch`" —
     * the distinction the login endpoint relies on to answer the user usefully.
     */
    #[Test]
    public function a_captcha_code_is_case_insensitive_and_single_use(): void
    {
        $captcha = $this->app->make(LegacyCaptcha::class);
        $session = $this->app['session']->driver();

        $captcha->store($session, 'ABCDEF');

        $this->assertTrue($captcha->validate($session, 'abcdef')['valid']);
        $this->assertFalse($captcha->hasCode($session), 'a used code must not be replayable');
        $this->assertFalse($captcha->validate($session, 'ABCDEF')['valid']);
    }

    #[Test]
    public function a_wrong_captcha_code_counts_down_and_then_burns_the_code(): void
    {
        $captcha = $this->app->make(LegacyCaptcha::class);
        $session = $this->app['session']->driver();

        $captcha->store($session, 'ABCDEF');

        // Latin digits: the Python f-string interpolated an `int`, so the message
        // has always read "2 تلاش باقی مانده." and not "۲ …".
        $first = $captcha->validate($session, 'WRONG1');
        $this->assertFalse($first['valid']);
        $this->assertStringContainsString('2 تلاش باقی مانده', $first['message']);

        $second = $captcha->validate($session, 'WRONG2');
        $this->assertStringContainsString('1 تلاش باقی مانده', $second['message']);

        $third = $captcha->validate($session, 'WRONG3');
        $this->assertFalse($third['valid']);
        $this->assertFalse($captcha->hasCode($session));
    }

    #[Test]
    public function an_expired_code_is_reported_as_expired_not_as_a_mismatch(): void
    {
        $captcha = $this->app->make(LegacyCaptcha::class);
        $session = $this->app['session']->driver();

        $captcha->store($session, 'ABCDEF');
        $session->put(LegacyCaptcha::SESSION_TS_KEY, time() - 10_000);

        $wasPresent = $captcha->hasCode($session);
        $wasExpired = $captcha->expired($session);

        $this->assertTrue($wasExpired);
        $this->assertSame(
            LegacyCaptcha::REASON_EXPIRED,
            $captcha->failureReason($session, $wasPresent, $wasExpired),
        );
        $this->assertStringContainsString('منقضی', $captcha->validate($session, 'ABCDEF')['message']);
    }

    #[Test]
    public function a_missing_code_is_reported_as_missing(): void
    {
        $captcha = $this->app->make(LegacyCaptcha::class);
        $session = $this->app['session']->driver();

        $this->assertSame(
            LegacyCaptcha::REASON_MISSING,
            $captcha->failureReason($session, false, false),
        );
    }

    #[Test]
    public function a_generated_code_uses_only_the_unambiguous_alphabet(): void
    {
        $captcha = $this->app->make(LegacyCaptcha::class);

        for ($i = 0; $i < 100; $i++) {
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ]{6}$/', $captcha->code());
        }
    }

    // ── Session registry ─────────────────────────────────────────────────────

    #[Test]
    public function session_ids_are_url_safe_and_high_entropy(): void
    {
        $registry = $this->app->make(SessionRegistry::class);
        $seen = [];

        for ($i = 0; $i < 100; $i++) {
            $token = $registry->newToken();

            // `secrets.token_urlsafe(32)`: 32 bytes of base64 with padding removed.
            $this->assertSame(43, strlen($token));
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
            $this->assertArrayNotHasKey($token, $seen);
            $seen[$token] = true;
        }
    }

    #[Test]
    public function an_empty_session_id_is_never_valid(): void
    {
        $this->assertFalse($this->app->make(SessionRegistry::class)->validate('', 'ali'));
    }

    // ── Recovery endpoints ───────────────────────────────────────────────────

    #[Test]
    public function forgot_password_rejects_a_non_json_body_the_way_the_legacy_route_did(): void
    {
        $this->post('/forgot_password', ['username' => 'ali'])
            ->assertStatus(400)
            ->assertJson(['success' => false, 'message' => 'درخواست نامعتبر است.']);
    }

    #[Test]
    public function reset_password_rejects_a_malformed_request_id_before_touching_the_database(): void
    {
        $this->postJson('/reset_password', [
            'request_id' => 'nonsense',
            'code' => 'AB12CD34',
            'new_password' => 'Str0ng!Pass',
        ])
            ->assertOk()
            ->assertJson([
                'success' => false,
                'message' => 'فرمت شناسه درخواست نامعتبر است.',
            ]);
    }

    #[Test]
    public function reset_password_enforces_the_password_policy(): void
    {
        $this->postJson('/reset_password', [
            'request_id' => 'HST-20260930-0A1B2C3D',
            'code' => 'AB12CD34',
            'new_password' => 'weak',
        ])
            ->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'رمز عبور ضعیف است: حداقل ۸ کاراکتر, حداقل یک حرف بزرگ انگلیسی, حداقل یک عدد, حداقل یک کاراکتر خاص (!@#$%^&*)');
    }

    #[Test]
    public function reset_password_requires_a_new_password(): void
    {
        $this->postJson('/reset_password', [
            'request_id' => 'HST-20260930-0A1B2C3D',
            'code' => 'AB12CD34',
            'new_password' => ' ',
        ])
            ->assertJson(['success' => false, 'message' => 'رمز عبور جدید الزامی است.']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A POST to an exempt path, with a session and (optionally) an `Origin`.
     *
     * A session is attached because the framework adds the `XSRF-TOKEN` cookie to
     * every response, which needs one — exactly as a real browser request has one.
     */
    private function exemptRequest(string $path, ?string $origin = null, string $host = 'localhost'): Request
    {
        $request = Request::create($path, 'POST');
        $request->setLaravelSession($this->app['session']->driver());
        $request->headers->set('Host', $host);

        if ($origin !== null) {
            $request->headers->set('Origin', $origin);
        }

        return $request;
    }

    /**
     * A request whose session holds the given authorisation flags.
     *
     * The guards decide from the session flags the login flow set, exactly as the
     * Python code did, so a request with no flags is an ordinary user.
     *
     * @param  array<string, mixed>  $session
     */
    private function guardRequest(array $session = []): Request
    {
        $store = $this->app['session']->driver();
        $store->start();

        foreach ($session as $key => $value) {
            $store->put($key, $value);
        }

        $request = Request::create('/api/anything', 'GET');
        $request->setLaravelSession($store);

        return $request;
    }

    /**
     * A request carrying the legacy session token and (optionally) the header.
     *
     * The session store is the real one, so this exercises the same comparison the
     * middleware performs in production rather than a reimplementation of it.
     */
    private function requestWithLegacyToken(?string $header): Request
    {
        $store = $this->app['session']->driver();
        $store->start();
        $store->put(VerifyLegacyCsrf::LEGACY_SESSION_KEY, 'legacy-token-value');

        $request = Request::create('/api/session/destroy', 'POST');
        $request->setLaravelSession($store);

        if ($header !== null) {
            $request->headers->set(VerifyLegacyCsrf::LEGACY_HEADER, $header);
        }

        return $request;
    }

    private function cookieFrom(TestResponse $response, string $name): ?Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }
}
