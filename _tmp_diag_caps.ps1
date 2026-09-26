$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName ReachFramework
Add-Type -AssemblyName System.Printing

$printerName = 'EPSON TM-T88III Receipt'
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

$cap = $queue.GetPrintCapabilities()
Write-Output '=== CUSTOM FEATURES ==='
foreach ($f in $cap.CustomFeatures) {
  Write-Output ("  " + $f.FeatureName)
  foreach ($opt in $f.Options) {
    Write-Output ("    opt: " + $opt)
  }
}

Write-Output '=== PAGE OUTPUT COLOR ==='
try { $poc = $cap.PageOutputColor; Write-Output $poc } catch { Write-Output $_.Exception.Message }

Write-Output '=== NAMED FEATURES (subset) ==='
# Walk PrintCapability XML for feature names
$sw = New-Object System.IO.StringWriter
$xw = [System.Xml.XmlWriter]::Create($sw)
$cap.WriteXml($xw)
$xw.Flush()
$xml = $sw.ToString()
# extract Feature Name attributes
[regex]::Matches($xml, 'Feature[^>]*Name="([^"]+)"') | ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique | ForEach-Object { Write-Output ("  " + $_) }

Write-Output '=== DEFAULT TICKET XML ==='
$t = $queue.DefaultPrintTicket
$sw2 = New-Object System.IO.StringWriter
$xw2 = [System.Xml.XmlWriter]::Create($sw2)
$t.WriteXml($xw2)
$xw2.Flush()
Write-Output $sw2.ToString()

Write-Output '=== DITHER-ISH STRINGS IN CAPS ==='
if ($xml -match 'dither|halftone|raster|DmDither|quality') {
  [regex]::Matches($xml, '.{0,80}(dither|halftone|raster|DmDither|Quality).{0,80}', 'IgnoreCase') | ForEach-Object { Write-Output $_.Value }
} else {
  Write-Output '  none'
}
