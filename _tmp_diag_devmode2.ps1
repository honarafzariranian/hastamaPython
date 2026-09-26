$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework

$printerName = 'EPSON TM-T88III Receipt'
$cfg = Get-PrintConfiguration -PrinterName $printerName
$doc = New-Object System.Xml.XmlDocument
$doc.LoadXml($cfg.PrintTicketXML)
$snapB64 = $null
foreach ($n in $doc.SelectNodes('//*[contains(@name,"PageDevmodeSnapshot")]/*[local-name()="Value"]')) {
  $snapB64 = $n.InnerText
}
$bytes = [Convert]::FromBase64String($snapB64)
Write-Output ("snapshot bytes=" + $bytes.Length)

# Correct UNICODE DEVMODE offsets
$dmSize = [BitConverter]::ToUInt16($bytes, 68)
$dmExtra = [BitConverter]::ToUInt16($bytes, 70)
$dmFields = [BitConverter]::ToUInt32($bytes, 72)
$dmColor = [BitConverter]::ToInt16($bytes, 92)
$dmDuplex = [BitConverter]::ToInt16($bytes, 94)
$dmYRes = [BitConverter]::ToInt16($bytes, 96)
$dmTT = [BitConverter]::ToInt16($bytes, 98)
$dmCollate = [BitConverter]::ToInt16($bytes, 100)
$dmPrintQuality = [BitConverter]::ToInt16($bytes, 90)
$dmPaperSize = [BitConverter]::ToInt16($bytes, 78)
$dmPaperLen = [BitConverter]::ToInt16($bytes, 80)
$dmPaperWid = [BitConverter]::ToInt16($bytes, 82)
$dmOrient = [BitConverter]::ToInt16($bytes, 76)
$dmScale = [BitConverter]::ToInt16($bytes, 84)
$dmCopies = [BitConverter]::ToInt16($bytes, 86)
$dmDefaultSrc = [BitConverter]::ToInt16($bytes, 88)
$dmLogPixels = [BitConverter]::ToUInt16($bytes, 166)
$dmBitsPerPel = [BitConverter]::ToUInt32($bytes, 168)
$dmICMMethod = [BitConverter]::ToUInt32($bytes, 188)
$dmICMIntent = [BitConverter]::ToUInt32($bytes, 192)
$dmMediaType = [BitConverter]::ToUInt32($bytes, 196)
$dmDither = [BitConverter]::ToUInt32($bytes, 200)
$dmReserved1 = [BitConverter]::ToUInt32($bytes, 204)
$dmReserved2 = [BitConverter]::ToUInt32($bytes, 208)
$dmPanW = [BitConverter]::ToUInt32($bytes, 212)
$dmPanH = [BitConverter]::ToUInt32($bytes, 216)

Write-Output ("dmSize=$dmSize dmExtra=$dmExtra dmFields=0x$('{0:X8}' -f $dmFields)")
Write-Output ("  dmOrient=$dmOrient dmPaperSize=$dmPaperSize dmPaperLen=$dmPaperLen dmPaperWid=$dmPaperWid dmScale=$dmScale dmCopies=$dmCopies dmDefaultSrc=$dmDefaultSrc dmPrintQuality=$dmPrintQuality")
Write-Output ("  dmColor=$dmColor dmDuplex=$dmDuplex dmYRes=$dmYRes dmTT=$dmTT dmCollate=$dmCollate")
Write-Output ("  dmLogPixels=$dmLogPixels dmBpp=$dmBitsPerPel ICM=$dmICMMethod/$dmICMIntent MediaType=$dmMediaType")
Write-Output ("  dmDitherType=$dmDither Reserved=$dmReserved1/$dmReserved2 Pan=${dmPanW}x${dmPanH}")

$ditherNames = @{
  0='DEFAULT'; 1='NONE'; 2='FS1'; 3='FS2'; 4='FS3'; 5='FS4';
  6='CAPTURE'; 7='SOLID'; 8='YCCK'; 9='YCCK2'; 10='YCCK3'; 11='YCCK4';
  12='CITY1'; 13='CITY2'; 14='CITY3'; 15='CITY4'
}
$dn = if ($ditherNames.ContainsKey([int]$dmDither)) { $ditherNames[[int]$dmDither] } else { 'UNKNOWN' }
Write-Output ("  => dither: " + $dn)

