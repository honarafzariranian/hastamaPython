Add-Type -AssemblyName System.Printing
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue('EPSON TM-T88III Receipt')
$t = $queue.DefaultPrintTicket
$f = $t.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance')
$inner = $f.GetValue($t)
$ms = New-Object System.IO.MemoryStream
$inner.SaveTo($ms)
$bytes = $ms.ToArray()
Write-Output ("len=" + $bytes.Length)
Write-Output ("pos after save=" + $ms.Position)
Write-Output ("first32: " + (($bytes[0..31] | ForEach-Object { $_.ToString('X2') }) -join ' '))
# Also try _xmlDoc OuterXml
$ff = $inner.GetType().GetField('_xmlDoc', [System.Reflection.BindingFlags]'NonPublic,Instance')
$doc = $ff.GetValue($inner)
if ($doc) {
  $ox = $doc.OuterXml
  Write-Output ("doc OuterXml len=" + $ox.Length + " first40=" + $ox.Substring(0, [Math]::Min(40, $ox.Length)))
} else {
  Write-Output 'doc null'
}
# list inner fields
$inner.GetType().GetFields([System.Reflection.BindingFlags]'NonPublic,Instance') | ForEach-Object {
  try {
    $v = $_.GetValue($inner)
    $s = if ($null -eq $v) { 'null' } elseif ($v -is [byte[]]) { 'byte[' + $v.Length + ']' } elseif ($v -is [xml] -or $v -is [System.Xml.XmlDocument]) { 'xml len=' + $v.OuterXml.Length } else { $v.GetType().Name + '=' + $v }
    if ($s.Length -gt 80) { $s = $s.Substring(0, 80) }
    Write-Output ("  " + $_.Name + " : " + $s)
  } catch { Write-Output ("  " + $_.Name + " : err") }
}
