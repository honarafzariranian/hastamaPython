<#
.SYNOPSIS
    Watchdog: keeps 127.0.0.1:5000 owned by the supervised production instance.

.DESCRIPTION
    The scheduled task "HastamaServer" starts the application at boot only.  If
    the Python process dies during the day, the public URL https://hastama.ir
    stays down until somebody logs in.  This script is the missing piece: it runs
    from a 5-minute scheduled task as SYSTEM and repairs the listener.

    "The port is listening" is NOT a health condition.  On 2026-09-28 a uvicorn
    process started by hand from a VS Code terminal took 127.0.0.1:5000 over from
    the supervised instance; it ran without the production flags and without the
    UTF-8 environment, and it died with its terminal, causing a public 502.  A
    port-only probe (the previous implementation) reported that state as healthy
    for the whole time.

    Layered health model, in order:

      1. Listener identity - the process that owns the port must be the Hastama
         application itself: an interpreter running `uvicorn app.main:app` with
         `--port <Port>`.  A process that merely owns port 5000 is reported as a
         foreign owner and is NEVER killed.
      2. Production configuration - the listener's command line must carry all
         four production flags (--host 127.0.0.1 --port 5000 --proxy-headers
         --forwarded-allow-ips 127.0.0.1).
      3. Supervision - the listener must descend from the production launcher
         (`cmd.exe /c ...\scripts\<launcher>.bat`, i.e. the HastamaServer task)
         OR show that it
         is the production instance by writing to the production log
         (logs\hastama-autostart.log is refreshed every second while the
         supervised instance runs).  Ancestry is supporting evidence, not the
         only identity mechanism.
      4. Application health - GET http://127.0.0.1:5000/health must answer 200
         within a few seconds (the endpoint is a constant, no database access).

    Anything identified as the Hastama application running OUTSIDE supervision is
    logged with its PID, executable, command line and the missing evidence, then
    reclaimed: the identified process tree is stopped and the official
    HastamaServer task is started again.

    Safety properties:
      * never kills a process that does not carry the Hastama application
        signature (a foreign owner is logged and left alone);
      * never starts a second instance - it triggers the existing task, whose
        MultipleInstancesPolicy is IgnoreNew;
      * restart-loop protection: at most $MaxRestartsPerWindow restarts inside a
        rolling $RestartWindowMinutes window, with the reason and counters logged;
      * never touches the database, the tunnel or the firewall;
      * logs one bounded line per event (rotated above $MaxLogBytes) and never
        records secrets.

    Install it with scripts\install_watchdog.ps1 (elevated; registers the task as
    SYSTEM so no password has to be stored).
#>

[CmdletBinding()]
param(
    [int]$Port = 5000,
    [string]$TaskName = "HastamaServer",
    [string]$HealthUrl = "",
    [string]$PublicHealthUrl = "https://hastama.ir/health",
    [int]$HealthTimeoutSeconds = 5,
    [int]$StartupWaitSeconds = 75,
    [int]$UnhealthyThreshold = 3,
    [int]$MaxRestartsPerWindow = 3,
    [int]$RestartWindowMinutes = 30,
    [int]$ProductionLogFreshSeconds = 120,
    [int]$MaxLogBytes = 2097152,
    [switch]$SkipPublicCheck
)

$InstallRoot = Split-Path -Parent $PSScriptRoot
$LogFile = Join-Path $InstallRoot "logs\hastama-watchdog.log"
$StateFile = Join-Path $InstallRoot "logs\hastama-watchdog-state.json"
$ProductionLog = Join-Path $InstallRoot "logs\hastama-autostart.log"

if (-not $HealthUrl) { $HealthUrl = "http://127.0.0.1:$Port/health" }

