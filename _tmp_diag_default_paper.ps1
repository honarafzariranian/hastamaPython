Add-Type -AssemblyName System.Drawing
$printer = 'EPSON TM-T88III Receipt'
# Default paper via Win32_DEVMODE is hard; use .NET defaults without override
$doc = New-Object System.Drawing.Printing.PrintDocument
$doc.PrinterSettings.PrinterName = $printer
$def = $doc.PrinterSettings.DefaultPageSettings.PaperSize
Write-Output ('default_paper=' + $def.PaperName + ' ' + $def.Width + 'x' + $def.Height + ' kind=' + $def.Kind)
Write-Output ('default_bounds=' + $doc.PrinterSettings.DefaultPageSettings.Bounds)
Write-Output ('printer_isvalid=' + $doc.PrinterSettings.IsValid)
# Copies / print controller
Write-Output ('printer_settings_name=' + $doc.PrinterSettings.PrinterName)
# List DEVMODE-related via PrintTicket if available
try {
  $add = Add-Type -Namespace Wp -Name PrintHelper -MemberDefinition @'
[System.Runtime.InteropServices.DllImport("winspool.drv", CharSet=System.Runtime.InteropServices.CharSet.Unicode, SetLastError=true)]
public static extern bool GetDefaultPrinter(System.Text.StringBuilder name, ref int size);
'@ -PassThru
  $sb = New-Object System.Text.StringBuilder 256
  $sz = 256
  if ([Wp.PrintHelper]::GetDefaultPrinter($sb, [ref]$sz)) {
    Write-Output ('windows_default_printer=' + $sb.ToString())
  }
} catch { Write-Output ('wp_err=' + $_.Exception.Message) }
