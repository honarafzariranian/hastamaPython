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
    assert '"label_print_settings"' in ma


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
    # Label studio physical size (master-admin DEFAULT_LABEL_W/H).
    assert "75mm" in html_text and "81mm" in html_text
    # Shared presentation layer from /master-admin/label-printer.
    assert "lbl__queue-number" in html_text
    assert "lbl__records" in html_text
    assert "label-print" in html_text or ".lbl {" in html_text


def test_build_ticket_html_without_optional_patient_fields():
    html_text = ticket_print.build_ticket_html(
        {"number": 1, "service": "اصلاح پذیرش"}, {}
    )
    # Full template still renders (— placeholders), but no raw empty name row value.
    assert "نام و نام خانوادگی" in html_text
    assert "شماره ملی" in html_text


def test_build_ticket_html_minimal_services_show_only_number_and_admission():
    for service in ("جوابدهی", "نمونه‌گیری", "نوبت خالی"):
        html_text = ticket_print.build_ticket_html(
            {"number": 9, "persian_number": "۹", "service": service},
            {"admission_number": "42", "name": "SECRET", "phone": "0912"},
        )
        assert "۹" in html_text
        assert "42" in html_text or "۴۲" in html_text
        assert "SECRET" not in html_text
        assert "0912" not in html_text
        assert "شماره ملی" not in html_text
        assert "بیمه پایه" not in html_text


def test_build_ticket_html_full_services_include_patient_block():
    for service in ("پذیرش", "اصلاح پذیرش", "تست آزاد"):
        html_text = ticket_print.build_ticket_html(
            {"number": 3, "service": service},
            {"name": "تقی", "national_id": "0890519684", "insurance_base": "تأمین اجتماعی"},
        )
        assert "نام و نام خانوادگی" in html_text
        assert "تقی" in html_text
        assert "شماره ملی" in html_text
        assert "بیمه پایه" in html_text


def test_is_minimal_label_service_normalizes_zwnj():
    assert ticket_print.is_minimal_label_service("نمونه‌گیری")
    assert ticket_print.is_minimal_label_service("نمونه گیری")
    assert not ticket_print.is_minimal_label_service("اصلاح پذیرش")
    assert not ticket_print.is_minimal_label_service("javabdehiX")


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


def test_kiosk_has_blank_ticket_button_without_form():
    assert "issueBlankTicket" in KIOSK_HTML
    assert 'data-service="نوبت خالی"' in KIOSK_HTML
    # Issues immediately with empty patient — no overlay/form path.
    start = KIOSK_HTML.index("function issueBlankTicket")
    end = KIOSK_HTML.index("/* ── متن مودال", start)
    fn = KIOSK_HTML[start:end]
    assert "issueTicket('نوبت خالی', {})" in fn
    assert "showPatientForm" not in fn
    assert "patientOverlay" not in fn


def test_kiosk_browser_fallback_uses_label_template():
    assert "label-print.css" in KIOSK_HTML
    assert "lbl__queue-number" in KIOSK_HTML
    assert "isMinimalLabelService" in KIOSK_HTML


def test_kiosk_falls_back_to_browser_print():
    assert "printTicket(ticket, patientData, labelSettings)" in KIOSK_HTML
    # Fallback is inside printIssuedTicket when the server reports failure.
    fn = KIOSK_HTML.split("async function printIssuedTicket", 1)[1].split("function escText", 1)[0]
    assert "printTicket" in fn
    assert "data.success" in fn
    # Settings from the server response feed the browser fallback.
    assert "data.settings" in fn
    assert "normalizeLabelSettings" in KIOSK_HTML


def test_kiosk_print_is_non_blocking_for_modal():
    # Modal + queue refresh must not wait on the print round-trip.
    issue = KIOSK_HTML.split("async function issueTicket", 1)[1].split("async function printIssuedTicket", 1)[0]
    assert "printIssuedTicket" in issue
    # printIssuedTicket is fire-and-forget relative to the UI (no await before modal).
    # The call is the last statement of the success path — modal already shown.
    assert issue.rfind("classList.add('visible')") < issue.rfind("printIssuedTicket")


