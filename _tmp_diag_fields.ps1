$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework

$printerName = 'EPSON TM-T88III Receipt'
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Get-Internal($t) { return $t.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance').GetValue($t) }
function Get-Doc($inner) { return $inner.GetType().GetField('_xmlDoc', [System.Reflection.BindingFlags]'NonPublic,Instance').GetValue($inner) }

Write-Output '=== BEFORE ==='
$ticket = $queue.DefaultPrintTicket
$inner = Get-Internal $ticket
$doc = Get-Doc $inner
$msNode = $doc.SelectSingleNode('//*[local-name()="Feature" and @name="psk:PageMediaSize"]')
Write-Output ('xml media: ' + $msNode.OuterXml.Substring(0, [Math]::Min(220, $msNode.OuterXml.Length)))
Write-Output ('prop media: ' + $ticket.PageMediaSize)
Write-Output ('prop color: ' + $ticket.ColorSetting)
Write-Output ('prop orient: ' + $ticket.PageOrientation)
Write-Output 'non-null fields BEFORE:'
$inner.GetType().GetFields([System.Reflection.BindingFlags]'NonPublic,Instance') | ForEach-Object {
  try {
    $v = $_.GetValue($inner)
    if ($null -ne $v) {
      $s = $v.ToString()
      if ($s.Length -gt 100) { $s = $s.Substring(0, 100) }
      Write-Output ('  ' + $_.Name + ' : ' + $v.GetType().Name + ' = ' + $s)
    }
  } catch {}
}

$w = [double](75 * 100); $h = [double](81 * 100)
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$ticket.PageMediaSize = $pms
try { $ticket.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $ticket.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}

Write-Output '=== AFTER SET ==='
$inner2 = Get-Internal $ticket
$doc2 = Get-Doc $inner2
Write-Output ('same inner object? ' + [object]::ReferenceEquals($inner, $inner2))
Write-Output ('same doc object? ' + [object]::ReferenceEquals($doc, $doc2))
$msNode2 = $doc2.SelectSingleNode('//*[local-name()="Feature" and @name="psk:PageMediaSize"]')
Write-Output ('xml media after: ' + $msNode2.OuterXml.Substring(0, [Math]::Min(250, $msNode2.OuterXml.Length)))
Write-Output ('prop media after: ' + $ticket.PageMediaSize)
Write-Output ('prop color after: ' + $ticket.ColorSetting)
Write-Output 'non-null fields AFTER:'
$inner2.GetType().GetFields([System.Reflection.BindingFlags]'NonPublic,Instance') | ForEach-Object {
  try {
    $v = $_.GetValue($inner2)
    if ($null -ne $v) {
      $s = $v.ToString()
      if ($s.Length -gt 100) { $s = $s.Substring(0, 100) }
      Write-Output ('  ' + $_.Name + ' : ' + $v.GetType().Name + ' = ' + $s)
    }
  } catch {}
}
