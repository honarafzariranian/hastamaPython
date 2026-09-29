"""Bulk session actions on ``/master-admin/sessions``.

Pins the two panel buttons the system owner asked for:

* **خاتمه همه نشست‌ها** — ``POST /master-admin/api/sessions/terminate-all``:
  closes every *active* session in one statement, keeping the master
  administrators logged in (the same rule outage mode uses) so the operator who
  pressed the button does not lose the control plane they need to recover.
* **حذف همه رکوردها** — ``DELETE /master-admin/api/sessions``: removes every row
  of the session registry, history included.  Destructive, no account exempted.

Both are master-admin only, CSRF-protected, and audited with the affected count.
"""

from __future__ import annotations

import asyncio
import json
import pathlib

from starlette.testclient import TestClient

from app.core import sessions

TEMPLATE = pathlib.Path("app/templates/master-admin.html")
SCRIPT = pathlib.Path("app/static/js/master-admin.js")


class _FakeRequest:
    """Just enough of a Starlette request for the handler body."""

    def __init__(self, body=None) -> None:
        self._body = body or {}
        self.session = {"username": "admin", "is_master_admin": True}
        self.client = type("Client", (), {"host": "127.0.0.1"})()
        self.headers: dict = {}
        self.url = type("URL", (), {"path": "/master-admin/api/sessions"})()
        self.query_params: dict = {}

    async def json(self):
        return self._body


def _run(coro):
    return asyncio.run(coro)


# ── authorization ───────────────────────────────────────────────────────────


def test_the_bulk_endpoints_need_a_master_admin():
    from app.main import app

    client = TestClient(app, base_url="https://hastama.ir", raise_server_exceptions=False)
    # The CSRF layer answers first (403) — an anonymous caller never reaches the
    # handler and never changes a row.
    assert client.post("/master-admin/api/sessions/terminate-all").status_code == 403
    assert client.delete("/master-admin/api/sessions").status_code == 403


def test_only_a_master_admin_session_is_accepted(monkeypatch):
    from app.api.routes import master_admin

    plain_admin = _FakeRequest()
    plain_admin.session = {"username": "bob", "is_admin": True}
    for handler in (master_admin.terminate_all_sessions, master_admin.delete_all_sessions):
        try:
            _run(handler(plain_admin))
        except Exception as exc:  # HTTPException from _master_admin
            assert getattr(exc, "status_code", None) in (401, 403)
        else:  # pragma: no cover - a plain admin must never be let through
            raise AssertionError(f"{handler.__name__} accepted an ordinary admin")


# ── terminate all ───────────────────────────────────────────────────────────


def test_terminate_all_closes_every_active_session_and_keeps_master_admins(monkeypatch):
    from app.api.routes import master_admin

    calls: list[tuple] = []
    audited: list[dict] = []
    monkeypatch.setenv("MASTER_ADMIN_USERNAMES", "ali, sara")
    monkeypatch.setattr(
        sessions,
        "revoke_all_sessions",
        lambda by="system", keep_usernames=(): calls.append((by, tuple(keep_usernames))) or 4,
    )
    monkeypatch.setattr(master_admin, "log_admin_action", lambda **kw: audited.append(kw) or "id")

    response = _run(master_admin.terminate_all_sessions(_FakeRequest()))
    payload = json.loads(response.body)

    assert response.status_code == 200
    assert payload == {"success": True, "terminated": 4, "kept_usernames": ["ali", "sara"]}
    assert calls == [("admin", ("ali", "sara"))]
    assert audited[-1]["action"] == "terminate_all_sessions"
    assert audited[-1]["admin_username"] == "admin"
    assert audited[-1]["after_data"] == {"terminated": 4, "kept_usernames": ["ali", "sara"]}


def test_terminate_all_reports_zero_without_failing(monkeypatch):
    from app.api.routes import master_admin

    monkeypatch.setattr(sessions, "revoke_all_sessions", lambda *a, **k: 0)
    monkeypatch.setattr(master_admin, "log_admin_action", lambda **kw: "id")

    payload = json.loads(_run(master_admin.terminate_all_sessions(_FakeRequest())).body)
    assert payload["success"] is True
    assert payload["terminated"] == 0


def test_the_keep_list_comes_from_the_environment(monkeypatch):
    monkeypatch.setenv("MASTER_ADMIN_USERNAMES", " ali , sara ,, ")
    assert sessions.master_admin_usernames() == ("ali", "sara")
    monkeypatch.delenv("MASTER_ADMIN_USERNAMES", raising=False)
    assert sessions.master_admin_usernames() == ("ali",)


# ── delete all ──────────────────────────────────────────────────────────────


def test_delete_all_removes_every_record_and_is_audited(monkeypatch):
    from app.api.routes import master_admin

    audited: list[dict] = []
    monkeypatch.setattr(sessions, "delete_all_session_records", lambda: 7)
    monkeypatch.setattr(master_admin, "log_admin_action", lambda **kw: audited.append(kw) or "id")

    response = _run(master_admin.delete_all_sessions(_FakeRequest()))
    payload = json.loads(response.body)

    assert response.status_code == 200
    assert payload == {"success": True, "deleted": 7}
    assert audited[-1]["action"] == "delete_all_session_records"
    assert audited[-1]["after_data"] == {"deleted": 7}


