<?php

namespace App\Http\Controllers\Admin;

use App\Services\Auth\SessionRegistry;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyInput;
use App\Support\Legacy\LegacyPagination;
use App\Support\Legacy\LegacyPassword;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use App\Support\Legacy\LegacyValidationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The administrator user writes — `POST /add_user`, `POST /update_user` and
 * `GET /fetch_user_data` from `app/main.py`.
 *
 * ### Validation order
 *
 * FastAPI validates a handler's declared parameters before the handler runs,
 * which puts the framework's 422 ahead of the handler's own `_require_admin` on
 * the two routes that declare parameters:
 *
 * * `POST /add_user` declares fifteen `Form(...)` fields, so a missing **or
 *   empty** one is a 422 before the guard — and FastAPI 0.141 treats an empty
 *   form value as *not provided*, so `name=` is a `missing` exactly like an
 *   absent `name`;
 * * `GET /fetch_user_data` declares `username: str = Query(...)`, so a missing
 *   `username` is a 422 before the guard.
 *
 * `POST /update_user` reads its body with `await request.json()` inside the
 * handler, so there is no pre-handler validation and it carries the `admin`
 * middleware.
 *
 * ### Two shapes worth naming
 *
 * * `add_user` **returns `null` on success** — the `RedirectResponse` that
 *   used to end the handler is commented out (`app/main.py:3292`), so the
 *   handler falls off the end and FastAPI serialises `None` as `null` with
 *   status 200.  Its failure is a 200 carrying `{"error": "خطا در ذخیره کاربر"}`.
 * * `fetch_user_data` compares the username **without** trimming it
 *   (`WHERE username = ?`), unlike nearly every other lookup in the
 *   application — so `?username= admin` matches nothing and answers 404.
 */
final class UserAdminController extends AdminPanelController
{
    /** The fifteen required `Form(...)` fields, in declaration order. */
    private const REQUIRED_FORM_FIELDS = [
        'name', 'last_name', 'department', 'work_hours', 'substitute',
        'username', 'password', 'role', 'hozoorNum',
        'shanbeh', 'yekshanbeh', 'doshanbeh', 'seshanbeh', 'chrshanbeh', 'panjshanbeh',
    ];

    /** `EMPLOYMENT_STATUS_VALUES` in `app/main.py`. */
    private const EMPLOYMENT_STATUSES = ['official', 'unofficial'];

