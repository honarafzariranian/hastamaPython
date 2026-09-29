"""Only the supervised production process may own ``127.0.0.1:5000``.

The deployment contract::

    \\HastamaServer (scheduled task, at boot)
        -> cmd.exe /c scripts\\راه‌اندازی_سرور_تولید.bat
        -> .venv\\Scripts\\python.exe -m uvicorn app.main:app
             --host 127.0.0.1 --port 5000
             --proxy-headers --forwarded-allow-ips 127.0.0.1
        -> 127.0.0.1:5000

A process *listening* on the port is not evidence of health.  On 2026-09-28 an
unsupervised ``uvicorn app.main:app`` started from a VS Code terminal owned the
socket; the port-only watchdog reported that as healthy, the instance ran without
the production flags and without ``PYTHONUTF8``/``PYTHONIOENCODING``, and it died
with its terminal (public ``502`` until the port was reclaimed).  These tests pin
the parts of the fix that live in the repository:

* the watchdog identifies the owning process before trusting it, probes
  ``GET /health`` (never the TCP port alone) and never kills a foreign owner;
* repeated health failures - not a single one - trigger a restart, and restarts
  are capped per rolling window;
* the production start path is the task, with a separate development launcher
  that cannot touch the production port;
* the stop helper and the autostart toggles agree with the watchdog.

Live recovery runs (kill the instance -> watchdog restarts it; manual uvicorn ->
watchdog reclaims the port) need Task Scheduler and the real server, so they are
recorded in ``docs/network/UNIFIED_URL_ARCHITECTURE.md`` §5 rather than here.
"""
from __future__ import annotations

import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def _read(*parts: str) -> str:
    return ROOT.joinpath(*parts).read_text(encoding="utf-8")


WATCHDOG = _read("scripts", "watchdog_server.ps1")
STOP_PS1 = _read("scripts", "stop_server.ps1")
STOP_BAT = _read("scripts", "توقف_سرور.bat")
START_BAT = _read("scripts", "شروع_سرور.bat")
RUN_SERVER = _read("scripts", "راه‌اندازی_سرور_تولید.bat")
RUN_DEV = _read("scripts", "راه‌اندازی_سرور_توسعه.bat")
DISABLE_AUTOSTART = _read("scripts", "غیرفعال‌سازی_اجرای_خودکار.bat")
ENABLE_AUTOSTART = _read("scripts", "فعال‌سازی_اجرای_خودکار.bat")
INSTALL_WATCHDOG = _read("scripts", "install_watchdog.ps1")
RISK_REGISTER = _read("docs", "security", "RESIDUAL_RISK_REGISTER.md")
DEPLOYMENT_DOC = _read("docs", "HASTAMA_PRODUCTION_DEPLOYMENT.md")
NETWORK_DOC = _read("docs", "network", "UNIFIED_URL_ARCHITECTURE.md")

# The application signature must agree between the watchdog and the stop helper;
# a divergence would let one script trust a process the other refuses to touch.
SIGNATURE_PATTERN = re.compile(r"\(\?i\)\(-m[^\"]*\)")


def _signatures() -> list[str]:
    return SIGNATURE_PATTERN.findall(WATCHDOG) + SIGNATURE_PATTERN.findall(STOP_PS1)


# ── layered health model ────────────────────────────────────────────────────


def test_watchdog_does_not_treat_a_listening_port_as_health():
    assert "Get-NetTCPConnection" in WATCHDOG, "the listener must still be located"
    assert "/health" in WATCHDOG, "health must be probed over HTTP"
    assert "HttpClient" in WATCHDOG
    # The old implementation returned a verdict from the TCP probe alone.
    assert "Test-LoopbackListener" not in WATCHDOG


def test_watchdog_health_probe_ignores_the_machine_proxy():
    # The host has a local HTTP proxy configured for other tools; a proxied
    # loopback request must never decide the verdict.
    assert "Proxy = $null" in WATCHDOG


def test_watchdog_health_probe_uses_a_type_available_in_windows_powershell():
    # System.Net.Http is not loaded in Windows PowerShell 5.1: the first version
    # of this probe threw "Cannot find type HttpClientHandler" and reported a
    # perfectly healthy application as unhealthy.
    assert "New-Object System.Net.Http" not in WATCHDOG
    assert "[System.Net.WebRequest]::Create" in WATCHDOG