# ── logging ────────────────────────────────────────────────────────────────
# Statuses (never include secrets - no tokens, session ids or passwords):
#   HEALTHY                 production instance verified and serving
#   APPLICATION_DOWN        nothing is listening on the port
#   PORT_FOREIGN_OWNER      another application owns the port (never killed)
#   UNEXPECTED_PROCESS      the Hastama app is running outside supervision
#   APPLICATION_UNHEALTHY   expected process present, /health failing
#   HEALTH_PROBE_ERROR      the health probe itself failed (never restarts)
#   PROCESS_LOOKUP_FAILED   the owning process could not be inspected (no action)
#   RESTART_REQUESTED       a restart of the official task was triggered
#   RESTARTED               the official task was triggered and the port came back
#   RECOVERY_CONFIRMED      identity + /health verified after a restart
#   RESTART_FAILED          the port did not come back as expected
#   RESTART_SUPPRESSED      restart-loop protection refused another attempt
#   PUBLIC_HEALTH_*         best-effort check of the public URL (informational)

function Write-Log([string]$Status, [string]$Message) {
    try {
        $logDir = Split-Path -Parent $LogFile
        if (-not (Test-Path $logDir)) { New-Item -ItemType Directory -Force $logDir | Out-Null }
        try {
            if ((Test-Path $LogFile) -and ((Get-Item $LogFile).Length -gt $MaxLogBytes)) {
                $rotated = "$LogFile.1"
                if (Test-Path $rotated) { Remove-Item $rotated -Force -ErrorAction SilentlyContinue }
                Move-Item $LogFile $rotated -Force -ErrorAction SilentlyContinue
            }
        } catch {
            # rotation is best effort - never lose an event because of it
        }
        $line = "[{0}] {1} {2}" -f (Get-Date).ToString("yyyy-MM-dd HH:mm:ss"), $Status, $Message
        Add-Content -Path $LogFile -Value $line -Encoding UTF8
    } catch {
        # A watchdog must never fail because logging failed.
    }
}

# ── persistent state (consecutive failures, restart history) ───────────────
function Get-WatchdogState {
    $default = @{ unhealthy_streak = 0; last_status = ""; restart_times = @() }
    try {
        if (Test-Path $StateFile) {
            $raw = Get-Content -Path $StateFile -Raw -ErrorAction Stop
            if ($raw) {
                $parsed = $raw | ConvertFrom-Json
                return @{
                    unhealthy_streak = [int]$parsed.unhealthy_streak
                    last_status      = [string]$parsed.last_status
                    restart_times    = @($parsed.restart_times)
                }
            }
        }
    } catch {
        # A corrupt state file must not stop the watchdog.
    }
    return $default
}

function Save-WatchdogState($State) {
    try {
        $State | ConvertTo-Json -Depth 4 | Set-Content -Path $StateFile -Encoding UTF8
    } catch {
        # state is an optimisation, not a safety requirement
    }
}

# ── process inspection ─────────────────────────────────────────────────────
function Get-ListenerPid([int]$ProbePort) {
    try {
        $conn = Get-NetTCPConnection -LocalPort $ProbePort -State Listen -ErrorAction SilentlyContinue |
            Select-Object -First 1
        if ($conn) { return [int]$conn.OwningProcess }
    } catch {
        # fall through to netstat
    }
    try {
        $match = & netstat -ano | Select-String -Pattern (":{0}\s" -f $ProbePort) | Select-String "LISTENING" |
            Select-Object -First 1
        if ($match) {
            $fields = ($match.ToString() -replace "\s+", " ").Trim().Split(" ")
            if ($fields.Length -ge 5) { return [int]$fields[$fields.Length - 1] }
        }
    } catch {
        return 0
    }
    return 0
}

function Get-ProcessNode([int]$ProcessId) {
    try {
        return Get-CimInstance Win32_Process -Filter "ProcessId=$ProcessId" -ErrorAction SilentlyContinue
    } catch {
        return $null
    }
}

