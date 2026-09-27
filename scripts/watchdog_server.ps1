<#
.SYNOPSIS
    Watchdog: restarts the Hastama application if port 5000 is not listening.

.DESCRIPTION
    The scheduled task "HastamaServer" starts the application at boot only.  If
    the Python process dies during the day, the public URL https://hastama.ir
    stays down until somebody logs in.  This script is the missing piece: it is
    meant to run from a 5-minute scheduled task and only acts when the loopback
    listener is gone.

    Safety properties:
      * it never starts a second instance — it triggers the existing task, whose
        MultipleInstancesPolicy is IgnoreNew, so a boot/start already in progress
        is left alone;
      * it never touches the database, the tunnel or the firewall;
      * it logs one line per intervention to logs\hastama-watchdog.log and stays
        silent while healthy.

    Install it with scripts\install_watchdog.ps1 (elevated; registers the task as
    SYSTEM so no password has to be stored).
#>

[CmdletBinding()]
param(
    [int]$Port = 5000,
    [string]$TaskName = "HastamaServer",
    [int]$StartupWaitSeconds = 60
)

$InstallRoot = Split-Path -Parent $PSScriptRoot
$LogFile = Join-Path $InstallRoot "logs\hastama-watchdog.log"

function Write-Log([string]$Message) {
    try {
        $logDir = Split-Path -Parent $LogFile
        if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Force $logDir | Out-Null }
        $line = "[{0}] {1}" -f (Get-Date).ToString("yyyy-MM-dd HH:mm:ss"), $Message
        Add-Content -Path $LogFile -Value $line -Encoding UTF8
    } catch {
        # A watchdog must never fail because logging failed.
    }
}

function Test-LoopbackListener([int]$ProbePort) {
    $client = New-Object System.Net.Sockets.TcpClient
    try {
        $async = $client.BeginConnect("127.0.0.1", $ProbePort, $null, $null)
        $connected = $async.AsyncWaitHandle.WaitOne(1500) -and $client.Connected
        return [bool]$connected
    } catch {
        return $false
    } finally {
        $client.Close()
    }
}

if (Test-LoopbackListener $Port) {
    exit 0   # healthy: no output, no log growth
}

Write-Log "127.0.0.1:$Port is not listening - starting scheduled task '$TaskName'"
schtasks /Run /TN $TaskName | Out-Null

Start-Sleep -Seconds $StartupWaitSeconds

if (Test-LoopbackListener $Port) {
    Write-Log "application is listening again on 127.0.0.1:$Port"
    exit 0
}

Write-Log "still down after ${StartupWaitSeconds}s - inspect logs\hastama-autostart.log and the task result code"
exit 1
