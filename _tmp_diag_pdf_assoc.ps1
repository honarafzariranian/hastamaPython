Write-Output '--- assoc ---'
cmd /c 'assoc .pdf'
Write-Output '--- ftype lines with pdf ---'
cmd /c 'ftype' | Select-String -Pattern 'pdf|Edge|Acrobat|Sumatra|Foxit'
Write-Output '--- userchoice ---'
try {
  $uc = Get-ItemProperty 'HKCU:\Software\Microsoft\Windows\CurrentVersion\Explorer\FileExts\.pdf\UserChoice' -ErrorAction Stop
  Write-Output ('ProgId=' + $uc.ProgId)
} catch { Write-Output 'no userchoice' }