function Get-AncestorChain([int]$ProcessId, [int]$Depth = 6) {
    $chain = @()
    $current = Get-ProcessNode $ProcessId
    $level = 0
    while ($current -and $level -lt $Depth) {
        $chain += $current
        if (-not $current.ParentProcessId -or $current.ParentProcessId -eq 0) { break }
        $current = Get-ProcessNode ([int]$current.ParentProcessId)
        $level++
    }
    return $chain
}

function Format-ProcessLine($Proc) {
    if (-not $Proc) { return "(unknown)" }
    $cmd = ""
    if ($Proc.CommandLine) { $cmd = ($Proc.CommandLine -replace "\s+", " ").Trim() }
    return ("pid={0} name={1} exe={2} cmd={3}" -f $Proc.ProcessId, $Proc.Name, $Proc.ExecutablePath, $cmd)
}

# The application signature: an interpreter or entry point running the Hastama
# app on the configured port.  Both the production launcher form
# (`... -m uvicorn app.main:app`) and a direct entry point
# (`...\uvicorn.exe app.main:app`) are covered.
function Test-IsHastamaApp([string]$CommandLine, [int]$ProbePort) {
    if (-not $CommandLine) { return $false }
    $isApp = $CommandLine -match "(?i)(-m\s+uvicorn\s+app\.main:app|uvicorn(\.exe)?\s+app\.main:app)"
    if (-not $isApp) { return $false }
    return $CommandLine -match ("(?i)--port\s+" + $ProbePort + "(\s|$)")
}

function Test-HasProductionFlags([string]$CommandLine, [int]$ProbePort) {
    if (-not $CommandLine) { return $false }
    $flags = @(
        "(?i)--host\s+127\.0\.0\.1(\s|$)",
        "(?i)--port\s+" + $ProbePort + "(\s|$)",
        "(?i)--proxy-headers(\s|$)",
        "(?i)--forwarded-allow-ips\s+`"?127\.0\.0\.1"
    )
    foreach ($flag in $flags) {
        if ($CommandLine -notmatch $flag) { return $false }
    }
    return $true
}

# Supervised = an ancestor is the production launcher (the HastamaServer task
# runs `cmd.exe /c ...\scripts\<launcher>.bat`).  Parent PIDs are used only as
# supporting evidence - the command line of the launcher is the stable marker.
#
# The launcher file NAME is deliberately not hardcoded: the operator scripts
# under scripts\ are named in Persian, and this file is kept pure ASCII on
# purpose.  Matching any *.bat directly under scripts\ identifies the scheduled
# task's cmd.exe wrapper without depending on how that file is called; the port,
# flag and production-log checks above and below carry the real identity.
function Test-Supervised($Chain) {
    foreach ($node in $Chain) {
        if ($node.CommandLine -and ($node.CommandLine -match "(?i)[\\/]scripts[\\/][^\\/\s\u0022]*\.bat")) { return $true }
    }
    return $false
}

function Get-ProductionLogAgeSeconds {
    try {
        if (Test-Path $ProductionLog) {
            return [int]((Get-Date) - (Get-Item $ProductionLog).LastWriteTime).TotalSeconds
        }
    } catch {
        return -1
    }
    return -1
}

