<#
.SYNOPSIS
    The one-time Windows Firewall step for the optional LAN listener of Hastama.

.DESCRIPTION
    Hastama binds 127.0.0.1:5000 only, and inbound TCP 5000 is blocked on
    purpose: "Hastama - Block Uvicorn 5000 (Inbound)".  Windows gives a Block
    rule precedence over an Allow rule, so a permitted packet is not enough -
    the block rule has to go.  That is the *only* thing this script changes,
    and it is the only step of LAN access mode that needs an administrator.

    What it does:

        enable   remember + disable the Hastama block rules for port 5000,
                 then add "Hastama - Allow Uvicorn 5000 (LAN)" scoped to the
                 local subnet (RemoteAddress LocalSubnet, profiles Domain and
                 Private).  Nothing is opened to the Internet and no rule that
                 does not belong to Hastama is touched.
        disable  remove that allow rule and put the remembered block rules back.
        status   read-only report (no administrator needed).

    The in-app switch (master-admin -> system settings) stays the effective
    control: while the LAN listener is off, nothing is bound on the LAN address,
    so an allowed packet is answered with a reset.

    The state of the rules this script changed is kept in
    logs\lan-access-firewall.json so that "disable" is an exact undo.

.PARAMETER Action
    enable | disable | status   (default: status)

.EXAMPLE
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\lan_access_firewall.ps1 -Action enable
    powershell -NoProfile -ExecutionPolicy Bypass -File scripts\lan_access_firewall.ps1 -Action status

.NOTES
    Windows PowerShell 5.1 reads a BOM-less .ps1 file as ANSI, so this file is
    pure ASCII on purpose.  The Cloudflare Tunnel, SQL Server and every other
    firewall rule are never modified.
#>
[CmdletBinding()]
param(
    [ValidateSet('enable', 'disable', 'status')]
    [string]$Action = 'status',

    # Set by the script itself when it relaunches elevated.
    [switch]$AutoElevate
)

$ErrorActionPreference = 'Stop'

$RepoRoot      = Split-Path -Parent $PSScriptRoot
$LogDir        = Join-Path $RepoRoot 'logs'
$LogPath       = Join-Path $LogDir 'lan-access-firewall.log'
$StatePath     = Join-Path $LogDir 'lan-access-firewall.json'
$AllowRuleName = 'Hastama - Allow Uvicorn 5000 (LAN)'
$RuleGroup     = 'Hastama LAN access'
$AppPort       = 5000

# -- logging -----------------------------------------------------------------

function Write-Step([string]$Message) {
    $line = "[{0}] {1}" -f (Get-Date -Format 'yyyy-MM-dd HH:mm:ss'), $Message
    Write-Host $line
    try {
        if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
        [System.IO.File]::AppendAllText($LogPath, $line + "`r`n", (New-Object System.Text.UTF8Encoding($false)))
    } catch { }
}

function Test-Administrator {
    try {
        $identity = [Security.Principal.WindowsIdentity]::GetCurrent()
        $principal = New-Object Security.Principal.WindowsPrincipal($identity)
        return $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    } catch { return $false }
}

# -- rule discovery ----------------------------------------------------------

# Only rules that belong to Hastama *and* cover port 5000 are ever considered.
function Get-HastamaBlockRules {
    $rules = @()
    $candidates = Get-NetFirewallRule -ErrorAction SilentlyContinue |
        Where-Object {
            $_.DisplayName -like '*astama*' -and
            $_.Direction -eq 'Inbound' -and
            $_.Action -eq 'Block'
        }
    foreach ($rule in $candidates) {
        $ports = (Get-NetFirewallPortFilter -AssociatedNetFirewallRule $rule).LocalPort
        if (@($ports) -contains "$AppPort") { $rules += $rule }
    }
    return $rules
}

function Get-AllowRule {
    return Get-NetFirewallRule -DisplayName $AllowRuleName -ErrorAction SilentlyContinue
}

function Read-FirewallState {
    if (-not (Test-Path $StatePath)) { return $null }
    try { return (Get-Content $StatePath -Raw -Encoding UTF8 | ConvertFrom-Json) } catch { return $null }
}

function Save-FirewallState($Names) {
    if (-not (Test-Path $LogDir)) { New-Item -ItemType Directory -Path $LogDir -Force | Out-Null }
    $state = [pscustomobject]@{
        changed_at          = (Get-Date -Format 'yyyy-MM-dd HH:mm:ss')
        app_port            = $AppPort
        allow_rule          = $AllowRuleName
        disabled_block_rules = @($Names)
        by                  = $env:USERNAME
    }
    [System.IO.File]::WriteAllText($StatePath, ($state | ConvertTo-Json -Depth 5), (New-Object System.Text.UTF8Encoding($false)))
}

