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
  if ($bytes.Length -ge 2 -and $bytes[0] -eq 0xFF -and $bytes[1] -eq 0xFE) {
    return [Text.Encoding]::Unicode.GetString($bytes, 2, $bytes.Length - 2)
  }
  if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB) {
    return [Text.Encoding]::UTF8.GetString($bytes, 3, $bytes.Length - 3)
  }
  return [Text.Encoding]::UTF8.GetString($bytes)
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

function Get-SnapFromXml($xml) {
  $doc = New-Object System.Xml.XmlDocument
  $doc.LoadXml($xml)
  $n = $doc.SelectSingleNode('//*[contains(@name,"PageDevmodeSnapshot")]/*[local-name()="Value"]')
  if ($n) { return [Convert]::FromBase64String($n.InnerText) }
  return $null
}

# 1) Serialize DEFAULT as-is
$defXml0 = Save-TicketXml $queue.DefaultPrintTicket
Describe-Snap (Get-SnapFromXml $defXml0) 'DEFAULT-as-is'

# 2) Mutate like production, then SaveTo (what writer likely sends)
$w = [double](75 * 100); $h = [double](81 * 100)
$t = $queue.DefaultPrintTicket
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$t.PageMediaSize = $pms
try { $t.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $t.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}
try { $m = New-Object System.Printing.PageMargin; $m.Left=0; $m.Top=0; $m.Right=0; $m.Bottom=0; $t.PageMargin = $m } catch {}
$mutXml = Save-TicketXml $t
Describe-Snap (Get-SnapFromXml $mutXml) 'MUTATED-SaveTo'

# 3) Feature dump of serialized mutated ticket
$doc = New-Object System.Xml.XmlDocument
$doc.LoadXml($mutXml)
Write-Output '=== MUTATED features ==='
foreach ($n in $doc.SelectNodes('//*[local-name()="Feature"]')) {
  Write-Output ("  " + $n.GetAttribute('name') + " => " + $n.OuterXml.Substring(0, [Math]::Min(280, $n.OuterXml.Length)))
}
foreach ($n in $doc.SelectNodes('//*[local-name()="ParameterInit"]')) {
  $name = $n.GetAttribute('name')
  if ($name -notmatch 'Snapshot') {
    Write-Output ("  PI " + $name + " => " + $n.OuterXml.Substring(0, [Math]::Min(200, $n.OuterXml.Length)))
  } else {
    Write-Output ("  PI " + $name + " present len=" + $n.OuterXml.Length)
  }
}

# 4) User ticket as-is
$userXml = Save-TicketXml $queue.UserPrintTicket
Describe-Snap (Get-SnapFromXml $userXml) 'USER-as-is'

# 5) Diff private blobs Default vs User (byte offsets)
$dB = Get-SnapFromXml $defXml0
$uB = Get-SnapFromXml $userXml
if ($dB -and $uB -and $dB.Length -eq $uB.Length) {
  $diffs = @()
  for ($i = 0; $i -lt $dB.Length; $i++) { if ($dB[$i] -ne $uB[$i]) { $diffs += $i } }
  Write-Output ("Default vs User DEVMODE diffs: " + ($diffs -join ', '))
  # show values around interesting offsets
  foreach ($i in $diffs) {
    if ($i -lt 220) {
      continue  # standard fields already reported
    }
    $off = $i - 220
    Write-Output ("  priv+${off}: def=$($dB[$i]) user=$($uB[$i])")
  }
} elseif ($dB -and $uB) {
  Write-Output "Default vs User length mismatch"
}

# 6) Also check Get-PrintConfiguration full XML vs default SaveTo (sans snap)
$cfg = Get-PrintConfiguration -PrinterName $printerName
function Strip-Snap($xml) {
  $d = New-Object System.Xml.XmlDocument
  $d.LoadXml($xml)
  foreach ($n in $d.SelectNodes('//*[contains(@name,"Snapshot")]/*[local-name()="Value"]')) { $n.InnerText = 'X' }
  # normalize whitespace
  return ($d.OuterXml -replace '>\s+<', '><')
}
$a = Strip-Snap $cfg.PrintTicketXML
$b = Strip-Snap $defXml0
Write-Output ("cfg vs default SaveTo sans-snap equal: " + ($a -eq $b))
if ($a -ne $b) {
  # crude: lengths and feature names
  Write-Output ("  cfg len=$($a.Length) def len=$($b.Length)")
  $fa = [regex]::Matches($a, 'name="(psk:[^"]+|ns0000:[^"]+)"') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique
  $fb = [regex]::Matches($b, 'name="(psk:[^"]+|ns0000:[^"]+)"') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique
  Write-Output ("  only in cfg: " + (($fa | Where-Object { $fb -notcontains $_ }) -join ', '))
  Write-Output ("  only in def: " + (($fb | Where-Object { $fa -notcontains $_ }) -join ', '))
}
