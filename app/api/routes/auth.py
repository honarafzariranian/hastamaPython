# app/api/routes/auth.py

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse
import pyodbc

from core.password_utils import fetch_user_for_login, verify_password

router = APIRouter()

# Master admin usernames — the first admin user is the master admin.
# Adjust this list as needed for your organization.
MASTER_ADMIN_USERNAMES = {"ali"}

# اتصال به دیتابیس (میتونی این رو به صورت مشترک تو یه فایل دیگه هم بذاری)
conn = pyodbc.connect('DRIVER={ODBC Driver 17 for SQL Server};'
                      r'SERVER=localhost\SQLEXPRESS;'
                      'DATABASE=userDB;'
                      'Trusted_Connection=yes;')
cursor = conn.cursor()

def _client_ip(request: Request) -> str:
    return request.headers.get("x-forwarded-for", "").split(",")[0].strip() or (
        request.client.host if request.client else "unknown"
    )

def _user_agent(request: Request) -> str:
    return request.headers.get("user-agent", "")[:500]

@router.post("/login_user")
async def login(request: Request):
    data = await request.json()
    username = str(data.get("username") or "").strip()
    password = str(data.get("password") or "").strip()

    user = fetch_user_for_login(cursor, username)

    if user and verify_password(user[2], user[3] if len(user) > 3 else None, password):
        request.session["username"] = username
        role = user[1].strip().lower()

        if role == "admin":
            request.session["is_admin"] = True
            request.session["is_master_admin"] = username.strip().lower() in MASTER_ADMIN_USERNAMES
        else:
            request.session["is_admin"] = False
            request.session["is_master_admin"] = False

        # Audit: successful login
        try:
            from app.services.audit import log_event, track_session_login
            log_event(
                event_type="AUTHENTICATION", action="login",
                username=username, role=role,
                ip_address=_client_ip(request), user_agent=_user_agent(request),
                status="success",
            )
            track_session_login(
                session_key=request.session.get("session", ""),
                username=username,
                ip_address=_client_ip(request),
                user_agent=_user_agent(request),
            )
        except Exception:
            pass

        # Master admin goes directly to control center
        if request.session.get("is_master_admin"):
            redirect = "/master-admin"
        elif role == "admin":
            redirect = "/admin/dashboard"
        else:
            redirect = "/user_panel"
        return JSONResponse({"success": True, "redirect": redirect})

    # Audit: failed login
    try:
        from app.services.audit import log_event, create_security_event
        log_event(
            event_type="AUTHENTICATION", action="login",
            username=username,
            ip_address=_client_ip(request), user_agent=_user_agent(request),
            status="failure", severity="low",
        )
    except Exception:
        pass

    return JSONResponse({"success": False, "message": "نام کاربری یا رمز عبور اشتباه است"})


@router.post("/forgot_password")
async def forgot_password(request: Request):
    """Public endpoint — submit a password reset request (no auth required)."""
    data = await request.json()
    username = str(data.get("username") or "").strip()
    if not username:
        return JSONResponse({"success": False, "message": "نام کاربری را وارد کنید"})

    # Check user exists
    user = fetch_user_for_login(cursor, username)
    if not user:
        # Always return success to prevent user enumeration
        return JSONResponse({"success": True, "message": "درخواست شما ثبت شد. منتظر تأیید مدیر باشید."})

    try:
        from app.services.audit import create_password_reset_request
        result = create_password_reset_request(
            username=username,
            ip_address=_client_ip(request),
            user_agent=_user_agent(request),
        )
        if result.get("request_id"):
            return JSONResponse({"success": True, "message": "درخواست بازیابی رمز عبور ثبت شد. منتظر تأیید مدیر سامانه باشید."})
        else:
            return JSONResponse({"success": False, "message": "خطا در ثبت درخواست. لطفاً دوباره تلاش کنید."})
    except Exception as e:
        return JSONResponse({"success": False, "message": "خطا در ثبت درخواست."})
