@echo off
title Hastama Server - Start
echo Starting Hastama Server...
schtasks /Run /TN HastamaServer
echo.
echo Server starting in background...
echo Wait 60 seconds then open https://hastama.ir (or http://127.0.0.1:5000 on the server)
echo.
pause
