<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyProfileImage;
use App\Support\Legacy\LegacyValidationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The profile-image writes — `POST /upload-profile-image` and
 * `POST /delete-profile-image` from `app/main.py`.
 *
 * Both answer with a **303 redirect**, never JSON: an unauthenticated request
 * goes to `/login`, and every refusal — a disallowed extension, an oversized
 * file, a magic-byte mismatch, a traversal attempt — goes to `/user_panel`,
 * exactly as the Python's `RedirectResponse` did.  The front-end follows the
 * redirect and re-reads the profile, so the status code and the `Location`
 * header are the whole contract.
 *
 * The upload validation is ported step-for-step and in order, because the
 * order is observable; see {@see LegacyProfileImage}.  The two security
 * properties that matter:
 *
 * * **The client filename is never used.**  The stored name is
 *   `{sanitized_username}{extension}`, so it cannot carry a path, and the
 *   final `dirname()` is verified to be the upload directory.
 * * **Serving never accepts a client-supplied path.**  The delete handler
 *   reduces the *stored* filename to its `basename()`, resolves it, and
 *   removes it only when it resolves inside the real upload directory — the
 *   stored value is the only input, and it is validated before use.
 */
final class ProfileImageController extends Controller
{
    /**
     * `POST /upload-profile-image` — store the signed-in user's profile image.
     *
     * @throws LegacyValidationException 422 when the `file` field is absent.
     */
    public function upload(Request $request): RedirectResponse
    {
        $username = $request->session()->get('username');

        if (! $username) {
            return redirect('/login', 303);
        }

        // `file: UploadFile = File(...)` — a required file field, rejected by
        // FastAPI before the handler runs.
        if (! $request->hasFile('file')) {
            throw new LegacyValidationException([[
                'type' => 'missing',
                'loc' => ['body', 'file'],
                'msg' => 'Field required',
                'input' => null,
            ]]);
        }

        $file = $request->file('file');

        $extension = LegacyProfileImage::extensionOf($file->getClientOriginalName() ?? '');

        if (! LegacyProfileImage::isAllowedExtension($extension)) {
            return redirect('/user_panel', 303);
        }

        if ($file->getSize() > LegacyProfileImage::MAX_FILE_SIZE) {
            return redirect('/user_panel', 303);
        }

        $contents = (string) $file->get();
        $detected = LegacyProfileImage::detectExtension($contents);

        if ($detected === null || $detected !== $extension) {
            return redirect('/user_panel', 303);
        }

        $filename = LegacyProfileImage::buildFilename((string) $username, $extension);
        $uploadDir = LegacyProfileImage::uploadDir();

        if (! is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filePath = $uploadDir.DIRECTORY_SEPARATOR.$filename;

        // The Python's traversal guard: the resolved directory must be the upload
        // directory itself.  Unreachable given the sanitised name, reproduced.
        if (dirname($filePath) !== $uploadDir) {
            return redirect('/user_panel', 303);
        }

        file_put_contents($filePath, $contents);

        // No try/catch in the Python: a database failure is a 500, and the file
        // has already been written by that point.
        DB::connection()->table('user_table')
            ->where('username', $username)
            ->update(['profile_image' => $filename]);

        return redirect('/user_panel', 303);
    }

    /**
     * `POST /delete-profile-image` — remove the signed-in user's profile image.
     *
     * The stored filename is the only input to the filesystem, and it is
     * reduced to its basename and verified to stay inside the upload directory
     * before anything is deleted.
     */
    public function delete(Request $request): RedirectResponse
    {
        $username = $request->session()->get('username');

        if (! $username) {
            return redirect('/login', 303);
        }

        $stored = DB::connection()->table('user_table')
            ->where('username', $username)
            ->value('profile_image');

        if ($stored) {
            $safeName = basename(str_replace('\\', '/', (string) $stored));
            $filePath = LegacyProfileImage::uploadDir().DIRECTORY_SEPARATOR.$safeName;

            if (LegacyProfileImage::isWithinUploadDir($filePath, LegacyProfileImage::uploadDir()) && file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        DB::connection()->table('user_table')
            ->where('username', $username)
            ->update(['profile_image' => null]);

        return redirect('/user_panel', 303);
    }
}