def test_delete_all_session_records_purges_the_table_and_the_cache(monkeypatch):
    executed: list[tuple] = []
    committed: list[bool] = []

    class _Cursor:
        rowcount = 12

        def execute(self, sql, params=None):
            executed.append((sql, params))

        def close(self):
            pass

    class _Conn:
        def cursor(self):
            return _Cursor()

        def commit(self):
            committed.append(True)

        def close(self):
            pass

    monkeypatch.setattr(sessions, "_connect", lambda: _Conn())
    sessions._cache_set("some-sid", True)
    assert sessions._cache_get("some-sid") is True

    removed = sessions.delete_all_session_records()

    assert removed == 12
    assert executed and "DELETE FROM dbo.user_sessions" in executed[0][0]
    assert committed == [True]
    assert sessions._cache_get("some-sid") is None, "a stale cache would keep a deleted sid valid"


def test_delete_all_session_records_survives_a_database_error(monkeypatch):
    def _boom():
        raise RuntimeError("no database")

    monkeypatch.setattr(sessions, "_connect", _boom)
    assert sessions.delete_all_session_records() == 0


# ── routing / wiring ────────────────────────────────────────────────────────


def test_the_bulk_route_does_not_shadow_the_single_row_route():
    from app.api.routes.master_admin import router

    paths = {(route.path, tuple(sorted(route.methods or []))) for route in router.routes}
    assert ("/master-admin/api/sessions", ("DELETE",)) in paths
    assert ("/master-admin/api/sessions/terminate-all", ("POST",)) in paths
    # the per-row actions are untouched
    assert ("/master-admin/api/sessions/{session_key}/terminate", ("POST",)) in paths
    assert ("/master-admin/api/sessions/{session_key}", ("DELETE",)) in paths


def test_the_panel_exposes_both_bulk_buttons():
    template = TEMPLATE.read_text("utf-8")
    sessions_block = template.split("{% elif active_section == 'sessions' %}", 1)[1].split("{% elif", 1)[0]

    assert 'id="maTerminateAllSessions"' in sessions_block
    assert 'id="maDeleteAllSessions"' in sessions_block
    assert "خاتمه همه نشست‌ها" in sessions_block
    assert "حذف همه رکوردها" in sessions_block
    assert 'id="maSessionBulkCount"' in sessions_block
    # the buttons must be in the sessions panel only
    assert template.count('id="maTerminateAllSessions"') == 1
    assert template.count('id="maDeleteAllSessions"') == 1
    assert template.count('id="maSessionBulkCount"') == 1
    for other in ("users", "audit-logs", "security"):
        other_block = template.split("{%% elif active_section == '%s' %%}" % other, 1)[1].split("{% elif", 1)[0]
        assert 'id="maTerminateAllSessions"' not in other_block
        assert 'id="maDeleteAllSessions"' not in other_block


def test_the_buttons_are_bound_without_inline_handlers():
    template = TEMPLATE.read_text("utf-8")
    sessions_block = template.split("{% elif active_section == 'sessions' %}", 1)[1].split("{% elif", 1)[0]
    assert "onclick=" not in sessions_block, "bulk buttons must be bound in JS, not inline"

    script = SCRIPT.read_text("utf-8")
    assert "addEventListener('click', () => window.ma_terminateAllSessions())" in script
    assert "addEventListener('click', () => window.ma_deleteAllSessions())" in script


def _bulk_definition(script, name, following):
    """Return only the body of `window.<name> = async function () {...}`."""
    marker = "window.%s = async function" % name
    assert marker in script, "%s is not defined" % name
    return script.split(marker, 1)[1].split("window.%s = async function" % following, 1)[0]


def test_the_bulk_actions_call_the_new_endpoints():
    script = SCRIPT.read_text("utf-8")

    terminate_all = _bulk_definition(script, "ma_terminateAllSessions", "ma_deleteAllSessions")
    assert "api('/sessions/terminate-all', { method: 'POST' })" in terminate_all
    assert "maConfirm" in terminate_all, "a bulk action must be confirmed first"
    assert "نشست مدیران ارشد حفظ می‌شود" in terminate_all

    delete_all = _bulk_definition(script, "ma_deleteAllSessions", "ma_deleteSession")
    assert "api('/sessions', { method: 'DELETE' })" in delete_all
    assert "maConfirm" in delete_all
    assert "قابل بازگشت نیست" in delete_all


def test_the_table_is_refreshed_after_a_bulk_action():
    script = SCRIPT.read_text("utf-8")
    terminate_all = _bulk_definition(script, "ma_terminateAllSessions", "ma_deleteAllSessions")
    delete_all = _bulk_definition(script, "ma_deleteAllSessions", "ma_deleteSession")
    assert "loadSessions()" in terminate_all
    assert "loadSessions()" in delete_all
