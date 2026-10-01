<?php

namespace App\Http\Controllers\MasterAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared behaviour for the master-admin control plane.
 *
 * The control-plane endpoints are unusual in that they do **not** use
 * `App\Support\ApiResponse`.  That class is the shape the new Vue client reads
 * (`{"success": true, "data": …, "message": null}`), and it is the right default —
 * but these routes are already consumed by the running admin UI, which reads the
 * Python bodies verbatim:
 *
 *     success  {"success": true, "data": …}            # no "message" key at all
 *     failure  {"success": false, "message": "خطای داخلی سرور"}   # HTTP 500
 *     missing  {"detail": "کاربر یافت نشد."}                        # HTTP 404
 *
 * Three different shapes, all in the same module, all load-bearing.  Rebuilding
 * them through `ApiResponse` would have added a `message: null` key to every
 * success body and a `success: false` wrapper to every 404.  So this base class
 * names the shapes once instead.
 *
 * The one thing it changes deliberately: the internal-error body never carries the
 * exception text.  `message` is the generic Persian string the Python code used,
 * and the real detail goes to the log — the same contract `_safe_error_message()`
 * implemented.
 */
abstract class MasterAdminController extends Controller
{
    /**
     * The body every unexpected failure in this module answers with.
     *
     * Verbatim from `master_admin.py`; note it is **not** the message
     * `_safe_error_message()` produces for the rest of the application
     * (`خطای داخلی سرور. لطفاً با پشتیبانی تماس بگیرید.`).  Two modules, two
     * strings, one front-end that reads both.
     */
    protected const INTERNAL_ERROR = 'خطای داخلی سرور';

    /**
     * A successful control-plane response.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function ok(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status);
    }

    /**
     * The generic 500, with the technical detail logged rather than published.
     *
     * The Python code logged `f"Error: {type(e).__name__}: {e}"`; the class name is
     * kept because it is what makes a log line actionable at a glance.
     */
    protected function internalError(Throwable $exception, string $operation): JsonResponse
    {
        Log::error("master-admin {$operation} failed", [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        return response()->json([
            'success' => false,
            'message' => self::INTERNAL_ERROR,
        ], 500);
    }

    /**
     * A missing record, in the shape the FastAPI `HTTPException` produced.
     *
     * The `detail` key is not a mistake — it is what FastAPI's exception handler
     * rendered, and the admin UI reads it.
     */
    protected function notFound(string $message): JsonResponse
    {
        return response()->json(['detail' => $message], 404);
    }
}
