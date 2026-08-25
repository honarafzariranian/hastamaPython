@echo off
setlocal
cd /d "%~dp0"

if not exist ".venv\Scripts\python.exe" (
    echo Hastama virtual environment was not found.
    echo Create/install it first with: uv sync --dev
    exit /b 1
)
if not exist "Caddyfile" (
    echo Caddyfile was not found.
    exit /b 1
)
where caddy >nul 2>nul
if errorlevel 1 (
    echo Caddy was not found on PATH. Install Caddy and try again.
    exit /b 1
)

for /f "tokens=2 delims=:" %%A in ('ipconfig ^| findstr /R /C:"IPv4 Address" /C:"IPv4 Address\. ") do set "LAN_IP=%%A"
set "LAN_IP=%LAN_IP: =%"
if "%LAN_IP%"=="" set "LAN_IP"=the server LAN IP

start "Hastama FastAPI" cmd /k "set PYTHONPATH=app&& .venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8000 --proxy-headers --forwarded-allow-ips 127.0.0.1"
timeout /t 3 /nobreak >nul
start "Hastama Caddy HTTPS" cmd /k "caddy run --config "%~dp0Caddyfile" --adapter caddyfile"

echo.
echo Hastama is starting.
echo URL: https://hastama.local
 echo Server LAN IP detected: %LAN_IP%
echo Add the hostname to each client hosts file if LAN DNS is unavailable.
endlocal
