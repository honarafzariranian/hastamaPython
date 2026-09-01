<#
.SYNOPSIS
    Removes the Hastama auto-start scheduled task.
#>

$TaskName = "HastamaServer"

$existing = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
if ($existing) {
    Stop-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false
    Write-Host "Task '$TaskName' removed successfully."
} else {
    Write-Host "Task '$TaskName' not found. Nothing to remove."
}
