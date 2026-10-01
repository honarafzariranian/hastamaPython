<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTicketActor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The two "who can I pick" endpoints behind the ticket and request forms.
 *
 * Both are ports of handlers in `app/main.py` (`GET /get_users` and
 * `GET /get_receivers`), both are guarded by `_ticket_actor` — now the
 * `ticket.actor` middleware — and both exclude the caller from their own list, so
 * nobody can address a ticket or a request to themselves.
 *
 * They are separate endpoints rather than one with a flag because their response
 * shapes genuinely differ: `/get_users` wraps a `{value, label}` list in an
 * envelope, `/get_receivers` returns a **bare JSON array**.  That inconsistency is
 * real, is depended on by two different pieces of front-end code, and is preserved.
 */
final class PickerController extends Controller
{
    /**
     * `GET /get_users` — everyone except the caller, for the recipient picker.
     *
     * The `{value, label}` shape is what a `<select>`-style component consumes:
     * `value` is the username (trimmed, because the column is space-padded) and
     * `label` is the display name.
     *
     * A username that trims to nothing is dropped (`if user[0]`), which keeps a
     * row with a blank username out of the picker rather than rendering an empty
     * option.
     */
    public function users(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        try {
            $rows = DB::connection()->select(
                'SELECT LTRIM(RTRIM(username)) AS username, name
                 FROM user_table
                 WHERE LTRIM(RTRIM(username)) <> ?
                 ORDER BY LTRIM(RTRIM(username))',
                [$actor]
            );

            $users = [];

            foreach ($rows as $row) {
                $username = trim((string) $row->username);

                if ($username === '') {
                    continue;
                }

                $users[] = ['value' => $username, 'label' => $row->name];
            }

            return response()->json(['success' => true, 'users' => $users]);
        } catch (Throwable $exception) {
            Log::error('get_users failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'خطا در دریافت کاربران.'], 500);
        }
    }

    /**
     * `GET /get_receivers` — the accounts that may receive a request.
     *
     * Restricted to `MASTER_ADMIN_USERNAMES`, compared case-insensitively.
     * The point of the restriction is that a request has to be approved by someone
     * who can actually open the control plane, so offering every other user as a
     * recipient would produce requests nobody can act on.
     *
     * The response is a **bare array of strings**, not an envelope.  The Python
     * endpoint returned `JSONResponse(content=receiver_list)`; the consumer reads it
     * with `Array.isArray()`.
     */
    public function receivers(Request $request): JsonResponse
    {
        $actor = $this->actor($request);

        try {
            $rows = DB::connection()->select(
                'SELECT LTRIM(RTRIM(username)) AS username
                 FROM user_table
                 WHERE LTRIM(RTRIM(username)) <> ?
                 ORDER BY LTRIM(RTRIM(username))',
                [$actor]
            );

            // `str.casefold()` in Python, which is stronger than `lower()` (it folds
            // `ß` to `ss`).  For the ASCII usernames this configuration uses the two
            // agree; `mb_strtolower` is the closest PHP equivalent and is what the
            // rest of the migration uses for the same comparison.
            $masters = $this->masterAdminUsernames();

            $receivers = [];

            foreach ($rows as $row) {
                $username = trim((string) $row->username);

                if ($username === '') {
                    continue;
                }

                if (in_array(mb_strtolower($username), $masters, true)) {
                    $receivers[] = $username;
                }
            }

            return response()->json($receivers);
        } catch (Throwable $exception) {
            Log::error('get_receivers failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'خطا در دریافت کاربران.'], 500);
        }
    }

    /**
     * The actor resolved by `EnsureTicketActor`.
     *
     * The middleware rejects before the controller runs, so this cannot be empty;
     * the fallback exists only so a misconfigured route (one missing the middleware)
     * degrades to "nobody is excluded" rather than a type error.
     */
    private function actor(Request $request): string
    {
        return trim((string) $request->attributes->get(EnsureTicketActor::ACTOR_ATTRIBUTE, ''));
    }

    /**
     * `MASTER_ADMIN_USERNAMES`, parsed and lower-cased once.
     *
     * Read from the same config key the login flow uses, so the recipient list and
     * the control-plane guard can never disagree about who an administrator is.
     *
     * @return array<int, string>
     */
    private function masterAdminUsernames(): array
    {
        return array_values(array_map(
            static fn ($name): string => mb_strtolower(trim((string) $name)),
            (array) config('hastama.master_admin_usernames', []),
        ));
    }
}
