"""
FastAPI endpoints for Araz T7 attendance device.

Provides REST API for:
  - Testing device connectivity
  - Fetching attendance records directly from the device
  - Setting device time
  - Syncing device records into Hastama database

Base URL: /api/araz/
"""

from __future__ import annotations

import asyncio
import hmac
import logging
from datetime import datetime, timedelta
import os
from typing import Optional

from fastapi import APIRouter, HTTPException, Query, Request
from fastapi.responses import JSONResponse
from pydantic import BaseModel

from app.core.net import user_agent
from app.services.araz_connector import (
    ArazDevice,
    DEFAULT_IP,
    DEFAULT_PORT,
    DEFAULT_DEVICE_NUMBER,
    ArazProtocol,
)
from app.services.audit import log_event

# Bridge auth token — mandatory in production; the endpoint fails closed when unset.
BRIDGE_SECRET = os.environ.get("ARAZ_BRIDGE_SECRET", "")

# Upper bound for one batch (the bridge pushes one day of records per call).
MAX_BRIDGE_RECORDS = 5000

logger = logging.getLogger(__name__)

router = APIRouter(prefix="/api/araz", tags=["araz-device"])


def _require_admin(request: Request):
    """Check that the request comes from an authenticated admin session."""
    username = str(request.session.get("username") or "").strip()
    if not username:
        return JSONResponse(status_code=401, content={"success": False, "error": "ورود لازم است."})
    if request.session.get("is_admin") is not True:
        return JSONResponse(status_code=403, content={"success": False, "error": "دسترسی مدیریتی ندارید."})
    return None


# ---------------------------------------------------------------------------
# Pydantic response models
# ---------------------------------------------------------------------------

class DeviceTestResponse(BaseModel):
    connected: bool
    ip: str
    port: int
    device_time: Optional[str] = None
    error: Optional[str] = None


class DeviceTimeResponse(BaseModel):
    device_time: str
    server_time: str


class AttendanceRecordResponse(BaseModel):
    card_no: str
    date: str
    time: str
    in_out_type: int
    direction: str  # "ورود" or "خروج"
    datetime_jalali: str


class SyncResponse(BaseModel):
    success: bool
    records_count: int
    message: str


# ---------------------------------------------------------------------------
# Configuration endpoint (stores device IP/port in session or DB)
# ---------------------------------------------------------------------------

# In-memory device config (should be moved to DB/settings in production)
_device_config = {
    "ip": DEFAULT_IP,
    "port": DEFAULT_PORT,
    "device_number": DEFAULT_DEVICE_NUMBER,
    "timeout": 10.0,
}


@router.get("/config")
async def get_device_config(request: Request):
    """Get current device connection configuration."""
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    return _device_config


class DeviceConfigUpdate(BaseModel):
    ip: Optional[str] = None
    port: Optional[int] = None
    device_number: Optional[int] = None
    timeout: Optional[float] = None


@router.post("/config")
async def update_device_config(request: Request, update: DeviceConfigUpdate):
    """Update device connection configuration."""
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    if update.ip is not None:
        _device_config["ip"] = update.ip
    if update.port is not None:
        _device_config["port"] = update.port
    if update.device_number is not None:
        _device_config["device_number"] = update.device_number
    if update.timeout is not None:
        _device_config["timeout"] = update.timeout
    return {"status": "ok", "config": _device_config}


# ---------------------------------------------------------------------------
# Device communication endpoints
# ---------------------------------------------------------------------------

@router.get("/test", response_model=DeviceTestResponse)
async def test_device_connection(request: Request):
    """
    Test connectivity to the Araz T7 device.
    Returns device status including current time.
    """
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    device = ArazDevice(
        ip=_device_config["ip"],
        port=_device_config["port"],
        device_number=_device_config["device_number"],
        timeout=_device_config["timeout"],
    )

    try:
        async with device:
            ok = await device.test_connection()
            dev_time = await device.get_current_time()

            return DeviceTestResponse(
                connected=ok,
                ip=device.ip,
                port=device.port,
                device_time=dev_time.datetime.isoformat() if dev_time else None,
            )
    except ConnectionError as exc:
        return DeviceTestResponse(
            connected=False,
            ip=device.ip,
            port=device.port,
            error=str(exc),
        )
    except Exception as exc:
        logger.error("Device test failed: %s", exc)
        return DeviceTestResponse(
            connected=False,
            ip=device.ip,
            port=device.port,
            error=f"Unexpected error: {exc}",
        )


