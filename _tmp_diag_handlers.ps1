try {
  Import-Module PrintManagement -ErrorAction Stop
  Write-Output 'module_ok'
  Get-Command Add-PrinterForm -ErrorAction SilentlyContinue | ForEach-Object { Write-Output $_.Name }
} catch {
  Write-Output ('module_err=' + $_.Exception.Message)
}
Write-Output '--- chrome ---'
Write-Output (Test-Path 'C:\Program Files\Google\Chrome\Application\chrome.exe')
Write-Output '--- pdf shell ---'
foreach ($k in @(
  'HKCR:\ChromePDF\shell',
  'HKCR:\MSEdgePDF\shell',
  'HKCU:\Software\Classes\ChromePDF\shell',
  'HKCU:\Software\Microsoft\Windows\CurrentVersion\Explorer\FileExts\.pdf\UserChoice'
)) {
  Write-Output ("key=" + $k + ' exists=' + (Test-Path $k))
  if (Test-Path $k) {
    Get-ChildItem $k -ErrorAction SilentlyContinue | ForEach-Object { Write-Output ('  sub=' + $_.PSChildName) }
    if ($k -like '*UserChoice*') {
      Get-ItemProperty $k | ForEach-Object { Write-Output ('  prog=' + $_.ProgId) }
    }
  }
}
# PrintTo verbs
foreach ($k in @('HKCR:\ChromePDF\shell\PrintTo','HKCR:\MSEdgePDF\shell\PrintTo','HKCU:\Software\Classes\ChromePDF\shell\PrintTo')) {
  Write-Output ("printto=" + $k + ' exists=' + (Test-Path $k))
}
