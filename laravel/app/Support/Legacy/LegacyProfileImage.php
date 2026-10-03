<?php

namespace App\Support\Legacy;

/**
 * Profile-image upload validation — a port of `POST /upload-profile-image` and
 * `POST /delete-profile-image` (`app/main.py:2566` and `:2643`).
 *
 * The Python validated the upload in five steps and each is reproduced, because
 * the order is observable (a file can only fail the checks that run before the
 * one that would have caught it):
 *
 * 1. **Extension allow-list** — `{.jpg, .jpeg, .png, .gif, .webp}`, matched
 *    case-insensitively against the lowercased extension of the
 *    `basename()` of the client filename.
 * 2. **Size cap** — 5 MB, enforced while streaming so a huge upload cannot
 *    exhaust memory first.  A file of exactly 5 MB passes; one byte more is
 *    refused.
 * 3. **Magic bytes** — the signature must match the extension.  This is the
 *    step that makes the extension allow-list meaningful: a `.png` that is
 *    really a JPEG is refused.
 * 4. **Server-generated filename** — the client filename is never used.  The
 *    username is reduced to `[A-Za-z0-9_.-]` (64 chars) and the extension is
 *    appended, so the stored name cannot carry a path.
 * 5. **Path-traversal check** — the final `dirname()` must equal the upload
 *    directory.  Unreachable in practice given step 4, but reproduced.
 *
 * **A real bug, reproduced:** `.jpeg` is in the extension allow-list but can
 * *never* pass the magic-byte check.  The detector maps the JPEG signature to
 * `'.jpg'` unconditionally, and the comparison is `detected_ext != file_ext` —
 * so a valid `.jpeg` is refused as a signature mismatch.  The effective
 * allow-list is `{.jpg, .png, .gif, .webp}` with matching signatures.
 *
 * The delete handler's traversal protection is also ported: the stored filename
 * is reduced to its `basename()`, resolved, and removed only when it resolves to
 * a path *inside* the real upload directory.
 */
final class LegacyProfileImage
{
    /** The extension allow-list, lowercased, each with a leading dot. */
    public const ALLOWED_EXTENSIONS = ['.jpg', '.jpeg', '.png', '.gif', '.webp'];

    /** 5 MB. */
    public const MAX_FILE_SIZE = 5242880;

    /** The username is truncated to this many characters. */
    private const USERNAME_MAX_LENGTH = 64;

    /**
     * The lowercased extension of a filename, including the leading dot.
     *
     * `os.path.splitext(os.path.basename(name))[1].lower()` — the `basename`
     * first, so a client sending `../../etc/passwd` is reduced to `passwd`
     * before the extension is taken.
     */
    public static function extensionOf(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $dot = strrpos($base, '.');

        return $dot === false ? '' : strtolower(substr($base, $dot));
    }

    /**
     * Whether the extension is on the allow-list.
     */
    public static function isAllowedExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::ALLOWED_EXTENSIONS, true);
    }

    /**
     * The extension implied by the file's magic bytes, or `null`.
     *
     * A port of the detector in the Python handler.  Note the JPEG branch
     * returns `'.jpg'` for both `.jpg` and `.jpeg` — see the class docblock.
     */
    public static function detectExtension(string $contents): ?string
    {
        if (substr($contents, 0, 3) === "\xFF\xD8\xFF") {
            return '.jpg';
        }

        if (substr($contents, 0, 4) === "\x89PNG") {
            return '.png';
        }

        if (substr($contents, 0, 4) === 'GIF8') {
            return '.gif';
        }

        if (substr($contents, 0, 4) === 'RIFF' && strlen($contents) >= 12 && substr($contents, 8, 4) === 'WEBP') {
            return '.webp';
        }

        return null;
    }

    /**
     * The username reduced to a conservative charset, or `"user"`.
     *
     * `re.sub(r"[^A-Za-z0-9_.-]", "_", str(username))[:64] or "user"` — legacy
     * rows may contain characters the current validators reject, so the name is
     * folded before it is used as a filename component.
     */
    public static function sanitizeUsername(string $username): string
    {
        $safe = (string) preg_replace('/[^A-Za-z0-9_.-]/', '_', $username);
        $safe = mb_substr($safe, 0, self::USERNAME_MAX_LENGTH);

        return $safe === '' ? 'user' : $safe;
    }

    /**
     * The server-generated filename: `{safe_username}{extension}`.
     */
    public static function buildFilename(string $username, string $extension): string
    {
        return self::sanitizeUsername($username).$extension;
    }

    /**
     * Whether a path resolves to a location inside the upload directory.
     *
     * The delete handler's traversal protection: normalise both sides and
     * require the file's path to start with the upload directory's path plus a
     * separator — so `uploads` itself does not match `uploads-secret`, and a
     * path that escapes (`../`) does not match at all.
     *
     * The normalisation is Python's `os.path.realpath(..., strict=False)`: it
     * resolves `.` and `..` and does **not** require the file to exist, which is
     * what lets the check reject a traversal name before anything is touched.
     */
    public static function isWithinUploadDir(string $path, string $uploadDir): bool
    {
        $realPath = self::normalizePath($path);
        $realDir = self::normalizePath($uploadDir);

        return str_starts_with($realPath, $realDir.'/');
    }

    /**
     * A normalised absolute-ish path: `.` and `..` resolved, separators unified.
     *
     * This is the half of `os.path.realpath` that matters for the traversal check
     * — symlink resolution is a filesystem detail the upload flow never reaches,
     * because the name is sanitised before it is joined.
     */
    private static function normalizePath(string $path): string
    {
        $parts = explode('/', str_replace('\\', '/', $path));
        $resolved = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }

            if ($part === '..') {
                array_pop($resolved);

                continue;
            }

            $resolved[] = $part;
        }

        return implode('/', $resolved);
    }

    /**
     * The absolute upload directory.
     *
     * The Python stored into `app/static/uploads` (web-accessible through the
     * static mount).  The Laravel equivalent is `public/uploads`.
     */
    public static function uploadDir(): string
    {
        return public_path('uploads');
    }
}
