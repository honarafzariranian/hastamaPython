
@echo off
title Freebuff Launcher

echo Closing previous Freebuff processes...
taskkill /F /IM Freebuff.exe >nul 2>&1

echo Setting proxy...
set "HTTP_PROXY=http://127.0.0.1:10808"
set "HTTPS_PROXY=http://127.0.0.1:10808"
set "ALL_PROXY=http://127.0.0.1:10808"

echo Starting Freebuff...
start "" "C:\Users\Administrator\AppData\Local\Programs\@codebufffreebuff-desktop\Freebuff.exe"

echo.
echo Freebuff started.
exit