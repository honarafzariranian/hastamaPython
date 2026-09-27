"""صف نوبت‌دهی باید پیوسته باشد.

دو رفتار که این تست‌ها محافظت می‌کنند:

۱) شمارهٔ نوبت هرگز به ۱ برنمی‌گردد — نه با ریستارت سرور (شمارنده در حافظه
   نیست) و نه با عوض‌شدن روز (شمارنده با تاریخ فیلتر نمی‌شود)؛

۲) صف به روز بسته نمی‌شود: نوبتِ «در انتظار»/«فراخوان‌شده» از هر تاریخی در
   صف می‌ماند تا فراخوان یا حذف شود، و تنها تاریخ (پایان‌یافته) به امروز
   محدود است.

تست‌ها با اتصال جعلی اجرا می‌شوند و SQL واقعی هر endpoint را بررسی می‌کنند.
"""
from __future__ import annotations

import asyncio

import pytest

from app.api.routes import call_system


class FakeCursor:
    """Cursor جعلی: پاسخ‌های آماده را به ترتیب برمی‌گرداند و SQL را ثبت می‌کند."""

    def __init__(self, rows=None):
        self._rows = list(rows or [])
        self.statements: list[str] = []
        self.params: list[object] = []
        self.description: list = []
        self.rowcount = 0

    def execute(self, sql, params=None):
        self.statements.append(" ".join(str(sql).split()))
        self.params.append(params)
        return self

    def fetchone(self):
        return self._rows.pop(0) if self._rows else None

    def fetchall(self):
        return self._rows.pop(0) if self._rows else []

    def close(self):
        pass


class FakeConnection:
    def __init__(self, rows=None):
        self.cursor_obj = FakeCursor(rows)
        self.committed = False

    def cursor(self):
        return self.cursor_obj

    def commit(self):
        self.committed = True

    def rollback(self):
        pass

    def close(self):
        pass

    @property
    def statements(self):
        return self.cursor_obj.statements

    def statement(self, needle: str) -> str:
        matches = [s for s in self.statements if needle.lower() in s.lower()]
        assert matches, f"no statement containing {needle!r} in {self.statements}"
        return matches[0]


def _install(monkeypatch, *connections):
    """اتصال‌های جعلی را به ترتیب به _get_connection بده."""
    queue = list(connections)
    monkeypatch.setattr(call_system, "_schema_ready", True)
    monkeypatch.setattr(call_system, "_get_connection", lambda: queue.pop(0))


def _request(session=None, origin="http://127.0.0.1:5000"):
    class Req:
        def __init__(self):
            self.session = dict(session or {})
            self.headers = {"host": "127.0.0.1:5000"}
            if origin:
                self.headers["origin"] = origin
            self.client = type("C", (), {"host": "10.0.0.5"})()

    return Req()


def _run(coro):
    return asyncio.new_event_loop().run_until_complete(coro)


def _where(sql: str) -> str:
    """شرط WHERE یک دستور (ستون‌های انتخابی هم نام ticket_date دارند)."""
    return sql.split("WHERE", 1)[1] if "WHERE" in sql else ""


# ── شمارهٔ نوبت ─────────────────────────────────────────────────────────────


def test_ticket_number_continues_from_the_last_one(monkeypatch):
    """نوبت بعدی = بزرگ‌ترین شمارهٔ موجود + ۱، مستقل از تاریخ و ریستارت."""
    conn = FakeConnection(rows=[(call_system.datetime.now().date(),), (32,), (7,)])
    counter = FakeConnection(rows=[(5,)])
    _install(monkeypatch, conn, counter)

    body = _run(call_system.take_queue_ticket(_request())).body
    payload = __import__("json").loads(body)

    numbering = conn.statement("MAX(ticket_number)")
    assert "ticket_date" not in numbering, numbering
    assert "UPDLOCK" in numbering and "HOLDLOCK" in numbering, numbering
    assert payload["ticket"]["number"] == 32
    assert payload["ticket"]["persian_number"] == "۳۲"
    assert conn.committed is True


