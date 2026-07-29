[CmdletBinding()]
param(
  [Parameter(Mandatory = $true)][string]$Root,
  [ValidatePattern('^[A-Za-z0-9_.-]+$')][string]$ApacheServiceName = 'FLUS_Apache'
)

$ErrorActionPreference = 'Stop'
$Root = [System.IO.Path]::GetFullPath($Root)
$backupBase = Join-Path $Root 'upgrade_backups'
$pointer = Join-Path $backupBase 'last_upgrade_backup.txt'

if (-not (Test-Path -LiteralPath $pointer)) {
  exit 0
}

$backupRoot = ([System.IO.File]::ReadAllText($pointer)).Trim()
if ($backupRoot -eq '' -or -not $backupRoot.StartsWith($backupBase, [StringComparison]::OrdinalIgnoreCase)) {
  throw 'No se pudo validar el estado previo de servicios.'
}

if (Test-Path -LiteralPath (Join-Path $backupRoot 'apache_was_running.flag')) {
  $service = Get-Service -Name $ApacheServiceName -ErrorAction SilentlyContinue
  if ($service -and $service.Status -ne 'Running') {
    Start-Service -Name $ApacheServiceName -ErrorAction Stop
    $service.WaitForStatus('Running', [TimeSpan]::FromSeconds(20))
  }
}

Write-Host 'Estado de servicios restaurado.'
