"""Hastama Master Administration & Control Center — API Routes.

All routes require session authentication with is_master_admin=True.
The middleware / login hook must set request.session["is_master_admin"]
for the designated master-admin user(s).
"""
from __future__ import annotations

import json
import logging
from datetime import datetime, timezone, timedelta
from typing import Optional

import pyodbc
from fastapi import APIRouter, HTTPException, Query, Request
from fastapi.responses import JSONResponse

from app.core.database import connect as db_connect

logger = logging.getLogger(__name__)
from app.services.audit import (
    log_event, log_admin_action, log_system_error,
    create_security_event, terminate_session,
    approve_password_reset, reject_password_reset,
    generate_request_id, generate_event_id,
)

router = APIRouter(prefix="/master-admin/api", tags=["master-admin"])


# ── Authorization Helper ──────────────────────────────────────

def _master_admin(request: Request):
    username = str(request.session.get("username") or "").strip()
    is_ma = request.session.get("is_master_admin") is True
    is_admin = request.session.get("is_admin") is True
    if not username:
        raise HTTPException(status_code=401, detail="ورود لازم است.")
    if not (is_ma or is_admin):
        raise HTTPException(status_code=403, detail="دسترسی مدیریت اصلی لازم است.")
    return username


def _client_ip(request: Request) -> str:
    return request.headers.get("x-forwarded-for", "").split(",")[0].strip() or (
        request.client.host if request.client else "unknown"
    )


def _user_agent(request: Request) -> str:
    return request.headers.get("user-agent", "")[:500]


def _dict_rows(cursor) -> list[dict]:
    columns = [c[0] for c in cursor.description]
    return [dict(zip(columns, row)) for row in cursor.fetchall()]


def _serialize(row: dict) -> dict:
    for key in row:
        if isinstance(row[key], datetime):
            row[key] = row[key].isoformat()
        elif isinstance(row[key], bytes):
            row[key] = row[key].hex() if row[key] else None
    return row


# ══════════════════════════════════════════════════════════════
# DASHBOARD
# ══════════════════════════════════════════════════════════════

@router.get("/dashboard/stats")
async def dashboard_stats(request: Request):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        stats = {}

        # Total users
        cur.execute("SELECT COUNT(*) FROM user_table")
        stats["total_users"] = cur.fetchone()[0]

        # Active users
        cur.execute("SELECT COUNT(*) FROM user_table WHERE ISNULL(is_active,'active') = 'active'")
        stats["active_users"] = cur.fetchone()[0]

        # Online sessions
        cur.execute("SELECT COUNT(*) FROM user_sessions WHERE is_active = 1")
        stats["online_sessions"] = cur.fetchone()[0]

        # Today's logins
        today_start = datetime.now(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0)
        cur.execute("SELECT COUNT(*) FROM audit_logs WHERE event_type='AUTHENTICATION' AND action='login' AND created_at >= ?", (today_start,))
        stats["logins_today"] = cur.fetchone()[0]

        # Failed logins today
        cur.execute("SELECT COUNT(*) FROM audit_logs WHERE event_type='AUTHENTICATION' AND action='login' AND status='failure' AND created_at >= ?", (today_start,))
        stats["failed_logins_today"] = cur.fetchone()[0]

        # Pending password resets
        cur.execute("SELECT COUNT(*) FROM password_reset_requests WHERE status='pending'")
        stats["pending_password_resets"] = cur.fetchone()[0]

        # Open security events
        cur.execute("SELECT COUNT(*) FROM security_events WHERE status='open'")
        stats["open_security_events"] = cur.fetchone()[0]

        # Open system errors
        cur.execute("SELECT COUNT(*) FROM system_errors WHERE status='open'")
        stats["open_errors"] = cur.fetchone()[0]

        # Open support tickets (normalized)
        try:
            cur.execute("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved','closed')")
            stats["open_tickets"] = cur.fetchone()[0]
        except Exception:
            stats["open_tickets"] = 0

        # Admins count
        cur.execute("SELECT COUNT(*) FROM user_table WHERE LTRIM(RTRIM(LOWER(role))) = 'admin'")
        stats["admin_count"] = cur.fetchone()[0]

        # Today's audit events
        cur.execute("SELECT COUNT(*) FROM audit_logs WHERE created_at >= ?", (today_start,))
        stats["events_today"] = cur.fetchone()[0]

        return JSONResponse(content={"success": True, "data": stats})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.get("/dashboard/activity")
