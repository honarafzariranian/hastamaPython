<?php

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The machine and static endpoints at the origin root — ported from the top of
 * `app/main.py`.
 *
 * What they have in common is that none of them renders the application and
 * none of them touches the session: they answer the bytes the Python put on the
 * wire, with the same content type and the same headers, so a browser, a
 * service worker or a crawler cannot tell the two servers apart.
 *
 * The two files are served from the Python tree (`app/static/…`) rather than
 * copied into `public/`, because the running deployment's copy is the source of
 * truth during the side-by-side period — a favicon or a service worker that
 * drifted between the two trees would be a bug nobody can see in a diff.
 */
final class StaticAssetsController extends Controller
{
    /**
     * `GET /favicon.ico` — the icon itself.
     *
     * The Python declared `media_type="image/x-icon"` on the `FileResponse`;
     * the type is passed explicitly rather than guessed for the same reason.
     */
    public function favicon(): Response
    {
        return $this->serveFile('favicon.ico', 'image/x-icon');
    }

    /**
     * `GET /sw.js` — the outage service worker, served from the origin root.
     *
     * A worker only controls paths at or below its own URL, so it cannot live
     * under `/static/`; `Service-Worker-Allowed` states the root scope
     * explicitly and the file itself is never cached, so a new copy is picked
     * up immediately.  Both headers are the Python's, byte for byte.
     */
    public function serviceWorker(): Response
    {
        return $this->serveFile('sw.js', 'application/javascript', [
            'Service-Worker-Allowed' => '/',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    }

    /**
     * `GET /robots.txt` — the whole file is one `Response` literal in the
     * Python, trailing newline included.
     */
    public function robotsTxt(): Response
    {
        return response(
            "User-agent: *\nDisallow: /\nSitemap: https://hastama.ir/sitemap.xml\n",
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain; charset=utf-8'],
        );
    }

    /**
     * `GET /static/{path}` — the exact legacy asset tree.
     *
     * The Python templates for the call pages still reference `/static/...`.
     * Serving the original files keeps the HTML, CSS, JavaScript, fonts and
     * bundled audio byte-for-byte aligned without duplicating them into
     * Laravel's `public/` tree.
     */
    public function legacyStatic(string $path): BinaryFileResponse
    {
        $base = realpath(base_path('../app/static'));

        abort_if(! is_string($base) || $base === '', 404);

        $candidate = realpath($base . DIRECTORY_SEPARATOR . ltrim($path, '/'));

        abort_if(
            ! is_string($candidate)
            || $candidate === ''
            || is_dir($candidate)
            || ! str_starts_with($candidate, $base . DIRECTORY_SEPARATOR),
            404
        );

        return response()->file($candidate);
    }

    /**
     * `GET /sitemap.xml` — one URL, the login page, as a single-line document.
     */
    public function sitemapXml(): Response
    {
        return response(
            '<?xml version="1.0" encoding="UTF-8"?>'
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            .'<url><loc>https://hastama.ir/login</loc></url></urlset>',
            Response::HTTP_OK,
            ['Content-Type' => 'application/xml; charset=utf-8'],
        );
    }

    /**
     * Serve a file from the Python tree the way Starlette's `FileResponse` did.
     *
     * `BinaryFileResponse` is the Laravel equivalent: it streams the file and
     * sets `Content-Length`, `Last-Modified` and `Accept-Ranges`, and answers
     * `If-Modified-Since` with 304 — all of which the Python's `FileResponse`
     * did too.
     *
     * The `$public` flag is passed `false` on purpose.  The default (`true`)
     * calls `setPublic()`, which adds a `public` cache-control directive that
     * would contradict the `no-store` the service worker route declares.
     *
     * **A documented divergence:** Symfony's `ResponseHeaderBag` *computes*
     * the `Cache-Control` header rather than storing it — a directive list
     * without `public`/`private`/`s-maxage` gets `, private` appended, and the
     * directives are re-sorted alphabetically.  The Python sent the header
     * verbatim (`no-cache, no-store, must-revalidate` for the worker, nothing
     * for the favicon); this server answers
     * `must-revalidate, no-cache, no-store, private` and
     * `private, must-revalidate` respectively.  Both mean "do not cache",
     * which is the contract that matters for a file that must update the
     * moment it is replaced.
     *
     * @param  array<string, string>  $headers
     */
    private function serveFile(string $name, string $contentType, array $headers = []): Response
    {
        $path = base_path('../app/static/'.$name);

        if (! is_file($path)) {
            // The Python raised `FileNotFoundError` (a 500) here.  A missing
            // static asset is a 404 by any reading, and both files ship with the
            // application, so this is a broken deployment rather than a request
            // — the divergence is deliberate and documented.
            abort(404);
        }

        return new BinaryFileResponse($path, Response::HTTP_OK, ['Content-Type' => $contentType] + $headers, false);
    }
}
