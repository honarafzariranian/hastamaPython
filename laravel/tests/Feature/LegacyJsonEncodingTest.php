<?php

namespace Tests\Feature;

use App\Support\Http\LegacyResponseFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\ResponseFactory;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The wire encoding of every JSON response.
 *
 * Laravel's `json_encode` default — options `0` — escapes all non-ASCII
 * (`\u0644\u0627\u06af\u06cc\u0646`) and all forward slashes (`\/`).  Starlette called
 * `json.dumps(..., ensure_ascii=False)`, so the running server emits the Persian text
 * as raw UTF-8.  A decoder reads both identically, which is exactly why this needs a
 * test: nothing else in the suite can fail because of it, and almost every message
 * this application returns is Persian.
 *
 * Asserted on the **raw body**, not on `$response->json()`, because the decoded value
 * is the same either way — that is the whole problem.
 */
final class LegacyJsonEncodingTest extends TestCase
{
    #[Test]
    public function the_json_response_factory_is_the_legacy_one(): void
    {
        // A stale cache or a provider registered out of order would silently restore
        // Laravel's default factory, and every assertion below would then fail for a
        // reason that looks like an encoding bug rather than a binding bug.
        $this->assertInstanceOf(LegacyResponseFactory::class, app(ResponseFactory::class));
        $this->assertInstanceOf(LegacyResponseFactory::class, response());
    }

    #[Test]
    public function persian_text_is_sent_as_raw_utf8(): void
    {
        Route::get('/tmp-json-encoding', fn () => response()->json([
            'success' => false,
            'error' => 'لاگین نکرده‌اید.',
        ]));

        $raw = $this->get('/tmp-json-encoding')->getContent();

        // Byte-for-byte what `curl http://127.0.0.1:5000/get_user_info_report?username=x`
        // answers, which is what this is imitating.
        $this->assertSame('{"success":false,"error":"لاگین نکرده‌اید."}', $raw);
        $this->assertStringNotContainsString('\\u', $raw);
    }

    #[Test]
    public function forward_slashes_are_not_escaped(): void
    {
        Route::get('/tmp-json-slashes', fn () => response()->json([
            // The two places a `/` really does appear in a response: an audit-log
            // `resource_id` and the `link` the global search builds.
            'link' => '/master-admin#users',
            'resource_id' => 'user/admin',
        ]));

        $raw = $this->get('/tmp-json-slashes')->getContent();

        $this->assertSame('{"link":"/master-admin#users","resource_id":"user/admin"}', $raw);
    }

    #[Test]
    public function a_caller_may_still_add_encoding_options(): void
    {
        Route::get('/tmp-json-options', fn () => response()->json(
            ['note' => 'متنی'],
            options: JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE,
        ));

        $raw = $this->get('/tmp-json-options')->getContent();

        // `JSON_PRETTY_PRINT` is honoured …
        $this->assertStringContainsString("\n", $raw);
        // … and the legacy flags are still OR-ed in, so the escaped form cannot come
        // back through the `$options` argument.
        $this->assertStringContainsString('متنی', $raw);
    }

    #[Test]
    public function the_encoding_options_are_the_ones_starlette_used(): void
    {
        $this->assertSame(
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            LegacyResponseFactory::ENCODING,
        );

        // U+2028/U+2029 stay escaped, in PHP and in Python alike — a deliberate
        // non-change, asserted so it is not "fixed" by accident later.
        $this->assertSame(0, LegacyResponseFactory::ENCODING & JSON_UNESCAPED_LINE_TERMINATORS);

        $response = new JsonResponse(['error' => 'لاگین'], 200, [], JSON_UNESCAPED_UNICODE);
        $this->assertTrue($response->hasEncodingOption(JSON_UNESCAPED_UNICODE));
    }
}
