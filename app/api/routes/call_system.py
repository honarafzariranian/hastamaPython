"""Sample Collection Call System — Samaneh Farakhan Nemonegiri.

WebSocket-powered real-time call broadcasting for TV displays in the
organization's internal LAN.  No Internet access required.
"""
from __future__ import annotations

import asyncio
import json
import logging
import re
import threading
from datetime import datetime, timezone
from pathlib import Path
from typing import Literal, Optional

import pyodbc
from fastapi import APIRouter, HTTPException, Request, WebSocket, WebSocketDisconnect
from fastapi.responses import HTMLResponse, JSONResponse
from pydantic import BaseModel, Field, field_validator

from app.core.database import connect as db_connect

logger = logging.getLogger("hastama.call_system")

router = APIRouter(prefix="/api", tags=["call-system"])

# ---------------------------------------------------------------------------
# Lazy schema migration (same pattern as notifications)
# ---------------------------------------------------------------------------
_schema_ready = False
_schema_lock = threading.Lock()


def _ensure_schema(conn) -> None:
    global _schema_ready
    if _schema_ready:
        return
    with _schema_lock:
        if _schema_ready:
            return
        migration = Path(__file__).resolve().parents[3] / "database" / "reception_calls.sql"
        cursor = conn.cursor()
        cursor.execute(migration.read_text(encoding="utf-8"))
        conn.commit()
        _schema_ready = True
        logger.info("reception_calls schema ensured")


# ---------------------------------------------------------------------------
# WebSocket manager — in-memory broadcast to all connected TV displays
# ---------------------------------------------------------------------------
class DisplayManager:
    """Manages active TV display WebSocket connections."""

    def __init__(self):
        self._connections: list[WebSocket] = []
        self._lock = threading.Lock()

    async def connect(self, ws: WebSocket) -> None:
        await ws.accept()
        with self._lock:
            self._connections.append(ws)
        logger.info("display connected — total: %d", len(self._connections))

    def disconnect(self, ws: WebSocket) -> None:
        with self._lock:
            if ws in self._connections:
                self._connections.remove(ws)
        logger.info("display disconnected — total: %d", len(self._connections))

    async def broadcast(self, message: dict) -> int:
        """Send *message* to every connected display.  Returns count sent."""
        payload = json.dumps(message, ensure_ascii=False)
        dead: list[WebSocket] = []
        sent = 0
        with self._lock:
            targets = list(self._connections)
        for ws in targets:
            try:
                await ws.send_text(payload)
                sent += 1
            except Exception:
                dead.append(ws)
        if dead:
            with self._lock:
                for ws in dead:
                    if ws in self._connections:
                        self._connections.remove(ws)
        return sent

    @property
    def count(self) -> int:
        with self._lock:
            return len(self._connections)


display_manager = DisplayManager()

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _get_connection():
    return db_connect()


def _actor(request: Request, admin: bool = False) -> str:
    username = str(request.session.get("username") or "").strip()
    if not username:
        raise HTTPException(status_code=401, detail="برای ادامه وارد سامانه شوید.")
    if admin and request.session.get("is_admin") is not True:
        raise HTTPException(status_code=403, detail="دسترسی مدیریت لازم است.")
    return username


def _dict_rows(cursor) -> list[dict]:
    columns = [item[0] for item in cursor.description]
    return [dict(zip(columns, row)) for row in cursor.fetchall()]


def _iso(value) -> Optional[str]:
    if not value:
        return None
    if isinstance(value, str):
        return value
    if value.tzinfo is None:
        value = value.replace(tzinfo=timezone.utc)
    return value.isoformat().replace("+00:00", "Z")


# Persian number mapping
_FA_DIGITS = "۰۱۲۳۴۵۶۷۸۹"


def to_persian_numbers(text) -> str:
    s = str(text)
    for i in range(10):
        s = s.replace(str(i), _FA_DIGITS[i])
    return s


# Reception number validation — alphanumeric, max 50 chars, no HTML/script
_RECEPTION_RE = re.compile(r"^[\w\u0600-\u06FF\-\/\.\s]{1,50}$", re.UNICODE)


def _validate_reception_number(value: str) -> str:
    value = value.strip()
    if not value:
        raise HTTPException(status_code=422, detail="لطفاً شماره پذیرش را وارد کنید.")
    if len(value) > 50:
        raise HTTPException(status_code=422, detail="شماره پذیرش بیش از حد طولانی است.")
    if "<" in value or ">" in value or "script" in value.lower():
        raise HTTPException(status_code=422, detail="شماره پذیرش شامل کاراکترهای غیرمجاز است.")
    return value


