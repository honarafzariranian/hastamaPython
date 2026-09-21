"""
Full database export for SQL Server (userDB).

Usage:
    python scripts/export_db.py                 # → database/exports/latest.sql
    python scripts/export_db.py -o backup.sql   # → custom path

Requires: pyodbc, ODBC Driver 17 for SQL Server.
"""
from __future__ import annotations

import os
import sys
import argparse
from datetime import datetime
from pathlib import Path

import pyodbc

# ── Connection ────────────────────────────────────────────────────────
_DRIVER = "ODBC Driver 17 for SQL Server"
_DEFAULT_SERVER = r"localhost\SQLEXPRESS"
_DEFAULT_DB = "userDB"


def _conn_str(server: str, database: str) -> str:
    return (
        f"DRIVER={{{_DRIVER}}};"
        f"SERVER={server};"
        f"DATABASE={database};"
        f"Trusted_Connection=yes;"
    )


# ── Schema export ─────────────────────────────────────────────────────
def _schema_sql(conn: pyodbc.Connection) -> str:
    """Return T-SQL that recreates every user table (schema + data)."""
    cur = conn.cursor()

    # Gather all user tables
    cur.execute(
        "SELECT TABLE_SCHEMA, TABLE_NAME FROM INFORMATION_SCHEMA.TABLES "
        "WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_SCHEMA, TABLE_NAME"
    )
    tables = cur.fetchall()

    parts: list[str] = [
        f"-- Hastama full dump  ({datetime.now():%Y-%m-%d %H:%M:%S})",
        f"-- Database: {conn.getinfo(pyodbc.SQL_DATA_SOURCE_NAME)}",
        "SET NOCOUNT ON;",
        "SET XACT_ABORT ON;",
        "",
    ]

    for schema, table in tables:
        fq = f"[{schema}].[{table}]"

        # ── CREATE TABLE ──
        cur.execute(
            "SELECT COLUMN_NAME, DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, "
            "IS_NULLABLE, COLUMN_DEFAULT "
            "FROM INFORMATION_SCHEMA.COLUMNS "
            "WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? "
            "ORDER BY ORDINAL_POSITION",
            (schema, table),
        )
        cols = cur.fetchall()
        col_defs: list[str] = []
        for name, dtype, maxlen, nullable, default in cols:
            t = dtype.upper()
            if maxlen and maxlen > 0 and t not in ("TEXT", "NTEXT", "IMAGE"):
                t = f"{t}({maxlen})"
            col_defs.append(
                f"    [{name}] {t} {'NULL' if nullable == 'YES' else 'NOT NULL'}"
                + (f" DEFAULT {default}" if default else "")
            )

        parts.append(f"IF OBJECT_ID('{fq}', 'U') IS NOT NULL DROP TABLE {fq};")
        parts.append(f"CREATE TABLE {fq} (\n" + ",\n".join(col_defs) + "\n);")

        # ── Primary key (if any) ──
        cur.execute(
            "SELECT kc.COLUMN_NAME "
            "FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc "
            "JOIN INFORMATION_SCHEMA.KEY_COLUMN_USAGE kc "
            "  ON tc.CONSTRAINT_NAME = kc.CONSTRAINT_NAME "
            "  AND tc.TABLE_SCHEMA = kc.TABLE_SCHEMA "
            "WHERE tc.TABLE_SCHEMA = ? AND tc.TABLE_NAME = ? "
            "  AND tc.CONSTRAINT_TYPE = 'PRIMARY KEY' "
            "ORDER BY kc.ORDINAL_POSITION",
            (schema, table),
        )
        pk_cols = [r[0] for r in cur.fetchall()]
        if pk_cols:
            pk_list = ", ".join(f"[{c}]" for c in pk_cols)
            parts.append(f"ALTER TABLE {fq} ADD CONSTRAINT PK_{table} PRIMARY KEY ({pk_list});")

        parts.append("")

    return "\n".join(parts)


# ── Data export ───────────────────────────────────────────────────────
def _data_sql(conn: pyodbc.Connection) -> str:
    """Return INSERT statements for every row in every user table."""
    cur = conn.cursor()

    cur.execute(
        "SELECT TABLE_SCHEMA, TABLE_NAME FROM INFORMATION_SCHEMA.TABLES "
        "WHERE TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_SCHEMA, TABLE_NAME"
    )
    tables = cur.fetchall()

    parts: list[str] = ["-- ════════════════════════════════════════════════════════════",
                        "-- DATA",
                        "-- ════════════════════════════════════════════════════════════",
                        ""]

    for schema, table in tables:
        fq = f"[{schema}].[{table}]"
        cur.execute(f"SELECT COUNT(*) FROM {fq}")
        count = cur.fetchone()[0]
        if count == 0:
            continue

        cur.execute(f"SELECT * FROM {fq}")
        columns = [desc[0] for desc in cur.description]
        col_list = ", ".join(f"[{c}]" for c in columns)

        parts.append(f"-- {fq}  ({count} rows)")
        parts.append(f"SET IDENTITY_INSERT {fq} ON;")

        batch_size = 100
        rows = cur.fetchmany(batch_size)
        while rows:
            for row in rows:
                vals = ", ".join(_sql_literal(v) for v in row)
                parts.append(f"INSERT INTO {fq} ({col_list}) VALUES ({vals});")
            rows = cur.fetchmany(batch_size)

        parts.append(f"SET IDENTITY_INSERT {fq} OFF;")
        parts.append("")

    return "\n".join(parts)


def _sql_literal(v):
    if v is None:
        return "NULL"
    if isinstance(v, bool):
        return "1" if v else "0"
    if isinstance(v, (int, float)):
        return str(v)
    if isinstance(v, bytes):
        return "0x" + v.hex()
    # strings, dates, etc.
    s = str(v).replace("'", "''")
    return f"N'{s}'"


# ── Main ──────────────────────────────────────────────────────────────
def main():
    ap = argparse.ArgumentParser(description="Export SQL Server database to .sql")
    ap.add_argument("-o", "--output", help="Output file path")
    ap.add_argument("--server", default=os.getenv("DB_SERVER", _DEFAULT_SERVER))
    ap.add_argument("--database", default=os.getenv("DB_NAME", _DEFAULT_DB))
    ap.add_argument("--schema-only", action="store_true", help="Schema without data")
    args = ap.parse_args()

    out_path = Path(args.output) if args.output else (
        Path(__file__).resolve().parent.parent / "database" / "exports" / "latest.sql"
    )
    out_path.parent.mkdir(parents=True, exist_ok=True)

    print(f"Connecting to {args.server}/{args.database} ...")
    conn = pyodbc.connect(_conn_str(args.server, args.database), timeout=10)

    print("Exporting schema ...")
    sql = _schema_sql(conn)

    if not args.schema_only:
        print("Exporting data ...")
        sql += "\n" + _data_sql(conn)

    conn.close()

    out_path.write_text(sql, encoding="utf-8")
    size_mb = out_path.stat().st_size / (1024 * 1024)
    print(f"Done → {out_path}  ({size_mb:.2f} MB)")


if __name__ == "__main__":
    main()
