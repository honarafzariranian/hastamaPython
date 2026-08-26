from __future__ import annotations

from fastapi import APIRouter
from fastapi.responses import JSONResponse

from app.core.database import connection
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
