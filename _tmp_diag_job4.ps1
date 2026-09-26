$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework
Add-Type -AssemblyName WindowsBase
Add-Type -AssemblyName PresentationCore

$printerName = 'EPSON TM-T88III Receipt'
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Save-TicketXml($ticket) {
  if (-not $ticket) { return $null }
  $f = $ticket.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance')
  if (-not $f) { return $null }
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

$w = [double](75 * 100); $h = [double](81 * 100)
$ticket = $queue.DefaultPrintTicket
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$ticket.PageMediaSize = $pms
try { $ticket.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $ticket.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}

$dv = New-Object System.Windows.Media.DrawingVisual
$dc = $dv.RenderOpen()
$brush = New-Object System.Windows.Media.SolidColorBrush([System.Windows.Media.Colors]::Black)
$dc.DrawRectangle($brush, $null, (New-Object System.Windows.Rect(0, 0, 100, 100)))
$dc.Close()
$writer = [System.Printing.PrintQueue]::CreateXpsDocumentWriter($queue)
$writer.Write($dv, $ticket)
Start-Sleep -Milliseconds 600
$queue.Refresh()
Write-Output ("NumberOfJobs=" + $queue.NumberOfJobs)

$jobs = $queue.GetPrintJobInfoCollection()
$found = $null
foreach ($j in $jobs) { $found = $j; break }
if (-not $found) { Write-Output 'no job'; exit 0 }

Write-Output ("jobid=" + $found.JobIdentifier + " name=" + $found.Name)
Write-Output '--- PropertiesCollection ---'
try {
  $pc = $found.PropertiesCollection
  foreach ($p in $pc) {
    $nm = $p.Name
    $val = $null
    try { $val = $p.Value } catch {}
    $s = if ($val) { $val.ToString() } else { 'null' }
    if ($s.Length -gt 200) { $s = $s.Substring(0, 200) + '...' }
    Write-Output ("  " + $nm + " = " + $s)
  }
} catch { Write-Output ('props err: ' + $_.Exception.Message) }

Write-Output '--- JobPrintTicket ---'
try {
  $jt = $found.JobPrintTicket
  Write-Output ("jt null? " + ($null -eq $jt))
  if ($jt) {
    $xml = Save-TicketXml $jt
    if ($xml) {
      $doc = New-Object System.Xml.XmlDocument
      $doc.LoadXml($xml)
      $n = $doc.SelectSingleNode('//*[contains(@name,"PageDevmodeSnapshot")]/*[local-name()="Value"]')
      if ($n) {
        $b = [Convert]::FromBase64String($n.InnerText)
        Write-Output ("  SNAP len=" + $b.Length)
      } else { Write-Output '  NO SNAP' }
      $msNode = $doc.SelectSingleNode('//*[local-name()="Feature" and @name="psk:PageMediaSize"]')
      if ($msNode) { Write-Output ('  MEDIA: ' + $msNode.OuterXml) }
      foreach ($f in $doc.SelectNodes('//*[local-name()="Feature"]')) {
        $fn = $f.GetAttribute('name')
        if ($fn -match 'Color|Orientation|Resolution') { Write-Output ('  ' + $fn + ' :: ' + $f.OuterXml) }
      }
    } else { Write-Output '  xml null' }
  }
} catch { Write-Output ('jt err: ' + $_.Exception.Message) }

Write-Output '--- non-null fields on job object ---'
$found.GetType().GetFields([System.Reflection.BindingFlags]'NonPublic,Instance') | ForEach-Object {
  try {
    $v = $_.GetValue($found)
    if ($v -ne $null) {
      $t = if ($v -is [byte[]]) { 'byte[' + $v.Length + ']' } else { $v.GetType().Name }
      Write-Output ("  " + $_.Name + " : " + $t)
    }
  } catch {}
}
