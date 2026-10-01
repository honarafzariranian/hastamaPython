<?php

namespace App\Support\Http;

use Illuminate\Support\Facades\Auth;

/**
 * The acting master administrator, for the control-plane write endpoints.
 *
 * The Python handlers took the username from `_master_admin(request)` — the
 * guard helper that ran first in every handler and returned the session
 * username after refusing anyone who was not a master administrator.  The
 * Laravel port moves that guard into `EnsureMasterAdmin` middleware, so by the
 * time a handler runs the identity is already established and the username is
 * simply the authenticated user's.
 *
 * It lives as a trait rather than a method on `MasterAdminController` because
 * the write endpoints are split across seven new controllers and the read
 * half's base class is not theirs to edit; one shared, documented line beats
 * seven copies of `Auth::user()?->username() ?? ''` that can drift.
 *
 * The empty string is unreachable in production — the middleware answers 401
 * before the handler runs — but it is the same value the Python's
 * `str(request.session.get("username") or "").strip()` produced for a
 * session-less request, so the failure direction matches.
 */
trait MasterAdminActor
{
    /** The trimmed username of the master administrator whose session this is. */
    protected function adminUsername(): string
    {
        return Auth::user()?->username() ?? '';
    }
}
