# Print a PNG at exact mm size via PrintTicket (same path as browser @page)
param(
  [Parameter(Mandatory=$true)][string]$PngPath,
  [string]$PrinterName = 'EPSON TM-T88III Receipt',
  [double]$WidthMm = 75,
  [double]$HeightMm = 81
)
$ErrorActionPreference = 'Stop'

Add-Type -AssemblyName ReachFramework
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName WindowsBase
Add-Type -AssemblyName PresentationCore
Add-Type -AssemblyName PresentationFramework
Add-Type -AssemblyName System.Drawing

# Units: WPF PageMediaSize custom uses 1/100 mm in PrintTicket schema via WPF API?
# WPF PageMediaSize(Double width, Double height) - documented as 1/100 mm for print ticket.
# Actually MSDN: width/height are in 1/100 millimeters when PageMediaSizeName is Custom.
$w = [int][math]::Round($WidthMm * 100)
$h = [int][math]::Round($HeightMm * 100)
Write-Output ("target_1_100mm=" + $w + 'x' + $h)

$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($PrinterName)
Write-Output ("queue=" + $queue.Name + ' status=' + $queue.Status)

$ticket = $queue.DefaultPrintTicket
Write-Output ("default_ticket_ok=" + ($null -ne $ticket))

$pms = New-Object System.Printing.PageMediaSize([System.Printing.PageMediaSizeName]::Custom, $w, $h)
$ticket.PageMediaSize = $pms
Write-Output ("ticket_pms=" + $ticket.PageMediaSize.PageMediaSizeName + ' ' + $ticket.PageMediaSize.Width + 'x' + $ticket.PageMediaSize.Height)

# Image → visual at physical size (1/96 in units for WPF layout)
$bmp = New-Object System.Drawing.Bitmap($PngPath)
try {
  $dpiX = 96.0
  $dpiY = 96.0
  $pxW = $WidthMm / 25.4 * $dpiX
  $pxH = $HeightMm / 25.4 * $dpiY
  Write-Output ("visual_px=" + [math]::Round($pxW,2) + 'x' + [math]::Round($pxH,2) + ' png=' + $bmp.Width + 'x' + $bmp.Height)

  $bi = New-Object System.Windows.Media.Imaging.BitmapImage
  $bi.BeginInit()
  $bi.CacheOption = [System.Windows.Media.Imaging.BitmapCacheOption]::OnLoad
  $bi.UriSource = (New-Object Uri($PngPath, [UriKind]::Absolute))
  $bi.EndInit()
  $bi.Freeze()

  $rect = New-Object System.Windows.Rect(0, 0, $pxW, $pxH)
  $drawing = New-Object System.Windows.Media.ImageDrawing($bi, $rect)
  $dv = New-Object System.Windows.Media.DrawingVisual
  $dc = $dv.RenderOpen()
  $dc.DrawDrawing($drawing)
  $dc.Close()

  $jobName = 'HastamaLabel ' + $WidthMm + 'x' + $HeightMm
  $queue.CurrentJobSettings.JobName = $jobName
  $xpsWriter = [System.Printing.PrintQueue]::CreateXpsDocumentWriter($queue)
  $xpsWriter.Write($dv, $ticket)
  Write-Output 'print_ok'
} finally {
  $bmp.Dispose()
}
