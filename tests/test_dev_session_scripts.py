"""نشست کار روی کد: فقط یک نمونه، و برگرداندن استارت خودکار.

زمینهٔ نیاز (۱۴۰۵/۰۷/۰۶ خورشیدی):
  قرارداد تولید در `tests/test_production_supervision.py` تثبیت شده است: فقط
  `\\HastamaServer` -> `scripts\\run_server.bat` می‌تواند 127.0.0.1:5000 را در
  اختیار بگیرد و نگهبان هر نمونهٔ غیرنظارت‌شده‌ای را برمی‌گرداند.  اما وقتی روی
  خود کد کار می‌کنیم به‌طور موقت به عکس این نیاز داریم:

    * هیچ نمونه‌ای از سامانه روی هیچ پورتی باقی نماند؛
    * نمونه‌ای که خودمان اجرا می‌کنیم تنها نمونهٔ در حال اجرا باشد؛
    * استارت خودکار (تسک‌ها) وسط کار، نسخهٔ تولیدی را برنگرداند - چون قرار است
      استارت خودکار فقط به‌هنگام بوت شدن سرور معنی داشته باشد؛
    * و در پایان، همه‌چیز به حالت عادی برگردد.

  پس فایل‌های زیر اضافه شدند (تعریف تسک‌ها، `run_server.bat` و
  `watchdog_server.ps1` دست‌نخورده می‌مانند؛ فقط وضعیت enabled/disabled تسک‌ها
  برای مدت نشست تغییر می‌کند):

    run_hastama_dev.bat        نقطهٔ ورود یک‌کلیکی (ریشهٔ مخزن)
    scripts\\dev_session.ps1    مغز کار: بستن نمونه‌ها، پارک/برگرداندن تسک‌ها
    scripts\\stop_all_hastama.bat
    scripts\\dev-logging.json  لاگ UTF-8 روی کنسول + فایل کراندار

  این تست‌ها همان قراردادها را قفل می‌کنند، به‌ویژه این‌که کشتن فرایندها
  «هویتی» است و هرگز کورکورانه بر اساس شمارهٔ پورت انجام نمی‌شود.
"""
from __future__ import annotations

from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def _read(*parts: str) -> str:
    text = ROOT.joinpath(*parts).read_text(encoding="utf-8")
    return text.replace("\r\n", "\n")


SESSION_PS1 = _read("scripts", "dev_session.ps1")
LAUNCHER = _read("run_hastama_dev.bat")
STOP_ALL = _read("scripts", "stop_all_hastama.bat")
LOGGING = _read("scripts", "dev-logging.json")
DEPLOYMENT_DOC = _read("docs", "HASTAMA_PRODUCTION_DEPLOYMENT.md")
NEW_FILES = {
    "run_hastama_dev.bat": LAUNCHER,
    "scripts/dev_session.ps1": SESSION_PS1,
    "scripts/stop_all_hastama.bat": STOP_ALL,
    "scripts/dev-logging.json": LOGGING,
}

# Keep in sync with scripts\watchdog_server.ps1 and scripts\stop_server.ps1.
APPLICATION_SIGNATURE = r"(?i)(-m\s+uvicorn\s+app\.main:app|uvicorn(\.exe)?\s+app\.main:app)"


# ── یک نمونه، و فقط نمونه‌ای که خودمان اجرا می‌کنیم ───────────────────────────


def test_launcher_closes_every_instance_before_starting_its_own():
    stop = LAUNCHER.index("-Action stop-all")
    run = LAUNCHER.index("-m uvicorn app.main:app")
    assert stop < run, "همهٔ نمونه‌ها باید قبل از اجرای نمونهٔ جدید بسته شوند"


def test_launcher_aborts_when_a_foreign_process_owns_the_port():
    assert "if errorlevel 2" in LAUNCHER, "stop-all کد ۲ را برای مالک بیگانه برمی‌گرداند"
    assert "[ABORT]" in LAUNCHER


def test_the_session_script_uses_the_same_application_signature():
    assert APPLICATION_SIGNATURE in SESSION_PS1, "امضای برنامه باید همان امضای نگهبان باشد"
    assert "Test-IsHastamaApp([string]$CommandLine)" in SESSION_PS1


def test_killing_is_identity_based_and_never_blind():
    assert "taskkill /F /T /PID $ProcessId" in SESSION_PS1, "درخت فرایند باید بسته شود"
    assert "/IM python.exe" not in SESSION_PS1, "نباید همهٔ python.exe ها کشته شوند"
    assert "WINDOWTITLE" not in SESSION_PS1


