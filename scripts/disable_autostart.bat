@echo off
echo Disabling Hastama auto-start...
schtasks /Change /TN HastamaServer /DISABLE
echo.
echo Auto-start disabled. Server will NOT start on boot.
echo To re-enable: scripts\enable_autostart.bat
echo.
pause
