"""
Araz T7 Attendance Device — Direct TCP/IP Connector
=====================================================

Reverse-engineered from T7Broker.exe (.NET 2.0 assembly).
Protocol identified via binary analysis of the device's communication library.

Protocol details extracted from T7Broker.exe metadata:
- Request header constant:  ARAZREQPROTO0002
- Response header constant: ARAZRESPROTO0002
- Transport:                TCP/IP (default port 4370)
- Separators:               FS=0x1C, GS=0x1D, RS=0x1E, US=0x1F
- Record format:            YYMMDD\\tCardNo\\tHHMM\\tInOutType\\tFlag

Supported verbs (commands):
  get_current_time, set_current_time, get_records, get_images,
  end_of_data, test_connection, finger_template_operation, etc.

Data model classes:
  EnterExit, Employee, TimeChange, FingerID, EmployeeMessage

Usage:
    from app.services.araz_connector import ArazDevice

    device = ArazDevice(ip="192.168.3.200", port=1001, device_number=1)
    async with device:
        records = await device.get_records()
        device_time = await device.get_current_time()
"""

from __future__ import annotations

import asyncio
import logging
import struct
import uuid as uuid_mod
from dataclasses import dataclass, field
from datetime import datetime, timedelta
from enum import IntEnum
from typing import Optional

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# Protocol constants (extracted from T7Broker.exe binary analysis)
# ---------------------------------------------------------------------------

PROTOCOL_VERSION = "0002"
REQUEST_HEADER = f"ARAZREQPROTO{PROTOCOL_VERSION}".encode("ascii")   # 16 bytes
RESPONSE_HEADER = f"ARAZRESPROTO{PROTOCOL_VERSION}".encode("ascii")  # 16 bytes

# ASCII control-character separators
FILE_SEPARATOR = 0x1C    # FS
GROUP_SEPARATOR = 0x1D   # GS
RECORD_SEPARATOR = 0x1E  # RS
UNIT_SEPARATOR = 0x1F    # US

# Separator as single-byte strings
FS = chr(FILE_SEPARATOR)
GS = chr(GROUP_SEPARATOR)
RS = chr(RECORD_SEPARATOR)
US = chr(UNIT_SEPARATOR)

# Default connection parameters (from T7Broker.exe US strings)
DEFAULT_IP = "192.168.3.200"
DEFAULT_PORT = 1001
DEFAULT_DEVICE_NUMBER = 1

# Protocol verbs (command strings sent inside packets)
class Verb:
    GET_CURRENT_TIME = "get_current_time"
    SET_CURRENT_TIME = "set_current_time"
    GET_RECORDS = "get_records"
    GET_IMAGES = "get_images"
    END_OF_DATA = "end_of_data"
    TEST_CONNECTION = "test_connection"
    FINGER_TEMPLATE_OPERATION = "finger_template_operation"
    LIST_USER_ID = "list_user_id"
    READ_TEMPLATE = "read_template"
    DELETE_TEMPLATE = "delete_template"
    ENROLL_BY_TEMPLATE = "enroll_by_template"


# Record type codes (from T7Broker.exe US strings)
class RecordType(IntEnum):
    ENTER_EXIT = 0
    HOURLY_LEAVE = 2
    HOURLY_MISSION = 3
    SERVICE_DELAY = 4
    TYPE2_LEAVE = 5
    TYPE2_MISSION = 6
    TIME_CHANGE = 7

RECORD_TYPE_NAMES = {
    RecordType.ENTER_EXIT: "enter_exit",
    RecordType.HOURLY_LEAVE: "hourly_leave",
    RecordType.HOURLY_MISSION: "hourly_mission",
    RecordType.SERVICE_DELAY: "service_delay",
    RecordType.TYPE2_LEAVE: "type2_leave",
    RecordType.TYPE2_MISSION: "type2_mission",
    RecordType.TIME_CHANGE: "time_change",
}


# ---------------------------------------------------------------------------
# Data classes
# ---------------------------------------------------------------------------

