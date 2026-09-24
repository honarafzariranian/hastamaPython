"""Hastama Master Administration & Control Center — API Routes.

Every route in this module requires a **master administrator** session
(``is_master_admin`` is only set by ``/login_user`` for usernames listed in
``MASTER_ADMIN_USERNAMES``).

Previously the helper accepted any ``is_admin`` session.  Because
``POST /password-resets/{id}/approve`` returns the one-time recovery code to
its caller, that made full account takeover — including takeover of the master
administrator account — a single request for any ordinary admin.  The same flag
also allowed role changes, account disabling, session termination, audit-log
reads and system-config writes, i.e. a complete vertical privilege escalation.
"""
from __future__ import annotations

import json
import logging
from datetime import date, datetime, timezone, timedelta
from decimal import Decimal
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
    """Return the master-admin username or raise 401/403.

    Only ``is_master_admin`` is accepted — a regular ``is_admin`` session must
    not be able to reach this control plane (see module docstring).
    """
    username = str(request.session.get("username") or "").strip()
    if not username:
        raise HTTPException(status_code=401, detail="ورود لازم است.")
    if request.session.get("is_master_admin") is not True:
        _log_denied_master_admin(request, username)
        raise HTTPException(status_code=403, detail="دسترسی مدیریت اصلی لازم است.")
    return username


def _log_denied_master_admin(request: Request, username: str) -> None:
    """Audit every rejected attempt to use the master-admin control plane."""
    try:
        from app.services.audit import create_security_event

        create_security_event(
            event_type="authorization",
            severity="high",
            username=username,
            ip_address=_client_ip(request),
            description="non-master admin attempted to access master-admin API",
            metadata={"path": str(request.url.path), "method": request.method},
        )
    except Exception:
        pass


def _client_ip(request: Request) -> str:
    """Trustworthy client address (validated ``X-Forwarded-For``)."""
    from app.core.net import client_ip

    return client_ip(request)


def _user_agent(request: Request) -> str:
    from app.core.net import user_agent

    return user_agent(request)


def _dict_rows(cursor) -> list[dict]:
    columns = [c[0] for c in cursor.description]
    return [dict(zip(columns, row)) for row in cursor.fetchall()]


def _serialize(row: dict) -> dict:
    for key in row:
        if isinstance(row[key], datetime):
            row[key] = row[key].isoformat()
        elif isinstance(row[key], date):
            row[key] = row[key].isoformat()
        elif isinstance(row[key], Decimal):
            row[key] = float(row[key])
        elif isinstance(row[key], bytes):
            row[key] = row[key].hex() if row[key] else None
    return row


def _subscription_table_ready(cur) -> bool:
    """Return whether the optional subscriptions migration has been installed."""
    cur.execute("SELECT OBJECT_ID(N'dbo.customer_subscriptions', N'U')")
    return cur.fetchone()[0] is not None


def _subscription_empty(*, setup_needed: bool = True) -> JSONResponse:
    return JSONResponse(content={
        "success": True,
        "setup_needed": setup_needed,
        "data": [],
        "total": 0,
        "page": 1,
        "per_page": 0,
        "pages": 1,
    })


def _subscription_value(value):
    if isinstance(value, datetime):
        return value.date()
    if isinstance(value, date):
        return value
    if isinstance(value, str):
        try:
            return datetime.fromisoformat(value.replace("Z", "+00:00")).date()
        except ValueError:
            return None
    return None


def _subscription_status(row: dict, today: date) -> tuple[str, int | None]:
    start = _subscription_value(row.get("starts_at"))
    expires = _subscription_value(row.get("expires_at"))
    if not expires:
        return "unknown", None
    remaining = (expires - today).days
    if start and today < start:
        return "upcoming", remaining
    if remaining < 0:
        return "expired", remaining
    return "active", remaining


def _subscription_seat_usage(cur, row: dict, user_columns: set[str] | None = None) -> int:
    """Best-effort active-user count for installations that link users to customers."""
    try:
        if user_columns is None:
            cur.execute(
                "SELECT LOWER(COLUMN_NAME) FROM INFORMATION_SCHEMA.COLUMNS "
                "WHERE TABLE_NAME = 'user_table'"
            )
            user_columns = {str(r[0]).lower() for r in cur.fetchall()}
        identity_columns = [
            c for c in ("customer_code", "customer_id", "customer_name", "email", "phone")
            if c in user_columns
        ]
        value = str(row.get("customer_code") or row.get("customer_id") or "").strip()
        if not identity_columns or not value:
            return 0
        predicates = " OR ".join("LTRIM(RTRIM(COALESCE({0}, ''))) = ?".format(c) for c in identity_columns)
        params = [value] * len(identity_columns)
        active = " AND ISNULL(is_active, 'active') = 'active'" if "is_active" in user_columns else ""
        cur.execute("SELECT COUNT(*) FROM user_table WHERE ({0}){1}".format(predicates, active), params)
        return int(cur.fetchone()[0] or 0)
    except Exception:
        return 0


