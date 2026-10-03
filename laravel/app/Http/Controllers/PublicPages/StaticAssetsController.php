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
 * They are served from the Python tree (`app/static/…`) rather than copied into
 * `public/`, because the running deployment's copy is the source of
 * truth during the side-by-side period — a favicon or a service worker that
 * drifted between the two trees would be a bug nobody can see in a diff.
 */
final class StaticAssetsController extends Controller
{
    /**
     * The `Content-Type` values the Python's `mimetypes` table produced for the
     * extensions this tree actually carries.
     *
     * A map rather than a runtime guess because the only files the application
     * serves from here are images, fonts, stylesheets and MP3s, and a
     * `nosniff`-protected response must not depend on what the host happens to
     * have registered for `.mp3`.
     *
     * @var array<string, string>
     */
    private const MIME = [
        'css' => 'text/css',
        'gif' => 'image/gif',
        'ico' => 'image/x-icon',
        'jpeg' => 'image/jpeg',
        'jpg' => 'image/jpeg',
        'js' => 'text/javascript',
        'json' => 'application/json',
        'mp3' => 'audio/mpeg',
        'png' => 'image/png',
        'svg' => 'image/svg+xml',
        'ttf' => 'font/ttf',
        'txt' => 'text/plain',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
    ];

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
     * `GET /static/{path}` — the legacy static tree.
     *
     * The Python mounted the whole directory (`app.mount("/static",
     * StaticFiles(directory="app/static"))`), and two of its subtrees are
     * referenced by URL from the call surfaces: `/static/audio/sample_call/…`
     * (the 2 000 spoken-number MP3s a display plays for a call) and
     * `/static/slides/…` (the images the wall screens cycle between calls).  The
     * two JSON endpoints hand out exactly those URLs, so unless the same paths
     * answer here, the TV shows a broken `<img>` and stays silent.
     *
     * The tree is served from the Python checkout for the same reason the favicon
     * is: an uploaded slide or a re-rendered MP3 must appear on both servers on
     * the same day, not only on whichever one copied it last.
     *
     * What `StaticFiles` guaranteed and is reproduced here: only regular files
     * below the root are ever read (a `..`, an empty or absolute segment, or a
     * symlink escaping the tree is a 404), the extension decides the
     * `Content-Type`, and `BinaryFileResponse` supplies `Last-Modified`,
     * `Accept-Ranges` and range slicing, which is what lets an `<audio>` seek.
     *
     * No directory listing and no `.html` fallback exist on either server, so a
     * path naming a directory is simply a 404 here too.  An extension the map
     * does not know is `application/octet-stream` rather than a guess: a stray
     * `.html` file in the tree must never execute on this origin.
     */
    public function legacyAsset(string $path): Response
    {
        $relative = str_replace('\\', '/', trim($path, '/'));

        if ($relative === '' || str_contains($relative, "\0")) {
            abort(404);
        }

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                abort(404);
            }
        }

        $root = realpath(base_path('../app/static'));
        $file = $root === false ? false : realpath($root.'/'.$relative);

        // `realpath` resolves symlinks, so the containment test cannot be walked
        // around with a link planted inside the tree.
        if ($file === false || ! is_file($file) || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)) {
            abort(404);
        }

        return new BinaryFileResponse($file, Response::HTTP_OK, [
            'Content-Type' => self::MIME[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? 'application/octet-stream',
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
