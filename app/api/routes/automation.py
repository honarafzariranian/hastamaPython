import logging
from pathlib import Path
from fastapi import APIRouter, File, HTTPException, Query, Request, UploadFile
from fastapi.responses import FileResponse
from pydantic import BaseModel, Field
from app.services.automation import AutomationService
from app.services.ticketing import store_private_attachment

router = APIRouter(prefix='/api/automation', tags=['automation'])
logger = logging.getLogger("hastama.automation")
class ConversationCreate(BaseModel):
    subject: str = Field(min_length=2, max_length=180)
    participants: list[str] = Field(default_factory=list, max_length=100)
    body: str = Field(min_length=1, max_length=4000)
class MessageCreate(BaseModel):
    body: str = Field(min_length=1, max_length=4000)
def _actor(request):
    username=str(request.session.get('username') or '').strip()
    if not username: raise HTTPException(401, 'برای ادامه وارد سامانه شوید.')
    return username, request.session.get('is_admin') is True, request.session.get('is_master_admin') is True
def _service(): return AutomationService()
def _error(exc):
    if isinstance(exc, PermissionError): return HTTPException(403, str(exc))
    if isinstance(exc, LookupError): return HTTPException(404, str(exc))
    if isinstance(exc, ValueError): return HTTPException(422, str(exc))
    return HTTPException(500, 'خطا در پردازش گفتگوی خودکار.')
@router.get('')
def list_conversations(request: Request):
    actor, admin, master = _actor(request)
    service = None
    try:
        service = _service()
        return {'items':service.list_conversations(actor,admin,master)}
    except Exception as exc:
        logger.exception("failed to list internal automation conversations")
        raise _error(exc) from exc
    finally:
        if service is not None:
            service.close()
@router.post('/{conversation_id}/complete', status_code=204)
def complete_conversation(conversation_id:int, request:Request):
    actor,admin,master=_actor(request); service=_service()
    try: service.complete(conversation_id,actor,admin,master)
    except Exception as exc: raise _error(exc) from exc
    finally: service.close()
@router.post('/{conversation_id}/reopen-request', status_code=204)
def request_reopen(conversation_id:int, request:Request):
    actor,admin,master=_actor(request); service=_service()
    try: service.request_reopen(conversation_id,actor,admin,master)
    except Exception as exc: raise _error(exc) from exc
    finally: service.close()
@router.post('/{conversation_id}/reopen-request/{request_id}/approve', status_code=204)
def approve_reopen(conversation_id:int, request_id:int, request:Request):
    actor,admin,master=_actor(request); service=_service()
    try: service.approve_reopen(conversation_id,request_id,actor,admin,master)
    except Exception as exc: raise _error(exc) from exc
    finally: service.close()
@router.post('', status_code=201)
def create_conversation(payload: ConversationCreate, request: Request):
    actor, admin, master = _actor(request)
    if admin and not master: raise HTTPException(403, 'دسترسی ایجاد گفتگو ندارید.')
    service=_service()
    try:
        result = service.create(actor,payload.subject,payload.participants,payload.body)
        if not result or not result.get('id'):
            raise LookupError('گفتگوی ایجادشده قابل بازیابی نیست.')
        return result
    except Exception as exc: raise _error(exc) from exc
    finally: service.close()
@router.get('/{conversation_id}')
def get_conversation(conversation_id:int, request:Request):
    actor,admin,master=_actor(request); service=_service()
    try:
        result=service.get(conversation_id,actor,admin,master)
        if result is None: raise HTTPException(404,'گفتگو پیدا نشد.')
        return result
    finally: service.close()
@router.delete('/{conversation_id}', status_code=204)
def delete_conversation(conversation_id:int, request:Request):
    actor,admin,master=_actor(request); service=_service()
    try:
        service.delete(conversation_id, actor, admin, master)
    except Exception as exc:
        raise _error(exc) from exc
    finally: service.close()
@router.post('/{conversation_id}/messages')
def add_message(conversation_id:int,payload:MessageCreate,request:Request):
    actor,admin,master=_actor(request); service=_service()
    try: return service.add_message(conversation_id,actor,payload.body,admin,master)
    except Exception as exc: raise _error(exc) from exc
    finally: service.close()
@router.post('/{conversation_id}/attachments')
async def upload_attachment(conversation_id:int,request:Request,file:UploadFile=File(...),message_id:int|None=Query(None,ge=1)):
    actor,admin,master=_actor(request)
    if admin and not master: raise HTTPException(403,'دسترسی ارسال فایل ندارید.')
    payload=await file.read(10*1024*1024+1)
    try: metadata=store_private_attachment(file.filename or 'attachment',file.content_type or '',payload)
    except ValueError as exc: raise HTTPException(422,str(exc)) from exc
    service=_service()
    try:
        metadata['message_id']=message_id; return service.add_attachment(conversation_id,actor,metadata,admin,master)
    except Exception as exc:
        try: Path(metadata['path']).unlink(missing_ok=True)
        except OSError: pass
        raise _error(exc) from exc
    finally: service.close()
@router.get('/{conversation_id}/attachments/{attachment_id}')
def download_attachment(conversation_id:int,attachment_id:int,request:Request):
    actor,admin,master=_actor(request); service=_service()
    try:
        item=service.attachment(conversation_id,attachment_id,actor,admin,master)
        if not item: raise HTTPException(404,'فایل پیدا نشد.')
        root=Path(__import__('os').getenv('TICKETING_PRIVATE_DIR','app/private_uploads/tickets')).resolve(); path=(root/item['storage_name']).resolve()
        if root not in path.parents or not path.is_file(): raise HTTPException(404,'فایل پیدا نشد.')
        return FileResponse(path,media_type=item['content_type'],filename=item['original_name'])
    finally: service.close()
