"""Session-authenticated notification APIs for the admin and user panels.

The project uses SQL Server directly rather than an ORM. This module keeps that
convention while centralising authorization, validation, pagination and fan-out.
All timestamps are UTC DATETIME2 values; the browser formats them in fa-IR.
"""
from __future__ import annotations

import asyncio
import json
import os
import threading
from datetime import datetime, timezone
from pathlib import Path
from typing import Literal, Optional

import pyodbc
from fastapi import APIRouter, HTTPException, Query, Request
from app.core.database import connect as db_connect
from app.services.web_push import public_key as web_push_public_key, send_to_subscriptions
from fastapi.responses import StreamingResponse
from pydantic import BaseModel, Field, field_validator

router = APIRouter(prefix="/api", tags=["notifications"])

_schema_ready = False
_schema_lock = threading.Lock()


def get_connection():
    return db_connect()


def _ensure_schema(conn) -> None:
    global _schema_ready
    if _schema_ready:
        return
    with _schema_lock:
        if _schema_ready:
            return
        migration = Path(__file__).resolve().parents[3] / "database" / "notifications.sql"
        cursor = conn.cursor()
        cursor.execute(migration.read_text(encoding="utf-8"))
        conn.commit()
        _schema_ready = True


def _actor(request: Request, admin: bool = False) -> str:
    username = str(request.session.get("username") or "").strip()
    if not username:
        raise HTTPException(status_code=401, detail="برای ادامه وارد سامانه شوید.")
    if admin and request.session.get("is_admin") is not True:
        raise HTTPException(status_code=403, detail="دسترسی مدیریت لازم است.")
    return username


def _dict_rows(cursor) -> list[dict]:
    columns = [item[0] for item in cursor.description]
    return [dict(zip(columns, row)) for row in cursor.fetchall()]


def _iso(value):
    if not value:
        return None
    if isinstance(value, str):
        return value
    # SQL values are UTC but pyodbc returns naive datetime objects.
    if value.tzinfo is None:
        value = value.replace(tzinfo=timezone.utc)
    return value.isoformat().replace("+00:00", "Z")


def _serialize(row: dict) -> dict:
    for key in ("created_at", "updated_at", "published_at", "scheduled_at", "archived_at", "delivered_at", "read_at"):
        if key in row:
            row[key] = _iso(row[key])
    return row


def _parse_schedule(value: Optional[str]) -> Optional[datetime]:
    if not value:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError as exc:
        raise HTTPException(status_code=422, detail="زمان‌بندی نامعتبر است.") from exc
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc).replace(tzinfo=None, microsecond=0)


class NotificationInput(BaseModel):
    title: str = Field(min_length=2, max_length=180)
    content: str = Field(min_length=2, max_length=4000)
    type: Literal["general", "announcement", "system", "warning", "information", "success", "reminder"] = "general"
    priority: Literal["normal", "important", "high", "critical"] = "normal"
    status: Literal["draft", "scheduled", "published"] = "draft"
    target_type: Literal["all", "selected", "role", "department"] = "all"
    targets: list[str] = Field(default_factory=list, max_length=5000)
    action_label: Optional[str] = Field(default=None, max_length=80)
    action_url: Optional[str] = Field(default=None, max_length=500)
    scheduled_at: Optional[str] = None

    @field_validator("title", "content")
    @classmethod
    def strip_required(cls, value: str) -> str:
        value = value.strip()
        if len(value) < 2:
            raise ValueError("این فیلد الزامی است.")
        return value

    @field_validator("action_url")
    @classmethod
    def internal_link_only(cls, value):
        if value:
            value = value.strip()
            if not value.startswith("/") or value.startswith("//"):
                raise ValueError("فقط پیوند داخلی با / مجاز است.")
        return value or None


def _validate_target(payload: NotificationInput) -> list[str]:
    targets = list(dict.fromkeys(str(value).strip() for value in payload.targets if str(value).strip()))
    if payload.target_type != "all" and not targets:
        raise HTTPException(status_code=422, detail="حداقل یک مخاطب انتخاب کنید.")
    if payload.status == "scheduled":
        scheduled = _parse_schedule(payload.scheduled_at)
        if not scheduled or scheduled <= datetime.utcnow():
            raise HTTPException(status_code=422, detail="زمان انتشار باید در آینده باشد.")
    return targets


