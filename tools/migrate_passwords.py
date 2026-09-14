"""Password Migration Script — Hastama

Migrates legacy plaintext passwords in the `password` column to bcrypt hashes.
Safe to run multiple times (idempotent).

Usage:
    python -m tools.migrate_passwords [--dry-run]

Requires:
    - pyodbc
    - bcrypt
    - SQL Server connection (Trusted_Connection=yes)
"""
import argparse
import os
import sys

import bcrypt
import pyodbc


def get_connection():
    return pyodbc.connect(
        'DRIVER={ODBC Driver 17 for SQL Server};'
        r'SERVER=localhost\SQLEXPRESS;'
        'DATABASE=userDB;'
        'Trusted_Connection=yes;'
    )


def is_bcrypt_hash(value: str) -> bool:
    """Check if a string looks like a bcrypt hash."""
    return value.startswith(("$2a$", "$2b$", "$2y$"))


def migrate_passwords(dry_run: bool = False):
    conn = get_connection()
    cursor = conn.cursor()

    # Find users with non-empty password column
    cursor.execute(
        "SELECT username, password FROM user_table "
        "WHERE password IS NOT NULL AND LTRIM(RTRIM(password)) != ''"
    )
    rows = cursor.fetchall()

    total = len(rows)
    plaintext_count = 0
    bcrypt_count = 0
    skipped = 0

    print(f"Found {total} users with non-empty password column.")

    for row in rows:
        username = str(row[0] or "").strip()
        password_value = str(row[1] or "").strip()

        if not password_value:
            skipped += 1
            continue

        if is_bcrypt_hash(password_value):
            # Already a bcrypt hash — skip
            bcrypt_count += 1
            continue

        # This is a plaintext password — migrate it
        plaintext_count += 1
        new_hash = bcrypt.hashpw(password_value.encode("utf-8"), bcrypt.gensalt(rounds=12))

        if dry_run:
            print(f"  [DRY RUN] Would migrate: {username}")
        else:
            # Store hash in password_hash column if it exists, else in password column
            try:
                cursor.execute(
                    "UPDATE user_table SET password = '', password_hash = ? "
                    "WHERE LTRIM(RTRIM(username)) = ?",
                    (new_hash, username),
                )
            except Exception:
                # password_hash column doesn't exist — store hash in password column
                cursor.execute(
                    "UPDATE user_table SET password = ? "
                    "WHERE LTRIM(RTRIM(username)) = ?",
                    (new_hash.decode("utf-8"), username),
                )
            print(f"  Migrated: {username}")

    if not dry_run:
        conn.commit()
        print(f"\nMigration complete: {plaintext_count} passwords migrated, "
              f"{bcrypt_count} already hashed, {skipped} skipped.")
    else:
        print(f"\nDry run complete: {plaintext_count} passwords would be migrated, "
              f"{bcrypt_count} already hashed, {skipped} skipped.")

    cursor.close()
    conn.close()


if __name__ == "__main__":
    parser = argparse.ArgumentParser(description="Migrate plaintext passwords to bcrypt")
    parser.add_argument("--dry-run", action="store_true", help="Show what would be done without making changes")
    args = parser.parse_args()
    migrate_passwords(dry_run=args.dry_run)
