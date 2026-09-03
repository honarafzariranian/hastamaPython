import hashlib


def hash_password(password: str) -> bytes:
    if password is None:
        return b""
    return hashlib.sha512(str(password).encode("utf-8")).digest()


def get_user_table_columns(cursor) -> set:
    try:
        cursor.execute("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'user_table'")
        return {row[0].lower() for row in cursor.fetchall() if row and row[0]}
    except Exception:
        return set()


def verify_password(stored_password, stored_hash, provided_password: str) -> bool:
    if provided_password is None:
        return False

    provided_password = str(provided_password).strip()

    if stored_hash is not None:
        try:
            if isinstance(stored_hash, (bytearray, memoryview)):
                stored_hash = bytes(stored_hash)
            return bytes(stored_hash) == hash_password(provided_password)
        except Exception:
            return False

    if stored_password is not None:
        return str(stored_password).strip() == provided_password

    return False


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
