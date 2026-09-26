$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework

$printerName = 'EPSON TM-T88III Receipt'
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Get-TicketXml($ticket) {
  $f = $ticket.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance')
  $inner = $f.GetValue($ticket)
  $ff = $inner.GetType().GetField('_xmlDoc', [System.Reflection.BindingFlags]'NonPublic,Instance')
  return $ff.GetValue($inner).OuterXml
}

function Get-Snap($xmlText) {
  $doc = New-Object System.Xml.XmlDocument
  $doc.LoadXml($xmlText)
  $n = $doc.SelectSingleNode('//*[contains(@name,"PageDevmodeSnapshot")]/*[local-name()="Value"]')
  if ($n) { return [Convert]::FromBase64String($n.InnerText) }
  return $null
}

function Describe-Devmode($bytes, $label) {
  if (-not $bytes) { Write-Output "$label : NO SNAPSHOT"; return }
  $dmSize = [BitConverter]::ToUInt16($bytes, 68)
  $dmExtra = [BitConverter]::ToUInt16($bytes, 70)
  $dmFields = [BitConverter]::ToUInt32($bytes, 72)
  $orient = [BitConverter]::ToInt16($bytes, 76)
  $paper = [BitConverter]::ToInt16($bytes, 78)
  $plen = [BitConverter]::ToInt16($bytes, 80)
  $pwid = [BitConverter]::ToInt16($bytes, 82)
  $scale = [BitConverter]::ToInt16($bytes, 84)
  $copies = [BitConverter]::ToInt16($bytes, 86)
  $defsrc = [BitConverter]::ToInt16($bytes, 88)
  $pq = [BitConverter]::ToInt16($bytes, 90)
  $color = [BitConverter]::ToInt16($bytes, 92)
  $yres = [BitConverter]::ToInt16($bytes, 96)
  $dither = [BitConverter]::ToUInt32($bytes, 200)
  # form name at 102, 32 WCHAR
  $formChars = @()
  for ($i = 0; $i -lt 32; $i++) {
    $c = [BitConverter]::ToUInt16($bytes, 102 + $i * 2)
    if ($c -eq 0) { break }
    $formChars += [char]$c
  }
  $form = -join $formChars
  Write-Output ("$label : len=$($bytes.Length) dmSize=$dmSize extra=$dmExtra fields=0x$('{0:X8}' -f $dmFields)")
  Write-Output ("  orient=$orient paper=$paper len=$plen wid=$pwid scale=$scale copies=$copies defsrc=$defsrc qual=$pq color=$color yres=$yres dither=$dither")
  Write-Output ("  form='$form'")
  # hash of private blob
  $priv = if ($bytes.Length -gt $dmSize) { $bytes[$dmSize..($bytes.Length-1)] } else { @() }
  if ($priv.Length -gt 0) {
    $sha = [System.Security.Cryptography.SHA1]::Create()
    $hash = ($sha.ComputeHash($priv) | ForEach-Object { $_.ToString('X2') }) -join ''
    Write-Output ("  priv len=$($priv.Length) sha1=$($hash.Substring(0,24))")
  }
}

$cfg = Get-PrintConfiguration -PrinterName $printerName
Describe-Devmode (Get-Snap $cfg.PrintTicketXML) 'CONFIG'

Describe-Devmode (Get-Snap (Get-TicketXml $queue.DefaultPrintTicket)) 'DEFAULT'

Describe-Devmode (Get-Snap (Get-TicketXml $queue.UserPrintTicket)) 'USER'

# Compare private blobs byte-by-byte (first difference)
$def = Get-Snap (Get-TicketXml $queue.DefaultPrintTicket)
$user = Get-Snap (Get-TicketXml $queue.UserPrintTicket)
if ($def -and $user -and $def.Length -eq $user.Length) {
  $diffs = @()
  for ($i = 0; $i -lt $def.Length; $i++) {
    if ($def[$i] -ne $user[$i]) { $diffs += $i }
  }
  Write-Output ("Default vs USER first diffs: " + (($diffs | Select-Object -First 30) -join ', ') + " total=" + $diffs.Count)
} elseif ($def -and $user) {
  Write-Output ("Default vs USER length differs: $($def.Length) vs $($user.Length)")
}

# Also dump other config XML differences (non-snapshot features)
$cfgXml = $cfg.PrintTicketXML
$defXml = Get-TicketXml $queue.DefaultPrintTicket
# strip snapshot values for comparison
function Strip-Snap($xml) {
  $doc = New-Object System.Xml.XmlDocument
  $doc.LoadXml($xml)
  foreach ($n in $doc.SelectNodes('//*[contains(@name,"PageDevmodeSnapshot")]/*[local-name()="Value"]')) {
    $n.InnerText = 'SNAP'
  }
  return $doc.OuterXml
}
$a = Strip-Snap $cfgXml
$b = Strip-Snap $defXml
Write-Output ("cfg vs default (sans snap) equal: " + ($a -eq $b))
if ($a -ne $b) {
  # show a short diff hint: find Feature names
  $fa = [regex]::Matches($a, 'Feature name="([^"]+)"') | ForEach-Object { $_.Groups[1].Value }
  $fb = [regex]::Matches($b, 'Feature name="([^"]+)"') | ForEach-Object { $_.Groups[1].Value }
  Write-Output ("cfg features: " + ($fa -join ', '))
  Write-Output ("def features: " + ($fb -join ', '))
}
