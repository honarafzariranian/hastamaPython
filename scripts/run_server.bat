@echo off
cd /d "E:\Hastama"
if not exist logs mkdir logs
echo [%date% %time%] Waiting for SQL Server service... >> logs\hastama-autostart.log
:WAITSQL
sc query MSSQL$SQLEXPRESS | findstr /i "RUNNING" >nul
if errorlevel 1 (
    timeout /t 5 /nobreak >nul
    goto WAITSQL
)
echo [%date% %time%] SQL Server is running. Waiting extra 30s for full startup... >> logs\hastama-autostart.log
timeout /t 30 /nobreak >nul
echo [%date% %time%] Starting Hastama... >> logs\hastama-autostart.log
".venv\Scripts\python.exe" -m uvicorn app.main:app --host 0.0.0.0 --port 5000 >> logs\hastama-autostart.log 2>&1
