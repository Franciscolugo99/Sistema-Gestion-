[CmdletBinding()]
param(
  [switch]$KeepFixture,
  [ValidateRange(1, 65535)][int]$DbPort = 3306
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$pilotBase = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot '.pilot'))
$pilotRoot = [System.IO.Path]::GetFullPath((Join-Path $pilotBase 'preupgrade'))
$allowedPrefix = $pilotBase.TrimEnd('\') + '\'
$database = 'flus_it_pilot_428'
$mysqlRoot = 'C:\xampp\mysql'
$phpRoot = 'C:\xampp\php'
$mysql = Join-Path $mysqlRoot 'bin\mysql.exe'
$mysqlLink = Join-Path $pilotRoot 'stack\mysql'
$phpLink = Join-Path $pilotRoot 'stack\php'

if (-not $pilotRoot.StartsWith($allowedPrefix, [StringComparison]::OrdinalIgnoreCase)) {
  throw 'La ruta del piloto no es segura.'
}
if (-not $database.StartsWith('flus_it_')) {
  throw 'La base del piloto no es descartable.'
}
if (-not (Test-Path -LiteralPath $mysql)) {
  throw 'No se encontro MySQL local para el piloto.'
}

function Remove-PilotFixture {
  if (-not (Test-Path -LiteralPath $pilotRoot)) { return }
  $resolved = [System.IO.Path]::GetFullPath($pilotRoot)
  if (-not $resolved.StartsWith($allowedPrefix, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Se rechazo limpiar una ruta fuera del piloto.'
  }
  if (Test-Path -LiteralPath $mysqlLink) {
    [System.IO.Directory]::Delete($mysqlLink)
  }
  if (Test-Path -LiteralPath $phpLink) {
    [System.IO.Directory]::Delete($phpLink)
  }
  Remove-Item -LiteralPath $resolved -Recurse -Force
}

Remove-PilotFixture
New-Item -ItemType Directory -Force -Path @(
  (Join-Path $pilotRoot 'app\src'),
  (Join-Path $pilotRoot 'app\storage'),
  (Join-Path $pilotRoot 'db'),
  (Join-Path $pilotRoot 'stack')
) | Out-Null
New-Item -ItemType Junction -Path $mysqlLink -Target $mysqlRoot | Out-Null
New-Item -ItemType Junction -Path $phpLink -Target $phpRoot | Out-Null

$config = @(
  '<?php',
  "function flus_env(`$name, `$default) { return `$default; }",
  "define('DB_HOST', '127.0.0.1');",
  "define('DB_PORT', '$DbPort');",
  "define('DB_NAME', flus_env('FLUS_DB_NAME', '$database'));",
  "define('DB_USER', 'root');",
  "define('DB_PASS', '');"
) -join "`r`n"
[System.IO.File]::WriteAllText(
  (Join-Path $pilotRoot 'app\src\config.php'),
  $config,
  (New-Object System.Text.UTF8Encoding($false))
)
[System.IO.File]::WriteAllText(
  (Join-Path $pilotRoot 'app\storage\pilot.txt'),
  'PILOT_STORAGE',
  (New-Object System.Text.UTF8Encoding($false))
)
[System.IO.File]::WriteAllText(
  (Join-Path $pilotRoot 'db\pilot.txt'),
  'PILOT_DB_FILES',
  (New-Object System.Text.UTF8Encoding($false))
)

try {
  & $mysql -h 127.0.0.1 -P $DbPort -u root -e "
    DROP DATABASE IF EXISTS $database;
    CREATE DATABASE $database CHARACTER SET utf8mb4;
    CREATE TABLE $database.pilot_rows (id INT PRIMARY KEY, value_text VARCHAR(32));
    INSERT INTO $database.pilot_rows VALUES (1, 'preserved');
  "
  if ($LASTEXITCODE -ne 0) { throw 'No se pudo preparar la DB piloto.' }

  & powershell.exe -ExecutionPolicy Bypass -NoProfile -File (Join-Path $PSScriptRoot 'preupgrade.ps1') `
    -Root $pilotRoot -ApacheServiceName 'FLUS_Apache_Pilot_428'
  if ($LASTEXITCODE -ne 0) { throw 'El preupgrade piloto fallo.' }

  $pointer = Join-Path $pilotRoot 'upgrade_backups\last_upgrade_backup.txt'
  $backupRoot = [System.IO.File]::ReadAllText($pointer).Trim()
  $dump = Join-Path $backupRoot ($database + '.sql')
  $configOk = Test-Path -LiteralPath (Join-Path $backupRoot 'config\config.php')
  $storageOk = (Get-Content -LiteralPath (Join-Path $backupRoot 'storage\pilot.txt') -Raw) -eq 'PILOT_STORAGE'
  $dumpOk = (Test-Path -LiteralPath $dump) `
    -and (Get-Item -LiteralPath $dump).Length -gt 128 `
    -and (Select-String -LiteralPath $dump -SimpleMatch 'pilot_rows' -Quiet)
  $manifestOk = Test-Path -LiteralPath (Join-Path $backupRoot 'manifest.json')

  # Fuerza el camino de recuperacion: config dinamica sin PHP disponible.
  # El migrador solo puede tomar la DB del manifest del backup verificado.
  [System.IO.Directory]::Delete($phpLink)
  New-Item -ItemType Directory -Force -Path (Join-Path $pilotRoot 'db\migrations') | Out-Null
  & powershell.exe -ExecutionPolicy Bypass -NoProfile -File (Join-Path $PSScriptRoot 'upgrade_db.ps1') `
    -Root $pilotRoot -DbPort $DbPort -NoBackup
  $upgradeFallbackOk = $LASTEXITCODE -eq 0

  & powershell.exe -ExecutionPolicy Bypass -NoProfile -File (Join-Path $PSScriptRoot 'postupgrade.ps1') `
    -Root $pilotRoot -ApacheServiceName 'FLUS_Apache_Pilot_428'
  $postUpgradeOk = $LASTEXITCODE -eq 0

  Write-Host "PILOT_CONFIG_BACKUP=$configOk"
  Write-Host "PILOT_STORAGE_BACKUP=$storageOk"
  Write-Host "PILOT_DB_DUMP=$dumpOk"
  Write-Host "PILOT_MANIFEST=$manifestOk"
  Write-Host "PILOT_VERIFIED_MANIFEST_FALLBACK=$upgradeFallbackOk"
  Write-Host "PILOT_POSTUPGRADE=$postUpgradeOk"

  if (-not ($configOk -and $storageOk -and $dumpOk -and $manifestOk -and $upgradeFallbackOk -and $postUpgradeOk)) {
    throw 'El piloto no preservo todos los componentes requeridos.'
  }
} finally {
  & $mysql -h 127.0.0.1 -P $DbPort -u root -e "DROP DATABASE IF EXISTS $database;" | Out-Null
  if (-not $KeepFixture) {
    Remove-PilotFixture
    Write-Host "PILOT_CLEANED=$(-not (Test-Path -LiteralPath $pilotRoot))"
  }
}
