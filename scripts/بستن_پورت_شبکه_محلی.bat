@echo off
title Hastama - close port 5000 to the local subnet
rem ===================================================================
rem  ADMINISTRATOR ONLY - the exact undo of scripts\بازکردن_پورت_شبکه_محلی.bat.
rem
rem  Removes "Hastama - Allow Uvicorn 5000 (LAN)" and re-enables the block
rem  rules that the enable step disabled (remembered in
rem  logs\lan-access-firewall.json).  Remember to switch LAN access off in
rem  master-admin -^> system settings as well.
rem ===================================================================
echo.
echo Closing inbound TCP 5000 on the local subnet...
echo.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0lan_access_firewall.ps1" -Action disable
echo.
pause
