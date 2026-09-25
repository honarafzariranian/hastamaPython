Add-Type -AssemblyName System.Drawing
$doc = New-Object System.Drawing.Printing.PrintDocument
$doc.PrinterSettings.PrinterName = 'EPSON TM-T88III Receipt'
Write-Output ('valid=' + $doc.PrinterSettings.IsValid)
$custom = New-Object System.Drawing.Printing.PaperSize('Hastama Label', 295, 319)
$doc.DefaultPageSettings.PaperSize = $custom
$cur = $doc.DefaultPageSettings.PaperSize
Write-Output ('applied=' + $cur.PaperName + ' ' + $cur.Width + 'x' + $cur.Height)
Write-Output '--- sizes ---'
foreach ($ps in $doc.PrinterSettings.PaperSizes) {
  Write-Output ($ps.PaperName + ' ' + $ps.Width + 'x' + $ps.Height + ' kind=' + $ps.Kind)
}
Write-Output ('bounds=' + $doc.DefaultPageSettings.Bounds)
Write-Output ('landscape=' + $doc.DefaultPageSettings.Landscape)
# Simulate PrintPage PageBounds without actually printing: hook PrintPage then cancel via PrinterSettings
$logged = $false
$doc.add_PrintPage({
  param($sender, $e)
  if (-not $script:logged) {
    $script:logged = $true
    Write-Output ('event_pagebounds=' + $e.PageBounds)
    Write-Output ('event_pagesettings_paper=' + $e.PageSettings.PaperSize.PaperName + ' ' + $e.PageSettings.PaperSize.Width + 'x' + $e.PageSettings.PaperSize.Height)
    Write-Output ('graphics_pageunit=' + $e.Graphics.PageUnit)
    $e.Graphics.DrawString('x', (New-Object System.Drawing.Font('Arial', 10)), [System.Drawing.Brushes]::Black, 0, 0)
    $e.HasMorePages = $false
  }
})
# Print to Microsoft Print to PDF is interactive; instead use PrintController that captures
# Actually just print - may open save dialog. Skip real print; only settings above.
Write-Output 'done'
