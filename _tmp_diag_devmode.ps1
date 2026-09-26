$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework

$printerName = 'EPSON TM-T88III Receipt'
$cfg = Get-PrintConfiguration -PrinterName $printerName
$xmlText = $cfg.PrintTicketXML

# Decode PageDevmodeSnapshot from config ticket
$ns = New-Object System.Xml.XmlNamespaceManager((New-Object System.Xml.NameTable))
$ns.AddNamespace('psf', 'http://schemas.microsoft.com/windows/2003/08/printing/printschemaframework')
$ns.AddNamespace('xsi', 'http://www.w3.org/2001/XMLSchema-instance')
$doc = New-Object System.Xml.XmlDocument
$doc.LoadXml($xmlText)
$snapNode = $doc.SelectSingleNode('//psf:ParameterInit[@name="ns0000:PageDevmodeSnapshot"]/psf:Value', $ns)
if (-not $snapNode) {
  # fallback: any PageDevmodeSnapshot
  $snapNode = $doc.SelectSingleNode('//*[contains(@name,"PageDevmodeSnapshot")]/psf:Value', $ns)
}
if (-not $snapNode) {
  Write-Output 'NO PageDevmodeSnapshot in config ticket'
} else {
  $b64 = $snapNode.InnerText
  $bytes = [Convert]::FromBase64String($b64)
  Write-Output ("Snapshot bytes: " + $bytes.Length)
  # DEVMODE: dmDeviceName[32] TCHAR (UNICODE=64 bytes) if dmSize indicates
  # Standard printer DEVMODE offsets (UNICODE):
  # 0: dmDeviceName[32] wchar = 64 bytes
  # 64: dmSpecVersion (2), dmDriverVersion (2), dmSize (2), dmDriverExtra (2)
  # 72: dmFields (4)
  # Then printer-specific union...
  $dmSize = [BitConverter]::ToUInt16($bytes, 68)
  $dmExtra = [BitConverter]::ToUInt16($bytes, 70)
  $dmFields = [BitConverter]::ToUInt32($bytes, 72)
  Write-Output ("dmSize=" + $dmSize + " dmDriverExtra=" + $dmExtra + " dmFields=0x" + $dmFields.ToString('X8'))
  # For printer DEVMODE (per wingdi.h), after common fields:
  # offset 76: POINTL position? Actually printer DEVMODE:
  # After dmFields at 72:
  # 76: dmColor (short) - wait, layout differs for display vs printer
  #
  # Windows DEVMODE printer layout (UNICODE, dmSize typically 220):
  # 0    dmDeviceName[32] WCHAR (64)
  # 64   dmSpecVersion WORD
  # 66   dmDriverVersion WORD
  # 68   dmSize WORD
  # 70   dmDriverExtra WORD
  # 72   dmFields DWORD
  # 76   union { struct { short x, y } orient; struct { short orientation, paperSize } ...}
  #      Actually for printer:
  # 76   dmOrientation (short) OR dmPosition.x depending
  #
  # Standard layout from MSDN DEVMODE (printer):
  # 0    dmDeviceName[32] TCHAR
  # 32   dmSpecVersion WORD
  # 34   dmDriverVersion WORD
  # 36   dmSize WORD
  # 38   dmDriverExtra WORD
  # 40   dmFields DWORD
  # 44   dmColor short
  # 46   dmDuplex short
  # 48   dmYResolution short
  # 50   dmTTOption short
  # 52   dmCollate short
  # 54   dmFormName[CCHFORMNAME=32] TCHAR
  # ... UNICODE makes TCHAR=2 bytes so:
  #
  # UNICODE DEVMODE:
  # 0    dmDeviceName[32] WCHAR (64 bytes)
  # 64   dmSpecVersion WORD
  # 66   dmDriverVersion WORD
  # 68   dmSize WORD
  # 70   dmDriverExtra WORD
  # 72   dmFields DWORD
  # 76   dmColor short
  # 78   dmDuplex short
  # 80   dmYResolution short
  # 82   dmTTOption short
  # 84   dmCollate short
  # 86   dmFormName[32] WCHAR (64 bytes) -> ends 150
  # 150  dmLogPixels WORD
  # 152  dmBitsPerPel DWORD
  # 156  dmPelsWidth DWORD
  # 160  dmPelsHeight DWORD
  # 164  dmDisplayFlags DWORD
  # 168  dmDisplayFrequency DWORD
  # 172  dmICMMethod DWORD
  # 176  dmICMIntent DWORD
  # 180  dmMediaType DWORD
  # 184  dmDitherType DWORD
  # 188  dmReserved1 DWORD
  # 192  dmReserved2 DWORD
  # 196  dmPanningWidth DWORD
  # 200  dmPanningHeight DWORD
  # total 204, plus driver extra

  $color = [BitConverter]::ToInt16($bytes, 76)
  $duplex = [BitConverter]::ToInt16($bytes, 78)
  $yres = [BitConverter]::ToInt16($bytes, 80)
  $tt = [BitConverter]::ToInt16($bytes, 82)
  $collate = [BitConverter]::ToInt16($bytes, 84)
  $logPixels = [BitConverter]::ToUInt16($bytes, 150)
  $bpp = [BitConverter]::ToUInt32($bytes, 152)
  $pelW = [BitConverter]::ToUInt32($bytes, 156)
  $pelH = [BitConverter]::ToUInt32($bytes, 160)
  $icmMethod = [BitConverter]::ToUInt32($bytes, 172)
  $icmIntent = [BitConverter]::ToUInt32($bytes, 176)
  $mediaType = [BitConverter]::ToUInt32($bytes, 180)
  $dither = [BitConverter]::ToUInt32($bytes, 184)
  Write-Output ("dmColor=" + $color + " (1=mono,2=color) dmDuplex=" + $duplex + " dmYRes=" + $yres + " dmTTOption=" + $tt + " dmCollate=" + $collate)
  Write-Output ("dmLogPixels=" + $logPixels + " dmBitsPerPel=" + $bpp + " dmPels=" + $pelW + "x" + $pelH)
  Write-Output ("dmICMMethod=" + $icmMethod + " dmICMIntent=" + $icmIntent + " dmMediaType=" + $mediaType + " dmDitherType=" + $dither)
  # DMDITHER_* : 0=default,1=none,2-10 = FS1-FS4, coarse/fine/gray, 11=sparse?, 16=reserved
  $ditherNames = @{
    0='DEFAULT'; 1='NONE'; 2='FS1'; 3='FS2'; 4='FS3'; 5='FS4';
    6='CAPTURE'; 7='SOLID'; 8='YCCK'; 9='YCCK2'; 10='YCCK3';
    11='YCCK4'; 12='CITY1'; 13='CITY2'; 14='CITY3'; 15='CITY4'
  }
  $dn = if ($ditherNames.ContainsKey([int]$dither)) { $ditherNames[[int]$dither] } else { 'UNKNOWN' }
  Write-Output ("  => dither name: " + $dn)

  # dmFields flags for which are set
  $flags = @()
  if ($dmFields -band 0x00000001) { $flags += 'ORIENTATION' }
  if ($dmFields -band 0x00000002) { $flags += 'PAPERSIZE' }
  if ($dmFields -band 0x00000004) { $flags += 'PAPERLENGTH' }
  if ($dmFields -band 0x00000008) { $flags += 'PAPERWIDTH' }
  if ($dmFields -band 0x00000010) { $flags += 'SCALE' }
  if ($dmFields -band 0x00000020) { $flags += 'POSITION' }
  if ($dmFields -band 0x00000040) { $flags += 'NUPLICATE' }
  if ($dmFields -band 0x00000080) { $flags += 'COPIES' }
  if ($dmFields -band 0x00000100) { $flags += 'DEFAULTSOURCE' }
  if ($dmFields -band 0x00000200) { $flags += 'PRINTQUALITY' }
  if ($dmFields -band 0x00000400) { $flags += 'COLOR' }
  if ($dmFields -band 0x00000800) { $flags += 'DUPLEX' }
  if ($dmFields -band 0x00001000) { $flags += 'YRESOLUTION' }
  if ($dmFields -band 0x00002000) { $flags += 'TTOPTION' }
  if ($dmFields -band 0x00004000) { $flags += 'COLLATE' }
  if ($dmFields -band 0x00008000) { $flags += 'FORMNAME' }
  if ($dmFields -band 0x00010000) { $flags += 'LOGPIXELS' }
  if ($dmFields -band 0x00020000) { $flags += 'BITSPERPEL' }
  if ($dmFields -band 0x00040000) { $flags += 'WIDTH' }
  if ($dmFields -band 0x00080000) { $flags += 'HEIGHT' }
  if ($dmFields -band 0x00100000) { $flags += 'DISPLAYFLAGS' }
  if ($dmFields -band 0x00200000) { $flags += 'DISPLAYFREQUENCY' }
  if ($dmFields -band 0x00400000) { $flags += 'ICMMETHOD' }
  if ($dmFields -band 0x00800000) { $flags += 'ICMINTENT' }
  if ($dmFields -band 0x01000000) { $flags += 'MEDIATYPE' }
  if ($dmFields -band 0x02000000) { $flags += 'DITHER' }
  Write-Output ("  dmFields flags: " + ($flags -join ', '))
  Write-Output ("  dmSize header vs actual bytes: declared " + $dmSize + ", total " + $bytes.Length)
}

