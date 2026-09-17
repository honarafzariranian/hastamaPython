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
from fastapi import APIRouter, HTTPException, Request, UploadFile, File, WebSocket, WebSocketDisconnect
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
        db_dir = Path(__file__).resolve().parents[3] / "database"
        cursor = conn.cursor()
        for sql_file in ["reception_calls.sql", "display_queue.sql", "waiting_queue.sql", "slides.sql"]:
            sql_path = db_dir / sql_file
            if sql_path.exists():
                cursor.execute(sql_path.read_text(encoding="utf-8"))
        conn.commit()
        _schema_ready = True
        logger.info("call system schemas ensured")


# ---------------------------------------------------------------------------
# WebSocket manager — in-memory broadcast to all connected TV displays
# ---------------------------------------------------------------------------
class DisplayManager:
    """Manages active TV display WebSocket connections.

    Each connection is tagged as either 'display' (real TV screen)
    or 'preview' (iframe inside management page) so the management
    panel can distinguish them.
    """

    def __init__(self):
        self._connections: list[WebSocket] = []
        self._tags: dict[int, str] = {}          # id(ws) -> 'display'|'preview'
        self._lock = threading.Lock()

    async def connect(self, ws: WebSocket, tag: str = 'display') -> None:
        await ws.accept()
        with self._lock:
            self._connections.append(ws)
            self._tags[id(ws)] = tag
        logger.info("display connected (tag=%s) — total: %d", tag, len(self._connections))

    def disconnect(self, ws: WebSocket) -> None:
        with self._lock:
            if ws in self._connections:
                self._connections.remove(ws)
            self._tags.pop(id(ws), None)
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
                    self._tags.pop(id(ws), None)
        return sent

    @property
    def count(self) -> int:
        with self._lock:
            return len(self._connections)

    @property
    def display_count(self) -> int:
        """Count of real TV display connections (excludes preview iframes)."""
        with self._lock:
            return sum(1 for ws in self._connections if self._tags.get(id(ws)) == 'display')

    @property
    def preview_count(self) -> int:
        with self._lock:
            return sum(1 for ws in self._connections if self._tags.get(id(ws)) == 'preview')


display_manager = DisplayManager()

# ---------------------------------------------------------------------------
# Display Queue helpers (persistent state across refreshes)
# ---------------------------------------------------------------------------
MAX_DISPLAY_SLOTS = 5


def _queue_add(conn, number: str, department: str, username: str) -> None:
    """Add a number to the display queue, shifting older entries."""
    cursor = conn.cursor()
    # Shift existing entries: position 3->4, 2->3, 1->2, 0->1
    for pos in range(MAX_DISPLAY_SLOTS - 1, 0, -1):
        cursor.execute(
            "UPDATE dbo.display_queue SET slot_position = ? WHERE slot_position = ?",
            (pos, pos - 1),
        )
    # Delete the oldest if queue is full
    cursor.execute(
        "DELETE FROM dbo.display_queue WHERE slot_position = ?",
        (MAX_DISPLAY_SLOTS - 1,),
    )
    # Insert new call at position 0 (hero)
    cursor.execute(
        "INSERT INTO dbo.display_queue (reception_number, department, called_by, slot_position) VALUES (?, ?, ?, 0)",
        (number, department, username),
    )
    conn.commit()


def _queue_remove(conn, number: str) -> None:
    """Remove a specific number from the display queue."""
    cursor = conn.cursor()
    cursor.execute(
        "DELETE FROM dbo.display_queue WHERE reception_number = ?",
        (number,),
    )
    # Compact: re-number positions to fill gaps
    rows = cursor.execute(
        "SELECT id FROM dbo.display_queue ORDER BY slot_position ASC"
    ).fetchall()
    for idx, row in enumerate(rows):
        cursor.execute(
            "UPDATE dbo.display_queue SET slot_position = ? WHERE id = ?",
            (idx, row[0]),
        )
    conn.commit()


