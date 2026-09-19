
@echo off
title Free Port 5000 - Hastama

echo.
echo Checking port 5000...
echo.

for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":5000" ^| findstr "LISTENING"') do (
    echo Stopping process PID: %%a
    taskkill /F /PID %%a
)

echo.
echo Port 5000 is now available.
echo.
pause