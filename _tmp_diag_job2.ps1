$ErrorActionPreference = 'Stop'
Import-Module PrintManagement -ErrorAction Stop
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework
Add-Type -AssemblyName PresentationCore
Add-Type -AssemblyName WindowsBase

$printerName = 'EPSON TM-T88III Receipt'

function Get-TicketXmlFrom($t) {
  $f = $t.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance')
  $inner = $f.GetValue($t)
  $ff = $inner.GetType().GetField('_xmlDoc', [System.Reflection.BindingFlags]'NonPublic,Instance')
  $doc = $ff.GetValue($inner)
  # Also try SaveTo flush
  $ms = New-Object System.IO.MemoryStream
  try { $inner.SaveTo($ms) } catch {}
  return $doc.OuterXml
}

function Save-TicketXml($ticket) {
  $f = $ticket.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance')
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
  if (-not $n) { return @{ hasSnap = $false } }
  $b = [Convert]::FromBase64String($n.InnerText)
  $sha = [System.Security.Cryptography.SHA1]::Create()
  $hash = ($sha.ComputeHash($b) | ForEach-Object { $_.ToString('X2') }) -join ''
  $paper = [BitConverter]::ToInt16($b, 78)
  $plen = [BitConverter]::ToInt16($b, 80)
  $pwid = [BitConverter]::ToInt16($b, 82)
  $color = [BitConverter]::ToInt16($b, 92)
  $dither = [BitConverter]::ToUInt32($b, 200)
  $qual = [BitConverter]::ToInt16($b, 90)
  return @{
    hasSnap = $true
    len = $b.Length
    sha = $hash.Substring(0, 16)
    paper = $paper
    plen = $plen
    pwid = $pwid
    color = $color
    dither = $dither
    qual = $qual
  }
}

function Dump-TicketFeatures($xml, $label) {
  Write-Output "=== $label features ==="
  $doc = New-Object System.Xml.XmlDocument
  $doc.LoadXml($xml)
  foreach ($n in $doc.SelectNodes('//*[local-name()="Feature"]')) {
    $nm = $n.GetAttribute('name')
    $txt = $n.OuterXml
    if ($txt.Length -gt 400) { $txt = $txt.Substring(0, 400) + '...' }
    Write-Output ("  " + $nm + " :: " + $txt)
  }
  $si = Get-SnapInfo $xml
  if ($si.hasSnap) {
    Write-Output ("  SNAP len=$($si.len) sha=$($si.sha) paper=$($si.paper) plen=$($si.plen) pwid=$($si.pwid) color=$($si.color) dither=$($si.dither) qual=$($si.qual)")
  } else {
    Write-Output '  NO SNAP'
  }
}

# ── 1) Submit server-style XPS job ──
$w = [double](75 * 100); $h = [double](81 * 100)
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)
$ticket = $queue.DefaultPrintTicket
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$ticket.PageMediaSize = $pms
try { $ticket.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $ticket.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}
try { $m = New-Object System.Printing.PageMargin; $m.Left=0; $m.Top=0; $m.Right=0; $m.Bottom=0; $ticket.PageMargin = $m } catch {}

$dv = New-Object System.Windows.Media.DrawingVisual
$dc = $dv.RenderOpen()
$rect = New-Object System.Windows.Rect(0, 0, 100, 100)
$brush = New-Object System.Windows.Media.SolidColorBrush([System.Windows.Media.Colors]::Black)
$dc.DrawRectangle($brush, $null, $rect)
$dc.Close()
$writer = [System.Printing.PrintQueue]::CreateXpsDocumentWriter($queue)
Write-Output 'Submitting XPS job...'
$writer.Write($dv, $ticket)
Write-Output 'Submitted.'

# ── 2) Race to capture job ──
$found = $null
for ($i = 0; $i -lt 40; $i++) {
  $queue.Refresh()
  $jobs = $queue.GetJobs()
  if ($jobs.Count -gt 0) { $found = $jobs[0]; break }
  Start-Sleep -Milliseconds 50
}
if (-not $found) {
  Write-Output 'No job captured (may have completed too fast)'
} else {
  Write-Output ("Captured job: " + $found.Name + " status=" + $found.JobStatus)
  try {
    $jt = $found.JobPrintTicket
    if ($jt) {
      $xml = Save-TicketXml $jt
      Dump-TicketFeatures $xml 'SERVER-JOB'
    } else {
      Write-Output 'JobPrintTicket null - trying reflection on job'
      $jf = $found.GetType().GetFields([System.Reflection.BindingFlags]'NonPublic,Instance')
      foreach ($f in $jf) { Write-Output ("  job field: " + $f.Name + " : " + $f.FieldType.Name) }
    }
  } catch {
    Write-Output ('Job ticket error: ' + $_.Exception.Message)
    $jf = $found.GetType().GetFields([System.Reflection.BindingFlags]'NonPublic,Instance')
    foreach ($f in $jf) {
      try {
        $v = $f.GetValue($found)
        if ($v) { Write-Output ("  " + $f.Name + " = " + $v.GetType().Name) }
      } catch {}
    }
  }
}

# Also dump what our in-memory mutated ticket serializes to via property-aware path
Write-Output '=== SERVER in-memory ticket (SaveTo) ==='
Dump-TicketFeatures (Save-TicketXml $ticket) 'SERVER-mem'

# Default for reference
Write-Output '=== DEFAULT ticket SaveTo ==='
Dump-TicketFeatures (Save-TicketXml $queue.DefaultPrintTicket) 'DEFAULT'

# Config for reference
$cfg = Get-PrintConfiguration -PrinterName $printerName
Write-Output '=== Get-PrintConfiguration ==='
Dump-TicketFeatures $cfg.PrintTicketXML 'CONFIG'