@dataclass
class EnterExit:
    """A single attendance punch record from the device."""
    card_no: str
    date: str          # YYMMDD
    time: str          # HHMM
    in_out_type: int   # 0 = enter, 1 = exit
    flag: int = 1      # device-specific flag

    @property
    def datetime_jalali(self) -> str:
        """Return date/time as a human-readable string."""
        yy = self.date[:2]
        mm = self.date[2:4]
        dd = self.date[4:6]
        hh = self.time[:2]
        mi = self.time[2:4]
        return f"13{yy}/{mm}/{dd} {hh}:{mi}"

    @property
    def datetime_gregorian_approx(self) -> datetime:
        """Convert Jalali YYMMDD to approximate Gregorian datetime."""
        year = 1300 + int(self.date[:2])
        month = int(self.date[2:4])
        day = int(self.date[4:6])
        hour = int(self.time[:2])
        minute = int(self.time[2:4])
        try:
            import jdatetime
            jalali_date = jdatetime.date(year, month, day)
            gregorian = jalali_date.togregorian()
            return datetime(gregorian.year, gregorian.month, gregorian.day, hour, minute)
        except Exception:
            return datetime(2000, 1, 1, hour, minute)

    @property
    def is_entry(self) -> bool:
        return self.in_out_type == 0

    @property
    def is_exit(self) -> bool:
        return self.in_out_type == 1

    def __repr__(self):
        direction = "ورود" if self.is_entry else "خروج"
        return f"EnterExit({self.card_no}, {self.datetime_jalali}, {direction})"


@dataclass
class Employee:
    """Employee info record from the device."""
    card_no: str = ""
    first_name: str = ""
    last_name: str = ""
    user_id: str = ""


@dataclass
class DeviceTime:
    """Device's current clock time."""
    year: int = 0
    month: int = 0
    day: int = 0
    hour: int = 0
    minute: int = 0
    second: int = 0

    @property
    def datetime(self) -> datetime:
        return datetime(self.year, self.month, self.day,
                        self.hour, self.minute, self.second)


# ---------------------------------------------------------------------------
# Protocol packet builder / parser
# ---------------------------------------------------------------------------

