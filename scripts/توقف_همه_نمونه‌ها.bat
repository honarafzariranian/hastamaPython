@echo off
chcp 65001 >nul
setlocal EnableExtensions
title Hastama - close every instance

rem ===================================================================
rem  MANUAL RECOVERY TOOL - closes every running Hastama instance
rem
rem  Use it when you want the machine quiet: nothing is started afterwards.
rem  A process is only stopped when it is identified as Hastama (application
rem  signature on any port, the production launcher wrapper, or an orphaned child of
rem  this repository's virtual environment).  A foreign owner of the port is
rem  reported and left alone.
rem
rem  The Cloudflare Tunnel, SQL Server and the autostart task *definitions* are
rem  never touched.  Note that the HastamaWatchdog task starts the supervised
rem  production instance again within five minutes unless you park it with
rem  scripts\غیرفعال‌سازی_اجرای_خودکار.bat.
rem ===================================================================

cd /d "%~dp0.."

echo.
echo Closing every running Hastama instance (any port)...
echo.
powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File "%~dp0dev_session.ps1" -Action stop-all
echo.
echo NOTE: the \HastamaWatchdog task starts the supervised production instance
echo       again within five minutes.  For a quiet machine park it first with
echo       scripts\غیرفعال‌سازی_اجرای_خودکار.bat (and scripts\فعال‌سازی_اجرای_خودکار.bat later),
echo       or use scripts\نشست_توسعه_یک‌کلیکی.bat, which parks both

echo       tasks for the length of the session and restores them on exit.
echo.
echo To start the supervised production instance again: scripts\شروع_سرور.bat
echo.
pause
