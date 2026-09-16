"""Authenticated REST API for the normalized Hastama helpdesk."""
from __future__ import annotations

import os
from pathlib import Path

from fastapi import APIRouter, File, HTTPException, Query, Request, UploadFile
from fastapi.responses import FileResponse
from pydantic import BaseModel, Field, field_validator

from app.services.ticketing import (
    MESSAGE_VISIBILITIES,
    TICKET_PRIORITIES,
    TICKET_STATUSES,
    TicketService,
    actor_from_session,
    store_private_attachment,
)

router = APIRouter(prefix="/api/tickets", tags=["ticketing"])


class TicketCreate(BaseModel):
    recipient_username: str = Field(min_length=1, max_length=255)
    subject: str = Field(min_length=2, max_length=180)
    body: str = Field(min_length=2, max_length=4000)
    priority: str = Field(default="normal", max_length=16)
    category_id: int | None = Field(default=None, ge=1)

    @field_validator("recipient_username", "subject", "body")
    @classmethod
    def strip_text(cls, value: str) -> str:
        value = value.replace("\x00", "").strip()
        if not value:
            raise ValueError("این فیلد الزامی است.")
        return value


class MessageCreate(BaseModel):
    body: str = Field(min_length=1, max_length=4000)
    visibility: str = Field(default="public", max_length=16)

    @field_validator("body")
    @classmethod
    def strip_body(cls, value: str) -> str:
        value = value.replace("\x00", "").strip()
        if not value:
            raise ValueError("متن پیام الزامی است.")
        return value


class TicketUpdate(BaseModel):
    status: str | None = Field(default=None, max_length=32)
    priority: str | None = Field(default=None, max_length=16)
    category_id: int | None = Field(default=None, ge=1)
    assigned_to: str | None = Field(default=None, max_length=255)


def _actor(request: Request) -> tuple[str, bool, bool]:
    try:
        return actor_from_session(request)
    except PermissionError as exc:
        raise HTTPException(status_code=401, detail=str(exc)) from exc


def _assert_same_origin(request: Request) -> None:
    """Reject browser mutations initiated by a different origin.

    The application uses a signed session cookie. Checking Origin here adds a
    second, explicit CSRF boundary without requiring every legacy form to know
    about the ticket API.
    """
    origin = request.headers.get("origin")
    if origin and origin.rstrip("/") != str(request.base_url).rstrip("/"):
        raise HTTPException(status_code=403, detail="درخواست از مبدأ مجاز نیست.")


def _service():
    return TicketService()


def _domain_error(exc: Exception) -> HTTPException:
    if isinstance(exc, PermissionError):
        return HTTPException(status_code=403, detail=str(exc))
    if isinstance(exc, LookupError):
        return HTTPException(status_code=404, detail=str(exc))
    if isinstance(exc, ValueError):
        return HTTPException(status_code=422, detail=str(exc))
    return HTTPException(status_code=500, detail="خطا در پردازش تیکت.")


@router.get("/categories")
def categories(request: Request):
    _actor(request)
    service = _service()
    try:
        return {"items": service.categories()}
    finally:
        service.close()


@router.get("/users")
def ticket_users(request: Request):
    actor, is_admin, is_master_admin = _actor(request)
    service = _service()
    try:
        service.cursor.execute(
            """SELECT LTRIM(RTRIM(username)) username,
                      LTRIM(RTRIM(COALESCE(name, ''))) name,
                      LTRIM(RTRIM(COALESCE(last_name, ''))) last_name,
                      LTRIM(RTRIM(COALESCE(department, ''))) department
               FROM user_table ORDER BY name, username"""
        )
        users = []
        for row in service.cursor.fetchall():
            username = str(row[0] or "").strip()
            if username.casefold() == actor.casefold():
                continue
            users.append(
                {
                    "username": username,
                    "name": " ".join(str(value or "").strip() for value in row[1:3]).strip(),
                    "department": str(row[3] or "").strip(),
                }
            )
        return {"items": users}
    finally:
        service.close()


