@echo off
title Hastama Server - Stop
echo Stopping Hastama Server...
taskkill /F /IM python.exe /FI "WINDOWTITLE eq Hastama*" >nul 2>&1
schtasks /End /TN HastamaServer >nul 2>&1
echo.
echo Server stopped.
echo.
pause