def test_numbering_never_restarts_from_zero(monkeypatch):
    """حتی وقتی آخرین نوبت مربوط به روزهای قبل است، شماره ادامه پیدا می‌کند."""
    conn = FakeConnection(rows=[(call_system.datetime.now().date(),), (128,), (99,)])
    counter = FakeConnection(rows=[(3,)])
    _install(monkeypatch, conn, counter)

    payload = __import__("json").loads(_run(call_system.take_queue_ticket(_request())).body)
    assert payload["ticket"]["number"] == 128 > 1


def test_open_queue_count_covers_every_day(monkeypatch):
    """شمارندهٔ «نفر در صف» فقط نوبت‌های امروز را نمی‌شمارد."""
    conn = FakeConnection(rows=[(call_system.datetime.now().date(),), (12,), (3,)])
    counter = FakeConnection(rows=[(9,)])
    _install(monkeypatch, conn, counter)

    payload = __import__("json").loads(_run(call_system.take_queue_ticket(_request())).body)
    count_sql = counter.statement("COUNT(*) FROM dbo.queue_tickets")
    assert "ticket_date" not in count_sql, count_sql
    assert payload["ticket"]["waiting_count"] == 9


# ── نمای صف ─────────────────────────────────────────────────────────────────


def test_waiting_list_keeps_carried_over_tickets(monkeypatch):
    conn = FakeConnection(rows=[[]])
    _install(monkeypatch, conn)
    _run(call_system.list_queue_tickets(_request(), status="waiting"))
    where = _where(conn.statement("FROM dbo.queue_tickets"))
    assert "ticket_date" not in where, where
    assert "status = ?" in where


def test_called_list_keeps_carried_over_tickets(monkeypatch):
    conn = FakeConnection(rows=[[]])
    _install(monkeypatch, conn)
    _run(call_system.list_queue_tickets(_request(), status="called"))
    assert "ticket_date" not in _where(conn.statement("FROM dbo.queue_tickets"))


def test_finished_history_is_still_today_only(monkeypatch):
    conn = FakeConnection(rows=[[]])
    _install(monkeypatch, conn)
    _run(call_system.list_queue_tickets(_request(), status="completed"))
    assert "ticket_date = CAST(SYSUTCDATETIME() AS DATE)" in _where(
        conn.statement("FROM dbo.queue_tickets")
    )


def test_all_view_is_open_queue_plus_today(monkeypatch):
    conn = FakeConnection(rows=[[]])
    _install(monkeypatch, conn)
    _run(call_system.list_queue_tickets(_request(), status="all"))
    where = _where(conn.statement("FROM dbo.queue_tickets"))
    assert "status IN ('waiting', 'called')" in where
    assert "ticket_date = CAST(SYSUTCDATETIME() AS DATE)" in where


def test_stats_count_the_open_queue_and_todays_finished(monkeypatch):
    conn = FakeConnection(rows=[(7, 5, 2, 4)])
    _install(monkeypatch, conn)
    payload = __import__("json").loads(_run(call_system.queue_stats()).body)
    sql = conn.statement("FROM dbo.queue_tickets")
    assert "status IN ('waiting', 'called')" in sql
    assert "ticket_date = CAST(SYSUTCDATETIME() AS DATE)" in sql
    assert payload["stats"] == {"total": 7, "waiting": 5, "called": 2, "completed": 4}


def test_take_queue_ticket_normalizes_empty_stats(monkeypatch):
    """آمار خالی (NULL) نباید به خطا تبدیل شود."""
    conn = FakeConnection(rows=[(None, None, None, None)])
    _install(monkeypatch, conn)
    payload = __import__("json").loads(_run(call_system.queue_stats()).body)
    assert payload["stats"] == {"total": 0, "waiting": 0, "called": 0, "completed": 0}


# ── فراخوان و حذف و اصلاح ──────────────────────────────────────────────────


