@echo off
chcp 65001 >nul
title Free Port 5000 - Hastama (manual recovery)
echo.
echo MANUAL RECOVERY TOOL.  This frees 127.0.0.1:5000 by killing whatever owns it,
echo so it can kill the supervised production instance as well.
echo Prefer, in this order:
echo    scripts\توقف_سرور.bat     identifies the application, then stops it
echo    scripts\شروع_سرور.bat    starts it through the HastamaServer task
echo.
echo Checking port 5000...
echo.
for /f "tokens=5" %%a in ('netstat -ano ^| findstr ":5000" ^| findstr "LISTENING"') do (
    echo Current owner of port 5000:
    tasklist /FI "PID eq %%a"
    echo.
    echo Stopping process PID: %%a
    taskkill /F /PID %%a
)
echo.
echo Port 5000 is now available.
echo Start the supervised instance with: scripts\شروع_سرور.bat
echo.
pause