def _fanout(cursor, notification_id: int) -> int:
    cursor.execute("SELECT target_type FROM notifications WHERE id = ?", (notification_id,))
    row = cursor.fetchone()
    if not row:
        raise HTTPException(status_code=404, detail="اعلان پیدا نشد.")
    target_type = str(row[0]).strip()
    condition = {
        "all": "1 = 1",
        "selected": "EXISTS (SELECT 1 FROM notification_targets t WHERE t.notification_id = ? AND RTRIM(t.target_value) = RTRIM(u.username))",
        "role": "EXISTS (SELECT 1 FROM notification_targets t WHERE t.notification_id = ? AND RTRIM(t.target_value) = RTRIM(u.role))",
        "department": "EXISTS (SELECT 1 FROM notification_targets t WHERE t.notification_id = ? AND RTRIM(t.target_value) = RTRIM(u.department))",
    }[target_type]
    params = (notification_id,) if target_type != "all" else ()
    cursor.execute(
        f"""INSERT INTO user_notifications (notification_id, username)
            SELECT ?, RTRIM(u.username) FROM user_table u
            WHERE {condition}
              AND NOT EXISTS (SELECT 1 FROM user_notifications un
                  WHERE un.notification_id = ? AND RTRIM(un.username) = RTRIM(u.username))""",
        (notification_id, *params, notification_id),
    )
    return max(0, cursor.rowcount if cursor.rowcount is not None else 0)


def _deliver_push(conn, notification_id: int, username: str | None = None) -> None:
    """Best-effort delivery from the durable inbox; source writes remain primary."""
    if username:
        where = "RTRIM(username)=?"
        params = (username,)
    else:
        where = "1=1"
        params = ()
    cur = conn.cursor()
    cur.execute("SELECT endpoint,p256dh,auth FROM push_subscriptions WHERE disabled_at IS NULL AND " + where, params)
    subscriptions = [{"endpoint": r[0], "p256dh": r[1], "auth": r[2]} for r in cur.fetchall()]
    if not subscriptions:
        return
    cur.execute("SELECT id,title,content,type,action_url FROM notifications WHERE id=?", (notification_id,))
    row = cur.fetchone()
    if not row:
        return
    expired = send_to_subscriptions(subscriptions, {"id": row[0], "title": row[1], "body": row[2], "type": row[3], "url": row[4] or "/user_panel", "tag": "hastama-" + str(row[0])})
    for endpoint in expired:
        cur.execute("UPDATE push_subscriptions SET disabled_at=SYSUTCDATETIME(), updated_at=SYSUTCDATETIME() WHERE endpoint=?", (endpoint,))


def publish_system_notification(
    conn,
    title: str,
    content: str,
    targets: list[str],
    notification_type: str = "information",
    priority: str = "normal",
    action_url: Optional[str] = "/user_panel",
) -> int:
    """Publish a durable event from another subsystem into the notification inbox.

    Ticketing and attendance remain separate domains; they only call this small
    delivery boundary when a user-facing event should appear in notifications.
    The caller owns the transaction so the event is committed with its source
    change whenever possible.
    """
    values = list(dict.fromkeys(str(value or "").strip() for value in targets if str(value or "").strip()))
    if not values:
        return 0
    if notification_type not in {"general", "announcement", "system", "warning", "information", "success", "reminder"}:
        notification_type = "information"
    if priority not in {"normal", "important", "high", "critical"}:
        priority = "normal"
    conn_cursor = conn.cursor()
    _ensure_schema(conn)
    conn_cursor.execute(
        """INSERT INTO notifications
               (title,content,type,priority,status,target_type,action_url,created_by,published_at)
           OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,?,?,SYSUTCDATETIME())""",
        (str(title).strip()[:180], str(content).strip()[:4000], notification_type,
         priority, "published", "selected", action_url, "سامانه"),
    )
    notification_id = int(conn_cursor.fetchone()[0])
    for value in values:
        conn_cursor.execute(
            "INSERT INTO notification_targets (notification_id,target_value) VALUES (?,?)",
            (notification_id, value),
        )
    _fanout(conn_cursor, notification_id)
    _deliver_push(conn, notification_id)
    return notification_id