def test_next_ticket_to_call_is_the_oldest_waiting_one(monkeypatch):
    conn = FakeConnection(rows=[(5, 2)])
    _install(monkeypatch, conn)
    _run(call_system.call_next_ticket(_request(), department="پذیرش"))
    sql = conn.statement("FROM dbo.queue_tickets")
    assert "ticket_date" not in _where(sql), sql
    assert "ORDER BY ticket_number ASC" in sql


def test_deleting_a_waiting_ticket_works_across_days(monkeypatch):
    conn = FakeConnection(rows=[[]])
    conn.cursor_obj.rowcount = 1
    _install(monkeypatch, conn)
    _run(call_system.delete_queue_ticket(_request(session={"username": "a", "is_admin": True}), 5))
    where = _where(conn.statement("DELETE FROM dbo.queue_tickets"))
    assert "ticket_date" not in where, where
    assert "status = 'waiting'" in where


def test_clearing_the_queue_clears_every_waiting_ticket(monkeypatch):
    conn = FakeConnection(rows=[[]])
    conn.cursor_obj.rowcount = 4
    _install(monkeypatch, conn)
    payload = __import__("json").loads(
        _run(call_system.delete_waiting_queue(_request(session={"username": "a", "is_admin": True}))).body
    )
    assert "ticket_date" not in _where(conn.statement("DELETE FROM dbo.queue_tickets"))
    assert payload["deleted_count"] == 4


def test_ticket_lookup_prefers_the_open_ticket(monkeypatch):
    """حداقل در داده‌های قدیمی یک شماره در چند روز تکرار شده؛ باید نوبت بازِ
    تازه‌تر انتخاب شود تا اصلاح پذیرش روی نوبت درست بنشیند."""
    row = (11, 5, call_system.datetime.now().date(), "waiting", "پذیرش",
           "تقی", "26", "0890519684", "09332905840", "تأمین اجتماعی", "آسیا")
    conn = FakeConnection(rows=[row])
    conn.cursor_obj.description = [
        (name,) for name in (
            "id", "ticket_number", "ticket_date", "status", "service",
            "patient_name", "patient_age", "patient_national_id", "patient_phone",
            "insurance_base", "insurance_extra",
        )
    ]
    _install(monkeypatch, conn)
    payload = __import__("json").loads(_run(call_system.get_queue_ticket(_request(), 5)).body)
    sql = conn.statement("FROM dbo.queue_tickets")
    assert "ticket_date" not in _where(sql), sql
    assert "CASE WHEN status IN ('waiting', 'called') THEN 0 ELSE 1 END" in sql
    assert payload["ticket"]["id"] == 11
    assert payload["ticket"]["persian_number"] == "۵"


def test_editing_a_ticket_finds_it_by_id_not_by_day(monkeypatch):
    conn = FakeConnection(rows=[(11, "waiting")])
    _install(monkeypatch, conn)
    body = call_system.EditTicketRequest(patient_name="حسن")
    payload = __import__("json").loads(
        _run(call_system.edit_queue_ticket(_request(), 5, body)).body
    )
    assert payload["success"] is True
    find_sql = conn.statement("SELECT TOP 1 id, status")
    assert "ticket_date" not in _where(find_sql), find_sql
    assert "WHERE id = ?" in conn.statement("UPDATE dbo.queue_tickets")


def test_kiosk_badge_counts_the_whole_open_queue():
    """نشانگر «نفر در صف» کیوسک همان endpoint صف باز را می‌خواند."""
    from pathlib import Path

    kiosk = (Path(__file__).resolve().parents[1] / "app" / "templates" / "ticket-kiosk.html").read_text(
        encoding="utf-8"
    )
    assert "/api/queue/list?status=waiting" in kiosk


@pytest.mark.parametrize("status", ["waiting", "called", "completed", "all"])
def test_every_status_view_runs(monkeypatch, status):
    conn = FakeConnection(rows=[[]])
    _install(monkeypatch, conn)
    payload = __import__("json").loads(
        _run(call_system.list_queue_tickets(_request(), status=status)).body
    )
    assert payload["success"] is True
