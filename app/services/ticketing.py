"""Core ticketing domain service.

The legacy application stores one conversation as several rows in
``ticket_table``.  This service uses the normalized tables from
``database/ticketing.sql`` and keeps all authorization and lifecycle rules in
one place.  Routes should only validate transport data and delegate here.
"""
from __future__ import annotations

import json
import os
import threading
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Optional

import pyodbc

from app.api.routes.notifications import publish_system_notification

TICKET_STATUSES = (
    "new",
    "open",
    "in_progress",
    "waiting_for_user",
    "waiting_for_support",
    "resolved",
    "closed",
)
TICKET_PRIORITIES = ("low", "normal", "high", "urgent")
MESSAGE_VISIBILITIES = ("public", "internal")

STATUS_LABELS = {
    "new": "جدید",
    "open": "باز",
    "in_progress": "در حال بررسی",
    "waiting_for_user": "در انتظار کاربر",
    "waiting_for_support": "در انتظار پشتیبانی",
    "resolved": "حل‌شده",
    "closed": "بسته‌شده",
}
PRIORITY_LABELS = {
    "low": "کم",
    "normal": "عادی",
    "high": "زیاد",
    "urgent": "فوری",
}

# Explicit transitions prevent a closed conversation from accidentally behaving
# like an active ticket. Reopening is intentional and auditable.
ALLOWED_TRANSITIONS = {
    "new": {"open", "in_progress", "waiting_for_support", "resolved", "closed"},
    "open": {"in_progress", "waiting_for_user", "waiting_for_support", "resolved", "closed"},
    "in_progress": {"open", "waiting_for_user", "waiting_for_support", "resolved", "closed"},
    "waiting_for_user": {"open", "in_progress", "resolved", "closed"},
    "waiting_for_support": {"open", "in_progress", "resolved", "closed"},
    "resolved": {"open", "closed"},
    "closed": {"open"},
}

_CONNECTION_STRING = os.getenv(
    "DATABASE_URL",
    "DRIVER={ODBC Driver 17 for SQL Server};SERVER=localhost\\SQLEXPRESS;"
    "DATABASE=userDB;Trusted_Connection=yes;",
)
_SCHEMA_READY = False
_SCHEMA_LOCK = threading.Lock()
_SCHEMA_PATH = Path(__file__).resolve().parents[2] / "database" / "ticketing.sql"
_PRIVATE_ROOT = Path(os.getenv("TICKETING_PRIVATE_DIR", "app/private_uploads/tickets"))


def get_connection():
    return pyodbc.connect(_CONNECTION_STRING)


def ensure_schema(conn) -> None:
    """Install the additive ticket schema once per process.

    The SQL file is also deployable independently, while lazy execution keeps
    existing local installations usable without a separate migration runner.
    """
    global _SCHEMA_READY
    if _SCHEMA_READY:
        return
    with _SCHEMA_LOCK:
        if _SCHEMA_READY:
            return
        cursor = conn.cursor()
        cursor.execute(_SCHEMA_PATH.read_text(encoding="utf-8"))
        conn.commit()
        _SCHEMA_READY = True


def actor_from_session(request) -> tuple[str, bool]:
    actor = str(request.session.get("username") or "").strip()
    if not actor:
        raise PermissionError("برای ادامه وارد سامانه شوید.")
    return actor, request.session.get("is_admin") is True


def clean_text(value, field: str, minimum: int = 1, maximum: int = 4000) -> str:
    value = str(value or "").replace("\x00", "").strip()
    if len(value) < minimum:
        raise ValueError(f"{field} الزامی است.")
    if len(value) > maximum:
        raise ValueError(f"طول {field} بیشتر از حد مجاز است.")
    return value


def _iso(value):
    if not value:
        return None
    if isinstance(value, str):
        return value
    if value.tzinfo is None:
        value = value.replace(tzinfo=timezone.utc)
    return value.isoformat().replace("+00:00", "Z")


