@echo off
chcp 65001 >nul
title Hastama Server - Stop
echo Stopping Hastama Server...
rem The old window-title filter never matched the task's console, and
rem `schtasks /End` on its own left the python child alive while it still owned
rem 127.0.0.1:5000 - the next start then died with Errno 10048.
rem stop_server.ps1 identifies the process before stopping it.
powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0stop_server.ps1" -Port 5000 -TaskName HastamaServer
echo.
echo Server stopped.
echo NOTE: the HastamaWatchdog task starts it again within 5 minutes (by design).
echo For a maintenance window use scripts\غیرفعال‌سازی_اجرای_خودکار.bat (disables both tasks).
echo.
pause
