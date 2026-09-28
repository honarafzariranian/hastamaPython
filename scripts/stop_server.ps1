<#
.SYNOPSIS
    Stops the production Hastama application cleanly.

.DESCRIPTION
    Called by scripts\stop_server.bat.  The scheduled task is stopped as well,
    but only after the process that owns the listening socket has been
    identified and stopped:

      * `schtasks /End` alone kills the `cmd.exe` wrapper, not the python child -
        the orphan keeps owning 127.0.0.1:5000, so the next start (or the
        watchdog's restart) fails with `[Errno 10048] address already in use`
        while the task reports success.  That landmine is why this helper exists.
      * a process is only stopped when its command line carries the Hastama
        application signature, so a foreign owner of the port is reported and
        left alone instead of being killed by port number.

    The Cloudflare Tunnel, the database and the firewall are never touched.
#>

[CmdletBinding()]
param(
    [int]$Port = 5000,
    [string]$TaskName = "HastamaServer"
)

# Keep this signature in sync with scripts\watchdog_server.ps1 (Test-IsHastamaApp);
# tests\test_production_supervision.py fails if the two ever diverge.
$AppSignature = "(?i)(-m\s+uvicorn\s+app\.main:app|uvicorn(\.exe)?\s+app\.main:app)"
$PortSignature = "(?i)--port\s+" + $Port + "(\s|$)"

function Test-IsHastamaApp([string]$CommandLine) {
    if (-not $CommandLine) { return $false }
    if ($CommandLine -notmatch $AppSignature) { return $false }
    return $CommandLine -match $PortSignature
}

function Format-ProcessLine($Proc) {
    if (-not $Proc) { return "(none)" }
    return ("pid={0} name={1} exe={2} cmd={3}" -f $Proc.ProcessId, $Proc.Name, $Proc.ExecutablePath, ($Proc.CommandLine -replace "\s+", " ").Trim())
}

$listenerPid = 0
try {
    $conn = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($conn) { $listenerPid = [int]$conn.OwningProcess }
} catch { }

if (-not $listenerPid) {
    Write-Host "Nothing is listening on 127.0.0.1:$Port."
} else {
    $listener = Get-CimInstance Win32_Process -Filter "ProcessId=$listenerPid" -ErrorAction SilentlyContinue
    if (-not (Test-IsHastamaApp $listener.CommandLine)) {
        Write-Host "127.0.0.1:$Port is owned by a process that is NOT the Hastama application:"
        Write-Host "    $(Format-ProcessLine $listener)"
        Write-Host "Leaving it alone.  Stop it from its own service/console."
    } else {
        $targets = @()
        try {
            $targets = @(Get-CimInstance Win32_Process -Filter "Name='python.exe'" -ErrorAction SilentlyContinue |
                Where-Object { Test-IsHastamaApp $_.CommandLine })
        } catch { }
        if (-not $targets) { $targets = @($listener) }
        foreach ($target in $targets) {
            Write-Host "Stopping $(Format-ProcessLine $target)"
            try {
                Stop-Process -Id $target.ProcessId -Force -ErrorAction Stop
            } catch {
                Write-Host "  could not stop pid=$($target.ProcessId): $($_.Exception.Message)"
            }
        }
    }
}

# Clear the task instance as well, otherwise the next schtasks /Run is refused
# by MultipleInstancesPolicy=IgnoreNew while the dead wrapper is still counted.
try { & schtasks /End /TN $TaskName 2>&1 | Out-Null } catch { }

# The socket is released asynchronously, so report only after it is really gone
# (an immediate count once reported "1" for a process that had just been killed).
$remaining = -1
for ($attempt = 0; $attempt -lt 10; $attempt++) {
    try {
        $remaining = @(Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue).Count
    } catch {
        $remaining = 0
    }
    if ($remaining -eq 0) { break }
    Start-Sleep -Milliseconds 500
}
if ($remaining -eq 0) {
    Write-Host "127.0.0.1:$Port is free."
} else {
    Write-Host "127.0.0.1:$Port is still listening - see the output above (foreign owner?)."
}