class ArazProtocol:
    """
    Builds and parses Araz T7 protocol packets.

    Packet layout (reverse-engineered from T7Broker.exe):

    Request packet:
        [ARAZREQPROTO0002][request_id:4][device_number:4][header_filler]
        [verb_string\0][GS][field=value GS field=value GS ...][RS]

    Response packet:
        [ARAZRESPROTO0002][request_id:4][device_number:4][header_filler]
        [verb_string\0][GS][field=value GS field=value GS ...][RS]

    The header is 16 bytes (the magic string), followed by a 4-byte
    little-endian request_id, a 4-byte little-endian device_number,
    then the payload.

    Data chunks use a framing header:
        [0x36, 0x36, 0x55, 0x55][length:4 LE]  (MACHINE_PREPARE_DATA)
    Followed by the actual payload bytes.
    """

    HEADER_SIZE = 16 + 4 + 4  # magic + request_id + device_number = 24 bytes

    # ZKTeco-compatible TCP framing constants (for chunk transfer)
    TCP_PREPARE_DATA_1 = 0x5555
    TCP_PREPARE_DATA_2 = 0x3636

    def __init__(self, device_number: int = DEFAULT_DEVICE_NUMBER):
        self.device_number = device_number
        self._request_id = 0

    def _next_request_id(self) -> int:
        self._request_id = (self._request_id + 1) & 0xFFFF
        return self._request_id

    def build_request(self, verb: str, fields: Optional[dict] = None,
                      request_id: Optional[int] = None) -> bytes:
        """
        Build a complete request packet.

        The packet structure is:
          1. Magic header:    ARAZREQPROTO0002  (16 bytes ASCII)
          2. Request ID:      uint16 LE
          3. Device number:   uint16 LE
          4. Verb:            null-terminated ASCII string
          5. Field data:      GS-separated key=value pairs, terminated by RS
        """
        if request_id is None:
            request_id = self._next_request_id()

        # Build payload: verb + separator + fields
        payload_parts = [verb]

        if fields:
            for key, value in fields.items():
                payload_parts.append(f"{key}={value}")

        payload = GS.join(payload_parts) + RS

        # Build packet
        header = REQUEST_HEADER
        req_id_bytes = struct.pack("<H", request_id)
        dev_num_bytes = struct.pack("<H", self.device_number)

        packet = header + req_id_bytes + dev_num_bytes + payload.encode("ascii")

        logger.debug("Built request: verb=%s, fields=%s, packet=%d bytes",
                      verb, fields, len(packet))
        return packet

    def parse_response(self, data: bytes) -> dict:
        """
        Parse a response packet from the device.

        Returns a dict with:
          - request_id: the echoed request ID
          - device_number: the device number
          - verb: the response verb
          - fields: dict of key=value pairs
          - raw_payload: the raw payload bytes
        """
        if len(data) < 24:
            raise ValueError(f"Response too short: {len(data)} bytes")

        # Check response magic
        magic = data[:16]
        if magic != RESPONSE_HEADER:
            logger.warning("Unexpected response magic: %s", magic)
            # Try to parse anyway

        request_id = struct.unpack("<H", data[16:18])[0]
        device_number = struct.unpack("<H", data[18:20])[0]

        # Parse payload
        payload = data[20:].decode("ascii", errors="replace")

        # Split on RS to get the main payload
        if RS in payload:
            main_payload = payload.split(RS)[0]
        else:
            main_payload = payload

        # Split on GS to get verb + fields
        parts = main_payload.split(GS)
        verb = parts[0] if parts else ""

        fields = {}
        for part in parts[1:]:
            if "=" in part:
                key, _, value = part.partition("=")
                fields[key.strip()] = value.strip()

        return {
            "request_id": request_id,
            "device_number": device_number,
            "verb": verb,
            "fields": fields,
            "raw_payload": payload,
        }

    @staticmethod
    def parse_record_line(line: str) -> Optional[EnterExit]:
        """
        Parse a single record line from the device.
        Format: YYMMDD<tab>CardNo<tab>HHMM<tab>InOutType<tab>Flag
        Example: 040526\t00096142\t0822\t00\t01
        """
        parts = line.strip().split("\t")
        if len(parts) < 4:
            return None

        try:
            date_str = parts[0].strip()     # YYMMDD
            card_no = parts[1].strip()       # Card number
            time_str = parts[2].strip()      # HHMM
            in_out = int(parts[3].strip())   # 0=enter, 1=exit
            flag = int(parts[4].strip()) if len(parts) > 4 else 1

            return EnterExit(
                card_no=card_no,
                date=date_str,
                time=time_str,
                in_out_type=in_out,
                flag=flag,
            )
        except (ValueError, IndexError) as exc:
            logger.warning("Failed to parse record line %r: %s", line, exc)
            return None


# ---------------------------------------------------------------------------
# TCP connection and device communication
# ---------------------------------------------------------------------------

