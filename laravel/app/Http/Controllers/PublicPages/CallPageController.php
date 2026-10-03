<?php

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The call-system pages — ported from `call_display` and `call_management` in
 * `app/main.py`.
 *
 * Both are closed to direct entry: they only render for a master-admin
 * session whose navigation came from `/master-admin/dashboard` (or from one of
 * these pages — iframe / refresh).  The guard is `_require_call_page_access`
 * and `_call_page_referer_allowed`, reproduced in the handler because the
 * contract is a 303 redirect, not a JSON body.
 */
final class CallPageController extends Controller
{
    /**
     * `GET /call-display` — the TV display page.
     */
    public function display(Request $request): Response
    {
        return $this->denied($request) ?? $this->legacyTemplate($request, 'call-display.html');
    }

    /**
     * `GET /call-management` — the reception call desk.
     */
    public function management(Request $request): Response
    {
        return $this->denied($request) ?? $this->legacyTemplate($request, 'call-management.html');
    }

    /**
     * `_require_call_page_access` — allow only master-admin sessions linked
     * from the dashboard (or self).
     *
     * Returns the redirect the Python answered, or `null` when the caller may
     * see the page.
     */
    private function denied(Request $request): ?Response
    {
        $username = $request->session()->get('username');

        if (! is_string($username) || $username === '') {
            return redirect('/login', 303);
        }

        if ($request->session()->get('is_master_admin') !== true) {
            return $this->deniedRedirect($request);
        }

        if (! $this->refererAllowed($request)) {
            return $this->deniedRedirect($request);
        }

        return null;
    }

    /**
     * Serve the legacy Python page shell, with only the runtime config Laravel must inject.
     */
    private function legacyTemplate(Request $request, string $file): Response
    {
        $path = dirname(base_path()) . '/app/templates/' . $file;

        abort_unless(is_file($path), 404);

        $html = (string) file_get_contents($path);
        $injected = '<script>'.$this->realtimeBootstrap($request).'</script>';

        if (str_contains($html, '</head>')) {
            $html = str_replace('</head>', $injected."\n</head>", $html);
        } else {
            $html = $injected.$html;
        }

        return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /**
     * Runtime configuration for the legacy call-page scripts.
     *
     * The original Python pages talked to a raw WebSocket endpoint at
     * `/api/ws/call-display`. Laravel's real-time layer is Reverb (Pusher
     * protocol), so the legacy scripts need the connection facts injected at
     * render time while the visual HTML stays byte-for-byte identical.
     */
    private function realtimeBootstrap(Request $request): string
    {
        $scheme = (string) (config('reverb.apps.apps.0.options.scheme') ?: ($request->isSecure() ? 'https' : 'http'));
        $host = (string) (config('reverb.apps.apps.0.options.host') ?: $request->getHost());
        $port = config('reverb.apps.apps.0.options.port');
        $path = (string) (config('reverb.servers.reverb.path') ?: '');
        $appKey = (string) (config('reverb.apps.apps.0.key') ?: '');

        $config = [
            'mode' => $appKey !== '' ? 'reverb' : 'legacy',
            'appKey' => $appKey,
            'host' => $host,
            'port' => $port === null ? null : (int) $port,
            'scheme' => $scheme,
            'path' => $path,
            'displayChannel' => 'call-display',
            'previewChannel' => 'call-display.preview',
            'eventName' => 'display.message',
            'audioActivatedUrl' => '/api/calls/audio-activated',
        ];

        return 'window.HastamaRealtime = '.json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).';';
    }

    /**
     * `_call_page_denied` — where a refused caller is sent.
     *
     * A master admin who lost the referer (a typed URL, a refreshed iframe)
     * goes back to the dashboard the links live on; everybody else who is
     * signed in goes to `/admin`.
     */
    private function deniedRedirect(Request $request): Response
    {
        $username = $request->session()->get('username');

        if (! is_string($username) || $username === '') {
            return redirect('/login', 303);
        }

        if ($request->session()->get('is_master_admin') === true) {
            return redirect('/master-admin/dashboard', 303);
        }

        return redirect('/admin', 303);
    }

    /**
     * `_call_page_referer_allowed` — a same-site referer on one of the three
     * entry paths.
     *
     * The comparison is host-without-port on both sides, exactly as Python's
     * `urlparse(...).hostname` compared them, and the path is stripped of
     * every trailing slash before the set membership test.
     *
     * @var array<int, string>
     */
    private const ENTRY_PATHS = ['/master-admin/dashboard', '/call-management', '/call-display'];

    private function refererAllowed(Request $request): bool
    {
        $referer = trim((string) $request->header('Referer', ''));

        if ($referer === '') {
            return false;
        }

        $parsed = parse_url($referer);

        if ($parsed === false) {
            return false;
        }

        $path = (string) ($parsed['path'] ?? '/');
        $refererHost = isset($parsed['host']) ? mb_strtolower((string) $parsed['host']) : '';

        if ($refererHost === '') {
            return false;
        }

        // Same-site only — a foreign host must never unlock the pages.
        if ($refererHost !== mb_strtolower($request->getHost())) {
            return false;
        }

        $path = rtrim($path, '/');

        if ($path === '') {
            $path = '/';
        }

        return in_array($path, self::ENTRY_PATHS, true);
    }
}
