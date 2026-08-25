[CmdletBinding()]
param([string]$NssmPath = "nssm.exe")

$ErrorActionPreference = "Stop"
if (-not (Get-Command $NssmPath -ErrorAction SilentlyContinue)) { throw "NSSM was not found." }

foreach ($name in @("HastamaHttps", "HastamaApi")) {
    if (Get-Service $name -ErrorAction SilentlyContinue) {
        Stop-Service $name -Force -ErrorAction SilentlyContinue
        & $NssmPath remove $name confirm | Out-Null
        Write-Host "Removed $name"
    }
}
