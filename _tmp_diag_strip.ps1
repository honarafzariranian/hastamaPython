$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework
Add-Type -AssemblyName WindowsBase
Add-Type -AssemblyName PresentationCore

$printerName = 'EPSON TM-T88III Receipt'
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Get-Internal($t) {
  return $t.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance').GetValue($t)
}
function Get-Doc($inner) {
  return $inner.GetType().GetField('_xmlDoc', [System.Reflection.BindingFlags]'NonPublic,Instance').GetValue($inner)
}
function Save-TicketXml($ticket) {
  if (-not $ticket) { return $null }
  $inner = Get-Internal $ticket
  $ms = New-Object System.IO.MemoryStream
  $inner.SaveTo($ms)
  $bytes = $ms.ToArray()
  if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
    return [Text.Encoding]::UTF8.GetString($bytes, 3, $bytes.Length - 3)
  }
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
  $priv = if ($b.Length -gt 220) { $b[220..($b.Length-1)] } else { @() }
  $privHash = ($sha.ComputeHash($priv) | ForEach-Object { $_.ToString('X2') }) -join ''
  $dither = [BitConverter]::ToUInt32($b, 200)
  $paper = [BitConverter]::ToInt16($b, 78)
  $plen = [BitConverter]::ToInt16($b, 80)
  $pwid = [BitConverter]::ToInt16($b, 82)
  $color = [BitConverter]::ToInt16($b, 92)
  return "len=$($b.Length) privSha=$($privHash.Substring(0,16)) paper=$paper plen=$plen pwid=$pwid color=$color dither=$dither"
}

$w = [double](75 * 100); $h = [double](81 * 100)
$ticket = $queue.DefaultPrintTicket
Write-Output ('DEFAULT: ' + (Get-SnapInfo (Save-TicketXml $ticket)))

$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$ticket.PageMediaSize = $pms
try { $ticket.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $ticket.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}
try { $m = New-Object System.Printing.PageMargin; $m.Left=0; $m.Top=0; $m.Right=0; $m.Bottom=0; $ticket.PageMargin = $m } catch {}
Write-Output ('MUTATED: ' + (Get-SnapInfo (Save-TicketXml $ticket)))

# Strip snapshot from internal doc
$inner = Get-Internal $ticket
$doc = Get-Doc $inner
$snaps = $doc.SelectNodes('//*[contains(@name,"PageDevmodeSnapshot")]')
Write-Output ("snap nodes: " + $snaps.Count)
foreach ($s in $snaps) { [void]$s.ParentNode.RemoveChild($s) }
Write-Output 'removed.'

# Also strip other ParameterInits? List remaining parameter/features
Write-Output '--- nodes after strip ---'
$doc.SelectNodes('//*[local-name()="Feature" or local-name()="ParameterInit" or local-name()="PageTicket"]') | ForEach-Object {
  Write-Output ('  ' + $_.LocalName + ' name=' + $_.GetAttribute('name'))
}

# Media size still in _pageMediaSize cache - is it in _xmlDoc?
$msNode = $doc.SelectSingleNode('//*[local-name()="Feature" and @name="psk:PageMediaSize"]')
Write-Output ('MEDIA in doc: ' + $(if ($msNode) { $msNode.OuterXml } else { 'ABSENT' }))

Write-Output ('SNAP after strip: ' + (Get-SnapInfo (Save-TicketXml $ticket)))

# Try write
try {
  $dv = New-Object System.Windows.Media.DrawingVisual
  $dc = $dv.RenderOpen()
  $brush = New-Object System.Windows.Media.SolidColorBrush([System.Windows.Media.Colors]::Black)
  $dc.DrawRectangle($brush, $null, (New-Object System.Windows.Rect(0, 0, 100, 100)))
  $dc.Close()
  $writer = [System.Printing.PrintQueue]::CreateXpsDocumentWriter($queue)
  $writer.Write($dv, $ticket)
  Write-Output 'Write after strip: OK'
} catch {
  Write-Output ('Write after strip FAILED: ' + $_.Exception.Message)
}

Write-Output '--- UserPrintTicket ---'
$u = $queue.UserPrintTicket
Write-Output ('USER: ' + (Get-SnapInfo (Save-TicketXml $u)))
$uDoc = Get-Doc (Get-Internal $u)
$uMs = $uDoc.SelectSingleNode('//*[local-name()="Feature" and @name="psk:PageMediaSize"]')
Write-Output ('USER media: ' + $(if ($uMs) { $uMs.OuterXml } else { 'ABSENT' }))
