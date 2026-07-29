[CmdletBinding()]
param(
  [string]$Root = "",
  [string]$LicenseCloudUrl = "https://api.flus.com.ar/license-check.php",
  [string]$SyncUrl = "https://api.flus.com.ar/sync-ingest.php",
  [string]$Token = "",
  [string]$BranchCode = "central",
  [string]$BranchName = "Casa central",
  [string]$InstallationName = "",
  [string]$BranchAddress = "",
  [int]$HeartbeatIntervalSec = 300,
  [int]$StockSnapshotIntervalSec = 900,
  [int]$SchedulerIntervalMinutes = 1,
  [string]$PhpPath = "",
  [switch]$SendInitialStock,
  [switch]$SkipMigrations,
  [switch]$SkipScheduler,
  [switch]$DisableCloud,
  [switch]$StatusOnly,
  [switch]$DryRun,
  [switch]$NonInteractive
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

if ($Root -eq "") {
  $Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
}
$Root = [System.IO.Path]::GetFullPath($Root)
$configPath = Join-Path $Root "src\config.php"
$timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$defaultLicenseCloudUrl = "https://api.flus.com.ar/license-check.php"
$defaultSyncUrl = "https://api.flus.com.ar/sync-ingest.php"
$legacyLicenseCloudUrls = @("https://flus.com.ar/admin/api/license-check.php")
$legacySyncUrls = @("https://flus.com.ar/admin/api/sync-ingest.php")

function Write-Step([string]$Text) {
  Write-Host ""
  Write-Host $Text -ForegroundColor Cyan
}

function Read-Required([string]$Label, [string]$Current = "") {
  while ($true) {
    $suffix = if ($Current -ne "") { " [$Current]" } else { "" }
    $value = Read-Host "$Label$suffix"
    if ([string]::IsNullOrWhiteSpace($value)) {
      $value = $Current
    }
    if (-not [string]::IsNullOrWhiteSpace($value)) {
      return $value.Trim()
    }
    Write-Host "Este dato es obligatorio." -ForegroundColor Yellow
  }
}

function Read-SecureText([string]$Label) {
  Write-Host "El token no se mostrara mientras escribes." -ForegroundColor DarkGray
  $secureValue = Read-Host $Label -AsSecureString
  $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureValue)
  try {
    return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
  } finally {
    if ($bstr -ne [IntPtr]::Zero) {
      [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
  }
}

function ConvertTo-PhpString([string]$Value) {
  return "'" + $Value.Replace("\", "\\").Replace("'", "\'") + "'"
}

function ConvertTo-PhpValue($Value) {
  if ($Value -is [bool]) {
    if ($Value) { return "true" }
    return "false"
  }
  if ($Value -is [int]) {
    return [string]$Value
  }
  return ConvertTo-PhpString ([string]$Value)
}

function Set-FlusDefine([string]$Content, [string]$Name, $Value) {
  $phpValue = ConvertTo-PhpValue $Value
  $line = "define('$Name', $phpValue);"
  $pattern = "(?m)^\s*define\(\s*['""]$([regex]::Escape($Name))['""]\s*,.*?\);\s*$"
  if ([regex]::IsMatch($Content, $pattern)) {
    return [regex]::Replace($Content, $pattern, $line, 1)
  }

  $blockMarker = "// ============================================`r`n// SINCRONIZACION CLOUD OPERATIVA"
  if ($Content.Contains($blockMarker)) {
    return $Content.TrimEnd() + "`r`n" + $line + "`r`n"
  }

  $managedBlock = @(
    "",
    "// ============================================",
    "// SINCRONIZACION CLOUD OPERATIVA",
    "// Generado por scripts/cloud_sync_setup.ps1",
    "// ============================================",
    $line
  ) -join "`r`n"

  return $Content.TrimEnd() + "`r`n" + $managedBlock + "`r`n"
}

function Get-FlusDefineString([string]$Content, [string]$Name) {
  $pattern = "(?m)^\s*define\(\s*['""]$([regex]::Escape($Name))['""]\s*,\s*['""]([^'""]*)['""]\s*\);\s*$"
  $match = [regex]::Match($Content, $pattern)
  if (-not $match.Success) { return "" }
  return $match.Groups[1].Value.Trim()
}

function Get-FlusDefineBool([string]$Content, [string]$Name, [bool]$Default = $false) {
  $pattern = "(?mi)^\s*define\(\s*['""]$([regex]::Escape($Name))['""]\s*,\s*(true|false)\s*\);\s*$"
  $match = [regex]::Match($Content, $pattern)
  if (-not $match.Success) { return $Default }
  return $match.Groups[1].Value.ToLowerInvariant() -eq "true"
}

function Test-FlusCloudUrl([string]$Value) {
  if ([string]::IsNullOrWhiteSpace($Value)) { return $false }
  $uri = $null
  if (-not [Uri]::TryCreate($Value.Trim(), [UriKind]::Absolute, [ref]$uri)) { return $false }
  if ($uri.Scheme -notin @('http', 'https')) { return $false }
  $localHosts = @('localhost', '127.0.0.1', '::1')
  return $uri.Scheme -eq 'https' -or $uri.Host -in $localHosts
}

function Get-FlusLicensePlan([string]$AppRoot) {
  $licensePath = Join-Path $AppRoot 'storage\license.json'
  if (-not (Test-Path -LiteralPath $licensePath)) { return "" }
  try {
    $document = Get-Content -LiteralPath $licensePath -Raw | ConvertFrom-Json
    $plan = [string]($document.plan)
    if ($plan -ne '') { return $plan.Trim().ToLowerInvariant() }
    $payloadB64 = [string]($document.payload_b64)
    if ($payloadB64 -eq '') { return "" }
    $payloadJson = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String($payloadB64))
    $payload = $payloadJson | ConvertFrom-Json
    return ([string]($payload.plan)).Trim().ToLowerInvariant()
  } catch {
    return ""
  }
}

function Test-FlusCloudPlan([string]$Plan) {
  return $Plan.Trim().ToLowerInvariant().StartsWith('cloud')
}

function Get-FlusCloudStatus([string]$Content, [string]$Plan) {
  $licenseUrl = Get-FlusDefineString -Content $Content -Name 'FLUS_LICENSE_CLOUD_URL'
  $syncUrl = Get-FlusDefineString -Content $Content -Name 'FLUS_CLOUD_SYNC_URL'
  $licenseToken = Get-FlusDefineString -Content $Content -Name 'FLUS_LICENSE_CLOUD_TOKEN'
  $syncToken = Get-FlusDefineString -Content $Content -Name 'FLUS_CLOUD_SYNC_TOKEN'
  $enabled = Get-FlusDefineBool -Content $Content -Name 'FLUS_CLOUD_SYNC_ENABLED'
  $tokenReady = $licenseToken -ne '' -and (($syncToken -eq '') -or ($syncToken -eq $licenseToken))
  $licenseUrlReady = Test-FlusCloudUrl $licenseUrl
  $syncUrlReady = Test-FlusCloudUrl $syncUrl
  $urlReady = $licenseUrlReady -and $syncUrlReady
  return [ordered]@{
    cloud_plan = Test-FlusCloudPlan $Plan
    enabled = $enabled
    license_url = $licenseUrlReady
    sync_url = $syncUrlReady
    token = $tokenReady
    ready = $enabled -and $urlReady -and $tokenReady
  }
}

function Find-PhpExecutable {
  param([string]$RequestedPath)

  $candidates = @()
  if ($RequestedPath -ne "") { $candidates += $RequestedPath }
  $candidates += @(
    (Join-Path $Root "stack\php\php.exe"),
    "C:\FLUS\stack\php\php.exe",
    "C:\xampp82\php\php.exe",
    "C:\xampp\php\php.exe"
  )

  foreach ($candidate in $candidates) {
    if ($candidate -ne "" -and (Test-Path -LiteralPath $candidate)) {
      return $candidate
    }
  }

  $cmd = Get-Command php -ErrorAction SilentlyContinue
  if ($cmd) {
    return $cmd.Source
  }

  return ""
}

function Invoke-FlusPhp([string]$Php, [string[]]$ArgsList) {
  $process = Start-Process -FilePath $Php -ArgumentList $ArgsList -WorkingDirectory $Root -NoNewWindow -Wait -PassThru
  if ($process.ExitCode -ne 0) {
    throw "El comando PHP fallo con codigo $($process.ExitCode): $Php $($ArgsList -join ' ')"
  }
}

if (-not $NonInteractive) {
  Clear-Host
}
Write-Host "====================================================" -ForegroundColor Cyan
Write-Host " FLUS | Activador de Cloud" -ForegroundColor White
Write-Host "====================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Este asistente activa o desactiva la sincronizacion cloud sin pisar DB, licencia, ARCA ni Mercado Pago." -ForegroundColor White

if (-not (Test-Path -LiteralPath $configPath)) {
  throw "No se encontro src\config.php en $Root. Ejecuta este script apuntando a la raiz de FLUS instalada."
}

function Invoke-FlusScheduler([ValidateSet('Install','Remove','Status')][string]$Mode) {
  $setupPath = Join-Path $Root "scripts\cloud_sync_task_setup.ps1"
  if (-not (Test-Path -LiteralPath $setupPath)) {
    return 2
  }

  $powershell = Join-Path $env:SystemRoot "System32\WindowsPowerShell\v1.0\powershell.exe"
  $modeSwitch = '-' + $(if ($Mode -eq 'Status') { 'StatusOnly' } else { $Mode })
  & $powershell -ExecutionPolicy Bypass -NoProfile -File $setupPath -Root $Root $modeSwitch -IntervalMinutes $SchedulerIntervalMinutes
  return $LASTEXITCODE
}

function Invoke-FlusEndpointProbe([string]$Php) {
  $probePath = Join-Path $Root "scripts\cloud_sync_probe.php"
  if (-not (Test-Path -LiteralPath $probePath)) {
    throw "No se encontro la prueba segura de endpoints Cloud."
  }

  & $Php $probePath
  if ($LASTEXITCODE -eq 0) { return }

  $detail = "PREFLIGHT_FAILED"
  $statePath = Join-Path $Root "storage\cloud_sync_probe_state.json"
  if (Test-Path -LiteralPath $statePath) {
    try {
      $state = Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
      $licenseError = [string]($state.license.error)
      $syncError = [string]($state.sync.error)
      $detail = "licencia=$licenseError; sync=$syncError"
    } catch {
      $detail = "PREFLIGHT_STATE_INVALID"
    }
  }
  throw "Los endpoints Cloud no superaron la prueba segura ($detail). La tarea automatica no se activara."
}

$originalContent = [System.IO.File]::ReadAllText($configPath)
$licensePlan = Get-FlusLicensePlan -AppRoot $Root
$currentStatus = Get-FlusCloudStatus -Content $originalContent -Plan $licensePlan

if ($StatusOnly) {
  $schedulerCode = if ($SkipScheduler -or (-not $currentStatus.cloud_plan -and -not $currentStatus.ready)) {
    0
  } else {
    Invoke-FlusScheduler -Mode Status
  }
  $schedulerReady = $schedulerCode -eq 0
  Write-Step "Estado actual"
  Write-Host "Plan de licencia:    $(if ($licensePlan -ne '') { $licensePlan } else { 'no detectado' })"
  Write-Host "Endpoint licencia:   $(if ($currentStatus.license_url) { 'configurado' } else { 'faltante' })"
  Write-Host "Endpoint sync:       $(if ($currentStatus.sync_url) { 'configurado' } else { 'faltante' })"
  Write-Host "Token compartido:    $(if ($currentStatus.token) { 'configurado' } else { 'faltante' })"
  Write-Host "Tarea automatica:    $(if ($schedulerReady) { 'operativa' } else { 'faltante' })"
  Write-Host "Sincronizacion:      $(if ($currentStatus.ready -and $schedulerReady) { 'operativa' } elseif (-not $currentStatus.cloud_plan -and -not $currentStatus.ready) { 'opcional para este plan' } else { 'requiere configuracion' })"
  if ($currentStatus.cloud_plan -and (-not $currentStatus.ready -or -not $schedulerReady)) {
    Write-Host "Reparacion: ejecuta Configurar Cloud FLUS y usa el token de FLUS Admin > Configuracion cloud." -ForegroundColor Yellow
    exit 2
  }
  exit 0
}

if (-not $PSBoundParameters.ContainsKey('LicenseCloudUrl')) {
  $existing = Get-FlusDefineString -Content $originalContent -Name 'FLUS_LICENSE_CLOUD_URL'
  if ($existing -ne '') {
    $LicenseCloudUrl = if ($legacyLicenseCloudUrls -contains $existing.Trim()) { $defaultLicenseCloudUrl } else { $existing }
  }
}
if (-not $PSBoundParameters.ContainsKey('SyncUrl')) {
  $existing = Get-FlusDefineString -Content $originalContent -Name 'FLUS_CLOUD_SYNC_URL'
  if ($existing -ne '') {
    $SyncUrl = if ($legacySyncUrls -contains $existing.Trim()) { $defaultSyncUrl } else { $existing }
  }
}
if (-not $PSBoundParameters.ContainsKey('BranchCode')) {
  $existing = Get-FlusDefineString -Content $originalContent -Name 'FLUS_CLOUD_BRANCH_CODE'
  if ($existing -ne '') { $BranchCode = $existing }
}
if (-not $PSBoundParameters.ContainsKey('BranchName')) {
  $existing = Get-FlusDefineString -Content $originalContent -Name 'FLUS_CLOUD_BRANCH_NAME'
  if ($existing -ne '') { $BranchName = $existing }
}
if (-not $PSBoundParameters.ContainsKey('InstallationName')) {
  $existing = Get-FlusDefineString -Content $originalContent -Name 'FLUS_CLOUD_INSTALLATION_NAME'
  if ($existing -ne '') { $InstallationName = $existing }
}
if ($Token -eq '') {
  $Token = Get-FlusDefineString -Content $originalContent -Name 'FLUS_LICENSE_CLOUD_TOKEN'
}
if ($Token -eq '' -and -not [string]::IsNullOrWhiteSpace($env:FLUS_LICENSE_CLOUD_TOKEN)) {
  $Token = $env:FLUS_LICENSE_CLOUD_TOKEN.Trim()
}

if ($InstallationName -eq "") {
  $InstallationName = $env:COMPUTERNAME
}

if ($NonInteractive -and -not $DisableCloud -and -not $currentStatus.cloud_plan -and -not $currentStatus.ready) {
  Write-Host "La licencia actual no requiere Cloud. No se modifico la configuracion."
  exit 0
}

if (-not $DisableCloud) {
  if (-not $NonInteractive) {
    $LicenseCloudUrl = Read-Required -Label "URL de licencia cloud" -Current $LicenseCloudUrl
    $SyncUrl = Read-Required -Label "URL de sincronizacion cloud" -Current $SyncUrl
    $BranchCode = Read-Required -Label "Codigo de sucursal" -Current $BranchCode
    $BranchName = Read-Required -Label "Nombre de sucursal" -Current $BranchName
    $InstallationName = Read-Required -Label "Nombre de esta PC/caja" -Current $InstallationName
    if ($Token -eq "") {
      Write-Host "Obtene el token en FLUS Admin > Configuracion cloud > Revelar token." -ForegroundColor Cyan
      $Token = Read-SecureText -Label "Token cloud compartido"
    }
  }

  if (-not (Test-FlusCloudUrl $LicenseCloudUrl)) {
    throw "La URL de licencia cloud debe ser HTTPS. Solo localhost puede usar HTTP."
  }
  if (-not (Test-FlusCloudUrl $SyncUrl)) {
    throw "La URL de sincronizacion cloud debe ser HTTPS. Solo localhost puede usar HTTP."
  }
  if ([string]::IsNullOrWhiteSpace($Token)) {
    throw "Falta el token cloud. Sin token FLUS no puede validar licencia online ni enviar eventos."
  }
  if ($Token.Trim().Length -lt 32) {
    Write-Host "ADVERTENCIA: el token configurado tiene menos de 32 caracteres. Conviene rotarlo de forma coordinada." -ForegroundColor Yellow
  }
}

$changes = [ordered]@{
  FLUS_LICENSE_CLOUD_URL = if ($DisableCloud) { "" } else { $LicenseCloudUrl.Trim() }
  FLUS_LICENSE_CLOUD_TOKEN = if ($DisableCloud) { "" } else { $Token.Trim() }
  FLUS_LICENSE_CLOUD_REQUIRED = -not $DisableCloud
  FLUS_CLOUD_SYNC_ENABLED = -not $DisableCloud
  FLUS_CLOUD_SYNC_URL = if ($DisableCloud) { "" } else { $SyncUrl.Trim() }
  FLUS_CLOUD_SYNC_TOKEN = if ($DisableCloud) { "" } else { $Token.Trim() }
  FLUS_CLOUD_SYNC_HEARTBEAT_INTERVAL_SEC = [Math]::Max(60, $HeartbeatIntervalSec)
  FLUS_CLOUD_SYNC_STOCK_SNAPSHOT_INTERVAL_SEC = [Math]::Max(300, $StockSnapshotIntervalSec)
  FLUS_CLOUD_BRANCH_CODE = if ($DisableCloud) { "" } else { $BranchCode.Trim() }
  FLUS_CLOUD_BRANCH_NAME = if ($DisableCloud) { "" } else { $BranchName.Trim() }
  FLUS_CLOUD_BRANCH_ADDRESS = if ($DisableCloud) { "" } else { $BranchAddress.Trim() }
  FLUS_CLOUD_INSTALLATION_NAME = if ($DisableCloud) { "" } else { $InstallationName.Trim() }
}

Write-Step "Resumen"
Write-Host "Raiz FLUS:          $Root"
Write-Host "Config:             $configPath"
Write-Host "Modo:               $(if ($DisableCloud) { 'Desactivar Cloud' } else { 'Activar Cloud' })"
Write-Host "Sucursal:           $($changes.FLUS_CLOUD_BRANCH_CODE) - $($changes.FLUS_CLOUD_BRANCH_NAME)"
Write-Host "Instalacion:        $($changes.FLUS_CLOUD_INSTALLATION_NAME)"
Write-Host "Endpoint licencia:  $(if ($changes.FLUS_LICENSE_CLOUD_URL -ne '') { 'configurado' } else { 'sin URL' })"
Write-Host "Endpoint sync:      $(if ($changes.FLUS_CLOUD_SYNC_URL -ne '') { 'configurado' } else { 'sin URL' })"
Write-Host "Token:              $(if (($changes.FLUS_LICENSE_CLOUD_TOKEN) -ne '') { 'configurado' } else { 'sin token' })"
Write-Host "Tarea automatica:   $(if ($SkipScheduler) { 'sin cambios' } elseif ($DisableCloud) { 'se quitara' } else { "cada $SchedulerIntervalMinutes minuto(s)" })"

if (-not $NonInteractive -and -not $DryRun) {
  Write-Host ""
  $expectedConfirmation = if ($DisableCloud) { "DESACTIVAR" } else { "ACTIVAR" }
  $confirm = Read-Host "Escribe $expectedConfirmation para continuar"
  if ($confirm.Trim().ToUpperInvariant() -ne $expectedConfirmation) {
    Write-Host "Operacion cancelada. No se modifico FLUS." -ForegroundColor Yellow
    exit 0
  }
}

if ($DryRun) {
  Write-Host ""
  Write-Host "Simulacion correcta. No se modifico ningun archivo." -ForegroundColor Green
  exit 0
}

$php = Find-PhpExecutable -RequestedPath $PhpPath
if ($php -eq "") {
  throw "No se encontro PHP CLI. No se modifico la configuracion cloud."
}

if (-not $SkipMigrations) {
  $migratePath = Join-Path $Root "scripts\migrate.php"
  if (Test-Path -LiteralPath $migratePath) {
    Write-Step "Aplicando migraciones pendientes"
    Invoke-FlusPhp -Php $php -ArgsList @($migratePath)
  }
}

Write-Step "Actualizando configuracion"
$backupPath = $configPath + ".bak_cloud_" + $timestamp
Copy-Item -LiteralPath $configPath -Destination $backupPath -Force
Write-Host "Respaldo creado: $backupPath" -ForegroundColor DarkGray

$content = $originalContent
foreach ($entry in $changes.GetEnumerator()) {
  $content = Set-FlusDefine -Content $content -Name $entry.Key -Value $entry.Value
}
[System.IO.File]::WriteAllText($configPath, $content, (New-Object System.Text.UTF8Encoding($false)))

try {
  Write-Step "Validando PHP"
  Invoke-FlusPhp -Php $php -ArgsList @("-l", $configPath)

  $savedContent = [System.IO.File]::ReadAllText($configPath)
  $savedStatus = Get-FlusCloudStatus -Content $savedContent -Plan $licensePlan
  if (-not $DisableCloud -and -not $savedStatus.ready) {
    throw "La verificacion final detecto URL o token faltante."
  }
  if ($DisableCloud -and $savedStatus.enabled) {
    throw "La verificacion final no pudo desactivar Cloud."
  }

  if (-not $DisableCloud) {
    Write-Step "Probando endpoints Cloud"
    Invoke-FlusEndpointProbe -Php $php
  }

  if (-not $SkipScheduler) {
    Write-Step "Configurando sincronizacion automatica"
    $schedulerMode = if ($DisableCloud) { 'Remove' } else { 'Install' }
    $schedulerExitCode = Invoke-FlusScheduler -Mode $schedulerMode
    if ($schedulerExitCode -ne 0) {
      throw "La tarea automatica no quedo operativa."
    }
  }
} catch {
  if (-not $SkipScheduler -and -not $DisableCloud) {
    try { Invoke-FlusScheduler -Mode Remove | Out-Null } catch { }
  }
  Copy-Item -LiteralPath $backupPath -Destination $configPath -Force
  throw "No se pudo completar Cloud y se restauro config.php: $($_.Exception.Message)"
}

if (-not $DisableCloud -and $SendInitialStock) {
  $snapshotPath = Join-Path $Root "scripts\cloud_sync_stock_snapshot.php"
  if (Test-Path -LiteralPath $snapshotPath) {
    Write-Step "Enviando stock inicial"
    Invoke-FlusPhp -Php $php -ArgsList @($snapshotPath, "250")
  }
}

Write-Host ""
Write-Host "====================================================" -ForegroundColor Green
Write-Host " CLOUD ACTUALIZADO" -ForegroundColor Green
Write-Host "====================================================" -ForegroundColor Green
Write-Host "Siguiente paso: entrar a Tecnico > Sincronizacion cloud y verificar estado." -ForegroundColor Cyan