def test_killing_is_not_limited_to_the_production_port():
    """خواستهٔ صریح: هیچ نمونه‌ای روی هیچ پورتی باقی نماند."""
    signature_check = SESSION_PS1[SESSION_PS1.index("function Test-IsHastamaApp") :]
    signature_check = signature_check[: signature_check.index("}")]
    assert "--port" not in signature_check, "تشخیص هویت نباید به پورت گره بخورد"

    scanner = SESSION_PS1[SESSION_PS1.index("function Get-HastamaProcesses") :]
    scanner = scanner[: scanner.index("\nfunction ", 1)]
    assert "Get-CimInstance Win32_Process" in scanner
    assert "--port" not in scanner


def test_foreign_owners_of_a_port_are_reported_and_never_stopped():
    plan = SESSION_PS1[SESSION_PS1.index("function Get-StopPlan") :]
    plan = plan[: plan.index("\nfunction ", 1)]
    assert "if ($verdict.Kind -eq 'foreign') { continue }" in plan

    stop_all = SESSION_PS1[SESSION_PS1.index("function Invoke-StopAll") :]
    stop_all = stop_all[: stop_all.index("\nfunction ", 1)]
    assert "if ($verdict.Kind -ne 'foreign') { continue }" in stop_all
    assert "FOREIGN_OWNER left untouched" in stop_all
    assert "return 2" in stop_all, "پورتِ در اختیار دیگری باید اجرا را متوقف کند"


def test_orphaned_reload_workers_are_recognised_through_pyvenv_cfg():
    # این venv توسط uv ساخته شده و .venv\\Scripts\\python.exe یک shim است؛
    # فرزند ری‌لود با مسیر مفسرِ پایه اجرا می‌شود، نه با مسیر venv.
    assert "pyvenv.cfg" in SESSION_PS1
    assert "multiprocessing" in SESSION_PS1
    assert "function Test-IsOurReloadOrphan" in SESSION_PS1


# ── استارت خودکار: پارک موقت و برگرداندن ────────────────────────────────────


def test_the_session_parks_both_tasks_and_remembers_their_previous_state():
    assert "tasks_before" in SESSION_PS1
    assert "$state.tasks_before = @{" in SESSION_PS1
    hold = SESSION_PS1[SESSION_PS1.index("function Invoke-HoldTasks") :]
    assert "Set-TaskDisabled $task $true" in hold, "هر دو تسک باید غیرفعال شوند"
    for task in ("'HastamaServer'", "'HastamaWatchdog'"):
        assert task in SESSION_PS1
    assert "Set-TaskDisabled $task $false" in SESSION_PS1, "برگرداندن تسک‌ها"


def test_production_comes_back_only_if_it_was_serving_before():
    restore = SESSION_PS1[SESSION_PS1.index("function Invoke-Restore") :]
    assert "production_was_serving" in restore
    assert restore.index("if ($state.production_was_serving)") < restore.index(
        "& schtasks /Run /TN $ProductionTask"
    ), "شروع دوبارهٔ تولید باید مشروط به همان وضعیت قبلی باشد"


def test_the_session_waits_for_the_console_and_restores_on_exit():
    hold = SESSION_PS1[SESSION_PS1.index("function Invoke-HoldTasks") :]
    hold = hold[: hold.index("\nfunction ", 1)]
    assert "Get-Process -Id $WatchPid" in hold
    assert "Invoke-Restore" in hold, "خروج کنسول باید تسک‌ها را برگرداند"
    assert "-Action hold-tasks -WatchPid %SESSION_PID%" in LAUNCHER
    assert "-Action await-restore" in LAUNCHER
    assert "-Action restore" in LAUNCHER


def test_a_killed_session_is_repaired_before_taking_over_again():
    assert "function Repair-StaleSession" in SESSION_PS1
    assert "STALE_SESSION" in SESSION_PS1
    hold = SESSION_PS1[SESSION_PS1.index("function Invoke-HoldTasks") :]
    hold = hold[: hold.index("\nfunction ", 1)]
    assert "Repair-StaleSession" in hold
    assert "restored_at" in SESSION_PS1


def test_elevation_is_asked_for_once_and_only_for_the_task_bookkeeping():
    assert SESSION_PS1.count("Verb         = 'RunAs'") == 1, (
        "درخواست UAC باید فقط در یک نقطه باشد (Start-ElevatedSelf)"
    )
    assert "-m uvicorn" not in SESSION_PS1, (
        "خود برنامه نباید از اسکریپت مدیریتی و به‌صورت ادمین اجرا شود"
    )
    assert "ELEVATION_REFUSED" in SESSION_PS1, "رد شدن UAC باید گزارش شود"
    assert "-Action await-disable" in LAUNCHER, "پیش از اجرا، پارک شدن تسک‌ها تأیید شود"


