"""Hastama Password Security Utilities.

Uses bcrypt for password hashing with automatic salting.
Maintains backward compatibility with existing SHA-512 and plain text passwords.
"""
import hashlib
import re

import bcrypt


# ── Bcrypt Hashing ───────────────────────────────────────────

def hash_password(password: str) -> bytes:
    """Hash a password using bcrypt with automatic salting."""
    if password is None:
        return b""
    return bcrypt.hashpw(str(password).encode("utf-8"), bcrypt.gensalt(rounds=12))


def verify_password(stored_password, stored_hash, provided_password: str) -> bool:
    """Verify a password against stored hash or plain text.

    Supports:
    - bcrypt hashes (new format)
    - SHA-512 hashes (legacy format)
    - Plain text (legacy format, worst case)
    """
    if provided_password is None:
        return False

    provided_password = str(provided_password).strip()

    # 1. Try bcrypt verification (new format)
    if stored_hash is not None:
        try:
            if isinstance(stored_hash, (bytearray, memoryview)):
                stored_hash = bytes(stored_hash)
            # Check if it's a bcrypt hash (starts with $2a$, $2b$, $2y$)
            if stored_hash[:4] in (b"$2a$", b"$2b$", b"$2y$"):
                return bcrypt.checkpw(provided_password.encode("utf-8"), stored_hash)
            # Legacy SHA-512 comparison
            return bytes(stored_hash) == hashlib.sha512(provided_password.encode("utf-8")).digest()
        except Exception:
            return False

    # 2. Fallback to plain text comparison (legacy, worst case)
    if stored_password is not None:
        return str(stored_password).strip() == provided_password

    return False


# ── Password Policy ──────────────────────────────────────────

def validate_password_policy(password: str) -> dict:
    """Validate password against security policy.

    Returns:
        {"valid": bool, "errors": [str], "score": int}
    """
    errors = []
    score = 0

    if not password:
        return {"valid": False, "errors": ["رمز عبور الزامی است."], "score": 0}

    if len(password) < 8:
        errors.append("حداقل ۸ کاراکتر")
    else:
        score += 1

    if not re.search(r"[A-Z]", password):
        errors.append("حداقل یک حرف بزرگ انگلیسی")
    else:
        score += 1

    if not re.search(r"[a-z]", password):
        errors.append("حداقل یک حرف کوچک انگلیسی")
    else:
        score += 1

    if not re.search(r"[0-9]", password):
        errors.append("حداقل یک عدد")
    else:
        score += 1

    if not re.search(r"[^A-Za-z0-9]", password):
        errors.append("حداقل یک کاراکتر خاص (!@#$%^&*)")
    else:
        score += 1

    if len(password) > 128:
        errors.append("حداکثر ۱۲۸ کاراکتر")
        score = max(0, score - 1)

    return {
        "valid": len(errors) == 0,
        "errors": errors,
        "score": score,
    }


def get_password_strength_label(score: int) -> str:
    """Return human-readable password strength label."""
    labels = {
        0: "خیلی ضعیف",
        1: "ضعیف",
        2: "ضعیف",
        3: "متوسط",
        4: "خوب",
        5: "قوی",
    }
    return labels.get(score, "خیلی ضعیف")


# ── Input Validation ─────────────────────────────────────────

MAX_USERNAME_LENGTH = 50
MAX_PASSWORD_LENGTH = 128
MAX_REQUEST_ID_LENGTH = 32
MAX_RECOVERY_CODE_LENGTH = 8


def validate_username_input(username: str) -> dict:
    """Validate username input for security."""
    if not username:
        return {"valid": False, "error": "نام کاربری الزامی است."}

    username = username.strip()

    if len(username) > MAX_USERNAME_LENGTH:
        return {"valid": False, "error": f"نام کاربری نباید بیش از {MAX_USERNAME_LENGTH} کاراکتر باشد."}

    if len(username) < 2:
        return {"valid": False, "error": "نام کاربری باید حداقل ۲ کاراکتر باشد."}

    # Allow Persian and English characters, numbers, underscore, space
    if not re.match(r'^[\w\s\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF-]+$', username):
        return {"valid": False, "error": "نام کاربری شامل کاراکترهای غیرمجاز است."}

    return {"valid": True, "error": None}


def validate_request_id(request_id: str) -> dict:
    """Validate request_id format."""
    if not request_id:
        return {"valid": False, "error": "شناسه درخواست الزامی است."}

    request_id = request_id.strip()

    if len(request_id) > MAX_REQUEST_ID_LENGTH:
        return {"valid": False, "error": "شناسه درخواست نامعتبر است."}

    # HST-YYYYMMDD-HEX8 format
    if not re.match(r'^HST-\d{8}-[0-9a-fA-F]{8}$', request_id):
        return {"valid": False, "error": "فرمت شناسه درخواست نامعتبر است."}

    return {"valid": True, "error": None}


def validate_recovery_code(code: str) -> dict:
    """Validate recovery code format."""
    if not code:
        return {"valid": False, "error": "کد بازیابی الزامی است."}

    code = code.strip()

    if len(code) != MAX_RECOVERY_CODE_LENGTH:
        return {"valid": False, "error": f"کد بازیابی باید {MAX_RECOVERY_CODE_LENGTH} کاراکتر باشد."}

    if not re.match(r'^[A-Z0-9]+$', code):
        return {"valid": False, "error": "کد بازیابی فقط شامل حروف بزرگ انگلیسی و اعداد باشد."}

    return {"valid": True, "error": None}


# ── Database Helpers ─────────────────────────────────────────

def get_user_table_columns(cursor) -> set:
    try:
        cursor.execute("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'user_table'")
        return {row[0].lower() for row in cursor.fetchall() if row and row[0]}
    except Exception:
        return set()


def fetch_user_for_login(cursor, username: str):
    normalized_username = str(username or "").strip()
    columns = get_user_table_columns(cursor)
    if "password_hash" in columns:
        cursor.execute("SELECT username, role, password, password_hash FROM user_table WHERE LTRIM(RTRIM(username)) = ?", (normalized_username,))
    else:
        cursor.execute("SELECT username, role, password FROM user_table WHERE LTRIM(RTRIM(username)) = ?", (normalized_username,))
    return cursor.fetchone()


def insert_user_with_optional_hash(cursor, user_id: int, username: str, password: str, password_hash: bytes, name: str, last_name: str,
                                   department: str, substitute: str, work_hours: str, role: str, hozoor_num: str,
                                   shanbeh: str, yekshanbeh: str, doshanbeh: str, seshanbeh: str, chrshanbeh: str,
                                   panjshanbeh: str):
    columns = get_user_table_columns(cursor)
    if "password_hash" in columns:
        cursor.execute('''
            INSERT INTO user_table (
                id, username, password, password_hash, name, last_name, department, substitute, work_hours, role,
                hozoor_num, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ''', (user_id, username, password, password_hash, name, last_name, department, substitute, work_hours, role,
              hozoor_num, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh))
    else:
        cursor.execute('''
            INSERT INTO user_table (
                id, username, password, name, last_name, department, substitute, work_hours, role,
                hozoor_num, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ''', (user_id, username, password, name, last_name, department, substitute, work_hours, role,
              hozoor_num, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh))
