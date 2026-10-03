<?php

namespace App\Support\Training;

use App\Http\Controllers\PublicPages\TrainingController;
use Illuminate\Http\Request;
use Throwable;

/**
 * The training catalogue and its access rules — the ``_load_training_data``,
 * ``_get_user_role`` and ``_category_accessible`` helpers of `app/main.py`.
 *
 * The catalogue is the JSON file that ships with the Python application
 * (``app/data/training_content.json``).  It is read from there rather than
 * copied, because the file is the source of truth during the side-by-side
 * period: an operator adding a lesson to the running system must be served by
 * both applications without a second copy drifting behind.
 *
 * The role model is the Python's, including its three values:
 *
 * * ``null``  — no session identity; only the ``general`` category is visible;
 * * ``'user'`` — a signed-in non-administrator; ``general`` and ``user``;
 * * ``'admin'`` — ``is_admin`` **or** ``is_master_admin``; every category.
 *
 * The search itself lives in {@see TrainingController};
 * this class is the data and the two predicates the handler needs.
 */
final class TrainingContent
{
    /**
     * The catalogue as ``['categories' => [...], 'lessons' => [...]]``.
     *
     * Decoded to **associative arrays** so a lesson's fields read the same way
     * the Python's ``lesson.get(...)`` did.  Object order is preserved by
     * ``json_decode``, which matters: the Python iterated ``data["lessons"].items()``
     * and the result order is the file's order.
     *
     * A missing or unreadable file degrades to an empty catalogue rather than
     * taking the endpoint down — the Python raised ``FileNotFoundError`` (a 500)
     * only because the file ships with the application and cannot be missing in
     * a working deployment.  The divergence is deliberate and documented.
     *
     * @return array{categories: array<string, array<string, mixed>>, lessons: array<string, array<string, mixed>>}
     */
    public static function load(): array
    {
        $path = base_path('../app/data/training_content.json');

        try {
            $raw = is_file($path) ? file_get_contents($path) : false;
        } catch (Throwable) {
            $raw = false;
        }

        if ($raw === false) {
            return ['categories' => [], 'lessons' => []];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return ['categories' => [], 'lessons' => []];
        }

        $categories = $decoded['categories'] ?? [];
        $lessons = $decoded['lessons'] ?? [];

        return [
            'categories' => is_array($categories) ? $categories : [],
            'lessons' => is_array($lessons) ? $lessons : [],
        ];
    }

    /**
     * ``_get_user_role`` — the caller's role for training access control.
     *
     * Reads the **raw** session value, exactly as the Python did: the handlers
     * that use the role (the page renders) were replaced by the Vue shell, but
     * `/api/training/search` still applies the same predicate, and a padded or
     * letter-folded username is still "somebody logged in" here — the Python
     * compared ``request.session.get("username")`` truthiness only.
     */
    public static function userRole(Request $request): ?string
    {
        $username = $request->session()->get('username');

        if (! is_string($username) || $username === '') {
            return null;
        }

        if ($request->session()->get('is_admin') === true
            || $request->session()->get('is_master_admin') === true) {
            return 'admin';
        }

        return 'user';
    }

    /**
     * ``_category_accessible`` — whether a role may see a category.
     */
    public static function categoryAccessible(string $category, ?string $role): bool
    {
        if ($role === null) {
            return $category === 'general';
        }

        if ($role === 'user') {
            return $category === 'general' || $category === 'user';
        }

        if ($role === 'admin') {
            return true;
        }

        return false;
    }
}
