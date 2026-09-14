"""Hastama User Self-Registration & Approval API Routes.

Public endpoints for registration request submission.
Admin endpoints for approval/rejection workflow.
"""
from __future__ import annotations

import re
import logging
from datetime import datetime, timezone
from typing import Optional

from fastapi import APIRouter, HTTPException, Query, Request
from fastapi.responses import JSONResponse

from app.core.database import connect as db_connect
from app.services.audit import log_event, log_admin_action, generate_event_id

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/registration", tags=["registration"])


# ── Helpers ───────────────────────────────────────────────────
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


# ── Validation ────────────────────────────────────────────────
def _validate_national_id(nid: str) -> bool:
    """Validate Iranian national ID (10 digits)."""
    nid = nid.strip()
    if len(nid) != 10 or not nid.isdigit():
        return False
    # Basic checksum validation
    check = int(nid[9])
    s = sum(int(nid[i]) * (10 - i) for i in range(9))
    r = s % 11
    if r < 2:
        return check == r
    return check == 11 - r

def _validate_mobile(mobile: str) -> bool:
    """Validate Iranian mobile number."""
    mobile = mobile.strip().replace(" ", "").replace("-", "")
    # Accept 09xxxxxxxxx or +989xxxxxxxxx or 989xxxxxxxxx
    if re.match(r'^09\d{9}$', mobile):
        return True
    if re.match(r'^\+989\d{9}$', mobile):
        return True
    if re.match(r'^989\d{9}$', mobile):
        return True
    return False

def _normalize_mobile(mobile: str) -> str:
    mobile = mobile.strip().replace(" ", "").replace("-", "")
    if mobile.startswith("+98"):
        mobile = "0" + mobile[3:]
    elif mobile.startswith("98"):
        mobile = "0" + mobile[2:]
    return mobile

def _validate_username(username: str) -> bool:
    """Username: 3-30 chars, alphanumeric + underscore, no spaces."""
    return bool(re.match(r'^[a-zA-Z0-9_]{3,30}$', username))


