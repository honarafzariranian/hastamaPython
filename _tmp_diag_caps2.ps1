$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName ReachFramework
Add-Type -AssemblyName System.Printing

$printerName = 'EPSON TM-T88III Receipt'
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Dump-Ticket([System.Printing.PrintTicket]$t, [string]$label) {
  Write-Output "=== $label ==="
  $sw = New-Object System.IO.StringWriter
  $xw = [System.Xml.XmlWriter]::Create($sw, (New-Object System.Xml.XmlWriterSettings -Property @{ Indent = $true }))
  $t.WriteTo($xw)
  $xw.Flush()
  Write-Output $sw.ToString()
}

Dump-Ticket $queue.DefaultPrintTicket 'DEFAULT TICKET'

# Build the ticket exactly like production
$w = [double](75 * 100)
$h = [double](81 * 100)
$t = $queue.DefaultPrintTicket
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$t.PageMediaSize = $pms
try { $t.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $t.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}
try {
  $m = New-Object System.Printing.PageMargin
  $m.Left = 0; $m.Top = 0; $m.Right = 0; $m.Bottom = 0
  $t.PageMargin = $m
} catch {}
Dump-Ticket $t 'PRODUCTION TICKET'

# Capabilities: feature list via GetPrintCapabilityXsd is not available; use GetPrintCapabilities + Features
$cap = $queue.GetPrintCapabilities()
Write-Output '=== CAP TYPE ==='
Write-Output $cap.GetType().FullName
Write-Output '=== PAGE IMAGE AUX / COLOR ==='
try { Write-Output ("PageImageableArea: " + $cap.PageImageableArea) } catch {}
try { Write-Output ("PageMediaSize: " + $cap.PageMediaSize) } catch {}
# Named features known on PrintCapabilities
foreach ($p in $cap.PSObject.Properties) {
  $n = $p.Name
  if ($n -match 'Color|Dither|Halftone|Raster|Quality|Duplex|Staple|Collate|InputBin|OutputBin|Page' ) {
    try { Write-Output ("  " + $n + " = " + $p.Value) } catch {}
  }
}

# Driver DEVMODE dither via raw Get-Printer / winspool
Write-Output '=== WIN3OLE DEVMODE via .NET PrintingPreferences ==='
# Brute: read registry default DEVMODE for the queue
$queueName = $queue.FullName
Write-Output ("Queue full name: " + $queueName)
Get-ItemProperty -Path "HKLM:\SYSTEM\CurrentControlSet\Control\Print\Printers\*\Printer" -ErrorAction SilentlyContinue |
  Where-Object { $_.'Name' -like '*Epson*' -or $_.'Name' -like '*TM-T88*' -or $_.'Port' -like '*192.168.3.31*' } |
  ForEach-Object { Write-Output ("  " + $_.PSChildName + " Port=" + $_.Port) }
