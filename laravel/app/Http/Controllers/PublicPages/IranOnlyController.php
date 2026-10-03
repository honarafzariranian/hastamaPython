<?php

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use App\Support\Connectivity\IranAccessService;
use App\Support\Connectivity\IranOnlyPage;
use App\Support\Http\ClientAddress;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /iran-only` and `GET /iran-only/check` — the standing access-policy
 * guide and the probe the guide polls, ported from `iran_only_page` and
 * `iran_only_check` in `app/main.py`.
 *
 * Both are reachable on purpose from every address: a user who is asked to
 * switch a VPN off needs a URL they can keep, and the warning page itself
 * polls the check to come back on its own.
 */
final class IranOnlyController extends Controller
{
    /**
     * The page a blocked request carries.
     *
     * The context is `iran_access.page_context(ip=…)` — the verdict for the
     * caller's own address only, so the page leaks nothing about anyone else.
     */
    public function page(): Response
    {
        return response(
            IranOnlyPage::render(IranOnlyPage::context(ClientAddress::for(request()))),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }

    /**
     * Would *this* caller be allowed in right now?
     *
     * The body is the Python's, key for key: `success` is always `true` (the
     * check itself worked), `allowed` is the verdict's boolean, `ip`/`kind`
     * are the verdict's strings (empty when it has none), and `enforcing` is
     * whether the filter can currently reject anything at all.
     */
    public function check(): JsonResponse
    {
        $verdict = IranAccessService::classify(ClientAddress::for(request()));

        return response()->json([
            'success' => true,
            'allowed' => (bool) ($verdict['allowed'] ?? false),
            'ip' => ($verdict['ip'] ?? '') !== '' ? $verdict['ip'] : '',
            'kind' => ($verdict['kind'] ?? '') !== '' ? $verdict['kind'] : '',
            'enforcing' => IranAccessService::enforcing(),
        ], Response::HTTP_OK, ['Cache-Control' => 'no-store']);
    }
}