def test_a_broken_probe_can_never_restart_the_application():
    assert "PROBE_ERROR" in WATCHDOG
    assert re.search(r'if \(\$health\.Kind -eq "PROBE_ERROR"\)', WATCHDOG)
    # the probe-error branch must come before the unhealthy branch
    probe_error = WATCHDOG.index('if ($health.Kind -eq "PROBE_ERROR")')
    unhealthy = WATCHDOG.index('$state.unhealthy_streak += 1')
    assert probe_error < unhealthy


def test_watchdog_identifies_the_application_by_command_line():
    assert "Win32_Process" in WATCHDOG
    signature = _signatures()
    assert signature, "the application signature is missing"
    assert all("app" in item and "uvicorn" in item for item in signature)


def test_watchdog_requires_the_production_flags():
    for flag in ("--proxy-headers", "--forwarded-allow-ips", "--host", "--port"):
        assert flag in WATCHDOG, f"{flag} must be part of the identity check"


def test_watchdog_accepts_supervision_or_production_log_evidence():
    # The launchers are named in Persian, so the watchdog matches the scheduled
    # task's `cmd.exe /c ...\scripts\<launcher>.bat` wrapper generically and must
    # never hardcode one file name.
    assert r"[\\/]scripts[\\/]" in WATCHDOG, (
        "supervision is identified via the launcher path under the scripts folder"
    )
    assert r"\.bat" in WATCHDOG, "the launcher wrapper must still be matched"
    assert "run_server.bat" not in WATCHDOG, "the launcher name must not be hardcoded"
    assert "hastama-autostart.log" in WATCHDOG, (
        "an orphaned production process is recognised by writing the production log"
    )


def test_an_uninspectable_listener_is_not_misreported_as_foreign():
    # If WMI cannot describe the owning process, say so instead of inventing a
    # diagnosis, and touch nothing.
    assert "PROCESS_LOOKUP_FAILED" in WATCHDOG
    lookup = WATCHDOG.index("if (-not $listener)")
    foreign = WATCHDOG.index('Write-Log "PORT_FOREIGN_OWNER"')
    assert lookup < foreign, "the lookup guard must run before the foreign-owner branch"


def test_watchdog_never_kills_a_foreign_owner():
    assert "PORT_FOREIGN_OWNER" in WATCHDOG
    # Every stop is gated by the application signature for this port.
    assert re.search(r"Where-Object \{ Test-IsHastamaApp \$_\.CommandLine \$Port \}", WATCHDOG), (
        "Stop-HastamaApplicationProcesses must re-check the signature per process"
    )


def test_watchdog_restarts_only_after_repeated_health_failures():
    assert "UnhealthyThreshold" in WATCHDOG
    assert re.search(r"unhealthy_streak -ge \$UnhealthyThreshold", WATCHDOG)
    default = re.search(r"\[int\]\$UnhealthyThreshold = (\d+)", WATCHDOG)
    assert default and int(default.group(1)) >= 2, "one transient failure must not restart"


def test_watchdog_caps_restart_frequency():
    assert "MaxRestartsPerWindow" in WATCHDOG
    assert "RestartWindowMinutes" in WATCHDOG
    assert "RESTART_SUPPRESSED" in WATCHDOG


def test_watchdog_log_is_bounded_and_never_reads_secrets():
    assert "MaxLogBytes" in WATCHDOG, "the watchdog log must stay bounded"
    for secret_source in ("cloudflared", ".env", "/token", "\\token"):
        assert secret_source not in WATCHDOG, (
            f"the watchdog must not read {secret_source}"
        )


def test_watchdog_emits_the_documented_statuses():
    for status in (
        "HEALTHY",
        "APPLICATION_DOWN",
        "PORT_FOREIGN_OWNER",
        "UNEXPECTED_PROCESS",
        "UNEXPECTED_PROCESS_STOPPED",
        "APPLICATION_UNHEALTHY",
        "HEALTH_PROBE_ERROR",
        "RESTART_REQUESTED",
        "RESTARTED",
        "RECOVERY_CONFIRMED",
        "RESTART_FAILED",
        "RESTART_SUPPRESSED",
    ):
        assert status in WATCHDOG, f"status {status} is not logged"


