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

# List PageMediaSizeName values
[enum]::GetNames([System.Printing.PageMediaSizeName]) | ForEach-Object { Write-Output ("enum=" + $_ + ' val=' + [int][System.Printing.PageMediaSizeName]::$_) }

# Custom page size: width/height in 1/100 mm for print ticket XML via WPF PageMediaSize
$w = [double]($WidthMm * 100)
$h = [double]($HeightMm * 100)

$ctors = [System.Printing.PageMediaSize].GetConstructors() | ForEach-Object {
  ($_.GetParameters() | ForEach-Object { $_.ParameterType.Name + ' ' + $_.Name }) -join ', '
}
$ctors | ForEach-Object { Write-Output ("ctor=" + $_) }

$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($PrinterName)
$ticket = $queue.DefaultPrintTicket

# Try Unknown = often used for custom
$pms = $null
foreach ($name in @('Unknown', 'Custom', 'BusinessCard')) {
  try {
    $enumVal = [System.Printing.PageMediaSizeName]::$name
    $pms = New-Object System.Printing.PageMediaSize @($enumVal, $w, $h)
    Write-Output ("pms_ok name=" + $name + ' ' + $pms.Width + 'x' + $pms.Height)
    break
  } catch {
    Write-Output ("pms_fail " + $name + ' ' + $_.Exception.Message)
  }
}
if (-not $pms) {
  # reflection: New-Object with -ArgumentList
  try {
    $pms = New-Object -TypeName System.Printing.PageMediaSize -ArgumentList ([System.Printing.PageMediaSizeName]::Unknown), $w, $h
    Write-Output ("pms_unknown=" + $pms.Width + 'x' + $pms.Height)
  } catch { Write-Output ("pms_ref_err=" + $_.Exception.Message) }
}
if (-not $pms) { exit 1 }

$ticket.PageMediaSize = $pms
Write-Output ("ticket=" + $ticket.PageMediaSize.Width + 'x' + $ticket.PageMediaSize.Height)

# Also try to get supported page media sizes from queue
try {
  $cap = $queue.GetPrintCapabilities($ticket)
  Write-Output '--- ticket page media size supported ---'
  $cap.PageMediaSizeCapability | ForEach-Object {
    Write-Output ("  " + $_.PageMediaSizeName + ' ' + $_.Width + 'x' + $_.Height)
  }
} catch { Write-Output ("cap_err=" + $_.Exception.Message) }

# Print image
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

$queue.CurrentJobSettings.JobName = 'HastamaLabel'
$writer = [System.Printing.PrintQueue]::CreateXpsDocumentWriter($queue)
$writer.Write($dv, $ticket)
Write-Output 'print_ok'