    /**
     * `GET /admin/coworkers/users` — the directory rendered by admin.html.
     * Password columns are intentionally excluded from the projection.
     */
    public function index(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 100, ge: 1, le: 200),
            'search' => LegacyQuery::nullableString(),
        ]);

        $authError = $this->requireAdmin($request);

        if ($authError !== null) {
            return $authError;
        }

        try {
            $columns = $this->userTableColumns();
            $employmentStatus = in_array('employment_status', $columns, true)
                ? 'employment_status'
                : "'official' AS employment_status";
            $activeStatus = in_array('is_active', $columns, true)
                ? 'is_active'
                : "'active' AS is_active";
            $where = '';
            $bindings = [];
            $search = trim((string) ($params['search'] ?? ''));

            if ($search !== '') {
                $where = ' WHERE (username LIKE ? OR name LIKE ? OR last_name LIKE ? OR department LIKE ?)';
                $needle = '%'.$search.'%';
                $bindings = [$needle, $needle, $needle, $needle];
            }

            $connection = DB::connection();
            $total = (int) $connection->selectOne(
                "SELECT COUNT(*) AS total FROM user_table{$where}",
                $bindings
            )->total;
            $page = $params['page'];
            $perPage = $params['per_page'];
            $rows = $connection->select(
                'SELECT username, department, work_hours, substitute, name, last_name, '.$employmentStatus.', '.$activeStatus."
                 FROM user_table{$where}
                 ORDER BY id OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
                array_merge($bindings, [LegacyPagination::offset($page, $perPage), $perPage])
            );

            return response()->json(LegacyPagination::envelope(
                LegacySerializer::rows('user_table', $rows),
                $total,
                $page,
                $perPage,
            ));
        } catch (Throwable) {
            return response()->json(['success' => false, 'error' => 'خطای داخلی سرور'], 500);
        }
    }

    /** `POST /delete_user` — remove an account from the coworkers directory. */
    public function delete(Request $request): JsonResponse
    {
        $authError = $this->requireAdmin($request);

        if ($authError !== null) {
            return $authError;
        }

        $username = trim((string) $request->input('username', ''));

        if ($username === '') {
            return response()->json(['success' => false, 'error' => 'نام کاربری الزامی است.'], 400);
        }

        try {
            DB::connection()->delete('DELETE FROM user_table WHERE username = ?', [$username]);

            return response()->json(['success' => true]);
        } catch (Throwable) {
            return response()->json(['success' => false, 'error' => 'خطا در حذف کاربر.'], 500);
        }
    }

    /**
     * `POST /add_user` — create an account.
     *
     * The free-text fields are validated before they are stored (they are
     * rendered by the admin UI), the username goes through
     * {@see LegacyInput::validateUsername}, and the password is stored only as
     * a bcrypt hash — never in plaintext, in either column.
     *
     * The new id is drawn from a CSPRNG and checked against the table, the same
     * allocation `_next_available_user_id()` performed.
     */
    public function add(Request $request)
    {
        $input = $request->request->all();

        // FastAPI's `Form(...)`: a missing **or empty** required field is a
        // 422 `missing`, reported for every such field at once.
        $values = $this->validateForm($input);

        $authError = $this->requireAdmin($request);

        if ($authError !== null) {
            return $authError;
        }

        try {
            $name = $this->cleanDisplayField($values['name'], 100, 'نام');
            $lastName = $this->cleanDisplayField($values['last_name'], 100, 'نام خانوادگی');
            $department = $this->cleanDisplayField($values['department'], 100, 'بخش');
            $substitute = $this->cleanDisplayField($values['substitute'], 100, 'جانشین');
            $workHours = $this->cleanDisplayField($values['work_hours'], 50, 'ساعت کاری');
            $hozoorNum = $this->cleanDisplayField($values['hozoorNum'], 50, 'شماره حضور');
        } catch (LegacyHttpException $exception) {
            return response()->json(['success' => false, 'error' => $exception->getMessage()], 400);
        }

        $username = $values['username'];
        $usernameCheck = LegacyInput::validateUsername($username);

        if ($usernameCheck['valid'] !== true) {
            return response()->json(['success' => false, 'error' => $usernameCheck['error']], 400);
        }

        $role = $values['role'];

        if ($role !== 'user' && $role !== 'admin') {
            return response()->json(['success' => false, 'error' => 'نقش معتبر نیست.'], 400);
        }

        $password = $values['password'];

        if ($password === '') {
            return response()->json(['success' => false, 'error' => 'رمز عبور الزامی است.'], 400);
        }

        $userId = $this->nextAvailableUserId();

        if ($userId === null) {
            return response()->json(['success' => false, 'error' => 'خطا در تخصیص شناسه کاربر.'], 500);
        }

        $hash = LegacyPassword::hash($password);
        $columns = $this->userTableColumns();
        $connection = DB::connection();

        // `insert_user_with_optional_hash()` — the plaintext `password` column
        // is written as `''`; the bcrypt hash is the only credential stored.
        $row = [
            'id' => $userId,
            'username' => $username,
            'name' => $name,
            'last_name' => $lastName,
            'department' => $department,
            'substitute' => $substitute,
            'work_hours' => $workHours,
            'role' => $role,
            'hozoor_num' => $hozoorNum,
            'shanbeh' => $values['shanbeh'],
            'yekshanbeh' => $values['yekshanbeh'],
            'doshanbeh' => $values['doshanbeh'],
            'seshanbeh' => $values['seshanbeh'],
            'chrshanbeh' => $values['chrshanbeh'],
            'panjshanbeh' => $values['panjshanbeh'],
            'is_active' => 'active',
        ];

        if (in_array('password_hash', $columns, true)) {
            $row['password'] = '';
            $row['password_hash'] = $hash;
        } else {
            // Legacy install without a hash column: the bcrypt hash as text in
            // the `password` column (`verify_password` understands that form).
            $row['password'] = $hash;
        }

        $connection->table('user_table')->insert($row);

        $employmentStatus = mb_strtolower(trim($values['employment_status']));

        if (! in_array($employmentStatus, self::EMPLOYMENT_STATUSES, true)) {
            $employmentStatus = 'official';
        }

        $connection->update(
            'UPDATE user_table SET employment_status = ? WHERE LTRIM(RTRIM(username)) = LTRIM(RTRIM(?))',
            [$employmentStatus, $username]
        );

        // The handler's success path falls through to `None` (the
        // `RedirectResponse` is commented out), which FastAPI serialises as
        // `null` with status 200.  `response()->json(null)` cannot be used
        // for this: Symfony's `JsonResponse` coerces a `null` payload to an
        // empty `ArrayObject` and answers `{}`, so the literal string is
        // written instead.
        return response('null', 200, ['Content-Type' => 'application/json']);
    }

    /**
     * `POST /update_user` — rewrite an account.
     *
     * An empty `password` keeps the current credential; a non-empty one
     * replaces it with a bcrypt hash.  A credential, privilege or account-status
     * change revokes the user's existing sessions, because a signed cookie
     * cannot be revoked any other way.
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $data = json_decode((string) $request->getContent());

            if (json_last_error() !== JSON_ERROR_NONE || ! is_object($data)) {
                throw new \RuntimeException('The request body is not a JSON object.');
            }

            $currentUsername = trim((string) ($data->current_username ?? $data->username ?? ''));
            $username = trim((string) ($data->username ?? ''));
            $password = $data->password ?? null;

            try {
                $substitute = $this->cleanDisplayField($data->substitute ?? null, 100, 'جانشین');
                $workHours = $this->cleanDisplayField($data->work_hours ?? null, 50, 'ساعت ساعت کاری');
                $department = $this->cleanDisplayField($data->department ?? null, 100, 'بخش');
            } catch (LegacyHttpException $exception) {
                return response()->json(['success' => false, 'error' => $exception->getMessage()], 400);
            }

            $employmentStatus = mb_strtolower(trim((string) ($data->employment_status ?? '')));
            $isActive = mb_strtolower(trim((string) ($data->is_active ?? 'active')));

            if ($isActive !== 'active' && $isActive !== 'inactive') {
                $isActive = 'active';
            }

            if ($currentUsername === '' || $username === '') {
                return response()->json(['success' => false, 'error' => 'نام کاربری الزامی است.']);
            }

            $usernameCheck = LegacyInput::validateUsername($username);

            if ($usernameCheck['valid'] !== true) {
                return response()->json(['success' => false, 'error' => $usernameCheck['error']], 400);
            }

            $passwordValue = $password === null ? '' : trim((string) $password);
            $columns = $this->userTableColumns();
            $setClauses = ['username = ?', 'substitute = ?', 'work_hours = ?', 'department = ?'];
            $params = [$username, $substitute, $workHours, $department];

            if (in_array($employmentStatus, self::EMPLOYMENT_STATUSES, true)) {
                $setClauses[] = 'employment_status = ?';
                $params[] = $employmentStatus;
            }

            if (in_array('is_active', $columns, true)) {
                $setClauses[] = 'is_active = ?';
                $params[] = $isActive;
            }

            if ($passwordValue !== '') {
                $newHash = LegacyPassword::hash($passwordValue);

                if (in_array('password_hash', $columns, true)) {
                    $setClauses[] = "password = ''";
                    $setClauses[] = 'password_hash = ?';
                    $params[] = $newHash;
                } else {
                    $setClauses[] = 'password = ?';
                    $params[] = $newHash;
                }
            }

            $params[] = $currentUsername;

            DB::connection()->update(
                'UPDATE user_table SET '.implode(', ', $setClauses).' WHERE LTRIM(RTRIM(username)) = ?',
                $params
            );

            $revoked = 0;

            if ($passwordValue !== '' || $isActive !== 'active' || $username !== $currentUsername) {
                $revoked = app(SessionRegistry::class)->revokeUserSessions($currentUsername, 'user_update');
            }

            return response()->json(['success' => true, 'sessions_revoked' => $revoked]);
        } catch (Throwable) {
            return response()->json(['success' => false, 'error' => 'خطای داخلی سرور']);
        }
    }

    /**
     * `GET /fetch_user_data` — the three name fields for the report header.
     *
     * The query parameter is validated before the guard (see the class
     * docblock).  A missing user is the FastAPI 404 `{"error": …}` body, not an
     * envelope.
     */
    public function fetch(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'username' => LegacyQuery::requiredString(),
        ]);

        $authError = $this->requireAdmin($request);

        if ($authError !== null) {
            return $authError;
        }

        try {
            $row = DB::connection()->selectOne(
                'SELECT name, last_name, department FROM user_table WHERE username = ?',
                [$params['username']]
            );

            if ($row) {
                return response()->json([
                    'name' => $row->name,
                    'last_name' => $row->last_name,
                    'department' => $row->department,
                ]);
            }

            return response()->json(['error' => 'User not found'], 404);
        } catch (Throwable) {
            return response()->json(['error' => 'Error retrieving user info'], 500);
        }
    }

    /**
     * FastAPI's `Form(...)` validation for `add_user`.
     *
     * A required field that is absent **or empty** is a `missing` failure —
     * FastAPI 0.141 treats an empty form value as not provided.  Every failure
     * is reported at once, in declaration order, against `loc: ["body", …]`.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     *
     * @throws LegacyValidationException
     */
    private function validateForm(array $input): array
    {
        $errors = [];
        $values = [];

        foreach (self::REQUIRED_FORM_FIELDS as $field) {
            $value = $input[$field] ?? null;

            if ($value === null || $value === '') {
                $errors[] = [
                    'type' => 'missing',
                    'loc' => ['body', $field],
                    'msg' => 'Field required',
                    'input' => null,
                ];

                continue;
            }

            $values[$field] = $value;
        }

        if ($errors !== []) {
            throw new LegacyValidationException($errors);
        }

        // `employment_status: str = Form("official")` — a field with a default,
        // so an empty value takes the default rather than failing.
        $employmentStatus = $input['employment_status'] ?? null;
        $values['employment_status'] = ($employmentStatus === null || $employmentStatus === '')
            ? 'official'
            : $employmentStatus;

        return $values;
    }

    /**
     * `clean_display_text()` — the check behind `_clean_display_field()`.
     *
     * Inlined rather than taken from {@see LegacyDisplayText} because that
     * class's allowed-character pattern is **missing the hyphen** the Python
     * pattern allows (`app/core/validation.py` has `.,_\-/()`), so a
     * `work_hours` of `8-17` — which the running server accepts — would be
     * refused here.  The messages are the same ones that class publishes, so
     * the operator sees no difference.
     *
     * @throws LegacyHttpException 422 with the operator-facing Persian message.
     */
    private function cleanDisplayField(mixed $value, int $maxLength, string $field): string
    {
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', (string) ($value ?? ''));
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        if (mb_strlen($text) > $maxLength) {
            throw LegacyHttpException::detail(422, "{$field} نباید بیش از {$maxLength} کاراکتر باشد.");
        }

        if (str_contains($text, '<') || str_contains($text, '>')
            || (str_contains($text, '&') && ! str_contains($text, '&amp;'))) {
            throw LegacyHttpException::detail(422, "{$field} شامل کاراکترهای غیرمجاز است.");
        }

        $allowed = '/^[\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}'
            .'A-Za-z0-9 .,_\-\/()\x{200c}\x{200f}]+$/u';

        if (preg_match($allowed, $text) !== 1) {
            throw LegacyHttpException::detail(422, "{$field} شامل کاراکترهای غیرمجاز است.");
        }

        return $text;
    }

    /**
     * The lower-cased column names of `user_table`.
     *
     * `get_user_table_columns()` in `app/main.py` read `INFORMATION_SCHEMA`;
     * the schema builder is the Laravel equivalent and cannot drift from the
     * database.
     *
     * @return array<int, string>
     */
    private function userTableColumns(): array
    {
        $columns = Schema::getColumns('user_table');

        return array_map(
            static fn (array $column): string => strtolower((string) $column['name']),
            $columns
        );
    }

    /**
     * `_next_available_user_id()` — an unused numeric id from a CSPRNG.
     *
     * Up to 20 candidates in `[100, 999]` are drawn and checked against the
     * table; if all are taken the fallback is `MAX(id) + 1`.  A failure to
     * allocate is `null`, which the handler turns into a 500.
     */
    private function nextAvailableUserId(): ?int
    {
        $connection = DB::connection();

        for ($attempt = 0; $attempt < 20; $attempt++) {
            $candidate = random_int(100, 999);
            $exists = $connection->selectOne('SELECT 1 FROM user_table WHERE id = ?', [$candidate]);

            if ($exists === null) {
                return $candidate;
            }
        }

        $row = $connection->selectOne('SELECT ISNULL(MAX(id), 0) + 1 AS next_id FROM user_table');

        return $row === null ? null : (int) $row->next_id;
    }
}
