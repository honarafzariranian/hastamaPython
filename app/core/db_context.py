"""Per-request database connection isolation.

Legacy ``app.main`` opens one module level ``pyodbc`` connection and shares a
single cursor between every request.  ``pyodbc`` cursors are not safe to share:
two concurrent requests interleave ``execute``/``fetchone`` calls, which can
return another request's rows (cross-user data disclosure) or commit partially
applied statements.

This module keeps the existing ``conn`` / ``cursor`` names working while making
each HTTP request operate on its own connection:

* ``RequestConnectionMiddleware`` binds a fresh connection to a
  :class:`contextvars.ContextVar` for the duration of the request.
* ``connection_proxy()`` / ``cursor_proxy()`` resolve to the request connection
  when one is bound, otherwise to a process wide (thread local) fallback which
  preserves the old behaviour for startup code, background jobs and scripts.

Nothing about the call sites changes: ``cursor.execute(...)`` and
``conn.commit()`` keep working, they simply resolve to request-scoped objects.
"""

from __future__ import annotations

import contextvars
import logging
import threading
from typing import Any, Optional

logger = logging.getLogger("hastama.db")

_request_connection: contextvars.ContextVar[Any] = contextvars.ContextVar(
    "hastama_request_connection", default=None
)
_request_cursor: contextvars.ContextVar[Any] = contextvars.ContextVar(
    "hastama_request_cursor", default=None
)

_local = threading.local()


def _connect() -> Any:
    # Imported lazily so that a test harness may inject a fake driver first.
    from app.core.database import connect

    return connect()


def current_connection() -> Any:
    """Return the connection bound to this request (creating one if needed)."""
    conn = _request_connection.get()
    if conn is not None:
        return conn
    conn = getattr(_local, "connection", None)
    if conn is None:
        conn = _connect()
        _local.connection = conn
    return conn


def current_cursor() -> Any:
    """Return the cursor belonging to the current request/task.

    The cursor is resolved *per request* rather than cached on the shared proxy
    object.  A cached cursor would be handed to whichever request happened to
    touch the proxy next (the event loop interleaves requests inside one
    thread), which is exactly the cross-request leak this module exists to
    prevent.
    """
    cursor = _request_cursor.get()
    if cursor is not None:
        return cursor
    legacy = getattr(_local, "cursor", None)
    if legacy is None:
        legacy = current_connection().cursor()
        _local.cursor = legacy
    return legacy


def bind_request_connection(conn: Any) -> contextvars.Token:
    return _request_connection.set(conn)


def bind_request_cursor(cursor: Any) -> contextvars.Token:
    return _request_cursor.set(cursor)


def reset_request_connection(token: contextvars.Token) -> None:
    try:
        _request_connection.reset(token)
    except Exception:  # pragma: no cover - defensive
        pass


def reset_request_cursor(token: contextvars.Token) -> None:
    try:
        _request_cursor.reset(token)
    except Exception:  # pragma: no cover - defensive
        pass


def discard_thread_connection() -> None:
    """Drop the thread-local fallback connection (used on shutdown/tests)."""
    cursor = getattr(_local, "cursor", None)
    if cursor is not None:
        _close_quietly(cursor)
        _local.cursor = None
    conn = getattr(_local, "connection", None)
    if conn is not None:
        try:
            conn.close()
        except Exception:
            pass
        _local.connection = None


def _close_quietly(obj: Optional[Any]) -> None:
    if obj is None:
        return
    try:
        obj.close()
    except Exception:
        pass


class CursorProxy:
    """Delegates every cursor operation to the current request's cursor.

    The proxy itself is stateless: it may be created once at import time (which
    is what ``app.main`` does) and shared by every request.
    """

    __slots__ = ()

    def _resolve(self) -> Any:
        return current_cursor()

    def __getattr__(self, name: str) -> Any:
        return getattr(self._resolve(), name)

    def __iter__(self):  # pragma: no cover - convenience for `for row in cursor`
        return iter(self._resolve())

    def __enter__(self) -> "CursorProxy":
        return self

    def __exit__(self, *exc_info) -> None:
        self.close()

    def close(self) -> None:
        """Close the *request* cursor and drop it from the current context."""
        cursor = _request_cursor.get() or getattr(_local, "cursor", None)
        if cursor is not None:
            _close_quietly(cursor)
        try:
            _request_cursor.set(None)
        except Exception:  # pragma: no cover - defensive
            pass
        if getattr(_local, "cursor", None) is cursor:
            _local.cursor = None

    def __enter__(self) -> "CursorProxy":
        return self

    def __exit__(self, *exc_info) -> None:
        self.close()


class ConnectionProxy:
    """Delegates connection operations to the current request connection."""

    __slots__ = ()

    def _resolve(self) -> Any:
        return current_connection()

    def cursor(self) -> CursorProxy:
        # A fresh proxy; the underlying cursor is created on first use so that
        # import-time module state does not require a live database.
        return CursorProxy()

    def __getattr__(self, name: str) -> Any:
        return getattr(self._resolve(), name)

    def close(self) -> None:
        # Request connections are owned (and closed) by the middleware; closing
        # the proxy must not tear down a connection shared with other work.
        return None


_connection_proxy_singleton: Optional[ConnectionProxy] = None
_cursor_proxy_singleton: Optional[CursorProxy] = None


def connection_proxy() -> ConnectionProxy:
    """Process-wide proxy object (safe to share — it resolves per request)."""
    global _connection_proxy_singleton
    if _connection_proxy_singleton is None:
        _connection_proxy_singleton = ConnectionProxy()
    return _connection_proxy_singleton


def cursor_proxy() -> CursorProxy:
    """Process-wide cursor proxy (safe to share — it resolves per request)."""
    global _cursor_proxy_singleton
    if _cursor_proxy_singleton is None:
        _cursor_proxy_singleton = CursorProxy()
    return _cursor_proxy_singleton


class RequestConnectionMiddleware:
    """Raw ASGI middleware binding a per-request database connection."""

    def __init__(self, app) -> None:
        self.app = app

    async def __call__(self, scope, receive, send) -> None:
        if scope.get("type") != "http":
            await self.app(scope, receive, send)
            return

        conn = None
        cursor = None
        conn_token = None
        cursor_token = None
        try:
            conn = _connect()
            cursor = conn.cursor()
            conn_token = bind_request_connection(conn)
            cursor_token = bind_request_cursor(cursor)
        except Exception as exc:  # pragma: no cover - depends on environment
            # Falling back keeps the legacy shared connection semantics rather
            # than taking the whole application down with the database.
            logger.warning("per-request database connection unavailable: %s", type(exc).__name__)

        try:
            await self.app(scope, receive, send)
        finally:
            if cursor_token is not None:
                reset_request_cursor(cursor_token)
            if conn_token is not None:
                reset_request_connection(conn_token)
            _close_quietly(cursor)
            if conn is not None:
                try:
                    conn.rollback()
                except Exception:
                    pass
                _close_quietly(conn)