# ---------------------------------------------------------------------------
# Call models
# ---------------------------------------------------------------------------
class CallInput(BaseModel):
    reception_number: str = Field(min_length=1, max_length=50)
    department: str = Field(default="نمونه‌گیری", max_length=100)

    @field_validator("reception_number")
    @classmethod
    def clean_number(cls, v: str) -> str:
        return v.strip()


# ---------------------------------------------------------------------------
# REST API — Call Management
# ---------------------------------------------------------------------------

@router.post("/calls")
async def create_call(request: Request, payload: CallInput):
    """Create a new reception call and broadcast to all TV displays."""
    username = _actor(request, admin=True)
    number = _validate_reception_number(payload.reception_number)
    department = payload.department.strip() or "نمونه‌گیری"

    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")

    # Store in database
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        cursor.execute(
            """INSERT INTO dbo.reception_calls (reception_number, department, called_by, is_test, called_at)
               VALUES (?, ?, ?, 0, SYSUTCDATETIME())""",
            (number, department, username),
        )
        conn.commit()
    except Exception:
        logger.exception("failed to store reception call")
        raise HTTPException(status_code=500, detail="فراخوان انجام نشد. لطفاً دوباره تلاش کنید.")
    finally:
        conn.close()

    # Broadcast via WebSocket
    event = {
        "type": "reception_call",
        "data": {
            "number": number,
            "persian_number": to_persian_numbers(number),
            "department": department,
            "message": f"لطفاً به بخش {department} مراجعه کنید.",
            "voice": f"شماره {to_persian_numbers(number)}، لطفاً به بخش {department} مراجعه کنید.",
            "timestamp": now,
            "is_test": False,
        },
    }
    sent = await display_manager.broadcast(event)
    logger.info("call broadcast: number=%s dept=%s displays=%d", number, department, sent)

    return JSONResponse({
        "success": True,
        "message": "فراخوان با موفقیت ارسال شد.",
        "display_count": sent,
        "data": event["data"],
    })


@router.post("/calls/repeat")
async def repeat_last_call(request: Request):
    """Repeat the most recent non-test call."""
    username = _actor(request, admin=True)

    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        cursor.execute(
            """SELECT TOP 1 reception_number, department, called_at
               FROM dbo.reception_calls
               WHERE is_test = 0
               ORDER BY called_at DESC"""
        )
        row = cursor.fetchone()
    finally:
        conn.close()

    if not row:
        raise HTTPException(status_code=404, detail="فراخوانی برای تکرار وجود ندارد.")

    number = str(row[0]).strip()
    department = str(row[1]).strip()
    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")

    # Store repeat
    conn2 = _get_connection()
    try:
        _ensure_schema(conn2)
        cursor2 = conn2.cursor()
        cursor2.execute(
            """INSERT INTO dbo.reception_calls (reception_number, department, called_by, is_test, called_at)
               VALUES (?, ?, ?, 0, SYSUTCDATETIME())""",
            (number, department, username),
        )
        conn2.commit()
    finally:
        conn2.close()

    event = {
        "type": "reception_call",
        "data": {
            "number": number,
            "persian_number": to_persian_numbers(number),
            "department": department,
            "message": f"لطفاً به بخش {department} مراجعه کنید.",
            "voice": f"شماره {to_persian_numbers(number)}، لطفاً به بخش {department} مراجعه کنید.",
            "timestamp": now,
            "is_test": False,
        },
    }
    sent = await display_manager.broadcast(event)
    logger.info("call repeat broadcast: number=%s displays=%d", number, sent)

    return JSONResponse({
        "success": True,
        "message": "آخرین فراخوان تکرار شد.",
        "display_count": sent,
        "data": event["data"],
    })


@router.post("/calls/test-display")
async def test_display(request: Request):
    """Send a test event to TV displays (no database record)."""
    _actor(request, admin=True)

    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")
    event = {
        "type": "reception_call",
        "data": {
            "number": "۱۲۳",
            "persian_number": "۱۲۳",
            "department": "نمونه‌گیری",
            "message": "این یک پیام آزمایشی است.",
            "voice": "این یک پیام آزمایشی است.",
            "timestamp": now,
            "is_test": True,
        },
    }
    sent = await display_manager.broadcast(event)
    return JSONResponse({
        "success": True,
        "message": "پیام آزمایشی ارسال شد.",
        "display_count": sent,
    })