def _subscription_row(cur, row: dict, today: date, user_columns: set[str] | None = None) -> dict:
    status, remaining = _subscription_status(row, today)
    row["computed_status"] = status
    row["status"] = status
    row["remaining_days"] = remaining
    row["seats_used"] = _subscription_seat_usage(cur, row, user_columns)
    return _serialize(row)


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
# CUSTOMER SUBSCRIPTIONS (optional migration)
# ══════════════════════════════════════════════════════════════

_SUBSCRIPTION_COLUMNS = """id, customer_id, customer_code, customer_name, contact_name,
    contact_email, contact_phone, plan_name, subscription_status, purchased_at, starts_at,
    expires_at, max_users, price, currency, payment_method, payment_reference,
    invoice_number, notes, created_at, updated_at"""


@router.get("/subscriptions/summary")
async def subscriptions_summary(request: Request):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        if not _subscription_table_ready(cur):
            return JSONResponse(content={
                "success": True, "setup_needed": True,
                "data": {
                    "total_customers": 0, "active_subscriptions": 0,
                    "expiring_soon": 0, "total_seats_used": 0, "total_seats": 0,
                },
            })
        cur.execute("SELECT " + _SUBSCRIPTION_COLUMNS + " FROM dbo.customer_subscriptions")
        today = datetime.now(timezone.utc).date()
        rows = _dict_rows(cur)
        prepared = [_subscription_row(cur, row, today) for row in rows]
        active = [r for r in prepared if r["status"] == "active"]
        return JSONResponse(content={
            "success": True, "setup_needed": False,
            "data": {
                "total_customers": len({r.get("customer_code") or r.get("customer_id") or r.get("customer_name") for r in prepared}),
                "active_subscriptions": len(active),
                "expiring_soon": sum(0 <= (r.get("remaining_days") or -1) <= 30 for r in active),
                "total_seats_used": sum(r.get("seats_used") or 0 for r in prepared),
                "total_seats": sum(r.get("max_users") or 0 for r in prepared),
            },
        })
    except Exception as e:
        logger.error("Error loading subscription summary: %s: %s", type(e).__name__, e)
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.get("/subscriptions")
async def list_subscriptions(
    request: Request,
    page: int = Query(1, ge=1),
    per_page: int = Query(50, ge=1, le=200),
    search: Optional[str] = None,
    status: Optional[str] = None,
):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        if not _subscription_table_ready(cur):
            return _subscription_empty()
        cur.execute("SELECT " + _SUBSCRIPTION_COLUMNS + " FROM dbo.customer_subscriptions ORDER BY expires_at DESC, id DESC")
        today = datetime.now(timezone.utc).date()
        rows = [_subscription_row(cur, row, today) for row in _dict_rows(cur)]
        if search:
            needle = search.casefold()
            rows = [r for r in rows if needle in " ".join(
                str(r.get(k) or "") for k in ("customer_name", "customer_code", "customer_id", "plan_name", "contact_email")
            ).casefold()]
        if status:
            normalized_status = status.casefold()
            if normalized_status == "expiring":
                rows = [
                    r for r in rows
                    if r["status"] == "active"
                    and 0 <= (r.get("remaining_days") or -1) <= 30
                ]
            else:
                rows = [r for r in rows if r["status"].casefold() == normalized_status]
        total = len(rows)
        offset = (page - 1) * per_page
        rows = rows[offset:offset + per_page]
        return JSONResponse(content={
            "success": True, "setup_needed": False, "data": rows, "total": total,
            "page": page, "per_page": per_page,
            "pages": max(1, (total + per_page - 1) // per_page),
        })
    except Exception as e:
        logger.error("Error loading subscriptions: %s: %s", type(e).__name__, e)
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.get("/subscriptions/{subscription_id}")
async def get_subscription_detail(request: Request, subscription_id: int):
    _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        if not _subscription_table_ready(cur):
            return JSONResponse(content={"success": True, "setup_needed": True, "data": None})
        cur.execute(
            "SELECT " + _SUBSCRIPTION_COLUMNS +
            " FROM dbo.customer_subscriptions WHERE id = ?", (subscription_id,)
        )
        row = cur.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="اشتراک یافت نشد.")
        data = _subscription_row(cur, dict(zip([c[0] for c in cur.description], row)),
                                 datetime.now(timezone.utc).date())
        try:
            cur.execute(
                """SELECT id, username, name, last_name, department, role, is_active, last_login
                   FROM user_table WHERE LTRIM(RTRIM(customer_id)) = ?
                   ORDER BY name, last_name, username""",
                (str(data.get("customer_id") or "").strip(),),
            )
            data["users"] = [_serialize(user) for user in _dict_rows(cur)]
        except Exception:
            data["users"] = []
        return JSONResponse(content={"success": True, "setup_needed": False, "data": data})
    except HTTPException:
        raise
    except Exception as e:
        logger.error("Error loading subscription detail: %s: %s", type(e).__name__, e)
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


