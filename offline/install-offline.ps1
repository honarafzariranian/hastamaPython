param(
    [string]$Python = "python"
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent $PSScriptRoot
$Wheelhouse = Join-Path $PSScriptRoot "wheels"
$Requirements = Join-Path $PSScriptRoot "requirements-runtime.txt"

if (-not (Test-Path $Wheelhouse)) {
    throw "Offline wheelhouse not found: $Wheelhouse"
}
if (-not (Test-Path $Requirements)) {
    throw "Offline requirements file not found: $Requirements"
}

& $Python --version
if ($LASTEXITCODE -ne 0) {
    throw "Python executable could not be started: $Python"
}

& $Python -m pip install `
    --no-index `
    --find-links $Wheelhouse `
    --requirement $Requirements
if ($LASTEXITCODE -ne 0) {
    throw "Offline dependency installation failed."
}

Push-Location $Root
try {
    & $Python -m pip install `
        --no-index `
        --find-links $Wheelhouse `
        --no-deps `
        --no-build-isolation `
        --editable .
    if ($LASTEXITCODE -ne 0) {
        throw "Offline project installation failed."
    }
}
finally {
    Pop-Location
}

Write-Host "Offline installation completed. No package index was contacted."
