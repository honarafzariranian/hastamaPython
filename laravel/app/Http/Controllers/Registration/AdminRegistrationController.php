<?php

namespace App\Http\Controllers\Registration;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ClientAddress;
use App\Support\Legacy\LegacyHttpException;
use App\Support\Legacy\LegacyPagination;
use App\Support\Legacy\LegacyQuery;
use App\Support\Legacy\LegacySerializer;
use App\Support\Registration\ApprovedAccountWriter;
use App\Support\Registration\DisplayText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The administrator half of the self-registration surface — ported from the
 * `admin/requests` handlers in `app/api/routes/registration.py`.
 *
 * Four endpoints: the review list, the request detail, and the approve /
 * reject workflow.  All four sit behind the `admin` middleware (wired in
 * `routes/registration.php`), which is the application's port of the
 * Python's session guard.
 *
 * Three rules the Python establishes and this port keeps:
 *
 * * **`password_hash` never leaves the server.**  The list and detail
 *   projections name their columns explicitly; only the approve handler
 *   reads the hash, and only to hand it to {@see ApprovedAccountWriter}.
 *   A `SELECT *` here would publish the bcrypt hash to the review screen.
 * * **The approval is atomic.**  The Python's single connection committed
 *   the `user_table` insert and the request-status update together; this
 *   port wraps them in one transaction so a failure cannot leave an account
 *   created but the request still pending (or the reverse).
 * * **Every refusal has its own body and status**, and they are not all the
 *   same: a missing or already-processed request is a 404, a username that
 *   was taken between submission and approval is a 400, a missing rejection
 *   reason is a 400, and a database failure is the 500 envelope.
 */
final class AdminRegistrationController extends Controller
{
    /**
     * The review-list and detail projection — eighteen columns, in the
     * Python's order, and never `password_hash`.
     */
    private const REQUEST_COLUMNS = 'request_id, first_name, last_name, father_name, national_id, mobile,
                        username, department, work_hours, substitute, status, rejection_reason,
                        reviewed_by, reviewed_at, created_ip, created_user_agent, created_at, updated_at';