# Also dump DefaultPrintTicket / UserPrintTicket raw XML via internal serialization if possible
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Get-TicketXml($ticket) {
  # PrintTicket has no public WriteTo; use reflection on internal helper
  $t = $ticket.GetType()
  # Try GetXml / Save / WriteTo variants
  foreach ($name in @('GetXml', 'ToXml', 'WriteTo', 'Save')) {
    $m = $t.GetMethod($name)
    if ($m) { Write-Output ("  found method " + $name) }
  }
  # Fallback: serialize via XpsDocumentWriter is not helpful.
  # Use PrintTicket via PrintQueue.GetPrintTicket? Not available.
  # Reflect: System.Printing.PrintTicket has internal field m_xml or similar
  $fields = $t.GetFields([System.Reflection.BindingFlags]::NonPublic -bor [System.Reflection.BindingFlags]::Instance)
  foreach ($f in $fields) {
    Write-Output ("  nonpublic field: " + $f.Name + " type=" + $f.FieldType.Name)
  }
}

Write-Output '=== DefaultPrintTicket internals ==='
Get-TicketXml $queue.DefaultPrintTicket
Write-Output '=== UserPrintTicket internals ==='
Get-TicketXml $queue.UserPrintTicket

# Check if DefaultPrintTicket XML can be obtained via GetPrintCapabilities pattern:
# PrintTicket is created from a stream internally. Try saving via XmlWriter through
# reflection on PrintTicket's m_innerObject / PrintTicketWriter