@router.get("")
def list_tickets(
    request: Request,
    page: int = Query(1, ge=1),
    page_size: int = Query(20, ge=5, le=100),
    search: str = Query("", max_length=100),
    status: str = Query("", max_length=32),
    priority: str = Query("", max_length=16),
    assignee: str = Query("", max_length=255),
    sort: str = Query("newest", max_length=16),
):
    actor, is_admin, is_master_admin = _actor(request)
    service = _service()
    try:
        return service.list_tickets(actor, is_admin, page, page_size, search, status, priority, assignee, sort, is_master_admin=is_master_admin)
    finally:
        service.close()


@router.post("", status_code=201)
def create_ticket(payload: TicketCreate, request: Request):
    _assert_same_origin(request)
    actor, is_admin, is_master_admin = _actor(request)
    if payload.priority not in TICKET_PRIORITIES:
        raise HTTPException(status_code=422, detail="اولویت تیکت معتبر نیست.")
    service = _service()
    try:
        try:
            return service.create_ticket(
                actor,
                is_admin,
                payload.recipient_username,
                payload.subject,
                payload.body,
                payload.priority,
                payload.category_id,
            )
        except Exception as exc:
            raise _domain_error(exc) from exc
    finally:
        service.close()


@router.get("/{ticket_id}")
def get_ticket(ticket_id: int, request: Request):
    actor, is_admin, is_master_admin = _actor(request)
    service = _service()
    try:
        ticket = service.get_ticket(ticket_id, actor, is_admin)
        if ticket is None:
            raise HTTPException(status_code=404, detail="تیکت پیدا نشد.")
        return ticket
    finally:
        service.close()


@router.post("/{ticket_id}/messages")
def add_message(ticket_id: int, payload: MessageCreate, request: Request):
    _assert_same_origin(request)
    actor, is_admin, is_master_admin = _actor(request)
    if payload.visibility not in MESSAGE_VISIBILITIES:
        raise HTTPException(status_code=422, detail="نوع پیام معتبر نیست.")
    service = _service()
    try:
        try:
            return service.add_message(ticket_id, actor, is_admin, payload.body, payload.visibility)
        except Exception as exc:
            raise _domain_error(exc) from exc
    finally:
        service.close()


@router.patch("/{ticket_id}")
def update_ticket(ticket_id: int, payload: TicketUpdate, request: Request):
    _assert_same_origin(request)
    actor, is_admin, is_master_admin = _actor(request)
    service = _service()
    try:
        try:
            return service.update_ticket(
                ticket_id,
                actor,
                is_admin,
                payload.status,
                payload.priority,
                payload.category_id,
                payload.assigned_to,
            )
        except Exception as exc:
            raise _domain_error(exc) from exc
    finally:
        service.close()


@router.post("/{ticket_id}/attachments")
async def upload_attachment(
    ticket_id: int,
    request: Request,
    file: UploadFile = File(...),
    message_id: int | None = Query(default=None, ge=1),
):
    _assert_same_origin(request)
    actor, is_admin, is_master_admin = _actor(request)
    payload = await file.read(10 * 1024 * 1024 + 1)
    try:
        metadata = store_private_attachment(file.filename or "attachment", file.content_type or "", payload)
    except ValueError as exc:
        raise HTTPException(status_code=422, detail=str(exc)) from exc
    service = _service()
    try:
        metadata["message_id"] = message_id
        try:
            return service.add_attachment(ticket_id, actor, is_admin, metadata)
        except Exception as exc:
            try:
                Path(metadata["path"]).unlink(missing_ok=True)
            except OSError:
                pass
            raise _domain_error(exc) from exc
    finally:
        service.close()


@router.get("/{ticket_id}/attachments/{attachment_id}")
def download_attachment(ticket_id: int, attachment_id: int, request: Request):
    actor, is_admin, is_master_admin = _actor(request)
    service = _service()
    try:
        attachment = service.attachment(ticket_id, attachment_id, actor, is_admin)
        if attachment is None:
            raise HTTPException(status_code=404, detail="فایل پیدا نشد.")
        root = Path(os.getenv("TICKETING_PRIVATE_DIR", "app/private_uploads/tickets")).resolve()
        path = (root / str(attachment["storage_name"])).resolve()
        if root not in path.parents or not path.is_file():
            raise HTTPException(status_code=404, detail="فایل پیدا نشد.")
        return FileResponse(
            path,
            media_type=attachment["content_type"],
            filename=attachment["original_name"],
        )
    finally:
        service.close()
