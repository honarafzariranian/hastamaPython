"""Regression tests: console encoding must never fail a request.

The production process is started by the ``HastamaServer`` scheduled task,
which redirects uvicorn's stdout to ``logs\\hastama-autostart.log``.  When the
stream is a pipe, CPython picks the ANSI code page of the host (cp1252) rather
than UTF-8, so a single ``print()`` of a Persian username raised::

    UnicodeEncodeError: 'charmap' codec can't encode characters ...

from inside the handler and uvicorn answered **500 Internal Server Error**.
``GET /get_hozoor/{username}`` therefore failed for every Persian username
(``آی تی``, ``سالاری``, ...) while ``admin``/``ali`` worked, and the browser
console on /admin/dashboard showed one 500 per user.

Three independent guards are pinned here:

* :func:`app.core.console.enable_utf8_output` reconfigures the real streams;
* ``app/__init__.py`` calls it, so it runs before any module (or loguru sink)
  can write — early enough to survive a redirected stdout;
* :func:`app.core.console.safe_print` degrades to an ASCII-escaped line instead
  of raising, because a diagnostic must not be able to break a request.
"""
from __future__ import annotations

import io
import os
import subprocess
import sys
from pathlib import Path

import pytest
from starlette.testclient import TestClient

ROOT = Path(__file__).resolve().parents[1]

PERSIAN_USERNAME = "آی تی"
PERSIAN_USERNAME_ALT = "جعفري مدير"  # note: Latin 'ي', as stored in the DB


def _cp1252_stream() -> io.TextIOWrapper:
    """A stream that behaves like the console that caused the outage."""
    return io.TextIOWrapper(io.BytesIO(), encoding="cp1252", errors="strict")


# ── the bootstrap ───────────────────────────────────────────────────────────


def test_enable_utf8_output_reconfigures_ansi_streams():
    from app.core.console import enable_utf8_output

    stream = _cp1252_stream()
    assert stream.encoding.lower() == "cp1252"

    enable_utf8_output(streams=[stream])

    assert stream.encoding.lower().replace("-", "") == "utf8"
    assert stream.errors == "replace", (
        "an unencodable character must degrade, not raise: logging is never "
        "allowed to fail the request that is being logged"
    )


def test_enable_utf8_output_makes_persian_writable():
    from app.core.console import enable_utf8_output

    stream = _cp1252_stream()
    with pytest.raises(UnicodeEncodeError):
        stream.write(PERSIAN_USERNAME)

    enable_utf8_output(streams=[stream])
    stream.write(PERSIAN_USERNAME)
    stream.flush()

    assert stream.buffer.getvalue().decode("utf-8") == PERSIAN_USERNAME


def test_enable_utf8_output_tolerates_unreconfigurable_streams():
    """pytest capture objects, closed files and byte pipes must not crash."""
    from app.core.console import enable_utf8_output

    class NotATextStream:
        def write(self, _text):
            return 0

    enable_utf8_output(streams=[NotATextStream(), object()])  # no raise


def test_package_import_configures_utf8_console():
    """``import app`` — the first thing uvicorn does — must fix the streams.

    Run in a subprocess with ``PYTHONIOENCODING=cp1252`` and ``PYTHONUTF8``
    removed, which is exactly what the scheduled task produced.  Before the
    fix this exited with UnicodeEncodeError; the encoding it reports proves
    the bootstrap, not luck, is responsible.
    """
    env = dict(os.environ)
    env["PYTHONIOENCODING"] = "cp1252"
    env.pop("PYTHONUTF8", None)
    script = (
        "import app, sys;"
        "print(sys.stdout.encoding, sys.stdout.errors);"
        f"print({PERSIAN_USERNAME!r})"
    )
    proc = subprocess.run(
        [sys.executable, "-c", script],
        cwd=str(ROOT),
        env=env,
        capture_output=True,
    )
    assert proc.returncode == 0, (
        f"importing app must neutralise a cp1252 stdout; "
        f"stderr={proc.stderr.decode('utf-8', 'replace')}"
    )
    assert b"UnicodeEncodeError" not in proc.stderr
    out = proc.stdout.decode("utf-8")
    assert out.splitlines()[0].split() == ["utf-8", "replace"]
    assert PERSIAN_USERNAME in out, "the username must survive the round-trip"