# ══════════════════════════════════════════════════════════════
# PUBLIC — Check username availability
# ══════════════════════════════════════════════════════════════
@router.get("/check-username")
async def check_username(q: str = Query("")):
    username = q.strip()
    if not username or len(username) < 3:
        return JSONResponse(content={"available": False, "message": "نام کاربری باید حداقل ۳ کاراکتر باشد."})

    if not _validate_username(username):
        return JSONResponse(content={"available": False, "message": "نام کاربری فقط شامل حروف انگلیسی، اعداد و زیرخط باشد."})

    conn = db_connect()
    try:
        cur = conn.cursor()
        # Check existing users
        cur.execute("SELECT 1 FROM user_table WHERE LTRIM(RTRIM(username)) = ?", (username,))
        if cur.fetchone():
            return JSONResponse(content={"available": False, "message": "این نام کاربری قبلاً استفاده شده است."})

        # Check pending requests
        cur.execute("SELECT 1 FROM user_registration_requests WHERE username = ? AND status = 'pending'", (username,))
        if cur.fetchone():
            return JSONResponse(content={"available": False, "message": "این نام کاربری قبلاً درخواست داده شده و در انتظار تأیید است."})

        return JSONResponse(content={"available": True, "message": "نام کاربری قابل استفاده است."})
    except Exception as e:
        return JSONResponse(content={"available": False, "message": "خطا در بررسی."})
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# PUBLIC — Check national ID availability
# ══════════════════════════════════════════════════════════════
@router.get("/check-national-id")
async def check_national_id(q: str = Query("")):
    nid = q.strip().replace(" ", "")
    if not nid:
        return JSONResponse(content={"available": True})

    if not _validate_national_id(nid):
        return JSONResponse(content={"available": False, "message": "شماره ملی معتبر نیست."})

    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("SELECT 1 FROM user_registration_requests WHERE national_id = ? AND status = 'pending'", (nid,))
        if cur.fetchone():
            return JSONResponse(content={"available": False, "message": "این شماره ملی قبلاً درخواست ثبت نام داده است."})
        return JSONResponse(content={"available": True})
    except Exception:
        return JSONResponse(content={"available": True})
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# PUBLIC — Submit registration request
# ══════════════════════════════════════════════════════════════
@router.post("/submit")
async def submit_registration(request: Request):
    data = await request.json()

    # Extract fields
    first_name = str(data.get("first_name") or "").strip()
    last_name = str(data.get("last_name") or "").strip()
    father_name = str(data.get("father_name") or "").strip()
    national_id = str(data.get("national_id") or "").strip().replace(" ", "")
    mobile = str(data.get("mobile") or "").strip()
    username = str(data.get("username") or "").strip()
    password = str(data.get("password") or "")
    department = str(data.get("department") or "").strip()
    work_hours = str(data.get("work_hours") or "").strip()
    substitute = str(data.get("substitute") or "").strip()

    # ── Validate required fields ──
    errors = []
    if not first_name:
        errors.append("نام الزامی است.")
    if not last_name:
        errors.append("نام خانوادگی الزامی است.")
    if not username:
        errors.append("نام کاربری الزامی است.")
    elif not _validate_username(username):
        errors.append("نام کاربری فقط شامل حروف انگلیسی، اعداد و زیرخط باشد (۳ تا ۳۰ کاراکتر).")
    if not password:
        errors.append("رمز عبور الزامی است.")
    elif len(password) < 8:
        errors.append("رمز عبور باید حداقل ۸ کاراکتر باشد.")
    if national_id and not _validate_national_id(national_id):
        errors.append("شماره ملی معتبر نیست.")
    if mobile and not _validate_mobile(mobile):
        errors.append("شماره همراه معتبر نیست.")
    if not department:
        errors.append("بخش فعالیت الزامی است.")

    if errors:
        return JSONResponse(content={"success": False, "errors": errors}, status_code=400)

    # Normalize mobile
    if mobile:
        mobile = _normalize_mobile(mobile)

    # ── Check duplicates ──
    from core.password_utils import hash_password
    password_hash = hash_password(password)

    conn = db_connect()
    try:
        cur = conn.cursor()

        # Check username in existing users
        cur.execute("SELECT 1 FROM user_table WHERE LTRIM(RTRIM(username)) = ?", (username,))
        if cur.fetchone():
            return JSONResponse(content={"success": False, "errors": ["نام کاربری قبلاً استفاده شده است."]}, status_code=400)

        # Check username in pending requests
        cur.execute("SELECT 1 FROM user_registration_requests WHERE username = ? AND status = 'pending'", (username,))
        if cur.fetchone():
            return JSONResponse(content={"success": False, "errors": ["نام کاربری قبلاً درخواست داده شده."]}, status_code=400)

        # Check national ID
        if national_id:
            cur.execute("SELECT 1 FROM user_registration_requests WHERE national_id = ? AND status = 'pending'", (national_id,))
            if cur.fetchone():
                return JSONResponse(content={"success": False, "errors": ["شماره ملی قبلاً درخواست ثبت نام داده است."]}, status_code=400)

        # Check mobile
        if mobile:
            cur.execute("SELECT 1 FROM user_registration_requests WHERE mobile = ? AND status = 'pending'", (mobile,))
            if cur.fetchone():
                return JSONResponse(content={"success": False, "errors": ["شماره همراه قبلاً درخواست ثبت نام داده است."]}, status_code=400)

        # Create request
        request_id = generate_event_id()
        cur.execute("""
            INSERT INTO user_registration_requests
            (request_id, first_name, last_name, father_name, national_id, mobile,
             username, password_hash, department, work_hours, substitute,
             created_ip, created_user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """, (
            request_id, first_name, last_name,
            father_name if father_name else None,
            national_id if national_id else None,
            mobile if mobile else None,
            username, password_hash, department,
            work_hours if work_hours else None,
            substitute if substitute else None,
            _client_ip(request), _user_agent(request),
        ))
        conn.commit()

        # Audit
        log_event(
            event_type="USER", action="registration_request",
            username=username, module="registration",
            status="success", severity="info",
            ip_address=_client_ip(request), user_agent=_user_agent(request),
            metadata={"request_id": request_id, "department": department},
        )

        return JSONResponse(content={
            "success": True,
            "message": "درخواست شما با موفقیت ثبت شد.\nپس از بررسی مدیر مجموعه، حساب شما فعال خواهد شد.",
            "request_id": request_id,
        })
    except Exception as e:
        return JSONResponse(content={"success": False, "errors": ["خطا در ثبت درخواست. لطفاً دوباره تلاش کنید."]}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# PUBLIC — Check request status
# ══════════════════════════════════════════════════════════════
@router.get("/status/{request_id}")
async def check_request_status(request_id: str):
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute(
            "SELECT request_id, username, status, rejection_reason, reviewed_at FROM user_registration_requests WHERE request_id = ?",
            (request_id.strip(),),
        )
        row = cur.fetchone()
        if not row:
            return JSONResponse(content={"found": False, "message": "درخواست یافت نشد."})
        columns = [c[0] for c in cur.description]
        data = dict(zip(columns, row))
        return JSONResponse(content={"found": True, "data": _serialize(data)})
    except Exception:
        return JSONResponse(content={"found": False, "message": "خطا در بررسی."})
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# ADMIN — List pending registration requests
# ══════════════════════════════════════════════════════════════
@router.get("/admin/requests")
async def list_registration_requests(
    request: Request,
    status: str = Query(""),
    search: str = Query(""),
    page: int = Query(1, ge=1),
    per_page: int = Query(25, ge=1, le=100),
):
    # Auth check
    username = request.session.get("username")
    is_admin = request.session.get("is_admin") is True
    if not username or not is_admin:
        raise HTTPException(status_code=403, detail="دسترسی مدیریت لازم است.")

    conn = db_connect()
    try:
        cur = conn.cursor()

        where_clauses = []
        params = []

        if status:
            where_clauses.append("status = ?")
            params.append(status)
        else:
            where_clauses.append("status IN ('pending', 'approved', 'rejected')")

        if search:
            where_clauses.append("(first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR national_id LIKE ? OR mobile LIKE ?)")
            s = f"%{search}%"
            params.extend([s, s, s, s, s])

        where_sql = " AND ".join(where_clauses) if where_clauses else "1=1"

        # Count
        cur.execute(f"SELECT COUNT(*) FROM user_registration_requests WHERE {where_sql}", params)
        total = cur.fetchone()[0]

        # Fetch page
        offset = (page - 1) * per_page
        cur.execute(
            f"SELECT * FROM user_registration_requests WHERE {where_sql} ORDER BY created_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY",
            params + [offset, per_page],
        )
        rows = _dict_rows(cur)
        for r in rows:
            _serialize(r)

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


# ══════════════════════════════════════════════════════════════
# ADMIN — Get single request detail
# ══════════════════════════════════════════════════════════════
@router.get("/admin/requests/{request_id}")
async def get_registration_request(request: Request, request_id: str):
    username = request.session.get("username")
    is_admin = request.session.get("is_admin") is True
    if not username or not is_admin:
        raise HTTPException(status_code=403, detail="دسترسی مدیریت لازم است.")

    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("SELECT * FROM user_registration_requests WHERE request_id = ?", (request_id.strip(),))
        row = cur.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="درخواست یافت نشد.")
        data = _serialize(_dict_rows(cur)[0]) if cur.description else {}
        # Re-fetch properly
        columns = [c[0] for c in cur.description]
        data = _serialize(dict(zip(columns, row)))
        return JSONResponse(content={"success": True, "data": data})
    except HTTPException:
        raise
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# ADMIN — Approve registration request
# ══════════════════════════════════════════════════════════════
@router.post("/admin/requests/{request_id}/approve")
async def approve_registration(request: Request, request_id: str):
    admin_username = request.session.get("username")
    is_admin = request.session.get("is_admin") is True
    if not admin_username or not is_admin:
        raise HTTPException(status_code=403, detail="دسترسی مدیریت لازم است.")

    conn = db_connect()
    try:
        cur = conn.cursor()

        # Fetch request
        cur.execute("SELECT * FROM user_registration_requests WHERE request_id = ? AND status = 'pending'", (request_id.strip(),))
        row = cur.fetchone()
        if not row:
            return JSONResponse(content={"success": False, "message": "درخواست یافت نشد یا قبلاً پردازش شده."}, status_code=404)

        columns = [c[0] for c in cur.description]
        req = dict(zip(columns, row))

        # Check username not taken (double-check)
        cur.execute("SELECT 1 FROM user_table WHERE LTRIM(RTRIM(username)) = ?", (req["username"],))
        if cur.fetchone():
            return JSONResponse(content={"success": False, "message": "نام کاربری قبلاً استفاده شده است."}, status_code=400)

        # Get next user ID
        cur.execute("SELECT ISNULL(MAX(id), 0) + 1 FROM user_table")
        next_id = cur.fetchone()[0]

        # Create user account using existing function
        from core.password_utils import insert_user_with_optional_hash, get_user_table_columns
        columns_set = get_user_table_columns(cur)

        password_plain = ""  # We don't have the plain password, only hash
        # Store password_hash only
        if "password_hash" in columns_set:
            cur.execute("""
                INSERT INTO user_table (
                    id, username, password, password_hash, name, last_name,
                    department, substitute, work_hours, role, hozoor_num,
                    shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh,
                    is_active
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'user', '', '', '', '', '', '', '', 'active')
            """, (
                next_id, req["username"], "",
                req["password_hash"],
                req["first_name"], req["last_name"],
                req["department"] or "",
                req["substitute"] or "",
                req["work_hours"] or "",
            ))
        else:
            cur.execute("""
                INSERT INTO user_table (
                    id, username, password, name, last_name,
                    department, substitute, work_hours, role, hozoor_num,
                    shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh,
                    is_active
                ) VALUES (?, ?, '', ?, ?, ?, ?, ?, 'user', '', '', '', '', '', '', '', 'active')
            """, (
                next_id, req["username"],
                req["first_name"], req["last_name"],
                req["department"] or "",
                req["substitute"] or "",
                req["work_hours"] or "",
            ))

        # Update request status
        cur.execute("""
            UPDATE user_registration_requests
            SET status = 'approved', reviewed_by = ?, reviewed_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME()
            WHERE request_id = ?
        """, (admin_username, request_id.strip()))

        conn.commit()

        # Audit
        log_event(
            event_type="ADMINISTRATION", action="approve_registration",
            username=admin_username, module="registration",
            status="success", severity="medium",
            ip_address=_client_ip(request), user_agent=_user_agent(request),
            metadata={"request_id": request_id, "new_user": req["username"]},
        )
        log_admin_action(
            admin_username=admin_username, action="approve_registration",
            target_username=req["username"], target_type="registration_request",
            target_id=request_id,
            description=f"تأیید درخواست ثبت نام کاربر {req['username']}",
            ip_address=_client_ip(request),
        )

        return JSONResponse(content={
            "success": True,
            "message": f"حساب کاربری {req['username']} با موفقیت ایجاد شد.",
        })
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# ADMIN — Reject registration request
# ══════════════════════════════════════════════════════════════
@router.post("/admin/requests/{request_id}/reject")
async def reject_registration(request: Request, request_id: str):
    admin_username = request.session.get("username")
    is_admin = request.session.get("is_admin") is True
    if not admin_username or not is_admin:
        raise HTTPException(status_code=403, detail="دسترسی مدیریت لازم است.")

    data = await request.json()
    reason = str(data.get("reason") or "").strip()
    if not reason:
        return JSONResponse(content={"success": False, "message": "دلیل رد الزامی است."}, status_code=400)

    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute(
            "UPDATE user_registration_requests SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = SYSUTCDATETIME(), updated_at = SYSUTCDATETIME() WHERE request_id = ? AND status = 'pending'",
            (reason, admin_username, request_id.strip()),
        )
        if cur.rowcount == 0:
            return JSONResponse(content={"success": False, "message": "درخواست یافت نشد یا قبلاً پردازش شده."}, status_code=404)
        conn.commit()

        log_event(
            event_type="ADMINISTRATION", action="reject_registration",
            username=admin_username, module="registration",
            status="success", severity="medium",
            ip_address=_client_ip(request), user_agent=_user_agent(request),
            metadata={"request_id": request_id, "reason": reason},
        )
        log_admin_action(
            admin_username=admin_username, action="reject_registration",
            target_type="registration_request", target_id=request_id,
            description=f"رد درخواست ثبت نام: {reason}",
            ip_address=_client_ip(request),
        )

        return JSONResponse(content={"success": True, "message": "درخواست رد شد."})
    except Exception as e:
        logger.error(f"Error: {type(e).__name__}: {e}")
        return JSONResponse(content={"success": False, "message": "خطای داخلی سرور"}, status_code=500)
    finally:
        conn.close()


