from __future__ import annotations

import importlib.util

from fastapi import APIRouter
from fastapi.responses import JSONResponse

from app.core.database import connection
from app.services.web_push import enabled

router = APIRouter(tags=["health"])


@router.get("/health")
def health():
    return {"status": "ok"}


@router.get("/health/database")
def health_database():
    try:
        with connection() as conn:
            conn.cursor().execute("SELECT 1")
        return {"status": "ok"}
    except Exception:
        return JSONResponse(status_code=503, content={"status": "error"})


@router.get("/health/push")
def health_push():
    available = importlib.util.find_spec("pywebpush") is not None
    configured = enabled()
    if not available or not configured:
        return JSONResponse(
            status_code=503,
            content={"status": "error", "configured": configured, "pywebpush": available},
        )
    try:
        with connection() as conn:
            conn.cursor().execute("SELECT TOP 1 1 FROM push_subscriptions")
        database = True
    except Exception:
        database = False
    if not database:
        return JSONResponse(status_code=503, content={"status": "error", "configured": True, "pywebpush": True, "database": False})
    return {"status": "ok", "configured": True, "pywebpush": True, "database": True}