def test_the_previous_session_state_is_not_restored_without_evidence():
    """اگر وضعیت قبلی ناشناخته باشد، تسک نباید کورکورانه فعال یا غیرفعال شود."""
    restore = SESSION_PS1[SESSION_PS1.index("function Invoke-Restore") :]
    restore = restore[: restore.index("\nfunction ", 1)]
    assert "$enable = ($before -ne 'Disabled')" in restore
    assert "if ($enable) { [void](Set-TaskDisabled $task $false) }" in restore


# ── همان قواعد تولید، بدون دست‌زدن به آن ─────────────────────────────────────


def test_the_session_keeps_the_production_bind_and_environment():
    for fragment in (
        "--host 127.0.0.1",
        "--port 5000",
        "--proxy-headers",
        "--forwarded-allow-ips 127.0.0.1",
        "set PYTHONUTF8=1",
        "set PYTHONIOENCODING=utf-8",
    ):
        assert fragment in LAUNCHER, f"{fragment} باید در نشست کار هم باشد"


def test_nothing_new_leaves_loopback_or_touches_the_tunnel_and_the_database():
    for name, text in NEW_FILES.items():
        assert "0.0.0.0" not in text, f"{name}: باید فقط روی لوپ‌بک گوش بدهد"
        for forbidden in ("cloudflared", "sqlcmd", "MSSQL$SQLEXPRESS"):
            assert forbidden not in text, f"{name}: نباید {forbidden} را دست بزند"


def test_the_new_scripts_never_rewrite_the_production_start_paths():
    for token in ("Set-Content", "Out-File", "Set-ItemProperty"):
        assert token not in SESSION_PS1, f"{token} نباید در اسکریپت نشست باشد"
    # Remove-Item فقط برای چرخش لاگ خودِ نشست است، نه برای هیچ فایل دیگری.
    assert SESSION_PS1.count("Remove-Item") == SESSION_PS1.count('Remove-Item "$SessionLog.1"'), (
        "تنها فایلی که این اسکریپت حذف می‌کند لاگ خودش است"
    )
    # تنها اشاره به run_server.bat برای «شناسایی» است، نه اجرا.
    assert r"run_server\.bat" in SESSION_PS1
    # اشاره به نگهبان فقط در توضیحات مجاز است (امضای مشترک)، نه در کد اجرایی.
    for line in SESSION_PS1.splitlines():
        if "watchdog_server.ps1" in line:
            assert line.strip().startswith("#"), (
                f"ارتباط با watchdog_server.ps1 باید در توضیحات باشد، نه در کد: {line.strip()}"
            )


def test_the_session_script_stays_ascii_for_windows_powershell():
    """فایل بدون BOM را Windows PowerShell 5.1 با ANSI می‌خواند؛ پس متن باید ASCII بماند."""
    raw = (ROOT / "scripts" / "dev_session.ps1").read_bytes()
    assert all(byte < 128 for byte in raw), "کاراکتر غیر ASCII در dev_session.ps1"
    assert not raw.startswith(b"\xef\xbb\xbf"), "به BOM نیاز نیست اگر فایل ASCII باشد"


def test_the_session_log_is_bounded():
    assert "SessionLogMaxBytes" in SESSION_PS1
    assert "$SessionLogMaxBytes = 5242880" in SESSION_PS1
    assert "Move-Item -Path $SessionLog -Destination \"$SessionLog.1\"" in SESSION_PS1


def test_the_development_log_is_utf8_and_bounded():
    assert '"filename": "logs/hastama-dev.log"' in LOGGING
    assert '"encoding": "utf-8"' in LOGGING
    assert '"maxBytes": 52428800' in LOGGING
    assert '"backupCount": 1' in LOGGING
    assert "--log-config scripts\\dev-logging.json" in LAUNCHER


def test_the_manual_stop_tool_starts_nothing():
    assert "-Action stop-all" in STOP_ALL
    assert "uvicorn" not in STOP_ALL
    assert "start_server.bat" in STOP_ALL, "کاربر باید بداند چطور تولید را برگرداند"


def test_documentation_describes_the_development_session():
    assert "run_hastama_dev.bat" in DEPLOYMENT_DOC
    assert "run_hastama_dev.bat --check" in DEPLOYMENT_DOC
    assert "stop_all_hastama.bat" in DEPLOYMENT_DOC
    assert "Park" in DEPLOYMENT_DOC or "park" in DEPLOYMENT_DOC