def _queue_clear(conn) -> None:
    """Clear all entries from the display queue."""
    cursor = conn.cursor()
    cursor.execute("DELETE FROM dbo.display_queue")
    conn.commit()


def _queue_get(conn) -> list[dict]:
    """Return the current display queue ordered by position."""
    cursor = conn.cursor()
    rows = cursor.execute(
        "SELECT reception_number, department, called_by, slot_position, created_at FROM dbo.display_queue ORDER BY slot_position ASC"
    ).fetchall()
    columns = [d[0] for d in cursor.description]
    result = []
    for row in rows:
        d = dict(zip(columns, row))
        d["persian_number"] = to_persian_numbers(str(d["reception_number"]))
        d["called_at"] = _iso(d.pop("created_at", None))
        result.append(d)
    return result


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _get_connection():
    return db_connect()


def _actor(request: Request, admin: bool = False, required: bool = True) -> str:
    username = str(request.session.get("username") or "").strip()
    if not username and required:
        raise HTTPException(status_code=401, detail="برای ادامه وارد سامانه شوید.")
    if admin and required and request.session.get("is_admin") is not True:
        raise HTTPException(status_code=403, detail="دسترسی مدیریت لازم است.")
    return username or "guest"


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
    username = _actor(request, admin=True, required=False)
    number = _validate_reception_number(payload.reception_number)
    department = payload.department.strip() or "نمونه‌گیری"

    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")

    # Store in database + display queue
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        cursor.execute(
            """INSERT INTO dbo.reception_calls (reception_number, department, called_by, is_test, called_at)
               VALUES (?, ?, ?, 0, SYSUTCDATETIME())""",
            (number, department, username),
        )
        # Deduplicate: remove this number from queue before re-adding at position 0
        _queue_remove(conn, number)
        _queue_add(conn, number, department, username)
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
    username = _actor(request, admin=True, required=False)

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

    # Store repeat + update display queue
    conn2 = _get_connection()
    try:
        _ensure_schema(conn2)
        cursor2 = conn2.cursor()
        cursor2.execute(
            """INSERT INTO dbo.reception_calls (reception_number, department, called_by, is_test, called_at)
               VALUES (?, ?, ?, 0, SYSUTCDATETIME())""",
            (number, department, username),
        )
        _queue_remove(conn2, number)
        _queue_add(conn2, number, department, username)
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
    _actor(request, admin=True, required=False)

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
    _actor(request, admin=True, required=False)

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
    _actor(request, admin=True, required=False)

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
        "directory": str(audio_dir),        "exists": True,
    })


@router.get("/calls/display-queue")
async def get_display_queue():
    """Return the current display queue (what's on TV right now)."""
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        queue = _queue_get(conn)
    finally:
        conn.close()
    return JSONResponse({"success": True, "queue": queue})


# ---------------------------------------------------------------------------
# Waiting Queue (reception → sample collection)
# ---------------------------------------------------------------------------

@router.get("/calls/waiting-queue")
async def get_waiting_queue():
    """Return all waiting items (not yet called)."""
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        rows = cursor.execute(
            """SELECT id, reception_number, department, added_by, status, created_at, called_at
               FROM dbo.waiting_queue
               WHERE status = 'waiting'
               ORDER BY id ASC"""
        ).fetchall()
        columns = [d[0] for d in cursor.description]
        result = []
        for row in rows:
            d = dict(zip(columns, row))
            d["persian_number"] = to_persian_numbers(str(d["reception_number"]))
            d["created_at"] = _iso(d.pop("created_at", None))
            d["called_at"] = _iso(d.pop("called_at", None))
            result.append(d)
    finally:
        conn.close()
    return JSONResponse({"success": True, "items": result})


