<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `GET /master-admin/api/search` — the control centre's global search box.
 *
 * Four independent `TOP 10` queries are concatenated into one flat result list, and
 * each result carries its own `link` so the UI can navigate without knowing which
 * query produced it.  The `type` field is the discriminator (`user`, `audit`,
 * `security`, `error`).
 *
 * Two behaviours are inherited verbatim because the UI depends on them:
 *
 * * **an empty or whitespace-only query is a success with no results**, not a 400.
 *   The search box fires on every keystroke, including the one that clears it.
 * * the result body is `{"success": true, "results": [...]}` — the key is
 *   `results`, not `data`.  This is the only list endpoint in the module that does
 *   not paginate, and the only one whose payload key differs.
 */
final class SearchController extends MasterAdminController
{
    /** `GET /master-admin/api/search?q=…` */
    public function search(Request $request): JsonResponse
    {
        // `q: str = Query("")` — unconstrained and never required, but declared so a
        // repeated parameter (`?q[]=a`) is rejected as a non-string, as FastAPI did.
        $params = LegacyQuery::validate($request, [
            'q' => LegacyQuery::string(default: ''),
        ]);

        $query = $params['q'];

        // `if not q.strip()` — the *stripped* value decides emptiness, but the
        // unstripped `q` is what goes into the audit-log link below.  Both facts are
        // the Python behaviour and both are visible in the UI.
        if (trim($query) === '') {
            return $this->ok(['success' => true, 'results' => []]);
        }

        $needle = '%'.trim($query).'%';
        $results = [];

        try {
            $connection = DB::connection();

            foreach (LegacySerializer::rows('user_table', $connection->select(
                'SELECT TOP 10 username, name, last_name, department, role
                 FROM user_table WHERE username LIKE ? OR name LIKE ? OR last_name LIKE ?',
                [$needle, $needle, $needle]
            )) as $row) {
                $results[] = [
                    'type' => 'user',
                    'title' => "{$row['username']} — {$row['name']} {$row['last_name']}",
                    'subtitle' => "{$row['department']} | {$row['role']}",
                    // The username is trimmed here but not in `title`: the stored
                    // value is space-padded and a padded URL segment would 404.
                    'link' => '/master-admin/users/'.trim((string) $row['username']),
                ];
            }

            foreach (LegacySerializer::rows('audit_logs', $connection->select(
                'SELECT TOP 10 event_id, action, username, module FROM audit_logs
                 WHERE event_id LIKE ? OR username LIKE ? ORDER BY created_at DESC',
                [$needle, $needle]
            )) as $row) {
                $results[] = [
                    'type' => 'audit',
                    'title' => "{$row['event_id']} — {$row['action']}",
                    'subtitle' => "{$row['username']} | {$row['module']}",
                    'link' => '/master-admin/audit-logs?search='.$query,
                ];
            }

            foreach ($connection->select(
                'SELECT TOP 10 event_id, event_type, description FROM security_events
                 WHERE event_id LIKE ? OR description LIKE ? ORDER BY created_at DESC',
                [$needle, $needle]
            ) as $row) {
                $results[] = [
                    'type' => 'security',
                    'title' => "{$row->event_id} — {$row->event_type}",
                    'subtitle' => $this->excerpt($row->description),
                    'link' => '/master-admin/security',
                ];
            }

            foreach ($connection->select(
                'SELECT TOP 10 error_id, message, severity FROM system_errors
                 WHERE error_id LIKE ? OR message LIKE ? ORDER BY first_seen DESC',
                [$needle, $needle]
            ) as $row) {
                $results[] = [
                    'type' => 'error',
                    'title' => "{$row->error_id} — {$row->severity}",
                    'subtitle' => $this->excerpt($row->message),
                    'link' => '/master-admin/errors',
                ];
            }

            return $this->ok(['success' => true, 'results' => $results]);
        } catch (Throwable $exception) {
            return $this->internalError($exception, 'search');
        }
    }

    /**
     * The first 100 characters of a description.
     *
     * `r[2][:100]` in Python — a character slice, so `mb_substr` rather than a byte
     * slice, which would cut a Persian character in half and produce invalid UTF-8.
     *
     * A `null` description raises `TypeError` in Python and takes the whole search
     * down with a 500; here it renders as an empty string.  Fixing a crash is not a
     * behaviour change the client can observe as "different", so it is corrected
     * rather than reproduced.
     */
    private function excerpt(?string $value): string
    {
        return mb_substr((string) $value, 0, 100);
    }
}
