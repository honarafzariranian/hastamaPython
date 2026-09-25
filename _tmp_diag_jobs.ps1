# After a print, inspect queue jobs; also test PDF PrintTo silently
Add-Type -AssemblyName System.Drawing
$ErrorActionPreference = 'Continue'

Write-Output '--- current jobs ---'
try { Get-PrintJob -PrinterName 'EPSON TM-T88III Receipt' -ErrorAction Stop | Select-Object Id, Name, SubmittedTime, DocumentName | Format-List | Out-String | Write-Output } catch { Write-Output ("jobs_err=" + $_.Exception.Message) }

Write-Output '--- printer detail ---'
try { Get-Printer -Name 'EPSON TM-T88III Receipt' | Format-List * | Out-String | Write-Output } catch { Write-Output ("pr_err=" + $_.Exception.Message) }

# forms
Write-Output '--- forms matching label ---'
try { Get-PrinterForm -ErrorAction Stop | Where-Object { $_.Width -ge 280 -and $_.Width -le 330 } | Select-Object Name, Width, Height | Format-Table -AutoSize | Out-String | Write-Output } catch { Write-Output ("form_err=" + $_.Exception.Message) }
