<#
upgrade_db.ps1 (FLUS) - SAFE/SMART (+ baseline install.sql)
- Para upgrades: aplica migraciones SOLO si hay pendientes (tabla schema_migrations).
- Para instalaciones nuevas (DB vacÃƒÆ’Ã‚Â­a): si existe db\install.sql, lo importa y (si pasa sanity check)
  marca las migraciones actuales como aplicadas para NO re-ejecutarlas.
- NO toca config.php.
- Hace backup ANTES de migrar (salvo -NoBackup).
- Detiene Apache durante migraciones para evitar escrituras web (solo si estaba corriendo).
#>

param(
  [string]$Root = "C:\FLUS",
  [int]$DbPort = 3307,
  [string]$DbName = "",
  [switch]$NoBackup,
  [string]$InstallSql = ""   # opcional: ruta explÃƒÆ’Ã‚Â­cita a install.sql
)

$ErrorActionPreference = "Stop"
$ProgressPreference    = "SilentlyContinue"
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}

function Log([string]$m) {
  $ts = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
  Write-Host "[$ts] $m"
}

function Ensure-Dir([string]$p) {
  if (-not (Test-Path $p)) { New-Item -ItemType Directory -Force $p | Out-Null }
}

function ToSlash([string]$p) { return $p.Replace('\','/') }

function Write-Utf8NoBom([string]$path, [string]$text) {
  $enc = New-Object System.Text.UTF8Encoding($false)
  [System.IO.File]::WriteAllText($path, $text, $enc)
}

function Repair-PhpIniForPortableStack {
  $phpDir = Join-Path $Root "stack\php"
  $apacheDir = Join-Path $Root "stack\apache"
  $iniPath = Join-Path $phpDir "php.ini"
  if (-not (Test-Path $iniPath)) {
    Log "WARN: No encuentro php.ini para reparar OpenSSL: $iniPath"
    return
  }

  $phpExt = Join-Path $phpDir "ext"
  $tmpDir = Join-Path $Root "tmp"
  $logDir = Join-Path $Root "logs"
  Ensure-Dir $tmpDir
  Ensure-Dir $logDir

  $t = Get-Content -Path $iniPath -Raw
  $t = [regex]::Replace($t, '(?m)^\s*;?\s*extension_dir\s*=.*$', ('extension_dir="' + $phpExt + '"'))
  $t = [regex]::Replace($t, '(?m)^\s*;?\s*upload_tmp_dir\s*=.*$', ('upload_tmp_dir="' + $tmpDir + '"'))
  $t = [regex]::Replace($t, '(?m)^\s*;?\s*session\.save_path\s*=.*$', ('session.save_path="' + $tmpDir + '"'))
  $t = [regex]::Replace($t, '(?m)^\s*;?\s*error_log\s*=.*$', ('error_log="' + (Join-Path $logDir "php_error_log") + '"'))

  $ca = Join-Path (Join-Path $apacheDir "bin") "curl-ca-bundle.crt"
  if (Test-Path $ca) {
    $t = [regex]::Replace($t, '(?m)^\s*;?\s*curl\.cainfo\s*=.*$', ('curl.cainfo="' + $ca + '"'))
    $t = [regex]::Replace($t, '(?m)^\s*;?\s*openssl\.cafile\s*=.*$', ('openssl.cafile="' + $ca + '"'))
  }

  $requiredExtensions = @('curl','fileinfo','gd','mbstring','mysqli','openssl','pdo_mysql')
  $lines = $t -split "\r?\n"
  $seenActive = @{}
  for ($i = 0; $i -lt $lines.Count; $i++) {
    foreach ($ext in $requiredExtensions) {
      if ($lines[$i] -match ("(?i)^\s*;\s*extension\s*=\s*" + [regex]::Escape($ext) + "\s*$")) {
        $lines[$i] = "extension=$ext"
      }
      if ($lines[$i] -match ("(?i)^\s*extension\s*=\s*" + [regex]::Escape($ext) + "\s*$")) {
        if ($seenActive.ContainsKey($ext)) {
          $lines[$i] = ";extension=$ext"
        } else {
          $seenActive[$ext] = $true
        }
      }
    }

    if ($lines[$i] -match '(?i)^\s*extension\s*=\s*php_openssl\.dll\s*$') {
      $lines[$i] = ';extension=php_openssl.dll'
    }
  }

  foreach ($ext in $requiredExtensions) {
    if (-not $seenActive.ContainsKey($ext)) {
      $lines += "extension=$ext"
    }
  }

  Write-Utf8NoBom -path $iniPath -text ($lines -join "`r`n")
  Log "PHP portable saneado para OpenSSL/licencias: $iniPath"
}

function Repair-ApachePhpOpenSslLoadFiles {
  $apacheDir = Join-Path $Root "stack\apache"
  $phpDir = Join-Path $Root "stack\php"
  $httpdConf = Join-Path $apacheDir "conf\httpd.conf"
  if (-not (Test-Path $httpdConf) -or -not (Test-Path $phpDir)) {
    Log "WARN: No encuentro Apache/PHP portable para reparar LoadFile de OpenSSL."
    return
  }

  $phpApacheDll = Get-ChildItem -Path $phpDir -Filter "php*apache*.dll" -File -ErrorAction SilentlyContinue | Select-Object -First 1
  if (-not $phpApacheDll) {
    Log "WARN: No encuentro php*apache*.dll para reparar Apache/PHP."
    return
  }

  $phpTsDll = @(
    (Join-Path $phpDir "php8ts.dll"),
    (Join-Path $phpDir "php7ts.dll")
  ) | Where-Object { Test-Path $_ } | Select-Object -First 1
  if (-not $phpTsDll) {
    $phpTsDll = Get-ChildItem -Path $phpDir -Filter "php*ts.dll" -File -ErrorAction SilentlyContinue |
      Select-Object -First 1 | ForEach-Object { $_.FullName }
  }
  if (-not $phpTsDll) {
    Log "WARN: No encuentro php*ts.dll para reparar Apache/PHP."
    return
  }

  $patterns = @(
    'libcrypto-*.dll','libssl-*.dll','libssh2.dll','nghttp2.dll','libsqlite3.dll',
    'libsodium.dll','libpq.dll','libsasl.dll','glib-2.dll','gmodule-2.dll',
    'libenchant2.dll','icudt*.dll','icuin*.dll','icuio*.dll','icuuc*.dll'
  )
  $loadFiles = @()
  foreach ($pattern in $patterns) {
    foreach ($dep in (Get-ChildItem -Path $phpDir -Filter $pattern -File -ErrorAction SilentlyContinue)) {
      $line = 'LoadFile "' + (ToSlash $dep.FullName) + '"'
      if ($loadFiles -notcontains $line) { $loadFiles += $line }
    }
  }

  $confText = Get-Content -Path $httpdConf -Raw
  $confText = [regex]::Replace($confText, '(?ms)^\s*#\s*---\s*FLUS PORTABLE PHP START\s*---.*?#\s*---\s*FLUS PORTABLE PHP END\s*---\s*\r?\n?', '')
  $confText = [regex]::Replace($confText, '(?m)^\s*PHPIniDir\s+.*$\r?\n?', '')
  $confText = [regex]::Replace($confText, '(?m)^\s*LoadModule\s+php_module\s+.*$\r?\n?', '')
  $confText = [regex]::Replace($confText, '(?m)^\s*LoadFile\s+".*stack/php/(?:php\d+ts|libcrypto-|libssl-|libssh2|nghttp2|libsqlite3|libsodium|libpq|libsasl|glib-2|gmodule-2|libenchant2|icudt|icuin|icuio|icuuc).*\.dll"\s*\r?\n?', '')

  $phpBlock = @"
# --- FLUS PORTABLE PHP START ---
$($loadFiles -join "`r`n")
LoadFile "$(ToSlash $phpTsDll)"
LoadModule php_module "$(ToSlash $phpApacheDll.FullName)"
PHPIniDir "$(ToSlash $phpDir)"
AddHandler application/x-httpd-php .php
AddType application/x-httpd-php .php
# --- FLUS PORTABLE PHP END ---
"@

  Write-Utf8NoBom -path $httpdConf -text ($confText.TrimEnd() + "`r`n`r`n" + $phpBlock + "`r`n")
  Log "Apache/PHP portable saneado con LoadFile de dependencias OpenSSL: $httpdConf"
}

function Get-ConfiguredDbName {
  $cfg = Join-Path $Root "app\src\config.php"
  if (-not (Test-Path $cfg)) { return "" }

  try {
    $txt = Get-Content -Path $cfg -Raw
    $m = [regex]::Match($txt, 'define\s*\(\s*[''"]DB_NAME[''"]\s*,\s*[''"](?<db>[^''"]+)[''"]\s*\)', 'IgnoreCase')
    if ($m.Success) { return $m.Groups["db"].Value }

    foreach ($pattern in @(
      '(?im)^\s*\$dbName\s*=\s*[''"](?<db>[A-Za-z0-9_]+)[''"]\s*;',
      '(?im)^\s*\$dbname\s*=\s*[''"](?<db>[A-Za-z0-9_]+)[''"]\s*;',
      '(?im)^\s*\$database\s*=\s*[''"](?<db>[A-Za-z0-9_]+)[''"]\s*;',
      '(?im)^\s*\$db\s*=\s*[''"](?<db>[A-Za-z0-9_]+)[''"]\s*;'
    )) {
      $m = [regex]::Match($txt, $pattern)
      if ($m.Success) { return $m.Groups["db"].Value }
    }
  } catch {}

  # Algunas instalaciones conservan config.php basado en flus_env(), por lo
  # que el valor no aparece como literal. Consultar la constante con el PHP
  # portable evita adivinar la base y no expone credenciales en argumentos.
  $phpExe = Join-Path $Root "stack\php\php.exe"
  if (Test-Path -LiteralPath $phpExe) {
    try {
      $probe = '$path=$argv[1]; require $path; if (!defined("DB_NAME")) { exit(2); } $value=(string)constant("DB_NAME"); if (!preg_match("/^[A-Za-z0-9_]+$/D", $value)) { exit(3); } echo $value;'
      $value = (& $phpExe -r $probe $cfg 2>$null | Out-String).Trim().TrimStart([char]0xFEFF)
      if ($LASTEXITCODE -eq 0 -and $value -match '^[A-Za-z0-9_]+$') {
        return $value
      }
    } catch {}
  }

  return ""
}

function Get-VerifiedPreupgradeDbName {
  $backupBase = [System.IO.Path]::GetFullPath((Join-Path $Root 'upgrade_backups'))
  $pointer = Join-Path $backupBase 'last_upgrade_backup.txt'
  if (-not (Test-Path -LiteralPath $pointer)) { return '' }

  try {
    $backupRoot = [System.IO.Path]::GetFullPath(([System.IO.File]::ReadAllText($pointer)).Trim())
    $safePrefix = $backupBase.TrimEnd('\') + '\'
    if (-not $backupRoot.StartsWith($safePrefix, [StringComparison]::OrdinalIgnoreCase)) { return '' }

    $manifestPath = Join-Path $backupRoot 'manifest.json'
    if (-not (Test-Path -LiteralPath $manifestPath)) { return '' }
    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    $database = ('' + $manifest.database).Trim()
    $dumpName = [System.IO.Path]::GetFileName(('' + $manifest.database_dump).Trim())
    $dumpPath = Join-Path $backupRoot $dumpName
    if (('' + $manifest.release) -ne '4.2.8' -or
        $database -notmatch '^[A-Za-z0-9_]+$' -or
        $dumpName -eq '' -or
        -not (Test-Path -LiteralPath $dumpPath) -or
        (Get-Item -LiteralPath $dumpPath).Length -lt 128) {
      return ''
    }
    return $database
  } catch {
    return ''
  }
}

function Ensure-SafeDbIdentifier([string]$value) {
  if ([string]::IsNullOrWhiteSpace($value)) { throw "DbName no puede estar vacio." }
  if ($value -notmatch '^[A-Za-z0-9_]+$') {
    throw "DbName '$value' no es valido. Usar solo letras, numeros y guion bajo."
  }
}

function Try-Connect([int]$port) {
  try {
    & $mysqlExe -h 127.0.0.1 -P $port -u root -e "SELECT 1;" 2>$null | Out-Null
    return ($LASTEXITCODE -eq 0)
  } catch { return $false }
}

function Detect-DbPort([int]$preferred) {
  $candidates = New-Object System.Collections.Generic.List[int]

  if ($preferred -gt 0) { $candidates.Add($preferred) }

  # 1) my.ini (stack)

$myIniCandidates = @(
  (Join-Path $Root "stack\mysql\my.ini"),
  (Join-Path $Root "stack\mysql\data\my.ini"),
  (Join-Path $Root "stack\mysql\bin\my.ini")
)
$myIni = $myIniCandidates | Where-Object { Test-Path $_ } | Select-Object -First 1
  if (Test-Path $myIni) {
    try {
      $txt = Get-Content -Path $myIni -Raw
      $m = [regex]::Match($txt, '(?m)^\s*port\s*=\s*(\d+)\s*$')
      if ($m.Success) { $candidates.Add([int]$m.Groups[1].Value) }
    } catch {}
  }

  # 2) config.php (DSN con port=)
  $cfg = Join-Path $Root "app\src\config.php"
  if (Test-Path $cfg) {
    try {
      $txt = Get-Content -Path $cfg -Raw
      $m = [regex]::Match($txt, 'port=(\d+);', 'IgnoreCase')
      if ($m.Success) { $candidates.Add([int]$m.Groups[1].Value) }
    } catch {}
  }

  # 3) fallback tÃƒÆ’Ã‚Â­picos
  foreach ($p in @(3307,3308,3310,13307,23307)) { $candidates.Add($p) }

  # unique preserve order
  $seen = @{}
  $uniq = @()
  foreach ($p in $candidates) {
    if (-not $seen.ContainsKey($p)) { $seen[$p] = $true; $uniq += $p }
  }

  foreach ($p in $uniq) {
    if (Try-Connect $p) { return $p }
  }
  throw "No puedo conectar a MySQL como root en ninguno de estos puertos: $($uniq -join ', '). Asegurate de que FLUS_MariaDB estÃƒÆ’Ã‚Â© corriendo."
}

function Ensure-MySqlRunning {
  try {
    $svc = Get-Service -Name "FLUS_MariaDB" -ErrorAction Stop
    if ($svc.Status -ne "Running") {
      Log "Iniciando servicio FLUS_MariaDB..."
      Start-Service -Name "FLUS_MariaDB" -ErrorAction Stop
      Start-Sleep -Seconds 3
    }
  } catch {
    # Puede no existir en entornos dev; no es fatal.
  }
}

function Ensure-DbExists() {
  # usa mysql sin seleccionar DB (por si todavÃƒÆ’Ã‚Â­a no existe)
  $sql = "CREATE DATABASE IF NOT EXISTS $($script:DbName) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
  & $script:mysqlExe -h 127.0.0.1 -P $script:DbPort -u root -e $sql
  if ($LASTEXITCODE -ne 0) { throw "No pude crear/verificar la DB '$($script:DbName)' (exit=$LASTEXITCODE)" }
}

function Exec-MySql([string]$sql) {
  & $script:mysqlExe -h 127.0.0.1 -P $script:DbPort -u root $script:DbName -e $sql
  if ($LASTEXITCODE -ne 0) { throw "MySQL error (exit=$LASTEXITCODE) SQL=$sql" }
}

function Query-MySql([string]$sql) {
  $out = & $script:mysqlExe -h 127.0.0.1 -P $script:DbPort -u root $script:DbName -N -e $sql
  if ($LASTEXITCODE -ne 0) { throw "MySQL query error (exit=$LASTEXITCODE) SQL=$sql" }
  return $out
}

function Query-Scalar([string]$sql) {
  $lines = Query-MySql $sql
  foreach ($line in $lines) {
    $value = ("" + $line).Trim()
    if ($value -ne '') {
      return $value
    }
  }
  return ''
}

function Import-SqlFile([string]$path) {
  Log "Importando SQL: $path"
  cmd.exe /c "`"$script:mysqlExe`" -h 127.0.0.1 -P $script:DbPort -u root `"$script:DbName`" < `"$path`""
  if ($LASTEXITCODE -ne 0) { throw "Fallo import SQL $path (exit=$LASTEXITCODE)" }
}

function Get-Tables() {
  $lines = Query-MySql "SHOW TABLES"
  $arr = @()
  foreach ($line in $lines) {
    $k = ("" + $line).Trim()
    if ($k) { $arr += $k }
  }
  return $arr
}

function Has-Column([string]$table, [string]$col) {
  try {
    $lines = Query-MySql "SHOW COLUMNS FROM $table LIKE '$col'"
    foreach ($line in $lines) {
      $k = ("" + $line).Trim()
      if ($k) { return $true }
    }
    return $false
  } catch {
    return $false
  }
}

function Has-Table([string]$table) {
  try {
    $value = Query-Scalar "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$table'"
    return ([int]$value -gt 0)
  } catch {
    return $false
  }
}

function Has-IndexColumns([string]$table, [string]$indexName, [string[]]$expectedColumns) {
  try {
    $value = Query-Scalar @"
SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = '$table'
  AND index_name = '$indexName'
GROUP BY index_name
"@
    if ($value -eq '') {
      return $false
    }
    return ($value -eq ($expectedColumns -join ','))
  } catch {
    return $false
  }
}

function Test-MigrationAlreadySatisfied([string]$filename) {
  switch ($filename) {
    '002_p0_hardening.sql' {
      return (Has-Column 'ventas' 'uuid')
    }
    '004_facturas_unique_scope.sql' {
      return (Has-Column 'facturas' 'modo') -and (Has-IndexColumns 'facturas' 'ux_facturas_numero' @('punto_venta', 'tipo', 'modo', 'numero'))
    }
    '005_compras_descuentos_schema.sql' {
      return (Has-Column 'compras' 'total_bruto') -and (Has-Column 'compra_items' 'descuento')
    }
    '006_diagnostics_permission.sql' {
      return (([int](Query-Scalar "SELECT COUNT(*) FROM permissions WHERE slug = 'ver_diagnostico'")) -gt 0)
    }
    '007_support_modules_schema.sql' {
      return (Has-Column 'clientes' 'cc_habilitado') -and (Has-Table 'inventario_sesiones') -and (Has-Table 'factura_manual_items')
    }
    default {
      return $false
    }
  }
}

function Get-MigrationFiles([string]$dir) {
  if (-not (Test-Path $dir)) { return @() }

  $allSql = @(Get-ChildItem -Path $dir -Filter '*.sql' | Sort-Object Name)
  $canonicalSql = @($allSql | Where-Object { $_.Name -match '^\d{3}_.+\.sql$' })

  if ($canonicalSql.Count -gt 0) {
    Log "Migraciones canonicas detectadas en $dir. Se usara el listado actual del paquete (*.sql con prefijo NNN_)."
    return $canonicalSql
  }

  $manifestPath = Join-Path $dir 'manifest.txt'
  if (Test-Path $manifestPath) {
    $items = New-Object System.Collections.Generic.List[object]
    $manifestLines = Get-Content -Path $manifestPath
    $manifestNames = New-Object System.Collections.Generic.List[string]
    foreach ($line in $manifestLines) {
      $name = ('' + $line).Trim()
      if ($name -eq '' -or $name.StartsWith('#')) { continue }
      $manifestNames.Add($name) | Out-Null
      $full = Join-Path $dir $name
      if (-not (Test-Path $full)) {
        Log "Manifest inconsistente en $manifestPath. Falta archivo: $full. Uso fallback por listado *.sql."
        return $allSql
      }
      $items.Add((Get-Item $full)) | Out-Null
    }

    $extraSql = @($allSql | Where-Object { -not $manifestNames.Contains($_.Name) })
    if ($extraSql.Count -gt 0) {
      Log "Manifest obsoleto detectado en $manifestPath. Hay SQL fuera del manifest: $($extraSql.Name -join ', '). Uso fallback por listado *.sql."
      return $allSql
    }

    Log "Manifest de migraciones detectado: $manifestPath"
    return $items.ToArray()
  }

  Log "Manifest no encontrado en $dir. Uso fallback por listado *.sql."
  return $allSql
}

$mysqlExe     = Join-Path $Root "stack\mysql\bin\mysql.exe"
$mysqldumpExe = Join-Path $Root "stack\mysql\bin\mysqldump.exe"
$migDir       = Join-Path $Root "db\migrations"
$bkDir        = Join-Path $Root "db\backups"
$logDir       = Join-Path $Root "logs"

if (!(Test-Path $mysqlExe)) { throw "No encuentro mysql.exe en $mysqlExe" }

# localizar install.sql
$installSqlPath = $InstallSql
if (-not $installSqlPath) { $installSqlPath = Join-Path $Root "db\install.sql" }
if (-not (Test-Path $installSqlPath)) {
  $alt = Join-Path $Root "app\scripts\install.sql"
  if (Test-Path $alt) { $installSqlPath = $alt }
}
$hasInstall = (Test-Path $installSqlPath)
$hasMigDir  = (Test-Path $migDir)

if (-not $hasInstall -and -not $hasMigDir) {
  throw "No encuentro migrations en $migDir ni install.sql en db\install.sql (ni app\scripts\install.sql)."
}

Ensure-Dir $bkDir
Ensure-Dir $logDir

# En upgrades desde stacks viejos, Apache puede cargar PHP sin las DLL de
# OpenSSL aunque php.exe funcione. Repararlo antes de migrar evita licencias
# invalidas por OPENSSL_MISSING despues de instalar.
Repair-PhpIniForPortableStack
Repair-ApachePhpOpenSslLoadFiles

$stamp   = (Get-Date).ToString("yyyyMMdd_HHmmss")
$logFile = Join-Path $logDir ("upgrade_db_" + $stamp + ".log")

$apacheWasRunning = $false

try {
  Start-Transcript -Path $logFile | Out-Null
} catch {
  # si transcript falla igual seguimos
}

# tabla de tracking
$createMigrationsTable = @"
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename   VARCHAR(255) PRIMARY KEY,
  checksum   CHAR(40) NOT NULL DEFAULT '',
  applied_at DATETIME NOT NULL DEFAULT current_timestamp()
);
"@

function Ensure-SchemaMigrationsCompatibility {
  $tablesNow = Get-Tables
  $hasSchemaMigrations = $false
  foreach ($t in $tablesNow) {
    if (($t + '') -eq 'schema_migrations') { $hasSchemaMigrations = $true; break }
  }

  if (-not $hasSchemaMigrations) {
    Exec-MySql $createMigrationsTable
    return
  }

  if (-not (Has-Column 'schema_migrations' 'filename')) {
    $legacyName = 'schema_migrations_legacy_' + (Get-Date).ToString('yyyyMMdd_HHmmss')
    Log "schema_migrations legacy detectada. Renombrando a $legacyName"
    Exec-MySql "RENAME TABLE schema_migrations TO $legacyName"
    Exec-MySql $createMigrationsTable
    return
  }

  if (-not (Has-Column 'schema_migrations' 'checksum')) {
    Exec-MySql "ALTER TABLE schema_migrations ADD COLUMN checksum CHAR(40) NOT NULL DEFAULT '' AFTER filename"
  }
  if (-not (Has-Column 'schema_migrations' 'applied_at')) {
    Exec-MySql "ALTER TABLE schema_migrations ADD COLUMN applied_at DATETIME NOT NULL DEFAULT current_timestamp()"
  }
}

function Ensure-ProviderTransferColumns {
  if (-not (Has-Table 'proveedores')) {
    return
  }

  $cols = @(
    @{ Name = 'razon_social'; Sql = 'ALTER TABLE `proveedores` ADD COLUMN `razon_social` VARCHAR(150) DEFAULT NULL AFTER `nombre`' },
    @{ Name = 'contacto_nombre'; Sql = 'ALTER TABLE `proveedores` ADD COLUMN `contacto_nombre` VARCHAR(100) DEFAULT NULL AFTER `cuit`' },
    @{ Name = 'whatsapp'; Sql = 'ALTER TABLE `proveedores` ADD COLUMN `whatsapp` VARCHAR(20) DEFAULT NULL AFTER `email`' },
    @{ Name = 'ciudad'; Sql = 'ALTER TABLE `proveedores` ADD COLUMN `ciudad` VARCHAR(100) DEFAULT NULL AFTER `direccion`' },
    @{ Name = 'provincia'; Sql = 'ALTER TABLE `proveedores` ADD COLUMN `provincia` VARCHAR(100) DEFAULT NULL AFTER `ciudad`' },
    @{ Name = 'dias_pago'; Sql = 'ALTER TABLE `proveedores` ADD COLUMN `dias_pago` TINYINT(3) UNSIGNED DEFAULT 0 AFTER `provincia`' },
    @{ Name = 'descuento_habitual'; Sql = 'ALTER TABLE `proveedores` ADD COLUMN `descuento_habitual` DECIMAL(5,2) DEFAULT 0.00 AFTER `dias_pago`' },
    @{ Name = 'notas'; Sql = 'ALTER TABLE `proveedores` ADD COLUMN `notas` TEXT DEFAULT NULL AFTER `descuento_habitual`' }
  )

  foreach ($col in $cols) {
    if (-not (Has-Column 'proveedores' $col.Name)) {
      Log "Compat proveedores: agregando columna $($col.Name)"
      Exec-MySql $col.Sql
    }
  }
}

$migrationFailed = $false
$dbNameRecoveredFromBackup = $false
try {
  Log "=== FLUS upgrade DB ==="
  if ([string]::IsNullOrWhiteSpace($DbName)) {
    $DbName = Get-ConfiguredDbName
    if ([string]::IsNullOrWhiteSpace($DbName)) {
      $DbName = Get-VerifiedPreupgradeDbName
      if (-not [string]::IsNullOrWhiteSpace($DbName)) {
        $dbNameRecoveredFromBackup = $true
        Log "DbName recuperado desde el backup previo verificado -> $DbName"
      }
    }
    if ([string]::IsNullOrWhiteSpace($DbName)) {
      $configPath = Join-Path $Root "app\src\config.php"
      if (Test-Path $configPath) {
        throw "No pude detectar DB_NAME ni validarlo contra el backup previo. Se cancela el upgrade para no modificar una base incorrecta."
      }
      $DbName = "flus_db"
      Log "Instalacion nueva sin config.php; uso base inicial $DbName."
    } elseif (-not $dbNameRecoveredFromBackup) {
      Log "DbName detectado desde config.php -> $DbName"
    }
  }
  Ensure-SafeDbIdentifier $DbName

  Log "Root=$Root DbName=$DbName (DbPort solicitado=$DbPort)"
  if ($hasInstall) { Log "InstallSql=$installSqlPath" }

  # 0) Asegurar DB (por si no existe)
  # 0) Asegurar que MySQL estÃƒÆ’Ã‚Â© corriendo y detectar puerto real
  Ensure-MySqlRunning
  $DbPort = Detect-DbPort $DbPort
  Log "DbPort detectado -> $DbPort"

  Ensure-DbExists

  # 1) Cargar lista de migraciones (si existe carpeta)
  $migsAll = @()
  if ($hasMigDir) {
    $migsAll = Get-MigrationFiles $migDir
    if ($migsAll.Count -eq 0) { Log "WARN: No hay .sql en $migDir" }
  } else {
    Log "WARN: No existe $migDir (ok si usÃƒÆ’Ã‚Â¡s install.sql como baseline)."
  }

  # 2) Si la DB estÃƒÆ’Ã‚Â¡ vacÃƒÆ’Ã‚Â­a y hay install.sql -> importar baseline
  $tables = Get-Tables
  $isEmpty = ($tables.Count -eq 0) -or (($tables.Count -eq 1) -and ($tables[0] -eq "schema_migrations"))
  if ($isEmpty -and $hasInstall) {
    Log "DB vacÃƒÆ’Ã‚Â­a detectada. Aplicando baseline install.sql..."
    Import-SqlFile $installSqlPath


    # Asegurar tracking
    Ensure-SchemaMigrationsCompatibility

    # sanity check mÃƒÆ’Ã‚Â­nimo (evita que marquemos migraciones si el baseline quedÃƒÆ’Ã‚Â³ viejo)
    $okBaseline = (Has-Column "compras" "total_bruto") -and (Has-Column "compra_items" "descuento") -and (Has-Column "ventas" "cliente_id")
    if (-not $okBaseline) {
      Log "WARN: baseline aplicado pero faltan columnas clave (compras.total_bruto / compra_items.descuento / ventas.cliente_id)."
      Log "WARN: continuarÃƒÆ’Ã‚Â© con el flujo de migraciones para completar la DB (si hay migrations)."
    } else {
      if ($migsAll.Count -gt 0) {
        Log "Marcando migraciones actuales como aplicadas (instalaciÃƒÆ’Ã‚Â³n nueva)..."
        foreach ($f in $migsAll) {
          $fnEsc = $f.Name.Replace("'", "''")
          Exec-MySql "INSERT IGNORE INTO schema_migrations(filename, applied_at) VALUES ('$fnEsc', NOW())"
        }
      }
      Log "Baseline OK. DB lista."
      Log "=== FIN upgrade DB ==="
      exit 0
    }
  }

  # si no hay migrations, no hay nada mÃƒÆ’Ã‚Â¡s que hacer
  if ($migsAll.Count -eq 0) {
    Log "No hay migrations para aplicar."
    Log "=== FIN upgrade DB ==="
    exit 0
  }

  # 3) Asegurar tabla de tracking (por si venimos de una instalaciÃƒÆ’Ã‚Â³n vieja)
  Ensure-SchemaMigrationsCompatibility
  Ensure-ProviderTransferColumns

  # 4) Leer migraciones ya aplicadas
  $applied = @{}
  $appliedLines = Query-MySql "SELECT filename FROM schema_migrations"
  foreach ($line in $appliedLines) {
    $k = ("" + $line).Trim()
    if ($k) { $applied[$k] = $true }
  }

  # 5) Pendientes = archivos que no estÃƒÆ’Ã‚Â©n en schema_migrations
  $migs = @()
  foreach ($f in $migsAll) {
    if (-not $applied.ContainsKey($f.Name)) { $migs += $f }
  }

  if ($migs.Count -eq 0) {

  Log "No hay migraciones nuevas. Schema al dÃƒÆ’Ã‚Â­a -> no se toca la DB."
    Log "=== FIN upgrade DB ==="
    exit 0
  }

  Log ("Migraciones pendientes: " + $migs.Count)
  foreach ($p in $migs) { Log (" - " + $p.Name) }

  # 6) Detener Apache solo si estÃƒÆ’Ã‚Â¡ corriendo (para evitar escrituras web)
  try {
    $svcApache = Get-Service -Name "FLUS_Apache" -ErrorAction Stop
    $apacheWasRunning = ($svcApache.Status -eq "Running")
    if ($apacheWasRunning) {
      Log "Deteniendo servicio FLUS_Apache..."
      Stop-Service -Name "FLUS_Apache" -Force -ErrorAction Stop
      Start-Sleep -Seconds 2
    } else {
      Log "FLUS_Apache ya estaba detenido."
    }
  } catch {
    Log "WARN: No pude consultar/detener FLUS_Apache (puede no existir)."
  }

  # 7) Backup (solo si hay pendientes)
  if (-not $NoBackup) {
    if (!(Test-Path $mysqldumpExe)) { throw "No encuentro mysqldump.exe en $mysqldumpExe" }
      $bkFile = Join-Path $bkDir ($DbName + "_backup_" + $stamp + ".sql")
    Log "Backup DB -> $bkFile"
    cmd.exe /c "`"$mysqldumpExe`" -h 127.0.0.1 -P $DbPort -u root `"$DbName`" > `"$bkFile`""
    if ($LASTEXITCODE -ne 0) { throw "Backup fallÃƒÆ’Ã‚Â³ (exit=$LASTEXITCODE)" }
  } else {
    Log "NoBackup=ON (saltando backup)"
  }

  # 8) Aplicar SOLO pendientes
  foreach ($f in $migs) {
    if (Test-MigrationAlreadySatisfied $f.Name) {
      Log "Migracion ya absorbida por compatibilidad: $($f.Name). Se marca como aplicada sin reejecutar SQL."
      $fnEsc = $f.Name.Replace("'", "''")
      Exec-MySql "INSERT IGNORE INTO schema_migrations(filename, applied_at) VALUES ('$fnEsc', NOW())"
      continue
    }

    Log "Aplicando: $($f.Name)"

    cmd.exe /c "`"$mysqlExe`" -h 127.0.0.1 -P $DbPort -u root `"$DbName`" < `"$($f.FullName)`""
    if ($LASTEXITCODE -ne 0) { throw "Fallo migraciÃƒÆ’Ã‚Â³n $($f.Name) (exit=$LASTEXITCODE)" }

    $fnEsc = $f.Name.Replace("'", "''")
    Exec-MySql "INSERT INTO schema_migrations(filename, applied_at) VALUES ('$fnEsc', NOW())"
  }
  Log "Migraciones OK."
  Log "=== FIN upgrade DB ==="
} catch {
  $migrationFailed = $true
  $safeError = ('' + $_.Exception.Message) -replace '[\r\n]+', ' '
  Log "ERROR: $safeError"
}
finally {
  # 9) Levantar Apache SOLO si estaba corriendo antes
  if ($apacheWasRunning) {
    try {
      Log "Iniciando servicio FLUS_Apache..."
      Start-Service -Name "FLUS_Apache" -ErrorAction Stop
    } catch {
      Log "WARN: No pude iniciar FLUS_Apache (puede no existir o fallÃƒÆ’Ã‚Â³)."
    }
  }

  try { Stop-Transcript | Out-Null } catch {}
}

if ($migrationFailed) { exit 1 }