def publish_system_notification_to_admins(
    conn,
    title: str,
    content: str,
    notification_type: str = "information",
    priority: str = "normal",
    action_url: Optional[str] = "/admin",
) -> int:
    """Deliver a system event to every account whose persisted role is admin."""
    cursor = conn.cursor()
    cursor.execute(
        "SELECT RTRIM(username) FROM user_table WHERE LOWER(RTRIM(COALESCE(role, ''))) = 'admin'"
    )
    admins = [str(row[0]).strip() for row in cursor.fetchall() if row[0]]
    return publish_system_notification(
        conn,
        title,
        content,
        admins,
        notification_type=notification_type,
        priority=priority,
        action_url=action_url,
    )


def _publish(cursor, notification_id: int, actor: str) -> int:
    cursor.execute("SELECT status FROM notifications WHERE id = ?", (notification_id,))
    row = cursor.fetchone()
    if not row:
        raise HTTPException(status_code=404, detail="اعلان پیدا نشد.")
    if str(row[0]).strip() == "archived":
        raise HTTPException(status_code=409, detail="اعلان بایگانی‌شده قابل انتشار نیست.")
    delivered = _fanout(cursor, notification_id)
    cursor.execute(
        """UPDATE notifications SET status = 'published', published_at = COALESCE(published_at, SYSUTCDATETIME()),
           scheduled_at = NULL, updated_at = SYSUTCDATETIME() WHERE id = ?""",
        (notification_id,),
    )
    return delivered


def _publish_due(cursor) -> None:
    cursor.execute("SELECT id FROM notifications WHERE status = 'scheduled' AND scheduled_at <= SYSUTCDATETIME()")
    for row in cursor.fetchall():
        _publish(cursor, int(row[0]), "scheduler")


@router.get("/admin/notification-targets")
def target_options(request: Request):
    _actor(request, admin=True)
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor()
        cur.execute("SELECT RTRIM(username) username, RTRIM(COALESCE(name, '')) name, RTRIM(COALESCE(last_name, '')) last_name, RTRIM(COALESCE(department, '')) department, RTRIM(COALESCE(role, '')) role FROM user_table ORDER BY name, username")
        users = _dict_rows(cur)
        return {
            "users": users,
            "departments": sorted({u["department"] for u in users if u["department"]}),
            "roles": sorted({u["role"] for u in users if u["role"]}),
        }
    finally:
        conn.close()