@router.post("/calls/waiting-queue")
async def add_to_waiting_queue(request: Request):
    """Add a number to the waiting queue."""
    username = _actor(request, admin=True, required=False)
    body = await request.json()
    number = str(body.get("number", "")).strip()
    department = str(body.get("department", "نمونه‌گیری")).strip()
    if not number:
        raise HTTPException(status_code=422, detail="شماره پذیرش را وارد کنید.")

    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        cursor.execute(
            """INSERT INTO dbo.waiting_queue (reception_number, department, added_by)
               VALUES (?, ?, ?)""",
            (number, department, username),
        )
        new_id = cursor.execute("SELECT SCOPE_IDENTITY()").fetchone()[0]
        conn.commit()
    finally:
        conn.close()

    return JSONResponse({
        "success": True,
        "message": "شماره " + to_persian_numbers(number) + " به صف اضافه شد.",
        "id": int(new_id) if new_id else 0,
    })


@router.delete("/calls/waiting-queue/{item_id}")
async def remove_from_waiting_queue(request: Request, item_id: int):
    """Remove an item from the waiting queue."""
    username = request.session.get("username")
    if not username:
        return JSONResponse(status_code=401, content={"success": False, "error": "لاگین نکرده‌اید."})
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        cursor.execute("DELETE FROM dbo.waiting_queue WHERE id = ?", (item_id,))
        conn.commit()
    finally:
        conn.close()
    return JSONResponse({"success": True, "message": "از صف حذف شد."})


@router.post("/calls/waiting-queue/{item_id}/call")
async def call_from_queue(request: Request, item_id: int):
    """Mark a waiting queue item as called (triggers call system)."""
    username = _actor(request, admin=True, required=False)

    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        row = cursor.execute(
            """SELECT reception_number, department FROM dbo.waiting_queue
               WHERE id = ? AND status = 'waiting'""",
            (item_id,),
        ).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="مورد مورد نظر یافت نشد.")
        number = str(row[0]).strip()
        department = str(row[1]).strip()

        # Mark as called
        cursor.execute(
            """UPDATE dbo.waiting_queue SET status = 'called', called_at = SYSUTCDATETIME()
               WHERE id = ?""",
            (item_id,),
        )

        # Add to display queue
        _queue_remove(conn, number)
        _queue_add(conn, number, department, username)

        # Store in reception_calls history
        cursor.execute(
            """INSERT INTO dbo.reception_calls (reception_number, department, called_by, is_test, called_at)
               VALUES (?, ?, ?, 0, SYSUTCDATETIME())""",
            (number, department, username),
        )
        conn.commit()
    finally:
        conn.close()

    # Broadcast via WebSocket
    now = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S")
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

    return JSONResponse({
        "success": True,
        "message": "فراخوان شماره " + to_persian_numbers(number) + " ارسال شد.",
        "display_count": sent,
    })




@router.post("/calls/reset-display")
async def reset_display(request: Request):
    """Broadcast a reset event to clear all numbers from TV displays."""
    _actor(request, admin=True, required=False)

    # Clear persistent display queue
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        _queue_clear(conn)
    finally:
        conn.close()

    event = {"type": "reset_display", "data": {}}
    sent = await display_manager.broadcast(event)
    return JSONResponse({
        "success": True,
        "message": "تمام شماره‌ها از نمایشگر پاک شد.",
        "display_count": sent,
    })


@router.post("/calls/remove")
async def remove_call(request: Request):
    """Broadcast a remove event to TV displays to remove a specific number."""
    _actor(request, admin=True, required=False)

    body = await request.json()
    number = str(body.get("number", "")).strip()
    if not number:
        raise HTTPException(status_code=422, detail="شماره مورد نظر را وارد کنید.")

    # Remove from persistent display queue
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        _queue_remove(conn, number)
    finally:
        conn.close()

    event = {
        "type": "remove_call",
        "data": {
            "number": number,
            "persian_number": to_persian_numbers(number),
        },
    }
    sent = await display_manager.broadcast(event)
    return JSONResponse({
        "success": True,
        "message": "شماره " + to_persian_numbers(number) + " از نمایشگر حذف شد.",
        "display_count": sent,
    })


