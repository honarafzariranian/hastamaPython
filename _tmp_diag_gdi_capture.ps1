# Render ticket HTML → PNG → GDI print to Microsoft Print to PDF (captures job geometry)
# Microsoft Print to PDF may prompt; use -Verb? Instead use PrintDocument with PrintToFile controller.
Add-Type -AssemblyName System.Drawing
Add-Type -AssemblyName System.Drawing.Printing

$ErrorActionPreference = 'Stop'
$png = $env:TEMP + '\hastama-size-check.png'
$outPdf = $env:TEMP + '\hastama-gdi-capture.pdf'
if (Test-Path $outPdf) { Remove-Item $outPdf -Force }

# Inspect PNG
$img = [System.Drawing.Image]::FromFile($png)
Write-Output ('png=' + $img.Width + 'x' + $img.Height + ' dpi=' + $img.HorizontalResolution + 'x' + $img.VerticalResolution)
$img.Dispose()

$printer = 'Microsoft Print to PDF'
$doc = New-Object System.Drawing.Printing.PrintDocument
$doc.PrinterSettings.PrinterName = $printer
Write-Output ('mpp_valid=' + $doc.PrinterSettings.IsValid)
if (-not $doc.PrinterSettings.IsValid) {
  Write-Output 'invalid printer'
  exit 1
}
# Force label paper on this virtual printer too
$custom = New-Object System.Drawing.Printing.PaperSize('Hastama Label', 295, 319)
$doc.DefaultPageSettings.PaperSize = $custom
$cur = $doc.DefaultPageSettings.PaperSize
Write-Output ('mpp_paper=' + $cur.PaperName + ' ' + $cur.Width + 'x' + $cur.Height)
$doc.DefaultPageSettings.Margins = New-Object System.Drawing.Printing.Margins(0,0,0,0)
$doc.DefaultPageSettings.Landscape = $false

# Capture PrintPage geometry
$doc.add_PrintPage({
  param($sender, $e)
  Write-Output ('pagebounds=' + $e.PageBounds)
  Write-Output ('pagesettings=' + $e.PageSettings.PaperSize.PaperName + ' ' + $e.PageSettings.PaperSize.Width + 'x' + $e.PageSettings.PaperSize.Height)
  $img = [System.Drawing.Image]::FromFile($png)
  try {
    $img.SetResolution(203, 203)
    $e.Graphics.PageUnit = [System.Drawing.GraphicsUnit]::Display
    $dest = New-Object System.Drawing.Rectangle(0, 0, 295, 319)
    $src = New-Object System.Drawing.Rectangle(0, 0, $img.Width, $img.Height)
    $e.Graphics.DrawImage($img, $dest, $src, [System.Drawing.GraphicsUnit]::Pixel)
    $e.HasMorePages = $false
  } finally { $img.Dispose() }
})

# PrintToFile via PrintDocument.PrintToFile
$doc.PrinterSettings.PrintToFile = $true
$doc.PrinterSettings.PrintFileName = $outPdf
try {
  $doc.Print()
  Write-Output ('printed_ok=' + (Test-Path $outPdf) + ' size=' + $(if (Test-Path $outPdf) { (Get-Item $outPdf).Length } else { 0 }))
} catch {
  Write-Output ('print_err=' + $_.Exception.Message)
}
