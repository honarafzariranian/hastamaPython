<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline response headers.
 *
 * Deliberately conservative, and deliberately **not** a Content-Security-Policy
 * yet: the Vue bundle, the Vite dev server and the call-display page all inject
 * differently, and a wrong CSP breaks the interface in ways that look like
 * application bugs.  The full policy is a Phase 15 (security review) decision taken
 * against the finished front-end, where every resource origin is known.
 *
 * What is set here is safe unconditionally:
 *
 * * `X-Content-Type-Options: nosniff` — the legacy CAPTCHA endpoint set this too;
 * * `X-Frame-Options: SAMEORIGIN` — the panels are not meant to be framed, while
 *   the call display is served on the same origin so it keeps working;
 * * `Referrer-Policy: same-origin` — the call-display page checks the `Referer` to
 *   decide whether it is allowed to render, and that check must not be fed by a
 *   cross-origin referrer;
 * * `Permissions-Policy` — the display needs sound, so autoplay is not disabled;
 *   everything the application never uses is switched off.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // The CAPTCHA image and any authenticated page must never be cached by a
        // shared proxy; per-endpoint no-store headers are added by the CAPTCHA
        // controller itself, where the contract requires them.
        if ($request->is('api/*') && ! $response->headers->has('Cache-Control')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        }

        return $response;
    }
}
