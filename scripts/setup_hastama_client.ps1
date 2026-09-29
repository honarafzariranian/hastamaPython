[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string]$ServerIp,
    [string]$RootCertificatePath = ""
)

$ErrorActionPreference = "Stop"
$hostname = "hastama.local"
$hostsPath = Join-Path $env:SystemRoot "System32\drivers\etc\hosts"

if (-not ([System.Net.IPAddress]::TryParse($ServerIp, [ref]$null))) {
    throw "ServerIp must be a valid IPv4 address."
}

$lines = @(Get-Content -LiteralPath $hostsPath -ErrorAction Stop)
$filtered = $lines | Where-Object {
    $_ -notmatch "^\s*#?\s*\S+\s+$([regex]::Escape($hostname))(\s|$)"
}
Set-Content -LiteralPath $hostsPath -Value (@($filtered) + "$ServerIp`t$hostname") -Encoding ascii

if ($RootCertificatePath) {
    if (-not (Test-Path -LiteralPath $RootCertificatePath -PathType Leaf)) {
        throw "Certificate file not found: $RootCertificatePath"
    }
    Import-Certificate -FilePath $RootCertificatePath -CertStoreLocation Cert:\LocalMachine\Root | Out-Null
}

Clear-DnsClientCache
$resolved = Resolve-DnsName $hostname -Type A -ErrorAction Stop | Where-Object Type -eq A | Select-Object -First 1
if ($resolved.IPAddress -ne $ServerIp) {
    throw "Hostname resolved to $($resolved.IPAddress), expected $ServerIp."
}

$response = Invoke-WebRequest -Uri "https://$hostname/" -UseBasicParsing -TimeoutSec 15
if ($response.StatusCode -lt 200 -or $response.StatusCode -ge 500) {
    throw "HTTPS returned status $($response.StatusCode)."
}
Write-Host "[OK] $hostname resolves to $ServerIp"
Write-Host "[OK] HTTPS is reachable"
Write-Host "If Chrome still reports an untrusted certificate, import the matching Caddy root CA and retry."