@router.get("/calls/recent")
async def recent_calls(request: Request, limit: int = 20):
    """Return the most recent non-test calls."""
    _actor(request, admin=True, required=False)

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
    """Return the number of active TV display connections.

    ``connected_displays``  — total WebSocket connections
    ``real_displays``       — real TV screens (excludes iframe previews)
    ``preview_displays``    — management-page iframe previews
    """
    return JSONResponse({
        "success": True,
        "connected_displays": display_manager.count,
        "real_displays": display_manager.display_count,
        "preview_displays": display_manager.preview_count,
    })


# ---------------------------------------------------------------------------
# Slideshow management
# ---------------------------------------------------------------------------
SLIDES_DIR = Path(__file__).resolve().parents[3] / "app" / "static" / "slides"


def _ensure_slides_dir():
    SLIDES_DIR.mkdir(parents=True, exist_ok=True)


@router.get("/calls/slides")
async def list_slides():
    """Return all slides ordered by sort_order."""
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        rows = cursor.execute(
            "SELECT id, filename, original_name, is_active, sort_order, created_at "
            "FROM dbo.slides ORDER BY sort_order ASC, id ASC"
        ).fetchall()
        columns = [d[0] for d in cursor.description]
        result = []
        for row in rows:
            d = dict(zip(columns, row))
            d["created_at"] = _iso(d.pop("created_at", None))
            d["url"] = "/static/slides/" + d["filename"]
            result.append(d)
    finally:
        conn.close()
    return JSONResponse({"success": True, "slides": result})


@router.get("/calls/slides/active")
async def list_active_slides():
    """Return only active slides (for TV display)."""
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        rows = cursor.execute(
            "SELECT id, filename, sort_order FROM dbo.slides "
            "WHERE is_active = 1 ORDER BY sort_order ASC, id ASC",
        ).fetchall()
        result = []
        for row in rows:
            result.append({
                "id": row[0],
                "filename": row[1],
                "url": "/static/slides/" + row[1],
                "sort_order": row[2],
            })
    finally:
        conn.close()
    return JSONResponse({"success": True, "slides": result})


@router.post("/calls/slides/upload")
async def upload_slide(request: Request, file: UploadFile = File(...)):
    """Upload a slide image."""
    _actor(request, admin=True, required=True)
    _ensure_slides_dir()

    # Validate file type
    allowed = {"image/jpeg", "image/png", "image/webp", "image/gif"}
    if file.content_type not in allowed:
        raise HTTPException(status_code=422, detail="فقط فایل‌های تصویری (JPG, PNG, WebP, GIF) مجاز هستند.")

    # Read file content
    content = await file.read()
    if len(content) > 10 * 1024 * 1024:  # 10 MB max
        raise HTTPException(status_code=422, detail="حجم فایل نباید بیشتر از ۱۰ مگابایت باشد.")
    if len(content) < 100:
        raise HTTPException(status_code=422, detail="فایل نامعتبر است.")

    # Generate safe filename
    ext = Path(file.filename or "slide.jpg").suffix.lower()
    if ext not in {".jpg", ".jpeg", ".png", ".webp", ".gif"}:
        ext = ".jpg"
    safe_name = f"slide_{datetime.now().strftime('%Y%m%d_%H%M%S_%f')}{ext}"

    # Save to disk
    file_path = SLIDES_DIR / safe_name
    file_path.write_bytes(content)

    # Store in database
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        # Get next sort_order
        max_order = cursor.execute("SELECT ISNULL(MAX(sort_order), 0) FROM dbo.slides").fetchone()[0]
        cursor.execute(
            "INSERT INTO dbo.slides (filename, original_name, is_active, sort_order) VALUES (?, ?, 1, ?)",
            (safe_name, file.filename or safe_name, max_order + 1),
        )
        new_id = cursor.execute("SELECT SCOPE_IDENTITY()").fetchone()[0]
        conn.commit()
    finally:
        conn.close()

    return JSONResponse({
        "success": True,
        "message": "اسلاید با موفقیت آپلود شد.",
        "slide": {
            "id": int(new_id) if new_id else 0,
            "filename": safe_name,
            "original_name": file.filename or safe_name,
            "url": "/static/slides/" + safe_name,
            "is_active": True,
        },
    })


