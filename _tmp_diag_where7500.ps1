$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework

$printerName = 'EPSON TM-T88III Receipt'
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Get-Internal($t) { return $t.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance').GetValue($t) }
function Get-Doc($inner) { return $inner.GetType().GetField('_xmlDoc', [System.Reflection.BindingFlags]'NonPublic,Instance').GetValue($inner) }

$ticket = $queue.DefaultPrintTicket
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, 7500, 8100)
$ticket.PageMediaSize = $pms

$inner = Get-Internal $ticket
$doc = Get-Doc $inner
$msNode = $doc.SelectSingleNode('//*[local-name()="Feature" and @name="psk:PageMediaSize"]')
Write-Output '=== _xmlDoc PageMediaSize after set ==='
Write-Output $msNode.OuterXml

$vals = $msNode.SelectNodes('.//*[local-name()="Value"]')
foreach ($v in $vals) { Write-Output ('VALUE: ' + $v.InnerText) }

# Search all fields for 7500
Write-Output '=== fields containing 7500 or new object ==='
$inner.GetType().GetFields([System.Reflection.BindingFlags]'NonPublic,Instance') | ForEach-Object {
  try {
    $v = $_.GetValue($inner)
    if ($null -eq $v) { return }
    $s = "$v"
    if ($s -match '7500|8100' -or $_.Name -match 'Media|pending|change|setter|user') {
      $out = if ($s.Length -gt 150) { $s.Substring(0,150) } else { $s }
      Write-Output ('  ' + $_.Name + ' : ' + $v.GetType().Name + ' = ' + $out)
    }
  } catch {}
}

# Also list ALL non-null again
Write-Output '=== all non-null fields ==='
$inner.GetType().GetFields([System.Reflection.BindingFlags]'NonPublic,Instance') | ForEach-Object {
  try {
    $v = $_.GetValue($inner)
    if ($null -ne $v) {
      $s = "$v"
      if ($s.Length -gt 80) { $s = $s.Substring(0,80) }
      Write-Output ('  ' + $_.Name + ' = ' + $s)
    }
  } catch {}
}

# Check public property backing - maybe PrintTicket has its own fields
Write-Output '=== PrintTicket (public) non-null fields ==='
$ticket.GetType().GetFields([System.Reflection.BindingFlags]'NonPublic,Instance') | ForEach-Object {
  try {
    $v = $_.GetValue($ticket)
    if ($null -ne $v) {
      $s = "$v"
      if ($s.Length -gt 80) { $s = $s.Substring(0,80) }
      Write-Output ('  ' + $_.Name + ' : ' + $v.GetType().Name + ' = ' + $s)
    }
  } catch {}
}
