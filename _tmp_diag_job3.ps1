$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework
Add-Type -AssemblyName WindowsBase
Add-Type -AssemblyName PresentationCore

$printerName = 'EPSON TM-T88III Receipt'
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Save-TicketXml($ticket) {
  $f = $ticket.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance')
  if (-not $f) { return '<no _printTicket field>' }
  $inner = $f.GetValue($ticket)
  $ms = New-Object System.IO.MemoryStream
  $inner.SaveTo($ms)
  $bytes = $ms.ToArray()
  if ($bytes.Length -ge 2 -and $bytes[0] -eq 0xFF -and $bytes[1] -eq 0xFE) {
    return [Text.Encoding]::Unicode.GetString($bytes, 2, $bytes.Length - 2)
  }
  if ($bytes.Length -ge 2 -and $bytes[0] -eq 0x3C -and $bytes[1] -eq 0x00) {
    return [Text.Encoding]::Unicode.GetString($bytes)
  }
  return [Text.Encoding]::UTF8.GetString($bytes)
}

function Get-SnapInfo($xml) {
  $doc = New-Object System.Xml.XmlDocument
  $doc.LoadXml($xml)
  $n = $doc.SelectSingleNode('//*[contains(@name,"PageDevmodeSnapshot")]/*[local-name()="Value"]')
  if (-not $n) { return 'NO SNAP' }
  $b = [Convert]::FromBase64String($n.InnerText)
  $sha = [System.Security.Cryptography.SHA1]::Create()
  $hash = ($sha.ComputeHash($b) | ForEach-Object { $_.ToString('X2') }) -join ''
  $paper = [BitConverter]::ToInt16($b, 78)
  $plen = [BitConverter]::ToInt16($b, 80)
  $pwid = [BitConverter]::ToInt16($b, 82)
  $color = [BitConverter]::ToInt16($b, 92)
  $dither = [BitConverter]::ToUInt32($b, 200)
  $qual = [BitConverter]::ToInt16($b, 90)
  $priv = if ($b.Length -gt 220) { $b[220..($b.Length-1)] } else { @() }
  $privHash = ($sha.ComputeHash($priv) | ForEach-Object { $_.ToString('X2') }) -join ''
  return "snap=$($b.Length) sha=$($hash.Substring(0,16)) privSha=$($privHash.Substring(0,16)) paper=$paper plen=$plen pwid=$pwid color=$color dither=$dither qual=$qual"
}

function Dump-Ticket($xml, $label) {
  Write-Output "=== $label ==="
  $doc = New-Object System.Xml.XmlDocument
  $doc.LoadXml($xml)
  foreach ($n in $doc.SelectNodes('//*[local-name()="Feature"]')) {
    $nm = $n.GetAttribute('name')
    if ($nm -match 'Devmode|Media|Color|Orientation|Resolution|Quality|Scale|Margin|InputBin|OutputBin|Staple|Duplex|Collate|Page') {
      $txt = $n.OuterXml
      if ($txt.Length -gt 350) { $txt = $txt.Substring(0, 350) + '...' }
      Write-Output ("  " + $nm + " :: " + $txt)
    }
  }
  Write-Output ('  SNAP: ' + (Get-SnapInfo $xml))
}

try { $queue.Pause(); Write-Output 'Queue PAUSED' } catch { Write-Output ('Pause failed: ' + $_.Exception.Message) }

$w = [double](75 * 100); $h = [double](81 * 100)
$ticket = $queue.DefaultPrintTicket
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$ticket.PageMediaSize = $pms
try { $ticket.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $ticket.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}
try { $m = New-Object System.Printing.PageMargin; $m.Left=0; $m.Top=0; $m.Right=0; $m.Bottom=0; $ticket.PageMargin = $m } catch {}

$dv = New-Object System.Windows.Media.DrawingVisual
$dc = $dv.RenderOpen()
$brush = New-Object System.Windows.Media.SolidColorBrush([System.Windows.Media.Colors]::Black)
$dc.DrawRectangle($brush, $null, (New-Object System.Windows.Rect(0, 0, 100, 100)))
$dc.Close()
$writer = [System.Printing.PrintQueue]::CreateXpsDocumentWriter($queue)
Write-Output 'Submitting XPS job...'
$writer.Write($dv, $ticket)
Write-Output 'Submitted.'

Start-Sleep -Milliseconds 800
$queue.Refresh()
Write-Output ("NumberOfJobs=" + $queue.NumberOfJobs)

$jobs = $queue.GetPrintJobInfoCollection()
$found = $null
foreach ($j in $jobs) { $found = $j; break }

if ($found) {
  Write-Output ("Captured: " + $found.Name + " jobid=" + $found.JobIdentifier)
  try {
    $jt = $found.JobPrintTicket
    $xml = Save-TicketXml $jt
    Dump-Ticket $xml 'SERVER-JOB'
  } catch {
    Write-Output ('JobPrintTicket error: ' + $_.Exception.Message)
    $found.GetType().GetProperties([System.Reflection.BindingFlags]'Public,Instance') | ForEach-Object { $_.Name }
  }
} else {
  Write-Output 'No job found'
}

try { $queue.Resume(); Write-Output 'Queue RESUMED' } catch { Write-Output ('Resume failed: ' + $_.Exception.Message) }
