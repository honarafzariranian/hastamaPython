<#
.SYNOPSIS
    Registers Hastama server as a Windows Scheduled Task that starts at boot.
#>

$TaskName = "HastamaServer"
$InstallRoot = Split-Path -Parent $PSScriptRoot
$LogsDir = Join-Path $InstallRoot "logs"

# The production launcher is named in Persian, and this installer is deliberately
# kept pure ASCII (Windows PowerShell 5.1 reads a BOM-less script with the ANSI
# code page, which would garble an Arabic-script literal).  The launcher is
# therefore found by what it does instead of by how it is called: it is the only
# launcher under scripts\ that binds the production port with the proxy flags.
$BatFile = Get-ChildItem -LiteralPath (Join-Path $InstallRoot "scripts") -Filter "*.bat" |
    Where-Object {
        (Get-Content -LiteralPath $_.FullName -Raw -Encoding UTF8) -match "--port 5000" -and
        (Get-Content -LiteralPath $_.FullName -Raw -Encoding UTF8) -match "--forwarded-allow-ips 127\.0\.0\.1"
    } |
    Select-Object -First 1 -ExpandProperty FullName

# --- Validate ---
if (-not (Test-Path $BatFile)) {
    Write-Error "Batch file not found: $BatFile"
    exit 1
}

New-Item -ItemType Directory -Force $LogsDir | Out-Null

# --- Remove old task if exists ---
schtasks /Delete /TN $TaskName /F 2>$null

# --- Get current user ---
$currentUser = [System.Security.Principal.WindowsIdentity]::GetCurrent().Name
Write-Host ""
Write-Host "Current user: $currentUser"
Write-Host ""

# --- Ask for password ---
$password = Read-Host -Prompt "Enter Windows password for $currentUser" -AsSecureString
$plainPassword = [Runtime.InteropServices.Marshal]::PtrToStringAuto(
    [Runtime.InteropServices.Marshal]::SecureStringToBSTR($password)
)

# --- Create task via schtasks ---
$schtasksArgs = @(
    "/Create"
    "/TN", $TaskName
    "/TR", "`"cmd.exe`" /c `"$BatFile`""
    "/SC", "ONSTART"
    "/RL", "HIGHEST"
    "/RU", $currentUser
    "/RP", $plainPassword
    "/F"
)

$result = & schtasks @schtasksArgs 2>&1

if ($LASTEXITCODE -ne 0) {
    Write-Host ""
    Write-Error "Failed to create task:`n$result"
    exit 1
}

# --- Fix power settings: don't stop on battery, start when available ---
$task = Get-ScheduledTask -TaskName $TaskName
$task.Settings.DisallowStartIfOnBatteries = $false
$task.Settings.StopIfGoingOnBatteries = $false
$task.Settings.StartWhenAvailable = $true
$task.Settings.ExecutionTimeLimit = 'PT0S'
Set-ScheduledTask -InputObject $task -User $currentUser -Password $plainPassword

# --- Start immediately ---
schtasks /Run /TN $TaskName

Write-Host ""
Write-Host "=================================================="
Write-Host " Hastama Auto-Start Installed Successfully"
Write-Host "=================================================="
Write-Host " Task Name  : $TaskName"
Write-Host " User       : $currentUser"
Write-Host " Batch      : $BatFile"
Write-Host " Address    : http://127.0.0.1:5000 (loopback; public via Cloudflare Tunnel)"
Write-Host " Logs       : $LogsDir\hastama-autostart.log"
Write-Host " Trigger    : At system startup"
Write-Host ""
Write-Host " Server is now starting..."
Write-Host ""
Write-Host " Commands:"
Write-Host "   Check:   schtasks /Query /TN $TaskName"
Write-Host "   Start:   schtasks /Run /TN $TaskName"
Write-Host "   Stop:    schtasks /End /TN $TaskName"
Write-Host "   Delete:  schtasks /Delete /TN $TaskName /F"
Write-Host "=================================================="