# ── PowerShell quoting / encoding (Windows silent print path) ──────────


def test_ps_quote_escapes_single_quotes():
    assert ticket_print._ps_quote("C:\\a b\\x.pdf") == "'C:\\a b\\x.pdf'"
    assert ticket_print._ps_quote("it's") == "'it''s'"


def test_print_pdf_embeds_paths_in_command_not_argv():
    import inspect

    src = inspect.getsource(ticket_print._print_pdf_windows)
    assert "_ps_quote(pdf_path)" in src
    assert "_ps_quote(printer_name)" in src
    # Bare $args + extra argv breaks on spaces / TEMP short names.
    assert "$args" not in src


def test_print_text_windows_forces_utf8_console():
    import inspect

    src = inspect.getsource(ticket_print._print_text_windows)
    assert "UTF8" in src or "utf-8" in src
    assert "_ps_quote(printer_name)" in src


def test_print_pdf_windows_reports_printto_failure():
    """PrintTo failure must not be reported as success (missing .pdf association)."""
    import inspect

    src = inspect.getsource(ticket_print._print_pdf_windows)
    # Old bug: Start-Process ...; exit 0 always returned rc=0 even on throw.
    assert "ErrorActionPreference" in src
    assert "exit 1" in src
    assert "try" in src and "catch" in src


def test_print_ticket_falls_back_to_out_printer_when_image_print_fails(monkeypatch):
    monkeypatch.setattr(ticket_print, "_render_png_with_edge", lambda *a, **k: False)
    monkeypatch.setattr(ticket_print, "_render_pdf_with_edge", lambda *a, **k: False)
    monkeypatch.setattr(ticket_print, "_print_pdf_windows", lambda *a, **k: False)
    called = {}

    def fake_text(text, printer_name):
        called["text"] = text
        called["printer"] = printer_name
        return True

    monkeypatch.setattr(ticket_print, "_print_text_windows", fake_text)
    result = ticket_print.print_ticket_to_printer(
        {"service": "javabdehi", "number": 7, "persian_number": "7"},
        {"name": "TEST", "admission_number": "A9"},
        "EPSON TM-T88III Receipt",
    )
    assert result["ok"] is True
    assert result["method"] == "out-printer"
    assert called["printer"] == "EPSON TM-T88III Receipt"
    assert "شماره نوبت" in called["text"]
    assert "A9" in called["text"]


def test_print_ticket_prefers_edge_png_when_available(monkeypatch):
    monkeypatch.setattr(ticket_print, "_render_png_with_edge", lambda *a, **k: True)
    monkeypatch.setattr(ticket_print, "_print_png_windows", lambda *a, **k: True)
    result = ticket_print.print_ticket_to_printer(
        {"service": "پذیرش", "number": 1, "persian_number": "۱"},
        {"name": "X"},
        "EPSON TM-T88III Receipt",
    )
    assert result["ok"] is True
    assert result["method"] == "edge-png"


def test_print_png_windows_quotes_paths():
    import inspect

    src = inspect.getsource(ticket_print._print_png_windows)
    assert "_ps_quote(printer_name)" in src
    assert "_ps_quote(png_path)" in src


def test_build_ticket_html_inlines_label_css_and_logos():
    html_text = ticket_print.build_ticket_html(
        {"number": 1, "service": "اصلاح پذیرش"}, {"name": "a"}
    )
    assert "lbl__header" in html_text
    assert "lbl__footer" in html_text
    assert "lab-logo" in html_text
    assert "newlogo" in html_text
    assert "Vazir" in html_text


# ── shared print settings (studio ↔ kiosk) ────────────────────


