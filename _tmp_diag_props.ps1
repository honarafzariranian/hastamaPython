$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework
Add-Type -AssemblyName WindowsBase
Add-Type -AssemblyName PresentationCore

$printerName = 'EPSON TM-T88III Receipt'
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Get-Internal($t) { return $t.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance').GetValue($t) }
function Get-Doc($inner) { return $inner.GetType().GetField('_xmlDoc', [System.Reflection.BindingFlags]'NonPublic,Instance').GetValue($inner) }

$w = [double](75 * 100); $h = [double](81 * 100)
$ticket = $queue.DefaultPrintTicket
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$ticket.PageMediaSize = $pms
try { $ticket.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $ticket.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}

Write-Output ('prop PageMediaSize: ' + $ticket.PageMediaSize)

$inner = Get-Internal $ticket
# dump cache field _pageMediaSize
$pmsField = $inner.GetType().GetField('_pageMediaSize', [System.Reflection.BindingFlags]'NonPublic,Instance')
$pmsObj = $pmsField.GetValue($inner)
Write-Output ('_pageMediaSize null? ' + ($null -eq $pmsObj))
if ($pmsObj) {
  Write-Output ('_pageMediaSize type: ' + $pmsObj.GetType().FullName)
  $pmsObj | Get-Member -MemberType Method,Property | ForEach-Object { $_.Name } | Sort-Object -Unique
  try { Write-Output ('  ToString: ' + $pmsObj.ToString()) } catch {}
  # try common props
  foreach ($p in 'MediaSizeWidth','MediaSizeHeight','PageMediaSizeName','OptionName') {
    try { $v = $pmsObj.$p; if ($null -ne $v) { Write-Output ("  " + $p + "=" + $v) } } catch {}
  }
}

# Strip snap
$doc = Get-Doc $inner
foreach ($s in $doc.SelectNodes('//*[contains(@name,"PageDevmodeSnapshot")]')) { [void]$s.ParentNode.RemoveChild($s) }
Write-Output ('prop after strip: ' + $ticket.PageMediaSize)
Write-Output ('color after strip: ' + $ticket.ColorSetting)
Write-Output ('orient after strip: ' + $ticket.PageOrientation)

# Validate against capabilities
$caps = $queue.GetPrintCapabilities()
$merged = $queue.MergeAndValidatePrintTicket($ticket)
Write-Output ('merged result: ' + $merged.ValidatedPrintTicket.PageMediaSize)
Write-Output ('validate: ' + $merged.Result)
Write-Output ('merged color: ' + $merged.ValidatedPrintTicket.ColorSetting)
