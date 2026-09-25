# Print PNG to Epson with PageBounds logging only (no need to see paper)
Add-Type -AssemblyName System.Drawing
$png = Join-Path $env:TEMP 'hastama-size-check.png'
$printer = 'EPSON TM-T88III Receipt'
$log = Join-Path $env:TEMP 'hastama-print-log.txt'
if (Test-Path $log) { Remove-Item $log -Force }

$doc = New-Object System.Drawing.Printing.PrintDocument
$doc.PrinterSettings.PrinterName = $printer
if (-not $doc.PrinterSettings.IsValid) { 'invalid'; exit 1 }

# Match production script: custom paper
$targetW = [int]([math]::Round(75 / 25.4 * 100))
$targetH = [int]([math]::Round(81 / 25.4 * 100))
$custom = New-Object System.Drawing.Printing.PaperSize('Hastama Label', $targetW, $targetH)
$doc.DefaultPageSettings.PaperSize = $custom
$cur = $doc.DefaultPageSettings.PaperSize
"paper_applied=$($cur.PaperName) $($cur.Width)x$($cur.Height)" | Out-File $log -Append
"default_after=$($doc.PrinterSettings.DefaultPageSettings.PaperSize.PaperName) $($doc.PrinterSettings.DefaultPageSettings.PaperSize.Width)x$($doc.PrinterSettings.DefaultPageSettings.PaperSize.Height)" | Out-File $log -Append

$doc.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins(0,0,0,0)
$doc.DefaultPageSettings.Landscape = $false
$doc.PrinterSettings.Copies = 1

$doc.add_PrintPage({
  param($sender, $e)
  $log = Join-Path $env:TEMP 'hastama-print-log.txt'
  "event_pagebounds=$($e.PageBounds)" | Out-File $log -Append
  "event_paper=$($e.PageSettings.PaperSize.PaperName) $($e.PageSettings.PaperSize.Width)x$($e.PageSettings.PaperSize.Height)" | Out-File $log -Append
  "graphics_unit=$($e.Graphics.PageUnit)" | Out-File $log -Append
  $img = [System.Drawing.Image]::FromFile($png)
  try {
    try { $img.SetResolution(203, 203) } catch {}
    $e.Graphics.PageUnit = [System.Drawing.GraphicsUnit]::Display
    $dest = New-Object System.Drawing.Rectangle(0, 0, 295, 319)
    $src = New-Object System.Drawing.Rectangle(0, 0, $img.Width, $img.Height)
    $e.Graphics.DrawImage($img, $dest, $src, [System.Drawing.GraphicsUnit]::Pixel)
    $e.HasMorePages = $false
  } finally { $img.Dispose() }
})

try {
  $doc.Print()
  "print_rc=ok" | Out-File $log -Append
} catch {
  "print_err=$($_.Exception.Message)" | Out-File $log -Append
}
Get-Content $log
