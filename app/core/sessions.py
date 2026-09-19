"""Server-side session registry and revocation.

Starlette's :class:`SessionMiddleware` stores the whole session inside a signed
cookie.  A signed cookie cannot be revoked: password reset, account disable,
role change or an administrator's "terminate session" action had **no effect**
on cookies already issued to a browser.

This module adds the smallest durable mechanism that fixes that, reusing the
existing ``dbo.user_sessions`` table (created by ``database/master_admin.sql``):

* every login mints a random ``sid`` which is stored both in the signed session
  and in ``user_sessions``;
* :class:`SessionRegistryMiddleware` validates the ``sid`` on each request
  (cached for a few seconds to bound database load);
* revocation helpers mark rows inactive, which invalidates the corresponding
  cookies at the next check.

The middleware deliberately *fails open* when the database is unreachable: every
other route needs the database anyway, and fail-closed would turn a transient
outage into a global logout.  This is recorded as a residual risk.
"""

from __future__ import annotations

import logging
import os
import secrets
import threading
import time
from typing import Optional

logger = logging.getLogger("hastama.sessions")

SESSION_TOKEN_KEY = "sid"
SESSION_ISSUED_KEY = "sid_iat"

_DEFAULT_CHECK_TTL = 5.0
_DEFAULT_IDLE_SECONDS = 1800.0
_ACTIVITY_TOUCH_SECONDS = 60.0

_cache_lock = threading.Lock()
_validation_cache: dict[str, tuple[float, bool]] = {}
_table_ready = False
_table_lock = threading.Lock()
_table_unavailable = False


def _env_float(name: str, default: float) -> float:
    try:
        return float(os.getenv(name, "") or default)
    except (TypeError, ValueError):
        return default


def check_ttl() -> float:
    return max(0.0, _env_float("HASTAMA_SESSION_CHECK_TTL", _DEFAULT_CHECK_TTL))


def idle_timeout_seconds() -> float:
    return max(0.0, _env_float("HASTAMA_IDLE_TIMEOUT_SECONDS", _DEFAULT_IDLE_SECONDS))


def cache_size() -> int:
    with _cache_lock:
        return len(_validation_cache)


def _cache_get(sid: str) -> Optional[bool]:
    with _cache_lock:
        entry = _validation_cache.get(sid)
    if not entry:
        return None
    expires_at, value = entry
    if expires_at < time.time():
        with _cache_lock:
            _validation_cache.pop(sid, None)
        return None
    return value


def _cache_set(sid: str, value: bool) -> None:
    ttl = check_ttl()
    with _cache_lock:
        _validation_cache[sid] = (time.time() + ttl, value)
        if len(_validation_cache) > 10000:  # defensive bound
            _validation_cache.clear()


def _cache_invalidate(sid: Optional[str] = None) -> None:
    with _cache_lock:
        if sid is None:
            _validation_cache.clear()
        else:
            _validation_cache.pop(sid, None)


def new_session_token() -> str:
    """Cryptographically strong opaque session identifier."""
    return secrets.token_urlsafe(32)


def _connect():
    from app.core.database import connect

    return connect()


def _ensure_table(cur) -> None:
    global _table_ready
    if _table_ready:
        return
    with _table_lock:
        if _table_ready:
            return
        cur.execute(
            """
            IF OBJECT_ID(N'dbo.user_sessions', N'U') IS NULL
            BEGIN
                CREATE TABLE dbo.user_sessions (
                    id              BIGINT IDENTITY(1,1) NOT NULL CONSTRAINT PK_user_sessions PRIMARY KEY,
                    session_key     NVARCHAR(255) NOT NULL,
                    username        NVARCHAR(255) NOT NULL,
                    ip_address      VARCHAR(45) NULL,
                    user_agent      NVARCHAR(500) NULL,
                    login_at        DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
                    last_activity   DATETIME2(3) NOT NULL DEFAULT SYSUTCDATETIME(),
                    logout_at       DATETIME2(3) NULL,
                    is_active       BIT NOT NULL DEFAULT 1,
                    terminated_by   NVARCHAR(255) NULL
                );
                CREATE INDEX IX_user_sessions_active ON dbo.user_sessions(is_active, last_activity DESC);
                CREATE INDEX IX_user_sessions_key    ON dbo.user_sessions(session_key);
            END
            """
        )
        _table_ready = True


