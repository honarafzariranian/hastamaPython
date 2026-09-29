<#
.SYNOPSIS
    Development session manager for Hastama: take the machine, then give it back.

.DESCRIPTION
    Used by the double-clickable launcher `run_hastama_dev.bat` (and by the
    VS Code tasks) so that working on the code never leaves a second instance of
    the application running somewhere.

    The production contract is untouched:

        \HastamaServer   (at boot) -> scripts\run_server.bat -> uvicorn on 5000
        \HastamaWatchdog (every 5 minutes) -> health / identity / restart

    Nothing here edits those files, their triggers or their definitions.  A
    development session only *parks* the two tasks (Disable) for as long as the
    session lives and enables them again on exit, because the watchdog would
    otherwise restart the production instance within five minutes - which is
    exactly the "two instances at once" situation this script exists to remove.

    Actions
        stop-all        close EVERY Hastama instance, on any port (identity
                        checked), clear the task instance, report foreign owners
        hold-tasks      (self-elevating) record the task states, disable both
                        tasks, wait for the console pid, then restore
        await-disable   wait until the tasks are really disabled
        await-restore   wait until the session state says "restored"
        restore         enable the tasks again (+ start production if it was
                        serving before the session)
        status          one-screen report: tasks, port owner, last watchdog state

.NOTES
    A process is only ever stopped when it is *identified*:

      * its command line matches the Hastama application signature (any port), or
      * it is the `cmd.exe` wrapper of scripts\run_server.bat, or
      * it listens on a Hastama port while running THIS repository's
        .venv\Scripts\python.exe (an orphaned reload child).

    A foreign owner of 127.0.0.1:5000 is reported and left alone.  The
    Cloudflare Tunnel, SQL Server and the firewall are never touched.
#>
[CmdletBinding()]
param(
    [ValidateSet('status', 'list', 'stop-all', 'hold-tasks', 'await-disable', 'await-restore', 'restore')]
    [string]$Action = 'status',

    # Console (cmd.exe) pid of the development session: when it exits, the
    # parked tasks are restored.
    [int]$WatchPid = 0,

    [int]$TimeoutSeconds = 20,

    # Set by the script itself when it relaunches elevated.
    [switch]$AutoElevate
)

$ErrorActionPreference = 'Stop'

$RepoRoot       = Split-Path -Parent $PSScriptRoot
$VenvPython     = Join-Path $RepoRoot '.venv\Scripts\python.exe'
$VenvPythonW    = Join-Path $RepoRoot '.venv\Scripts\pythonw.exe'
$LogDir         = Join-Path $RepoRoot 'logs'
$SessionLog     = Join-Path $LogDir 'hastama-dev-session.log'
$StatePath      = Join-Path $LogDir 'hastama-dev-session.json'
$WatchdogState  = Join-Path $LogDir 'hastama-watchdog-state.json'
$ProductionTask = 'HastamaServer'
$WatchdogTask   = 'HastamaWatchdog'
$Port           = 5000
$ProbePorts     = @(5000, 5001, 8000)

# Keep this signature in sync with scripts\watchdog_server.ps1 (Test-IsHastamaApp)
# and scripts\stop_server.ps1 - a divergence would let one script trust a process
# the other refuses to touch (tests\test_production_supervision.py).
$AppSignature = "(?i)(-m\s+uvicorn\s+app\.main:app|uvicorn(\.exe)?\s+app\.main:app)"

$SessionLogMaxBytes = 5242880   # 5 MB, two files at most

# -- logging -----------------------------------------------------------------

function Write-Session([string]$Message) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Write-Host $line
    try {
        if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
        if ((Test-Path $SessionLog) -and ((Get-Item $SessionLog).Length -gt $SessionLogMaxBytes)) {
            if (Test-Path "$SessionLog.1") { Remove-Item "$SessionLog.1" -Force -ErrorAction SilentlyContinue }
            Move-Item -Path $SessionLog -Destination "$SessionLog.1" -Force
        }
        [System.IO.File]::AppendAllText($SessionLog, $line + "`r`n", (New-Object System.Text.UTF8Encoding($false)))
    } catch { }
}

function Write-Section([string]$Title) {
    Write-Host ""
    Write-Host "  $Title"
}

