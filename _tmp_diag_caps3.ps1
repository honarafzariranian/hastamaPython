$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName ReachFramework
Add-Type -AssemblyName System.Printing

$printerName = 'EPSON TM-T88III Receipt'
$local = New-Object System.Printing.LocalPrintServer
$queue = $local.GetPrintQueue($printerName)

function Dump-Props($obj, $label) {
  Write-Output "=== $label ($($obj.GetType().Name)) ==="
  foreach ($p in $obj.GetType().GetProperties([System.Reflection.BindingFlags]::Public -bor [System.Reflection.BindingFlags]::Instance)) {
    if ($p.CanRead -and $p.GetIndexParameters().Count -eq 0) {
      try {
        $v = $p.GetValue($obj, $null)
        Write-Output ("  " + $p.Name + " = " + $v)
      } catch {
        Write-Output ("  " + $p.Name + " = <err>")
      }
    }
  }
}

$dt = $queue.DefaultPrintTicket
Dump-Props $dt 'DEFAULT TICKET'

$cap = $queue.GetPrintCapabilities()
Dump-Props $cap 'CAPABILITIES'

# Halftone / dither related members on both types
Write-Output '=== TYPE MEMBERS matching dither/halftone/color/quality ==='
foreach ($t in @([System.Printing.PrintTicket], [System.Printing.PrintCapabilities])) {
  foreach ($m in $t.GetMembers()) {
    if ($m.Name -match 'Dither|Halftone|Color|Quality|Raster|Image|Tone|Contrast|Brightness') {
      Write-Output ("  " + $t.Name + "::" + $m.Name + " (" + $m.MemberType + ")")
    }
  }
}

# After applying production mutations, show Color/Orientation/Page again
$w = [double](75 * 100); $h = [double](81 * 100)
$t2 = $queue.DefaultPrintTicket
$pms = New-Object System.Printing.PageMediaSize @([System.Printing.PageMediaSizeName]::Unknown, $w, $h)
$t2.PageMediaSize = $pms
try { $t2.PageOrientation = [System.Printing.PageOrientation]::Portrait } catch {}
try { $t2.ColorSetting = [System.Printing.PrintColorMode]::Monochrome } catch {}
try { $m = New-Object System.Printing.PageMargin; $m.Left=0; $m.Top=0; $m.Right=0; $m.Bottom=0; $t2.PageMargin = $m } catch {}
Write-Output '=== MUTATED ==='
Dump-Props $t2 'MUTATED TICKET'

# PrintQueue user-print-ticket (saved preferences) if any
Write-Output '=== USER PRINT TICKET ==='
try {
  $ut = $queue.UserPrintTicket
  if ($ut) { Dump-Props $ut 'USER TICKET' } else { Write-Output '  null' }
} catch { Write-Output ('  ' + $_.Exception.Message) }