# ── health probe ───────────────────────────────────────────────────────────
# System.Net.Http is NOT loaded in Windows PowerShell 5.1 (New-Object
# System.Net.Http.HttpClientHandler fails with "Cannot find type"), so the probe
# uses HttpWebRequest from System.dll, which is always available.  The first
# version of this watchdog hit exactly that and reported a healthy application as
# unhealthy; a broken probe must therefore be reported as PROBE_ERROR and must
# never count towards the restart threshold.
#
# Proxy is disabled per request: this host has a local HTTP proxy configured for
# other tools, and a proxied loopback request must never decide the verdict.
function Invoke-HealthCheck([string]$Url, [int]$TimeoutSeconds) {
    $request = $null
    try {
        [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.SecurityProtocolType]::Tls12
    } catch { }
    try {
        $request = [System.Net.WebRequest]::Create($Url)
        $request.Method = "GET"
        $request.Proxy = $null
        $request.Timeout = $TimeoutSeconds * 1000
        $request.ReadWriteTimeout = $TimeoutSeconds * 1000
        $request.AllowAutoRedirect = $true
        $request.UserAgent = "HastamaWatchdog"
        $response = $request.GetResponse()
        $code = [int]$response.StatusCode
        $response.Close()
        return @{ Ok = ($code -eq 200); Kind = "HTTP"; Detail = "HTTP $code" }
    } catch [System.Net.WebException] {
        $status = $_.Exception.Status
        if ($status -eq [System.Net.WebExceptionStatus]::ProtocolError) {
            $code = 0
            try { if ($_.Exception.Response) { $code = [int]$_.Exception.Response.StatusCode } } catch { }
            return @{ Ok = $false; Kind = "HTTP"; Detail = "HTTP $code" }
        }
        $unreachable = @(
            [System.Net.WebExceptionStatus]::ConnectFailure,
            [System.Net.WebExceptionStatus]::Timeout,
            [System.Net.WebExceptionStatus]::NameResolutionFailure,
            [System.Net.WebExceptionStatus]::ConnectionClosed,
            [System.Net.WebExceptionStatus]::SendFailure,
            [System.Net.WebExceptionStatus]::ReceiveFailure
        )
        if ($unreachable -contains $status) {
            return @{ Ok = $false; Kind = "UNREACHABLE"; Detail = "$status" }
        }
        return @{ Ok = $false; Kind = "PROBE_ERROR"; Detail = "$status :: $($_.Exception.Message)" }
    } catch {
        return @{ Ok = $false; Kind = "PROBE_ERROR"; Detail = "$($_.Exception.GetType().Name): $($_.Exception.Message)" }
    } finally {
        if ($request) { try { $request.Abort() } catch { } }
    }
}

function Write-PublicHealth([string]$Stage) {
    if ($SkipPublicCheck) {
        Write-Log "PUBLIC_HEALTH_NOT_VERIFIED" "$Stage - public check skipped by parameter"
        return
    }
    $result = Invoke-HealthCheck $PublicHealthUrl 10
    if ($result.Ok) {
        Write-Log "PUBLIC_HEALTH_OK" "$Stage - $PublicHealthUrl = $($result.Detail)"
    } elseif ($result.Kind -eq "PROBE_ERROR") {
        Write-Log "PUBLIC_HEALTH_NOT_VERIFIED" "$Stage - $PublicHealthUrl could not be probed: $($result.Detail)"
    } else {
        Write-Log "PUBLIC_HEALTH_FAILED" "$Stage - $PublicHealthUrl -> $($result.Kind) $($result.Detail) (informational: local identity and /health are authoritative)"
    }
}

# ── recovery ───────────────────────────────────────────────────────────────
# Stops every process that carries the Hastama application signature for this
# port.  Callers must have established that the signature matches; this function
# re-checks it per process so it can never kill an unrelated application.
function Stop-HastamaApplicationProcesses([string]$Reason) {
    $stopped = @()
    try {
        $candidates = Get-CimInstance Win32_Process -Filter "Name='python.exe'" -ErrorAction SilentlyContinue |
            Where-Object { Test-IsHastamaApp $_.CommandLine $Port }
    } catch {
        return $stopped
    }
    foreach ($proc in $candidates) {
        Write-Log "UNEXPECTED_PROCESS_STOPPED" ("stopping identified Hastama process: {0} reason={1}" -f (Format-ProcessLine $proc), $Reason)
        try {
            Stop-Process -Id $proc.ProcessId -Force -ErrorAction Stop
            $stopped += $proc.ProcessId
        } catch {
            Write-Log "RESTART_FAILED" ("could not stop pid={0}: {1}" -f $proc.ProcessId, $_.Exception.Message)
        }
    }
    return $stopped
}

