<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Http\ClientAddress;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The control-plane guard.
 *
 * `_master_admin` in `app/api/routes/master_admin.py` is explicit about why it
 * exists as a separate check: *only* `is_master_admin` is accepted, because a
 * session holding the ordinary `is_admin` flag must not reach the control plane.
 * The migration keeps the two flags separate for the same reason.
 *
 * Failure answers match the legacy ones — the Python code raised `HTTPException`,
 * which FastAPI rendered as `{"detail": "…"}`:
 *
 *     401  {"detail": "ورود لازم است."}
 *     403  {"detail": "دسترسی مدیریت اصلی لازم است."}
 *
 * The 403 also raises a security event, as `_log_denied_master_admin` did: an
 * ordinary administrator probing the control plane is exactly the kind of event the
 * operator wants to see.
 */
final class EnsureMasterAdmin
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();
        $username = $user instanceof User ? $user->username() : '';

        if ($username === '') {
            return response()->json(['detail' => 'ورود لازم است.'], 401);
        }

        if ($request->session()->get('is_master_admin') !== true) {
            $this->audit->securityEvent(
                'AUTHORIZATION',
                'master admin endpoints were requested without the master-admin flag',
                [
                    'severity' => AuditLogger::SEVERITY_HIGH,
                    'username' => $username,
                    'ip_address' => ClientAddress::for($request),
                    'metadata' => [
                        'path' => $request->path(),
                        'method' => $request->method(),
                        'is_admin' => $request->session()->get('is_admin') === true,
                    ],
                ],
            );

            return response()->json(['detail' => 'دسترسی مدیریت اصلی لازم است.'], 403);
        }

        return $next($request);
    }
}
