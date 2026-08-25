[CmdletBinding()]
param(
    [string]$InstallRoot = (Split-Path -Parent $PSScriptRoot),
    [string]$NssmPath = "nssm.exe"
)

$ErrorActionPreference = "Stop"
$apiService = "HastamaApi"
$caddyService = "HastamaHttps"
$python = Join-Path $InstallRoot ".venv\Scripts\python.exe"
$caddyfile = Join-Path $InstallRoot "Caddyfile"

if (-not (Test-Path $python)) { throw "Python environment not found: $python" }
if (-not (Test-Path $caddyfile)) { throw "Caddyfile not found: $caddyfile" }
if (-not (Get-Command $NssmPath -ErrorAction SilentlyContinue)) { throw "NSSM was not found. Install and approve NSSM before running this script." }

function Install-HastamaService([string]$Name, [string]$Application, [string]$Arguments, [string]$WorkingDirectory, [string]$LogName) {
    & $NssmPath install $Name $Application $Arguments | Out-Null
    & $NssmPath set $Name AppDirectory $WorkingDirectory | Out-Null
    & $NssmPath set $Name Start SERVICE_AUTO_START | Out-Null
    & $NssmPath set $Name AppExit Default Restart | Out-Null
    & $NssmPath set $Name AppStdout (Join-Path $WorkingDirectory "logs\$LogName.log") | Out-Null
    & $NssmPath set $Name AppStderr (Join-Path $WorkingDirectory "logs\$LogName.error.log") | Out-Null
    & $NssmPath set $Name AppRotateFiles 1 | Out-Null
    & $NssmPath set $Name AppRotateOnline 1 | Out-Null
    & $NssmPath set $Name AppRotateBytes 10485760 | Out-Null
}

New-Item -ItemType Directory -Force (Join-Path $InstallRoot "logs") | Out-Null
Get-Service $apiService,$caddyService -ErrorAction SilentlyContinue | Stop-Service -Force -ErrorAction SilentlyContinue
& $NssmPath remove $apiService confirm 2>$null
& $NssmPath remove $caddyService confirm 2>$null
Install-HastamaService $apiService $python "-m uvicorn app.main:app --host 127.0.0.1 --port 8000 --proxy-headers --forwarded-allow-ips 127.0.0.1" $InstallRoot "api"
Install-HastamaService $caddyService "caddy.exe" "run --config `"$caddyfile`" --adapter caddyfile" $InstallRoot "caddy"
Start-Service $apiService
Start-Sleep -Seconds 3
Start-Service $caddyService
Write-Host "Installed and started $apiService and $caddyService."