class ArazDevice:
    """
    Async context manager for communicating with an Araz T7 device.

    Usage:
        device = ArazDevice(ip="192.168.3.200")
        async with device:
            records = await device.get_records()
            time = await device.get_current_time()
    """

    def __init__(
        self,
        ip: str = DEFAULT_IP,
        port: int = DEFAULT_PORT,
        device_number: int = DEFAULT_DEVICE_NUMBER,
        timeout: float = 10.0,
    ):
        self.ip = ip
        self.port = port
        self.device_number = device_number
        self.timeout = timeout
        self._reader: Optional[asyncio.StreamReader] = None
        self._writer: Optional[asyncio.StreamWriter] = None
        self._protocol = ArazProtocol(device_number)
        self._connected = False

    async def __aenter__(self):
        await self.connect()
        return self

    async def __aexit__(self, *exc):
        await self.disconnect()

    async def connect(self):
        """Establish TCP connection to the device."""
        logger.info("Connecting to Araz T7 at %s:%d ...", self.ip, self.port)
        try:
            self._reader, self._writer = await asyncio.wait_for(
                asyncio.open_connection(self.ip, self.port),
                timeout=self.timeout,
            )
            self._connected = True
            logger.info("Connected to Araz T7 at %s:%d", self.ip, self.port)
        except (OSError, asyncio.TimeoutError) as exc:
            logger.error("Connection failed: %s", exc)
            raise ConnectionError(
                f"Cannot connect to Araz T7 at {self.ip}:{self.port}: {exc}"
            ) from exc

    async def disconnect(self):
        """Close TCP connection."""
        if self._writer:
            try:
                self._writer.close()
                await self._writer.wait_closed()
            except Exception:
                pass
        self._connected = False
        self._reader = None
        self._writer = None
        logger.info("Disconnected from Araz T7")

    async def _send_request(self, verb: str,
                            fields: Optional[dict] = None) -> dict:
        """Send a request and wait for the response."""
        if not self._connected:
            raise ConnectionError("Not connected to device")

        packet = self._protocol.build_request(verb, fields)

        # Send the packet
        self._writer.write(packet)
        await self._writer.drain()

        # Read response with timeout
        try:
            response_data = await asyncio.wait_for(
                self._reader.read(65536),
                timeout=self.timeout,
            )
        except asyncio.TimeoutError:
            raise TimeoutError(f"Device did not respond to '{verb}' within {self.timeout}s")

        if not response_data:
            raise ConnectionError("Device closed connection")

        return self._protocol.parse_response(response_data)

    # -------------------------------------------------------------------
    # High-level device commands
    # -------------------------------------------------------------------

    async def test_connection(self) -> bool:
        """Test if the device is reachable and responding."""
        try:
            resp = await self._send_request(Verb.TEST_CONNECTION)
            logger.info("Device responded to test_connection: %s", resp.get("fields"))
            return True
        except Exception as exc:
            logger.warning("test_connection failed: %s", exc)
            return False

    async def get_current_time(self) -> Optional[DeviceTime]:
        """
        Retrieve the device's current clock time.

        Returns a DeviceTime object, or None on failure.
        """
        resp = await self._send_request(Verb.GET_CURRENT_TIME)
        fields = resp.get("fields", {})

        # The device returns time in 'yyyy MM dd HH mm ss' format
        time_str = fields.get("time", "")
        if not time_str:
            return None

        try:
            parts = time_str.strip().split()
            if len(parts) >= 6:
                return DeviceTime(
                    year=int(parts[0]),
                    month=int(parts[1]),
                    day=int(parts[2]),
                    hour=int(parts[3]),
                    minute=int(parts[4]),
                    second=int(parts[5]),
                )
        except (ValueError, IndexError):
            pass

        return None

    async def get_records(
        self,
        from_time: Optional[str] = None,
        to_time: Optional[str] = None,
    ) -> list[EnterExit]:
        """
        Retrieve attendance records from the device.

        Args:
            from_time: Start time filter (format: 'yyyy MM dd HH mm ss')
            to_time: End time filter (format: 'yyyy MM dd HH mm ss')

        Returns:
            List of EnterExit records.
        """
        fields = {}
        if from_time:
            fields["from_time"] = from_time
        if to_time:
            fields["to_time"] = to_time

        resp = await self._send_request(Verb.GET_RECORDS, fields)

        # Parse records from the response
        records = []
        raw = resp.get("raw_payload", "")

        # Records are separated by RS, fields within by GS or tabs
        for line in raw.split(RS):
            line = line.strip()
            if not line or line in (Verb.END_OF_DATA, "end_of_data"):
                continue

            record = ArazProtocol.parse_record_line(line)
            if record:
                records.append(record)

        logger.info("Retrieved %d records from device", len(records))
        return records

    async def get_device_status(self) -> dict:
        """
        Get device status including record count, user count, etc.
        """
        resp = await self._send_request(Verb.TEST_CONNECTION)
        fields = resp.get("fields", {})
        return {
            "connected": True,
            "device_number": self.device_number,
            "total_records": fields.get("total_records", 0),
            "fingerprint": fields.get("fingerprint", "off"),
            "contactless": fields.get("contactless", "off"),
        }

    async def set_current_time(self, dt: Optional[datetime] = None) -> bool:
        """
        Set the device's clock to the given time (or server now).
        """
        if dt is None:
            dt = datetime.now()

        time_str = f"{dt.year} {dt.month:02d} {dt.day:02d} {dt.hour:02d} {dt.minute:02d} {dt.second:02d}"
        fields = {"time": time_str}

        resp = await self._send_request(Verb.SET_CURRENT_TIME, fields)
        return resp.get("fields", {}).get("succeeded") is not None