def test_launcher_forces_utf8_for_the_scheduled_task():
    """Belt and braces: the interpreter is told about UTF-8 before it starts."""
    launcher = (ROOT / "scripts" / "راه‌اندازی_سرور_تولید.bat").read_text(encoding="utf-8")
    assert "set PYTHONUTF8=1" in launcher
    assert "set PYTHONIOENCODING=utf-8" in launcher


# ── safe_print ──────────────────────────────────────────────────────────────


def test_safe_print_escapes_instead_of_raising(capsys):
    from app.core.console import safe_print

    stream = _cp1252_stream()
    safe_print(f"user={PERSIAN_USERNAME}", file=stream)  # must not raise
    stream.flush()

    written = stream.buffer.getvalue().decode("ascii")
    assert "user=" in written
    assert all(ord(ch) < 128 for ch in written), "fallback output must be ASCII"


def test_safe_print_never_propagates_stream_failures():
    from app.core.console import safe_print

    class BrokenStream:
        def write(self, _text):
            raise RuntimeError("disk full")

        def flush(self):
            raise RuntimeError("disk full")

    safe_print("anything", file=BrokenStream())  # diagnostics stay best-effort


def test_safe_print_keeps_normal_output_unchanged():
    from app.core.console import safe_print

    stream = io.StringIO()
    safe_print("plain", 42, file=stream, end="\n")
    assert stream.getvalue() == "plain 42\n"


# ── the endpoint that was returning 500 ─────────────────────────────────────


def _admin_bypassed(monkeypatch):
    """Authorisation is not what this test is about; the print is."""
    import app.main as main_mod

    monkeypatch.setattr(main_mod, "_require_admin", lambda _request: None)
    return main_mod


def test_get_hozoor_persian_username_survives_cp1252_stdout(monkeypatch):
    """End-to-end reproduction of the reported 500.

    The fake pyodbc in ``tests/conftest.py`` answers the ``user_table`` lookup
    with no row, so the handler stops at 404 *after* having printed the
    username — which is precisely the line that used to explode.
    """
    main_mod = _admin_bypassed(monkeypatch)
    monkeypatch.setattr(sys, "stdout", _cp1252_stream())

    client = TestClient(main_mod.app)
    response = client.get(
        f"/get_hozoor/{PERSIAN_USERNAME}",
        params={"start_date": "1405/01/01", "end_date": "1405/01/31"},
    )

    assert response.status_code != 500, response.text
    assert response.status_code in (404, 200)


def test_get_hozoor_all_reported_usernames_are_not_500(monkeypatch):
    """Every name from the dashboard console log, incl. Latin-'ي' variants."""
    main_mod = _admin_bypassed(monkeypatch)
    monkeypatch.setattr(sys, "stdout", _cp1252_stream())

    client = TestClient(main_mod.app)
    for username in [
        "آی تی",
        "سالاری    ",
        "حبیبی     ",
        "تست       ",
        "م.براتی   ",
        "گنجمه     ",
        "سقایی     ",
        "محمودی",
        "جعفری     ",
        "حلاجی     ",
        PERSIAN_USERNAME_ALT,
    ]:
        response = client.get(
            f"/get_hozoor/{username}",
            params={"start_date": "1405/01/01", "end_date": "1405/01/31"},
        )
        assert response.status_code != 500, f"{username!r} -> {response.status_code}"


def test_get_hozoor_diagnostics_cannot_raise():
    """The handler must log through safe_print, not bare print()."""
    import app.main as main_mod

    source = Path(main_mod.__file__).read_text(encoding="utf-8")
    start = source.index('@app.get("/get_hozoor/{username}")')
    handler = source[start : source.index('@app.post("/sabt_hozoor")', start)]

    assert "safe_print(" in handler, "get_hozoor must use the crash-proof helper"
    for line in handler.splitlines():
        stripped = line.strip()
        if stripped.startswith("print("):
            pytest.fail(f"bare print() in get_hozoor can 500 the request: {stripped}")
