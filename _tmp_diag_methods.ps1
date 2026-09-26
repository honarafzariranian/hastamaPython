Add-Type -AssemblyName System.Printing
$local = New-Object System.Printing.LocalPrintServer
$q = $local.GetPrintQueue('EPSON TM-T88III Receipt')
Write-Output '--- Methods matching Job/Suspend/Pause ---'
$q | Get-Member -MemberType Method | Where-Object { $_.Name -match 'Job|Suspend|Pause|Reserve|Write' } | ForEach-Object { $_.Name }
Write-Output '--- All public methods ---'
$q.GetType().GetMethods([System.Reflection.BindingFlags]'Public,Instance') | ForEach-Object { $_.Name } | Sort-Object -Unique
