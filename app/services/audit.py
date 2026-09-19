"""Hastama Master Admin — Audit Logging Service.

Provides lightweight helpers that write to the audit_logs, security_events,
user_sessions, system_errors, and admin_actions tables.  Designed to be
called from routes / middleware without blocking the request.

All functions accept an *optional* ``conn`` parameter.  When omitted they
open a fresh connection (via ``app.core.database``) and commit immediately.
"""
from __future__ import annotations

import hashlib
import json
import logging
import os
import secrets
import string
import time
from datetime import datetime, timezone, timedelta
from typing import Any, Optional

from app.core.database import connect as db_connect

logger = logging.getLogger(__name__)

# ── ID Generators ─────────────────────────────────────────────

_seq_counter = 0
_seq_lock_ts = 0


def _hex_id(length: int = 8) -> str:
    return secrets.token_hex(length // 2)


def generate_event_id() -> str:
    ts = datetime.now(timezone.utc).strftime("%Y%m%d")
    return f"HST-{ts}-{_hex_id(8)}".upper()


def generate_request_id() -> str:
    return generate_event_id()


def generate_action_id() -> str:
    return generate_event_id()


# ── Core Audit Log ────────────────────────────────────────────

def log_event(
    *,
    event_type: str,
    action: str,
    username: Optional[str] = None,
    role: Optional[str] = None,
    module: Optional[str] = None,
    resource_type: Optional[str] = None,
    resource_id: Optional[str] = None,
    request_id: Optional[str] = None,
    session_id: Optional[str] = None,
    ip_address: Optional[str] = None,
    user_agent: Optional[str] = None,
    status: str = "success",
    severity: str = "info",
    before_data: Optional[Any] = None,
    after_data: Optional[Any] = None,
    metadata: Optional[Any] = None,
    error_id: Optional[str] = None,
    conn=None,
) -> str:
    """Insert a row into audit_logs.  Returns the generated event_id."""
    event_id = generate_event_id()
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        cur.execute(
            """INSERT INTO dbo.audit_logs
               (event_id, event_type, action, username, role, module,
                resource_type, resource_id, request_id, session_id,
                ip_address, user_agent, status, severity,
                before_data, after_data, metadata, error_id)
               VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)""",
            (
                event_id,
                event_type,
                action,
                username,
                role,
                module,
                resource_type,
                str(resource_id) if resource_id is not None else None,
                request_id,
                session_id,
                ip_address,
                (user_agent[:500] if user_agent else None),
                status,
                severity,
                _json_or_none(before_data),
                _json_or_none(after_data),
                _json_or_none(metadata),
                error_id,
            ),
        )
        if _close:
            conn.commit()
        return event_id
    except Exception:
        # Audit must never crash the application
        return event_id
    finally:
        if _close:
            conn.close()


# ── Security Events ───────────────────────────────────────────

def create_security_event(
    *,
    event_type: str,
    severity: str = "medium",
    username: Optional[str] = None,
    ip_address: Optional[str] = None,
    description: str = "",
    metadata: Optional[Any] = None,
    conn=None,
) -> str:
    event_id = generate_event_id()
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        cur.execute(
            """INSERT INTO dbo.security_events
               (event_id, event_type, severity, username, ip_address,
                description, metadata)
               VALUES (?,?,?,?,?,?,?)""",
            (event_id, event_type, severity, username, ip_address,
             description, _json_or_none(metadata)),
        )
        if _close:
            conn.commit()
        return event_id
    except Exception:
        return event_id
    finally:
        if _close:
            conn.close()


# ── Session Tracking ──────────────────────────────────────────

def track_session_login(
    session_key: str,
    username: str,
    ip_address: Optional[str] = None,
    user_agent: Optional[str] = None,
    conn=None,
) -> None:
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        cur.execute(
            """INSERT INTO dbo.user_sessions
               (session_key, username, ip_address, user_agent)
               VALUES (?,?,?,?)""",
            (session_key, username, ip_address,
             (user_agent[:500] if user_agent else None)),
        )
        if _close:
            conn.commit()
    except Exception:
        pass
    finally:
        if _close:
            conn.close()


def track_session_activity(session_key: str, conn=None) -> None:
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        cur.execute(
            "UPDATE dbo.user_sessions SET last_activity = SYSUTCDATETIME() WHERE session_key = ? AND is_active = 1",
            (session_key,),
        )
        if _close:
            conn.commit()
    except Exception:
        pass
    finally:
        if _close:
            conn.close()


def track_session_logout(session_key: str, conn=None) -> None:
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        cur.execute(
            """UPDATE dbo.user_sessions
               SET logout_at = SYSUTCDATETIME(), is_active = 0
               WHERE session_key = ? AND is_active = 1""",
            (session_key,),
        )
        if _close:
            conn.commit()
    except Exception:
        pass
    finally:
        if _close:
            conn.close()


def terminate_session(
    session_key: str,
    admin_username: str,
    ip_address: Optional[str] = None,
    conn=None,
) -> bool:
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        cur.execute(
            """UPDATE dbo.user_sessions
               SET logout_at = SYSUTCDATETIME(), is_active = 0,
                   terminated_by = ?
               WHERE session_key = ? AND is_active = 1""",
            (admin_username, session_key),
        )
        if _close:
            conn.commit()
        return cur.rowcount > 0
    except Exception:
        return False
    finally:
        if _close:
            conn.close()


# ── Password Reset ────────────────────────────────────────────

import hmac as _hmac
import hashlib as _hashlib

# ── Recovery-code integrity key ───────────────────────────────
#
# Recovery codes are stored as HMAC-SHA256 digests.  Without a server secret the
# digest would be a plain hash of an 8 character code, i.e. offline computable
# by anybody who learns a request id — and a request id is returned to the
# caller by ``/forgot_password``.  The password recovery flow therefore fails
# closed when no secret is configured (see ``recovery_codes_available``), rather
# than silently issuing codes with no integrity key.
_HMAC_SECRET = os.environ.get("HASTAMA_HMAC_SECRET", "").strip().encode()
_HMAC_SECRET_PER_PROCESS: Optional[bytes] = None


def recovery_codes_available() -> bool:
    """Whether the recovery-code HMAC key is configured."""
    return bool(_HMAC_SECRET)


def _recovery_secret() -> bytes:
    global _HMAC_SECRET_PER_PROCESS
    if _HMAC_SECRET:
        return _HMAC_SECRET
    if DEBUG_MODE:
        # Development convenience only (DEBUG=true): use an ephemeral key so a
        # local checkout still works. Codes do not survive a restart.
        if _HMAC_SECRET_PER_PROCESS is None:
            _HMAC_SECRET_PER_PROCESS = secrets.token_bytes(32)
            logger.warning("HASTAMA_HMAC_SECRET not set — using an ephemeral development key")
        return _HMAC_SECRET_PER_PROCESS
    raise RuntimeError("HASTAMA_HMAC_SECRET is not configured")


DEBUG_MODE = os.environ.get("DEBUG", "").strip().lower() in {"1", "true", "yes", "on"}
if not _HMAC_SECRET and not DEBUG_MODE:
    logger.error(
        "HASTAMA_HMAC_SECRET is not set — password recovery is disabled until it is configured"
    )


def _hash_code(code: str) -> str:
    """Hash recovery code using HMAC-SHA256 with the server secret."""
    return _hmac.new(_recovery_secret(), code.encode(), _hashlib.sha256).hexdigest()


def create_password_reset_request(
    username: str,
    ip_address: Optional[str] = None,
    user_agent: Optional[str] = None,
    conn=None,
) -> dict:
    """Create a pending password-reset request.  Returns dict with request_id."""
    request_id = generate_event_id()
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        cur.execute(
            """INSERT INTO dbo.password_reset_requests
               (request_id, username, ip_address, user_agent)
               VALUES (?,?,?,?)""",
            (request_id, username, ip_address,
             (user_agent[:500] if user_agent else None)),
        )
        if _close:
            conn.commit()
        return {"request_id": request_id, "status": "pending"}
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return {"request_id": None, "status": "error", "message": "خطای داخلی سرور"}
    finally:
        if _close:
            conn.close()


def approve_password_reset(
    request_id: str,
    admin_username: str,
    ttl_minutes: int = 60,
    conn=None,
) -> dict:
    """Approve a reset request and generate a one-time recovery code."""
    if not recovery_codes_available() and not DEBUG_MODE:
        return {
            "success": False,
            "message": "کلید HASTAMA_HMAC_SECRET تنظیم نشده است؛ بازیابی رمز عبور غیرفعال است.",
            "code_unavailable": True,
        }
    # ``secrets`` has no ``token_uppercase``; the previous implementation raised
    # AttributeError, so approving a reset request always failed.
    code = "".join(secrets.choice(string.ascii_uppercase + string.digits) for _ in range(8))
    code_hash = _hash_code(code)
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        expires = datetime.now(timezone.utc) + timedelta(minutes=ttl_minutes)
        cur.execute(
            """UPDATE dbo.password_reset_requests
               SET status = 'approved', recovery_code = ?, code_expires_at = ?,
                   approved_by = ?, approved_at = SYSUTCDATETIME(),
                   updated_at = SYSUTCDATETIME()
               WHERE request_id = ? AND status = 'pending'""",
            (code_hash, expires, admin_username, request_id),
        )
        if _close:
            conn.commit()
        if cur.rowcount > 0:
            return {"success": True, "code": code, "expires": expires.isoformat()}
        return {"success": False, "message": "درخواست یافت نشد یا قبلاً پردازش شده است."}
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return {"success": False, "message": "خطای داخلی سرور"}
    finally:
        if _close:
            conn.close()


def reject_password_reset(request_id: str, admin_username: str, conn=None) -> bool:
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        cur.execute(
            """UPDATE dbo.password_reset_requests
               SET status = 'rejected', approved_by = ?,
                   updated_at = SYSUTCDATETIME()
               WHERE request_id = ? AND status = 'pending'""",
            (admin_username, request_id),
        )
        if _close:
            conn.commit()
        return cur.rowcount > 0
    except Exception:
        return False
    finally:
        if _close:
            conn.close()


# Single rejection message for every recovery failure that must not be
# distinguishable to an unauthenticated caller.
CODE_REJECTED_MESSAGE = "کد بازیابی نامعتبر است."


def verify_recovery_code(request_id: str, code: str, conn=None) -> dict:
    """Verify a one-time recovery code.  Returns success + new password info."""
    if not recovery_codes_available() and not DEBUG_MODE:
        return {"success": False, "message": "بازیابی رمز عبور در این نصب غیرفعال است."}
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        cur.execute(
            """SELECT recovery_code, code_expires_at, code_attempts, max_attempts, status, username
               FROM dbo.password_reset_requests WHERE request_id = ?""",
            (request_id,),
        )
        row = cur.fetchone()
        if not row:
            # Deliberately identical to the wrong-code answer below: /forgot_password
            # returns a (decoy) request id even for unknown accounts, so a
            # distinguishable message here would turn this endpoint into a
            # username enumeration oracle.
            return {"success": False, "message": CODE_REJECTED_MESSAGE}

        stored_hash, expires, attempts, max_attempts, status, username = row

        # ── Ordering matters for anti-enumeration ────────────────────────────
        # /forgot_password answers with a decoy request id for accounts that do
        # not exist, so *nothing* an unauthenticated caller can observe may
        # differ between a decoy id and a real id.  The reason-specific answers
        # ("expired", "already processed") are therefore only produced once the
        # caller has proven possession of the code — an attacker who does not
        # hold the code can never reach them and always sees the one generic
        # rejection message, exactly as for an unknown request id.
        if attempts >= max_attempts:
            # Hard cap reached: no further code is accepted.  The state change is
            # kept internal so the caller cannot tell it apart from a wrong code.
            cur.execute(
                "UPDATE dbo.password_reset_requests SET status='expired', updated_at=SYSUTCDATETIME() WHERE request_id=?",
                (request_id,),
            )
            if _close:
                conn.commit()
            return {"success": False, "message": CODE_REJECTED_MESSAGE}

        code_matches = _hmac.compare_digest(
            str(stored_hash or ""), _hash_code(code.strip())
        )
        if not code_matches:
            cur.execute(
                "UPDATE dbo.password_reset_requests SET code_attempts = code_attempts + 1, updated_at=SYSUTCDATETIME() WHERE request_id=?",
                (request_id,),
            )
            if _close:
                conn.commit()
            return {"success": False, "message": CODE_REJECTED_MESSAGE}

        # The caller proved possession of the code; the remaining answers are
        # operational feedback, not an oracle.
        if expires and expires.replace(tzinfo=timezone.utc) < datetime.now(timezone.utc):
            cur.execute(
                "UPDATE dbo.password_reset_requests SET status='expired', updated_at=SYSUTCDATETIME() WHERE request_id=?",
                (request_id,),
            )
            if _close:
                conn.commit()
            return {"success": False, "message": "کد بازیابی منقضی شده است."}

        if status != "approved":
            return {"success": False, "message": "این درخواست قبلاً پردازش شده است."}

        # Success — mark completed
        cur.execute(
            """UPDATE dbo.password_reset_requests
               SET status = 'completed', completed_at = SYSUTCDATETIME(),
                   updated_at = SYSUTCDATETIME()
               WHERE request_id = ?""",
            (request_id,),
        )
        if _close:
            conn.commit()
        return {"success": True, "username": username}
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return {"success": False, "message": "خطای داخلی سرور"}
    finally:
        if _close:
            conn.close()


# ── System Errors ─────────────────────────────────────────────

def log_system_error(
    *,
    error_type: str = "application",
    severity: str = "medium",
    message: str = "",
    detail: Optional[str] = None,
    endpoint: Optional[str] = None,
    method: Optional[str] = None,
    username: Optional[str] = None,
    ip_address: Optional[str] = None,
    request_id: Optional[str] = None,
    session_id: Optional[str] = None,
    conn=None,
) -> str:
    error_id = generate_event_id()
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        cur.execute(
            """INSERT INTO dbo.system_errors
               (error_id, error_type, severity, message, detail,
                endpoint, method, username, ip_address, request_id, session_id)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
            (error_id, error_type, severity, message[:2000],
             (detail[:4000] if detail else None),
             endpoint, method, username, ip_address, request_id, session_id),
        )
        if _close:
            conn.commit()
        return error_id
    except Exception:
        return error_id
    finally:
        if _close:
            conn.close()


# ── Admin Actions ─────────────────────────────────────────────

def log_admin_action(
    *,
    admin_username: str,
    action: str,
    target_username: Optional[str] = None,
    target_type: Optional[str] = None,
    target_id: Optional[str] = None,
    description: Optional[str] = None,
    before_data: Optional[Any] = None,
    after_data: Optional[Any] = None,
    ip_address: Optional[str] = None,
    request_id: Optional[str] = None,
    conn=None,
) -> str:
    action_id = generate_action_id()
    _close = False
    if conn is None:
        conn = db_connect()
        _close = True
    try:
        cur = conn.cursor()
        cur.execute(
            """INSERT INTO dbo.admin_actions
               (action_id, admin_username, action, target_username,
                target_type, target_id, description,
                before_data, after_data, ip_address, request_id)
               VALUES (?,?,?,?,?,?,?,?,?,?,?)""",
            (action_id, admin_username, action, target_username,
             target_type, target_id, description,
             _json_or_none(before_data), _json_or_none(after_data),
             ip_address, request_id),
        )
        if _close:
            conn.commit()
        return action_id
    except Exception:
        return action_id
    finally:
        if _close:
            conn.close()


# ── Helpers ───────────────────────────────────────────────────

def _json_or_none(value: Any) -> Optional[str]:
    if value is None:
        return None
    if isinstance(value, str):
        return value
    try:
        return json.dumps(value, ensure_ascii=False, default=str)
    except Exception:
        return str(value)