async def dashboard_activity(request: Request, limit: int = Query(50, ge=1, le=200)):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute(
            """SELECT TOP (?) event_id, event_type, action, username, module,
                      resource_type, resource_id, status, severity, created_at, ip_address
               FROM audit_logs ORDER BY created_at DESC""",
            (limit,),
        )
        rows = _dict_rows(cur)
        return JSONResponse(content={"success": True, "data": [_serialize(r) for r in rows]})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# AUDIT LOGS
# ══════════════════════════════════════════════════════════════

@router.get("/audit-logs")
async def list_audit_logs(
    request: Request,
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
    event_type: Optional[str] = None,
    action: Optional[str] = None,
    username: Optional[str] = None,
    module: Optional[str] = None,
    severity: Optional[str] = None,
    status: Optional[str] = None,
    date_from: Optional[str] = None,
    date_to: Optional[str] = None,
    request_id: Optional[str] = None,
    search: Optional[str] = None,
):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        where, params = [], []

        if event_type:
            where.append("event_type = ?"); params.append(event_type)
        if action:
            where.append("action = ?"); params.append(action)
        if username:
            where.append("username LIKE ?"); params.append(f"%{username}%")
        if module:
            where.append("module = ?"); params.append(module)
        if severity:
            where.append("severity = ?"); params.append(severity)
        if status:
            where.append("status = ?"); params.append(status)
        if request_id:
            where.append("request_id = ?"); params.append(request_id)
        if date_from:
            where.append("created_at >= ?"); params.append(date_from)
        if date_to:
            where.append("created_at <= ?"); params.append(date_to)
        if search:
            where.append("(username LIKE ? OR module LIKE ? OR action LIKE ? OR event_id LIKE ?)")
            s = f"%{search}%"
            params.extend([s, s, s, s])

        clause = (" WHERE " + " AND ".join(where)) if where else ""

        # Count
        cur.execute(f"SELECT COUNT(*) FROM audit_logs{clause}", params)
        total = cur.fetchone()[0]

        # Page
        offset = (page - 1) * per_page
        cur.execute(
            f"SELECT * FROM audit_logs{clause} ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
            params + [offset, per_page],
        )
        rows = [_serialize(r) for r in _dict_rows(cur)]
        return JSONResponse(content={
            "success": True,
            "data": rows,
            "total": total,
            "page": page,
            "per_page": per_page,
            "pages": max(1, (total + per_page - 1) // per_page),
        })
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.get("/audit-logs/{event_id}")
async def get_audit_event(request: Request, event_id: str):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("SELECT * FROM audit_logs WHERE event_id = ?", (event_id,))
        row = cur.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="رویداد یافت نشد.")
        return JSONResponse(content={"success": True, "data": _serialize(_dict_rows(cur)[0] if cur.description else {})})
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# USER MANAGEMENT
# ══════════════════════════════════════════════════════════════

