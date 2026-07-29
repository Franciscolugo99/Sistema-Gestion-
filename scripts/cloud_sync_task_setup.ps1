[CmdletBinding(DefaultParameterSetName = 'Status')]
param(
  [string]$Root = "",
  [ValidatePattern('^[A-Za-z0-9_.-]+$')][string]$TaskName = "FLUS_CloudSync",
  [int]$IntervalMinutes = 1,
  [Parameter(ParameterSetName = 'Install')][switch]$Install,
  [Parameter(ParameterSetName = 'Remove')][switch]$Remove,
  [Parameter(ParameterSetName = 'Status')][switch]$StatusOnly
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"
$taskName = $TaskName

if ($Root -eq "") {
  $Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
}
$Root = [System.IO.Path]::GetFullPath($Root)
$IntervalMinutes = [Math]::Max(1, [Math]::Min(30, $IntervalMinutes))
$runner = Join-Path $Root "scripts\cloud_sync_task_runner.ps1"
$storage = Join-Path $Root "storage"
$statePath = Join-Path $storage "cloud_sync_scheduler_state.json"

function Write-SchedulerState([bool]$Enabled, [bool]$Registered) {
  if (-not (Test-Path -LiteralPath $storage)) {
    New-Item -ItemType Directory -Force -Path $storage | Out-Null
  }
  $state = [ordered]@{
    enabled = $Enabled
    task_registered = $Registered
    task_name = $taskName
    interval_minutes = if ($Enabled) { $IntervalMinutes } else { 0 }
    updated_at = (Get-Date).ToString('o')
  }
  $json = $state | ConvertTo-Json
  $temporary = $statePath + '.tmp'
  [System.IO.File]::WriteAllText($temporary, $json, (New-Object System.Text.UTF8Encoding($false)))
  Move-Item -LiteralPath $temporary -Destination $statePath -Force
}

function Get-CloudTask {
  return Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
}

function Test-TaskMatches($Task) {
  if (-not $Task) { return $false }
  $expected = [System.IO.Path]::GetFullPath($runner)
  foreach ($action in @($Task.Actions)) {
    if (($action.Execute + '') -notmatch '(?i)powershell(?:\.exe)?$') { continue }
    if (($action.Arguments + '').IndexOf($expected, [StringComparison]::OrdinalIgnoreCase) -ge 0) {
      return $Task.State -ne 'Disabled'
    }
  }
  return $false
}

if ($StatusOnly -or (-not $Install -and -not $Remove)) {
  $task = Get-CloudTask
  $ready = Test-TaskMatches $task
  Write-SchedulerState -Enabled:$ready -Registered:([bool]$task)
  Write-Host "Tarea automatica: $(if ($ready) { 'operativa' } else { 'requiere configuracion' })"
  if ($ready) { exit 0 }
  exit 2
}

$identity = [Security.Principal.WindowsIdentity]::GetCurrent()
$principal = New-Object Security.Principal.WindowsPrincipal($identity)
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
  throw "Se requieren permisos de administrador para configurar la sincronizacion automatica."
}

if ($Remove) {
  $task = Get-CloudTask
  if ($task) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
  }
  Write-SchedulerState -Enabled:$false -Registered:$false
  Write-Host "Sincronizacion automatica desactivada."
  exit 0
}

if (-not (Test-Path -LiteralPath $runner)) {
  throw "No se encontro el ejecutor de sincronizacion automatica."
}

$powershell = Join-Path $env:SystemRoot "System32\WindowsPowerShell\v1.0\powershell.exe"
$arguments = '-ExecutionPolicy Bypass -NoProfile -File "' + $runner + '" -Root "' + $Root + '"'
$action = New-ScheduledTaskAction -Execute $powershell -Argument $arguments -WorkingDirectory $Root
$startup = New-ScheduledTaskTrigger -AtStartup
$repeat = New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) `
  -RepetitionInterval (New-TimeSpan -Minutes $IntervalMinutes) `
  -RepetitionDuration (New-TimeSpan -Days 3650)
$settings = New-ScheduledTaskSettingsSet `
  -StartWhenAvailable `
  -MultipleInstances IgnoreNew `
  -RestartCount 3 `
  -RestartInterval (New-TimeSpan -Minutes 1) `
  -ExecutionTimeLimit (New-TimeSpan -Minutes 5)

Register-ScheduledTask `
  -TaskName $taskName `
  -Action $action `
  -Trigger @($startup, $repeat) `
  -Settings $settings `
  -User 'SYSTEM' `
  -RunLevel Highest `
  -Force | Out-Null

$registered = Test-TaskMatches (Get-CloudTask)
Write-SchedulerState -Enabled:$registered -Registered:$registered
if (-not $registered) {
  throw "La tarea automatica no quedo operativa."
}

Start-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
Write-Host "Sincronizacion automatica operativa cada $IntervalMinutes minuto(s)."
