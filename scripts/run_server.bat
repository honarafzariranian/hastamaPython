@echo off
rem ===========================================================================
rem Legacy launcher name - a bridge, nothing more.
rem
rem The scheduled task "HastamaServer" (and the watchdog that triggers it) was
rem registered against scripts\run_server.bat.  The production launcher itself
rem was renamed to Persian - see docs\HASTAMA_PRODUCTION_DEPLOYMENT.md,
rem "Operator scripts (Persian file names)".
rem
rem cmd.exe cannot hand a Persian path to a child process: the command line of
rem the child is built through the console code page, so a literal "call" of the
rem renamed launcher fails with "The system cannot find the path specified"
rem even after "chcp 65001".  (The renamed launchers themselves are fine - the
rem scheduled task and Explorer pass a UTF-16 command line.)  This file
rem therefore asks PowerShell, which works in UTF-16 end to end, for the
rem launcher and runs it.
rem
rem Which launcher: the one that declares the production port and the
rem production-only client-asset minifier switch - the same rule the task
rem installer uses, narrowed by a marker the development launchers do not have.
rem The two needles are assembled at run time on purpose: written as whole
rem literals this file would look like a production launcher to
rem scripts\install_autostart.ps1, and would then be registered instead of the
rem real one.
rem
rem Run scripts\install_autostart.ps1 again (as administrator, it asks for the
rem Windows password) to register the Persian name directly, then delete this
rem file.
rem ===========================================================================
setlocal

set "PS=%SystemRoot%\System32\WindowsPowerShell\v1.0\powershell.exe"
set "PSCODE=$ErrorActionPreference='Stop'; $port='--port ' + '5000'; $mark='MINIFY' + '_CLIENT_ASSETS'; $p=Get-ChildItem -LiteralPath '%~dp0' -Filter '*.bat' | Where-Object { $_.Name -ne 'run_server.bat' -and (Get-Content -LiteralPath $_.FullName -Raw -Encoding UTF8) -match $port -and (Get-Content -LiteralPath $_.FullName -Raw -Encoding UTF8) -match $mark } | Select-Object -First 1; if (-not $p) { Write-Host '[run_server] no production launcher found under %~dp0'; exit 1 }; Write-Host ('[run_server] -> ' + $p.FullName); & cmd.exe /c $p.FullName; exit $LASTEXITCODE"

"%PS%" -NoProfile -NonInteractive -ExecutionPolicy Bypass -Command "%PSCODE%" %*

endlocal