function Show-AllowRuleScope {
    $rule = Get-AllowRule
    if (-not $rule) {
        Write-Host '  Allow rule: not present'
        return
    }
    $ports = (Get-NetFirewallPortFilter -AssociatedNetFirewallRule $rule).LocalPort
    $remote = (Get-NetFirewallAddressFilter -AssociatedNetFirewallRule $rule).RemoteAddress
    Write-Host ("  Allow rule: {0}  [enabled={1}, action={2}, protocol={3}, local port={4}, remote address={5}, profile={6}]" -f `
        $rule.DisplayName, $rule.Enabled, $rule.Action, $rule.Protocol, (@($ports) -join ','), (@($remote) -join ','), $rule.Profile)
}

# -- actions -----------------------------------------------------------------

function Invoke-Enable {
    Write-Step "Enabling LAN access on inbound TCP $AppPort (local subnet only)."

    $blockRules = Get-HastamaBlockRules
    $disabled = @()
    foreach ($rule in $blockRules) {
        if ("$($rule.Enabled)" -eq 'True') {
            Set-NetFirewallRule -Name $rule.Name -Enabled False
            $disabled += $rule.DisplayName
            Write-Step "  disabled block rule: $($rule.DisplayName)"
        } else {
            Write-Step "  block rule already disabled: $($rule.DisplayName)"
        }
    }
    if ($disabled.Count -gt 0) { Save-FirewallState $disabled }

    $existing = Get-AllowRule
    if ($existing) {
        Remove-NetFirewallRule -Name $existing.Name
        Write-Step "  replaced existing allow rule: $($existing.DisplayName)"
    }

    New-NetFirewallRule `
        -DisplayName $AllowRuleName `
        -Group $RuleGroup `
        -Description 'Hastama LAN access mode: the toggled fallback listener on the laboratory network. Internet access stays closed.' `
        -Direction Inbound `
        -Action Allow `
        -Protocol TCP `
        -LocalPort $AppPort `
        -RemoteAddress LocalSubnet `
        -Profile Domain,Private `
        -Enabled True | Out-Null

    Write-Step "  created allow rule: $AllowRuleName (RemoteAddress=LocalSubnet)"
    Write-Host ''
    Write-Host '  Done. Now switch the LAN listener on in master-admin -> system settings.'
    Write-Host '  To undo this firewall change: -Action disable'
}

function Invoke-Disable {
    Write-Step "Closing inbound TCP $AppPort on the LAN."

    $allow = Get-AllowRule
    if ($allow) {
        Remove-NetFirewallRule -Name $allow.Name
        Write-Step "  removed allow rule: $($allow.DisplayName)"
    } else {
        Write-Step '  allow rule was not present'
    }

    $state = Read-FirewallState
    if ($state -and $state.disabled_block_rules) {
        foreach ($name in $state.disabled_block_rules) {
            $rule = Get-NetFirewallRule -DisplayName $name -ErrorAction SilentlyContinue
            if ($rule) {
                Set-NetFirewallRule -Name $rule.Name -Enabled True
                Write-Step "  re-enabled block rule: $name"
            } else {
                Write-Step "  block rule no longer exists: $name"
            }
        }
        Remove-Item -Path $StatePath -Force -ErrorAction SilentlyContinue
    } else {
        Write-Step '  no remembered block rules: re-enable them by hand if needed'
    }

    Write-Host ''
    Write-Host '  Done. Switch the LAN listener off in master-admin -> system settings too.'
}

function Invoke-Status {
    Write-Host ''
    Write-Host '  Hastama LAN access - firewall report'
    Write-Host ''
    Show-AllowRuleScope
    $blockRules = Get-HastamaBlockRules
    if ($blockRules.Count -eq 0) {
        Write-Host '  Block rules for port 5000: none'
    } else {
        foreach ($rule in $blockRules) {
            Write-Host ("  Block rule: {0}  [enabled={1}]" -f $rule.DisplayName, $rule.Enabled)
        }
    }
    $state = Read-FirewallState
    if ($state) {
        Write-Host ("  Last enable: {0} by {1}" -f $state.changed_at, $state.by)
    }
    Write-Host ''
    Write-Host '  Reminder: the in-app switch decides whether anything actually listens.'
}

# -- main --------------------------------------------------------------------

if (-not (Get-Command Get-NetFirewallRule -ErrorAction SilentlyContinue)) {
    Write-Host 'This script needs the NetSecurity module (Windows 8 / Server 2012 or newer).'
    exit 1
}

if ($Action -ne 'status' -and -not (Test-Administrator)) {
    if ($AutoElevate) {
        Write-Host 'Administrator rights are required.'
        exit 1
    }
    Write-Host 'Administrator rights are required for the firewall change. Opening a UAC prompt...'
    $arguments = @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass',
        '-File', "`"$PSCommandPath`"",
        '-Action', $Action, '-AutoElevate'
    )
    $process = Start-Process -FilePath 'powershell.exe' -ArgumentList $arguments -Verb RunAs -PassThru
    $process.WaitForExit()
    exit $process.ExitCode
}

switch ($Action) {
    'enable'  { Invoke-Enable }
    'disable' { Invoke-Disable }
    default   { Invoke-Status }
}

if ($AutoElevate) {
    Write-Host ''
    Read-Host 'Press Enter to close this window' | Out-Null
}