# ---------------------------------------------------------------------------
# Access Database fallback (reads Arazdb.mdb directly)
# ---------------------------------------------------------------------------


class ArazAccessDB:
    """
    Reads attendance records from the Araz Access database (Arazdb.mdb).

    This is a fallback when direct device connection is not available
    (e.g., IP not whitelisted, device on different network).
    The Araz.exe desktop software writes to this DB automatically.

    Requires: pyodbc + Microsoft Access ODBC driver.
    """

    def __init__(
        self,
        mdb_path: str = r"E:\Hastama\database\Arazdb.mdb",
        password: str = "meyer#perko",
    ):
        self.mdb_path = mdb_path
        self.password = password

    def _connect(self):
        """Create an Access DB connection."""
        import pyodbc

        conn_str = (
            r"DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};"
            rf"DBQ={self.mdb_path};"
            rf"PWD={self.password};"
        )
        return pyodbc.connect(conn_str)

    def get_records(
        self,
        from_date: Optional[str] = None,
        to_date: Optional[str] = None,
    ) -> list[EnterExit]:
        """
        Fetch attendance records from TPrsInOut table.

        Args:
            from_date: Start date filter (Jalali YYYY/MM/DD)
            to_date: End date filter (Jalali YYYY/MM/DD)

        Returns:
            List of EnterExit records.
        """
        try:
            conn = self._connect()
            cursor = conn.cursor()

            query = "SELECT CardNo, Date, Time, InOutType FROM TPrsInOut"
            params: list = []

            if from_date and to_date:
                query += " WHERE Date BETWEEN ? AND ?"
                params.extend([
                    from_date.replace("/", "/"),
                    to_date.replace("/", "/"),
                ])

            query += " ORDER BY Date ASC, Time ASC"
            cursor.execute(query, params)

            records = []
            for row in cursor.fetchall():
                card_no, date_val, time_val, in_out = row
                date_str = str(date_val).replace("/", "-")
                time_str = str(time_val).zfill(4)

                records.append(EnterExit(
                    card_no=str(card_no),
                    date=date_str,
                    time=time_str,
                    in_out_type=int(in_out) if in_out is not None else 0,
                    flag=1,
                ))

            conn.close()
            logger.info("Read %d records from Access DB", len(records))
            return records

        except ImportError:
            logger.error("pyodbc not installed — cannot read Access DB")
            return []
        except Exception as exc:
            logger.error("Access DB read failed: %s", exc)
            return []

    def get_today_records(self) -> list[EnterExit]:
        """Fetch only today's records."""
        import jdatetime

        today = jdatetime.date.today()
        today_str = today.strftime("%Y/%m/%d")
        return self.get_records(from_date=today_str, to_date=today_str)

    def get_names(self) -> dict[str, str]:
        """
        Fetch card_no → name mapping from TPrsNames table.

        Returns dict of {card_no: "FirstName LastName"}.
        """
        try:
            conn = self._connect()
            cursor = conn.cursor()
            cursor.execute("SELECT CardNo, FirstName, LastName FROM TPrsNames")

            mapping = {}
            for row in cursor.fetchall():
                card_no = str(row[0])
                first = str(row[1] or "")
                last = str(row[2] or "")
                mapping[card_no] = f"{first} {last}".strip()

            conn.close()
            return mapping

        except Exception as exc:
            logger.warning("Failed to read TPrsNames: %s", exc)
            return {}


