"""
Restore a Hastama database dump into SQL Server.

Usage:
    python scripts/restore_db.py                         # → database/exports/latest.sql
    python scripts/restore_db.py -i database/exports/latest.sql
    python scripts/restore_db.py -i my_dump.sql --database userDB_test

Requires: pyodbc, ODBC Driver 17 for SQL Server.
"""
from __future__ import annotations

import os
import sys
import argparse
from pathlib import Path

import pyodbc

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


def main():
    ap = argparse.ArgumentParser(description="Restore a Hastama .sql dump")
    ap.add_argument("-i", "--input", help="SQL dump file path")
    ap.add_argument("--server", default=os.getenv("DB_SERVER", _DEFAULT_SERVER))
    ap.add_argument("--database", default=os.getenv("DB_NAME", _DEFAULT_DB))
    ap.add_argument("--drop-first", action="store_true",
                     help="Drop and recreate the database before restore")
    args = ap.parse_args()

    sql_path = Path(args.input) if args.input else (
        Path(__file__).resolve().parent.parent / "database" / "exports" / "latest.sql"
    )
    if not sql_path.exists():
        print(f"Error: file not found → {sql_path}")
        sys.exit(1)

    sql_text = sql_path.read_text(encoding="utf-8")
    print(f"Read {sql_path}  ({len(sql_text)} bytes)")

    # Connect to master to optionally recreate the database
    master_conn = pyodbc.connect(
        _conn_str(args.server, "master"), timeout=10
    )
    master_conn.autocommit = True

    if args.drop_first:
        print(f"Dropping & recreating [{args.database}] ...")
        master_conn.execute(
            f"IF DB_ID('{args.database}') IS NOT NULL "
            f"ALTER DATABASE [{args.database}] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;"
        )
        master_conn.execute(f"IF DB_ID('{args.database}') IS NOT NULL DROP DATABASE [{args.database}];")
        master_conn.execute(f"CREATE DATABASE [{args.database}];")
    else:
        # Ensure the database exists
        row = master_conn.execute(
            f"SELECT DB_ID('{args.database}')"
        ).fetchone()
        if row[0] is None:
            print(f"Creating [{args.database}] ...")
            master_conn.execute(f"CREATE DATABASE [{args.database}];")

    master_conn.close()

    # Connect to the target database and execute the dump
    conn = pyodbc.connect(_conn_str(args.server, args.database), timeout=10)
    conn.autocommit = False

    cur = conn.cursor()
    # Split on GO (SQL Server batch separator)
    batches = _split_batches(sql_text)

    total = len(batches)
    for i, batch in enumerate(batches, 1):
        batch = batch.strip()
        if not batch:
            continue
        try:
            cur.execute(batch)
            conn.commit()
            if i % 50 == 0 or i == total:
                print(f"  [{i}/{total}] batches executed")
        except Exception as e:
            conn.rollback()
            print(f"\n  ⚠ Error at batch {i}/{total}:")
            print(f"    {e}")
            print(f"    SQL (first 200 chars): {batch[:200]}...")
            resp = input("    Continue? [y/N] ").strip().lower()
            if resp == "y":
                conn.autocommit = True
                continue
            else:
                conn.close()
                sys.exit(1)

    conn.close()
    print(f"Done — restored {total} batches into {args.server}/{args.database}")


def _split_batches(sql: str) -> list[str]:
    """Split SQL on standalone 'GO' lines (SQL Server batch separator)."""
    batches = []
    current: list[str] = []
    for line in sql.splitlines():
        stripped = line.strip()
        if stripped.upper() == "GO":
            batches.append("\n".join(current))
            current = []
        else:
            current.append(line)
    if current:
        batches.append("\n".join(current))
    return batches


if __name__ == "__main__":
    main()
