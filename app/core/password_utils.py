"""Hastama Password Security Utilities.

Uses bcrypt for password hashing with automatic salting.
Maintains backward compatibility with existing SHA-512 and plain text passwords.
"""
import hashlib
import hmac
import re

import bcrypt


# ── Bcrypt Hashing ───────────────────────────────────────────

def hash_password(password: str) -> bytes:
    """Hash a password using bcrypt with automatic salting."""
    if password is None:
        return b""
    return bcrypt.hashpw(str(password).encode("utf-8"), bcrypt.gensalt(rounds=12))


BCRYPT_PREFIXES = (b"$2a$", b"$2b$", b"$2y$")


def _as_bytes(value) -> bytes:
    if isinstance(value, (bytearray, memoryview)):
        return bytes(value)
    if isinstance(value, bytes):
        return value
    if isinstance(value, str):
        return value.encode("utf-8", errors="ignore")
    return b""


def looks_like_bcrypt(value) -> bool:
    """Whether *value* is a bcrypt hash (bytes or text)."""
    return _as_bytes(value)[:4] in BCRYPT_PREFIXES


def is_legacy_plaintext(stored_password, stored_hash) -> bool:
    """Whether this row still stores a password we must migrate.

    ``True`` for a non-empty ``password`` column that is neither a bcrypt hash
    nor a SHA-512 digest (those two formats are handled by
    :func:`verify_password`).
    """
    if stored_hash is not None or stored_password is None:
        return False
    raw = str(stored_password).strip()
    if not raw or looks_like_bcrypt(raw):
        return False
    return not re.fullmatch(r"[0-9a-fA-F]{128}", raw)


def verify_password(stored_password, stored_hash, provided_password: str) -> bool:
    """Verify a password against the stored hash or a legacy stored value.

    Supported, in order of preference:

    1. bcrypt hash in ``password_hash`` (current format);
    2. bcrypt hash stored in the ``password`` column (pre-migration installs
       where ``password_hash`` does not exist yet);
    3. legacy SHA-512 digest in ``password_hash``;
    4. legacy plaintext in ``password`` (worst case, documented migration debt —
       see ``tools/migrate_passwords.py``).
    """
    if provided_password is None:
        return False

    provided_password = str(provided_password).strip()

    # 1./3. Preferred: hash column.
    if stored_hash is not None:
        try:
            stored_hash = _as_bytes(stored_hash)
            if stored_hash[:4] in BCRYPT_PREFIXES:
                return bcrypt.checkpw(provided_password.encode("utf-8"), stored_hash)
            # Legacy SHA-512 comparison — constant-time to prevent timing
            # attacks.  Historical installs stored the digest in two different
            # shapes (raw VARBINARY or a 128 character hex string); both must
            # keep authenticating, otherwise a schema difference would lock
            # every legacy user out.
            digest = hashlib.sha512(provided_password.encode("utf-8")).digest()
            candidates = (digest, digest.hex().encode("ascii"))
            for candidate in candidates:
                if len(stored_hash) == len(candidate) and hmac.compare_digest(stored_hash, candidate):
                    return True
            return False
        except Exception:
            return False

    # 2. bcrypt hash that ended up in the plaintext column (no hash column).
    if stored_password is not None and looks_like_bcrypt(stored_password):
        try:
            return bcrypt.checkpw(provided_password.encode("utf-8"), _as_bytes(stored_password))
        except Exception:
            return False

    # 3b. SHA-512 digest that ended up in the plaintext column (very old
    # installs, before the hash column existed) — hex or raw digest.
    if stored_password is not None and not looks_like_bcrypt(stored_password):
        raw = str(stored_password).strip()
        if re.fullmatch(r"[0-9a-fA-F]{128}", raw):
            digest = hashlib.sha512(provided_password.encode("utf-8"))
            if hmac.compare_digest(raw.lower(), digest.hexdigest()):
                return True
            return False

    # 4. Legacy plaintext comparison — constant-time.  This path is temporary:
    # it disappears once ``tools/migrate_passwords.py`` has been run (verified
    # by the "no plaintext password column" check in the deployment checklist).
    if stored_password is not None:
        return hmac.compare_digest(
            str(stored_password).strip(),
            provided_password,
        )

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
    """Fetch the authentication row for *username*.

    Returns ``(username, role, password, password_hash, is_active)`` where the
    trailing ``is_active`` element is ``None`` when the column does not exist in
    this installation.  The caller (``/login_user``) refuses to authenticate
    accounts whose status is not active.
    """
    normalized_username = str(username or "").strip()
    columns = get_user_table_columns(cursor)
    has_hash = "password_hash" in columns
    has_active = "is_active" in columns

    select = ["username", "role", "password"]
    select.append("password_hash" if has_hash else "NULL AS password_hash")
    select.append("is_active" if has_active else "NULL AS is_active")
    cursor.execute(
        f"SELECT {', '.join(select)} FROM user_table WHERE LTRIM(RTRIM(username)) = ?",
        (normalized_username,),
    )
    return cursor.fetchone()


def insert_user_with_optional_hash(cursor, user_id: int, username: str, password: str, password_hash: bytes, name: str, last_name: str,
                                   department: str, substitute: str, work_hours: str, role: str, hozoor_num: str,
                                   shanbeh: str, yekshanbeh: str, doshanbeh: str, seshanbeh: str, chrshanbeh: str,
                                   panjshanbeh: str):
    """Insert a new user.  Plaintext is NEVER stored — only the bcrypt hash."""
    if password_hash is None or password_hash == b"":
        password_hash = hash_password(password)
    columns = get_user_table_columns(cursor)
    if "password_hash" in columns:
        # NOTE: the previous revision passed 17 parameters to a statement with
        # 16 markers and a literal '' in the username slot, so `/add_user` could
        # never insert a row.  The parameter count now matches the markers and
        # the plaintext ``password`` column is written as '' — the bcrypt hash is
        # the only credential material stored.
        cursor.execute('''
            INSERT INTO user_table (
                id, username, password, password_hash, name, last_name, department, substitute, work_hours, role,
                hozoor_num, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh, is_active
            ) VALUES (?, ?, '', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ''', (user_id, username, password_hash, name, last_name, department, substitute, work_hours, role,
              hozoor_num, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh))
    else:
        # Legacy install without a hash column: store the bcrypt hash as text in
        # the ``password`` column (``verify_password`` understands that form).
        cursor.execute('''
            INSERT INTO user_table (
                id, username, password, name, last_name, department, substitute, work_hours, role,
                hozoor_num, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh, is_active
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
        ''', (user_id, username,
              password_hash.decode("utf-8") if isinstance(password_hash, bytes) else str(password_hash),
              name, last_name, department, substitute, work_hours, role,
              hozoor_num, shanbeh, yekshanbeh, doshanbeh, seshanbeh, chrshanbeh, panjshanbeh))