def register_session(sid: str, username: str, ip_address: str = "", user_agent: str = "") -> bool:
    """Record a freshly issued session id.  Returns ``True`` on success."""
    global _table_unavailable
    conn = None
    try:
        conn = _connect()
        cur = conn.cursor()
        _ensure_table(cur)
        cur.execute(
            """
            INSERT INTO dbo.user_sessions (session_key, username, ip_address, user_agent)
            VALUES (?, ?, ?, ?)
            """,
            (sid, username, (ip_address or "")[:45] or None, (user_agent or "")[:500] or None),
        )
        conn.commit()
        _table_unavailable = False
        _cache_invalidate(sid)
        return True
    except Exception as exc:
        _table_unavailable = True
        logger.warning("session registry insert failed: %s", type(exc).__name__)
        return False
    finally:
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass


def _touch(cur, sid: str) -> None:
    cur.execute(
        "UPDATE dbo.user_sessions SET last_activity = SYSUTCDATETIME() "
        "WHERE session_key = ? AND is_active = 1 "
        "AND DATEDIFF(SECOND, last_activity, SYSUTCDATETIME()) >= ?",
        (sid, int(_ACTIVITY_TOUCH_SECONDS)),
    )


def validate_session(sid: str, username: str) -> bool:
    """Return ``True`` when *sid* is an active session for *username*."""
    if not sid:
        return False
    if _table_unavailable:
        # Registry unavailable (e.g. table not migrated): do not lock users out.
        return True
    cached = _cache_get(sid)
    if cached is not None:
        return cached

    conn = None
    valid = False
    try:
        conn = _connect()
        cur = conn.cursor()
        cur.execute(
            "SELECT username, is_active, last_activity FROM dbo.user_sessions WHERE session_key = ?",
            (sid,),
        )
        row = cur.fetchone()
        if row is None:
            valid = False
        else:
            row_username = str(row[0] or "").strip()
            is_active = bool(row[1])
            last_activity = row[2]
            valid = is_active and row_username.lower() == (username or "").strip().lower()
            if valid and last_activity is not None:
                idle_limit = idle_timeout_seconds()
                try:
                    age = (time.time() - last_activity.timestamp()) if hasattr(last_activity, "timestamp") else 0.0
                except Exception:
                    age = 0.0
                if idle_limit and age > idle_limit:
                    cur.execute(
                        "UPDATE dbo.user_sessions SET is_active = 0, logout_at = SYSUTCDATETIME() "
                        "WHERE session_key = ?",
                        (sid,),
                    )
                    conn.commit()
                    valid = False
            if valid:
                _touch(cur, sid)
                conn.commit()
    except Exception as exc:
        logger.warning("session validation unavailable: %s", type(exc).__name__)
        # Fail open on infrastructure errors, but remember it so we do not hammer
        # the database on every request while it is down.
        valid = True
    finally:
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass

    _cache_set(sid, valid)
    return valid


def revoke_session(sid: str, by_username: str = "system") -> bool:
    """Terminate a single session id."""
    conn = None
    try:
        conn = _connect()
        cur = conn.cursor()
        cur.execute(
            "UPDATE dbo.user_sessions SET is_active = 0, logout_at = SYSUTCDATETIME(), "
            "terminated_by = ? WHERE session_key = ? AND is_active = 1",
            (by_username, sid),
        )
        changed = cur.rowcount or 0
        conn.commit()
        _cache_invalidate(sid)
        return changed > 0
    except Exception as exc:
        logger.warning("session revoke failed: %s", type(exc).__name__)
        return False
    finally:
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass


def revoke_user_sessions(username: str, by_username: str = "system") -> int:
    """Terminate every active session of *username*.

    Called on password reset/change, account disable and role change.
    """
    conn = None
    try:
        conn = _connect()
        cur = conn.cursor()
        cur.execute(
            "UPDATE dbo.user_sessions SET is_active = 0, logout_at = SYSUTCDATETIME(), "
            "terminated_by = ? WHERE LTRIM(RTRIM(username)) = ? AND is_active = 1",
            (by_username, (username or "").strip()),
        )
        changed = cur.rowcount or 0
        conn.commit()
        _cache_invalidate()
        return changed
    except Exception as exc:
        logger.warning("bulk session revoke failed: %s", type(exc).__name__)
        return 0
    finally:
        if conn is not None:
            try:
                conn.close()
            except Exception:
                pass


def reset_state_for_tests() -> None:
    """Testing helper — clears caches and failure flags."""
    global _table_unavailable, _table_ready
    _cache_invalidate()
    _table_unavailable = False
    _table_ready = False