@router.get("/users")
async def list_users(
    request: Request,
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
    search: Optional[str] = None,
    role: Optional[str] = None,
    status: Optional[str] = None,
):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        where, params = [], []
        if search:
            where.append("(username LIKE ? OR name LIKE ? OR last_name LIKE ?)")
            s = f"%{search}%"; params.extend([s, s, s])
        if role:
            where.append("LTRIM(RTRIM(LOWER(role))) = ?"); params.append(role.lower())
        if status:
            where.append("ISNULL(is_active,'active') = ?"); params.append(status)
        clause = (" WHERE " + " AND ".join(where)) if where else ""

        cur.execute(f"SELECT COUNT(*) FROM user_table{clause}", params)
        total = cur.fetchone()[0]

        offset = (page - 1) * per_page
        cur.execute(
            f"""SELECT id, username, name, last_name, department, role, work_hours,
                       substitute, hozoor_num, is_active, last_login, failed_login_count,
                       password_changed_at
                FROM user_table{clause}
                ORDER BY id OFFSET ? ROWS FETCH NEXT ? ROWS ONLY""",
            params + [offset, per_page],
        )
        rows = [_serialize(r) for r in _dict_rows(cur)]
        return JSONResponse(content={
            "success": True, "data": rows, "total": total,
            "page": page, "per_page": per_page,
            "pages": max(1, (total + per_page - 1) // per_page),
        })
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.get("/users/{username}")
async def get_user_detail(request: Request, username: str):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute(
            """SELECT id, username, name, last_name, department, role, work_hours,
                      substitute, hozoor_num, is_active, last_login, failed_login_count,
                      password_changed_at
               FROM user_table WHERE LTRIM(RTRIM(username)) = ?""",
            (username.strip(),),
        )
        row = cur.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="کاربر یافت نشد.")
        columns = [c[0] for c in cur.description]
        user_data = _serialize(dict(zip(columns, row)))

        # Recent audit events
        cur.execute(
            "SELECT TOP 50 * FROM audit_logs WHERE username = ? ORDER BY created_at DESC",
            (username.strip(),),
        )
        user_data["recent_audit"] = [_serialize(r) for r in _dict_rows(cur)]

        # Sessions
        cur.execute(
            "SELECT TOP 20 * FROM user_sessions WHERE username = ? ORDER BY login_at DESC",
            (username.strip(),),
        )
        user_data["sessions"] = [_serialize(r) for r in _dict_rows(cur)]

        # Password reset history
        cur.execute(
            "SELECT TOP 10 * FROM password_reset_requests WHERE username = ? ORDER BY created_at DESC",
            (username.strip(),),
        )
        user_data["password_resets"] = [_serialize(r) for r in _dict_rows(cur)]

        return JSONResponse(content={"success": True, "data": user_data})
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.post("/users/{username}/toggle-status")
async def toggle_user_status(request: Request, username: str):
    admin = _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("SELECT is_active FROM user_table WHERE LTRIM(RTRIM(username)) = ?", (username.strip(),))
        row = cur.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="کاربر یافت نشد.")
        current = row[0] or "active"
        new_status = "disabled" if current == "active" else "active"
        cur.execute(
            "UPDATE user_table SET is_active = ? WHERE LTRIM(RTRIM(username)) = ?",
            (new_status, username.strip()),
        )
        conn.commit()
        log_admin_action(
            admin_username=admin, action="toggle_user_status",
            target_username=username.strip(), target_type="user",
            description=f"تغییر وضعیت به {new_status}",
            before_data={"is_active": current}, after_data={"is_active": new_status},
            ip_address=_client_ip(request),
        )
        return JSONResponse(content={"success": True, "new_status": new_status})
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.post("/users/{username}/change-role")
async def change_user_role(request: Request, username: str):
    admin = _master_admin(request)
    data = await request.json()
    new_role = str(data.get("role", "")).strip().lower()
    if new_role not in ("user", "admin"):
        raise HTTPException(status_code=400, detail="نقش معتبر نیست.")
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("SELECT role FROM user_table WHERE LTRIM(RTRIM(username)) = ?", (username.strip(),))
        row = cur.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="کاربر یافت نشد.")
        old_role = row[0]
        cur.execute(
            "UPDATE user_table SET role = ? WHERE LTRIM(RTRIM(username)) = ?",
            (new_role, username.strip()),
        )
        conn.commit()
        log_admin_action(
            admin_username=admin, action="change_role",
            target_username=username.strip(), target_type="user",
            description=f"تغییر نقش از {old_role} به {new_role}",
            before_data={"role": old_role}, after_data={"role": new_role},
            ip_address=_client_ip(request),
        )
        return JSONResponse(content={"success": True})
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# SESSIONS
# ══════════════════════════════════════════════════════════════

