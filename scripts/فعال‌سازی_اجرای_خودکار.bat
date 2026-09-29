@echo off
echo Enabling Hastama auto-start...
schtasks /Change /TN HastamaServer /ENABLE
schtasks /Change /TN HastamaWatchdog /ENABLE
echo.
echo Auto-start enabled (HastamaServer + HastamaWatchdog). The server starts on boot
echo and is restarted within 5 minutes if the supervised instance stops.
echo To disable: scripts\disable_autostart.bat
echo.
pause
