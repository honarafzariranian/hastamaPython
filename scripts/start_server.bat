@echo off
title Hastama Server - Start
echo Starting Hastama Server...
schtasks /Run /TN HastamaServer
echo.
echo Server starting in background...
echo Wait 60 seconds then open https://hastama.ir
echo (same URL for laboratory LAN and Internet users; loopback 127.0.0.1:5000 is
echo only for an administrator checking the service on this server)
echo.
pause
