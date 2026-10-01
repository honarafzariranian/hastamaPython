<?php

namespace App\Support\Automation;

use App\Support\Legacy\LegacyHttpException;

/**
 * `store_private_attachment()` from `app/services/ticketing.py` — validate an upload and
 * write it **outside** `/static`.
 *
 * The automation module and the ticketing module share this function in Python (the
 * import at the top of `routes/automation.py` is `from app.services.ticketing import
 * store_private_attachment`), and the checks are in a fixed order because the *message*
 * is what a caller sees: suffix, then size, then declared content type, then the write.
 * A `.exe` named `report.pdf` with the wrong `Content-Type` answers the suffix refusal;
 * a `.pdf` too large answers the size refusal.  Getting them the other way round would
 * still refuse the request and would still answer 422 — with the other message.
 *
 * ### The suffix rule is `pathlib`, not `pathinfo()`
 *
 * ```python
 * safe_name = Path(str(original_name or "").replace("\\", "/")).name
 * suffix = Path(safe_name).suffix.lower()
 * ```
 *
 * `Path.suffix` is the part from the **last** dot when that dot is neither the first
 * character nor the last one, so `.pdf` (no suffix), `a.` (no suffix), `..pdf` (`.pdf`)
 * and `a.tar.gz` (`.gz`) all behave differently from `pathinfo()`, which answers `.pdf`,
 * `''`, `.pdf` and `.gz` — two of the four wrong.  The cases are not hypothetical: a
 * file named `.pdf` is exactly what a "give me a PDF" refusal has to catch.
 *
 * ### There is no magic-byte check here
 *
 * The Python reads the multipart `Content-Type` and the filename and nothing else; the
 * signature check lives in `app/main.py` and `app/api/routes/call_system.py`, on other
 * routes.  Reproducing a sniff here would refuse uploads the running server accepts.
 *
 * ### Failures
 *
 * A refused upload raises `ValueError`, which the route renders as **422** with the
 * Persian message.  A *filesystem* failure (`mkdir`, `write_bytes`) is not a `ValueError`
 * in Python and escapes the route's `except ValueError` — an unhandled `OSError`, which
 * answers a plain-text **500**.  PHP's warnings are escalated to `ErrorException` by the
 * framework, so a failed write throws too, and the route deliberately lets anything that
 * is not a `LegacyHttpException` propagate for the same reason.
 *
 * The directory is `TICKETING_PRIVATE_DIR`, default `app/private_uploads/tickets`
 * relative to the legacy working directory — read through `config('hastama.
 * ticketing_private_dir')` so it survives `config:cache`, and overridable in a test by
 * binding an instance built on a temporary directory.
 */
