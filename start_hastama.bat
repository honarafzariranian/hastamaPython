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

for /f "usebackq delims=" %%A in (`powershell -NoProfile -Command "$c=Get-NetIPConfiguration ^| Where-Object {$_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up'} ^| Select-Object -First 1; if($c){$c.IPv4Address.IPAddress}"`) do set "LAN_IP=%%A"
if "%LAN_IP%"=="" set "LAN_IP=not detected"

powershell -NoProfile -Command "$p=Get-NetTCPConnection -State Listen -LocalPort 8000 -ErrorAction SilentlyContinue; if($p -and ($p.LocalAddress -notin @('127.0.0.1','::1'))){Write-Error 'Port 8000 is already exposed outside loopback.'; exit 1}"
if errorlevel 1 exit /b 1
start "Hastama FastAPI" cmd /k "set PYTHONPATH=.&& .venv\Scripts\python.exe -m uvicorn app.main:app --host 127.0.0.1 --port 8000 --proxy-headers --forwarded-allow-ips 127.0.0.1"
timeout /t 3 /nobreak >nul
start "Hastama Caddy HTTPS" cmd /k "caddy run --config "%~dp0Caddyfile" --adapter caddyfile"

echo.
echo Hastama is starting.
echo URL: https://hastama.local
 echo Server LAN IP detected: %LAN_IP%
echo FastAPI: 127.0.0.1:8000 ^(loopback only^)
echo HTTPS: 443 ^(Caddy^)
echo Add the hostname to each client hosts file if LAN DNS is unavailable.
endlocal