@router.get("/sessions")
async def list_sessions(
    request: Request,
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
    active_only: bool = False,
    username: Optional[str] = None,
):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        where, params = [], []
        if active_only:
            where.append("is_active = 1")
        if username:
            where.append("username LIKE ?"); params.append(f"%{username}%")
        clause = (" WHERE " + " AND ".join(where)) if where else ""

        cur.execute(f"SELECT COUNT(*) FROM user_sessions{clause}", params)
        total = cur.fetchone()[0]

        offset = (page - 1) * per_page
        cur.execute(
            f"SELECT * FROM user_sessions{clause} ORDER BY login_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
            params + [offset, per_page],
        )
        rows = [_serialize(r) for r in _dict_rows(cur)]
        return JSONResponse(content={
            "success": True, "data": rows, "total": total,
            "page": page, "per_page": per_page,
            "pages": max(1, (total + per_page - 1) // per_page),
        })
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.post("/sessions/{session_key}/terminate")
async def terminate_user_session(request: Request, session_key: str):
    admin = _master_admin(request)
    ok = terminate_session(session_key, admin, _client_ip(request))
    if ok:
        log_admin_action(
            admin_username=admin, action="terminate_session",
            target_type="session", target_id=session_key,
            description="خاتمه اجباری نشست", ip_address=_client_ip(request),
        )
    return JSONResponse(content={"success": ok})


# ══════════════════════════════════════════════════════════════
# PASSWORD RESETS
# ══════════════════════════════════════════════════════════════

@router.get("/password-resets")
async def list_password_resets(
    request: Request,
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
    status: Optional[str] = None,
):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        where, params = [], []
        if status:
            where.append("status = ?"); params.append(status)
        clause = (" WHERE " + " AND ".join(where)) if where else ""

        cur.execute(f"SELECT COUNT(*) FROM password_reset_requests{clause}", params)
        total = cur.fetchone()[0]

        offset = (page - 1) * per_page
        cur.execute(
            f"""SELECT request_id, username, ip_address, status, code_attempts,
                       max_attempts, approved_by, approved_at, completed_at, created_at
                FROM password_reset_requests{clause}
                ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY""",
            params + [offset, per_page],
        )
        rows = [_serialize(r) for r in _dict_rows(cur)]
        return JSONResponse(content={
            "success": True, "data": rows, "total": total,
            "page": page, "per_page": per_page,
            "pages": max(1, (total + per_page - 1) // per_page),
        })
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.post("/password-resets/{request_id}/approve")
async def approve_reset(request: Request, request_id: str):
    admin = _master_admin(request)
    result = approve_password_reset(request_id, admin)
    if result.get("success"):
        log_admin_action(
            admin_username=admin, action="approve_password_reset",
            target_type="password_reset", target_id=request_id,
            description="تأیید درخواست بازیابی رمز عبور", ip_address=_client_ip(request),
        )
    return JSONResponse(content=result)


@router.post("/password-resets/{request_id}/reject")
async def reject_reset(request: Request, request_id: str):
    admin = _master_admin(request)
    ok = reject_password_reset(request_id, admin)
    if ok:
        log_admin_action(
            admin_username=admin, action="reject_password_reset",
            target_type="password_reset", target_id=request_id,
            description="رد درخواست بازیابی رمز عبور", ip_address=_client_ip(request),
        )
    return JSONResponse(content={"success": ok})


# ══════════════════════════════════════════════════════════════
# SECURITY EVENTS
# ══════════════════════════════════════════════════════════════

@router.get("/security")
async def list_security_events(
    request: Request,
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
    severity: Optional[str] = None,
    status: Optional[str] = None,
):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        where, params = [], []
        if severity:
            where.append("severity = ?"); params.append(severity)
        if status:
            where.append("status = ?"); params.append(status)
        clause = (" WHERE " + " AND ".join(where)) if where else ""

        cur.execute(f"SELECT COUNT(*) FROM security_events{clause}", params)
        total = cur.fetchone()[0]

        offset = (page - 1) * per_page
        cur.execute(
            f"SELECT * FROM security_events{clause} ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
            params + [offset, per_page],
        )
        rows = [_serialize(r) for r in _dict_rows(cur)]
        return JSONResponse(content={
            "success": True, "data": rows, "total": total,
            "page": page, "per_page": per_page,
            "pages": max(1, (total + per_page - 1) // per_page),
        })
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.post("/security/{event_id}/resolve")
async def resolve_security_event(request: Request, event_id: str):
    admin = _master_admin(request)
    data = await request.json() if request.headers.get("content-type", "").startswith("application/json") else {}
    new_status = data.get("status", "resolved")
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute(
            "UPDATE security_events SET status=?, resolved_by=?, resolved_at=SYSUTCDATETIME() WHERE event_id=?",
            (new_status, admin, event_id),
        )
        conn.commit()
        log_admin_action(
            admin_username=admin, action="resolve_security_event",
            target_type="security_event", target_id=event_id,
            description=f"تغییر وضعیت به {new_status}", ip_address=_client_ip(request),
        )
        return JSONResponse(content={"success": True})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# SYSTEM ERRORS
# ══════════════════════════════════════════════════════════════

@router.get("/errors")
async def list_errors(
    request: Request,
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
    severity: Optional[str] = None,
    status: Optional[str] = None,
    error_type: Optional[str] = None,
):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        where, params = [], []
        if severity:
            where.append("severity = ?"); params.append(severity)
        if status:
            where.append("status = ?"); params.append(status)
        if error_type:
            where.append("error_type = ?"); params.append(error_type)
        clause = (" WHERE " + " AND ".join(where)) if where else ""

        cur.execute(f"SELECT COUNT(*) FROM system_errors{clause}", params)
        total = cur.fetchone()[0]

        offset = (page - 1) * per_page
        cur.execute(
            f"SELECT * FROM system_errors{clause} ORDER BY first_seen DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
            params + [offset, per_page],
        )
        rows = [_serialize(r) for r in _dict_rows(cur)]
        return JSONResponse(content={
            "success": True, "data": rows, "total": total,
            "page": page, "per_page": per_page,
            "pages": max(1, (total + per_page - 1) // per_page),
        })
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.post("/errors/{error_id}/resolve")
async def resolve_error(request: Request, error_id: str):
    admin = _master_admin(request)
    data = await request.json() if request.headers.get("content-type", "").startswith("application/json") else {}
    new_status = data.get("status", "resolved")
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute(
            "UPDATE system_errors SET status=?, resolved_by=?, resolved_at=SYSUTCDATETIME() WHERE error_id=?",
            (new_status, admin, error_id),
        )
        conn.commit()
        log_admin_action(
            admin_username=admin, action="resolve_error",
            target_type="system_error", target_id=error_id,
            description=f"تغییر وضعیت خطا به {new_status}", ip_address=_client_ip(request),
        )
        return JSONResponse(content={"success": True})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# ADMIN ACTIONS LOG
# ══════════════════════════════════════════════════════════════

@router.get("/admin-actions")
async def list_admin_actions(
    request: Request,
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
    admin_username: Optional[str] = None,
    action: Optional[str] = None,
):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        where, params = [], []
        if admin_username:
            where.append("admin_username LIKE ?"); params.append(f"%{admin_username}%")
        if action:
            where.append("action = ?"); params.append(action)
        clause = (" WHERE " + " AND ".join(where)) if where else ""

        cur.execute(f"SELECT COUNT(*) FROM admin_actions{clause}", params)
        total = cur.fetchone()[0]

        offset = (page - 1) * per_page
        cur.execute(
            f"SELECT * FROM admin_actions{clause} ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
            params + [offset, per_page],
        )
        rows = [_serialize(r) for r in _dict_rows(cur)]
        return JSONResponse(content={
            "success": True, "data": rows, "total": total,
            "page": page, "per_page": per_page,
            "pages": max(1, (total + per_page - 1) // per_page),
        })
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# SYSTEM HEALTH
# ══════════════════════════════════════════════════════════════

@router.get("/system-health")
async def system_health(request: Request):
    _master_admin(request)
    health = {}
    # Database
    try:
        conn = db_connect()
        cur = conn.cursor()
        start = datetime.now(timezone.utc)
        cur.execute("SELECT 1")
        cur.fetchone()
        latency = (datetime.now(timezone.utc) - start).total_seconds() * 1000
        health["database"] = {"status": "healthy", "latency_ms": round(latency, 1)}
        conn.close()
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        health["database"] = {"status": "error", "message": "خطای اتصال به پایگاه داده"}

    # Session count
    try:
        conn = db_connect()
        cur = conn.cursor()
        cur.execute("SELECT COUNT(*) FROM user_sessions WHERE is_active = 1")
        health["active_sessions"] = cur.fetchone()[0]
        conn.close()
    except Exception:
        health["active_sessions"] = -1

    # Audit log count (today)
    try:
        conn = db_connect()
        cur = conn.cursor()
        today = datetime.now(timezone.utc).replace(hour=0, minute=0, second=0, microsecond=0)
        cur.execute("SELECT COUNT(*) FROM audit_logs WHERE created_at >= ?", (today,))
        health["audit_events_today"] = cur.fetchone()[0]
        conn.close()
    except Exception:
        health["audit_events_today"] = -1

    health["server_time_utc"] = datetime.now(timezone.utc).isoformat()
    return JSONResponse(content={"success": True, "data": health})


# ══════════════════════════════════════════════════════════════
# GLOBAL SEARCH
# ══════════════════════════════════════════════════════════════

@router.get("/search")
async def global_search(request: Request, q: str = Query("")):
    _master_admin(request)
    if not q.strip():
        return JSONResponse(content={"success": True, "results": []})

    query = f"%{q.strip()}%"
    conn = db_connect()
    results = []
    try:
        cur = conn.cursor()

        # Users
        cur.execute(
            "SELECT TOP 10 username, name, last_name, department, role FROM user_table WHERE username LIKE ? OR name LIKE ? OR last_name LIKE ?",
            (query, query, query),
        )
        for r in cur.fetchall():
            results.append({"type": "user", "title": f"{r[0]} — {r[1]} {r[2]}", "subtitle": f"{r[3]} | {r[4]}", "link": f"/master-admin/users/{r[0].strip()}"})

        # Audit logs
        cur.execute(
            "SELECT TOP 10 event_id, action, username, module FROM audit_logs WHERE event_id LIKE ? OR username LIKE ? ORDER BY created_at DESC",
            (query, query),
        )
        for r in cur.fetchall():
            results.append({"type": "audit", "title": f"{r[0]} — {r[1]}", "subtitle": f"{r[2]} | {r[3]}", "link": f"/master-admin/audit-logs?search={q}"})

        # Security events
        cur.execute(
            "SELECT TOP 10 event_id, event_type, description FROM security_events WHERE event_id LIKE ? OR description LIKE ? ORDER BY created_at DESC",
            (query, query),
        )
        for r in cur.fetchall():
            results.append({"type": "security", "title": f"{r[0]} — {r[1]}", "subtitle": r[2][:100], "link": f"/master-admin/security"})

        # Errors
        cur.execute(
            "SELECT TOP 10 error_id, message, severity FROM system_errors WHERE error_id LIKE ? OR message LIKE ? ORDER BY first_seen DESC",
            (query, query),
        )
        for r in cur.fetchall():
            results.append({"type": "error", "title": f"{r[0]} — {r[2]}", "subtitle": r[1][:100], "link": f"/master-admin/errors"})

        return JSONResponse(content={"success": True, "results": results})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# TICKET MANAGEMENT
# ══════════════════════════════════════════════════════════════

@router.get("/tickets")
async def list_all_tickets(
    request: Request,
    page: int = Query(1, ge=1),
    per_page: int = Query(20, ge=5, le=100),
    search: str = Query("", max_length=100),
    status: str = Query("", max_length=32),
    priority: str = Query("", max_length=16),
    assignee: str = Query("", max_length=255),
    sort: str = Query("newest", max_length=16),
):
    _master_admin(request)
    from app.services.ticketing import TicketService, TICKET_STATUSES, TICKET_PRIORITIES, STATUS_LABELS, PRIORITY_LABELS
    service = TicketService()
    try:
        result = service.list_tickets(
            actor="", is_admin=True, page=page, page_size=per_page,
            search=search, status=status, priority=priority,
            assignee=assignee, sort=sort,
        )
        return JSONResponse(content={"success": True, "data": result})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        service.close()


@router.get("/tickets/stats")
async def ticket_stats(request: Request):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        stats = {}
        cur.execute("SELECT COUNT(*) FROM tickets")
        stats["total"] = cur.fetchone()[0]
        cur.execute("SELECT COUNT(*) FROM tickets WHERE status NOT IN ('resolved','closed')")
        stats["open"] = cur.fetchone()[0]
        cur.execute("SELECT status, COUNT(*) count FROM tickets GROUP BY status")
        stats["by_status"] = {row[0]: row[1] for row in cur.fetchall()}
        cur.execute("SELECT priority, COUNT(*) count FROM tickets GROUP BY priority")
        stats["by_priority"] = {row[0]: row[1] for row in cur.fetchall()}
        return JSONResponse(content={"success": True, "data": stats})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.get("/tickets/{ticket_id}")
async def get_ticket_detail(ticket_id: int, request: Request):
    _master_admin(request)
    from app.services.ticketing import TicketService
    service = TicketService()
    try:
        ticket = service.get_ticket(ticket_id, actor="", is_admin=True)
        if ticket is None:
            raise HTTPException(status_code=404, detail="تیکت پیدا نشد.")
        return JSONResponse(content={"success": True, "data": ticket})
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        service.close()


@router.patch("/tickets/{ticket_id}")
async def update_ticket_admin(ticket_id: int, request: Request):
    _master_admin(request)
    from app.services.ticketing import TicketService
    data = await request.json()
    service = TicketService()
    try:
        result = service.update_ticket(
            ticket_id,
            actor=str(request.session.get("username") or "").strip(),
            is_admin=True,
            status=data.get("status"),
            priority=data.get("priority"),
            category_id=data.get("category_id"),
            assigned_to=data.get("assigned_to"),
        )
        if result is None:
            raise HTTPException(status_code=404, detail="تیکت پیدا نشد.")
        return JSONResponse(content={"success": True, "data": result})
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        service.close()


@router.post("/tickets/{ticket_id}/reply")
async def reply_ticket_admin(ticket_id: int, request: Request):
    _master_admin(request)
    from app.services.ticketing import TicketService
    data = await request.json()
    body = str(data.get("body") or "").strip()
    visibility = str(data.get("visibility") or "public").strip()
    if not body:
        raise HTTPException(status_code=400, detail="متن پیام الزامی است.")
    if visibility not in ("public", "internal"):
        raise HTTPException(status_code=400, detail="نوع پیام معتبر نیست.")
    service = TicketService()
    try:
        result = service.add_message(
            ticket_id,
            actor=str(request.session.get("username") or "").strip(),
            is_admin=True,
            body=body,
            visibility=visibility,
        )
        return JSONResponse(content={"success": True, "data": result})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        service.close()


@router.delete("/tickets/{ticket_id}")
async def delete_ticket_admin(ticket_id: int, request: Request):
    _master_admin(request)
    admin = str(request.session.get("username") or "").strip()
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("SELECT id, subject FROM tickets WHERE id=?", (ticket_id,))
        row = cur.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="تیکت پیدا نشد.")
        cur.execute("DELETE FROM ticket_messages WHERE ticket_id=?", (ticket_id,))
        cur.execute("DELETE FROM ticket_events WHERE ticket_id=?", (ticket_id,))
        cur.execute("DELETE FROM ticket_attachments WHERE ticket_id=?", (ticket_id,))
        cur.execute("DELETE FROM ticket_tag_relations WHERE ticket_id=?", (ticket_id,))
        cur.execute("DELETE FROM tickets WHERE id=?", (ticket_id,))
        conn.commit()
        log_admin_action(
            admin_username=admin, action="delete_ticket",
            target_type="ticket", target_id=str(ticket_id),
            description=f"حذف تیکت: {row[1]}", ip_address=_client_ip(request),
        )
        return JSONResponse(content={"success": True})
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.get("/tickets/categories/all")
async def ticket_categories_admin(request: Request):
    _master_admin(request)
    from app.services.ticketing import TicketService
    service = TicketService()
    try:
        return JSONResponse(content={"success": True, "data": service.categories()})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        service.close()


@router.get("/tickets/users/all")
async def ticket_users_admin(request: Request):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute(
            """SELECT LTRIM(RTRIM(username)) username,
                      LTRIM(RTRIM(COALESCE(name,''))) name,
                      LTRIM(RTRIM(COALESCE(last_name,''))) last_name,
                      LTRIM(RTRIM(COALESCE(department,''))) department
               FROM user_table ORDER BY name, username"""
        )
        users = []
        for row in cur.fetchall():
            username = str(row[0] or "").strip()
            users.append({
                "username": username,
                "name": " ".join(str(v or "").strip() for v in row[1:3]).strip(),
                "department": str(row[3] or "").strip(),
            })
        return JSONResponse(content={"success": True, "data": users})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# SYSTEM CONFIG
# ══════════════════════════════════════════════════════════════

@router.get("/config")
async def get_config(request: Request):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("SELECT config_key, config_value, description, updated_by, updated_at FROM system_config")
        rows = [_serialize(r) for r in _dict_rows(cur)]
        return JSONResponse(content={"success": True, "data": rows})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.post("/config")
async def update_config(request: Request):
    admin = _master_admin(request)
    data = await request.json()
    key = data.get("key", "")
    value = data.get("value", "")
    if not key:
        raise HTTPException(status_code=400, detail="کلید الزامی است.")
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute(
            "UPDATE system_config SET config_value=?, updated_by=?, updated_at=SYSUTCDATETIME() WHERE config_key=?",
            (value, admin, key),
        )
        conn.commit()
        log_admin_action(
            admin_username=admin, action="update_config",
            target_type="system_config", target_id=key,
            description=f"تغییر تنظیم {key} به {value}", ip_address=_client_ip(request),
        )
        return JSONResponse(content={"success": True})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()
