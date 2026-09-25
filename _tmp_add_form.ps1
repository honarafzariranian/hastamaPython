# Register exact 75x81mm form on the Epson queue via winspool AddForm
param(
  [string]$PrinterName = 'EPSON TM-T88III Receipt',
  [int]$Width = 295,   # 75mm in 1/100 inch
  [int]$Height = 319,  # 81mm
  [string]$FormName = 'Hastama 75x81'
)

$src = @"
using System;
using System.Runtime.InteropServices;
using System.Text;

public class WinSpool {
  [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
  public struct FORM_1 {
    public string pName;
    public uint sizeRight;
    public uint sizeBottom; // total unused; real fields below via offset carefully
  }

  // FORM structure (level 1) for AddForm
  [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
  public struct FORM {
    [MarshalAs(UnmanagedType.LPWStr)] public string pName;
    public uint Flags;
    public uint SizeRight;   // actually POINT (not used as name)
    // We'll use explicit layout matching winspool FORM:
  }

  // Correct FORM for AddForm level 1:
  // typedef struct _FORM_1 {
  //   LPWSTR pName;
  //   DWORD Flags;
  //   SIZEL Size;      // cx, cy in 1/100 mm? NO - for forms it's 1/100 inch in DEVMODE
  //   RECTL ImageArea; // actually for AddForm it's different
  // } Actually Windows FORM:
  //   LPWSTR pName
  //   DWORD Flags
  //   SIZEL Size   (LONG cx, cy) - paper size in 1/100 inch? Documentation says 1/100 mm for SIZEL in forms... 
  //   Wait: MSDN AddForm FORM structure uses 1/100 inch for Size and ImageArea when used with DEVMODE.
  //   Actually: "The size of the paper, in hundredths of a millimeter" - NO
  //   MSDN: FORM.pName, Flags, Size (SIZEL), ImageArea (RECTL) - Size and ImageArea are in 1/100 inch.

  [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
  public struct FORM1 {
    [MarshalAs(UnmanagedType.LPWStr)] public string pName;
    public int Flags;
    public int SizeX;
    public int SizeY;
    public int ImageLeft;
    public int ImageTop;
    public int ImageRight;
    public int ImageBottom;
  }

  [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
  public static extern bool OpenPrinter(string pPrinterName, out IntPtr hPrinter, IntPtr pDefault);

  [DllImport("winspool.drv", SetLastError = true)]
  public static extern bool ClosePrinter(IntPtr hPrinter);

  [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
  public static extern bool AddForm(IntPtr hPrinter, int level, ref FORM1 pForm);

  [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
  public static extern bool DeleteForm(IntPtr hPrinter, string pFormName);

  [DllImport("winspool.drv", CharSet = CharSet.Unicode, SetLastError = true)]
  public static extern bool GetForm(IntPtr hPrinter, string pFormName, int level, IntPtr pForm, int cbBuf, out int pcbNeeded);
}
"@

try { Add-Type -TypeDefinition $src -ErrorAction Stop } catch {
  if ($_.Exception.Message -notmatch 'already exists|duplicate') { throw }
}

$h = [IntPtr]::Zero
if (-not [WinSpool]::OpenPrinter($PrinterName, [ref]$h, [IntPtr]::Zero)) {
  $err = [Runtime.InteropServices.Marshal]::GetLastWin32Error()
  Write-Output ("open_err=" + $err)
  exit 1
}
try {
  # delete existing if any
  [void][WinSpool]::DeleteForm($h, $FormName)

  $f = New-Object WinSpool+FORM1
  $f.pName = $FormName
  $f.Flags = 0
  $f.SizeX = $Width
  $f.SizeY = $Height
  $f.ImageLeft = 0
  $f.ImageTop = 0
  $f.ImageRight = $Width
  $f.ImageBottom = $Height
  $ok = [WinSpool]::AddForm($h, 1, [ref]$f)
  $err = [Runtime.InteropServices.Marshal]::GetLastWin32Error()
  Write-Output ("addform_ok=" + $ok + " err=" + $err)

  # verify via .NET paper list
  Add-Type -AssemblyName System.Drawing
  $doc = New-Object System.Drawing.Printing.PrintDocument
  $doc.PrinterSettings.PrinterName = $PrinterName
  $found = $null
  foreach ($ps in $doc.PrinterSettings.PaperSizes) {
    if ($ps.PaperName -eq $FormName) { $found = $ps; break }
    Write-Output ("  list=" + $ps.PaperName + " " + $ps.Width + "x" + $ps.Height)
  }
  if ($found) {
    Write-Output ("found=" + $found.PaperName + " " + $found.Width + "x" + $found.Height)
    $doc.DefaultPageSettings.PaperSize = $found
    $cur = $doc.DefaultPageSettings.PaperSize
    Write-Output ("applied=" + $cur.PaperName + " " + $cur.Width + "x" + $cur.Height)
    Write-Output ("printer_default=" + $doc.PrinterSettings.DefaultPageSettings.PaperSize.PaperName)
    $doc.PrinterSettings.DefaultPageSettings.PaperSize = $found
    Write-Output ("printer_default_after=" + $doc.PrinterSettings.DefaultPageSettings.PaperSize.PaperName + " " + $doc.PrinterSettings.DefaultPageSettings.PaperSize.Width)
  } else {
    Write-Output 'not_in_dotnet_list'
  }
} finally {
  [void][WinSpool]::ClosePrinter($h)
}