# -- state file --------------------------------------------------------------

function Read-SessionState {
    if (-not (Test-Path $StatePath)) { return $null }
    try { return (Get-Content $StatePath -Raw -Encoding UTF8 | ConvertFrom-Json) } catch { return $null }
}

function Save-SessionState($State) {
    if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
    $json = $State | ConvertTo-Json -Depth 6
    [System.IO.File]::WriteAllText($StatePath, $json, (New-Object System.Text.UTF8Encoding($false)))
}

function New-SessionState {
    return [pscustomobject]@{
        session_started_at        = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
        console_pid               = $WatchPid
        holder_pid                = $null
        port                      = $Port
        tasks_before              = @{}
        tasks_disabled_by_session = $false
        production_was_serving    = $false
        restored_at               = $null
        restore_mode              = $null
        killed                    = @()
        foreign_owners            = @()
    }
}

# -- elevation ---------------------------------------------------------------

function Test-Elevated {
    try {
        $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
        $principal = New-Object Security.Principal.WindowsPrincipal($identity)
        return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    } catch { return $false }
}

# Relaunch this script elevated.  Only the task bookkeeping needs it; the
# application itself always runs unprivileged.
function Start-ElevatedSelf([string[]]$ExtraArguments, [switch]$Wait) {
    $arguments = @('-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', ('"' + $PSCommandPath + '"'))
    $arguments += $ExtraArguments
    $arguments += '-AutoElevate'
    $parameters = @{
        FilePath     = 'powershell.exe'
        ArgumentList = ($arguments -join ' ')
        Verb         = 'RunAs'
        WindowStyle  = 'Hidden'
    }
    if ($Wait) {
        $parameters.Wait = $true
        $parameters.PassThru = $true
    }
    try {
        $process = Start-Process @parameters
        if ($Wait) { return $process.ExitCode }
        return 0
    } catch {
        Write-Session "ELEVATION_REFUSED - could not relaunch elevated: $($_.Exception.Message)"
        return 1
    }
}

# -- task helpers ------------------------------------------------------------

function Get-TaskState([string]$Name) {
    try {
        $output = (& schtasks /Query /TN $Name /FO LIST 2>&1 | Out-String)
    } catch {
        return 'Unknown'
    }
    if ($output -match 'Status:\s+Disabled') { return 'Disabled' }
    if ($output -match 'Status:\s+Running') { return 'Running' }
    if ($output -match 'Status:\s+Ready') { return 'Ready' }
    return 'Unknown'
}

function Set-TaskDisabled([string]$Name, [bool]$Disabled) {
    $verb = '/ENABLE'
    if ($Disabled) { $verb = '/DISABLE' }
    try {
        $output = (& schtasks /Change /TN $Name $verb 2>&1 | Out-String)
        if ($LASTEXITCODE -eq 0) { return $true }
        Write-Session "TASK_CHANGE_FAILED $Name $verb -> $($output.Trim())"
        return $false
    } catch {
        Write-Session "TASK_CHANGE_FAILED $Name $verb -> $($_.Exception.Message)"
        return $false
    }
}

# -- process identity --------------------------------------------------------

function Test-IsHastamaApp([string]$CommandLine) {
    if (-not $CommandLine) { return $false }
    return ($CommandLine -match $AppSignature)
}

function Test-IsOurVenvPython($Process) {
    if (-not $Process) { return $false }
    $exe = $Process.ExecutablePath
    if (-not $exe) { return $false }
    return (@($VenvPython, $VenvPythonW) -contains $exe)
}

# This virtual environment is uv-managed: .venv\Scripts\python.exe is a shim
# that re-executes the base interpreter named in pyvenv.cfg (`home = ...`).  An
# orphaned uvicorn reload worker therefore shows the BASE path, not the venv one.
function Get-VenvBasePythonHome {
    $cfg = Join-Path $RepoRoot '.venv\pyvenv.cfg'
    if (-not (Test-Path $cfg)) { return '' }
    try {
        foreach ($line in (Get-Content $cfg -Encoding UTF8)) {
            if ($line -match '^\s*home\s*=\s*(.+?)\s*$') { return $Matches[1] }
        }
    } catch { }
    return ''
}

