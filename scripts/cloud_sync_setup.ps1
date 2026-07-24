[CmdletBinding()]
param(
  [string]$Root = "",
  [string]$LicenseCloudUrl = "https://flus.com.ar/admin/api/license-check.php",
  [string]$SyncUrl = "https://flus.com.ar/admin/api/sync-ingest.php",
  [string]$Token = "",
  [string]$BranchCode = "central",
  [string]$BranchName = "Casa central",
  [string]$InstallationName = "",
  [string]$BranchAddress = "",
  [int]$StockSnapshotIntervalSec = 900,
  [string]$PhpPath = "",
  [switch]$SendInitialStock,
  [switch]$SkipMigrations,
  [switch]$DisableCloud,
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

if ($InstallationName -eq "") {
  $InstallationName = $env:COMPUTERNAME
}

if (-not $DisableCloud) {
  if (-not $NonInteractive) {
    $LicenseCloudUrl = Read-Required -Label "URL de licencia cloud" -Current $LicenseCloudUrl
    $SyncUrl = Read-Required -Label "URL de sincronizacion cloud" -Current $SyncUrl
    $BranchCode = Read-Required -Label "Codigo de sucursal" -Current $BranchCode
    $BranchName = Read-Required -Label "Nombre de sucursal" -Current $BranchName
    $InstallationName = Read-Required -Label "Nombre de esta PC/caja" -Current $InstallationName
    if ($Token -eq "") {
      $Token = Read-SecureText -Label "Token cloud compartido"
    }
  }

  if ([string]::IsNullOrWhiteSpace($Token) -and -not $DryRun) {
    throw "Falta el token cloud. Sin token FLUS no puede validar licencia online ni enviar eventos."
  }
}

$changes = [ordered]@{
  FLUS_LICENSE_CLOUD_URL = if ($DisableCloud) { "" } else { $LicenseCloudUrl.Trim() }
  FLUS_LICENSE_CLOUD_TOKEN = if ($DisableCloud) { "" } else { $Token.Trim() }
  FLUS_CLOUD_SYNC_ENABLED = -not $DisableCloud
  FLUS_CLOUD_SYNC_URL = if ($DisableCloud) { "" } else { $SyncUrl.Trim() }
  FLUS_CLOUD_SYNC_TOKEN = if ($DisableCloud) { "" } else { $Token.Trim() }
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

Write-Step "Actualizando configuracion"
$backupPath = $configPath + ".bak_cloud_" + $timestamp
Copy-Item -LiteralPath $configPath -Destination $backupPath -Force
Write-Host "Respaldo creado: $backupPath" -ForegroundColor DarkGray

$content = [System.IO.File]::ReadAllText($configPath)
foreach ($entry in $changes.GetEnumerator()) {
  $content = Set-FlusDefine -Content $content -Name $entry.Key -Value $entry.Value
}
[System.IO.File]::WriteAllText($configPath, $content, (New-Object System.Text.UTF8Encoding($false)))

$php = Find-PhpExecutable -RequestedPath $PhpPath
if ($php -eq "") {
  Write-Host "No se encontro PHP CLI. Configuracion guardada, pero no se corrieron validaciones." -ForegroundColor Yellow
  exit 0
}

Write-Step "Validando PHP"
Invoke-FlusPhp -Php $php -ArgsList @("-l", $configPath)

if (-not $SkipMigrations) {
  $migratePath = Join-Path $Root "scripts\migrate.php"
  if (Test-Path -LiteralPath $migratePath) {
    Write-Step "Aplicando migraciones pendientes"
    Invoke-FlusPhp -Php $php -ArgsList @($migratePath)
  }
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