@router.get("/time", response_model=DeviceTimeResponse)
async def get_device_time(request: Request):
    """Get the current time from the Araz T7 device."""
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    device = ArazDevice(
        ip=_device_config["ip"],
        port=_device_config["port"],
        device_number=_device_config["device_number"],
        timeout=_device_config["timeout"],
    )

    async with device:
        dev_time = await device.get_current_time()
        if not dev_time:
            raise HTTPException(status_code=502, detail="Device did not return time")

        return DeviceTimeResponse(
            device_time=dev_time.datetime.isoformat(),
            server_time=datetime.now().isoformat(),
        )


@router.post("/time/sync")
async def sync_device_time(request: Request):
    """Sync the Araz T7 device clock to the server's current time."""
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    device = ArazDevice(
        ip=_device_config["ip"],
        port=_device_config["port"],
        device_number=_device_config["device_number"],
        timeout=_device_config["timeout"],
    )

    async with device:
        ok = await device.set_current_time()
        return {"success": ok, "server_time": datetime.now().isoformat()}


@router.get("/records", response_model=list[AttendanceRecordResponse])
async def get_device_records(
    request: Request,
    from_date: Optional[str] = Query(None, description="Start date (yyyy/MM/dd)"),
    to_date: Optional[str] = Query(None, description="End date (yyyy/MM/dd)"),
):
    """
    Fetch attendance records directly from the Araz T7 device.

    Optional date filters use Gregorian dates (yyyy/MM/dd).
    Without filters, returns all records on the device.
    """
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    device = ArazDevice(
        ip=_device_config["ip"],
        port=_device_config["port"],
        device_number=_device_config["device_number"],
        timeout=_device_config["timeout"],
    )

    from_time = None
    to_time = None

    if from_date:
        from_time = from_date.replace("/", " ") + " 00 00 00"
    if to_date:
        to_time = to_date.replace("/", " ") + " 23 59 59"

    async with device:
        records = await device.get_records(from_time=from_time, to_time=to_time)

        return [
            AttendanceRecordResponse(
                card_no=r.card_no,
                date=r.date,
                time=r.time,
                in_out_type=r.in_out_type,
                direction="ورود" if r.is_entry else "خروج",
                datetime_jalali=r.datetime_jalali,
            )
            for r in records
        ]


@router.post("/sync")
async def sync_records_to_database(
    request: Request,
    from_date: Optional[str] = Query(None, description="Start date (yyyy/MM/dd)"),
    to_date: Optional[str] = Query(None, description="End date (yyyy/MM/dd)"),
):
    """
    Sync attendance records from the Araz T7 device directly
    into the Hastama attendance database (hozoor table).

    This replaces the Araz.exe + Access DB pipeline.
    """
    auth_err = _require_admin(request)
    if auth_err:
        return auth_err
    device = ArazDevice(
        ip=_device_config["ip"],
        port=_device_config["port"],
        device_number=_device_config["device_number"],
        timeout=_device_config["timeout"],
    )

    # Default to today if no date range specified
    if not from_date:
        from_date = datetime.now().strftime("%Y/%m/%d")
    if not to_date:
        to_date = datetime.now().strftime("%Y/%m/%d")

    from_time = from_date.replace("/", " ") + " 00 00 00"
    to_time = to_date.replace("/", " ") + " 23 59 59"

    async with device:
        records = await device.get_records(from_time=from_time, to_time=to_time)

        if not records:
            return SyncResponse(
                success=True,
                records_count=0,
                message="No records to sync from device",
            )

        # Write records to hozoor table
        synced = 0
        try:
            from app.db import get_db_cursor
            cursor = get_db_cursor()

            for rec in records:
                # Convert device record to hozoor table format
                # Date: YYMMDD → Gregorian date
                # Time: HHMM → HH:MM
                time_str = f"{rec.time[:2]}:{rec.time[2:4]}"

                # Insert or update hozoor record
                # Note: card_no → username mapping needs hozoor_num lookup
                cursor.execute("""
                    SELECT username FROM users WHERE hozoor_num = ?
                """, (rec.card_no,))
                user_row = cursor.fetchone()

                if user_row:
                    username = user_row[0]
                    # Check if record already exists
                    cursor.execute("""
                        SELECT id FROM hozoor
                        WHERE username = ? AND date = ?
                    """, (username, datetime.now().strftime("%Y-%m-%d")))
                    existing = cursor.fetchone()

                    if existing:
                        if rec.is_entry:
                            cursor.execute("""
                                UPDATE hozoor SET vrood = ? WHERE id = ?
                            """, (time_str, existing[0]))
                        else:
                            cursor.execute("""
                                UPDATE hozoor SET khoroj = ? WHERE id = ?
                            """, (time_str, existing[0]))
                    else:
                        if rec.is_entry:
                            cursor.execute("""
                                INSERT INTO hozoor (username, date, vrood)
                                VALUES (?, ?, ?)
                            """, (username, datetime.now().strftime("%Y-%m-%d"), time_str))
                        else:
                            cursor.execute("""
                                INSERT INTO hozoor (username, date, khoroj)
                                VALUES (?, ?, ?)
                            """, (username, datetime.now().strftime("%Y-%m-%d"), time_str))

                    synced += 1

            # Commit changes
            cursor.connection.commit()

        except Exception as exc:
            logger.error("Database sync failed: %s", exc)
            return SyncResponse(
                success=False,
                records_count=synced,
                message=f"Database error: {exc}",
            )

        return SyncResponse(
            success=True,
            records_count=synced,
            message=f"Successfully synced {synced} records from device",
        )


