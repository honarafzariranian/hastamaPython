<?php

namespace App\Http\Controllers\CallSystem;

use App\Support\Http\LegacyOrigin;
use App\Support\Legacy\CallActor;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyPath;
use App\Support\Legacy\LegacySerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `/api/calls/slides*` — the images the TV screens cycle through between calls.
 *
 * Five routes, and the authorisation on them is more careful than the rest of the module
 * because they are the only ones that touch the **filesystem**: an upload writes a file
 * that every display in the building will render, and a delete unlinks one.
 *
 * Two things are deliberate and easy to "tidy" away:
 *
 * 1. **`/slides/upload` checks the actor *before* the origin.** The Python called
 *    `_actor(admin=True, required=True)` and only then `origin_is_same_site`, so an
 *    anonymous cross-site upload gets `401` and not `403` — an attacker learns nothing
 *    about the origin check until they are authenticated.
 * 2. **`/slides/{id}/toggle` and `DELETE /slides/{id}` check the origin *before* the
 *    actor**, the opposite order, because the Python did. Those two are idempotent-shaped
 *    writes driven by the console's table buttons, where the CSRF-exempt path is the risk
 *    and the identity is already known.
 *
 * The order is asserted in the tests rather than left to look accidental.
 *
 * **Magic bytes are the real validation.** A browser-supplied `Content-Type` is whatever
 * the client claims, so the Python also matched the leading bytes to the extension and
 * refused a mismatch — and a file whose extension is not a known image type is treated as
 * `.jpg` rather than rejected, which is what keeps a camera's `IMG_0001` with no extension
 * (or a scanner's `.jpeg`) working.
 */
final class SlideController extends CallSystemController
{
    /** `SLIDES_DIR` — the legacy application's static tree, shared by both servers. */
    private const SLIDES_DIR = 'slides';

    private const MAX_BYTES = 10 * 1024 * 1024;

    /** A file smaller than this cannot be a real image. */
    private const MIN_BYTES = 100;

    /** The `Content-Type` values the Python accepted. */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** Extension → the leading bytes that extension must carry. */
    private const MAGIC = [
        '.jpg' => ["\xff\xd8\xff"],
        '.jpeg' => ["\xff\xd8\xff"],
        '.png' => ["\x89PNG"],
        '.gif' => ['GIF8'],
        '.webp' => ['RIFF'],
    ];

    /** `GET /api/calls/slides` */
    public function index(): JsonResponse
    {
        $rows = DB::connection()->select(
            'SELECT id, filename, original_name, is_active, sort_order, created_at
             FROM slides ORDER BY sort_order ASC, id ASC'
        );

        $slides = [];

        foreach ($rows as $row) {
            // `is_active` is a `bit`: `pdo_sqlsrv` hands back "1"/"0" where `pyodbc` gave a
            // real boolean, so the serializer is what keeps this a JSON `true`.
            $slide = LegacySerializer::row('slides', $row);

            // `created_at` is re-formatted in place, keeping its column position; `url` is
            // then appended, which is the order the console's sheet reads.
            $slide['created_at'] = LegacySerializer::isoUtcZ($slide['created_at'] ?? null);
            $slide['url'] = '/static/slides/'.$slide['filename'];

            $slides[] = $slide;
        }

        return $this->ok(['slides' => $slides]);
    }

    /**
     * `GET /api/calls/slides/active` — what the displays actually fetch.
     *
     * A hand-built object rather than a serialised row: the Python read the row by
     * position and published exactly four keys, **without** `original_name`, `is_active` or
     * `created_at`.  The displays poll this on every slide change, so it is the smallest
     * payload that can drive them.
     */
    public function active(): JsonResponse
    {
        $rows = DB::connection()->select(
            'SELECT id, filename, sort_order FROM slides WHERE is_active = 1 ORDER BY sort_order ASC, id ASC'
        );

        $slides = [];

        foreach ($rows as $row) {
            $filename = (string) $row->filename;

            $slides[] = [
                'id' => (int) $row->id,
                'filename' => $filename,
                'url' => '/static/slides/'.$filename,
                'sort_order' => (int) $row->sort_order,
            ];
        }

        return $this->ok(['slides' => $slides]);
    }

    /** `POST /api/calls/slides/upload` */
    public function upload(Request $request): JsonResponse
    {
        // Actor **first**, then origin — see the class docblock.
        CallActor::resolve($request, admin: true, required: true);

        if (! LegacyOrigin::isSameSite($request)) {
            throw LegacyHttpException::crossSite();
        }

        $file = $request->file('file');

        // `file: UploadFile = File(...)` is a required multipart field, so a request
        // without it is FastAPI's 422 rather than a crash.
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw LegacyHttpException::detail(422, 'فایل نامعتبر است.');
        }

        if (! in_array($file->getClientMimeType(), self::ALLOWED_MIME, true)) {
            throw LegacyHttpException::detail(422, 'فقط فایل‌های تصویری (JPG, PNG, WebP, GIF) مجاز هستند.');
        }

        // Read the size from the file itself rather than from `getSize()`, and check the
        // ceiling before the floor: the Python streamed at most `MAX+1` bytes so a huge
        // upload was refused without ever being buffered whole.
        $size = $file->getSize();

        if ($size > self::MAX_BYTES) {
            throw LegacyHttpException::detail(422, 'حجم فایل نباید بیشتر از ۱۰ مگابایت باشد.');
        }

        if ($size < self::MIN_BYTES) {
            throw LegacyHttpException::detail(422, 'فایل نامعتبر است.');
        }

        $extension = strtolower((string) pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION));
        $extension = $extension === '' ? '' : '.'.$extension;

        // An unknown extension is served as `.jpg` rather than refused — a scanner that
        // writes no extension at all is a real input in this lab.
        if (! array_key_exists($extension, self::MAGIC)) {
            $extension = '.jpg';
        }

        $contents = (string) file_get_contents($file->getRealPath());

        if (! $this->hasMagicBytes($contents, $extension)) {
            throw LegacyHttpException::detail(422, 'محتوای فایل با پسوند آن مطابقت ندارد.');
        }

        $this->ensureDirectory();
        $safeName = 'slide_'.now()->format('Ymd_His_u').$extension;

        DB::connection()->transaction(function () use ($file, $safeName, $contents): void {
            $directory = $this->directory();

            if (file_put_contents($directory.DIRECTORY_SEPARATOR.$safeName, $contents) === false) {
                throw new \RuntimeException('could not write the slide file');
            }

            // `ISNULL(MAX(sort_order), 0) + 1` — appended last, so re-uploading a slide
            // puts it at the end of the rotation rather than silently at the front.
            $maxOrder = (int) DB::connection()->selectOne(
                'SELECT ISNULL(MAX(sort_order), 0) AS value FROM slides'
            )->value;

            // `int` rather than the name the extension came from: the stored filename is
            // always the generated one, never the client's.
            DB::table('slides')->insert([
                'filename' => $safeName,
                'original_name' => $file->getClientOriginalName() ?: $safeName,
                'is_active' => 1,
                'sort_order' => $maxOrder + 1,
            ]);
        });

        $original = $file->getClientOriginalName() ?: $safeName;

        return $this->ok([
            'message' => 'اسلاید با موفقیت آپلود شد.',
            'slide' => [
                'id' => (int) DB::connection()->selectOne(
                    'SELECT TOP 1 id FROM slides WHERE filename = ? ORDER BY id DESC',
                    [$safeName],
                )->id,
                'filename' => $safeName,
                'original_name' => $original,
                'url' => '/static/slides/'.$safeName,
                'is_active' => true,
            ],
        ]);
    }

    /** `PUT /api/calls/slides/{slide_id}/toggle` */
    public function toggle(Request $request, string $slideId): JsonResponse
    {
        // Path validation first — FastAPI converts `{slide_id}` before the handler runs, so
        // a malformed id is a 422 even when the origin check below would have refused.
        $slideId = LegacyPath::int($slideId, 'slide_id');

        // Origin **next** here — see the class docblock.
        if (! LegacyOrigin::isSameSite($request)) {
            throw LegacyHttpException::crossSite();
        }

        CallActor::requireAdmin($request);

        $updated = DB::connection()->update(
            'UPDATE slides SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?',
            [$slideId],
        );

        if ($updated === 0) {
            throw LegacyHttpException::detail(404, 'اسلاید یافت نشد.');
        }

        return $this->ok(['message' => 'وضعیت اسلاید تغییر کرد.']);
    }

    /** `DELETE /api/calls/slides/{slide_id}` */
    public function destroy(Request $request, string $slideId): JsonResponse
    {
        $slideId = LegacyPath::int($slideId, 'slide_id');

        if (! LegacyOrigin::isSameSite($request)) {
            throw LegacyHttpException::crossSite();
        }

        CallActor::requireAdmin($request);

        $row = DB::connection()->selectOne('SELECT filename FROM slides WHERE id = ?', [$slideId]);

        if ($row === null) {
            throw LegacyHttpException::detail(404, 'اسلاید یافت نشد.');
        }

        $filename = (string) $row->filename;

        DB::connection()->delete('DELETE FROM slides WHERE id = ?', [$slideId]);

        // The row is gone whether or not the file can be; an unlink failure is swallowed,
        // as in the Python — a locked file must not make a successful delete look failed.
        $this->deleteFile($filename);

        return $this->ok(['message' => 'اسلاید حذف شد.']);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function directory(): string
    {
        return $this->legacyStaticPath(self::SLIDES_DIR);
    }

    /** `_ensure_slides_dir` — `mkdir(parents=True, exist_ok=True)`. */
    private function ensureDirectory(): void
    {
        $directory = $this->directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw LegacyHttpException::detail(500, 'ذخیره اسلاید ممکن نشد.');
        }
    }

    /** `any(content.startswith(sig) for sig in allowed_ext[ext])`. */
    private function hasMagicBytes(string $contents, string $extension): bool
    {
        foreach (self::MAGIC[$extension] as $signature) {
            if (str_starts_with($contents, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Unlink a slide, refusing to leave the slides directory.
     *
     * `is_relative_to(SLIDES_DIR)` — the stored name is generated by this class, but the
     * column is writable by anything with database access, and a delete that follows a
     * `../` out of the directory is a file-deletion primitive.  The containment check is
     * resolved against the real path so a symlink cannot sidestep it either.
     */
    private function deleteFile(string $filename): void
    {
        $directory = realpath($this->directory());

        if ($directory === false) {
            return;
        }

        try {
            $path = realpath($directory.DIRECTORY_SEPARATOR.$filename);

            if ($path === false || ! str_starts_with($path, $directory.DIRECTORY_SEPARATOR)) {
                return;
            }

            if (is_file($path)) {
                @unlink($path);
            }
        } catch (Throwable) {
            // Deliberately silent: the row is already gone and the response says so.
        }
    }
}
