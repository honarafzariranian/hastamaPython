"""Server-side silent print for issued queue tickets (kiosk → label printer).

Covers:
  1) ``system_config.label_target_printer`` key seed + upsert write path;
  2) ``POST /api/queue/print`` registration, kiosk guard, and JSON contract;
  3) shared HTML receipt builder + printer resolution (config first, then heuristic);
  4) master-admin persists the chosen printer to the server (not localStorage alone);
  5) ticket-kiosk calls the print endpoint after issue and falls back to window.print().
"""
from __future__ import annotations

from pathlib import Path

import pytest

from app.services import ticket_print

ROOT = Path(__file__).resolve().parents[1]
KIOSK_HTML = (ROOT / "app" / "templates" / "ticket-kiosk.html").read_text(encoding="utf-8")
MASTER_JS = (ROOT / "app" / "static" / "js" / "master-admin.js").read_text(encoding="utf-8")
MASTER_HTML = (ROOT / "app" / "templates" / "master-admin.html").read_text(encoding="utf-8")
MAIN_PY = (ROOT / "app" / "main.py").read_text(encoding="utf-8")
MASTER_ADMIN_SQL = (ROOT / "database" / "master_admin.sql").read_text(encoding="utf-8")
CALL_SYSTEM = (ROOT / "app" / "api" / "routes" / "call_system.py").read_text(encoding="utf-8")


# ── system_config key ─────────────────────────────────────────


def test_label_target_printer_key_is_seeded():
    assert "label_target_printer" in MASTER_ADMIN_SQL
    assert "INSERT INTO dbo.system_config" in MASTER_ADMIN_SQL


def test_config_upsert_and_allowlist():
    assert '"label_target_printer"' in CALL_SYSTEM or "label_target_printer" in (
        ROOT / "app" / "api" / "routes" / "master_admin.py"
    ).read_text(encoding="utf-8")
    ma = (ROOT / "app" / "api" / "routes" / "master_admin.py").read_text(encoding="utf-8")
    # UPSERT: INSERT when the row does not exist yet
    assert "rowcount == 0" in ma
    assert "INSERT INTO system_config" in ma
    assert "allowed_keys" in ma
    assert '"label_target_printer"' in ma


# ── print HTML builder ────────────────────────────────────────


def test_build_ticket_html_escapes_and_includes_fields():
    html_text = ticket_print.build_ticket_html(
        {"number": 12, "persian_number": "۱۲", "service": "پذیرش"},
        {"name": "علی <script>", "admission_number": "A1"},
    )
    assert "۱۲" in html_text
    assert "پذیرش" in html_text
    assert "<script>" not in html_text
    assert "A1" in html_text
    assert 'dir="rtl"' in html_text
    assert "70mm" in html_text and "50mm" in html_text


def test_build_ticket_html_without_optional_patient_fields():
    html_text = ticket_print.build_ticket_html({"number": 1}, {})
    assert "شماره پذیرش" not in html_text
    assert "class=\"name\"" not in html_text


def test_resolve_printer_prefers_explicit_config():
    assert ticket_print.resolve_printer_name("EPSON TM-T88III Receipt") == "EPSON TM-T88III Receipt"


def test_resolve_printer_falls_back_to_label_pick(monkeypatch):
    monkeypatch.setattr(
        "app.services.printer.pick_label_printer",
        lambda *a, **k: {"name": "Zebra ZD220"},
    )
    assert ticket_print.resolve_printer_name("") == "Zebra ZD220"


def test_print_without_printer_returns_fallback():
    result = ticket_print.print_ticket_to_printer({"number": 1}, {}, printer_name="")
    assert result["ok"] is False
    assert result["method"] == "none"
    assert result["message"]


# ── API surface ───────────────────────────────────────────────


def test_print_route_is_on_call_system_router():
    assert '@router.post("/queue/print")' in CALL_SYSTEM
    assert "_guard_kiosk_write(request, \"queue-print\")" in CALL_SYSTEM


def test_print_route_is_csrf_exempt_with_documented_guard():
    # Same kiosk pattern as /api/queue/take: Origin/Referer + rate limit in-handler.
    assert '"/api/queue/print"' in MAIN_PY
    # Extract the CSRF_EXEMPT_PREFIXES tuple body (ends at the closing paren
    # of the assignment, not the first ")" inside a comment).
    start = MAIN_PY.index("CSRF_EXEMPT_PREFIXES")
    end = MAIN_PY.index("CSRF_TOKEN_SESSION_KEY", start)
    block = MAIN_PY[start:end]
    assert '"/api/queue/print"' in block
    assert '"/registration/"' not in block
    assert '"/api/"' not in block  # never exempt the whole API tree


def test_print_uses_system_config_not_local_storage():
    assert "system_config" in CALL_SYSTEM
    assert ticket_print.CONFIG_KEY == "label_target_printer"
    # The endpoint must read the shared key, not a browser-only source.
    assert "CONFIG_KEY" in CALL_SYSTEM


def test_print_runs_in_worker_thread():
    # Spooler / Edge headless must not block the event loop.
    assert "asyncio.to_thread" in CALL_SYSTEM
    assert "print_ticket_to_printer" in CALL_SYSTEM


# ── master-admin client persists the selection ────────────────


def test_label_studio_saves_printer_to_server_config():
    assert "label_target_printer" in MASTER_JS
    assert "api('/config'" in MASTER_JS or 'api("/config"' in MASTER_JS
    # Both the POST-on-select and the load-from-server paths exist.
    assert "loadServerTargetPrinter" in MASTER_JS
    assert "key: 'label_target_printer'" in MASTER_JS


def test_label_studio_still_keeps_local_cache():
    # localStorage remains a fast offline hint; server is the source of truth.
    assert "hastama-label-target-printer" in MASTER_JS


# ── kiosk wires the silent print after issue ──────────────────


def test_kiosk_calls_print_endpoint_after_issue():
    assert "/api/queue/print" in KIOSK_HTML
    assert "printIssuedTicket" in KIOSK_HTML
    # Called only after a successful take (inside issueTicket success path).
    assert "printIssuedTicket(ticket, patientData)" in KIOSK_HTML


def test_kiosk_falls_back_to_browser_print():
    assert "printTicket(ticket, patientData)" in KIOSK_HTML
    # Fallback is inside printIssuedTicket when the server reports failure.
    fn = KIOSK_HTML.split("async function printIssuedTicket", 1)[1].split("function escText", 1)[0]
    assert "printTicket" in fn
    assert "data.success" in fn


def test_kiosk_print_is_non_blocking_for_modal():
    # Modal + queue refresh must not wait on the print round-trip.
    issue = KIOSK_HTML.split("async function issueTicket", 1)[1].split("async function printIssuedTicket", 1)[0]
    assert "printIssuedTicket" in issue
    # printIssuedTicket is fire-and-forget relative to the UI (no await before modal).
    # The call is the last statement of the success path — modal already shown.
    assert issue.rfind("classList.add('visible')") < issue.rfind("printIssuedTicket")