# ---------------------------------------------------------------------------
# Bridge agent sync endpoint
# ---------------------------------------------------------------------------


class BridgeRecord(BaseModel):
    """Single attendance record from the bridge agent."""
    username: str
    tarikh: str  # Jalali date: YYYY/MM/DD
    vorood: str  # Entry time: HH:MM
    khorooj: str  # Exit time: HH:MM


class BridgeSyncRequest(BaseModel):
    """Batch of attendance records from the bridge agent."""
    records: list[BridgeRecord]
    secret: str = ""  # Auth token


class BridgeSyncResponse(BaseModel):
    success: bool
    synced: int
    skipped: int
    failed: int
    message: str


@router.post("/bridge-sync", response_model=BridgeSyncResponse)
async def bridge_sync(req: BridgeSyncRequest, request: Request):
    """
    Receive attendance records from the Araz bridge agent.

    The bridge agent runs on the PC that is whitelisted by the device.
    It fetches records from the device and pushes them here.

    Accepts:
    {
        "records": [
            {
                "username": "admin",
                "tarikh": "1404/06/06",
                "vorood": "08:30",
                "khorooj": "17:00"
            }
        ],
        "secret": "..."
    }
    """
    # ── Authentication: fail closed, constant-time, rate limited ──
    if not BRIDGE_SECRET:
        # Refuse to sync at all when no secret is configured (fail closed).
        logger.error("bridge-sync rejected: ARAZ_BRIDGE_SECRET is not configured")
        return JSONResponse(
            status_code=503,
            content={"success": False, "error": "ARAZ_BRIDGE_SECRET not configured"},
        )

    from app.core.net import client_ip as _client_ip
    from app.core.rate_limit import limiter

    if not limiter.allow(f"bridge-sync:{_client_ip(request)}", limit=60, window_seconds=60):
        raise HTTPException(status_code=429, detail="Too many bridge-sync requests")

    if not hmac.compare_digest(str(req.secret or ""), BRIDGE_SECRET):
        log_event(
            event_type="SECURITY", action="bridge_sync_rejected",
            module="araz", status="failure", severity="high",
            ip_address=_client_ip(request), user_agent=user_agent(request),
            metadata={"reason": "invalid_secret"},
        )
        raise HTTPException(status_code=401, detail="Invalid bridge secret")

    if not req.records:
        return BridgeSyncResponse(
            success=True, synced=0, skipped=0, failed=0,
            message="No records provided",
        )

    if len(req.records) > MAX_BRIDGE_RECORDS:
        raise HTTPException(
            status_code=413,
            detail=f"Too many records in one batch (max {MAX_BRIDGE_RECORDS})",
        )

    synced = 0
    skipped = 0
    failed = 0
    errors = []
    conn = None

    try:
        from persiantools.jdatetime import JalaliDate

        # The bridge previously borrowed the process-wide ``app.main.cursor``
        # connection: pyodbc connections are not thread safe, so concurrent
        # usage could interleave statements and commit the wrong transaction.
        from app.core.database import connect as db_connect

        conn = db_connect()
        cursor = conn.cursor()

        # Only known employees may be written to the attendance table.
        cursor.execute(
            "SELECT LTRIM(RTRIM(username)) FROM user_table WHERE ISNULL(is_active,'active') = 'active'"
        )
        known_users = {str(row[0] or "").strip().lower() for row in cursor.fetchall()}

        for rec in req.records:
            try:
                username = str(rec.username or "").strip()
                if not username or len(username) > 50:
                    failed += 1
                    errors.append(f"invalid username: {rec.username!r}")
                    continue
                if username.lower() not in known_users:
                    failed += 1
                    errors.append(f"unknown user: {username}")
                    continue

                # Parse Jalali date
                parts = rec.tarikh.replace("\u200f", "").split("/")
                if len(parts) != 3:
                    failed += 1
                    errors.append(f"Bad date format: {rec.tarikh}")
                    continue

                y, m, d = int(parts[0]), int(parts[1]), int(parts[2])
                gregorian = JalaliDate(y, m, d).to_gregorian()
                tarikh_obj = gregorian

                # Reject implausible dates instead of writing them to hozoor.
                if not (2015 <= tarikh_obj.year <= 2100):
                    failed += 1
                    errors.append(f"date out of range: {rec.tarikh}")
                    continue

                # Parse times
                vorood_obj = datetime.strptime(rec.vorood.strip(), "%H:%M").time()
                khorooj_obj = datetime.strptime(rec.khorooj.strip(), "%H:%M").time()

                # Check for existing record
                cursor.execute(
                    "SELECT id FROM hozoor WHERE username = ? AND [date] = ?",
                    (username, tarikh_obj),
                )
                existing = cursor.fetchone()

                if existing:
                    # Update — only update non-zero times
                    updates = []
                    params = []
                    if rec.vorood and rec.vorood != "00:00":
                        updates.append("vrood = ?")
                        params.append(vorood_obj)
                    if rec.khorooj and rec.khorooj != "00:00":
                        updates.append("khoroj = ?")
                        params.append(khorooj_obj)

                    if updates:
                        params.append(existing[0])
                        cursor.execute(
                            f"UPDATE hozoor SET {', '.join(updates)} WHERE id = ?",
                            params,
                        )
                        synced += 1
                    else:
                        skipped += 1
                else:
                    # Insert new record
                    cursor.execute(
                        """INSERT INTO hozoor (username, [date], vrood, khoroj)
                           VALUES (?, ?, ?, ?)""",
                        (username, tarikh_obj, vorood_obj, khorooj_obj),
                    )
                    synced += 1

            except Exception as exc:
                failed += 1
                errors.append(f"{rec.username}@{rec.tarikh}: {type(exc).__name__}")
                logger.warning("Bridge sync record error: %s: %s", type(exc).__name__, exc)

        conn.commit()

    except Exception as exc:
        if conn is not None:
            try:
                conn.rollback()
            except Exception:
                pass
        logger.error("Bridge sync failed: %s: %s", type(exc).__name__, exc)
        # Never return raw driver/database error text to the caller.
        return BridgeSyncResponse(
            success=False, synced=synced, skipped=skipped,
            failed=failed, message="Database error while applying bridge records",
        )
    finally:
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass

    msg = f"Synced: {synced}, Skipped: {skipped}, Failed: {failed}"
    if errors:
        msg += f" Errors: {'; '.join(errors[:5])}"

    log_event(
        event_type="INTEGRATION", action="bridge_sync",
        module="araz", status="success" if failed == 0 else "partial",
        severity="low" if failed == 0 else "medium",
        ip_address=_client_ip(request),
        metadata={"synced": synced, "skipped": skipped, "failed": failed},
    )
    logger.info("Bridge sync: %s", msg)
    return BridgeSyncResponse(
        success=failed == 0,
        synced=synced,
        skipped=skipped,
        failed=failed,
        message=msg,
    )