@router.post("/calls/test-voice")
async def test_voice(request: Request):
    """Send a voice-only test event to TV displays."""
    _actor(request, admin=True)

    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")
    event = {
        "type": "reception_call",
        "data": {
            "number": "",
            "persian_number": "",
            "department": "",
            "message": "",
            "voice": "این یک پیام آزمایشی است.",
            "timestamp": now,
            "is_test": True,
        },
    }
    sent = await display_manager.broadcast(event)
    return JSONResponse({
        "success": True,
        "message": " تست صدا ارسال شد.",
        "display_count": sent,
    })


@router.post("/calls/test-audio")
async def test_audio(request: Request, number: int = 1):
    """Send a test call with a specific number to test local MP3 audio playback."""
    _actor(request, admin=True)

    if number < 1 or number > 2000:
        raise HTTPException(status_code=422, detail="شماره باید بین ۱ تا ۲۰۰۰ باشد.")

    # Verify the audio file exists on disk
    audio_dir = Path(__file__).resolve().parents[3] / "app" / "static" / "audio" / "sample_call" / "fa-IR-DilaraNeural"
    audio_file = audio_dir / f"{number:04d}.mp3"
    audio_exists = audio_file.exists() and audio_file.stat().st_size > 100

    department = "نمونه‌گیری"
    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")

    event = {
        "type": "reception_call",
        "data": {
            "number": str(number),
            "persian_number": to_persian_numbers(str(number)),
            "department": department,
            "message": "تست صدا — فراخوان شماره " + to_persian_numbers(str(number)),
            "voice": "شماره " + to_persian_numbers(str(number)) + "، لطفاً به بخش " + department + " مراجعه کنید.",
            "timestamp": now,
            "is_test": True,
            "audio_available": audio_exists,
        },
    }
    sent = await display_manager.broadcast(event)
    return JSONResponse({
        "success": True,
        "message": "تست صدا ارسال شد." + ("" if audio_exists else " (فایل صوتی موجود نیست)"),
        "display_count": sent,
        "audio_available": audio_exists,
    })


@router.get("/calls/audio-status")
async def audio_status():
    """Check how many audio files exist in the audio directory."""
    audio_dir = Path(__file__).resolve().parents[3] / "app" / "static" / "audio" / "sample_call" / "fa-IR-DilaraNeural"
    if not audio_dir.exists():
        return JSONResponse({
            "success": True,
            "total_files": 0,
            "total_expected": 2000,
            "directory": str(audio_dir),
            "exists": False,
        })

    count = sum(1 for f in audio_dir.iterdir()
                if f.suffix == ".mp3" and f.stat().st_size > 100)
    return JSONResponse({
        "success": True,
        "total_files": count,
        "total_expected": 2000,
        "directory": str(audio_dir),
        "exists": True,
    })


@router.get("/calls/recent")
async def recent_calls(request: Request, limit: int = 20):
    """Return the most recent non-test calls."""
    _actor(request, admin=True)

    limit = min(max(limit, 1), 100)
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        cursor.execute(
            """SELECT TOP (?) reception_number, department, called_by, called_at
               FROM dbo.reception_calls
               WHERE is_test = 0
               ORDER BY called_at DESC""",
            (limit,),
        )
        rows = _dict_rows(cursor)
    finally:
        conn.close()

    for row in rows:
        row["called_at"] = _iso(row.get("called_at"))
        row["persian_number"] = to_persian_numbers(row.get("reception_number", ""))

    return JSONResponse({"success": True, "calls": rows})


@router.get("/calls/status")
async def display_status():
    """Return the number of active TV display connections."""
    return JSONResponse({
        "success": True,
        "connected_displays": display_manager.count,
    })


# ---------------------------------------------------------------------------
# WebSocket endpoint — TV displays connect here
# ---------------------------------------------------------------------------

@router.websocket("/ws/call-display")
async def call_display_ws(websocket: WebSocket):
    """WebSocket endpoint for TV display pages."""
    await display_manager.connect(websocket)
    try:
        while True:
            # Keep connection alive; TV page may send pings
            data = await websocket.receive_text()
            if data == "ping":
                await websocket.send_text(json.dumps({"type": "pong"}))
    except WebSocketDisconnect:
        display_manager.disconnect(websocket)
    except Exception:
        display_manager.disconnect(websocket)


# ---------------------------------------------------------------------------
# Pages — Management and TV Display
# ---------------------------------------------------------------------------


