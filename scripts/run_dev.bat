@echo off
rem ===================================================================
rem  DEVELOPMENT ONLY - never use this for the production instance.
rem  The production application is owned by the HastamaServer scheduled task
rem  (scripts\run_server.bat) on 127.0.0.1:5000 and is published as
rem  https://hastama.ir through the Cloudflare Tunnel.
rem  A second uvicorn on port 5000 would take the port from the supervised
rem  instance; this launcher therefore binds 127.0.0.1:5001 and is not
rem  reachable through the tunnel.
rem ===================================================================
title Hastama Server - DEVELOPMENT (port 5001)
cd /d "%~dp0.."
set PYTHONUTF8=1
set PYTHONIOENCODING=utf-8
echo.
echo Development instance: http://127.0.0.1:5001   (NOT the public production URL)
echo Production instance : http://127.0.0.1:5000   (HastamaServer task, https://hastama.ir)
echo.
".venv\Scripts\python.exe" -m uvicorn app.main:app --host 127.0.0.1 --port 5001
