@echo off
echo Disabling Hastama auto-start...
schtasks /Change /TN HastamaServer /DISABLE
rem The watchdog would start the application again within 5 minutes, so leaving it
rem enabled would make "auto-start disabled" untrue.
schtasks /Change /TN HastamaWatchdog /DISABLE
echo.
echo Auto-start disabled (HastamaServer + HastamaWatchdog). Server will NOT start on boot
echo and will NOT be restarted automatically.
echo To stop it right now: scripts\stop_server.bat
echo To re-enable: scripts\enable_autostart.bat
echo.
pause
