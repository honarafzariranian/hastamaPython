$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework

$printerName = 'EPSON TM-T88III Receipt'
$cfg = Get-PrintConfiguration -PrinterName $printerName
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Get-TicketXml($ticket) {
  $f = $ticket.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance')
  $inner = $f.GetValue($ticket)
  $ff = $inner.GetType().GetField('_xmlDoc', [System.Reflection.BindingFlags]'NonPublic,Instance')
  $doc = $ff.GetValue($inner)
  return $doc.OuterXml
}

function Get-SnapshotB64($xmlText) {
  $doc = New-Object System.Xml.XmlDocument
  $doc.LoadXml($xmlText)
  $n = $doc.SelectSingleNode('//*[contains(@name,"PageDevmodeSnapshot")]/*[local-name()="Value"]')
  if ($n) { return $n.InnerText }
  return $null
}

$cfgSnap = Get-SnapshotB64 $cfg.PrintTicketXML
$defSnap = Get-SnapshotB64 (Get-TicketXml $queue.DefaultPrintTicket)
$userSnap = Get-SnapshotB64 (Get-TicketXml $queue.UserPrintTicket)

Write-Output ("cfg snap len=" + $cfgSnap.Length + " md5-ish=" + (Get-FileHash -InputStream ([IO.MemoryStream]::new([Convert]::FromBase64String($cfgSnap))) -Algorithm SHA1).Hash.Substring(0,16))
Write-Output ("Default snap len=" + $defSnap.Length + " hash=" + (Get-FileHash -InputStream ([IO.MemoryStream]::new([Convert]::FromBase64String($defSnap))) -Algorithm SHA1).Hash.Substring(0,16))
Write-Output ("User snap len=" + $userSnap.Length + " hash=" + (Get-FileHash -InputStream ([IO.MemoryStream]::new([Convert]::FromBase64String($userSnap))) -Algorithm SHA1).Hash.Substring(0,16))
Write-Output ("cfg==default: " + ($cfgSnap -eq $defSnap))
Write-Output ("cfg==user: " + ($cfgSnap -eq $userSnap))
Write-Output ("default==user: " + ($defSnap -eq $userSnap))

# Mutate like production script and check snapshot survival
$w = [double](75 * 100); $h = [double](81 * 100)
$t = $queue.DefaultPrintTicket
$beforeMut = Get-SnapshotB64 (Get-TicketXml $t)
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$t.PageMediaSize = $pms
try { $t.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $t.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}
try { $m = New-Object System.Printing.PageMargin; $m.Left=0; $m.Top=0; $m.Right=0; $m.Bottom=0; $t.PageMargin = $m } catch {}

$afterXml = Get-TicketXml $t
$afterSnap = Get-SnapshotB64 $afterXml
Write-Output ("AFTER mutate: snap present=" + ($null -ne $afterSnap) + " unchanged=" + ($afterSnap -eq $beforeMut) + " len=" + $(if ($afterSnap) { $afterSnap.Length } else { 0 }))

# Show PageMediaSize feature in mutated XML
$doc = New-Object System.Xml.XmlDocument
$doc.LoadXml($afterXml)
$ns = New-Object System.Xml.XmlNamespaceManager($doc.NameTable)
$ns.AddNamespace('psf', 'http://schemas.microsoft.com/windows/2003/08/printing/printschemaframework')
$ns.AddNamespace('psk', 'http://schemas.microsoft.com/windows/2003/08/printing/printschemakeywords')
$ms = $doc.SelectSingleNode('//psf:Feature[@name="psk:PageMediaSize"]', $ns)
if ($ms) { Write-Output ("PageMediaSize feature: " + $ms.OuterXml.Substring(0, [Math]::Min(600, $ms.OuterXml.Length))) }
$oc = $doc.SelectSingleNode('//psf:Feature[@name="psk:PageOutputColor"]', $ns)
if ($oc) { Write-Output ("PageOutputColor: " + $oc.OuterXml) }
$pr = $doc.SelectSingleNode('//psf:Feature[@name="psk:PageResolution"]', $ns)
if ($pr) { Write-Output ("PageResolution: " + $pr.OuterXml) }
$pm = $doc.SelectSingleNode('//psf:Feature[@name="psk:PageMargin"]', $ns)
if ($pm) { Write-Output ("PageMargin: " + $pm.OuterXml) } else { Write-Output 'PageMargin feature: ABSENT' }

# Private blob dither-like fields: dump Epson section headers
$bytes = [Convert]::FromBase64String($afterSnap)
$priv = $bytes[220..($bytes.Length-1)]
# Search for common Epson section markers (ASCII or UTF16)
$ascii = [Text.Encoding]::ASCII.GetString($priv)
# Find printable runs
$runs = [regex]::Matches($ascii, '[\x20-\x7E]{4,}') | ForEach-Object { $_.Value } | Select-Object -First 40
Write-Output '=== Epson private ASCII runs ==='
$runs | ForEach-Object { Write-Output ("  " + $_) }
