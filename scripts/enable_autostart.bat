@echo off
echo Enabling Hastama auto-start...
schtasks /Change /TN HastamaServer /ENABLE
echo.
echo Auto-start enabled. Server will start on boot.
echo To disable: scripts\disable_autostart.bat
echo.
pause