final class PrivateAttachmentStore
{
    /**
     * The allow-list, keyed by suffix: `suffix -> the content type that suffix must be
     * served with`.
     *
     * Lower-cased on purpose — the lookup is by `suffix` and `.PDF` must be `.pdf`.
     */
    public const ALLOWED_TYPES = [
        '.pdf' => 'application/pdf',
        '.png' => 'image/png',
        '.jpg' => 'image/jpeg',
        '.jpeg' => 'image/jpeg',
        '.webp' => 'image/webp',
        '.txt' => 'text/plain',
        '.doc' => 'application/msword',
        '.docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        '.xls' => 'application/vnd.ms-excel',
        '.xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];

    /** `10 * 1024 * 1024` — the bound the route reads up to as well. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /** One past the bound, so a larger file is read *just* far enough to be refused. */
    public const READ_LIMIT = self::MAX_BYTES + 1;

    /** The content type Python allowed in place of the declared one. */
    private const OCTET_STREAM = 'application/octet-stream';

    /**
     * The resolved upload directory.
     *
     * `realpath()` when the directory already exists, so a containment comparison is
     * against a canonical path; the configured string when it does not, in which case no
     * file can exist under it either and every download answers 404.
     */
    public function root(): string
    {
        $configured = (string) config('hastama.ticketing_private_dir');

        $resolved = realpath($configured);

        if ($resolved !== false) {
            return $resolved;
        }

        return rtrim($configured, '/\\');
    }

    /**
     * Validate and store one attachment.
     *
     * @param  string  $originalName  The multipart filename, after the route's
     *                                `file.filename or 'attachment'`.
     * @param  string  $contentType  The part's `Content-Type`, `''` when it had none.
     * @param  string  $payload  The bytes read from the upload — at most
     *                           `READ_LIMIT`, as `await file.read(10*1024*1024+1)` was.
     * @return array{original_name: string, storage_name: string, content_type: string,
     *               size_bytes: int, path: string}
     *
     * @throws LegacyHttpException 422 with one of the three messages.
     */
    public function store(string $originalName, string $contentType, string $payload): array
    {
        $safeName = self::basename($originalName);
        $suffix = self::suffix($safeName);

        if (! isset(self::ALLOWED_TYPES[$suffix])) {
            throw LegacyHttpException::detail(422, 'نوع فایل مجاز نیست.');
        }

        $size = strlen($payload);

        if ($size === 0 || $size > self::MAX_BYTES) {
            throw LegacyHttpException::detail(422, 'حجم فایل باید بین ۱ بایت و ۱۰ مگابایت باشد.');
        }

        // `content_type.split(";")[0].lower()` — deliberately without a trim, so a header
        // of `" application/pdf"` is refused as the running server refuses it.
        if ($contentType !== '') {
            $declared = strtolower(explode(';', $contentType)[0]);

            if ($declared !== self::ALLOWED_TYPES[$suffix] && $declared !== self::OCTET_STREAM) {
                throw LegacyHttpException::detail(422, 'نوع محتوای فایل معتبر نیست.');
            }
        }

        $storageName = bin2hex(random_bytes(16)).$suffix;
        $root = $this->root();

        // `root.mkdir(parents=True, exist_ok=True)` — not suppressed, because a
        // directory this process cannot create is a fault the Python reports as 500.
        if (! is_dir($root) && ! mkdir($root, 0777, true) && ! is_dir($root)) {
            throw new \ErrorException('Unable to create the attachment directory.');
        }

        $path = $root.DIRECTORY_SEPARATOR.$storageName;

        // `if root not in path.parents: raise ValueError("نام فایل ناامن است.")` — a name
        // assembled from `random_bytes()` can never leave the root, so the refusal is
        // unreachable; the check is kept because the Python has it.
        if (str_contains($storageName, '/') || str_contains($storageName, '\\')) {
            throw LegacyHttpException::detail(422, 'نام فایل ناامن است.');
        }

        if (file_put_contents($path, $payload) === false) {
            throw new \ErrorException('Unable to write the attachment.');
        }

        return [
            'original_name' => mb_substr($safeName, 0, 255) ?: 'attachment'.$suffix,
            'storage_name' => $storageName,
            'content_type' => self::ALLOWED_TYPES[$suffix],
            'size_bytes' => $size,
            'path' => $path,
        ];
    }

    /**
     * The download path for a stored file, or `null` when it is not a file under the root.
     *
     * `if root not in path.parents or not path.is_file(): 404` — the containment test is
     * a canonical-prefix test because the file has to exist for `realpath()` to answer,
     * and a file *at* the root rather than below it is not a valid storage name.
     */
    public function resolve(string $storageName): ?string
    {
        $root = $this->root();
        $path = realpath($root.DIRECTORY_SEPARATOR.$storageName);

        if ($path === false || ! is_file($path)) {
            return null;
        }

        if (! str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    /**
     * `os.remove(path)` for a stored file, skipping one that is already gone.
     *
     * `try: os.remove(path) except FileNotFoundError: pass` — and *only* that: a file
     * this process may not delete raises `OSError` in the Python, which escapes
     * `AutomationService.delete()` and is answered by `_error()` as the module's 500.
     * The failure is therefore not swallowed here; PHP's warning becomes an
     * `ErrorException`, which the route maps the same way.
     */
    public function delete(string $storageName): void
    {
        $path = $this->resolve($storageName);

        if ($path === null) {
            return;
        }

        unlink($path);
    }

    /**
     * `Path(storage).unlink(missing_ok=True)`, swallowing `OSError`.
     *
     * The route calls this after a failed insert: the row never existed, so the bytes
     * must not be left behind — but a file it cannot remove must not turn a handled
     * refusal into a second failure.
     */
    public function forget(?string $path): void
    {
        if ($path === null || $path === '' || ! is_file($path)) {
            return;
        }

        try {
            @unlink($path);
        } catch (\Throwable) {
            // `except OSError: pass`
        }
    }

    /**
     * `Path(str(name).replace("\\", "/")).name`.
     *
     * Trailing separators are dropped first, which is what `pathlib` does: `a/` names
     * `a`, `/` names nothing.
     */
    private static function basename(string $name): string
    {
        $path = rtrim(str_replace('\\', '/', $name), '/');

        $slash = strrpos($path, '/');

        return $slash === false ? $path : substr($path, $slash + 1);
    }

    /**
     * `Path(name).suffix.lower()` — the part from the last dot, when that dot is neither
     * the first nor the last character of the name.
     */
    private static function suffix(string $name): string
    {
        $dot = strrpos($name, '.');

        if ($dot === false || $dot === 0 || $dot === strlen($name) - 1) {
            return '';
        }

        return strtolower(substr($name, $dot));
    }
}
