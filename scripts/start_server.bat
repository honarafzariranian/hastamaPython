@echo off
title Hastama Server - Start
echo Starting Hastama Server...
schtasks /Run /TN HastamaServer
echo.
echo Server starting in background...
echo Wait 60 seconds then open http://192.168.3.69:5000
echo.
pause
