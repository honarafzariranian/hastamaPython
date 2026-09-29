@echo off
title Hastama - open port 5000 to the local subnet
rem ===================================================================
rem  ONE TIME, ADMINISTRATOR ONLY - the single step LAN access mode needs.
rem
rem  Windows blocks inbound TCP 5000 ("Hastama - Block Uvicorn 5000") and a
rem  Block rule wins over an Allow rule, so this script remembers and
rem  disables that block rule, then adds an allow rule limited to the local
rem  subnet (Domain/Private profiles only).  Nothing is opened to the
rem  Internet and no firewall rule outside Hastama is touched.
rem
rem  Undo: scripts\بستن_پورت_شبکه_محلی.bat
rem  Report (no administrator needed): scripts\lan_access_firewall.ps1 -Action status
rem ===================================================================
echo.
echo Opening inbound TCP 5000 for the local subnet only...
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0lan_access_firewall.ps1" -Action enable
echo.
echo Next step: switch LAN access on in master-admin -^> system settings.
echo While that switch is off, nothing listens on the local address at all.
echo.
pause