function Invoke-ProductionRestart([string]$Reason, $State) {
    $now = Get-Date
    $windowStart = $now.AddMinutes(-$RestartWindowMinutes)
    $recent = @()
    foreach ($stamp in @($State.restart_times)) {
        try {
            $parsed = [datetime]::Parse([string]$stamp)
            if ($parsed -gt $windowStart) { $recent += $parsed }
        } catch {
            # ignore unparsable timestamps
        }
    }

    if ($recent.Count -ge $MaxRestartsPerWindow) {
        Write-Log "RESTART_SUPPRESSED" ("{0} restarts in the last {1} minutes (limit {2}); last attempt {3}; reason={4}" -f `
            $recent.Count, $RestartWindowMinutes, $MaxRestartsPerWindow, ($recent | Sort-Object | Select-Object -Last 1), $Reason)
        $State.restart_times = @($recent | ForEach-Object { $_.ToString("o") })
        $State.last_status = "RESTART_SUPPRESSED"
        Save-WatchdogState $State
        return $false
    }

    Write-Log "RESTART_REQUESTED" ("reason={0}; task={1}; restarts_in_window={2}" -f $Reason, $TaskName, $recent.Count)

    # Free the port first: a supervised process that is hung or that was started
    # outside supervision would otherwise make the new instance fail with
    # Errno 10048 while the task reports success.
    [void](Stop-HastamaApplicationProcesses "restart: $Reason")
    try { & schtasks /End /TN $TaskName 2>&1 | Out-Null } catch { }

    try {
        $run = & schtasks /Run /TN $TaskName 2>&1
        Write-Log "RESTARTED" ("task={0} triggered: {1}" -f $TaskName, (($run | Out-String).Trim()))
    } catch {
        Write-Log "RESTART_FAILED" ("could not trigger task {0}: {1}" -f $TaskName, $_.Exception.Message)
    }

    $recent += $now
    $State.restart_times = @($recent | ForEach-Object { $_.ToString("o") })

    # Wait for the supervised instance to come back and answer /health.
    $deadline = (Get-Date).AddSeconds($StartupWaitSeconds)
    $lastDetail = "no listener"
    while ((Get-Date) -lt $deadline) {
        Start-Sleep -Seconds 5
        $pid_ = Get-ListenerPid $Port
        if (-not $pid_) { continue }
        $proc = Get-ProcessNode $pid_
        if (-not $proc) { continue }
        if (-not (Test-IsHastamaApp $proc.CommandLine $Port)) {
            $lastDetail = "foreign owner: $(Format-ProcessLine $proc)"
            continue
        }
        $health = Invoke-HealthCheck $HealthUrl $HealthTimeoutSeconds
        $lastDetail = "health=$($health.Kind) $($health.Detail)"
        if ($health.Ok) {
            $state = @{ unhealthy_streak = 0; last_status = "RECOVERY_CONFIRMED"; restart_times = $State.restart_times }
            Save-WatchdogState $state
            Write-Log "RECOVERY_CONFIRMED" ("supervised instance serving on 127.0.0.1:{0}: {1}" -f $Port, (Format-ProcessLine $proc))
            Write-PublicHealth "after restart"
            return $true
        }
    }

    Write-Log "RESTART_FAILED" ("port did not come back within {0}s ({1}); inspect logs\hastama-autostart.log" -f $StartupWaitSeconds, $lastDetail)
    $State.unhealthy_streak += 1
    $State.last_status = "RESTART_FAILED"
    Save-WatchdogState $State
    return $false
}

# ── main ───────────────────────────────────────────────────────────────────
$state = Get-WatchdogState
$listenerPid = Get-ListenerPid $Port

if (-not $listenerPid) {
    Write-Log "APPLICATION_DOWN" "nothing is listening on 127.0.0.1:$Port"
    [void](Invoke-ProductionRestart "port not listening" $state)
    exit 1
}

$listener = Get-ProcessNode $listenerPid
$chain = Get-AncestorChain $listenerPid

if (-not $listener) {
    # The port is owned by something we cannot inspect (WMI/CIM unavailable).
    # Report it as such - calling it a foreign owner would be a wrong diagnosis,
    # and nothing is touched while the process is unknown.
    Write-Log "PROCESS_LOOKUP_FAILED" ("could not read the process (pid={0}) that owns 127.0.0.1:{1}; no action taken" -f $listenerPid, $Port)
    $state.last_status = "PROCESS_LOOKUP_FAILED"
    Save-WatchdogState $state
    exit 1
}

if (-not (Test-IsHastamaApp $listener.CommandLine $Port)) {
    # Some other application owns the port.  Never kill it, never start a second
    # instance on top of it - report and let a human decide.
    Write-Log "PORT_FOREIGN_OWNER" ("127.0.0.1:{0} is owned by a process that is not the Hastama application: {1}; not touching it" -f $Port, (Format-ProcessLine $listener))
    $state.last_status = "PORT_FOREIGN_OWNER"
    Save-WatchdogState $state
    exit 1
}

$hasFlags = Test-HasProductionFlags $listener.CommandLine $Port
$isSupervised = Test-Supervised $chain
$logAge = Get-ProductionLogAgeSeconds
$writesProductionLog = ($logAge -ge 0) -and ($logAge -le $ProductionLogFreshSeconds)

if (-not ($hasFlags -and ($isSupervised -or $writesProductionLog))) {
    $missing = @()
    if (-not $hasFlags) { $missing += "production flags" }
    if (-not $isSupervised) { $missing += "launcher supervision" }
    if (-not $writesProductionLog) { $missing += "production log writes (age=${logAge}s)" }
    Write-Log "UNEXPECTED_PROCESS" ("127.0.0.1:{0} is served by the Hastama application OUTSIDE supervision; missing: {1}; {2}" -f `
        $Port, ($missing -join ", "), (Format-ProcessLine $listener))
    foreach ($node in $chain) {
        Write-Log "UNEXPECTED_PROCESS" ("  ancestry: {0}" -f (Format-ProcessLine $node))
    }
    [void](Invoke-ProductionRestart "unexpected (unsupervised) Hastama process owned the port" $state)
    exit 1
}

$health = Invoke-HealthCheck $HealthUrl $HealthTimeoutSeconds

if ($health.Kind -eq "PROBE_ERROR") {
    # The watchdog itself is broken (missing type, permission, ...).  Report it
    # and leave the application alone: a probe fault must never restart a
    # healthy production instance.
    Write-Log "HEALTH_PROBE_ERROR" ("could not probe {0}: {1}; restart threshold not affected; {2}" -f `
        $HealthUrl, $health.Detail, (Format-ProcessLine $listener))
    $state.last_status = "HEALTH_PROBE_ERROR"
    Save-WatchdogState $state
    exit 1
}

if ($health.Ok) {
    if ($state.last_status -and $state.last_status -ne "HEALTHY") {
        Write-Log "HEALTHY" ("recovered to healthy state (previous status {0}); {1}" -f $state.last_status, (Format-ProcessLine $listener))
    }
    $state.unhealthy_streak = 0
    $state.last_status = "HEALTHY"
    Save-WatchdogState $state
    exit 0   # healthy: no output, no log growth
}

$state.unhealthy_streak += 1
Write-Log "APPLICATION_UNHEALTHY" ("{0} -> {1} {2}; consecutive failures {3}/{4}; {5}" -f `
    $HealthUrl, $health.Kind, $health.Detail, $state.unhealthy_streak, $UnhealthyThreshold, (Format-ProcessLine $listener))

if ($state.unhealthy_streak -ge $UnhealthyThreshold) {
    [void](Invoke-ProductionRestart ("health check failing {0} times in a row ({1})" -f $state.unhealthy_streak, $health.Detail) $state)
    exit 1
}

$state.last_status = "APPLICATION_UNHEALTHY"
Save-WatchdogState $state
exit 1
