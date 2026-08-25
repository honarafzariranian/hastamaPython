"""Centralized SQL Server connection helpers.

The existing application uses pyodbc directly in several legacy modules. New and
migrated code should use ``connection()`` or ``connect()`` from this module. The
ODBC driver maintains its process-level pool by default; this wrapper centralizes
connection-string configuration and guarantees close/rollback handling.
"""
from __future__ import annotations

import os
from contextlib import contextmanager
from typing import Iterator

import pyodbc

_DEFAULT_CONNECTION_STRING = (
    "DRIVER={ODBC Driver 17 for SQL Server};"
    r"SERVER=localhost\SQLEXPRESS;"
    "DATABASE=userDB;Trusted_Connection=yes;"
)


def connection_string() -> str:
    return os.getenv("DATABASE_URL", _DEFAULT_CONNECTION_STRING)


def connect() -> pyodbc.Connection:
    """Open a SQL Server connection using the configured ODBC pool."""
    return pyodbc.connect(connection_string(), timeout=int(os.getenv("DB_CONNECT_TIMEOUT", "5")))


@contextmanager
def connection(*, commit: bool = False) -> Iterator[pyodbc.Connection]:
    """Yield a connection and always close it, rolling back failed work."""
    conn = connect()
    try:
        yield conn
        if commit:
            conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


@contextmanager
def cursor(*, commit: bool = False):
    """Yield a cursor with the same transaction guarantees as ``connection``."""
    with connection(commit=commit) as conn:
        cur = conn.cursor()
        try:
            yield cur
        finally:
            cur.close()
