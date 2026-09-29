"""Shared access to the ``system_config`` key/value table.

Several features keep their operator settings there (the label printer, LAN
access, the internet-outage page).  The UPSERT and the flag parsing live here
once so that every caller behaves the same way:

* a **missing row is never an error** — the caller's default wins;
* an **unreadable database** degrades to the default instead of breaking a
  request or a startup hook;
* writes always UPSERT (``UPDATE`` then ``INSERT`` when no row existed yet), so a
  key does not have to be seeded by hand.
"""
from __future__ import annotations

import logging
from typing import Optional

logger = logging.getLogger("hastama.system_config")

_TRUTHY = {"1", "true", "yes", "on", "enabled"}

_TABLE = "system_config"


def truthy(value) -> bool:
    """Interpret a stored flag (``"1"``, ``"true"``, ``True`` …)."""
    if isinstance(value, bool):
        return value
    return str(value or "").strip().lower() in _TRUTHY


def read_value(key: str) -> Optional[str]:
    """Stored value of *key*, or ``None`` when it is missing or unreadable."""
    try:
        from app.core.database import connect

        conn = connect()
        try:
            cursor = conn.cursor()
            cursor.execute(
                f"SELECT config_value FROM {_TABLE} WHERE config_key = ?", (key,)
            )
            row = cursor.fetchone()
        finally:
            conn.close()
    except Exception as exc:
        logger.warning("setting %s could not be read: %s: %s", key, type(exc).__name__, exc)
        return None
    if not row:
        return None
    value = row[0]
    return None if value is None else str(value)


def read_flag(key: str, default: bool = False) -> bool:
    """Boolean setting; *default* is used when the row is missing or unreadable."""
    value = read_value(key)
    return default if value is None else truthy(value)


def read_int(key: str, default: int, *, minimum: int = 0, maximum: int = 10**9) -> int:
    """Integer setting clamped into ``[minimum, maximum]``."""
    value = read_value(key)
    try:
        parsed = int(str(value).strip())
    except (TypeError, ValueError):
        return default
    return max(minimum, min(maximum, parsed))


def write_value(key: str, value: str, *, actor: str = "", description: str = "") -> bool:
    """UPSERT one setting; returns ``False`` when the write failed."""
    try:
        from app.core.database import connect

        conn = connect()
        try:
            cursor = conn.cursor()
            cursor.execute(
                f"""UPDATE {_TABLE} SET config_value = ?, updated_by = ?,
                           updated_at = SYSUTCDATETIME()
                    WHERE config_key = ?""",
                (value, actor, key),
            )
            if cursor.rowcount == 0:
                cursor.execute(
                    f"""INSERT INTO {_TABLE}
                            (config_key, config_value, description, updated_by, updated_at)
                        VALUES (?, ?, ?, ?, SYSUTCDATETIME())""",
                    (key, value, description, actor),
                )
            conn.commit()
        finally:
            conn.close()
    except Exception as exc:
        logger.error("setting %s could not be saved: %s: %s", key, type(exc).__name__, exc)
        return False
    return True


def write_flag(key: str, value: bool, *, actor: str = "", description: str = "") -> bool:
    """UPSERT a boolean setting as ``"1"`` / ``"0"``."""
    return write_value(key, "1" if value else "0", actor=actor, description=description)
