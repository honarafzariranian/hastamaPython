$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework

$printerName = 'EPSON TM-T88III Receipt'
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Get-Internal($ticket) {
  $f = $ticket.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance')
  return $f.GetValue($ticket)
}

function Save-TicketXml($ticket) {
  $inner = Get-Internal $ticket
  $ms = New-Object System.IO.MemoryStream
  $inner.SaveTo($ms)
  $bytes = $ms.ToArray()
  [Diagnostics.Debug]::WriteLine(("SaveTo len=" + $bytes.Length + " first8=" + (($bytes[0..7] | ForEach-Object { $_.ToString('X2') }) -join ' ')))
  if ($bytes.Length -ge 2 -and $bytes[0] -eq 0xFF -and $bytes[1] -eq 0xFE) {
    $s = [Text.Encoding]::Unicode.GetString($bytes, 2, $bytes.Length - 2)
  } elseif ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
    $s = [Text.Encoding]::UTF8.GetString($bytes, 3, $bytes.Length - 3)
  } elseif ($bytes.Length -ge 2 -and $bytes[0] -eq 0x3C -and $bytes[1] -eq 0x00) {
    $s = [Text.Encoding]::Unicode.GetString($bytes)
  } else {
    $s = [Text.Encoding]::UTF8.GetString($bytes)
  }
  return $s
}

function Get-SnapFromXml($xml) {
  $doc = New-Object System.Xml.XmlDocument
  $doc.LoadXml($xml)
  $n = $doc.SelectSingleNode('//*[contains(@name,"PageDevmodeSnapshot")]/*[local-name()="Value"]')
  if ($n) { return [Convert]::FromBase64String($n.InnerText) }
  return $null
}

function Describe-Snap($bytes, $label) {
  if (-not $bytes) { Write-Output "$label : no snap"; return }
  $paper = [BitConverter]::ToInt16($bytes, 78)
  $plen = [BitConverter]::ToInt16($bytes, 80)
  $pwid = [BitConverter]::ToInt16($bytes, 82)
  $color = [BitConverter]::ToInt16($bytes, 92)
  $yres = [BitConverter]::ToInt16($bytes, 96)
  $qual = [BitConverter]::ToInt16($bytes, 90)
  $dither = [BitConverter]::ToUInt32($bytes, 200)
  $priv = if ($bytes.Length -gt 220) { $bytes[220..($bytes.Length-1)] } else { @() }
  $sha = [System.Security.Cryptography.SHA1]::Create()
  $hash = ($sha.ComputeHash($priv) | ForEach-Object { $_.ToString('X2') }) -join ''
  Write-Output ("$label : snap=$($bytes.Length) paper=$paper len=$plen wid=$pwid color=$color yres=$yres qual=$qual dither=$dither privSha=$($hash.Substring(0,16))")
}

Write-Output '--- DEFAULT ---'
$defXml0 = Save-TicketXml $queue.DefaultPrintTicket
Describe-Snap (Get-SnapFromXml $defXml0) 'DEFAULT'

$w = [double](75 * 100); $h = [double](81 * 100)
$t = $queue.DefaultPrintTicket
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$t.PageMediaSize = $pms
try { $t.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $t.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}
try { $m = New-Object System.Printing.PageMargin; $m.Left=0; $m.Top=0; $m.Right=0; $m.Bottom=0; $t.PageMargin = $m } catch {}

Write-Output '--- MUTATED ---'
$mutXml = Save-TicketXml $t
Describe-Snap (Get-SnapFromXml $mutXml) 'MUTATED'

$doc = New-Object System.Xml.XmlDocument
$doc.LoadXml($mutXml)
$msNode = $doc.SelectSingleNode('//*[local-name()="Feature" and @name="psk:PageMediaSize"]')
Write-Output '=== FULL PageMediaSize (mutated SaveTo) ==='
Write-Output $msNode.OuterXml

$defDoc = New-Object System.Xml.XmlDocument
$defDoc.LoadXml($defXml0)
$msDef = $defDoc.SelectSingleNode('//*[local-name()="Feature" and @name="psk:PageMediaSize"]')
Write-Output '=== FULL PageMediaSize (default SaveTo) ==='
Write-Output $msDef.OuterXml

foreach ($n in $doc.SelectNodes('//*[local-name()="Feature" or local-name()="ParameterInit"]')) {
  $nm = $n.GetAttribute('name')
  if ($nm -match 'Margin|Scale|Quality|Color|Resolution|InputBin|Orientation|Media') {
    Write-Output ("HIT " + $n.LocalName + " " + $nm + " :: " + $n.OuterXml.Substring(0, [Math]::Min(350, $n.OuterXml.Length)))
  }
}
Write-Output ("hasMarginToken=" + ($mutXml -match 'PageMargin|JobMargin|psk:Margin'))
Write-Output ("hasScalingToken=" + ($mutXml -match 'PageScalingFactor'))