def _dict_rows(cursor) -> list[dict]:
    columns = [column[0] for column in cursor.description]
    return [dict(zip(columns, row)) for row in cursor.fetchall()]


def _ticket_number(ticket_id: int) -> str:
    return f"HT-{int(ticket_id):08d}"


def _serialize_ticket(row: dict) -> dict:
    ticket_id = int(row["id"])
    status = str(row.get("status") or "new").strip()
    priority = str(row.get("priority") or "normal").strip()
    result = dict(row)
    result["id"] = ticket_id
    result["ticket_number"] = _ticket_number(ticket_id)
    result["status"] = status
    result["status_label"] = STATUS_LABELS.get(status, status)
    result["priority"] = priority
    result["priority_label"] = PRIORITY_LABELS.get(priority, priority)
    for key in (
        "created_at",
        "updated_at",
        "last_message_at",
        "first_response_at",
        "resolved_at",
        "closed_at",
        "sla_due_at",
    ):
        if key in result:
            result[key] = _iso(result[key])
    due = result.get("sla_due_at")
    if due and isinstance(due, str):
        try:
            due_dt = datetime.fromisoformat(due.replace("Z", "+00:00"))
            result["sla_state"] = "overdue" if due_dt < datetime.now(timezone.utc) else "healthy"
        except ValueError:
            result["sla_state"] = "unknown"
    else:
        result["sla_state"] = "none"
    return result


def _event(cursor, ticket_id: int, actor: str, event_type: str, metadata: Optional[dict] = None):
    cursor.execute(
        "INSERT INTO ticket_events (ticket_id, actor_username, event_type, metadata) VALUES (?, ?, ?, ?)",
        (ticket_id, actor, event_type, json.dumps(metadata or {}, ensure_ascii=False)),
    )


def _verify_user(cursor, username: str) -> str:
    username = clean_text(username, "نام کاربری", 1, 255)
    cursor.execute(
        "SELECT TOP 1 LTRIM(RTRIM(username)) FROM user_table WHERE LTRIM(RTRIM(username)) = ?",
        (username,),
    )
    row = cursor.fetchone()
    if not row:
        raise LookupError("کاربر موردنظر پیدا نشد.")
    return str(row[0]).strip()


