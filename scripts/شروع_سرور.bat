@echo off
title Hastama Server - Start
rem PRODUCTION start path: this triggers the HastamaServer scheduled task, which
rem runs scripts\run_server.bat (SQL wait, log rotation, UTF-8 environment,
rem --proxy-headers --forwarded-allow-ips 127.0.0.1).
rem Never start uvicorn by hand on port 5000: an unsupervised instance takes the
rem port from the task, runs without those settings and dies with its terminal
rem (incident 2026-09-28).  Development: scripts\run_dev.bat (port 5001).
echo Starting Hastama Server...
schtasks /Run /TN HastamaServer
echo.
echo Server starting in background...
echo Wait 60 seconds then open https://hastama.ir
echo (same URL for laboratory LAN and Internet users; loopback 127.0.0.1:5000 is
echo only for an administrator checking the service on this server)
echo.
pause
