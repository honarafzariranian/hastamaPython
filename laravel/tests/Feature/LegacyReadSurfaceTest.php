<?php

namespace Tests\Feature;

use App\Http\Middleware\ValidateLegacySession;
use App\Models\User;
use App\Support\Legacy\LegacyDate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * The legacy read surface: route wiring, guards, and the exact bodies each guard
 * answers with.
 *
 * Everything here runs **offline**.  That is possible because the guards and the
 * shape checks short-circuit before the database is touched, which is exactly the
 * property worth testing: a guard that reaches for the database before deciding
 * whether the caller is allowed is a guard that can be made to fail open under load.
 *
 * The bodies asserted below are not guesses.  They were read from the running
 * FastAPI implementation's helper functions:
 *
 *     _ticket_actor   403  {"success": false, "error": "دسترسی غیرمجاز"}
 *     _require_admin  401  {"success": false, "error": "لاگین نکرده‌اید."}
 *     _require_admin  403  {"success": false, "error": "دسترسی مدیریتی ندارید."}
 *     _require_auth   401  {"success": false, "error": "لاگین نکرده‌اید."}
 *     _master_admin   401  {"detail": "ورود لازم است."}
 *     _master_admin   403  {"detail": "دسترسی مدیریت اصلی لازم است."}
 *
 * Note the two families: the `main.py` helpers answer with an `error` key, the
 * master-admin module answers with FastAPI's `detail`.  A client that reads only
 * `success` would not notice; the two existing front-ends read different keys, so
 * the difference is preserved rather than unified.
 */
final class LegacyReadSurfaceTest extends TestCase
{
    /**
     * Every route this phase adds, and the controller action it must reach.
     *
     * Route **names** are asserted rather than paths alone so a later refactor that
     * renames a route cannot silently change which handler answers a legacy path.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function routes(): array
    {
        return [
            'get_users' => ['/get_users', 'App\Http\Controllers\UserPanel\PickerController@users'],
            'get_receivers' => ['/get_receivers', 'App\Http\Controllers\UserPanel\PickerController@receivers'],
            'get_user_info' => ['/get_user_info', 'App\Http\Controllers\UserPanel\ProfileController@info'],
            'get_user_info_report' => ['/get_user_info_report', 'App\Http\Controllers\UserPanel\ProfileController@report'],
            'get_leave_info' => ['/get_leave_info', 'App\Http\Controllers\UserPanel\LeaveController@info'],
            'get_leave_requests' => ['/get_leave_requests', 'App\Http\Controllers\UserPanel\LeaveController@requests'],
            'get_active_shifts' => ['/get_active_shifts', 'App\Http\Controllers\UserPanel\ShiftController@active'],
            'get_today_date' => ['/get_today_date', 'App\Http\Controllers\UserPanel\CalendarController@today'],
            'dashboard stats' => ['/master-admin/api/dashboard/stats', 'App\Http\Controllers\MasterAdmin\DashboardController@stats'],
            'dashboard activity' => ['/master-admin/api/dashboard/activity', 'App\Http\Controllers\MasterAdmin\DashboardController@activity'],
            'users index' => ['/master-admin/api/users', 'App\Http\Controllers\MasterAdmin\UserController@index'],
            'users show' => ['/master-admin/api/users/admin', 'App\Http\Controllers\MasterAdmin\UserController@show'],
            'audit logs index' => ['/master-admin/api/audit-logs', 'App\Http\Controllers\MasterAdmin\AuditLogController@index'],
            'audit logs show' => ['/master-admin/api/audit-logs/HST-1', 'App\Http\Controllers\MasterAdmin\AuditLogController@show'],
            'sessions index' => ['/master-admin/api/sessions', 'App\Http\Controllers\MasterAdmin\SessionListController@index'],
            'system health' => ['/master-admin/api/system-health', 'App\Http\Controllers\MasterAdmin\SystemHealthController@show'],
            'search' => ['/master-admin/api/search', 'App\Http\Controllers\MasterAdmin\SearchController@search'],
        ];
    }

    #[DataProvider('routes')]
    public function test_the_legacy_path_is_wired_to_its_controller(string $path, string $action): void
    {
        // Matched with a real request rather than by comparing URI strings, because a
        // parameterised route (`/users/{username}`) never equals its own resolved path.
        $request = Request::create($path, 'GET');

        $route = collect(Route::getRoutes())->first(
            static fn ($route): bool => $route->matches($request)
        );

        $this->assertNotNull($route, "no route is registered for {$path}");
        $this->assertContains('GET', $route->methods());
        $this->assertSame($action, $route->getActionName());
    }

    /**
     * The two picker endpoints are the ones with the unusual guard: a fully anonymous
     * request is answered **403**, not 401, because `_ticket_actor` does not
     * distinguish "not signed in" from "not allowed".
     */
    public function test_the_picker_endpoints_answer_the_ticket_actor_body_when_anonymous(): void
    {
        foreach (['/get_users', '/get_receivers'] as $path) {
            $response = $this->get($path);

            $response->assertStatus(403);
            $this->assertSame(
                ['success' => false, 'error' => 'دسترسی غیرمجاز'],
                $response->json(),
                "{$path} must answer the _ticket_actor body",
            );
        }
    }

