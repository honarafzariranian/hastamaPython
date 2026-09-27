<#
.SYNOPSIS
    Registers the Hastama watchdog scheduled task (every 5 minutes, as SYSTEM).

.DESCRIPTION
    Companion to scripts\watchdog_server.ps1.  Run this from an ELEVATED
    PowerShell prompt.  The watchdog task runs as SYSTEM so that no Windows
    password has to be stored anywhere; the application itself keeps running as
    the normal service account because the watchdog only triggers the existing
    "HastamaServer" task.

    Idempotent: re-running replaces the task.

.EXAMPLE
    powershell -ExecutionPolicy Bypass -File .\scripts\install_watchdog.ps1
#>

[CmdletBinding()]
param(
    [string]$TaskName = "HastamaWatchdog",
    [string]$TargetTaskName = "HastamaServer",
    [int]$IntervalMinutes = 5
)

$InstallRoot = Split-Path -Parent $PSScriptRoot
$Script = Join-Path $InstallRoot "scripts\watchdog_server.ps1"

if (-not (Test-Path $Script)) {
    Write-Error "Watchdog script not found: $Script"
    exit 1
}

if ($IntervalMinutes -lt 1) {
    Write-Error "IntervalMinutes must be >= 1"
    exit 1
}

$action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument ("-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"{0}`" -Port 5000 -TaskName {1}" -f $Script, $TargetTaskName) `
    -WorkingDirectory $InstallRoot

$trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes)

$principal = New-ScheduledTaskPrincipal -UserId "SYSTEM" -LogonType ServiceAccount -RunLevel Highest

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -MultipleInstances IgnoreNew `
    -ExecutionTimeLimit (New-TimeSpan -Minutes 10)

Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Force | Out-Null

Write-Host ""
Write-Host "Hastama watchdog installed."
Write-Host "  Task      : $TaskName"
Write-Host "  Trigger   : every $IntervalMinutes minutes"
Write-Host "  Runs as   : SYSTEM (only triggers the '$TargetTaskName' task)"
Write-Host "  Script    : $Script"
Write-Host "  Log       : $InstallRoot\logs\hastama-watchdog.log"
Write-Host ""
Write-Host "Verify:  schtasks /Query /TN $TaskName /V /FO LIST"
Write-Host "Remove:  schtasks /Delete /TN $TaskName /F"