@router.put("/calls/slides/{slide_id}/toggle")
async def toggle_slide(request: Request, slide_id: int):
    """Toggle active/inactive status of a slide."""
    username = request.session.get("username")
    if not username:
        return JSONResponse(status_code=401, content={"success": False, "error": "لاگین نکرده‌اید."})
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        cursor.execute("UPDATE dbo.slides SET is_active = CASE WHEN is_active = 1 THEN 0 ELSE 1 END WHERE id = ?", (slide_id,))
        if cursor.rowcount == 0:
            raise HTTPException(status_code=404, detail="اسلاید یافت نشد.")
        conn.commit()
    finally:
        conn.close()
    return JSONResponse({"success": True, "message": "وضعیت اسلاید تغییر کرد."})


@router.delete("/calls/slides/{slide_id}")
async def delete_slide(request: Request, slide_id: int):
    """Delete a slide from database and disk."""
    username = request.session.get("username")
    if not username:
        return JSONResponse(status_code=401, content={"success": False, "error": "لاگین نکرده‌اید."})
    conn = _get_connection()
    try:
        _ensure_schema(conn)
        cursor = conn.cursor()
        row = cursor.execute("SELECT filename FROM dbo.slides WHERE id = ?", (slide_id,)).fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="اسلاید یافت نشد.")
        filename = row[0]
        cursor.execute("DELETE FROM dbo.slides WHERE id = ?", (slide_id,))
        conn.commit()
    finally:
        conn.close()

    # Delete from disk
    _ensure_slides_dir()
    file_path = SLIDES_DIR / filename
    if file_path.exists():
        try:
            file_path.unlink()
        except Exception:
            pass

    return JSONResponse({"success": True, "message": "اسلاید حذف شد."})


# ---------------------------------------------------------------------------
# WebSocket endpoint — TV displays connect here
# ---------------------------------------------------------------------------

@router.websocket("/ws/call-display")
async def call_display_ws(websocket: WebSocket):
    """WebSocket endpoint for TV display pages — no authentication required.

    The first message after connect may be a JSON `{"tag": "preview"}` or
    `{"tag": "display"}`.  If omitted the connection is treated as a real
    TV display.
    """
    tag = 'display'          # default: real TV display
    await display_manager.connect(websocket, tag=tag)
    try:
        # Check if the first message declares a tag
        first = await websocket.receive_text()
        try:
            msg = json.loads(first)
            if isinstance(msg, dict) and 'tag' in msg:
                tag = msg['tag']
                with display_manager._lock:
                    display_manager._tags[id(websocket)] = tag
                logger.info("display tag updated to: %s", tag)
                await websocket.send_text(json.dumps({"type": "pong"}))
                first = None   # consumed
        except (json.JSONDecodeError, TypeError):
            pass

        if first == "ping":
            await websocket.send_text(json.dumps({"type": "pong"}))

        while True:
            data = await websocket.receive_text()
            if data == "ping":
                await websocket.send_text(json.dumps({"type": "pong"}))
            else:
                # Forward JSON messages (e.g. audio_activated) to all displays
                try:
                    msg = json.loads(data)
                    if isinstance(msg, dict) and msg.get('type') == 'audio_activated':
                        await display_manager.broadcast(msg)
                except (json.JSONDecodeError, TypeError):
                    pass
    except WebSocketDisconnect:
        display_manager.disconnect(websocket)
    except Exception:
        display_manager.disconnect(websocket)


# ---------------------------------------------------------------------------
# Pages — Management and TV Display
# ---------------------------------------------------------------------------


