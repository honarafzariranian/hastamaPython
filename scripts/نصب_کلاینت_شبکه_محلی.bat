@echo off
chcp 65001 >nul
setlocal
cd /d "%~dp0"
if "%~1"=="" (
    echo Usage: نصب_کلاینت_شبکه_محلی.bat SERVER_LAN_IP [CADDY_ROOT_CERT_PATH]
    exit /b 2
)
set "CERT=%~2"
powershell.exe -NoProfile -ExecutionPolicy Bypass -Command "Start-Process powershell.exe -Verb RunAs -Wait -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','%~dp0setup_hastama_client.ps1','-ServerIp','%~1','-RootCertificatePath','%CERT%'"
endlocal