@router.patch("/subscriptions/{subscription_id}")
async def update_subscription(request: Request, subscription_id: int):
    _master_admin(request)
    data = await request.json()
    allowed = {
        "customer_id", "customer_code", "customer_name", "contact_name", "contact_email",
        "contact_phone", "plan_name", "subscription_status", "starts_at", "expires_at",
        "max_users", "payment_method", "payment_reference",
        "invoice_number", "notes",
    }
    updates = {key: data[key] for key in allowed if key in data}
    if not updates:
        raise HTTPException(status_code=400, detail="اطلاعاتی برای ویرایش ارسال نشده است.")
    for key in ("customer_id", "customer_name", "plan_name", "starts_at", "expires_at"):
        if key in updates and not str(updates[key] or "").strip():
            raise HTTPException(status_code=400, detail=f"فیلد {key} الزامی است.")
    if "max_users" in updates:
        try:
            updates["max_users"] = int(updates["max_users"])
            if updates["max_users"] < 0:
                raise ValueError
        except (TypeError, ValueError):
            raise HTTPException(status_code=400, detail="ظرفیت کاربران معتبر نیست.")
    for key in ("starts_at", "expires_at"):
        if key in updates:
            try:
                updates[key] = date.fromisoformat(str(updates[key])[:10])
            except ValueError:
                raise HTTPException(status_code=400, detail="تاریخ واردشده معتبر نیست.")
    if "price" in updates and updates["price"] not in (None, ""):
        try:
            updates["price"] = Decimal(str(updates["price"]))
        except Exception:
            raise HTTPException(status_code=400, detail="مبلغ واردشده معتبر نیست.")
    if "payment_method" in updates and updates["payment_method"] not in {"cash", "check", "installment"}:
        raise HTTPException(status_code=400, detail="روش پرداخت معتبر نیست.")

    conn = db_connect()
    try:
        cur = conn.cursor()
        if not _subscription_table_ready(cur):
            raise HTTPException(status_code=409, detail="جدول مشتریان آماده نیست.")
        cur.execute(
            "SELECT customer_id, customer_name FROM dbo.customer_subscriptions WHERE id = ?",
            (subscription_id,),
        )
        previous = cur.fetchone()
        if not previous:
            raise HTTPException(status_code=404, detail="اشتراک یافت نشد.")
        assignments = ", ".join(f"{key} = ?" for key in updates)
        cur.execute(
            f"UPDATE dbo.customer_subscriptions SET {assignments}, updated_at = SYSUTCDATETIME() WHERE id = ?",
            list(updates.values()) + [subscription_id],
        )
        old_customer_id, old_customer_name = (str(previous[0] or "").strip(), str(previous[1] or "").strip())
        new_customer_id = str(updates.get("customer_id", old_customer_id)).strip()
        new_customer_name = str(updates.get("customer_name", old_customer_name)).strip()
        try:
            cur.execute(
                "UPDATE user_table SET customer_id = ?, customer_name = ? WHERE LTRIM(RTRIM(customer_id)) = ?",
                (new_customer_id, new_customer_name, old_customer_id),
            )
        except Exception:
            logger.warning("Could not synchronize subscription users for id %s", subscription_id)
        conn.commit()
        return JSONResponse(content={"success": True, "message": "اطلاعات مشتری ذخیره شد."})
    except HTTPException:
        conn.rollback()
        raise
    except Exception as e:
        conn.rollback()
        logger.error("Error updating subscription: %s: %s", type(e).__name__, e)
        return JSONResponse(content={"success": False, "message": "ذخیره اطلاعات مشتری ناموفق بود."}, status_code=500)
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
            f"""SELECT event_id, event_type, action, username, role, module, resource_type,
                       resource_id, request_id, session_id, ip_address, status, severity, created_at
                FROM audit_logs{clause} ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY""",
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
        cur.execute(
            """SELECT event_id, event_type, action, username, role, module, resource_type,
                      resource_id, request_id, session_id, ip_address, user_agent, status,
                      severity, before_data, after_data, metadata, error_id, created_at
               FROM audit_logs WHERE event_id = ?""",
            (event_id,),
        )
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


