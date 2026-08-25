@echo off
setlocal EnableExtensions
cd /d "%~dp0"

echo ==================================================
echo HASTAMA LAN SERVER
echo ==================================================

if not exist ".venv\Scripts\python.exe" (
    echo [ERROR] Python environment not found: %~dp0.venv\Scripts\python.exe
    echo Run: uv sync --dev
    exit /b 1
)
where caddy >nul 2>nul
if errorlevel 1 (
    echo [ERROR] Caddy is not available on PATH.
    echo Install with: choco install caddy -y
    exit /b 1
)

caddy validate --config "%~dp0Caddyfile" --adapter caddyfile
if errorlevel 1 (
    echo [ERROR] Caddyfile validation failed. Nothing was started.
    exit /b 1
)

for /f "usebackq delims=" %%A in (`powershell -NoProfile -Command "$c=Get-NetIPConfiguration ^| Where-Object {$_.IPv4DefaultGateway -and $_.NetAdapter.Status -eq 'Up'} ^| Select-Object -First 1; if($c){$c.IPv4Address.IPAddress}"`) do set "LAN_IP=%%A"
if "%LAN_IP%"=="" set "LAN_IP=not detected"

powershell -NoProfile -Command "$p=Get-NetTCPConnection -State Listen -LocalPort 8000 -ErrorAction SilentlyContinue; if($p -and ($p.LocalAddress -notin @('127.0.0.1','::1'))){Write-Error 'Port 8000 is already exposed outside loopback.'; exit 1}"
if errorlevel 1 exit /b 1

powershell -NoProfile -Command "$p=Get-NetTCPConnection -State Listen -LocalPort 8000 -ErrorAction SilentlyContinue; if(-not $p){Start-Process -FilePath '%~dp0.venv\Scripts\python.exe' -ArgumentList '-m','uvicorn','app.main:app','--host','127.0.0.1','--port','8000','--proxy-headers','--forwarded-allow-ips','127.0.0.1' -WorkingDirectory '%~dp0'} else {Write-Host 'FastAPI already listening on 127.0.0.1:8000'}"
timeout /t 3 /nobreak >nul

powershell -NoProfile -Command "$p=Get-NetTCPConnection -State Listen -LocalPort 443 -ErrorAction SilentlyContinue; if(-not $p){Start-Process -FilePath 'caddy' -ArgumentList 'run','--config','%~dp0Caddyfile','--adapter','caddyfile' -WorkingDirectory '%~dp0'} else {Write-Host 'Caddy already listening on TCP 443'}"
timeout /t 3 /nobreak >nul

powershell -NoProfile -Command "$a=Get-NetTCPConnection -State Listen -LocalPort 8000 -ErrorAction SilentlyContinue; $b=Get-NetTCPConnection -State Listen -LocalPort 443 -ErrorAction SilentlyContinue; if(-not $a -or ($a.LocalAddress -notin @('127.0.0.1','::1'))){Write-Error 'FastAPI binding check failed'; exit 1}; if(-not $b){Write-Error 'Caddy HTTPS binding check failed'; exit 1}"
if errorlevel 1 exit /b 1

echo.
echo URL: https://hastama.local
echo Server LAN IP: %LAN_IP%
echo FastAPI: 127.0.0.1:8000 ^(loopback only^)
echo HTTPS: TCP 443 ^(Caddy^)
echo Web Push: check VAPID configuration in .env
echo SSE: /api/notifications/stream and /api/notifications/admin-stream
echo ==================================================
endlocal