    /**
     * The approve projection: the same columns plus the hash, which the
     * approval is the one operation that needs.
     */
    private const APPROVE_COLUMNS = 'request_id, first_name, last_name, father_name, national_id, mobile,
                        username, password_hash, department, work_hours, substitute, status';

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ApprovedAccountWriter $accounts,
    ) {}

    // ── List requests ────────────────────────────────────────────────────

    /**
     * `GET /registration/admin/requests` — the review queue.
     *
     * With no `status` filter the Python lists every state (`pending`,
     * `approved`, `rejected`); with one, it filters to exactly that state.
     * `search` is a `LIKE` across the five human-entered fields.  The
     * envelope is the six-key paginated shape the admin UI reads.
     */
    public function index(Request $request): JsonResponse
    {
        $params = LegacyQuery::validate($request, [
            'status' => LegacyQuery::string(default: ''),
            'search' => LegacyQuery::string(default: ''),
            'page' => LegacyQuery::int(default: 1, ge: 1),
            'per_page' => LegacyQuery::int(default: 25, ge: 1, le: 100),
        ]);

        $page = $params['page'];
        $perPage = $params['per_page'];

        try {
            $where = [];
            $bindings = [];

            if ($params['status'] !== '') {
                $where[] = 'status = ?';
                $bindings[] = $params['status'];
            } else {
                $where[] = "status IN ('pending', 'approved', 'rejected')";
            }

            if ($params['search'] !== '') {
                $where[] = '(first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR national_id LIKE ? OR mobile LIKE ?)';
                $needle = '%'.$params['search'].'%';
                $bindings = array_merge($bindings, [$needle, $needle, $needle, $needle, $needle]);
            }

            $clause = $where === [] ? '1=1' : implode(' AND ', $where);
            $connection = DB::connection();

            $total = (int) $connection->selectOne(
                "SELECT COUNT(*) AS total FROM user_registration_requests WHERE {$clause}",
                $bindings
            )->total;

            $rows = $connection->select(
                'SELECT '.self::REQUEST_COLUMNS."
                 FROM user_registration_requests WHERE {$clause}
                 ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
                array_merge($bindings, [LegacyPagination::offset($page, $perPage), $perPage])
            );

            return response()->json(LegacyPagination::envelope(
                LegacySerializer::rows('user_registration_requests', $rows),
                $total,
                $page,
                $perPage,
            ));
        } catch (Throwable $exception) {
            Log::error('registration admin list failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطای داخلی سرور',
            ], 500);
        }
    }

    // ── Request detail ───────────────────────────────────────────────────

    /**
     * `GET /registration/admin/requests/{request_id}` — one request.
     *
     * A missing id is FastAPI's 404 `detail` body, not the `success`
     * envelope — the review screen branches on the distinction.
     */
    public function show(string $requestId): JsonResponse
    {
        $trimmed = DisplayText::strip($requestId);

        try {
            $row = DB::selectOne(
                'SELECT '.self::REQUEST_COLUMNS.'
                 FROM user_registration_requests WHERE request_id = ?',
                [$trimmed]
            );

            if ($row === null) {
                return response()->json(['detail' => 'درخواست یافت نشد.'], 404);
            }

            return response()->json([
                'success' => true,
                'data' => LegacySerializer::row('user_registration_requests', $row),
            ]);
        } catch (Throwable $exception) {
            Log::error('registration admin detail failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطای داخلی سرور',
            ], 500);
        }
    }

    // ── Approve ─────────────────────────────────────────────────────────

    /**
     * `POST /registration/admin/requests/{request_id}/approve` — create the
     * account.
     *
     * The order is the Python's: fetch the pending request (with the hash),
     * double-check the username is still free, allocate the next id, insert
     * the account through {@see ApprovedAccountWriter}, mark the request
     * approved, commit, then audit.  The account is written with the bcrypt
     * hash the applicant's password produced at submit time — the plaintext
     * is never stored and never leaves the server.
     */
    public function approve(Request $request, string $requestId): JsonResponse
    {
        $adminUsername = $request->session()->get('username');
        $trimmed = DisplayText::strip($requestId);

        try {
            $row = DB::selectOne(
                'SELECT '.self::APPROVE_COLUMNS."
                 FROM user_registration_requests WHERE request_id = ? AND status = 'pending'",
                [$trimmed]
            );

            if ($row === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'درخواست یافت نشد یا قبلاً پردازش شده.',
                ], 404);
            }

            $taken = DB::selectOne(
                'SELECT 1 FROM user_table WHERE LTRIM(RTRIM(username)) = ?',
                [$row->username]
            );

            if ($taken !== null) {
                return response()->json([
                    'success' => false,
                    'message' => 'نام کاربری قبلاً استفاده شده است.',
                ], 400);
            }

            $nextId = (int) DB::selectOne(
                'SELECT ISNULL(MAX(id), 0) + 1 AS next_id FROM user_table'
            )->next_id;

            DB::transaction(function () use ($row, $nextId, $adminUsername, $requestId): void {
                // The Python's `req["…"] or ""` — a NULL department,
                // substitute or work-hours is stored as an empty string.
                $this->accounts->create(
                    $nextId,
                    $row->username,
                    $row->password_hash,
                    $row->first_name,
                    $row->last_name,
                    $row->department ?? '',
                    $row->substitute ?? '',
                    $row->work_hours ?? '',
                );

                DB::update(
                    "UPDATE user_registration_requests
                     SET status = 'approved', reviewed_by = ?, reviewed_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME()
                     WHERE request_id = ?",
                    [$adminUsername, DisplayText::strip($requestId)]
                );
            });

            $ipAddress = ClientAddress::for($request);
            $userAgent = ClientAddress::userAgent($request);

            $this->audit->logEvent('ADMINISTRATION', 'approve_registration', [
                'username' => $adminUsername,
                'module' => 'registration',
                'status' => 'success',
                'severity' => 'medium',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => ['request_id' => $requestId, 'new_user' => $row->username],
            ]);

            $this->audit->adminAction($adminUsername, 'approve_registration', [
                'target_username' => $row->username,
                'target_type' => 'registration_request',
                'target_id' => $requestId,
                'description' => "تأیید درخواست ثبت نام کاربر {$row->username}",
                'ip_address' => $ipAddress,
            ]);

            return response()->json([
                'success' => true,
                'message' => "حساب کاربری {$row->username} با موفقیت ایجاد شد.",
            ]);
        } catch (Throwable $exception) {
            Log::error('registration approve failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطای داخلی سرور',
            ], 500);
        }
    }

    // ── Reject ──────────────────────────────────────────────────────────

    /**
     * `POST /registration/admin/requests/{request_id}/reject`.
     *
     * The reason is required and comes from the JSON body.  The Python parses
     * the body *outside* its `try`, so a body that is not a JSON object is an
     * unhandled 500 rather than the 400 a missing reason produces — reproduced
     * here as a plain 500.
     */
    public function reject(Request $request, string $requestId): JsonResponse
    {
        $adminUsername = $request->session()->get('username');

        $decoded = json_decode($request->getContent());

        if (! is_object($decoded)) {
            throw LegacyHttpException::detail(500, 'Internal Server Error');
        }

        $reason = DisplayText::strip(DisplayText::pyStrOrEmpty($decoded->reason ?? null));

        if ($reason === '') {
            return response()->json([
                'success' => false,
                'message' => 'دلیل رد الزامی است.',
            ], 400);
        }

        try {
            $updated = DB::update(
                "UPDATE user_registration_requests
                 SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME()
                 WHERE request_id = ? AND status = 'pending'",
                [$reason, $adminUsername, DisplayText::strip($requestId)]
            );

            if ($updated === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'درخواست یافت نشد یا قبلاً پردازش شده.',
                ], 404);
            }

            $ipAddress = ClientAddress::for($request);
            $userAgent = ClientAddress::userAgent($request);

            $this->audit->logEvent('ADMINISTRATION', 'reject_registration', [
                'username' => $adminUsername,
                'module' => 'registration',
                'status' => 'success',
                'severity' => 'medium',
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'metadata' => ['request_id' => $requestId, 'reason' => $reason],
            ]);

            $this->audit->adminAction($adminUsername, 'reject_registration', [
                'target_type' => 'registration_request',
                'target_id' => $requestId,
                'description' => "رد درخواست ثبت نام: {$reason}",
                'ip_address' => $ipAddress,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'درخواست رد شد.',
            ]);
        } catch (Throwable $exception) {
            Log::error('registration reject failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'خطای داخلی سرور',
            ], 500);
        }
    }
}