function Test-IsUnderPath([string]$Path, [string]$Parent) {
    if (-not $Path -or -not $Parent) { return $false }
    return $Path.StartsWith($Parent.TrimEnd('\'), [System.StringComparison]::OrdinalIgnoreCase)
}

function Test-IsOurReloadOrphan($Process) {
    if (-not $Process) { return $false }
    if (Test-IsOurVenvPython $Process) { return $true }
    if (-not $Process.CommandLine) { return $false }
    if ($Process.CommandLine -notmatch '(?i)multiprocessing') { return $false }
    return (Test-IsUnderPath $Process.ExecutablePath (Get-VenvBasePythonHome))
}

function Format-ProcessLine($Process) {
    if (-not $Process) { return '(none)' }
    $cmd = $Process.CommandLine
    if (-not $cmd) { $cmd = '' }
    $cmd = ($cmd -replace '\s+', ' ').Trim()
    return ("pid={0} name={1} exe={2} cmd={3}" -f $Process.ProcessId, $Process.Name, $Process.ExecutablePath, $cmd)
}

function Get-ListenerPid([int]$ProbePort) {
    try {
        $connection = Get-NetTCPConnection -LocalPort $ProbePort -State Listen -ErrorAction SilentlyContinue |
            Select-Object -First 1
        if ($connection) { return [int]$connection.OwningProcess }
        return 0
    } catch { }
    try {
        $match = & netstat -ano | Select-String -Pattern (":{0}\s" -f $ProbePort) | Select-String 'LISTENING' |
            Select-Object -First 1
        if ($match) {
            $parts = @(($match.ToString() -split '\s+') | Where-Object { $_ -ne '' })
            if ($parts.Count -ge 5) { return [int]$parts[-1] }
        }
    } catch { }
    return 0
}

function Get-ProcessNode([int]$ProcessId) {
    try {
        return (Get-CimInstance Win32_Process -Filter "ProcessId=$ProcessId" -ErrorAction SilentlyContinue)
    } catch {
        return $null
    }
}

# Every Hastama instance, on ANY port (no port filter on purpose: the point is
# to leave no instance behind, not only the one on 5000).
function Get-HastamaProcesses {
    $found = @()
    try {
        $all = Get-CimInstance Win32_Process -ErrorAction Stop
    } catch {
        return $found
    }
    foreach ($process in $all) {
        $commandLine = $process.CommandLine
        if (-not $commandLine) { continue }
        if (Test-IsHastamaApp $commandLine) { $found += $process; continue }
        # The production launcher wrapper (`cmd.exe /c ...run_server.bat`).
        if ($process.Name -eq 'cmd.exe' -and $commandLine -match '(?i)run_server\.bat') { $found += $process; continue }
    }
    return $found
}

# What is listening on the ports this project uses, and what is it?
#   hastama    - the application (any of its processes)
#   venv-child - an orphan of THIS repository's virtual environment
#   foreign    - somebody else; never touched
function Get-ListenerVerdicts {
    $verdicts = @()
    foreach ($probe in $ProbePorts) {
        $listenerPid = Get-ListenerPid $probe
        if (-not $listenerPid) { continue }
        $listener = Get-ProcessNode $listenerPid
        $kind = 'foreign'
        if (Test-IsHastamaApp $listener.CommandLine) { $kind = 'hastama' }
        elseif (Test-IsOurReloadOrphan $listener) { $kind = 'venv-child' }
        $verdicts += [pscustomobject]@{ Port = $probe; Process = $listener; Kind = $kind }
    }
    return $verdicts
}

# Everything that this session would stop, with the reason, and nothing else.
function Get-StopPlan {
    $plan = @()
    foreach ($process in Get-HastamaProcesses) {
        $reason = 'application signature (any port)'
        if ($process.Name -eq 'cmd.exe') { $reason = 'production launcher wrapper' }
        $plan += [pscustomobject]@{ Process = $process; Reason = $reason }
    }
    $known = @($plan | ForEach-Object { $_.Process.ProcessId })
    foreach ($verdict in Get-ListenerVerdicts) {
        if ($verdict.Kind -eq 'foreign') { continue }
        if ($known -contains $verdict.Process.ProcessId) { continue }
        $reason = 'application signature on port {0}' -f $verdict.Port
        if ($verdict.Kind -eq 'venv-child') {
            $reason = "orphan of this repository's virtual environment on port {0}" -f $verdict.Port
        }
        $plan += [pscustomobject]@{ Process = $verdict.Process; Reason = $reason }
    }
    return $plan
}

function Stop-ProcessTree([int]$ProcessId, [string]$Reason) {
    try {
        $output = (& taskkill /F /T /PID $ProcessId 2>&1 | Out-String)
        Write-Session ("STOPPED {0} ({1}) -> {2}" -f $ProcessId, $Reason, ($output -replace '\s+', ' ').Trim())
        return $true
    } catch {
        Write-Session ("STOP_FAILED {0} ({1}) -> {2}" -f $ProcessId, $Reason, $_.Exception.Message)
        return $false
    }
}

function Wait-PortFree([int]$ProbePort, [int]$Seconds) {
    for ($attempt = 0; $attempt -lt ($Seconds * 2); $attempt++) {
        if ((Get-ListenerPid $ProbePort) -eq 0) { return $true }
        Start-Sleep -Milliseconds 500
    }
    return ((Get-ListenerPid $ProbePort) -eq 0)
}

# -- actions -----------------------------------------------------------------

function Invoke-StopAll {
    # Keep whatever session bookkeeping already exists (which tasks were enabled,
    # whether production was serving): a manual stop in the middle of a session
    # must not break the restore that happens when that session ends.
    $state = Read-SessionState
    if (-not $state) { $state = New-SessionState }
    if (-not $state.session_started_at) { $state.session_started_at = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss') }

    Write-Section "Closing every running Hastama instance (any port)."
    $state.killed = @()
    $state.foreign_owners = @()

    foreach ($target in Get-StopPlan) {
        if (Stop-ProcessTree $target.Process.ProcessId $target.Reason) {
            $state.killed += (Format-ProcessLine $target.Process)
        }
    }

    # Clear the task instance so a later `schtasks /Run` is not refused by
    # MultipleInstancesPolicy=IgnoreNew while the dead wrapper still counts.
    try { & schtasks /End /TN $ProductionTask 2>&1 | Out-Null } catch { }

    foreach ($verdict in Get-ListenerVerdicts) {
        if ($verdict.Kind -ne 'foreign') { continue }
        $line = "port={0} {1}" -f $verdict.Port, (Format-ProcessLine $verdict.Process)
        $state.foreign_owners += $line
        Write-Session ("FOREIGN_OWNER left untouched: {0}" -f $line)
    }

    Save-SessionState $state

    $free = Wait-PortFree $Port 10
    if ($state.killed.Count -eq 0) {
        Write-Host "  Nothing was running."
    } else {
        Write-Host ("  Stopped {0} process(es):" -f $state.killed.Count)
        foreach ($line in $state.killed) { Write-Host "    $line" }
    }

    if (-not $free) {
        $owner = Get-ProcessNode (Get-ListenerPid $Port)
        Write-Host ""
        Write-Host "  [ABORT] 127.0.0.1:$Port is still owned by a process that is not Hastama:"
        Write-Host ("    {0}" -f (Format-ProcessLine $owner))
        Write-Host "  Close it from its own console/service, then run this again."
        return 2
    }

    Write-Host "  127.0.0.1:$Port is free."
    return 0
}

function Repair-StaleSession {
    # A previous session that was killed (reboot, closed window) may have left the
    # autostart parked.  Put it back before taking the machine again.
    $state = Read-SessionState
    if (-not $state) { return }
    if (-not $state.tasks_disabled_by_session) { return }
    if ($state.restored_at) { return }
    $holderAlive = $false
    if ($state.holder_pid) { $holderAlive = [bool](Get-Process -Id $state.holder_pid -ErrorAction SilentlyContinue) }
    if ($holderAlive) { return }
    Write-Session "STALE_SESSION - a previous session never restored the autostart tasks; doing it now."
    Invoke-Restore -state $state
}

function Invoke-HoldTasks {
    if (-not (Test-Elevated)) {
        if (-not $AutoElevate) {
            Write-Session "Requesting administrator rights once to park HastamaServer + HastamaWatchdog for this session..."
            [void](Start-ElevatedSelf @('-Action', 'hold-tasks', '-WatchPid', "$WatchPid") -Wait:$false)
            return 0
        }
        Write-Session "ELEVATION_REFUSED - without administrator rights the tasks cannot be parked."
        return 1
    }

    Repair-StaleSession

    $state = Read-SessionState
    if (-not $state) { $state = New-SessionState }
    $state.session_started_at = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
    $state.console_pid = $WatchPid
    $state.holder_pid = $PID
    $state.restored_at = $null
    $state.restore_mode = $null
    $state.tasks_before = @{
        HastamaServer   = (Get-TaskState $ProductionTask)
        HastamaWatchdog = (Get-TaskState $WatchdogTask)
    }

    $listenerPid = Get-ListenerPid $Port
    $listener = Get-ProcessNode $listenerPid
    $state.production_was_serving = (Test-IsHastamaApp $listener.CommandLine)

    $ok = $true
    foreach ($task in @($ProductionTask, $WatchdogTask)) {
        if (-not (Set-TaskDisabled $task $true)) { $ok = $false }
    }
    $state.tasks_disabled_by_session = $ok
    Save-SessionState $state

    if ($ok) {
        Write-Session ("SESSION_HOLDING - {0} + {1} parked until this session ends (console pid {2})." -f $ProductionTask, $WatchdogTask, $WatchPid)
    } else {
        Write-Session "SESSION_HOLDING_FAILED - the tasks could not be parked; the watchdog may restart production."
    }

    if ($WatchPid -le 0) { return 0 }

    # Hold the machine for as long as the development console lives.
    while ($true) {
        if (-not (Get-Process -Id $WatchPid -ErrorAction SilentlyContinue)) { break }
        Start-Sleep -Seconds 2
    }

    Write-Session "SESSION_ENDED - console pid $WatchPid is gone; restoring the autostart tasks."
    Invoke-Restore -state (Read-SessionState)
    return 0
}

function Invoke-Restore($state) {
    if (-not $state) {
        Write-Host "  No development session state found; nothing to restore."
        return 0
    }
    if ($state.restored_at) {
        Write-Host "  Autostart already restored at $($state.restored_at)."
        return 0
    }
    if (-not (Test-Elevated)) {
        if (-not $AutoElevate) {
            Write-Session "Requesting administrator rights once to restore HastamaServer + HastamaWatchdog..."
            [void](Start-ElevatedSelf @('-Action', 'restore') -Wait:$true)
            return 0
        }
        Write-Session "ELEVATION_REFUSED - could not restore the tasks; run scripts\enable_autostart.bat as administrator."
        return 1
    }

    $restoreProduction = $false
    foreach ($task in @($ProductionTask, $WatchdogTask)) {
        $before = $null
        if ($state.tasks_before) { $before = $state.tasks_before.$task }
        $enable = ($before -ne 'Disabled')
        if ($enable) { [void](Set-TaskDisabled $task $false) }
    }

    if ($state.production_was_serving) {
        if (Wait-PortFree $Port 20) {
            try {
                $output = (& schtasks /Run /TN $ProductionTask 2>&1 | Out-String)
                Write-Session ("PRODUCTION_STARTED - {0} triggered: {1}" -f $ProductionTask, ($output -replace '\s+', ' ').Trim())
                $restoreProduction = $true
            } catch {
                Write-Session "PRODUCTION_START_FAILED - $($_.Exception.Message)"
            }
        } else {
            Write-Session "PRODUCTION_START_SKIPPED - 127.0.0.1:$Port is still busy."
        }
    }

    $state.restored_at = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
    if ($restoreProduction) { $state.restore_mode = 'tasks-enabled + production started' }
    else { $state.restore_mode = 'tasks-enabled' }
    Save-SessionState $state
    Write-Session "SESSION_RESTORED - autostart back to normal ($($state.restore_mode))."
    return 0
}

function Invoke-AwaitDisabled {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        $state = Read-SessionState
        if ($state -and $state.tasks_disabled_by_session) {
            Write-Host "  Autostart parked for this session."
            return 0
        }
        Start-Sleep -Milliseconds 500
    }
    Write-Host ""
    Write-Host "  [WARNING] The autostart tasks are still enabled (administrator rights were"
    Write-Host "            probably declined).  The watchdog can bring the production instance"
    Write-Host "            back on 127.0.0.1:$Port within five minutes."
    Write-Host "            To park them by hand: scripts\disable_autostart.bat"
    return 1
}

function Invoke-AwaitRestore {
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        $state = Read-SessionState
        if ($state -and $state.restored_at) {
            Write-Host "  Autostart restored ($($state.restore_mode))."
            return 0
        }
        if (-not $state -or (-not $state.tasks_disabled_by_session)) { return 0 }
        Start-Sleep -Milliseconds 500
    }
    return 1
}

function Invoke-List {
    Write-Section "Dry run - this is what a development session would stop:"
    $plan = @(Get-StopPlan)
    if ($plan.Count -eq 0) { Write-Host "  (nothing)" }
    foreach ($target in $plan) {
        Write-Host ("  - {0} [{1}]" -f (Format-ProcessLine $target.Process), $target.Reason)
    }
    Write-Section "Left alone:"
    $foreign = @(Get-ListenerVerdicts | Where-Object { $_.Kind -eq 'foreign' })
    if ($foreign.Count -eq 0) { Write-Host "  (nothing foreign on $($ProbePorts -join ', ') )" }
    foreach ($verdict in $foreign) {
        Write-Host ("  - {0}" -f (Format-ProcessLine $verdict.Process))
    }
    return 0
}

function Invoke-Status {
    $state = Read-SessionState
    Write-Section "Hastama development session"
    Write-Host ("  {0,-18}: {1}" -f 'HastamaServer', (Get-TaskState $ProductionTask))
    Write-Host ("  {0,-18}: {1}" -f 'HastamaWatchdog', (Get-TaskState $WatchdogTask))

    $listenerPid = Get-ListenerPid $Port
    if (-not $listenerPid) {
        Write-Host ("  {0,-18}: nothing is listening" -f "127.0.0.1:$Port")
    } else {
        $listener = Get-ProcessNode $listenerPid
        $kind = 'FOREIGN (not Hastama)'
        if (Test-IsHastamaApp $listener.CommandLine) { $kind = 'Hastama application' }
        elseif (Test-IsOurReloadOrphan $listener) { $kind = 'this repository''s virtual environment' }
        Write-Host ("  {0,-18}: {1}" -f "127.0.0.1:$Port", $kind)
        Write-Host ("      {0}" -f (Format-ProcessLine $listener))
    }

    if ($state) {
        Write-Host ("  {0,-18}: {1}" -f 'session started', $state.session_started_at)
        Write-Host ("  {0,-18}: {1}" -f 'console pid', $state.console_pid)
        Write-Host ("  {0,-18}: {1}" -f 'tasks parked', $state.tasks_disabled_by_session)
        Write-Host ("  {0,-18}: {1}" -f 'restored at', $state.restored_at)
        if ($state.killed) { Write-Host ("  {0,-18}: {1}" -f 'stopped', $state.killed.Count) }
        if ($state.foreign_owners) {
            foreach ($owner in $state.foreign_owners) { Write-Host ("  {0,-18}: {1}" -f 'foreign owner', $owner) }
        }
    }

    if (Test-Path $WatchdogState) {
        try {
            $watchdog = Get-Content $WatchdogState -Raw -Encoding UTF8 | ConvertFrom-Json
            Write-Host ("  {0,-18}: {1}" -f 'watchdog last', $watchdog.last_status)
        } catch { }
    }
}

# -- entry point -------------------------------------------------------------

if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }

switch ($Action) {
    'list'          { exit (Invoke-List) }
    'stop-all'      { exit (Invoke-StopAll) }
    'hold-tasks'    { exit (Invoke-HoldTasks) }
    'await-disable' { exit (Invoke-AwaitDisabled) }
    'await-restore' { exit (Invoke-AwaitRestore) }
    'restore'       { exit (Invoke-Restore (Read-SessionState)) }
    default         { Invoke-Status; exit 0 }
}
