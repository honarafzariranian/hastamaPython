@echo off
rem ===================================================================
rem  PRODUCTION START PATH - do not bypass.
rem  This batch file is the ONLY supported way to run the production
rem  application: the HastamaServer scheduled task invokes it with
rem    cmd.exe /c E:\Hastama\scripts\run_server.bat
rem  Starting uvicorn by hand on port 5000 takes the port away from the
rem  supervised instance, runs without the settings below and dies with its
rem  terminal (real incident 2026-09-28, public 502 until the watchdog
rem  reclaimed the port).  For development use scripts\run_dev.bat (port 5001).
rem ===================================================================
title Hastama Server - production (HastamaServer task)
cd /d "E:\Hastama"
if not exist logs mkdir logs
echo [%date% %time%] Waiting for SQL Server service... >> logs\hastama-autostart.log
:WAITSQL
sc query MSSQL$SQLEXPRESS | findstr /i "RUNNING" >nul
if errorlevel 1 (
    timeout /t 5 /nobreak >nul
    goto WAITSQL
)
echo [%date% %time%] SQL Server is running. Waiting extra 30s for full startup... >> logs\hastama-autostart.log
timeout /t 30 /nobreak >nul
rem Keep this log bounded.  The app logs every APScheduler sweep, so the file
rem reached ~270 MB in three weeks; an unbounded log on the same volume as SQL
rem Server is a disk-exhaustion risk.  One previous file is kept for diagnosis.
if exist "logs\hastama-autostart.log" (
    for %%F in ("logs\hastama-autostart.log") do if %%~zF GTR 52428800 (
        if exist "logs\hastama-autostart.log.1" del /q "logs\hastama-autostart.log.1"
        move /y "logs\hastama-autostart.log" "logs\hastama-autostart.log.1" >nul
    )
)

echo [%date% %time%] Starting Hastama... >> logs\hastama-autostart.log
rem UTF-8 console.  stdout is redirected to a file, and without this the
rem interpreter encoded it with the host ANSI code page (cp1252): a Persian
rem `print()` then raised UnicodeEncodeError inside the request and returned
rem HTTP 500 (seen on GET /get_hozoor/{username} for every Persian username).
set PYTHONUTF8=1
set PYTHONIOENCODING=utf-8
rem --proxy-headers is the uvicorn default, but state it explicitly: the public
rem URL is https://hastama.ir via the Cloudflare Tunnel, and cloudflared connects
rem from 127.0.0.1, so X-Forwarded-Proto/Host/For are trusted only from loopback.
".venv\Scripts\python.exe" -m uvicorn app.main:app --host 127.0.0.1 --port 5000 --proxy-headers --forwarded-allow-ips 127.0.0.1 >> logs\hastama-autostart.log 2>&1
