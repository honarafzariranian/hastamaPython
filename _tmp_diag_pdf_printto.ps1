# Test: Edge PDF (75x81) → PrintTo Epson via Chrome association; log only
$pdf = Join-Path $env:TEMP 'hastama-size-check.pdf'
if (-not (Test-Path $pdf)) { Write-Output 'missing pdf'; exit 1 }
$printer = 'EPSON TM-T88III Receipt'
Write-Output ("pdf_size=" + (Get-Item $pdf).Length)
try {
  $sw = [Diagnostics.Stopwatch]::StartNew()
  Start-Process -FilePath $pdf -Verb PrintTo -ArgumentList $printer -Wait
  $sw.Stop()
  Write-Output ("printto_ok ms=" + $sw.ElapsedMilliseconds)
} catch {
  Write-Output ("printto_err=" + $_.Exception.Message)
}
try {
  Get-PrintJob -PrinterName $printer -ErrorAction Stop | Select-Object Id, DocumentName, SubmittedTime | Format-Table -AutoSize | Out-String | Write-Output
} catch { Write-Output ("jobs=" + $_.Exception.Message) }