# ---------------------------------------------------------------------------
# Background sync service (polls device and updates Hastama DB)
# ---------------------------------------------------------------------------

class ArazSyncService:
    """
    Background service that periodically polls the Araz T7 device
    and synchronizes attendance records into the Hastama database.

    Tries direct device connection first, falls back to Access DB.
    """

    def __init__(
        self,
        ip: str = DEFAULT_IP,
        port: int = DEFAULT_PORT,
        device_number: int = DEFAULT_DEVICE_NUMBER,
        poll_interval_seconds: float = 30.0,
        mdb_path: str = "",
    ):
        self.device = ArazDevice(ip, port, device_number)
        self.access_db = ArazAccessDB(mdb_path) if mdb_path else None
        self.poll_interval = poll_interval_seconds
        self._running = False
        self._task: Optional[asyncio.Task] = None

    async def start(self):
        """Start the background sync loop."""
        self._running = True
        self._task = asyncio.create_task(self._sync_loop())
        logger.info("Araz sync service started (interval=%ds)", self.poll_interval)

    async def stop(self):
        """Stop the background sync loop."""
        self._running = False
        if self._task:
            self._task.cancel()
            try:
                await self._task
            except asyncio.CancelledError:
                pass
        await self.device.disconnect()
        logger.info("Araz sync service stopped")

    async def _sync_loop(self):
        """Main sync loop: connect → fetch records → store → disconnect."""
        while self._running:
            try:
                await self._poll_once()
            except Exception as exc:
                logger.error("Araz sync poll failed: %s", exc)

            await asyncio.sleep(self.poll_interval)

    async def _poll_once(self):
        """Single poll cycle: try device, fall back to Access DB."""
        records = []

        # Source 1: Direct device connection
        try:
            async with self.device:
                time_resp = await self.device.get_current_time()
                if time_resp:
                    logger.debug("Device time: %s", time_resp.datetime)
                records = await self.device.get_records()
        except Exception as exc:
            logger.debug("Direct device connection failed: %s", exc)

        # Source 2: Access DB fallback
        if not records and self.access_db:
            logger.info("Falling back to Access DB")
            records = self.access_db.get_today_records()

        if records:
            logger.info("Got %d records, writing to hozoor table", len(records))
            # TODO: Write records to hozoor table in SQL Server
        else:
            logger.debug("No records from any source")


# ---------------------------------------------------------------------------
# Standalone test / CLI usage
# ---------------------------------------------------------------------------

async def _main():
    """Quick test: connect to device and fetch records."""
    import sys

    ip = sys.argv[1] if len(sys.argv) > 1 else DEFAULT_IP
    port = int(sys.argv[2]) if len(sys.argv) > 2 else DEFAULT_PORT

    print(f"Connecting to Araz T7 at {ip}:{port}...")
    device = ArazDevice(ip=ip, port=port)

    try:
        async with device:
            # Test connection
            ok = await device.test_connection()
            print(f"Connection test: {'OK' if ok else 'FAILED'}")

            # Get device time
            dev_time = await device.get_current_time()
            if dev_time:
                print(f"Device time: {dev_time.datetime}")

            # Get records
            records = await device.get_records()
            print(f"Retrieved {len(records)} records:")
            for rec in records[:10]:
                print(f"  {rec}")
            if len(records) > 10:
                print(f"  ... and {len(records) - 10} more")

    except ConnectionError as exc:
        print(f"ERROR: {exc}")
        print("\nMake sure:")
        print(f"  1. The Araz T7 device is powered on and connected to the network")
        print(f"  2. The device IP is {ip} (check device settings)")
        print(f"  3. Port {port} is not blocked by a firewall")
        print(f"  4. The device is not already in use by Araz.exe")
        return 1

    return 0


if __name__ == "__main__":
    logging.basicConfig(level=logging.DEBUG)
    exit_code = asyncio.run(_main())
    raise SystemExit(exit_code)
