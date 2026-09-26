$ErrorActionPreference = 'Stop'
Import-Module PrintManagement -ErrorAction SilentlyContinue
Add-Type -AssemblyName System.Printing
Add-Type -AssemblyName ReachFramework
Add-Type -AssemblyName PresentationCore
Add-Type -AssemblyName WindowsBase

$printerName = 'EPSON TM-T88III Receipt'

$cmd = Get-Command Get-PrintQueue -ErrorAction SilentlyContinue
if ($cmd) {
  Get-PrintQueue -Name $printerName | Set-PrintQueue -IsPaused $true
  Write-Output 'Queue paused via PrintManagement'
} else {
  Write-Output 'Get-PrintQueue unavailable - submitting without pause'
}

$w = [double](75 * 100); $h = [double](81 * 100)
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)
$ticket = $queue.DefaultPrintTicket
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$ticket.PageMediaSize = $pms
try { $ticket.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $ticket.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}
try { $m = New-Object System.Printing.PageMargin; $m.Left=0; $m.Top=0; $m.Right=0; $m.Bottom=0; $ticket.PageMargin = $m } catch {}

$dv = New-Object System.Windows.Media.DrawingVisual
$dc = $dv.RenderOpen()
$ft = New-Object System.Windows.Media.FormattedText('TEST', [System.Globalization.CultureInfo]::InvariantCulture, [System.Windows.FlowDirection]::LeftToRight, (New-Object System.Windows.Media.Typeface('Arial')), 24, [System.Windows.Media.Brushes]::Black, 1.25)
$dc.DrawText($ft, 10, 10)
$dc.Close()
$writer = [System.Printing.PrintQueue]::CreateXpsDocumentWriter($queue)
$writer.Write($dv, $ticket)
Write-Output 'XPS test job submitted'

Start-Sleep -Milliseconds 800

function Get-TicketXmlFrom($t) {
  $f = $t.GetType().GetField('_printTicket', [System.Reflection.BindingFlags]'NonPublic,Instance')
  $inner = $f.GetValue($t)
  $ff = $inner.GetType().GetField('_xmlDoc', [System.Reflection.BindingFlags]'NonPublic,Instance')
  return $ff.GetValue($inner).OuterXml
}

$queue.Refresh()
$jobs = $queue.GetJobs()
Write-Output ("Job count: " + $jobs.Count)
foreach ($j in $jobs) {
  Write-Output ("  Job: " + $j.Name + " id=" + $j.JobIdentifier + " status=" + $j.JobStatus + " pages=" + $j.NumberOfPages)
  try {
    $xml = $null
    try { $xml = Get-TicketXmlFrom $j.JobPrintTicket } catch { Write-Output ('    JobPrintTicket access: ' + $_.Exception.Message) }
    if ($xml) {
      $hasSnap = $xml -match 'PageDevmodeSnapshot'
      Write-Output ("    ticket len=" + $xml.Length + " hasSnap=" + $hasSnap)
      $doc = New-Object System.Xml.XmlDocument
      $doc.LoadXml($xml)
      if ($hasSnap) {
        $n = $doc.SelectSingleNode('//*[contains(@name,"PageDevmodeSnapshot")]/*[local-name()="Value"]')
        $b = [Convert]::FromBase64String($n.InnerText)
        $sha = [System.Security.Cryptography.SHA1]::Create()
        $hash = ($sha.ComputeHash($b) | ForEach-Object { $_.ToString('X2') }) -join ''
        Write-Output ("    snap len=" + $b.Length + " sha1=" + $hash.Substring(0,24))
        Write-Output ("    job DEVMODE paper=" + [BitConverter]::ToInt16($b,78) + " len=" + [BitConverter]::ToInt16($b,80) + " wid=" + [BitConverter]::ToInt16($b,82) + " dither=" + [BitConverter]::ToUInt32($b,200) + " color=" + [BitConverter]::ToInt16($b,92) + " qual=" + [BitConverter]::ToInt16($b,90))
        # Compare with config snapshot
        $cfg = Get-PrintConfiguration -PrinterName $printerName
        $cdoc = New-Object System.Xml.XmlDocument
        $cdoc.LoadXml($cfg.PrintTicketXML)
        $cn = $cdoc.SelectSingleNode('//*[contains(@name,"PageDevmodeSnapshot")]/*[local-name()="Value"]')
        Write-Output ("    matches config snap: " + ($n.InnerText -eq $cn.InnerText))
      }
      foreach ($fname in @('psk:PageMediaSize','psk:PageOutputColor','psk:PageResolution','psk:PageOrientation','psk:JobInputBin')) {
        $node = $doc.SelectSingleNode('//*[local-name()="Feature" and @name="' + $fname + '"]')
        if ($node) { Write-Output ("    " + $fname + ": " + $node.OuterXml.Substring(0, [Math]::Min(350, $node.OuterXml.Length))) }
      }
      # margin / page scaling / quality
      foreach ($pname in @('psk:PageMargin','psk:PageScalingFactor','psk:PageOutputQuality','psk:JobOutputBin')) {
        $node = $doc.SelectSingleNode('//*[contains(@name,"' + $pname + '")]')
        if ($node) { Write-Output ("    found " + $pname) }
      }
    }
  } catch { Write-Output ('    err: ' + $_.Exception.Message) }
}

if ($cmd) {
  Get-PrintQueue -Name $printerName | Set-PrintQueue -IsPaused $false
  Write-Output 'Queue resumed'
}