def test_watchdog_reclaims_before_starting_the_task():
    """The port must be freed first, otherwise the restart dies with Errno 10048."""
    body = WATCHDOG[WATCHDOG.index("function Invoke-ProductionRestart") :]
    stop_position = body.index("Stop-HastamaApplicationProcesses")
    run_position = body.index("schtasks /Run /TN $TaskName")
    assert stop_position < run_position


def test_installer_keeps_the_system_principal_and_the_existing_arguments():
    assert "-UserId \"SYSTEM\"" in INSTALL_WATCHDOG
    assert "IgnoreNew" in INSTALL_WATCHDOG
    assert "-Port 5000 -TaskName" in INSTALL_WATCHDOG


# ── one start path, one owner ───────────────────────────────────────────────


def test_run_server_keeps_the_production_startup():
    for fragment in (
        "--host 127.0.0.1",
        "--port 5000",
        "--proxy-headers",
        "--forwarded-allow-ips 127.0.0.1",
        "set PYTHONUTF8=1",
        "set PYTHONIOENCODING=utf-8",
    ):
        assert fragment in RUN_SERVER, f"{fragment} must stay in the production launcher"
    assert "0.0.0.0" not in RUN_SERVER, "the application must stay loopback-bound"


def test_run_server_declares_itself_the_only_production_start_path():
    assert "PRODUCTION START PATH" in RUN_SERVER
    assert "راه‌اندازی_سرور_توسعه.bat" in RUN_SERVER


def test_development_launcher_cannot_take_the_production_port():
    assert "--port 5001" in RUN_DEV
    assert "--port 5000" not in RUN_DEV
    assert "DEVELOPMENT ONLY" in RUN_DEV


def test_start_helper_uses_the_scheduled_task():
    assert "schtasks /Run /TN HastamaServer" in START_BAT
    assert "راه‌اندازی_سرور_توسعه.bat" in START_BAT, "the helper must point developers elsewhere"


def test_stop_helper_identifies_the_process_before_stopping_it():
    assert "stop_server.ps1" in STOP_BAT
    # The old filter never matched, and a blind taskkill would kill unrelated
    # python processes.
    assert "WINDOWTITLE" not in STOP_BAT
    assert "taskkill /IM python.exe" not in STOP_BAT
    assert "Test-IsHastamaApp" in STOP_PS1
    assert "schtasks /End" in STOP_PS1, "the task instance must be cleared as well"


def test_watchdog_and_stop_helper_share_the_application_signature():
    assert len(set(_signatures())) == 1, "the application signature diverged"


def test_autostart_toggles_cover_both_tasks():
    assert "HastamaServer" in DISABLE_AUTOSTART and "HastamaWatchdog" in DISABLE_AUTOSTART
    assert "HastamaServer" in ENABLE_AUTOSTART and "HastamaWatchdog" in ENABLE_AUTOSTART


def test_manual_port_killer_warns_and_shows_the_owner():
    manual = _read("scripts", "آزادسازی_پورت_۵۰۰۰.bat")
    assert "MANUAL RECOVERY" in manual
    assert "توقف_سرور.bat" in manual
    assert "tasklist" in manual, "the operator must see what is being killed"


# ── documentation ───────────────────────────────────────────────────────────


def test_documentation_states_the_production_start_path():
    assert "راه‌اندازی_سرور_توسعه.bat" in DEPLOYMENT_DOC
    assert "PROCESS" in DEPLOYMENT_DOC or "Process supervision" in DEPLOYMENT_DOC
    assert "Never start production by hand" in DEPLOYMENT_DOC


def test_network_document_describes_the_layered_health_model():
    for layer in ("PORT_FOREIGN_OWNER", "UNEXPECTED_PROCESS", "APPLICATION_UNHEALTHY"):
        assert layer in NETWORK_DOC
    assert "127.0.0.1:5001" in NETWORK_DOC, "the development port must be documented"


def test_risk_register_records_the_unsupervised_process_risk():
    assert "RR-28" in RISK_REGISTER
    assert "unsupervised" in RISK_REGISTER.lower()
