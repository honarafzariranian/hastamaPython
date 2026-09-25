# Register 75x81mm form via winspool AddForm with correct unmarshalling
param(
  [string]$PrinterName = 'EPSON TM-T88III Receipt',
  [int]$Width = 295,
  [int]$Height = 319,
  [string]$FormName = 'Hastama 75x81'
)

$src = @'
using System;
using System.Runtime.InteropServices;

public static class SpoolForm {
  [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
  public struct FORM_INFO_1 {
    public IntPtr pName;      // LPWSTR
    public uint Flags;
    public int SizeX;         // SIZEL.cx  (1/100 inch)
    public int SizeY;         // SIZEL.cy
    public int ImageLeft;
    public int ImageTop;
    public int ImageRight;
    public int ImageBottom;
  }

  [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
  public static extern bool OpenPrinter(string name, out IntPtr h, IntPtr pd);

  [DllImport("winspool.drv", SetLastError = true)]
  public static extern bool ClosePrinter(IntPtr h);

  [DllImport("winspool.drv", SetLastError = true)]
  public static extern bool AddForm(IntPtr h, int level, ref FORM_INFO_1 form);

  [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
  public static extern bool DeleteForm(IntPtr h, string name);
}
'@
Add-Type -TypeDefinition $src

$h = [IntPtr]::Zero
if (-not [SpoolForm]::OpenPrinter($PrinterName, [ref]$h, [IntPtr]::Zero)) {
  Write-Output ("open_err=" + [Runtime.InteropServices.Marshal]::GetLastWin32Error())
  exit 1
}
try {
  [void][SpoolForm]::DeleteForm($h, $FormName)
  $namePtr = [Runtime.InteropServices.Marshal]::StringToHGlobalUni($FormName)
  try {
    $f = New-Object SpoolForm+FORM_INFO_1
    $f.pName = $namePtr
    $f.Flags = 0
    $f.SizeX = $Width
    $f.SizeY = $Height
    $f.ImageLeft = 0
    $f.ImageTop = 0
    $f.ImageRight = $Width
    $f.ImageBottom = $Height
    $ok = [SpoolForm]::AddForm($h, 1, [ref]$f)
    $err = [Runtime.InteropServices.Marshal]::GetLastWin32Error()
    Write-Output ("addform_ok=" + $ok + " err=" + $err)
  } finally {
    [Runtime.InteropServices.Marshal]::FreeHGlobal($namePtr)
  }
} finally {
  [void][SpoolForm]::ClosePrinter($h)
}

Add-Type -AssemblyName System.Drawing
$doc = New-Object System.Drawing.Printing.PrintDocument
$doc.PrinterSettings.PrinterName = $PrinterName
Write-Output '--- paper sizes ---'
$found = $null
foreach ($ps in $doc.PrinterSettings.PaperSizes) {
  Write-Output ("  " + $ps.PaperName + " " + $ps.Width + "x" + $ps.Height)
  if ($ps.PaperName -eq $FormName) { $found = $ps }
}
if ($found) {
  $doc.DefaultPageSettings.PaperSize = $found
  $doc.PrinterSettings.DefaultPageSettings.PaperSize = $found
  Write-Output ("dotnet_ok=" + $doc.DefaultPageSettings.PaperSize.PaperName + " " + $doc.DefaultPageSettings.PaperSize.Width + "x" + $doc.DefaultPageSettings.PaperSize.Height)
} else {
  Write-Output 'not_listed'
}
