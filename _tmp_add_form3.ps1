# Try Add-PrinterForm if module available; else try pnputil / other
try {
  Import-Module PrintManagement -ErrorAction Stop
  $forms = Get-PrinterForm -ErrorAction Stop | Where-Object { $_.Width -ge 280 -and $_.Width -le 330 }
  Write-Output 'forms_280_330:'
  $forms | ForEach-Object { Write-Output ("  " + $_.Name + ' ' + $_.Width + 'x' + $_.Height) }
  try {
    Add-PrinterForm -Name 'Hastama 75x81' -PrinterName 'EPSON TM-T88III Receipt' -Width 295 -Height 319 -ErrorAction Stop
    Write-Output 'add_form_ok'
  } catch {
    Write-Output ('add_form_err=' + $_.Exception.Message)
  }
} catch {
  Write-Output ('module_err=' + $_.Exception.Message)
}
