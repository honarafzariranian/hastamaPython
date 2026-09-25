Add-Type -AssemblyName System.Drawing
# Test New-Object Rectangle syntax
try {
  $dest1 = New-Object System.Drawing.Rectangle(0, 0, 295, 319)
  Write-Output ("dest1_type=" + $dest1.GetType().FullName)
  Write-Output ("dest1_val=" + $dest1)
} catch { Write-Output ("dest1_err=" + $_.Exception.Message) }

try {
  $dest2 = New-Object -TypeName System.Drawing.Rectangle -ArgumentList 0, 0, 295, 319
  Write-Output ("dest2_type=" + $dest2.GetType().FullName)
  Write-Output ("dest2_val=" + $dest2)
} catch { Write-Output ("dest2_err=" + $_.Exception.Message) }

try {
  $dest3 = [System.Drawing.Rectangle]::new(0, 0, 295, 319)
  Write-Output ("dest3=" + $dest3)
} catch { Write-Output ("dest3_err=" + $_.Exception.Message) }

# Test DrawImage overloads with a bitmap
$bmp = New-Object System.Drawing.Bitmap(10, 10)
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.PageUnit = [System.Drawing.GraphicsUnit]::Display
$img = [System.Drawing.Image]::FromFile((Join-Path $env:TEMP 'hastama-size-check.png'))
try {
  $d = New-Object System.Drawing.Rectangle(0, 0, 295, 319)
  $s = New-Object System.Drawing.Rectangle(0, 0, $img.Width, $img.Height)
  Write-Output ("d_type=" + $d.GetType().Name + " s_type=" + $s.GetType().Name)
  $g.DrawImage($img, $d, $s, [System.Drawing.GraphicsUnit]::Pixel)
  Write-Output 'draw4=ok'
} catch { Write-Output ("draw4_err=" + $_.Exception.Message) }
try {
  $g.DrawImage($img, [float]0, [float]0, [float]295, [float]319)
  Write-Output 'drawfloat=ok'
} catch { Write-Output ("drawfloat_err=" + $_.Exception.Message) }
$g.Dispose(); $bmp.Dispose(); $img.Dispose()