# ══════════════════════════════════════════════════════════════
# PUBLIC — Get departments list
# ══════════════════════════════════════════════════════════════
@router.get("/departments")
async def get_departments():
    """Return the list of available departments."""
    departments = [
        "بیوشیمی", "هورمون", "میکروب", "مولکولی", "مدیریت",
        "پذیرش", "نمونه گیری", "جوابدهی", "حسابداری", "ایمونولوژی",
        "خدمات", "فناوری", "هماتولوژی",
    ]
    return JSONResponse(content={"success": True, "data": departments})


# ══════════════════════════════════════════════════════════════
# PUBLIC — Get work schedules
# ══════════════════════════════════════════════════════════════
@router.get("/work-schedules")
async def get_work_schedules():
    """Return available work schedule options."""
    schedules = [
        {"value": "16:00 - 09:00", "label": "۱۶:۰۰ - ۰۹:۰0 (صبح)"},
        {"value": "14:00 - 07:00", "label": "۱۴:۰۰ - ۰۷:۰۰ (صبح)"},
        {"value": "15:00 - 08:00", "label": "۱۵:۰۰ - ۰۸:۰۰ (صبح)"},
        {"value": "20:00 - 14:00", "label": "۲۰:۰۰ - ۱۴:۰۰ (عصر)"},
        {"value": "18:00 - 13:00", "label": "۱۸:۰۰ - ۱۳:۰۰ (عصر)"},
        {"value": "13:30 - 06:30", "label": "۱۳:۳۰ - ۰۶:۳۰"},
        {"value": "20:00 - 13:00", "label": "۲۰:۰۰ - ۱۳:۰۰"},
        {"value": "14:30 - 07:30", "label": "۱۴:۳۰ - ۰۷:۳۰"},
        {"value": "20:30 - 13:30", "label": "۲۰:۳۰ - ۱۳:۳۰"},
        {"value": "official", "label": "رسمی"},
        {"value": "unofficial", "label": "غیر رسمی"},
    ]
    return JSONResponse(content={"success": True, "data": schedules})


# ══════════════════════════════════════════════════════════════
# PUBLIC — Get active users (for substitute selection)
# ══════════════════════════════════════════════════════════════
@router.get("/active-users")
async def get_active_users(request: Request):
    """Return list of active users for substitute selection."""
    username = request.session.get("username")
    is_admin = request.session.get("is_admin") is True
    if not username or not is_admin:
        return JSONResponse(status_code=403, content={"success": False, "error": "دسترسی مدیریتی ندارید."})
    conn = db_connect()
    try:
        cur = conn.cursor()
        cur.execute("SELECT username, name, last_name, department FROM user_table WHERE ISNULL(is_active, 'active') = 'active' ORDER BY name")
        rows = []
        for r in cur.fetchall():
            rows.append({
                "username": r[0].strip() if r[0] else "",
                "name": f"{r[1] or ''} {r[2] or ''}".strip(),
                "department": r[3] or "",
            })
        return JSONResponse(content={"success": True, "data": rows})
    except Exception:
        return JSONResponse(content={"success": True, "data": []})
    finally:
        conn.close()
