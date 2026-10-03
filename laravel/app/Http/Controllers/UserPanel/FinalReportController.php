<?php

namespace App\Http\Controllers\UserPanel;

use App\Http\Controllers\Controller;
use App\Support\Legacy\LegacyHttpException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * `GET /get_user_info_final_report_page/{username}` — the name block the
 * final-report screen shows above a user's report.
 *
 * ### A real bug, reproduced
 *
 * The Python raises `HTTPException(404, "کاربر یافت نشد")` for a missing user —
 * but that `raise` is **inside** the `try`, and the handler's `except Exception`
 * catches `HTTPException` (it is an `Exception` subclass) and re-raises it as
 * `HTTPException(500, "خطای سرور")`.  So the 404 is unreachable: a missing user
 * answers **500** with `{"detail": "خطای سرور"}`, not a 404.
 *
 *     try:
 *         ...
 *         if row: return {...}
 *         else: raise HTTPException(404, "کاربر یافت نشد")   # caught below
 *     except Exception as e:
 *         raise HTTPException(500, "خطای سرور")              # what actually answers
 *
 * The port reproduces it: the not-found branch falls through to the same 500.
 */
final class FinalReportController extends Controller
{
    /**
     * The three columns the report header publishes.  Never widened.
     */
    private const COLUMNS = 'name, last_name, department';

    public function show(string $username): JsonResponse
    {
        try {
            $username = trim($username);

            $row = DB::connection()->selectOne(
                'SELECT '.self::COLUMNS.' FROM user_table WHERE RTRIM(username) = ?',
                [$username]
            );

            if ($row) {
                return response()->json([
                    'name' => $row->name,
                    'last_name' => $row->last_name,
                    'department' => $row->department,
                ]);
            }

            // The Python's `raise HTTPException(404)` is swallowed by its own
            // `except Exception`; the answer is the 500 below.
        } catch (Throwable) {
            // Falls through to the 500 below, exactly as the Python's except did.
        }

        throw LegacyHttpException::detail(500, 'خطای سرور');
    }
}