    public function test_the_admin_guarded_user_panel_reads_answer_the_require_admin_bodies(): void
    {
        foreach (['/get_active_shifts', '/get_leave_requests'] as $path) {
            $response = $this->get($path);

            $response->assertStatus(401);
            $this->assertSame(
                ['success' => false, 'error' => 'لاگین نکرده‌اید.'],
                $response->json(),
                "{$path} must answer the _require_admin body",
            );
        }
    }

    /**
     * `/get_user_info_report` guards itself with `_require_auth`, which answers 401
     * rather than 403 — but the required `?username=` is resolved **first**.
     *
     * `username: str = Query(...)` is validated by FastAPI as part of request
     * handling, before the function body — so the two rejections apply in that order,
     * and an anonymous request that omits the parameter is a 422.  The handler's own
     * 401 is what an anonymous request *with* the parameter gets.  Asserting both is
     * the only way to pin the order down, since either status looks reasonable on its
     * own.
     */
    public function test_the_report_endpoint_resolves_its_required_parameter_before_it_authenticates(): void
    {
        $missing = $this->get('/get_user_info_report');

        $missing->assertStatus(422);
        $this->assertSame('missing', $missing->json('detail.0.type'));
        $this->assertSame(['query', 'username'], $missing->json('detail.0.loc'));

        $anonymous = $this->get('/get_user_info_report?username=admin');

        $anonymous->assertStatus(401);
        $this->assertSame(['success' => false, 'error' => 'لاگین نکرده‌اید.'], $anonymous->json());
    }

    /**
     * `/get_user_info` and `/get_leave_info` are session-scoped but unguarded, and
     * each answers a missing session with its **own** body — a 200 envelope for the
     * first and a 400 `detail` for the second.  Both are preserved verbatim.
     */
    public function test_the_unguarded_session_reads_answer_their_own_missing_session_bodies(): void
    {
        $info = $this->get('/get_user_info');
        $info->assertOk();
        $this->assertSame(['success' => false, 'message' => 'نام کاربری پیدا نشد'], $info->json());

        $leave = $this->get('/get_leave_info');
        $leave->assertStatus(400);
        $this->assertSame(['detail' => 'نام کاربری پیدا نشد'], $leave->json());
    }

    /**
     * The control plane answers FastAPI's `detail` shape, which is what the admin UI
     * reads — not the `error`/`message` keys the rest of the module uses.
     */
    public function test_every_master_admin_read_refuses_an_anonymous_request_with_the_fastapi_body(): void
    {
        $paths = [
            '/master-admin/api/dashboard/stats',
            '/master-admin/api/dashboard/activity',
            '/master-admin/api/users',
            '/master-admin/api/users/admin',
            '/master-admin/api/audit-logs',
            '/master-admin/api/audit-logs/HST-1',
            '/master-admin/api/sessions',
            '/master-admin/api/system-health',
            '/master-admin/api/search',
        ];

        foreach ($paths as $path) {
            $response = $this->get($path);

            $response->assertStatus(401);
            $this->assertSame(
                ['detail' => 'ورود لازم است.'],
                $response->json(),
                "{$path} must answer the _master_admin 401 body",
            );
        }
    }

