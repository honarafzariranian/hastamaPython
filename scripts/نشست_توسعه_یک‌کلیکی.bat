@echo off
setlocal EnableExtensions
title Hastama - development session (127.0.0.1:5000)

rem ===================================================================
rem  WORK ON THE CODE - one instance, one console, logs on screen.
rem
rem  What this launcher guarantees:
rem    1. every running Hastama instance is closed first (any port);
rem    2. the two autostart tasks are PARKED for this session only, so the
rem       watchdog cannot bring the production instance back while we work.
rem       Their definitions, triggers and files are never edited;
rem    3. the application starts on 127.0.0.1:5000 - the normal address - so the
rem       Cloudflare Tunnel and https://hastama.ir serve THIS code;
rem    4. when this console ends, the tasks are enabled again and, if production
rem       was serving before, the supervised instance is started again.
rem
rem  Production itself is untouched: \HastamaServer -> scripts\run_server.bat.
rem  For an isolated development instance on port 5001 use scripts\run_dev.bat.
rem ===================================================================

cd /d "%~dp0"

set "REPO=%~dp0"
set "PY=%REPO%.venv\Scripts\python.exe"
set "PS=powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File"
set "SESSION=%REPO%scripts\dev_session.ps1"

if not exist "%PY%" (
    echo [ERROR] Python environment not found: %PY%
    echo         Create it first: uv sync --dev
    echo.
    pause
    exit /b 1
)
if not exist "%REPO%logs" mkdir "%REPO%logs"

rem The elevated task holder must know when this console dies.
set "SESSION_PID="
for /f "usebackq delims=" %%P in (`powershell -NoProfile -NonInteractive -Command "(Get-CimInstance Win32_Process -Filter ('ProcessId=' + $PID)).ParentProcessId"`) do set "SESSION_PID=%%P"
if "%SESSION_PID%"=="" set "SESSION_PID=0"

if /i "%~1"=="--check" (
    echo.
    echo [CHECK] console pid %SESSION_PID% - nothing will be stopped or started.
    %PS% "%SESSION%" -Action list
    %PS% "%SESSION%" -Action status
    echo.
    pause
    exit /b 0
)

echo.
echo ============================================================
echo   HASTAMA - development session
echo ============================================================
echo   address      : http://127.0.0.1:5000
echo   public       : https://hastama.ir   (the tunnel points at 5000,
echo                  so it serves the code under test)
echo   console logs : live, right here
echo   log file     : logs\hastama-dev.log  (utf-8, 50 MB + 1 rotation)
echo   end session  : Ctrl+C, or close this window
echo ============================================================
echo.

%PS% "%SESSION%" -Action stop-all
if errorlevel 2 (
    echo.
    echo [ABORT] Nothing was started: another application owns 127.0.0.1:5000.
    echo.
    pause
    exit /b 1
)

echo.
echo Parking the autostart tasks for this session (one UAC prompt is normal)...
%PS% "%SESSION%" -Action hold-tasks -WatchPid %SESSION_PID%
if errorlevel 1 (
    echo [WARNING] The tasks could not be parked automatically.
    echo           Run this file as administrator, or use scripts\disable_autostart.bat
)
%PS% "%SESSION%" -Action await-disable -TimeoutSeconds 15

set PYTHONUTF8=1
set PYTHONIOENCODING=utf-8
set PYTHONUNBUFFERED=1

echo.
echo Starting the application... (saving a .py file reloads it automatically)
echo.

"%PY%" -m uvicorn app.main:app ^
    --host 127.0.0.1 ^
    --port 5000 ^
    --reload ^
    --reload-dir app ^
    --proxy-headers ^
    --forwarded-allow-ips 127.0.0.1 ^
    --log-config scripts\dev-logging.json

echo.
echo Application stopped. Handing the machine back...
%PS% "%SESSION%" -Action await-restore -TimeoutSeconds 20
%PS% "%SESSION%" -Action restore
echo.
%PS% "%SESSION%" -Action status
echo.
pause
