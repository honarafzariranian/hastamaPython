<?php

namespace App\Http\Controllers\PublicPages;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyQuery;
use App\Support\Training\TrainingContent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The training pages and their search — ported from the training block of
 * `app/main.py`.
 *
 * The three page routes are SPA shells now (see {@see shell()}); the access
 * control the Python rendered into the page moved with them, and the one
 * piece of it that is a machine contract — `/api/training/search` — is
 * ported here in full, because the search box calls it with `fetch()` and
 * reads the JSON.
 */
final class TrainingController extends Controller
{
    /**
     * `GET /training` — the hub shell.
     */
    public function hub(): Response
    {
        return $this->shell();
    }

    /**
     * `GET /training/{category}` — one shell for every category.
     *
     * The Python rendered the hub again with an `error` context for a missing
     * category or one the caller's role cannot see.  That is client behaviour
     * now: the shell renders the hub and the category rules are enforced by
     * `/api/training/search`, which applies the same `_category_accessible`
     * predicate to every lesson it returns.
     */
    public function category(): Response
    {
        return $this->shell();
    }

    /**
     * `GET /training/lesson/{lesson_id}` — one shell for every lesson.
     */
    public function lesson(): Response
    {
        return $this->shell();
    }

    /**
     * `GET /api/training/search` — the search the hub's search box calls.
     *
     * `q` is `Query("")`: optional, no length bounds, so the only rejection
     * FastAPI could produce for it is a non-string — which a query parameter
     * cannot be.  An absent or empty `q` answers the empty result list, not
     * an error.
     *
     * The match is a substring test over `title + " " + description + " " +
     * category`, lowercased — the category slug is part of the searchable
     * text, which is why `?q=admin` finds every admin lesson.
     */
    public function search(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'q' => LegacyQuery::string(),
        ]);

        $data = TrainingContent::load();
        $role = TrainingContent::userRole($request);

        $query = mb_strtolower(trim($params['q']));

        if ($query === '') {
            return response()->json(['success' => true, 'results' => []]);
        }

        $results = [];

        foreach ($data['lessons'] as $id => $lesson) {
            $lessonCategory = (string) ($lesson['category'] ?? '');

            if (! TrainingContent::categoryAccessible($lessonCategory, $role)) {
                continue;
            }

            $searchable = mb_strtolower(
                (string) ($lesson['title'] ?? '').' '
                .(string) ($lesson['description'] ?? '').' '
                .$lessonCategory
            );

            if (! str_contains($searchable, $query)) {
                continue;
            }

            $categoryTitle = (string) ($data['categories'][$lessonCategory]['title'] ?? '');

            $results[] = [
                'id' => $id,
                'title' => (string) ($lesson['title'] ?? ''),
                'description' => (string) ($lesson['description'] ?? ''),
                // The category's display title, falling back to its slug.
                'category' => $categoryTitle !== '' ? $categoryTitle : $lessonCategory,
                'role' => (string) ($lesson['role'] ?? 'general'),
                'icon' => (string) ($lesson['icon'] ?? '📖'),
            ];
        }

        return response()->json(['success' => true, 'results' => $results]);
    }

    /**
     * The one mount document — see `routes/web.php` for why every shell is the
     * same view.
     */
    private function shell(): Response
    {
        return response()->view('app');
    }
}