    /**
     * A signed-in administrator who is **not** a master administrator is refused, and
     * the refusal is the 403 the Python raised.
     *
     * This is the assertion that matters most in the whole file: `is_admin` alone must
     * never reach the control plane, because several of its endpoints return one-time
     * recovery codes and change roles.  The check is the session flag the login flow
     * sets, so no database access is needed to decide it.
     */
    public function test_an_ordinary_admin_is_refused_by_the_control_plane(): void
    {
        $this->actingAs(new User(['username' => 'ordinary-admin']));

        $response = $this->withSession([
            'username' => 'ordinary-admin',
            'is_admin' => true,
            'is_master_admin' => false,
        ])->get('/master-admin/api/dashboard/stats');

        $response->assertStatus(403);
        $this->assertSame(['detail' => 'دسترسی مدیریت اصلی لازم است.'], $response->json());
    }

    /**
     * The guard runs **before** the session registry, and the ordering is a decision
     * rather than an accident.
     *
     * It is asserted directly because the difference is invisible from the outside
     * when both middleware permit a request — and only visible when both would refuse,
     * which is precisely the anonymous case: the guard's `{"detail": …}` is the body
     * the UI is written against, and the registry's "session expired" body is not.
     */
    public function test_the_control_plane_runs_the_guard_before_the_session_registry(): void
    {
        $middleware = Route::getRoutes()->getByName('master-admin.users.index')->gatherMiddleware();

        $this->assertSame(
            ['web', 'master_admin', 'legacy.session:optional'],
            array_values(array_filter($middleware, 'is_string')),
        );

        // Stated as positions as well, so the intent survives a future change to the
        // group composition: the guard must come before the registry check.
        $this->assertLessThan(
            array_search('legacy.session:optional', $middleware, true),
            array_search('master_admin', $middleware, true),
        );
    }

    /**
     * `legacy.session:optional` reproduces the legacy middleware's pass-through for a
     * request that carries no identity at all — and **only** when the parameter is
     * present.
     *
     * The opt-in is the point.  `/api/me` is built against the strict behaviour, where
     * an anonymous request is answered `401` so the Vue client knows to show the login
     * page; the legacy routes need the opposite, because their handlers already own the
     * answer for "nobody is signed in".  Making the pass-through the default would
     * quietly change `/api/me`.
     */
    public function test_only_the_optional_parameter_lets_an_identity_less_request_through(): void
    {
        $this->assertTrue(
            $this->passesThroughMiddleware('optional'),
            'the optional parameter must reproduce the legacy pass-through',
        );

        $this->assertFalse(
            $this->passesThroughMiddleware(null),
            'the default (used by /api/me) must stay strict',
        );

        $this->assertFalse(
            $this->passesThroughMiddleware('strict'),
            'an unrecognised parameter must not enable the pass-through',
        );
    }

    /**
     * `GET /get_today_date` is public and answers a **bare** Jalali triple.
     *
     * No `success` key, no `data` wrapper, three integers.  The front-end reads
     * `.year` off the response object, so an envelope would break it.
     */
    public function test_today_date_answers_a_bare_jalali_triple(): void
    {
        $response = $this->get('/get_today_date');

        $response->assertOk();

        $today = LegacyDate::today();

        $this->assertSame(['year', 'month', 'day'], array_keys($response->json()));
        $this->assertSame($today->year, $response->json('year'));
        $this->assertSame($today->month, $response->json('month'));
        $this->assertSame($today->day, $response->json('day'));
        $this->assertSame(['year' => $today->year, 'month' => $today->month, 'day' => $today->day], $response->json());

        // 1400 … 1500 is the range the live data occupies; a UTC-vs-Tehran slip would
        // still sit inside it, so the year is a sanity check rather than the assertion.
        $this->assertGreaterThan(1400, $response->json('year'));
        $this->assertLessThan(1500, $response->json('year'));
    }

    /**
     * Drive `ValidateLegacySession` directly and report whether it called through.
     *
     * Invoked by hand rather than through a route because the strict behaviour is
     * already under test on `/api/me`, and adding a throwaway route to assert the
     * branch would be a second thing to keep in sync.  The request carries a session
     * because the framework attaches one to every real request — a session-less
     * request makes the middleware throw when the response tries to add its
     * `XSRF-TOKEN` cookie.
     */
    private function passesThroughMiddleware(?string $mode): bool
    {
        $request = Request::create('/get_user_info', 'GET');
        $request->setLaravelSession($this->app['session']->driver());

        $called = false;

        $response = $this->app->make(ValidateLegacySession::class)->handle(
            $request,
            function () use (&$called): Response {
                $called = true;

                return new Response('next', 200);
            },
            $mode,
        );

        $this->assertInstanceOf(Response::class, $response);

        return $called;
    }
}