class TicketService:
    def __init__(self, conn=None):
        self.conn = conn or get_connection()
        self._owns_connection = conn is None
        ensure_schema(self.conn)
        self.cursor = self.conn.cursor()

    def close(self):
        try:
            self.cursor.close()
        finally:
            if self._owns_connection:
                self.conn.close()

    def __enter__(self):
        return self

    def __exit__(self, exc_type, exc_value, traceback):
        if exc_type:
            try:
                self.conn.rollback()
            except Exception:
                pass
        self.close()

    def _ticket_row(self, ticket_id: int, actor: str, is_admin: bool) -> Optional[dict]:
        self.cursor.execute(
            """SELECT t.id, t.legacy_parent_id, t.requester_username, t.recipient_username,
                      t.subject, t.status, t.priority, t.category_id, c.name category_name,
                      t.assigned_to, t.created_at, t.updated_at, t.last_message_at,
                      t.first_response_at, t.resolved_at, t.closed_at, t.sla_due_at
               FROM tickets t LEFT JOIN ticket_categories c ON c.id=t.category_id
               WHERE t.id=?""",
            (ticket_id,),
        )
        row = self.cursor.fetchone()
        if not row:
            return None
        data = dict(zip([column[0] for column in self.cursor.description], row))
        if not is_admin and actor not in {
            str(data.get("requester_username") or "").strip(),
            str(data.get("recipient_username") or "").strip(),
            str(data.get("assigned_to") or "").strip(),
        }:
            return None
        return data

    def list_tickets(
        self,
        actor: str,
        is_admin: bool,
        page: int = 1,
        page_size: int = 20,
        search: str = "",
        status: str = "",
        priority: str = "",
        assignee: str = "",
        sort: str = "newest",
    ) -> dict:
        page = max(1, int(page))
        page_size = min(100, max(5, int(page_size)))
        visibility_clauses = ["1=1"]
        visibility_params: list = []
        if not is_admin:
            visibility_clauses.append("(t.requester_username=? OR t.recipient_username=? OR t.assigned_to=?)")
            visibility_params.extend([actor, actor, actor])
        clauses = list(visibility_clauses)
        params: list = list(visibility_params)
        if search.strip():
            query = f"%{search.strip()}%"
            clauses.append(
                "(t.subject LIKE ? OR t.requester_username LIKE ? OR t.recipient_username LIKE ? "
                "OR t.id = TRY_CONVERT(BIGINT, ?) OR EXISTS "
                "(SELECT 1 FROM ticket_messages sm WHERE sm.ticket_id=t.id AND sm.body LIKE ?))"
            )
            params.extend([query, query, query, search.strip().removeprefix("HT-"), query])
        if status in TICKET_STATUSES:
            clauses.append("t.status=?")
            params.append(status)
        if priority in TICKET_PRIORITIES:
            clauses.append("t.priority=?")
            params.append(priority)
        if assignee.strip() and is_admin:
            clauses.append("t.assigned_to=?")
            params.append(assignee.strip())
        where = " AND ".join(clauses)
        order = {
            "newest": "t.updated_at DESC, t.id DESC",
            "oldest": "t.updated_at ASC, t.id ASC",
            "priority": "CASE t.priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'normal' THEN 3 ELSE 4 END, t.updated_at DESC",
        }.get(sort, "t.updated_at DESC, t.id DESC")
        self.cursor.execute(f"SELECT COUNT(*) FROM tickets t WHERE {where}", tuple(params))
        total = int(self.cursor.fetchone()[0])
        self.cursor.execute(
            f"""SELECT t.id,t.requester_username,t.recipient_username,t.subject,t.status,t.priority,
                       t.category_id,c.name category_name,t.assigned_to,t.created_at,t.updated_at,
                       t.last_message_at,t.sla_due_at,
                       (SELECT TOP 1 tm.body FROM ticket_messages tm WHERE tm.ticket_id=t.id AND tm.visibility='public' ORDER BY tm.created_at DESC,tm.id DESC) last_message_preview,
                       (SELECT TOP 1 tm.author_username FROM ticket_messages tm WHERE tm.ticket_id=t.id AND tm.visibility='public' ORDER BY tm.created_at DESC,tm.id DESC) last_responder
                FROM tickets t LEFT JOIN ticket_categories c ON c.id=t.category_id
                WHERE {where} ORDER BY {order} OFFSET ? ROWS FETCH NEXT ? ROWS ONLY""",
            tuple(params + [(page - 1) * page_size, page_size]),
        )
        items = [_serialize_ticket(row) for row in _dict_rows(self.cursor)]
        self.cursor.execute(
            f"SELECT status, COUNT(*) count FROM tickets t WHERE {' AND '.join(visibility_clauses)} GROUP BY status",
            tuple(visibility_params),
        )
        counts = {row["status"]: int(row["count"]) for row in _dict_rows(self.cursor)}
        self.conn.commit()
        return {
            "items": items,
            "total": total,
            "page": page,
            "page_size": page_size,
            "pages": max(1, (total + page_size - 1) // page_size),
            "counts": counts,
            "status_labels": STATUS_LABELS,
            "priority_labels": PRIORITY_LABELS,
        }

    def categories(self) -> list[dict]:
        self.cursor.execute(
            "SELECT id,name,slug,parent_id FROM ticket_categories WHERE is_active=1 ORDER BY name"
        )
        return _dict_rows(self.cursor)

    def get_ticket(self, ticket_id: int, actor: str, is_admin: bool) -> Optional[dict]:
        ticket = self._ticket_row(ticket_id, actor, is_admin)
        if ticket is None:
            return None
        self.cursor.execute(
            """SELECT m.id,m.author_username,m.body,m.visibility,m.created_at,m.edited_at,
                      (SELECT COUNT(*) FROM ticket_attachments a WHERE a.message_id=m.id) attachment_count
               FROM ticket_messages m WHERE m.ticket_id=?
               AND (?=1 OR m.visibility='public') ORDER BY m.created_at ASC,m.id ASC""",
            (ticket_id, 1 if is_admin else 0),
        )
        messages = []
        for message in _dict_rows(self.cursor):
            for key in ("created_at", "edited_at"):
                message[key] = _iso(message.get(key))
            messages.append(message)
        self.cursor.execute(
            """SELECT a.id,a.message_id,a.uploaded_by,a.original_name,a.content_type,a.size_bytes,a.created_at
               FROM ticket_attachments a WHERE a.ticket_id=?
               AND (?=1 OR a.message_id IS NULL OR EXISTS (
                   SELECT 1 FROM ticket_messages am
                   WHERE am.id=a.message_id AND am.visibility='public'
               ))
               ORDER BY a.created_at ASC,a.id ASC""",
            (ticket_id, 1 if is_admin else 0),
        )
        attachments = []
        for attachment in _dict_rows(self.cursor):
            attachment["created_at"] = _iso(attachment.get("created_at"))
            attachment["download_url"] = f"/api/tickets/{ticket_id}/attachments/{attachment['id']}"
            attachments.append(attachment)
        self.cursor.execute(
            """SELECT id,actor_username,event_type,metadata,created_at
               FROM ticket_events WHERE ticket_id=? ORDER BY created_at ASC,id ASC""",
            (ticket_id,),
        )
        events = []
        for event in _dict_rows(self.cursor):
            event["created_at"] = _iso(event.get("created_at"))
            try:
                event["metadata"] = json.loads(event["metadata"] or "{}")
            except (TypeError, ValueError):
                event["metadata"] = {}
            events.append(event)
        ticket = _serialize_ticket(ticket)
        ticket["messages"] = messages
        ticket["attachments"] = attachments
        ticket["events"] = events
        return ticket

    def create_ticket(
        self,
        actor: str,
        is_admin: bool,
        recipient_username: str,
        subject: str,
        body: str,
        priority: str = "normal",
        category_id: Optional[int] = None,
    ) -> dict:
        recipient = _verify_user(self.cursor, recipient_username)
        if recipient.casefold() == actor.casefold():
            raise ValueError("ارسال تیکت برای خودتان مجاز نیست.")
        subject = clean_text(subject, "موضوع", 2, 180)
        body = clean_text(body, "توضیحات", 2, 4000)
        if priority not in TICKET_PRIORITIES:
            raise ValueError("اولویت تیکت معتبر نیست.")
        if category_id is not None:
            self.cursor.execute("SELECT 1 FROM ticket_categories WHERE id=? AND is_active=1", (category_id,))
            if not self.cursor.fetchone():
                raise LookupError("دسته‌بندی تیکت پیدا نشد.")
        self.cursor.execute(
            """INSERT INTO tickets
                (requester_username,recipient_username,subject,status,priority,category_id,sla_due_at)
                OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,DATEADD(HOUR,48,SYSUTCDATETIME()))""",
            (actor, recipient, subject, "new", priority, category_id),
        )
        ticket_id = int(self.cursor.fetchone()[0])
        self.cursor.execute(
            """INSERT INTO ticket_messages (ticket_id,author_username,body,visibility)
               OUTPUT INSERTED.id VALUES (?,?,?,'public')""",
            (ticket_id, actor, body),
        )
        message_id = int(self.cursor.fetchone()[0])
        _event(self.cursor, ticket_id, actor, "created", {"message_id": message_id})
        publish_system_notification(
            self.conn,
            f"تیکت جدید: {subject}",
            f"کاربر «{actor}» برای شما تیکت جدیدی با موضوع «{subject}» ثبت کرد.",
            [recipient],
            notification_type="information",
            action_url="/user_panel",
        )
        self.conn.commit()
        return self.get_ticket(ticket_id, actor, is_admin)

    def add_message(self, ticket_id: int, actor: str, is_admin: bool, body: str, visibility: str = "public") -> dict:
        body = clean_text(body, "متن پیام", 1, 4000)
        if visibility not in MESSAGE_VISIBILITIES:
            raise ValueError("نوع پیام معتبر نیست.")
        ticket = self._ticket_row(ticket_id, actor, is_admin)
        if ticket is None:
            raise LookupError("تیکت پیدا نشد.")
        if visibility == "internal" and not is_admin:
            raise PermissionError("ثبت یادداشت داخلی فقط برای پشتیبانی مجاز است.")
        if ticket["status"] == "closed":
            raise ValueError("تیکت بسته‌شده قابل پاسخ نیست.")
        self.cursor.execute(
            """INSERT INTO ticket_messages (ticket_id,author_username,body,visibility)
               OUTPUT INSERTED.id VALUES (?,?,?,?)""",
            (ticket_id, actor, body, visibility),
        )
        message_id = int(self.cursor.fetchone()[0])
        if visibility == "internal":
            event_type = "internal_note_added"
        else:
            event_type = "reply_added"
            # Only a support/admin reply puts the conversation in the user's
            # queue; a reply from either participant without admin privileges
            # must wait for support, even when the ticket was admin-created.
            next_status = "waiting_for_user" if is_admin else "waiting_for_support"
            self.cursor.execute(
                """UPDATE tickets SET status=?,updated_at=SYSUTCDATETIME(),last_message_at=SYSUTCDATETIME(),
                   first_response_at=CASE WHEN first_response_at IS NULL AND ?=1 THEN SYSUTCDATETIME() ELSE first_response_at END
                   WHERE id=?""",
                (next_status, 1 if is_admin or actor != ticket["requester_username"] else 0, ticket_id),
            )
        _event(self.cursor, ticket_id, actor, event_type, {"message_id": message_id, "visibility": visibility})
        if visibility == "public":
            requester = str(ticket.get("requester_username") or "").strip()
            recipient = str(ticket.get("recipient_username") or "").strip()
            target = requester if actor.casefold() != requester.casefold() else recipient
            if target:
                publish_system_notification(
                    self.conn,
                    f"پاسخ جدید به تیکت: {ticket['subject']}",
                    f"کاربر «{actor}» به تیکت «{ticket['subject']}» پاسخ جدید داد.",
                    [target],
                    notification_type="information",
                    action_url="/user_panel",
                )
        self.conn.commit()
        return self.get_ticket(ticket_id, actor, is_admin)

    def update_ticket(
        self,
        ticket_id: int,
        actor: str,
        is_admin: bool,
        status: Optional[str] = None,
        priority: Optional[str] = None,
        category_id: Optional[int] = None,
        assigned_to: Optional[str] = None,
    ) -> dict:
        ticket = self._ticket_row(ticket_id, actor, is_admin)
        if ticket is None:
            raise LookupError("تیکت پیدا نشد.")
        if not is_admin and any(value is not None for value in (status, priority, category_id, assigned_to)):
            if status not in {"resolved", "open"} or priority is not None or category_id is not None or assigned_to is not None:
                raise PermissionError("تغییر این مشخصات فقط برای پشتیبانی مجاز است.")
        if status is not None:
            if status not in TICKET_STATUSES:
                raise ValueError("وضعیت تیکت معتبر نیست.")
            if status != ticket["status"] and status not in ALLOWED_TRANSITIONS.get(ticket["status"], set()):
                raise ValueError("تغییر وضعیت انتخاب‌شده مجاز نیست.")
        if priority is not None and priority not in TICKET_PRIORITIES:
            raise ValueError("اولویت تیکت معتبر نیست.")
        if category_id is not None:
            self.cursor.execute("SELECT 1 FROM ticket_categories WHERE id=? AND is_active=1", (category_id,))
            if not self.cursor.fetchone():
                raise LookupError("دسته‌بندی تیکت پیدا نشد.")
        if assigned_to is not None:
            assigned_to = _verify_user(self.cursor, assigned_to) if assigned_to else None
        assignments = []
        params = []
        if status is not None:
            assignments.append("status=?")
            params.append(status)
            if status == "resolved":
                assignments.append("resolved_at=SYSUTCDATETIME()")
            elif status == "closed":
                assignments.append("closed_at=SYSUTCDATETIME()")
            elif status == "open":
                assignments.extend(["resolved_at=NULL", "closed_at=NULL"])
        if priority is not None:
            assignments.append("priority=?")
            params.append(priority)
        if category_id is not None:
            assignments.append("category_id=?")
            params.append(category_id)
        if assigned_to is not None:
            assignments.append("assigned_to=?")
            params.append(assigned_to)
        if not assignments:
            return self.get_ticket(ticket_id, actor, is_admin)
        assignments.append("updated_at=SYSUTCDATETIME()")
        self.cursor.execute(f"UPDATE tickets SET {', '.join(assignments)} WHERE id=?", tuple(params + [ticket_id]))
        if status is not None and status != ticket["status"]:
            _event(self.cursor, ticket_id, actor, "status_changed", {"from": ticket["status"], "to": status})
            target = str(ticket.get("requester_username") or "").strip() if is_admin else str(ticket.get("recipient_username") or "").strip()
            if target and target.casefold() != actor.casefold():
                publish_system_notification(
                    self.conn,
                    f"وضعیت تیکت تغییر کرد: {ticket['subject']}",
                    f"وضعیت تیکت «{ticket['subject']}» به «{STATUS_LABELS.get(status, status)}» تغییر کرد.",
                    [target],
                    notification_type="success" if status in {"resolved", "closed"} else "information",
                    action_url="/user_panel",
                )
        if priority is not None and priority != ticket["priority"]:
            _event(self.cursor, ticket_id, actor, "priority_changed", {"from": ticket["priority"], "to": priority})
            requester = str(ticket.get("requester_username") or "").strip()
            recipient = str(ticket.get("recipient_username") or "").strip()
            target = requester if actor.casefold() != requester.casefold() else recipient
            if target and target.casefold() != actor.casefold():
                publish_system_notification(
                    self.conn,
                    f"اولویت تیکت تغییر کرد: {ticket['subject']}",
                    f"اولویت تیکت «{ticket['subject']}» به «{PRIORITY_LABELS.get(priority, priority)}» تغییر کرد.",
                    [target],
                    notification_type="warning" if priority in {"high", "urgent"} else "information",
                    action_url="/user_panel",
                )
        if assigned_to is not None and assigned_to != ticket.get("assigned_to"):
            _event(self.cursor, ticket_id, actor, "assigned", {"assignee": assigned_to})
            if assigned_to and assigned_to.casefold() != actor.casefold():
                publish_system_notification(
                    self.conn,
                    f"تیکت به شما واگذار شد: {ticket['subject']}",
                    f"تیکت «{ticket['subject']}» برای بررسی به شما واگذار شد.",
                    [assigned_to],
                    notification_type="information",
                    action_url="/admin",
                )
        self.conn.commit()
        return self.get_ticket(ticket_id, actor, is_admin)

    def add_attachment(self, ticket_id: int, actor: str, is_admin: bool, metadata: dict) -> dict:
        ticket = self._ticket_row(ticket_id, actor, is_admin)
        if ticket is None:
            raise LookupError("تیکت پیدا نشد.")
        if ticket["status"] == "closed":
            raise ValueError("تیکت بسته‌شده قابل تغییر نیست.")
        message_visibility = "public"
        if metadata.get("message_id") is not None:
            self.cursor.execute(
                "SELECT visibility FROM ticket_messages WHERE id=? AND ticket_id=?",
                (metadata["message_id"], ticket_id),
            )
            message_row = self.cursor.fetchone()
            if not message_row:
                raise LookupError("پیام مقصد پیوست پیدا نشد.")
            message_visibility = str(message_row[0] or "public").strip()
        self.cursor.execute(
            """INSERT INTO ticket_attachments
                (ticket_id,message_id,uploaded_by,original_name,storage_name,content_type,size_bytes)
                OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,?)""",
            (ticket_id, metadata.get("message_id"), actor, metadata["original_name"], metadata["storage_name"], metadata["content_type"], metadata["size_bytes"]),
        )
        attachment_id = int(self.cursor.fetchone()[0])
        _event(self.cursor, ticket_id, actor, "attachment_added", {"attachment_id": attachment_id})
        if message_visibility == "public":
            requester = str(ticket.get("requester_username") or "").strip()
            recipient = str(ticket.get("recipient_username") or "").strip()
            target = requester if actor.casefold() != requester.casefold() else recipient
            if target:
                publish_system_notification(
                    self.conn,
                    f"پیوست جدید به تیکت: {ticket['subject']}",
                    f"یک فایل جدید از طرف «{actor}» در تیکت «{ticket['subject']}» ارسال شد.",
                    [target],
                    notification_type="information",
                    action_url="/user_panel",
                )
        self.conn.commit()
        return {"id": attachment_id, "download_url": f"/api/tickets/{ticket_id}/attachments/{attachment_id}", "original_name": metadata["original_name"]}

    def attachment(self, ticket_id: int, attachment_id: int, actor: str, is_admin: bool) -> Optional[dict]:
        if self._ticket_row(ticket_id, actor, is_admin) is None:
            return None
        self.cursor.execute(
            """SELECT a.id,a.original_name,a.storage_name,a.content_type,a.size_bytes
               FROM ticket_attachments a WHERE a.id=? AND a.ticket_id=?
               AND (?=1 OR a.message_id IS NULL OR EXISTS (
                   SELECT 1 FROM ticket_messages am
                   WHERE am.id=a.message_id AND am.visibility='public'
               ))""",
            (attachment_id, ticket_id, 1 if is_admin else 0),
        )
        row = self.cursor.fetchone()
        if not row:
            return None
        return dict(zip([column[0] for column in self.cursor.description], row))


def store_private_attachment(original_name: str, content_type: str, payload: bytes) -> dict:
    """Validate and store an attachment outside ``/static``."""
    allowed = {
        ".pdf": "application/pdf",
        ".png": "image/png",
        ".jpg": "image/jpeg",
        ".jpeg": "image/jpeg",
        ".webp": "image/webp",
        ".txt": "text/plain",
        ".doc": "application/msword",
        ".docx": "application/vnd.openxmlformats-officedocument.wordprocessingml.document",
        ".xls": "application/vnd.ms-excel",
        ".xlsx": "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
    }
    safe_name = Path(str(original_name or "").replace("\\", "/")).name
    suffix = Path(safe_name).suffix.lower()
    if suffix not in allowed:
        raise ValueError("نوع فایل مجاز نیست.")
    if len(payload) == 0 or len(payload) > 10 * 1024 * 1024:
        raise ValueError("حجم فایل باید بین ۱ بایت و ۱۰ مگابایت باشد.")
    if content_type and content_type.split(";")[0].lower() not in {allowed[suffix], "application/octet-stream"}:
        raise ValueError("نوع محتوای فایل معتبر نیست.")
    storage_name = f"{uuid.uuid4().hex}{suffix}"
    root = _PRIVATE_ROOT.resolve()
    root.mkdir(parents=True, exist_ok=True)
    path = (root / storage_name).resolve()
    if root not in path.parents:
        raise ValueError("نام فایل ناامن است.")
    path.write_bytes(payload)
    return {
        "original_name": safe_name[:255] or f"attachment{suffix}",
        "storage_name": storage_name,
        "content_type": allowed[suffix],
        "size_bytes": len(payload),
        "path": path,
    }
