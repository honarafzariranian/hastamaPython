<#
.SYNOPSIS
    Registers Hastama server as a Windows Scheduled Task that starts at boot
    without requiring user login.

.DESCRIPTION
    Creates a scheduled task named "HastamaServer" that:
    - Runs at system startup (no login required)
    - Restarts automatically on failure
    - Logs output to logs/hastama-autostart.log
    - Runs uvicorn on 0.0.0.0:5000

    Run this script once with Administrator privileges to install.
    To uninstall: schtasks /Delete /TN "HastamaServer" /F
#>

$ErrorActionPreference = "Stop"

$TaskName = "HastamaServer"
$InstallRoot = Split-Path -Parent $PSScriptRoot
$Python = Join-Path $InstallRoot ".venv\Scripts\python.exe"
$LogsDir = Join-Path $InstallRoot "logs"
$LogFile = Join-Path $LogsDir "hastama-autostart.log"
$Host_ = "0.0.0.0"
$Port = "5000"

# --- Validate ---
if (-not (Test-Path $Python)) {
    Write-Error "Python not found: $Python`nRun 'uv sync' first."
    exit 1
}

New-Item -ItemType Directory -Force $LogsDir | Out-Null

# --- Remove old task if exists ---
$existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "Removing existing task '$TaskName'..."
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
}

# --- Build action: redirect stdout+stderr via cmd /c ---
$action = New-ScheduledTaskAction `
    -Execute "cmd.exe" `
    -Argument "/c `"$Python`" -m uvicorn app.main:app --host $Host_ --port $Port > `"$LogFile`" 2>&1" `
    -WorkingDirectory $InstallRoot

# --- Trigger: at system startup ---
$trigger = New-ScheduledTaskTrigger -AtStartup

# --- Settings ---
$settings = New-ScheduledTaskSettingsSet `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew

# --- Principal: run as SYSTEM, highest privilege ---
$principal = New-ScheduledTaskPrincipal `
    -UserId "SYSTEM" `
    -LogonType ServiceAccount `
    -RunLevel Highest

# --- Register ---
Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Principal $principal `
    -Description "Hastama Sample Collection Call System - Auto-starts at boot on $Host_`:$Port" `
    -Force

# --- Start immediately ---
Start-ScheduledTask -TaskName $TaskName

Write-Host ""
Write-Host "=================================================="
Write-Host " Hastama Auto-Start Installed Successfully"
Write-Host "=================================================="
Write-Host " Task Name  : $TaskName"
Write-Host " Python     : $Python"
Write-Host " Address    : http://${Host_}:${Port}"
Write-Host " Logs       : $LogFile"
Write-Host " Trigger    : At system startup (no login needed)"
Write-Host ""
Write-Host " Server is now starting..."
Write-Host ""
Write-Host "To check status:  Get-ScheduledTask -TaskName '$TaskName'"
Write-Host "To start now:     Start-ScheduledTask -TaskName '$TaskName'"
Write-Host "To stop:          Stop-ScheduledTask -TaskName '$TaskName'"
Write-Host "To uninstall:     schtasks /Delete /TN '$TaskName' /F"
Write-Host "=================================================="