@router.get("/admin/notifications")
def admin_list(
    request: Request,
    page: int = Query(1, ge=1),
    page_size: int = Query(15, ge=5, le=100),
    search: str = Query("", max_length=100),
    status: str = Query(""),
    type: str = Query(""),
    priority: str = Query(""),
    sort: Literal["newest", "oldest", "title"] = "newest",
):
    _actor(request, admin=True)
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor()
        _publish_due(cur)
        clauses, params = ["1=1"], []
        if search:
            clauses.append("(n.title LIKE ? OR n.content LIKE ?)")
            params.extend([f"%{search.strip()}%"] * 2)
        for field, value, allowed in (
            ("status", status, {"draft", "scheduled", "published", "archived"}),
            ("type", type, {"general", "announcement", "system", "warning", "information", "success", "reminder"}),
            ("priority", priority, {"normal", "important", "high", "critical"}),
        ):
            if value in allowed:
                clauses.append(f"n.{field} = ?")
                params.append(value)
        where = " AND ".join(clauses)
        order = {"newest": "n.created_at DESC", "oldest": "n.created_at ASC", "title": "n.title ASC"}[sort]
        cur.execute(f"SELECT COUNT(*) FROM notifications n WHERE {where}", tuple(params))
        total = int(cur.fetchone()[0])
        cur.execute(
            f"""SELECT n.*, (SELECT STRING_AGG(t.target_value, N'||') FROM notification_targets t WHERE t.notification_id=n.id) target_values
                FROM notifications n
                WHERE {where}
                ORDER BY {order} OFFSET ? ROWS FETCH NEXT ? ROWS ONLY""",
            tuple(params + [(page - 1) * page_size, page_size]),
        )
        items = [_serialize(row) for row in _dict_rows(cur)]
        cur.execute("""SELECT COUNT(*) total,
            SUM(CASE WHEN status='published' THEN 1 ELSE 0 END) published,
            SUM(CASE WHEN status='draft' THEN 1 ELSE 0 END) draft,
            SUM(CASE WHEN status='scheduled' THEN 1 ELSE 0 END) scheduled,
            SUM(CASE WHEN status='archived' THEN 1 ELSE 0 END) archived FROM notifications""")
        stats = _dict_rows(cur)[0]
        conn.commit()
        return {"items": items, "total": total, "page": page, "page_size": page_size, "pages": max(1, (total + page_size - 1) // page_size), "stats": stats}
    finally:
        conn.close()


@router.post("/admin/notifications/read-all")
def admin_mark_all_read(request: Request):
    """Mark every visible notification in the current admin's inbox as read."""
    username = _actor(request, admin=True)
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor()
        cur.execute(
            """UPDATE un
               SET read_at=COALESCE(un.read_at,SYSUTCDATETIME()),
                   updated_at=SYSUTCDATETIME()
               FROM user_notifications un
               INNER JOIN notifications n ON n.id=un.notification_id
               WHERE RTRIM(un.username)=?
                 AND un.dismissed_at IS NULL
                 AND n.status IN ('published','archived')""",
            (username,),
        )
        changed = max(0, cur.rowcount or 0)
        conn.commit()
        return {"success": True, "updated": changed}
    finally:
        conn.close()


@router.post("/admin/notifications", status_code=201)
def create_notification(payload: NotificationInput, request: Request):
    actor = _actor(request, admin=True)
    targets = _validate_target(payload)
    scheduled_at = _parse_schedule(payload.scheduled_at)
    initial_status = "scheduled" if payload.status == "scheduled" else "draft"
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor()
        cur.execute(
            """INSERT INTO notifications (title,content,type,priority,status,target_type,action_label,action_url,created_by,scheduled_at)
               OUTPUT INSERTED.id VALUES (?,?,?,?,?,?,?,?,?,?)""",
            (payload.title, payload.content, payload.type, payload.priority, initial_status, payload.target_type,
             payload.action_label, payload.action_url, actor, scheduled_at),
        )
        notification_id = int(cur.fetchone()[0])
        for value in targets:
            cur.execute("INSERT INTO notification_targets (notification_id,target_value) VALUES (?,?)", (notification_id, value))
        delivered = _publish(cur, notification_id, actor) if payload.status == "published" else 0
        conn.commit()
        return {"success": True, "id": notification_id, "status": payload.status, "delivered": delivered}
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


@router.put("/admin/notifications/{notification_id}")
def update_notification(notification_id: int, payload: NotificationInput, request: Request):
    actor = _actor(request, admin=True)
    targets = _validate_target(payload)
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor()
        cur.execute("SELECT status FROM notifications WHERE id=?", (notification_id,))
        row = cur.fetchone()
        if not row:
            raise HTTPException(status_code=404, detail="اعلان پیدا نشد.")
        if str(row[0]).strip() in {"published", "archived"}:
            raise HTTPException(status_code=409, detail="فقط پیش‌نویس یا اعلان زمان‌بندی‌شده قابل ویرایش است.")
        stored_status = "scheduled" if payload.status == "scheduled" else "draft"
        cur.execute("""UPDATE notifications SET title=?,content=?,type=?,priority=?,status=?,target_type=?,
            action_label=?,action_url=?,scheduled_at=?,updated_at=SYSUTCDATETIME() WHERE id=?""",
            (payload.title,payload.content,payload.type,payload.priority,stored_status,payload.target_type,
             payload.action_label,payload.action_url,_parse_schedule(payload.scheduled_at),notification_id))
        cur.execute("DELETE FROM notification_targets WHERE notification_id=?", (notification_id,))
        for value in targets:
            cur.execute("INSERT INTO notification_targets (notification_id,target_value) VALUES (?,?)", (notification_id,value))
        delivered = _publish(cur, notification_id, actor) if payload.status == "published" else 0
        conn.commit()
        return {"success": True, "id": notification_id, "status": payload.status, "delivered": delivered}
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


@router.post("/admin/notifications/{notification_id}/publish")
def publish_notification(notification_id: int, request: Request):
    actor = _actor(request, admin=True)
    conn = get_connection()
    try:
        _ensure_schema(conn)
        delivered = _publish(conn.cursor(), notification_id, actor)
        conn.commit()
        return {"success": True, "delivered": delivered}
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


@router.post("/admin/notifications/{notification_id}/archive")
def archive_notification(notification_id: int, request: Request):
    _actor(request, admin=True)
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor()
        cur.execute("UPDATE notifications SET status='archived',archived_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE id=?", (notification_id,))
        if cur.rowcount == 0:
            raise HTTPException(status_code=404, detail="اعلان پیدا نشد.")
        conn.commit()
        return {"success": True}
    finally:
        conn.close()


@router.delete("/admin/notifications/{notification_id}")
def delete_notification(notification_id: int, request: Request):
    _actor(request, admin=True)
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor()
        cur.execute("DELETE FROM notifications WHERE id=? AND status IN ('draft','scheduled','archived')", (notification_id,))
        if cur.rowcount == 0:
            raise HTTPException(status_code=409, detail="اعلان منتشرشده را ابتدا بایگانی کنید.")
        conn.commit()
        return {"success": True}
    finally:
        conn.close()


class PushSubscriptionInput(BaseModel):
    endpoint: str = Field(min_length=1, max_length=2048)
    keys: dict[str, str]
    user_agent: Optional[str] = Field(default=None, max_length=500)


@router.get("/push/config")
def push_config(request: Request):
    _actor(request)
    return {"public_key": web_push_public_key(), "enabled": bool(web_push_public_key())}


@router.post("/push/subscribe")
def push_subscribe(payload: PushSubscriptionInput, request: Request):
    username = _actor(request)
    p256dh = str(payload.keys.get("p256dh") or "").strip()
    auth = str(payload.keys.get("auth") or "").strip()
    if not p256dh or not auth:
        raise HTTPException(status_code=422, detail="اطلاعات Subscription ناقص است.")
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor()
        cur.execute("""UPDATE push_subscriptions SET username=?,p256dh=?,auth=?,user_agent=?,updated_at=SYSUTCDATETIME(),disabled_at=NULL
                       WHERE endpoint=?""", (username,p256dh,auth,payload.user_agent,payload.endpoint))
        if cur.rowcount == 0:
            cur.execute("INSERT INTO push_subscriptions (username,endpoint,p256dh,auth,user_agent) VALUES (?,?,?,?,?)", (username,payload.endpoint,p256dh,auth,payload.user_agent))
        conn.commit()
        return {"success": True}
    finally: conn.close()


@router.delete("/push/subscribe")
def push_unsubscribe(payload: PushSubscriptionInput, request: Request):
    username = _actor(request)
    conn = get_connection()
    try:
        _ensure_schema(conn); cur = conn.cursor()
        cur.execute("UPDATE push_subscriptions SET disabled_at=SYSUTCDATETIME(),updated_at=SYSUTCDATETIME() WHERE endpoint=? AND RTRIM(username)=?", (payload.endpoint,username))
        conn.commit(); return {"success": True}
    finally: conn.close()


@router.get("/push/status")
def push_status(request: Request):
    username = _actor(request)
    conn = get_connection()
    try:
        _ensure_schema(conn); cur=conn.cursor()
        cur.execute("SELECT COUNT(*) FROM push_subscriptions WHERE RTRIM(username)=? AND disabled_at IS NULL", (username,))
        return {"enabled": bool(web_push_public_key()), "subscriptions": int(cur.fetchone()[0])}
    finally: conn.close()


@router.get("/notifications")
def user_list(request: Request, page: int = Query(1, ge=1), page_size: int = Query(12, ge=5, le=50), state: Literal["all", "unread", "read"] = "all", search: str = Query("", max_length=100)):
    username = _actor(request)
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor()
        _publish_due(cur)
        clauses = ["RTRIM(un.username)=?", "un.dismissed_at IS NULL", "n.status IN ('published','archived')"]
        params: list = [username]
        if state == "unread": clauses.append("un.read_at IS NULL")
        elif state == "read": clauses.append("un.read_at IS NOT NULL")
        if search:
            clauses.append("(n.title LIKE ? OR n.content LIKE ?)")
            params.extend([f"%{search.strip()}%"] * 2)
        where = " AND ".join(clauses)
        cur.execute(f"SELECT COUNT(*) FROM user_notifications un JOIN notifications n ON n.id=un.notification_id WHERE {where}", tuple(params))
        total = int(cur.fetchone()[0])
        cur.execute(f"""SELECT n.id,n.title,n.content,n.type,n.priority,n.action_label,n.action_url,n.created_by,
            n.published_at,un.delivered_at,un.read_at FROM user_notifications un JOIN notifications n ON n.id=un.notification_id
            WHERE {where} ORDER BY un.delivered_at DESC OFFSET ? ROWS FETCH NEXT ? ROWS ONLY""", tuple(params + [(page-1)*page_size,page_size]))
        items = [_serialize(row) for row in _dict_rows(cur)]
        cur.execute("SELECT COUNT(*) FROM user_notifications WHERE RTRIM(username)=? AND read_at IS NULL AND dismissed_at IS NULL", (username,))
        unread = int(cur.fetchone()[0])
        conn.commit()
        return {"items":items,"total":total,"unread":unread,"page":page,"pages":max(1,(total+page_size-1)//page_size)}
    finally:
        conn.close()


@router.get("/notifications/unread-count")
def unread_count(request: Request):
    username = _actor(request)
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor(); _publish_due(cur)
        cur.execute("SELECT COUNT(*) FROM user_notifications un JOIN notifications n ON n.id=un.notification_id WHERE RTRIM(un.username)=? AND un.read_at IS NULL AND un.dismissed_at IS NULL AND n.status IN ('published','archived')", (username,))
        count = int(cur.fetchone()[0]); conn.commit()
        return {"unread": count}
    finally: conn.close()


@router.get("/notifications/poll")
def browser_notification_poll(request: Request):
    """Return a compact snapshot for Chrome notifications while a panel is open.

    This endpoint intentionally returns state, not a second notification inbox:
    the browser client compares snapshots and only announces changes observed
    after the page has finished its initial load.
    """
    username = _actor(request)
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor()

        cur.execute(
            """SELECT TOP 50 n.id,n.title,n.content,n.type,n.priority,n.action_url,n.created_by,
                      n.published_at,un.delivered_at,un.read_at
               FROM user_notifications un JOIN notifications n ON n.id=un.notification_id
               WHERE RTRIM(un.username)=? AND un.dismissed_at IS NULL
                 AND n.status IN ('published','archived')
               ORDER BY un.delivered_at DESC, n.id DESC""",
            (username,),
        )
        notifications = [_serialize(row) for row in _dict_rows(cur)]

        conn.commit()
        # Approval and ticket events are both represented by durable inbox rows.
        # This endpoint intentionally never reads ticket or attendance tables.
        return {"notifications": notifications}
    finally:
        conn.close()


@router.get("/notifications/stream")
def notification_stream(request: Request):
    """Push newly delivered inbox notifications, including ticket events."""
    username = _actor(request)

    async def events():
        seen = None
        try:
            last_event_id = int(request.headers.get("last-event-id") or 0)
        except (TypeError, ValueError):
            last_event_id = 0
        try:
            while True:
                conn = get_connection()
                try:
                    _ensure_schema(conn)
                    cur = conn.cursor()
                    cur.execute(
                        """SELECT TOP 100 n.id,n.title,n.content,n.type,n.priority,
                                  n.action_label,n.action_url,n.created_by,n.published_at,
                                  un.delivered_at,un.read_at
                           FROM user_notifications un
                           JOIN notifications n ON n.id=un.notification_id
                           WHERE RTRIM(un.username)=? AND un.dismissed_at IS NULL
                             AND n.status IN ('published','archived')
                           ORDER BY un.delivered_at DESC,n.id DESC""",
                        (username,),
                    )
                    items = [_serialize(row) for row in _dict_rows(cur)]
                finally:
                    conn.close()

                current = {str(item["id"]): item for item in items}
                if seen is None:
                    if last_event_id:
                        for item in reversed(items):
                            if int(item["id"]) > last_event_id:
                                yield "id: " + str(item["id"]) + "\ndata: " + json.dumps(item, ensure_ascii=False) + "\n\n"
                    seen = set(current)
                else:
                    for key, item in reversed(list(current.items())):
                        if key not in seen:
                            yield "id: " + str(item["id"]) + "\ndata: " + json.dumps(item, ensure_ascii=False) + "\n\n"
                    seen = set(current)
                yield ": heartbeat\n\n"
                await asyncio.sleep(2)
        except (GeneratorExit, ConnectionError):
            return

    return StreamingResponse(
        events(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache, no-store, must-revalidate",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )


@router.get("/notifications/admin-stream")
def admin_request_stream(request: Request):
    """Compatibility alias for the single durable notification stream."""
    _actor(request, admin=True)
    return notification_stream(request)


@router.get("/notifications/{notification_id}")
def notification_detail(notification_id: int, request: Request):
    """Return one notification only when it belongs to the signed-in user."""
    username = _actor(request)
    conn = get_connection()
    try:
        _ensure_schema(conn)
        cur = conn.cursor()
        cur.execute("""SELECT n.id,n.title,n.content,n.type,n.priority,n.action_label,n.action_url,
            n.created_by,n.published_at,un.delivered_at,un.read_at
            FROM user_notifications un JOIN notifications n ON n.id=un.notification_id
            WHERE n.id=? AND RTRIM(un.username)=? AND un.dismissed_at IS NULL AND n.status IN ('published','archived')""",
            (notification_id, username))
        rows = _dict_rows(cur)
        if not rows:
            raise HTTPException(status_code=404, detail="اعلان برای شما پیدا نشد.")
        return _serialize(rows[0])
    finally:
        conn.close()

def _owned_update(request: Request, notification_id: int, expression: str):
    username = _actor(request)
    conn = get_connection()
    try:
        _ensure_schema(conn); cur = conn.cursor()
        cur.execute(f"UPDATE user_notifications SET {expression},updated_at=SYSUTCDATETIME() WHERE notification_id=? AND RTRIM(username)=? AND dismissed_at IS NULL", (notification_id,username))
        if cur.rowcount == 0: raise HTTPException(status_code=404, detail="اعلان برای شما پیدا نشد.")
        conn.commit(); return {"success":True}
    finally: conn.close()


@router.post("/notifications/{notification_id}/read")
def mark_read(notification_id: int, request: Request): return _owned_update(request, notification_id, "read_at=COALESCE(read_at,SYSUTCDATETIME())")


@router.post("/notifications/{notification_id}/unread")
def mark_unread(notification_id: int, request: Request): return _owned_update(request, notification_id, "read_at=NULL")


@router.delete("/notifications/{notification_id}")
def dismiss(notification_id: int, request: Request): return _owned_update(request, notification_id, "dismissed_at=SYSUTCDATETIME()")


@router.post("/notifications/read-all")
def mark_all_read(request: Request):
    username = _actor(request); conn = get_connection()
    try:
        _ensure_schema(conn); cur=conn.cursor()
        cur.execute("UPDATE user_notifications SET read_at=COALESCE(read_at,SYSUTCDATETIME()),updated_at=SYSUTCDATETIME() WHERE RTRIM(username)=? AND dismissed_at IS NULL",(username,))
        changed=max(0,cur.rowcount or 0); conn.commit(); return {"success":True,"updated":changed}
    finally: conn.close()
