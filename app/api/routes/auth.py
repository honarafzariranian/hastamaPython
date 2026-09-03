# app/api/routes/auth.py

from fastapi import APIRouter, Request
from fastapi.responses import JSONResponse
import pyodbc

from core.password_utils import fetch_user_for_login, verify_password

router = APIRouter()

# اتصال به دیتابیس (میتونی این رو به صورت مشترک تو یه فایل دیگه هم بذاری)
conn = pyodbc.connect('DRIVER={ODBC Driver 17 for SQL Server};'
                      r'SERVER=localhost\SQLEXPRESS;'
                      'DATABASE=userDB;'
                      'Trusted_Connection=yes;')
cursor = conn.cursor()

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
            return JSONResponse({"success": True, "redirect": "/admin/dashboard"})
        else:
            request.session["is_admin"] = False
            return JSONResponse({"success": True, "redirect": "/user_panel"})

    return JSONResponse({"success": False, "message": "نام کاربری یا رمز عبور اشتباه است"})

