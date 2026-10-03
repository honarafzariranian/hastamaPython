<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Legacy\LegacyDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The public-content and shell surface ported from `app/main.py`.
 *
 * Everything here runs **offline** — no live SQL Server, no live session
 * registry.  That is possible because every guard short-circuits on the
 * session array before the database is touched, and the two rendered
 * documents read their settings through `SystemConfigStore`, which fails soft
 * to the defaults when the `system_config` table is not there.
 *
 * The guard matrix is the heart of the suite: the Python answered these
 * routes with **303 redirects** computed in the handler, in a specific order,
 * and a port that answered them with the middleware's JSON bodies (or with a
 * Vue route guard) would be a different application.
 */
final class PublicPagesTest extends TestCase
{
    /**
     * Every route this phase adds, and the controller action it must reach.
     *
     * Route **names** are asserted rather than paths alone so a later refactor
     * that renames a route cannot silently change which handler answers a
     * legacy path.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routes(): array
    {
        return [
            'favicon' => ['/favicon.ico', 'App\Http\Controllers\PublicPages\StaticAssetsController@favicon'],
            'sw.js' => ['/sw.js', 'App\Http\Controllers\PublicPages\StaticAssetsController@serviceWorker'],
            'robots.txt' => ['/robots.txt', 'App\Http\Controllers\PublicPages\StaticAssetsController@robotsTxt'],
            'sitemap.xml' => ['/sitemap.xml', 'App\Http\Controllers\PublicPages\StaticAssetsController@sitemapXml'],
            'offline' => ['/offline', 'App\Http\Controllers\PublicPages\OfflineController@offline'],
            'iran-only' => ['/iran-only', 'App\Http\Controllers\PublicPages\IranOnlyController@page'],
            'iran-only/check' => ['/iran-only/check', 'App\Http\Controllers\PublicPages\IranOnlyController@check'],
            'api/date' => ['/api/date', 'App\Http\Controllers\PublicPages\DateController@today'],
            'api/training/search' => ['/api/training/search', 'App\Http\Controllers\PublicPages\TrainingController@search'],
            'register' => ['/register', 'App\Http\Controllers\PublicPages\ShellController@register'],
            'rules' => ['/rules', 'App\Http\Controllers\PublicPages\ShellController@rules'],
            'ticket-kiosk' => ['/ticket-kiosk', 'App\Http\Controllers\PublicPages\ShellController@ticketKiosk'],
            'ticket-print' => ['/ticket-print', 'App\Http\Controllers\PublicPages\ShellController@ticketPrint'],
            'training' => ['/training', 'App\Http\Controllers\PublicPages\TrainingController@hub'],
            'training/{category}' => ['/training/general', 'App\Http\Controllers\PublicPages\TrainingController@category'],
            'training/lesson/{lesson_id}' => ['/training/lesson/welcome', 'App\Http\Controllers\PublicPages\TrainingController@lesson'],
            'master-admin' => ['/master-admin', 'App\Http\Controllers\PublicPages\MasterAdminController@root'],
            'master-admin/{section}' => ['/master-admin/dashboard', 'App\Http\Controllers\PublicPages\MasterAdminController@section'],
            'call-display' => ['/call-display', 'App\Http\Controllers\PublicPages\CallPageController@display'],
            'call-management' => ['/call-management', 'App\Http\Controllers\PublicPages\CallPageController@management'],
            'user_panel' => ['/user_panel', 'App\Http\Controllers\PublicPages\UserPanelController@panel'],
            'admin' => ['/admin', 'App\Http\Controllers\PublicPages\AdminController@admin'],
            'admin/dashboard' => ['/admin/dashboard', 'App\Http\Controllers\PublicPages\AdminController@dashboard'],
            'admin/{section}' => ['/admin/coworkers', 'App\Http\Controllers\PublicPages\AdminController@section'],
        ];
    }

    #[DataProvider('routes')]
    public function test_the_legacy_path_is_wired_to_its_controller(string $path, string $action): void
    {
        // Matched with a real request rather than by comparing URI strings,
        // because a parameterised route (`/admin/{section}`) never equals its
        // own resolved path.
        $request = Request::create($path, 'GET');

        $route = collect(Route::getRoutes())->first(
            static fn ($route): bool => $route->matches($request)
        );

        $this->assertNotNull($route, "no route is registered for {$path}");
        $this->assertContains('GET', $route->methods());
        $this->assertSame($action, $route->getActionName());
    }

    /**
     * `/login` is registered once, by `routes/web.php` as the SPA shell.
     *
     * The port must not duplicate it: two routes on one path is a collision
     * that `route:list` would not flag, and the second registration is the
     * one that silently loses.
     */
    public function test_login_is_registered_exactly_once(): void
    {
        $matches = collect(Route::getRoutes())
            ->filter(static fn ($route): bool => $route->uri() === 'login')
            ->values();

        $this->assertCount(1, $matches, '/login must be registered exactly once');
        $this->assertSame('login', $matches->first()->getName());
    }

