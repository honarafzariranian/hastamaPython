#!/usr/bin/env python3
"""
Araz T7 Bridge Agent
====================

Runs on the PC that is whitelisted by the Araz T7 device.
Periodically fetches attendance records and pushes them to Hastama.

Data flow:
    Araz T7 Device → bridge_agent.py (this script) → Hastama REST API

Supports three data sources (tried in order):
  1. Direct TCP connection to device (fastest, needs protocol)
  2. Arazdb.mdb Access database (reliable, needs pyodbc + Access driver)
  3. T7PrsInOutLast.txt flat file (fallback, no dependencies)

Usage:
    # Install dependencies
    pip install requests pyodbc

    # Run with config file
    python bridge_agent.py --config bridge_config.json

    # Or run with command-line args
    python bridge_agent.py --device-ip 192.168.3.200 --device-port 1001 \
                           --hastama-url http://192.168.3.69:8000 \
                           --mdb-path "E:\\Hastama\\database\\Arazdb.mdb"

    # As a Windows service (using NSSM or similar):
    nssm install ArazBridge python bridge_agent.py --config bridge_config.json
    nssm start ArazBridge
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import re
import signal
import struct
import sys
import time
from datetime import datetime, timedelta
from pathlib import Path
from typing import Optional

import requests

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s [%(levelname)s] %(message)s",
    datefmt="%Y-%m-%d %H:%M:%S",
)
logger = logging.getLogger("araz_bridge")


# ---------------------------------------------------------------------------
# Card → Username mapping
# ---------------------------------------------------------------------------

class CardMapper:
    """Maps Araz device card numbers to Hastama usernames."""

    def __init__(self, mapping_file: Optional[str] = None):
        self._map: dict[str, str] = {}
        if mapping_file and os.path.isfile(mapping_file):
            self._load_file(mapping_file)

    def _load_file(self, path: str):
        """Load mapping from a JSON or CSV file.

        JSON format: {"00055364": "admin", "00002601": "user2", ...}
        CSV format (no header):
            00055364,admin
            00002601,user2
        """
        with open(path, encoding="utf-8") as f:
            content = f.read().strip()

        if content.startswith("{"):
            self._map = json.loads(content)
        else:
            for line in content.splitlines():
                line = line.strip()
                if not line or line.startswith("#"):
                    continue
                parts = line.split(",", 1)
                if len(parts) == 2:
                    card, username = parts[0].strip(), parts[1].strip()
                    self._map[card] = username

        logger.info("Loaded %d card→username mappings", len(self._map))

    def get_username(self, card_no: str) -> Optional[str]:
        return self._map.get(card_no)

    def save_file(self, path: str):
        with open(path, "w", encoding="utf-8") as f:
            json.dump(self._map, f, indent=2, ensure_ascii=False)


# ---------------------------------------------------------------------------
# Data source 1: Direct TCP connection to device
# ---------------------------------------------------------------------------

class DeviceProtocol:
    """Attempt direct TCP communication with the Araz T7 device."""

    REQUEST_HEADER = b"ARAZREQPROTO0002"
    RESPONSE_HEADER = b"ARAZRESPROTO0002"
    GS = chr(0x1D)
    RS = chr(0x1E)

    def __init__(self, ip: str, port: int = 1001, timeout: float = 5.0):
        self.ip = ip
        self.port = port
        self.timeout = timeout
        self._request_id = 0

    def _next_id(self) -> int:
        self._request_id = (self._request_id + 1) & 0xFFFF
        return self._request_id

    def _send_recv(self, verb: str, fields: dict | None = None) -> dict | None:
        """Send a request and parse the response."""
        import socket

        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.settimeout(self.timeout)
        try:
            sock.connect((self.ip, self.port))

            payload = verb
            if fields:
                for k, v in fields.items():
                    payload += f"{self.GS}{k}={v}"
            payload += self.GS + self.RS

            packet = (
                self.REQUEST_HEADER
                + struct.pack("<HH", self._next_id(), 1)
                + payload.encode("ascii")
            )

            sock.send(packet)
            time.sleep(0.3)

            response = b""
            try:
                response = sock.recv(65536)
            except socket.timeout:
                pass

            if not response or len(response) < 20:
                return None

            # Parse response
            req_id = struct.unpack("<H", response[16:18])[0]
            payload_str = response[20:].decode("ascii", errors="replace")

            fields_out = {}
            parts = payload_str.split(self.GS)
            for part in parts[1:]:
                if "=" in part:
                    k, _, v = part.partition("=")
                    fields_out[k.strip()] = v.strip()

            return {"verb": parts[0] if parts else "", "fields": fields_out}

        except Exception as exc:
            logger.debug("Direct device communication failed: %s", exc)
            return None
        finally:
            try:
                sock.close()
            except Exception:
                pass

    def get_records(self) -> list[dict] | None:
        """Try to get records directly from the device."""
        resp = self._send_recv("get_records")
        if not resp:
            return None

        records = []
        raw = resp.get("fields", {}).get("data", "")
        for line in raw.split("\x1E"):
            line = line.strip()
            if not line:
                continue
            parts = line.split("\t")
            if len(parts) >= 4:
                records.append({
                    "date": parts[0],       # YYMMDD
                    "card_no": parts[1],    # Card number
                    "time": parts[2],       # HHMM
                    "type": int(parts[3]),  # 0=enter, 1=exit
                })
        return records if records else None


# ---------------------------------------------------------------------------
# Data source 2: Arazdb.mdb Access database
# ---------------------------------------------------------------------------

class AccessDBSource:
    """Read attendance records from Arazdb.mdb via pyodbc."""

    def __init__(self, mdb_path: str, password: str = None):
        import os
        self.mdb_path = mdb_path
        self.password = password or os.getenv("ARAZ_ACCESS_PASSWORD", "")

    def get_records(self) -> list[dict] | None:
        try:
            import pyodbc
        except ImportError:
            logger.debug("pyodbc not installed, skipping Access DB source")
            return None

        if not os.path.isfile(self.mdb_path):
            logger.debug("Access DB not found: %s", self.mdb_path)
            return None

        try:
            conn_str = (
                r"DRIVER={Microsoft Access Driver (*.mdb, *.accdb)};"
                rf"DBQ={self.mdb_path};"
                rf"PWD={self.password};"
            )
            conn = pyodbc.connect(conn_str)
            cursor = conn.cursor()
            cursor.execute("""
                SELECT CardNo, Date, Time, InOutType
                FROM TPrsInOut
                ORDER BY Date ASC, Time ASC
            """)

            records = []
            for row in cursor.fetchall():
                card_no, date_val, time_val, in_out = row
                date_str = str(date_val).replace("/", "-")
                time_str = str(time_val).zfill(4)

                records.append({
                    "date": date_str,
                    "card_no": str(card_no),
                    "time": time_str,
                    "type": int(in_out) if in_out is not None else 0,
                })

            conn.close()
            logger.info("Read %d records from Access DB", len(records))
            return records

        except Exception as exc:
            logger.warning("Access DB read failed: %s", exc)
            return None


# ---------------------------------------------------------------------------
# Data source 3: T7PrsInOutLast.txt flat file
# ---------------------------------------------------------------------------

class TextFileSource:
    """Read attendance records from T7PrsInOutLast.txt."""

    def __init__(self, file_path: str):
        self.file_path = file_path

    def get_records(self) -> list[dict] | None:
        if not os.path.isfile(self.file_path):
            logger.debug("Text file not found: %s", self.file_path)
            return None

        try:
            records = []
            with open(self.file_path, encoding="ascii", errors="replace") as f:
                for line in f:
                    line = line.strip()
                    if not line:
                        continue
                    parts = line.split("\t")
                    if len(parts) >= 4:
                        records.append({
                            "date": parts[0],       # YYMMDD
                            "card_no": parts[1],    # Card number
                            "time": parts[2],       # HHMM
                            "type": int(parts[3]),  # 0=enter, 1=exit
                        })

            logger.info("Read %d records from text file", len(records))
            return records if records else None

        except Exception as exc:
            logger.warning("Text file read failed: %s", exc)
            return None


# ---------------------------------------------------------------------------
# Jalali / Gregorian date conversion
# ---------------------------------------------------------------------------

def yymmdd_to_gregorian(yymmdd: str) -> tuple[int, int, int]:
    """Convert Jalali YYMMDD to approximate Gregorian (year, month, day)."""
    try:
        import jdatetime
        sh_year = 1300 + int(yymmdd[:2])
        sh_month = int(yymmdd[2:4])
        sh_day = int(yymmdd[4:6])
        g = jdatetime.date(sh_year, sh_month, sh_day).togregorian()
        return g.year, g.month, g.day
    except Exception:
        return 2000, 1, 1


def time_hhmm(hhmm: str) -> str:
    """Convert HHMM string to HH:MM format."""
    hhmm = hhmm.strip().zfill(4)
    return f"{hhmm[:2]}:{hhmm[2:4]}"


# ---------------------------------------------------------------------------
# Main bridge agent
# ---------------------------------------------------------------------------

class BridgeAgent:
    """Fetches records from device and pushes them to Hastama."""

    def __init__(self, config: dict):
        self.hastama_url = config["hastama_url"].rstrip("/")
        self.hastama_secret = config.get("hastama_secret", "")
        self.device_ip = config.get("device_ip", "192.168.3.200")
        self.device_port = config.get("device_port", 1001)
        self.mdb_path = config.get("mdb_path", "")
        self.txt_path = config.get("txt_path", "")
        self.mapping_file = config.get("mapping_file", "card_mapping.json")
        self.poll_interval = config.get("poll_interval_seconds", 60)
        self.only_today = config.get("only_today", True)

        self.mapper = CardMapper(self.mapping_file)
        self.device = DeviceProtocol(self.device_ip, self.device_port)
        self.access_db = AccessDBSource(self.mdb_path) if self.mdb_path else None
        self.txt_file = TextFileSource(self.txt_path) if self.txt_path else None

        self._last_sync_time: Optional[str] = None
        self._running = True

    def fetch_records(self) -> list[dict]:
        """Try each data source in order until one returns records."""
        # Source 1: Direct device connection
        records = self.device.get_records()
        if records:
            logger.info("Got %d records from direct device connection", len(records))
            return records

        # Source 2: Access database
        if self.access_db:
            records = self.access_db.get_records()
            if records:
                logger.info("Got %d records from Access DB", len(records))
                return records

        # Source 3: Text file
        if self.txt_file:
            records = self.txt_file.get_records()
            if records:
                logger.info("Got %d records from text file", len(records))
                return records

        logger.warning("No records from any data source")
        return []

    def group_records_by_day(
        self, records: list[dict]
    ) -> dict[str, dict[str, dict]]:
        """
        Group records by card_no + date.

        Returns: {card_no: {date: {"entry": "HH:MM", "exit": "HH:MM"}}}
        """
        grouped: dict[str, dict[str, dict]] = {}

        for rec in records:
            card = rec["card_no"]
            yymmdd = rec["date"]
            hhmm = time_hhmm(rec["time"])
            rec_type = rec["type"]  # 0=enter, 1=exit

            if card not in grouped:
                grouped[card] = {}
            if yymmdd not in grouped[card]:
                grouped[card][yymmdd] = {"entry": None, "exit": None}

            if rec_type == 0:  # Entry
                existing = grouped[card][yymmdd]["entry"]
                # Keep the earliest entry
                if existing is None or hhmm < existing:
                    grouped[card][yymmdd]["entry"] = hhmm
            elif rec_type == 1:  # Exit
                existing = grouped[card][yymmdd]["exit"]
                # Keep the latest exit
                if existing is None or hhmm > existing:
                    grouped[card][yymmdd]["exit"] = hhmm

        return grouped

    def push_to_hastama(
        self, grouped: dict[str, dict[str, dict]]
    ) -> tuple[int, int]:
        """
        Push grouped records to Hastama via REST API.

        Uses the /api/araz/bridge-sync batch endpoint.
        Returns (success_count, fail_count).
        """
        # Build batch payload
        batch_records = []
        for card_no, dates in grouped.items():
            username = self.mapper.get_username(card_no)
            if not username:
                logger.warning("No username mapping for card %s, skipping", card_no)
                continue

            for yymmdd, times in dates.items():
                try:
                    sh_year = 1300 + int(yymmdd[:2])
                    sh_month = int(yymmdd[2:4])
                    sh_day = int(yymmdd[4:6])
                    tarikh = f"{sh_year}/{sh_month:02d}/{sh_day:02d}"
                except Exception:
                    continue

                entry = times["entry"] or "00:00"
                exit_ = times["exit"] or "00:00"

                batch_records.append({
                    "username": username,
                    "tarikh": tarikh,
                    "vorood": entry,
                    "khorooj": exit_,
                })

        if not batch_records:
            return 0, 0

        # Send as a batch
        payload = {"records": batch_records}
        if self.hastama_secret:
            payload["secret"] = self.hastama_secret

        try:
            resp = requests.post(
                f"{self.hastama_url}/api/araz/bridge-sync",
                json=payload,
                headers={"Content-Type": "application/json"},
                timeout=30,
            )
            result = resp.json()
            success = result.get("synced", 0)
            failed = result.get("failed", 0)
            skipped = result.get("skipped", 0)
            logger.info(
                "Hastama bridge-sync: %d synced, %d skipped, %d failed",
                success, skipped, failed,
            )
            return success, failed

        except Exception as exc:
            logger.error("Bridge sync HTTP error: %s", exc)
            return 0, len(batch_records)

    def run_once(self) -> dict:
        """Run a single sync cycle."""
        logger.info("Starting sync cycle...")

        # Fetch records
        records = self.fetch_records()
        if not records:
            return {"status": "no_records", "synced": 0, "failed": 0}

        # Filter to today only if configured
        if self.only_today:
            today = datetime.now().strftime("%y%m%d")
            records = [r for r in records if r["date"] == today]
            logger.info("Filtered to %d records for today", len(records))

        if not records:
            return {"status": "no_today_records", "synced": 0, "failed": 0}

        # Group by card + date
        grouped = self.group_records_by_day(records)
        logger.info(
            "Grouped into %d cards, %d total day-entries",
            len(grouped),
            sum(len(dates) for dates in grouped.values()),
        )

        # Push to Hastama
        success, failed = self.push_to_hastama(grouped)
        logger.info("Sync complete: %d success, %d failed", success, failed)

        return {"status": "ok", "synced": success, "failed": failed}

    def run_loop(self):
        """Run the sync loop until stopped."""
        logger.info(
            "Bridge agent starting. Hastama: %s, Device: %s:%d, Interval: %ds",
            self.hastama_url,
            self.device_ip,
            self.device_port,
            self.poll_interval,
        )

        def handle_signal(sig, frame):
            logger.info("Received signal %s, stopping...", sig)
            self._running = False

        signal.signal(signal.SIGINT, handle_signal)
        signal.signal(signal.SIGTERM, handle_signal)

        while self._running:
            try:
                self.run_once()
            except Exception as exc:
                logger.error("Sync cycle failed: %s", exc)

            # Sleep in small increments so we can respond to signals
            for _ in range(int(self.poll_interval)):
                if not self._running:
                    break
                time.sleep(1)

        logger.info("Bridge agent stopped")


# ---------------------------------------------------------------------------
# Config loader
# ---------------------------------------------------------------------------

DEFAULT_CONFIG = {
    "hastama_url": "http://192.168.3.69:8000",
    "hastama_secret": "",
    "device_ip": "192.168.3.200",
    "device_port": 1001,
    "mdb_path": "",
    "txt_path": "",
    "mapping_file": "card_mapping.json",
    "poll_interval_seconds": 60,
    "only_today": True,
}


def load_config(args) -> dict:
    config = dict(DEFAULT_CONFIG)

    # Load from file
    if args.config and os.path.isfile(args.config):
        with open(args.config, encoding="utf-8") as f:
            file_config = json.load(f)
        config.update(file_config)
        logger.info("Loaded config from %s", args.config)

    # Override with CLI args
    if args.hastama_url:
        config["hastama_url"] = args.hastama_url
    if args.device_ip:
        config["device_ip"] = args.device_ip
    if args.device_port:
        config["device_port"] = args.device_port
    if args.mdb_path:
        config["mdb_path"] = args.mdb_path
    if args.txt_path:
        config["txt_path"] = args.txt_path
    if args.mapping:
        config["mapping_file"] = args.mapping

    return config


# ---------------------------------------------------------------------------
# CLI
# ---------------------------------------------------------------------------

def main():
    parser = argparse.ArgumentParser(
        description="Araz T7 Bridge Agent — syncs attendance records to Hastama"
    )
    parser.add_argument(
        "--config", "-c",
        help="Path to JSON config file",
    )
    parser.add_argument(
        "--hastama-url",
        help="Hastama server URL (e.g. http://192.168.3.69:8000)",
    )
    parser.add_argument(
        "--device-ip",
        help="Araz T7 device IP address",
    )
    parser.add_argument(
        "--device-port",
        type=int,
        help="Araz T7 device TCP port",
    )
    parser.add_argument(
        "--mdb-path",
        help="Path to Arazdb.mdb Access database file",
    )
    parser.add_argument(
        "--txt-path",
        help="Path to T7PrsInOutLast.txt attendance log",
    )
    parser.add_argument(
        "--mapping",
        help="Path to card→username mapping file (JSON or CSV)",
    )
    parser.add_argument(
        "--once",
        action="store_true",
        help="Run a single sync cycle and exit",
    )
    parser.add_argument(
        "--init-config",
        action="store_true",
        help="Create a default bridge_config.json and exit",
    )

    args = parser.parse_args()

    if args.init_config:
        config_path = "bridge_config.json"
        with open(config_path, "w", encoding="utf-8") as f:
            json.dump(DEFAULT_CONFIG, f, indent=2, ensure_ascii=False)
        print(f"Created {config_path} with default settings.")
        print("Edit it with your device IP, Hastama URL, and card mappings.")
        return

    config = load_config(args)

    # Create sample card mapping if it doesn't exist
    if not os.path.isfile(config["mapping_file"]):
        sample = {
            "00055364": "admin",
            "00002601": "user1",
        }
        with open(config["mapping_file"], "w", encoding="utf-8") as f:
            json.dump(sample, f, indent=2, ensure_ascii=False)
        print(
            f"Created sample {config['mapping_file']}. "
            "Edit it with your actual card→username mappings."
        )

    agent = BridgeAgent(config)

    if args.once:
        result = agent.run_once()
        print(json.dumps(result, indent=2))
    else:
        agent.run_loop()


if __name__ == "__main__":
    main()