@router.delete("/audit-logs/{event_id}")
async def delete_audit_event(request: Request, event_id: str):
    admin = _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("DELETE FROM audit_logs WHERE event_id = ?", (event_id,))
        deleted = (cur.rowcount or 0) > 0
        conn.commit()
        if deleted:
            log_admin_action(
                admin_username=admin, action="delete_audit_log",
                target_type="audit_log", target_id=event_id,
                description="حذف رکورد لاگ حسابرسی", ip_address=_client_ip(request),
            )
        return JSONResponse(content={"success": deleted})
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

        # Recent audit events (explicit columns — never SELECT *)
        cur.execute(
            """SELECT TOP 50 event_id, event_type, action, module, resource_type,
                      resource_id, status, severity, ip_address, created_at
               FROM audit_logs WHERE username = ? ORDER BY created_at DESC""",
            (username.strip(),),
        )
        user_data["recent_audit"] = [_serialize(r) for r in _dict_rows(cur)]

        # Sessions (no session_key in the detail payload: the opaque key is only
        # needed by the session list where the terminate action lives)
        cur.execute(
            """SELECT TOP 20 id, username, ip_address, user_agent, login_at,
                      last_activity, logout_at, is_active, terminated_by
               FROM user_sessions WHERE username = ? ORDER BY login_at DESC""",
            (username.strip(),),
        )
        user_data["sessions"] = [_serialize(r) for r in _dict_rows(cur)]

        # Password reset history — deliberately excludes recovery_code so the
        # code digest is not exposed through the API.
        cur.execute(
            """SELECT TOP 10 request_id, username, ip_address, status, code_attempts,
                      max_attempts, approved_by, approved_at, completed_at, created_at
               FROM password_reset_requests WHERE username = ? ORDER BY created_at DESC""",
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
        # Disabling an account must also invalidate the sessions that account
        # already holds (a signed cookie cannot be revoked otherwise).
        revoked = 0
        if new_status != "active":
            from app.core.sessions import revoke_user_sessions

            revoked = revoke_user_sessions(username.strip(), admin)
        log_admin_action(
            admin_username=admin, action="toggle_user_status",
            target_username=username.strip(), target_type="user",
            description=f"تغییر وضعیت به {new_status}",
            before_data={"is_active": current}, after_data={"is_active": new_status, "sessions_revoked": revoked},
            ip_address=_client_ip(request),
        )
        return JSONResponse(content={"success": True, "new_status": new_status, "sessions_revoked": revoked})
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
        # A role change must not keep the previous privilege set alive inside
        # sessions that were issued for the old role.
        from app.core.sessions import revoke_user_sessions

        revoked = revoke_user_sessions(username.strip(), admin)
        log_admin_action(
            admin_username=admin, action="change_role",
            target_username=username.strip(), target_type="user",
            description=f"تغییر نقش از {old_role} به {new_role}",
            before_data={"role": old_role}, after_data={"role": new_role, "sessions_revoked": revoked},
            ip_address=_client_ip(request),
        )
        return JSONResponse(content={"success": True, "sessions_revoked": revoked})
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
            f"""SELECT id, session_key, username, ip_address, user_agent, login_at,
                       last_activity, logout_at, is_active, terminated_by
                FROM user_sessions{clause} ORDER BY login_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY""",
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


@router.delete("/sessions/{session_key}")
async def delete_user_session(request: Request, session_key: str):
    admin = _master_admin(request)
    from app.core.sessions import delete_session_record

    deleted = delete_session_record(session_key)
    if deleted:
        log_admin_action(
            admin_username=admin, action="delete_session_record",
            target_type="session", target_id=session_key,
            description="حذف رکورد نشست", ip_address=_client_ip(request),
        )
    return JSONResponse(content={"success": deleted})


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


@router.delete("/password-resets/{request_id}")
async def delete_password_reset(request: Request, request_id: str):
    admin = _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute(
            "DELETE FROM password_reset_requests WHERE request_id = ?",
            (request_id,),
        )
        deleted = (cur.rowcount or 0) > 0
        conn.commit()
        if deleted:
            log_admin_action(
                admin_username=admin, action="delete_password_reset",
                target_type="password_reset", target_id=request_id,
                description="حذف رکورد درخواست بازیابی رمز عبور",
                ip_address=_client_ip(request),
            )
        return JSONResponse(content={"success": deleted})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


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
            f"""SELECT event_id, event_type, severity, username, ip_address, description,
                       status, resolved_by, resolved_at, created_at
                FROM security_events{clause} ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY""",
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


@router.delete("/security/{event_id}")
async def delete_security_event(request: Request, event_id: str):
    admin = _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("DELETE FROM security_events WHERE event_id = ?", (event_id,))
        deleted = (cur.rowcount or 0) > 0
        conn.commit()
        if deleted:
            log_admin_action(
                admin_username=admin, action="delete_security_event",
                target_type="security_event", target_id=event_id,
                description="حذف رکورد رویداد امنیتی", ip_address=_client_ip(request),
            )
        return JSONResponse(content={"success": deleted})
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
            f"""SELECT error_id, error_type, severity, message, endpoint, method, username,
                       ip_address, occurrences, status, resolved_at, first_seen, last_seen
                FROM system_errors{clause} ORDER BY first_seen DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY""",
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


@router.delete("/errors/{error_id}")
async def delete_error(request: Request, error_id: str):
    admin = _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("DELETE FROM system_errors WHERE error_id = ?", (error_id,))
        deleted = (cur.rowcount or 0) > 0
        conn.commit()
        if deleted:
            log_admin_action(
                admin_username=admin, action="delete_system_error",
                target_type="system_error", target_id=error_id,
                description="حذف رکورد خطای سیستم", ip_address=_client_ip(request),
            )
        return JSONResponse(content={"success": deleted})
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
            f"""SELECT action_id, admin_username, action, target_username, target_type,
                       target_id, description, ip_address, created_at
                FROM admin_actions{clause} ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY""",
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


@router.delete("/admin-actions/{action_id}")
async def delete_admin_action(request: Request, action_id: str):
    admin = _master_admin(request)
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("DELETE FROM admin_actions WHERE action_id = ?", (action_id,))
        deleted = (cur.rowcount or 0) > 0
        conn.commit()
        if deleted:
            log_admin_action(
                admin_username=admin, action="delete_admin_action",
                target_type="admin_action", target_id=action_id,
                description="حذف رکورد عملیات مدیریتی", ip_address=_client_ip(request),
            )
        return JSONResponse(content={"success": deleted})
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
            assignee=assignee, sort=sort, is_master_admin=True,
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
    allowed_keys = {
        "captcha_enabled",
        "idle_timeout_enabled",
        "idle_timeout_seconds",
        "label_target_printer",
    }
    if key not in allowed_keys:
        raise HTTPException(status_code=400, detail="کلید تنظیم مجاز نیست.")
    conn = db_connect()
    try:
        cur = conn.cursor()
        # UPSERT: first write creates the row (e.g. label_target_printer before seed).
        cur.execute(
            """UPDATE system_config SET config_value=?, updated_by=?, updated_at=SYSUTCDATETIME()
               WHERE config_key=?""",
            (value, admin, key),
        )
        if cur.rowcount == 0:
            cur.execute(
                """INSERT INTO system_config (config_key, config_value, description, updated_by, updated_at)
                   VALUES (?, ?, ?, ?, SYSUTCDATETIME())""",
                (
                    key,
                    value,
                    {
                        "label_target_printer": "نام چاپگر انتخابی برای چاپ لیبل و بلیت نوبت",
                    }.get(key, ""),
                    admin,
                ),
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


# ── Label printer discovery ───────────────────────────────────

@router.get("/printers")
async def get_printers(request: Request, refresh: bool = Query(False)):
    """List the printer queues visible to the server's spooler.

    The label studio cannot use WebUSB/WebSerial here: the LAN build is served
    over plain HTTP (no secure context) and the label printer is usually a
    *network* queue, which those APIs never expose.  The spooler of the machine
    running the server is the source of truth — see ``app/services/printer.py``.
    """
    _master_admin(request)
    from app.services.printer import describe_printers
    try:
        data = describe_printers(force=refresh)
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(
            content={"success": False, "message": "خواندن فهرست چاپگرهای سرور ناموفق بود."},
            status_code=500,
        )
    return JSONResponse(content={"success": True, "data": data})