    /**
     * Every route name is globally unique — the `public.` prefix is what
     * guarantees it, and a collision would surface as the wrong handler
     * answering a legacy path.
     */
    public function test_no_route_name_collides_with_another_registration(): void
    {
        $names = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name !== null) {
                $names[$name] = ($names[$name] ?? 0) + 1;
            }
        }

        $duplicates = array_keys(array_filter($names, static fn (int $count): bool => $count > 1));

        $this->assertSame([], $duplicates, 'route names must be globally unique');
    }

    // ── The landing page ────────────────────────────────────────────────────

    /**
     * The root is a 301 to `/login` — the private portal's entry point, not a
     * marketing page.  The status is 301 and not 303: it is a permanent move
     * the browser is meant to cache.
     */
    public function test_the_root_redirects_to_login_with_301(): void
    {
        $this->get('/')
            ->assertStatus(301)
            ->assertRedirect('/login');
    }

    // ── Machine and static endpoints ─────────────────────────────────────────

    /**
     * The favicon is the Python deployment's own file, byte for byte.
     *
     * The body is asserted through the file the response wraps rather than
     * through `getContent()` — a `BinaryFileResponse` streams its file at send
     * time and answers `getContent()` with `false`.  The content type plus the
     * file identity is the whole body contract.
     */
    public function test_favicon_serves_the_python_file_bytes(): void
    {
        $response = $this->get('/favicon.ico');

        $response->assertStatus(200);
        $this->assertSame('image/x-icon', $response->headers->get('Content-Type'));

        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame(
            realpath(base_path('../app/static/favicon.ico')),
            realpath($response->baseResponse->getFile()->getPathname())
        );
    }

    /**
     * The service worker, with the two headers that make it updatable.
     *
     * `Service-Worker-Allowed: /` states the root scope explicitly (a worker
     * cannot live under `/static/`), and the no-cache triplet means a new
     * copy is picked up immediately.
     *
     * **A documented divergence:** the Python sent
     * `Cache-Control: no-cache, no-store, must-revalidate` verbatim.
     * Symfony's `ResponseHeaderBag` *computes* the header — it re-sorts the
     * directives alphabetically and appends `, private` to a list without
     * `public`/`private`/`s-maxage` — so this server answers
     * `must-revalidate, no-cache, no-store, private`.  Both mean "do not
     * cache", which is the contract that matters for a file that must update
     * the moment it is replaced.
     */
    public function test_sw_js_serves_the_worker_with_its_headers(): void
    {
        $response = $this->get('/sw.js');

        $response->assertStatus(200);
        $this->assertSame('application/javascript', $response->headers->get('Content-Type'));
        $this->assertSame('/', $response->headers->get('Service-Worker-Allowed'));
        $this->assertSame('must-revalidate, no-cache, no-store, private', $response->headers->get('Cache-Control'));

        $this->assertInstanceOf(BinaryFileResponse::class, $response->baseResponse);
        $this->assertSame(
            realpath(base_path('../app/static/sw.js')),
            realpath($response->baseResponse->getFile()->getPathname())
        );
    }

    /**
     * `robots.txt` is one literal in the Python, trailing newline included.
     */
    public function test_robots_txt_is_the_exact_literal(): void
    {
        $response = $this->get('/robots.txt');

        $response->assertStatus(200);
        $this->assertSame('text/plain; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame(
            "User-agent: *\nDisallow: /\nSitemap: https://hastama.ir/sitemap.xml\n",
            $response->getContent()
        );
    }

    /**
     * `sitemap.xml` is one URL — the login page — as a single-line document.
     */
    public function test_sitemap_xml_is_the_exact_literal(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertStatus(200);
        $this->assertSame('application/xml; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>https://hastama.ir/login</loc></url></urlset>',
            $response->getContent()
        );
    }

    /**
     * `/api/date` answers the bare Jalali triple — no `success` key, no
     * envelope — because that is what the login page reads before anyone has
     * a session.
     */
    public function test_api_date_answers_the_jalali_triple(): void
    {
        $response = $this->get('/api/date');

        $response->assertStatus(200);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame(LegacyDate::today()->toDayTriple(), $response->json());
    }

    // ── The rendered guide documents ─────────────────────────────────────────

    /**
     * `/offline` is served with 200 on purpose — the service worker caches it
     * (the `cache` APIs reject error responses) — and is never stored.
     *
     * The defaults are asserted rather than the settings, because
     * `system_config` is empty in the test deployment and the Python's
     * `page_context()` falls back to the same defaults.
     *
     * **A documented divergence:** the Python sent `Cache-Control: no-store`
     * verbatim; Symfony's `ResponseHeaderBag` appends `, private` to a
     * directive list without `public`/`private`/`s-maxage`, so this server
     * answers `no-store, private`.  Same meaning — do not cache.
     */
    public function test_offline_page_renders_the_outage_guide(): void
    {
        $response = $this->get('/offline');

        $response->assertStatus(200);
        $this->assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));

        $content = (string) $response->getContent();

        $this->assertStringContainsString('ارتباط سامانه با اینترنت قطع شده است', $content);
        $this->assertStringContainsString('قطع ارتباط', $content);
        $this->assertStringContainsString('data-retry="30"', $content);
    }

    /**
     * `/iran-only` is reachable from every address with 200 — a user who is
     * asked to switch a VPN off needs a URL they can keep.
     */
    public function test_iran_only_page_renders_the_access_guide(): void
    {
        $response = $this->get('/iran-only');

        $response->assertStatus(200);
        $this->assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));

        $content = (string) $response->getContent();

        $this->assertStringContainsString('دسترسی از این آی‌پی مجاز نیست', $content);
        $this->assertStringContainsString('data-retry="30"', $content);
        $this->assertStringContainsString('چگونه VPN را قطع کنم و وارد شوم؟', $content);
    }

    /**
     * `/iran-only/check` answers about the caller's own address only.
     *
     * The loopback address is `internal` — always allowed — which is the one
     * verdict that does not depend on the range file being loaded.
     */
    public function test_iran_only_check_answers_the_callers_own_verdict(): void
    {
        $response = $this->get('/iran-only/check');

        $response->assertStatus(200);
        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertSame(
            ['success', 'allowed', 'ip', 'kind', 'enforcing'],
            array_keys($response->json())
        );

        $this->assertTrue($response->json('success'));
        $this->assertSame('internal', $response->json('kind'));
        $this->assertTrue($response->json('allowed'));
        $this->assertSame('127.0.0.1', $response->json('ip'));
    }

    /**
     * A public address is classified against the range list — and the answer
     * is self-consistent whether the list is loaded or not.
     */
    public function test_iran_only_check_is_self_consistent_for_a_public_address(): void
    {
        $response = $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->get('/iran-only/check');

        $response->assertStatus(200);

        $kind = $response->json('kind');

        $this->assertContains($kind, ['iran', 'foreign', 'unknown']);
        $this->assertSame($kind !== 'foreign', $response->json('allowed'));
        $this->assertSame('8.8.8.8', $response->json('ip'));
    }

    // ── The public shells ────────────────────────────────────────────────────

    /**
     * The public page routes serve the one mount document — the same view
     * `routes/web.php` serves for `/login` — and Vue Router renders the page.
     *
     * @return array<int, string>
     */
    public static function publicShells(): array
    {
        // PHPUnit 12 requires every dataset to be an array of arguments.
        return [
            ['/register'],
            ['/rules'],
            ['/training'],
            ['/training/general'],
            ['/training/lesson/welcome'],
            ['/ticket-kiosk'],
            ['/ticket-print'],
        ];
    }

    #[DataProvider('publicShells')]
    public function test_the_public_shells_serve_the_spa_mount_document(string $path): void
    {
        $response = $this->get($path);

        $response->assertStatus(200);
        $this->assertSame('text/html; charset=utf-8', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('<div id="app"></div>', (string) $response->getContent());
    }

    // ── The guard matrix ─────────────────────────────────────────────────────

    /**
     * `/master-admin` redirects **unconditionally** — the Python has no auth
     * check on this route at all; the guard lives one level down.
     */
    public function test_master_admin_root_always_redirects_to_the_dashboard(): void
    {
        $this->get('/master-admin')->assertStatus(303)->assertRedirect('/master-admin/dashboard');

        $this->asSession(['username' => 'ali'])->get('/master-admin')
            ->assertStatus(303)
            ->assertRedirect('/master-admin/dashboard');

        $this->asSession(['username' => 'ali', 'is_master_admin' => true])->get('/master-admin')
            ->assertStatus(303)
            ->assertRedirect('/master-admin/dashboard');
    }

    /**
     * `/master-admin/{section}` — the Python's guard, in the Python's order.
     *
     * The two failures have two different targets: an anonymous caller goes to
     * `/login`, a signed-in non-master-admin goes to `/admin` (the panel they
     * do have).  A session with the flag but no username is still "nobody".
     *
     * @return array<string, array{0: array<string, mixed>, 1: int}>
     */
    public static function masterAdminSectionGuards(): array
    {
        return [
            'anonymous' => [[], 303],
            'signed in, not master admin' => [['username' => 'ali'], 303],
            'flag without username' => [['is_master_admin' => true], 303],
            'master admin' => [['username' => 'ali', 'is_master_admin' => true], 200],
        ];
    }

    #[DataProvider('masterAdminSectionGuards')]
    public function test_master_admin_section_guard(array $session, int $status): void
    {
        $response = $session === []
            ? $this->get('/master-admin/dashboard')
            : $this->asSession($session)->get('/master-admin/dashboard');

        $response->assertStatus($status);

        if ($status === 200) {
            $this->assertStringContainsString('<div id="app"></div>', (string) $response->getContent());

            return;
        }

        $expected = $session === [] || ! isset($session['username']) ? '/login' : '/admin';

        $response->assertRedirect($expected);
    }

    /**
     * The admin guard — including the two rows the inventory gets wrong.
     *
     * `/admin/dashboard` and `/admin/{section}` are **not** public: the
     * source's `_render_admin_page` runs the same `not username or not
     * is_admin` check and answers 303 `/login`.  And `is_master_admin` alone
     * does not satisfy it — the flag is `is_admin`.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string, 2: int, 3: string}>
     */
    public static function adminGuards(): array
    {
        return [
            'anonymous /admin' => [[], '/admin', 303, '/login'],
            'signed in, not admin /admin' => [['username' => 'ali'], '/admin', 303, '/login'],
            'master admin flag is not is_admin /admin' => [['username' => 'ali', 'is_master_admin' => true], '/admin', 303, '/login'],
            'admin /admin goes on to the dashboard' => [['username' => 'ali', 'is_admin' => true], '/admin', 303, '/admin/dashboard'],
            'anonymous /admin/dashboard' => [[], '/admin/dashboard', 303, '/login'],
            'signed in, not admin /admin/dashboard' => [['username' => 'ali'], '/admin/dashboard', 303, '/login'],
            'admin /admin/dashboard' => [['username' => 'ali', 'is_admin' => true], '/admin/dashboard', 200, '/login'],
            'admin /admin/{section}' => [['username' => 'ali', 'is_admin' => true], '/admin/coworkers', 200, '/login'],
            'anonymous /admin/{section}' => [[], '/admin/coworkers', 303, '/login'],
        ];
    }

    #[DataProvider('adminGuards')]
    public function test_admin_guard(array $session, string $path, int $status, string $redirect): void
    {
        $response = $session === []
            ? $this->get($path)
            : $this->asSession($session)->get($path);

        $response->assertStatus($status);

        if ($status === 200) {
            $this->assertStringContainsString('<div id="app"></div>', (string) $response->getContent());

            return;
        }

        $response->assertRedirect($redirect);
    }

    /**
     * `/user_panel` — any signed-in user; an anonymous caller is redirected.
     */
    public function test_user_panel_requires_a_session(): void
    {
        $this->get('/user_panel')->assertStatus(303)->assertRedirect('/login');

        $this->asSession(['username' => 'ali'])->get('/user_panel')
            ->assertStatus(200)
            ->assertSee('<div id="app"></div>', false);
    }

    /**
     * The call pages — `_require_call_page_access`.
     *
     * A master admin whose navigation did not come from the dashboard (or one
     * of the pages themselves) is sent back to the dashboard; a signed-in
     * non-master-admin goes to `/admin`; an anonymous caller to `/login`.
     *
     * A `null` referer means "no Referer header"; a `http://` value is used
     * verbatim (the foreign host); anything else is a **path** on the
     * same-site host, which the test builds from `app.url` — the test client
     * requests `url('/call-display')`, so the request host is the configured
     * one (`127.0.0.1` here), not `localhost`.
     *
     * @return array<string, array{0: array<string, mixed>, 1: string|null, 2: int}>
     */
    public static function callPageGuards(): array
    {
        $masterAdmin = ['username' => 'ali', 'is_master_admin' => true];

        return [
            'anonymous /call-display' => [[], null, 303],
            'anonymous /call-management' => [[], null, 303],
            'signed in, not master admin' => [['username' => 'ali'], null, 303],
            'master admin, no referer' => [$masterAdmin, null, 303],
            'master admin, dashboard referer' => [$masterAdmin, '/master-admin/dashboard', 200],
            'master admin, call-management referer' => [$masterAdmin, '/call-management', 200],
            'master admin, call-display referer' => [$masterAdmin, '/call-display', 200],
            'master admin, trailing slash' => [$masterAdmin, '/master-admin/dashboard/', 200],
            'master admin, query string' => [$masterAdmin, '/master-admin/dashboard?tab=calls', 200],
            'master admin, foreign host' => [$masterAdmin, 'http://evil.example.com/master-admin/dashboard', 303],
            'master admin, other local path' => [$masterAdmin, '/', 303],
            'master admin, other master-admin path' => [$masterAdmin, '/master-admin/users', 303],
        ];
    }

    #[DataProvider('callPageGuards')]
    public function test_call_pages_require_a_master_admin_referer(
        array $session,
        ?string $referer,
        int $status
    ): void {
        $testCase = $session === [] ? $this : $this->asSession($session);

        if ($referer !== null) {
            $testCase->withHeaders(['Referer' => $this->refererUrl($referer)]);
        }

        $response = $testCase->get('/call-display');

        $response->assertStatus($status);

        if ($status === 200) {
            $this->assertStringContainsString('<div id="app"></div>', (string) $response->getContent());

            return;
        }

        $expected = match (true) {
            $session === [] => '/login',
            ($session['is_master_admin'] ?? null) === true => '/master-admin/dashboard',
            default => '/admin',
        };

        $response->assertRedirect($expected);
    }

    /**
     * The referer check is per-page: `/call-management` applies exactly the
     * same rule.
     */
    public function test_call_management_applies_the_same_referer_rule(): void
    {
        $this->asSession(['username' => 'ali', 'is_master_admin' => true])->get('/call-management')
            ->assertStatus(303)
            ->assertRedirect('/master-admin/dashboard');

        $this->asSession(['username' => 'ali', 'is_master_admin' => true])
            ->withHeaders(['Referer' => $this->refererUrl('/master-admin/dashboard')])
            ->get('/call-management')
            ->assertStatus(200);
    }

    // ── The training search ──────────────────────────────────────────────────

    /**
     * An absent or empty `q` answers the empty result list, not an error —
     * `q` is `Query("")`, so FastAPI could not reject it for length.
     */
    public function test_training_search_empty_query_answers_an_empty_list(): void
    {
        foreach (['/api/training/search', '/api/training/search?q='] as $path) {
            $response = $this->get($path);

            $response->assertStatus(200);
            $this->assertSame('application/json', $response->headers->get('Content-Type'));
            $this->assertSame(['success' => true, 'results' => []], $response->json());
        }
    }

    /**
     * FastAPI ignored undeclared query parameters, and so does the port —
     * `LegacyQuery` only looks up the names it declared.
     */
    public function test_training_search_ignores_unknown_parameters(): void
    {
        $response = $this->get('/api/training/search?unknown=value');

        $response->assertStatus(200);
        $this->assertSame(['success' => true, 'results' => []], $response->json());
    }

    /**
     * The role filter — the observable half of `_category_accessible`.
     *
     * The category slug is part of the searchable text, so `?q=admin` finds
     * exactly the admin lessons for a caller allowed to see them, and none
     * for a caller who is not.  The expected counts are computed from the
     * catalogue the port reads, so a lesson added to the file is picked up
     * without editing the test.
     */
    public function test_training_search_filters_lessons_by_role(): void
    {
        $catalogue = json_decode(
            (string) file_get_contents(base_path('../app/data/training_content.json')),
            true
        );

        $count = static fn (string $category): int => count(array_filter(
            $catalogue['lessons'],
            static fn (array $lesson): bool => ($lesson['category'] ?? '') === $category
        ));

        // Anonymous: the admin and user categories are invisible.
        $this->get('/api/training/search?q=admin')
            ->assertStatus(200)
            ->assertJson(['success' => true, 'results' => []]);

        $this->get('/api/training/search?q=user')
            ->assertStatus(200)
            ->assertJson(['success' => true, 'results' => []]);

        // A signed-in non-administrator sees general and user.
        $userResults = $this->withSession(['username' => 'ali'])
            ->get('/api/training/search?q=user')
            ->json('results');

        $this->assertCount($count('user'), $userResults);

        // An administrator (or master admin) sees everything.
        $adminResults = $this->withSession(['username' => 'ali', 'is_admin' => true])
            ->get('/api/training/search?q=admin')
            ->json('results');

        $this->assertCount($count('admin'), $adminResults);

        $masterAdminResults = $this->withSession(['username' => 'ali', 'is_master_admin' => true])
            ->get('/api/training/search?q=admin')
            ->json('results');

        $this->assertCount($count('admin'), $masterAdminResults);
    }

    /**
     * The result shape — the keys the hub's search box reads, in the Python's
     * order, with the category's display title (not its slug) and the two
     * defaults `role: 'general'` and the book icon.
     */
    public function test_training_search_result_shape(): void
    {
        $response = $this->withSession(['username' => 'ali', 'is_admin' => true])
            ->get('/api/training/search?q=پنل مدیریت');

        $response->assertStatus(200);

        $results = $response->json('results');

        $this->assertNotEmpty($results);
        $this->assertSame(
            ['id', 'title', 'description', 'category', 'role', 'icon'],
            array_keys($results[0])
        );

        $catalogue = json_decode(
            (string) file_get_contents(base_path('../app/data/training_content.json')),
            true
        );

        $adminTitle = $catalogue['categories']['admin']['title'];

        foreach ($results as $result) {
            $this->assertSame($adminTitle, $result['category']);
            $this->assertSame('admin', $result['role']);
        }

        $this->assertSame('admin-welcome', $results[0]['id']);
    }

    /**
     * The search is a substring test over the lowercased searchable text, so
     * a partial word in a title matches.
     */
    public function test_training_search_matches_a_partial_term(): void
    {
        $response = $this->withSession(['username' => 'ali', 'is_admin' => true])
            ->get('/api/training/search?q=داشبورد');

        $response->assertStatus(200);

        $ids = array_column($response->json('results'), 'id');

        $this->assertContains('admin-dashboard', $ids);
        $this->assertContains('user-dashboard', $ids);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * A signed-in caller for the guarded routes.
     *
     * Three layers, because every guarded route carries
     * `legacy.session:optional`:
     *
     * * `actingAs` — the Laravel auth user the middleware resolves;
     * * `withSession` — the legacy session flags the ported handlers read
     *   (`username`, `is_admin`, `is_master_admin`);
     * * `sid` — a registry token.  The `user_sessions` table does not exist
     *   in the test deployment, and `SessionRegistry` fails **open** on
     *   infrastructure errors, so the token reaches the database and the
     *   check passes — the handler's own guard is what the test is about.
     */
    private function asSession(array $session): static
    {
        $username = isset($session['username']) ? (string) $session['username'] : 'actor';

        return $this->actingAs(new User(['username' => $username]))
            ->withSession(['sid' => 'test-session-token'] + $session);
    }

    /**
     * A referer URL for a same-site path.
     *
     * The test client requests `url('/call-display')`, so the request host is
     * the one in `app.url` (`127.0.0.1` here) — not `localhost`.  The Python
     * compares hostnames without the port, so the referer only needs the
     * right host.  A value that is already a full URL is returned verbatim.
     */
    private function refererUrl(string $referer): string
    {
        if (str_starts_with($referer, 'http://') || str_starts_with($referer, 'https://')) {
            return $referer;
        }

        $appUrl = (string) config('app.url', 'http://localhost');
        $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'http';
        $host = parse_url($appUrl, PHP_URL_HOST) ?: 'localhost';

        return $scheme.'://'.$host.$referer;
    }
}