def test_settings_config_key_and_normalize():
    assert ticket_print.SETTINGS_CONFIG_KEY == "label_print_settings"
    # Studio localStorage payload shape
    cfg = ticket_print.normalize_label_settings({
        "maLabelWidth": 70,
        "maLabelHeight": 60,
        "maLabelTemplate": "blank",
        "maPrintRotate": True,
        "maShowName": False,
        "maShowTime": True,
        "maShowHint": False,
    })
    assert cfg["width_mm"] == 70
    assert cfg["height_mm"] == 60
    assert cfg["template"] == "blank"
    assert cfg["rotate"] is True
    assert cfg["show_name"] is False
    assert cfg["show_hint"] is False
    # JSON string from system_config
    cfg2 = ticket_print.normalize_label_settings('{"width_mm": 90, "template": "result"}')
    assert cfg2["width_mm"] == 90
    assert cfg2["template"] == "result"
    # Invalid / partial → defaults
    assert ticket_print.normalize_label_settings(None)["width_mm"] == 75
    assert ticket_print.normalize_label_settings({"template": "nope"})["template"] == "queue"
    assert ticket_print.normalize_label_settings({"width_mm": 999})["width_mm"] == 150


def test_build_ticket_html_applies_studio_settings():
    html_text = ticket_print.build_ticket_html(
        {"number": 5, "service": "پذیرش"},
        {"name": "Hidden", "admission_number": "A1"},
        settings={
            "width_mm": 70,
            "height_mm": 50,
            "template": "compact",
            "rotate": True,
            "show_name": False,
            "show_time": False,
            "show_hint": False,
        },
    )
    # Rotated physical page swaps axes (studio openPrintWindow).
    assert "@page { size: 50mm 70mm; margin: 0; }" in html_text
    assert "is-rotated" in html_text
    assert "rotate(-90deg)" in html_text
    assert 'data-template="compact"' in html_text
    assert "--lbl-mm-w: 70" in html_text
    assert "--lbl-mm-h: 50" in html_text
    # Visibility toggles
    assert "Hidden" not in html_text
    assert "lbl__datetime-value" in html_text
    assert 'title="تاریخ و ساعت ثبت نوبت" hidden' in html_text
    assert 'class="lbl__message" hidden' in html_text


def test_build_ticket_html_explicit_size_overrides_settings():
    html_text = ticket_print.build_ticket_html(
        {"number": 1, "service": "پذیرش"},
        {},
        width_mm=80,
        height_mm=90,
        settings={"width_mm": 40, "height_mm": 40},
    )
    assert "80mm" in html_text
    assert "90mm" in html_text


def test_print_ticket_passes_settings_sizes_to_png_path(monkeypatch):
    captured = {}

    def fake_render(html_text, png_path, width_mm=75, height_mm=81, **kwargs):
        captured["w"] = width_mm
        captured["h"] = height_mm
        return True

    monkeypatch.setattr(ticket_print, "_render_png_with_edge", fake_render)
    monkeypatch.setattr(ticket_print, "_print_png_windows", lambda *a, **k: True)
    result = ticket_print.print_ticket_to_printer(
        {"service": "پذیرش", "number": 1, "persian_number": "۱"},
        {"name": "X"},
        "EPSON TM-T88III Receipt",
        settings={"width_mm": 70, "height_mm": 55, "rotate": True},
    )
    assert result["ok"] is True
    # Rotated page swaps axes for the screenshot / paper size.
    assert captured["w"] == 55
    assert captured["h"] == 70
    assert result["settings"]["width_mm"] == 70
    assert result["settings"]["template"] == "queue"


def test_print_without_printer_still_returns_normalized_settings():
    result = ticket_print.print_ticket_to_printer(
        {"number": 1}, {}, printer_name="", settings={"maLabelWidth": 66}
    )
    assert result["ok"] is False
    assert result["settings"]["width_mm"] == 66


def test_print_endpoint_reads_and_returns_label_settings():
    assert "SETTINGS_CONFIG_KEY" in CALL_SYSTEM
    assert "normalize_label_settings" in CALL_SYSTEM
    assert "default_label_settings" in CALL_SYSTEM
    assert '"settings"' in CALL_SYSTEM


def test_studio_pushes_print_settings_to_server():
    assert "label_print_settings" in MASTER_JS
    assert "pushLabelSettingsToServer" in MASTER_JS
    assert "loadServerLabelSettings" in MASTER_JS
    assert "key: 'label_print_settings'" in MASTER_JS