# Driver private blob after public DEVMODE
$privOff = $dmSize
$privLen = $dmExtra
if ($privOff + $privLen -gt $bytes.Length) { $privLen = $bytes.Length - $privOff }
Write-Output ("private blob: offset=$privOff len=$privLen")
# Print first 64 bytes hex
$hex = ($bytes[$privOff..([Math]::Min($privOff+63, $bytes.Length-1))] | ForEach-Object { $_.ToString('X2') }) -join ' '
Write-Output ("  first64: $hex")

# Compare DefaultPrintTicket / UserPrintTicket XML for PageDevmodeSnapshot
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Get-InternalTicketXml($ticket) {
  $f = $ticket.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance')
  $inner = $f.GetValue($ticket)
  $it = $inner.GetType()
  # dump methods
  $methods = $it.GetMethods([System.Reflection.BindingFlags]'Public,NonPublic,Instance') | Where-Object { $_.Name -match 'Xml|Write|Read|Load|Save|Clone' } | ForEach-Object { $_.Name }
  Write-Output ("  InternalPrintTicket XML-ish methods: " + (($methods | Sort-Object -Unique) -join ', '))
  $fields = $it.GetFields([System.Reflection.BindingFlags]'NonPublic,Instance') | ForEach-Object { $_.Name + ':' + $_.FieldType.Name }
  Write-Output ("  InternalPrintTicket fields: " + ($fields -join ', '))
  # Try common field names for XML holder
  foreach ($fn in @('_ticketXml', 'm_xml', '_xml', 'xml', '_document', '_xmlDoc', '_printTicketXml', 'm_printTicketXml')) {
    $ff = $it.GetField($fn, [System.Reflection.BindingFlags]'NonPublic,Instance')
    if ($ff) {
      $v = $ff.GetValue($inner)
      if ($v) { Write-Output ("  field $fn => " + $v.GetType().Name + " : " + $v) }
    }
  }
  # Try _document / XmlDocument fields
  foreach ($ff in $it.GetFields([System.Reflection.BindingFlags]'NonPublic,Instance')) {
    if ($ff.FieldType.Name -match 'Xml|String|Document|Stream') {
      try {
        $v = $ff.GetValue($inner)
        if ($v) {
          if ($v -is [string]) { Write-Output ("  str field " + $ff.Name + " len=" + $v.Length + " hasSnapshot=" + ($v -match 'PageDevmodeSnapshot')) }
          elseif ($v -is [System.Xml.XmlDocument]) { $s = $v.OuterXml; Write-Output ("  xml field " + $ff.Name + " len=" + $s.Length + " hasSnapshot=" + ($s -match 'PageDevmodeSnapshot')) }
          else { Write-Output ("  field " + $ff.Name + " type=" + $v.GetType().Name) }
        }
      } catch {}
    }
  }
}

Write-Output '=== DefaultPrintTicket ==='
Get-InternalTicketXml $queue.DefaultPrintTicket
Write-Output '=== UserPrintTicket ==='
Get-InternalTicketXml $queue.UserPrintTicket
Write-Output '=== Get-PrintConfiguration snapshot present ==='
Write-Output ("  cfg has snapshot: " + ($cfg.PrintTicketXML -match 'PageDevmodeSnapshot'))

# Also: does GetPrintQueue expose CurrentJobSettings?
Write-Output '=== CurrentJobSettings ==='
try {
  $cjs = $queue.CurrentJobSettings
  if ($cjs) {
    Write-Output ("  has PrintTicket: " + ($null -ne $cjs.PrintTicket))
    if ($cjs.PrintTicket) { Get-InternalTicketXml $cjs.PrintTicket }
  } else { Write-Output '  null' }
} catch { Write-Output ('  ' + $_.Exception.Message) }
