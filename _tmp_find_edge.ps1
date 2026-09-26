$paths = @(
  'C:\Program Files (x86)\Microsoft\Edge\Application\msedge.exe',
  'C:\Program Files\Microsoft\Edge\Application\msedge.exe'
)
$edge = $paths | Where-Object { Test-Path $_ } | Select-Object -First 1
if (-not $edge) {
  $cmd = Get-Command msedge -ErrorAction SilentlyContinue
  if ($cmd) { $edge = $cmd.Source }
}
Write-Output "EDGE=$edge"
if ($edge) {
  & $edge --version 2>$null
}
