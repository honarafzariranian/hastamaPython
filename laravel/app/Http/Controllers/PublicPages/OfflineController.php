<?php

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use App\Support\Connectivity\OfflinePage;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /offline` — the outage guide, ported from `offline_page` in
 * `app/main.py`.
 *
 * Served with ``200`` on purpose: the service worker caches it (the ``cache``
 * APIs reject error responses) and shows it when the browser itself cannot
 * reach the server.  Blocked page requests are answered with the same template
 * plus ``503`` by the outage gate — a different route with a different
 * contract.
 */
final class OfflineController extends Controller
{
    public function offline(): Response
    {
        return response(
            OfflinePage::render(OfflinePage::context()),
            Response::HTTP_OK,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store'],
        );
    }
}
