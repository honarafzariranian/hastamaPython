from __future__ import annotations
import os
import threading
from datetime import timezone
from typing import Optional
import pyodbc
from app.services.ticketing import store_private_attachment

_SCHEMA_READY = False
_SCHEMA_LOCK = threading.Lock()
_SCHEMA_PATH = os.path.abspath(os.path.join(os.path.dirname(__file__), '..', '..', 'database', 'automation.sql'))

def _iso(value):
    if not value: return None
    if isinstance(value, str): return value
    if value.tzinfo is None: value = value.replace(tzinfo=timezone.utc)
    return value.isoformat().replace('+00:00', 'Z')

def _rows(cur):
    cols = [c[0] for c in cur.description]
    return [dict(zip(cols, row)) for row in cur.fetchall()]

class AutomationService:
    def __init__(self, conn=None):
        self.conn = conn or pyodbc.connect(os.getenv('DATABASE_URL', 'DRIVER={ODBC Driver 17 for SQL Server};SERVER=localhost\\SQLEXPRESS;DATABASE=userDB;Trusted_Connection=yes;'))
        self.owns = conn is None
        global _SCHEMA_READY
        if not _SCHEMA_READY:
            with _SCHEMA_LOCK:
                if not _SCHEMA_READY:
                    with open(_SCHEMA_PATH, encoding='utf-8-sig') as schema_file:
                        self.conn.cursor().execute(schema_file.read())
                    self.conn.commit(); _SCHEMA_READY = True
        self.cursor = self.conn.cursor()
    def close(self):
        self.cursor.close()
        if self.owns: self.conn.close()
    def _participant(self, conversation_id, username):
        self.cursor.execute('SELECT 1 FROM automation_participants WHERE conversation_id=? AND username=?', (conversation_id, username))
        return bool(self.cursor.fetchone())
    def _allowed(self, conversation_id, username, is_admin, is_master):
        if is_master or is_admin: return True
        return self._participant(conversation_id, username)
    def list_conversations(self, username, is_admin, is_master):
        if is_master:
            where, params = '1=1', ()
        elif is_admin:
            where, params = '1=1', ()
        else:
            where, params = 'EXISTS (SELECT 1 FROM automation_participants p WHERE p.conversation_id=c.id AND p.username=?)', (username,)
        self.cursor.execute(f'''SELECT c.id,c.subject,c.created_by,c.created_at,c.updated_at,
          (SELECT COUNT(*) FROM automation_participants p WHERE p.conversation_id=c.id) participant_count,
          (SELECT COUNT(*) FROM automation_messages m WHERE m.conversation_id=c.id) message_count
          FROM automation_conversations c WHERE {where} ORDER BY c.updated_at DESC,c.id DESC''', params)
        return self._serialized(_rows(self.cursor))
    def create(self, username, subject, participants, body):
        names = list(dict.fromkeys([username] + [str(x).strip() for x in participants if str(x).strip()]))
        if not subject.strip() or not body.strip() or len(subject.strip()) > 180 or len(body.strip()) > 4000: raise ValueError('موضوع و متن معتبر نیست.')
        self.cursor.execute('INSERT INTO automation_conversations(subject,created_by) OUTPUT INSERTED.id VALUES(?,?)', (subject.strip(), username)); cid=int(self.cursor.fetchone()[0])
        for name in names: self.cursor.execute('INSERT INTO automation_participants(conversation_id,username) VALUES(?,?)', (cid,name))
        self.cursor.execute('INSERT INTO automation_messages(conversation_id,author_username,body) OUTPUT INSERTED.id VALUES(?,?,?)', (cid,username,body.strip()))
        self.conn.commit(); return self.get(cid, username, False, False)
    def get(self, cid, username, is_admin, is_master):
        if not self._allowed(cid, username, is_admin, is_master): return None
        self.cursor.execute('SELECT id,subject,created_by,created_at,updated_at FROM automation_conversations WHERE id=?', (cid,)); row=self.cursor.fetchone()
        if not row: return None
        result=dict(zip([c[0] for c in self.cursor.description],row)); result.update(created_at=_iso(result['created_at']),updated_at=_iso(result['updated_at']))
        if is_admin and not is_master: return result
        self.cursor.execute('SELECT id,author_username,body,created_at FROM automation_messages WHERE conversation_id=? ORDER BY created_at,id', (cid,)); result['messages']=self._serialized(_rows(self.cursor))
        self.cursor.execute('SELECT id,message_id,uploaded_by,original_name,content_type,size_bytes,created_at FROM automation_attachments WHERE conversation_id=? ORDER BY created_at,id', (cid,)); result['attachments']=self._serialized(_rows(self.cursor))
        return result
    def add_message(self,cid,username,body,is_admin,is_master):
        if is_admin and not is_master: raise PermissionError('دسترسی ارسال پیام ندارید.')
        if not self._allowed(cid,username,is_admin,is_master): raise PermissionError('دسترسی به گفتگو ندارید.')
        if not body.strip() or len(body.strip())>4000: raise ValueError('متن پیام معتبر نیست.')
        self.cursor.execute('INSERT INTO automation_messages(conversation_id,author_username,body) VALUES(?,?,?)', (cid,username,body.strip())); self.cursor.execute('UPDATE automation_conversations SET updated_at=SYSUTCDATETIME() WHERE id=?',(cid,)); self.conn.commit(); return self.get(cid,username,is_admin,is_master)
    def add_attachment(self,cid,username,metadata,is_admin,is_master):
        if is_admin and not is_master: raise PermissionError('دسترسی ارسال فایل ندارید.')
        if not self._allowed(cid,username,is_admin,is_master): raise PermissionError('دسترسی به گفتگو ندارید.')
        mid=metadata.get('message_id')
        if mid is not None:
            self.cursor.execute('SELECT 1 FROM automation_messages WHERE id=? AND conversation_id=?',(mid,cid))
            if not self.cursor.fetchone(): raise LookupError('پیام مقصد پیدا نشد.')
        self.cursor.execute('INSERT INTO automation_attachments(conversation_id,message_id,uploaded_by,original_name,storage_name,content_type,size_bytes) OUTPUT INSERTED.id VALUES(?,?,?,?,?,?,?)',(cid,mid,username,metadata['original_name'],metadata['storage_name'],metadata['content_type'],metadata['size_bytes']))
        aid=int(self.cursor.fetchone()[0]); self.conn.commit(); return {'id':aid,'original_name':metadata['original_name']}
    def attachment(self,cid,aid,username,is_admin,is_master):
        if not self._allowed(cid,username,is_admin,is_master): return None
        self.cursor.execute('SELECT id,original_name,storage_name,content_type,size_bytes FROM automation_attachments WHERE id=? AND conversation_id=?',(aid,cid)); row=self.cursor.fetchone()
        return dict(zip([c[0] for c in self.cursor.description],row)) if row else None
    @staticmethod
    def _serialized(rows):
        for row in rows:
            for key in ('created_at','updated_at'): row[key]=_iso(row.get(key))
        return rows
