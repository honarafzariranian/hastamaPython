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

$w = [double]($WidthMm * 100)
$h = [double]($HeightMm * 100)

$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($PrinterName)
$ticket = $queue.DefaultPrintTicket
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$ticket.PageMediaSize = $pms
Write-Output ("ticket_pms=" + $ticket.PageMediaSize.Width + 'x' + $ticket.PageMediaSize.Height)

# Force landscape=false, color=false if available
try { $ticket.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $ticket.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}

$bi = New-Object System.Windows.Media.Imaging.BitmapImage
$bi.BeginInit()
$bi.CacheOption = [System.Windows.Media.Imaging.BitmapCacheOption]::OnLoad
$bi.UriSource = New-Object Uri($PngPath, [UriKind]::Absolute)
$bi.EndInit()
$bi.Freeze()

$pxW = $WidthMm / 25.4 * 96.0
$pxH = $HeightMm / 25.4 * 96.0
$rect = New-Object System.Windows.Rect(0, 0, $pxW, $pxH)
$drawing = New-Object System.Windows.Media.ImageDrawing($bi, $rect)
$dv = New-Object System.Windows.Media.DrawingVisual
$dc = $dv.RenderOpen()
$dc.DrawDrawing($drawing)
$dc.Close()

$writer = [System.Printing.PrintQueue]::CreateXpsDocumentWriter($queue)
$writer.Write($dv, $ticket)
Write-Output 'print_ok'
