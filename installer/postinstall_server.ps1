<#
postinstall_server.ps1 (FINAL) - v7 (robusto / instalaciÃƒÂ³n desde 0)

Objetivo:
- Instalar FLUS portable en C:\FLUS (o el Root que pases)
- Configurar Apache + PHP portable (sin hardcode C:\xampp)
- Inicializar MariaDB datadir de forma robusta
- Instalar servicios (FLUS_Apache / FLUS_MariaDB) SIN error 1053
- Crear DB + importar install.sql
- Crear usuario DB flus_user/flus1234 y generar app\src\config.php
- Crear acceso directo en Escritorio (FLUS.url)

Ejemplo:
  powershell -ExecutionPolicy Bypass -NoProfile -File .\postinstall_server.ps1 -Root "C:\FLUS" -ResetDb
#>

param(
  [string]$Root = "C:\FLUS",
  [int]$Port = 8080,
  [int]$DbPort = 3307,
  [string]$DbName = "flus_db",
  [string]$DbUser = "flus_user",
  [string]$DbPass = "flus1234",
  [switch]$ResetDb,
  [switch]$SkipMigrations
)

function Normalize-InstallerArg([string]$Value) {
  if ($null -eq $Value) { return "" }

  $normalized = $Value.Trim()
  $slash = [char]92
  $single = [char]39
  $double = [char]34
  while ($normalized.Length -ge 2) {
    $first = $normalized.Substring(0, 1)
    $last = $normalized.Substring($normalized.Length - 1, 1)
    if ($normalized.Length -ge 4 -and (
      ($normalized.StartsWith("$slash$single") -and $normalized.EndsWith("$slash$single")) -or
      ($normalized.StartsWith("$slash$double") -and $normalized.EndsWith("$slash$double"))
    )) {
      $normalized = $normalized.Substring(2, $normalized.Length - 4).Trim()
      continue
    }
    if (($first -eq "'" -and $last -eq "'") -or ($first -eq '"' -and $last -eq '"')) {
      $normalized = $normalized.Substring(1, $normalized.Length - 2).Trim()
      continue
    }
    break
  }

  return $normalized
}

$DbName = Normalize-InstallerArg $DbName
$DbUser = Normalize-InstallerArg $DbUser
$DbPass = Normalize-InstallerArg $DbPass

function Assert-SafeInstallerIdentifier([string]$Value, [string]$Label) {
  if ([string]::IsNullOrWhiteSpace($Value)) { throw "$Label no puede estar vacio." }
  if ($Value -notmatch '^[A-Za-z0-9_]+$') {
    throw "$Label '$Value' no es valido. Usar solo letras, numeros y guion bajo."
  }
}

Assert-SafeInstallerIdentifier $DbName "DbName"
Assert-SafeInstallerIdentifier $DbUser "DbUser"

$ErrorActionPreference = "Stop"
$ProgressPreference    = "SilentlyContinue"
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}

# -------------------------
# Admin check
# -------------------------
function Assert-Admin {
  $id = [Security.Principal.WindowsIdentity]::GetCurrent()
  $p  = New-Object Security.Principal.WindowsPrincipal($id)
  if (-not $p.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw "Tenes que ejecutar este script como ADMIN (Run as Administrator)."
  }
}
Assert-Admin

# -------------------------
# Helpers
# -------------------------
function ToSlash([string]$p) { return $p.Replace('\','/') }

function Ensure-Dir([string]$path) {
  if (-not (Test-Path $path)) { New-Item -ItemType Directory -Path $path | Out-Null }
}

function Test-PortFree([int]$p) {
  try {
    $l = [System.Net.Sockets.TcpListener]::new([System.Net.IPAddress]::Loopback, $p)
    $l.Start(); $l.Stop()
    return $true
  } catch { return $false }
}

function Pick-FreePort([int[]]$candidates, [int]$fallbackStart) {
  foreach ($p in $candidates) { if (Test-PortFree $p) { return $p } }
  for ($p=$fallbackStart; $p -lt $fallbackStart+200; $p++) { if (Test-PortFree $p) { return $p } }
  throw "No encontre un puerto libre en el rango."
}

function Wait-ServiceRunning([string]$name, [int]$seconds = 45) {
  for ($i=0; $i -lt $seconds; $i++) {
    $svc = Get-Service -Name $name -ErrorAction SilentlyContinue
    if ($svc -and $svc.Status -eq 'Running') { return $true }
    Start-Sleep -Seconds 1
  }
  return $false
}

function Throw-IfLastExitNotZero([string]$what) {
  if ($LASTEXITCODE -ne 0) { throw "$what fallo (exitcode=$LASTEXITCODE)." }
}

# Escribir texto UTF-8 SIN BOM (Apache/php.ini/config.php)
function Write-Utf8NoBom([string]$path, [string]$text) {
  $enc = New-Object System.Text.UTF8Encoding($false)
  [System.IO.File]::WriteAllText($path, $text, $enc)
}

# -------------------------
# Logging
# -------------------------
Ensure-Dir $Root
$logFile = Join-Path $Root "install.log"

function Log([string]$msg) {
  $line = "[{0}] {1}" -f (Get-Date -Format "yyyy-MM-dd HH:mm:ss"), $msg
  Write-Host $line
  try { Add-Content -Path $logFile -Value $line -Encoding UTF8 } catch {}
}

# -------------------------
# Helpers (servicios - robusto)
#  - Evita el clÃƒÂ¡sico 1072 "marcado para ser eliminado" cuando services.msc (mmc.exe) estÃƒÂ¡ abierto.
# -------------------------
function Kill-Mmc {
  try { taskkill /F /IM mmc.exe | Out-Null } catch {}
}

function Wait-ServiceDeleted([string]$name, [int]$seconds = 20) {
  for ($i=0; $i -lt $seconds; $i++) {
    $out = sc.exe query $name 2>&1
    $txt = ($out -join "`n")
    if ($txt -match "1060" -or $txt -match "does not exist" -or $txt -match "no existe") { return $true }
    if ($txt -match "1072" -or $txt -match "marked for deletion" -or $txt -match "marcado para ser eliminado") {
      Log "WARN: servicio $name marcado para eliminar -> cierro services.msc (mmc.exe) y espero..."
      Kill-Mmc
    }
    Start-Sleep -Seconds 1
  }
  return $false
}

function Sc-CreateWithRetry([string]$name, [string]$binPath, [string]$displayName) {
  for ($i=0; $i -lt 8; $i++) {
    $out = sc.exe create $name binPath= $binPath start= auto DisplayName= "$displayName" 2>&1
    foreach ($l in $out) { Log "sc create: $l" }
    if ($LASTEXITCODE -eq 0) { return }
    $txt = ($out -join "`n")
    if ($txt -match "1072" -or $txt -match "marked for deletion" -or $txt -match "marcado para ser eliminado") {
      Log "WARN: $name marcado para eliminar -> cierro services.msc (mmc.exe) y reintento..."
      Kill-Mmc
      Start-Sleep -Seconds 2
      continue
    }
    throw "sc create $name fallo (exitcode=$LASTEXITCODE)."
  }
  throw "sc create $name no pudo completarse (posible 'marked for deletion'). CerrÃƒÂ¡ services.msc o reiniciÃƒÂ¡."
}

# Ejecutar proceso con logs (stdout+stderr) SIN romper por stderr
function Run-Proc {
  param(
    [Parameter(Mandatory=$true)][string]$FilePath,
    [string[]]$ArgumentList = @(),
    [Parameter(Mandatory=$true)][string]$Label,
    [string]$RedirectStandardInput = $null
  )

  $ArgumentList = @($ArgumentList | Where-Object { $_ -ne $null -and $_ -ne '' })

  $tmpOut = Join-Path $env:TEMP ("flus_" + [guid]::NewGuid().ToString("N") + ".out")
  $tmpErr = Join-Path $env:TEMP ("flus_" + [guid]::NewGuid().ToString("N") + ".err")

  $spArgs = @{
    FilePath = $FilePath
    Wait = $true
    PassThru = $true
    NoNewWindow = $true
    RedirectStandardOutput = $tmpOut
    RedirectStandardError  = $tmpErr
  }

  if ($ArgumentList.Count -gt 0) { $spArgs['ArgumentList'] = $ArgumentList }

  if ($RedirectStandardInput -and (Test-Path $RedirectStandardInput)) {
    $spArgs['RedirectStandardInput'] = $RedirectStandardInput
  }

  $p = Start-Process @spArgs

  if (Test-Path $tmpOut) { Get-Content $tmpOut -ErrorAction SilentlyContinue | ForEach-Object { Log ("{0}: {1}" -f $Label, $_) } }
  if (Test-Path $tmpErr) { Get-Content $tmpErr -ErrorAction SilentlyContinue | ForEach-Object { Log ("{0} (stderr): {1}" -f $Label, $_) } }

  Remove-Item $tmpOut,$tmpErr -Force -ErrorAction SilentlyContinue
  return $p.ExitCode
}

# -------------------------
# Elegir puertos
# -------------------------
$Port   = Pick-FreePort @($Port,8081,8090,18080,28080) 18080
$DbPort = Pick-FreePort @($DbPort,3308,3310,13307,23307) 13307

# -------------------------
# Paths
# -------------------------
$stack  = Join-Path $Root "stack"
$appDir = Join-Path $Root "app"
$configReal = Join-Path $appDir "src\config.php"  # usado para detectar instalaciÃƒÂ³n nueva
$configExistsAtStart = Test-Path $configReal

# Baseline SQL (prioridad: db\install.sql -> app\storage\install.sql -> app\scripts\install.sql)
$sqlFileCandidates = @(
  (Join-Path $Root "db\install.sql"),
  (Join-Path $appDir "storage\install.sql"),
  (Join-Path $appDir "scripts\install.sql")
)
$sqlFile = $sqlFileCandidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1

Log "=== FLUS postinstall ==="
Log "Root=$Root"
Log "Puertos elegidos -> HTTP=$Port / DB=$DbPort"
Log "DbName=$DbName DbUser=$DbUser"
Log ("ResetDb=" + [bool]$ResetDb)

if (!(Test-Path $stack))  { throw "No encuentro stack en: $stack" }
if (!(Test-Path $appDir)) { throw "No encuentro app en: $appDir" }
if ($ResetDb -and $configExistsAtStart) {
  throw "ResetDb cancelado: ya existe app\src\config.php. Uso upgrade_db.ps1 para actualizar una instalacion existente."
}
if (-not $sqlFile) { throw "No encuentro baseline SQL (db\install.sql / app\storage\install.sql / app\scripts\install.sql) bajo: $Root" }

# tmp/logs (para php.ini portable)
Ensure-Dir (Join-Path $Root "tmp")
Ensure-Dir (Join-Path $Root "logs")

# -------------------------
# setup_xampp.bat (opcional)
# -------------------------
$setupBat = Join-Path $stack "setup_xampp.bat"
$setupPhp = Join-Path $stack "install\install.php"
if ((Test-Path $setupBat) -and (Test-Path $setupPhp)) {
  Log "Ejecutando setup_xampp.bat (opcional)..."
  $batOut = cmd.exe /c "cd /d `"$stack`" && call setup_xampp.bat" 2>&1
  foreach ($l in $batOut) { Log "setup_xampp: $l" }
  if ($LASTEXITCODE -ne 0) { Log "WARN: setup_xampp.bat fallo (exitcode=$LASTEXITCODE). Continuo igual." }
} elseif (Test-Path $setupBat) {
  Log "setup_xampp.bat encontrado pero falta $setupPhp -> salto (evita warning)."
}

# -------------------------
# Binaries
# -------------------------
$mysqlBase = Join-Path $stack "mysql"
if (!(Test-Path $mysqlBase)) {
  $alt = Join-Path $stack "mariadb"
  if (Test-Path $alt) { $mysqlBase = $alt } else { throw "No encuentro stack\\mysql ni stack\\mariadb dentro de: $stack" }
}
$mysqlBin   = Join-Path $mysqlBase "bin"
$mysqlExe   = Join-Path $mysqlBin "mysql.exe"
$mysqldExe  = Join-Path $mysqlBin "mysqld.exe"
$dataDir    = Join-Path $mysqlBase "data"
$myIni      = Join-Path $dataDir "my.ini"

$apacheBase = Join-Path $stack "apache"
$httpdExe   = Join-Path $apacheBase "bin\httpd.exe"
$httpdConf  = Join-Path $apacheBase "conf\httpd.conf"

$phpBase    = Join-Path $stack "php"
$phpIniPath = Join-Path $phpBase "php.ini"
$phpIniDir  = ToSlash $phpBase

if (!(Test-Path $mysqlExe))   { throw "No encuentro mysql.exe en: $mysqlExe" }
if (!(Test-Path $mysqldExe))  { throw "No encuentro mysqld.exe en: $mysqldExe" }
if (!(Test-Path $httpdExe))   { throw "No encuentro httpd.exe en: $httpdExe" }
if (!(Test-Path $httpdConf))  { throw "No encuentro httpd.conf en: $httpdConf" }
if (!(Test-Path $phpIniPath)) { throw "No encuentro php.ini en: $phpIniPath" }

# -------------------------
# Patch php.ini (portable)
# -------------------------
function Patch-PhpIni([string]$iniPath, [string]$root, [string]$phpDir, [string]$apacheDir) {
  $phpPear = Join-Path $phpDir "PEAR"
  $phpExt  = Join-Path $phpDir "ext"

  $tmpDir  = Join-Path $root "tmp"
  $logDir  = Join-Path $root "logs"
  Ensure-Dir $tmpDir
  Ensure-Dir $logDir

  $phpErr  = Join-Path $logDir "php_error_log"
  $ca      = Join-Path (Join-Path $apacheDir "bin") "curl-ca-bundle.crt"

  $t = Get-Content -Path $iniPath -Raw

  $phpDirEsc = [regex]::Escape($phpDir)
  $apacheDirEsc = [regex]::Escape($apacheDir)
  $t = [regex]::Replace($t, '(?i)C:[\\/]+xampp82[\\/]+php', $phpDirEsc.Replace('\\', '\'))
  $t = [regex]::Replace($t, '(?i)C:[\\/]+xampp[\\/]+php', $phpDirEsc.Replace('\\', '\'))
  $t = [regex]::Replace($t, '(?i)C:[\\/]+FLUS[\\/]+stack[\\/]+php', $phpDirEsc.Replace('\\', '\'))
  $t = [regex]::Replace($t, '(?i)C:[\\/]+xampp82[\\/]+apache', $apacheDirEsc.Replace('\\', '\'))
  $t = [regex]::Replace($t, '(?i)C:[\\/]+xampp[\\/]+apache', $apacheDirEsc.Replace('\\', '\'))
  $t = [regex]::Replace($t, '(?i)C:[\\/]+FLUS[\\/]+stack[\\/]+apache', $apacheDirEsc.Replace('\\', '\'))
  $t = [regex]::Replace($t, '(?i)C:[\\/]+xampp82[\\/]+tmp', ([regex]::Escape($tmpDir)).Replace('\\', '\'))
  $t = [regex]::Replace($t, '(?i)C:[\\/]+xampp[\\/]+tmp', ([regex]::Escape($tmpDir)).Replace('\\', '\'))
  $t = [regex]::Replace($t, '(?i)C:[\\/]+FLUS[\\/]+tmp', ([regex]::Escape($tmpDir)).Replace('\\', '\'))

  $t = [regex]::Replace($t, '(?m)^\s*include_path\s*=.*$', ('include_path=".;' + $phpPear + '"'))
  $t = [regex]::Replace($t, '(?m)^\s*extension_dir\s*=.*$', ('extension_dir="' + $phpExt + '"'))
  $t = [regex]::Replace($t, '(?m)^\s*upload_tmp_dir\s*=.*$', ('upload_tmp_dir="' + $tmpDir + '"'))
  $t = [regex]::Replace($t, '(?m)^\s*session\.save_path\s*=.*$', ('session.save_path="' + $tmpDir + '"'))
  $t = [regex]::Replace($t, '(?m)^\s*error_log\s*=.*$', ('error_log="' + $phpErr + '"'))

  $b = Join-Path (Join-Path $phpDir "extras") "browscap.ini"
  if (Test-Path $b) {
    $t = [regex]::Replace($t, '(?m)^\s*;?\s*browscap\s*=.*$', ('browscap="' + $b + '"'))
  } else {
    $t = [regex]::Replace($t, '(?m)^\s*browscap\s*=.*$', (';browscap="' + $b + '"'))
  }

  if (Test-Path $ca) {
    $t = [regex]::Replace($t, '(?m)^\s*curl\.cainfo\s*=.*$', ('curl.cainfo="' + $ca + '"'))
    $t = [regex]::Replace($t, '(?m)^\s*openssl\.cafile\s*=.*$', ('openssl.cafile="' + $ca + '"'))
  }

  # Extensiones requeridas por FLUS. Evita el caso donde CLI carga OpenSSL
  # pero Apache no puede validar licencias por tener php_openssl.dll duplicado
  # o extension=openssl comentado.
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
  $t = $lines -join "`r`n"

  Write-Utf8NoBom -path $iniPath -text $t
}

Log "Parchando php.ini (portable)..."
Patch-PhpIni -iniPath $phpIniPath -root $Root -phpDir $phpBase -apacheDir $apacheBase

# -------------------------
# Apache config (portable + mod_php)
# -------------------------
$docRootFs     = Join-Path $appDir "public"
$docRootApache = ToSlash $docRootFs
if (!(Test-Path $docRootFs)) { throw "No encuentro app/public en: $docRootFs" }

$apacheRootSlash = ToSlash $apacheBase

# detectar dll de apache para php (mod_php)
$phpApacheDll = Get-ChildItem -Path $phpBase -Filter "php*apache*.dll" -File -ErrorAction SilentlyContinue | Select-Object -First 1
if (-not $phpApacheDll) { throw "No encuentro php*apache*.dll en: $phpBase (necesario para mod_php)" }

$phpTsDll = @(
  (Join-Path $phpBase "php8ts.dll"),
  (Join-Path $phpBase "php7ts.dll")
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $phpTsDll) {
  $phpTsDll = Get-ChildItem -Path $phpBase -Filter "php*ts.dll" -File -ErrorAction SilentlyContinue |
    Select-Object -First 1 | ForEach-Object { $_.FullName }
}
if (-not $phpTsDll) { throw "No encuentro php*ts.dll en: $phpBase" }

$phpDependencyPatterns = @(
  'libcrypto-*.dll',
  'libssl-*.dll',
  'libssh2.dll',
  'nghttp2.dll',
  'libsqlite3.dll',
  'libsodium.dll',
  'libpq.dll',
  'libsasl.dll',
  'glib-2.dll',
  'gmodule-2.dll',
  'libenchant2.dll',
  'icudt*.dll',
  'icuin*.dll',
  'icuio*.dll',
  'icuuc*.dll'
)
$phpDependencyLoadFiles = @()
foreach ($depPattern in $phpDependencyPatterns) {
  $deps = Get-ChildItem -Path $phpBase -Filter $depPattern -File -ErrorAction SilentlyContinue
  foreach ($dep in $deps) {
    $line = 'LoadFile "' + (ToSlash $dep.FullName) + '"'
    if ($phpDependencyLoadFiles -notcontains $line) {
      $phpDependencyLoadFiles += $line
    }
  }
}

$phpApacheDllS = ToSlash $phpApacheDll.FullName
$phpTsDllS     = ToSlash $phpTsDll

Log "Configurando Apache: DocumentRoot=$docRootApache, Port=$Port, SRVROOT=$apacheRootSlash, PHPIniDir=$phpIniDir"

$confText = Get-Content -Path $httpdConf -Raw

# Define SRVROOT (portable)
if ($confText -match '(?m)^\s*Define\s+SRVROOT\s+"[^"]+"\s*$') {
  $confText = [regex]::Replace($confText, '(?m)^\s*Define\s+SRVROOT\s+"[^"]+"\s*$', "Define SRVROOT `"$apacheRootSlash`"")
} else {
  $confText = "Define SRVROOT `"$apacheRootSlash`"`r`n" + $confText
}

# ServerRoot (portable)
if ($confText -match '(?m)^\s*ServerRoot\s+"[^"]+"\s*$') {
  $confText = [regex]::Replace($confText, '(?m)^\s*ServerRoot\s+"[^"]+"\s*$', "ServerRoot `"$apacheRootSlash`"")
} else {
  $confText = "ServerRoot `"$apacheRootSlash`"`r`n" + $confText
}

# Deshabilitar includes heredados que no usa FLUS y suelen traer rutas fijas.
$disabledExtraIncludes = @(
  'httpd-xampp.conf',
  'httpd-ssl.conf',
  'httpd-vhosts.conf',
  'httpd-userdir.conf',
  'httpd-info.conf',
  'httpd-manual.conf',
  'httpd-dav.conf',
  'httpd-ajp.conf',
  'httpd-proxy.conf'
)
foreach ($extraInclude in $disabledExtraIncludes) {
  $quotedPat = '(?m)^\s*Include\s+"conf/extra/' + [regex]::Escape($extraInclude) + '"\s*$'
  $plainPat  = '(?m)^\s*Include\s+conf/extra/' + [regex]::Escape($extraInclude) + '\s*$'
  $disabled  = '# Include "conf/extra/' + $extraInclude + '"  # (disabled for FLUS portable)'
  $confText = [regex]::Replace($confText, $quotedPat, $disabled)
  $confText = [regex]::Replace($confText, $plainPat, $disabled)
}

# Listen
if ($confText -match '(?m)^\s*Listen\s+') {
  $confText = [regex]::Replace($confText, '(?m)^\s*Listen\s+.*$', "Listen $Port")
} else {
  $confText = "Listen $Port`r`n" + $confText
}

# ServerName
if ($confText -match '(?m)^\s*ServerName\s+') {
  $confText = [regex]::Replace($confText, '(?m)^\s*ServerName\s+.*$', "ServerName localhost:$Port")
} else {
  $confText += "`r`nServerName localhost:$Port`r`n"
}

# DocumentRoot
if ($confText -match '(?m)^\s*DocumentRoot\s+"[^"]+"\s*$') {
  $confText = [regex]::Replace($confText, '(?m)^\s*DocumentRoot\s+"[^"]+"\s*$', "DocumentRoot `"$docRootApache`"")
} else {
  $confText += "`r`nDocumentRoot `"$docRootApache`"`r`n"
}

# DirectoryIndex robusto
$confText = [regex]::Replace(
  $confText,
  "(?ms)^\s*DirectoryIndex\s+.*?(?:\\\s*\r?\n\s*.*?)*\r?$",
  "DirectoryIndex index.php index.html"
)
$confText = [regex]::Replace($confText, "(?m)^\s*default\.php\s*$\r?\n?", "")

# limpiar inyecciones previas (idempotente)
$confText = [regex]::Replace($confText, '(?m)^\s*PHPIniDir\s+.*$\r?\n?', '')
$confText = [regex]::Replace($confText, '(?m)^\s*LoadModule\s+php_module\s+.*$\r?\n?', '')
$confText = [regex]::Replace($confText, '(?m)^\s*LoadFile\s+.*php.*ts\.dll.*$\r?\n?', '')
$confText = [regex]::Replace($confText, '(?ms)^\s*#\s*---\s*FLUS PORTABLE PHP START\s*---.*?#\s*---\s*FLUS PORTABLE PHP END\s*---\s*\r?\n?', '')

# Directory block docroot
$dirBlock = "<Directory `"$docRootApache`">`r`n    AllowOverride All`r`n    Require all granted`r`n</Directory>"
if ($confText -notmatch [regex]::Escape("<Directory `"$docRootApache`">")) {
  $confText += "`r`n$dirBlock`r`n"
}

# PHP block
$phpDependencyBlock = ($phpDependencyLoadFiles -join "`r`n")
$phpBlock = @"
# --- FLUS PORTABLE PHP START ---
$phpDependencyBlock
LoadFile "$phpTsDllS"
LoadModule php_module "$phpApacheDllS"
PHPIniDir "$phpIniDir"
AddHandler application/x-httpd-php .php
AddType application/x-httpd-php .php
# --- FLUS PORTABLE PHP END ---
"@
$confText += "`r`n$phpBlock`r`n"

Write-Utf8NoBom -path $httpdConf -text $confText

# Test Apache
$testOut = cmd.exe /c "`"$httpdExe`" -t -f `"$httpdConf`" 2>&1"
foreach ($l in $testOut) { Log "httpd -t: $l" }
Throw-IfLastExitNotZero "Apache config test"

# -------------------------
# Servicios
# -------------------------
$svcMy  = "FLUS_MariaDB"
$svcWeb = "FLUS_Apache"

Log "Instalando servicios: $svcMy / $svcWeb"

# Remover si existen
if (Get-Service -Name $svcWeb -ErrorAction SilentlyContinue) {
  Log "Removiendo servicio existente $svcWeb..."
  try { Stop-Service $svcWeb -Force -ErrorAction SilentlyContinue } catch {}
  try { & $httpdExe -k uninstall -n $svcWeb 2>$null } catch {}
  try { sc.exe delete $svcWeb | Out-Null } catch {}
  Start-Sleep -Seconds 1
  [void](Wait-ServiceDeleted $svcWeb 25)
}
if (Get-Service -Name $svcMy -ErrorAction SilentlyContinue) {
  Log "Removiendo servicio existente $svcMy..."
  try { Stop-Service $svcMy -Force -ErrorAction SilentlyContinue } catch {}
  try { & $mysqldExe --remove $svcMy 2>$null } catch {}
  try { sc.exe delete $svcMy | Out-Null } catch {}
  Start-Sleep -Seconds 1
  [void](Wait-ServiceDeleted $svcMy 25)
}

if ($ResetDb) {
  Log "ResetDb=ON -> borrando datadir: $dataDir"
  Remove-Item -Recurse -Force $dataDir -ErrorAction SilentlyContinue
}

# -------------------------
# Init DB datadir (robusto)
# -------------------------
function Init-DbDataDir([string]$mysqlBin, [string]$myIniPath, [string]$dataDir) {

  $candidates = @(
    (Join-Path $mysqlBin "mariadb-install-db.exe"),
    (Join-Path $mysqlBin "mysql_install_db.bat"),
    (Join-Path $mysqlBin "mysql_install_db.exe")
  ) | Where-Object { Test-Path $_ }

  if (-not $candidates -or $candidates.Count -eq 0) {
    throw "No encontre herramienta de init (mariadb-install-db / mysql_install_db) en: $mysqlBin"
  }

  # Deshabilitar my.ini durante init
  $disabledIni = $null
  if (Test-Path $myIniPath) {
    $disabledIni = "$myIniPath.disabled"
    try { Move-Item -Force $myIniPath $disabledIni } catch {}
  }

  try {
    $dataArg = ToSlash $dataDir

    foreach ($tool in $candidates) {

      # limpiar datadir antes de cada intento
      if (Test-Path $dataDir) { Remove-Item -Recurse -Force $dataDir -ErrorAction SilentlyContinue }
      New-Item -ItemType Directory -Path $dataDir | Out-Null

      Log "Inicializando data dir con: $tool"
      Log "dbinit: datadir=$dataArg"

      $isBat  = $tool.ToLower().EndsWith(".bat")
      $runner = if ($isBat) { "call `"$tool`"" } else { "`"$tool`"" }

      # intento 1: con --skip-test-db
      $out = cmd.exe /c "cd /d `"$mysqlBin`" && $runner --datadir=`"$dataArg`" --skip-test-db 2>&1"
      foreach ($l in $out) { Log "dbinit: $l" }

      $hasMysqlDir = Test-Path (Join-Path $dataDir "mysql")
      if ($LASTEXITCODE -eq 0 -and $hasMysqlDir) {
        Log "dbinit: OK"
        return
      }

      $all = ($out -join "`n")
      if ($all -match "unknown option\s+'--skip-test-db'" -or $all -match "unknown option.*--skip-test-db") {
        Log "dbinit: WARN --skip-test-db no soportado, reintento sin esa opcion..."

        $out2 = cmd.exe /c "cd /d `"$mysqlBin`" && $runner --datadir=`"$dataArg`" 2>&1"
        foreach ($l in $out2) { Log "dbinit: $l" }

        $hasMysqlDir2 = Test-Path (Join-Path $dataDir "mysql")
        if ($LASTEXITCODE -eq 0 -and $hasMysqlDir2) {
          Log "dbinit: OK"
          return
        }
      }

      Log "dbinit: WARN init con $tool fallo (exitcode=$LASTEXITCODE). Pruebo siguiente..."
    }

    throw "DB init fallo (no se pudo inicializar datadir). Revisar install.log / *.err"
  }
  finally {
    if ($disabledIni -and (Test-Path $disabledIni)) {
      try { Move-Item -Force $disabledIni $myIniPath } catch {}
    }
  }
}

# Si falta data, inicializar
if (-not (Test-Path (Join-Path $dataDir "mysql"))) {
  Init-DbDataDir -mysqlBin $mysqlBin -myIniPath $myIni -dataDir $dataDir
} else {
  Log "Data dir ya existe (mysql/). No inicializo."
}

# -------------------------
# Escribir my.ini FINAL
# -------------------------
Log "Escribiendo my.ini final (portable)..."

$basedirS = ToSlash $mysqlBase
$datadirS = ToSlash $dataDir

$iniFinal = @"
[client]
port=$DbPort
host=127.0.0.1

[mysqld]
port=$DbPort
bind-address=127.0.0.1
basedir="$basedirS"
datadir="$datadirS"
character-set-server=utf8mb4
collation-server=utf8mb4_unicode_ci
skip-name-resolve
sql_mode=
"@
Set-Content -Path $myIni -Value $iniFinal -Encoding ASCII

# -------------------------
# Instalar servicio MariaDB (SIN 1053)
#  - Intento 1: mysqld --install (si el build lo soporta)
#  - Fallback: sc create PERO con --service= y --defaults-file= (clave!)
#  - Y SIEMPRE forzamos binPath correcto con sc config
# -------------------------
Log "Instalando servicio MariaDB ($svcMy)..."

$svcInstalled = $false

# intento 1: mysqld --install (si existe)
$installOut = cmd.exe /c "`"$mysqldExe`" --install $svcMy --defaults-file=`"$myIni`" 2>&1"
foreach ($l in $installOut) { Log "mysqld --install: $l" }
if ((Get-Service -Name $svcMy -ErrorAction SilentlyContinue)) { $svcInstalled = $true }

# fallback: sc create (pero BIEN)
if (-not $svcInstalled) {
  $bin = "`"$mysqldExe`" --defaults-file=`"$myIni`" --service=$svcMy"
  Log "Registrando servicio MariaDB via sc.exe (binPath=$bin)"
  Sc-CreateWithRetry $svcMy $bin "FLUS MariaDB"
  $svcInstalled = $true
}

# Solo forzar autostart - NO tocar binPath (mysqld --install ya lo configurÃƒÂ³ correctamente)
# Sobreescribir el binPath causa error 1053: mysqld arranca pero Windows no recibe la seÃƒÂ±al de "ready"
sc.exe config $svcMy start= auto | Out-Null
Log "Servicio ${svcMy} OK (binPath conservado tal como lo registrÃƒÂ³ mysqld --install)."

# -------------------------
# Instalar servicio Apache
# -------------------------
$svcWebExists = (Get-Service -Name $svcWeb -ErrorAction SilentlyContinue) -ne $null
if (-not $svcWebExists) {
  Log "Instalando servicio Apache ($svcWeb)..."
  $code = Run-Proc -FilePath $httpdExe -ArgumentList @('-k','install','-n',$svcWeb,'-f',$httpdConf) -Label 'httpd install'
  $svcWebExists2 = (Get-Service -Name $svcWeb -ErrorAction SilentlyContinue) -ne $null
  if (-not $svcWebExists2) { throw "No se creÃƒÂ³ el servicio $svcWeb (exitcode=$code). Revisar httpd.conf y permisos." }
  if ($code -ne 0) { Log "WARN: httpd -k install devolviÃƒÂ³ exitcode=$code pero el servicio existe -> continuo." }
} else {
  Log "Servicio Apache $svcWeb ya existe -> skip install."
}

# -------------------------
# Start MySQL
# -------------------------
Log "Iniciando $svcMy..."
try { Start-Service $svcMy } catch { Log "WARN: Start-Service ${svcMy}: $($_.Exception.Message)" }
Start-Sleep -Seconds 2

if (-not (Wait-ServiceRunning $svcMy 60)) {
  $st = (Get-Service $svcMy).Status
  Log "FATAL: $svcMy no quedÃƒÂ³ Running (estado=$st). Dump sc query:"
  (sc.exe query $svcMy 2>&1) | ForEach-Object { Log "sc query: $_" }

  $err = Get-ChildItem -Path $dataDir -Filter "*.err" -File -ErrorAction SilentlyContinue |
    Sort-Object LastWriteTime -Descending | Select-Object -First 1
  if ($err) {
    Log "Ultimo *.err: $($err.FullName)"
    try { Get-Content $err.FullName -Tail 120 | ForEach-Object { Log "mysqld.err: $_" } } catch {}
  }

  throw "No pudo iniciar $svcMy. (Probable binPath incorrecto o permisos)."
}

# -------------------------
# Esperar MySQL responda (CORRECTO: chequea $LASTEXITCODE)
# -------------------------
Log "Esperando que MySQL responda..."
$ok = $false
for ($i=0; $i -lt 60; $i++) {
  & $mysqlExe -h 127.0.0.1 -P $DbPort -u root -e "SELECT 1;" 2>$null | Out-Null
  if ($LASTEXITCODE -eq 0) { $ok = $true; break }
  Start-Sleep -Milliseconds 400
}
if (-not $ok) { throw "MySQL no responde en 127.0.0.1:$DbPort (root sin pass)." }

$bt = [char]96

function Quote-MySqlIdentifier([string]$value, [string]$label) {
  if ([string]::IsNullOrWhiteSpace($value)) { throw "$label no puede estar vacio." }
  if ($value.IndexOf([char]0) -ge 0) { throw "$label contiene un caracter invalido." }
  if ($value -notmatch '^[A-Za-z0-9_]+$') {
    throw "$label '$value' no es valido. Usar solo letras, numeros y guion bajo."
  }
  return "$bt" + $value.Replace("$bt", "$bt$bt") + "$bt"
}

function Quote-MySqlString([string]$value) {
  if ($null -eq $value) { $value = "" }
  return "'" + $value.Replace("\", "\\").Replace("'", "''") + "'"
}

$dbNameSqlIdent = Quote-MySqlIdentifier $DbName "DbName"
$dbNameSqlValue = Quote-MySqlString $DbName
$dbUserSqlValue = Quote-MySqlString $DbUser
$dbPassSqlValue = Quote-MySqlString $DbPass

# -------------------------
# DB + import
# -------------------------
Log "Creando DB e importando SQL..."

$isNewInstall  = -not (Test-Path $configReal)
$shouldResetDb = [bool]$ResetDb -or $isNewInstall

if ($shouldResetDb) {
  Log ("Reset DB activado (ResetDb=" + [bool]$ResetDb + ", isNewInstall=" + [bool]$isNewInstall + ") -> DROP DATABASE IF EXISTS " + $DbName)
  $dropSql = "DROP DATABASE IF EXISTS $dbNameSqlIdent;"
  $dropOut = & $mysqlExe -h 127.0.0.1 -P $DbPort -u root -e $dropSql 2>&1
  foreach ($l in $dropOut) { Log "mysql drop-db: $l" }
  Throw-IfLastExitNotZero "Drop DB"
}

$createDbSql = "CREATE DATABASE IF NOT EXISTS $dbNameSqlIdent CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
$dbOut = & $mysqlExe -h 127.0.0.1 -P $DbPort -u root -e $createDbSql 2>&1
foreach ($l in $dbOut) { Log "mysql create-db: $l" }
Throw-IfLastExitNotZero "Create DB"

# Import solo si la DB estÃƒÂ¡ vacÃƒÂ­a
$tablesCountRaw = (& $mysqlExe -h 127.0.0.1 -P $DbPort -u root -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=$dbNameSqlValue AND table_type='BASE TABLE';") 2>$null
$tc = ($tablesCountRaw | Select-Object -Last 1) -as [int]
if (-not $tc) { $tc = 0 }

if ($tc -eq 0) {
  Log "Importando baseline SQL desde: $sqlFile"
  # El baseline actual no incluye USE, asi que importamos dentro de la DB creada.
  $code = Run-Proc -FilePath $mysqlExe -ArgumentList @('-h','127.0.0.1','-P', "$DbPort",'-u','root', $DbName) -RedirectStandardInput $sqlFile -Label 'mysql import install.sql'
  if ($code -ne 0) { throw "Import SQL fallÃƒÂ³ (exitcode=$code). Ver log." }
} else {
  Log "DB ya tiene $tc tablas -> no importo install.sql"
}

# -------------------------
# Migraciones: NO aplican en postinstall (este script es solo para instalaciÃƒÂ³n nueva)
# Los upgrades usan upgrade_db.ps1 directamente desde el instalador.
# -------------------------
Log "InstalaciÃƒÂ³n nueva -> sin migraciones (install.sql ya tiene el schema completo)."

# -------------------------
# Crear usuario DB (flus_user) + permisos
# -------------------------
Log "Creando usuario DB y permisos..."
$grantTarget = "$dbNameSqlIdent.*"
$createUserSql = @"
CREATE USER IF NOT EXISTS $dbUserSqlValue@'localhost' IDENTIFIED BY $dbPassSqlValue;
CREATE USER IF NOT EXISTS $dbUserSqlValue@'127.0.0.1' IDENTIFIED BY $dbPassSqlValue;
GRANT ALL PRIVILEGES ON $grantTarget TO $dbUserSqlValue@'localhost';
GRANT ALL PRIVILEGES ON $grantTarget TO $dbUserSqlValue@'127.0.0.1';
FLUSH PRIVILEGES;
"@
$usrOut = & $mysqlExe -h 127.0.0.1 -P $DbPort -u root -e $createUserSql 2>&1
foreach ($l in $usrOut) { Log "mysql user: $l" }
Throw-IfLastExitNotZero "Create user + grants"

# -------------------------
# config.php (desde config.example.php)
# -------------------------
$configExample = Join-Path $appDir "src\config.example.php"
$configReal    = Join-Path $appDir "src\config.php"
if (!(Test-Path $configExample)) { throw "No encuentro config.example.php en: $configExample" }

$shouldWriteConfig = -not $configExistsAtStart
if (Test-Path $configReal) {
  Log "config.php ya existe -> NO se pisa (seguro ante re-ejecuciÃƒÂ³n)."
} else {
  Log "Generando config.php..."
  Copy-Item $configExample $configReal -Force
}

if ($shouldWriteConfig) {
function Escape-PHPSingleQuoted([string]$s) {
  if ($null -eq $s) { return "" }
  $s = $s.Replace('\\', '\\\\')
  $s = $s.Replace("'", "\\'")
  return $s
}

$phpDbName = Escape-PHPSingleQuoted $DbName
$phpDbUser = Escape-PHPSingleQuoted $DbUser
$phpDbPass = Escape-PHPSingleQuoted $DbPass

$cfg = Get-Content $configReal -Raw

function Set-PhpDefine([ref]$cfgRef, [string]$constName, [string]$newLine) {
  $cfg = $cfgRef.Value
  $pat = '(?m)^\s*define\(\s*[\x27\x22]{0}[\x27\x22]\s*,\s*[^\r\n]*\)\s*;\s*$' -f [regex]::Escape($constName)
  if ($cfg -match $pat) {
    $cfg = [regex]::Replace($cfg, $pat, $newLine)
  } else {
    $insertPat = "(?m)^(date_default_timezone_set\([^\)]*\);\s*)$"
    if ($cfg -match $insertPat) {
      $cfg = [regex]::Replace($cfg, $insertPat, "`$1`r`n" + $newLine)
    } else {
      $cfg = $newLine + "`r`n" + $cfg
    }
  }
  $cfgRef.Value = $cfg
}

$tmp = [ref]$cfg
Set-PhpDefine $tmp "DB_HOST" "define('DB_HOST', '127.0.0.1');"
Set-PhpDefine $tmp "DB_PORT" ("define('DB_PORT', " + $DbPort + ");")
Set-PhpDefine $tmp "DB_NAME" ("define('DB_NAME', '" + $phpDbName + "');")
Set-PhpDefine $tmp "DB_USER" ("define('DB_USER', '" + $phpDbUser + "');")
Set-PhpDefine $tmp "DB_PASS" ("define('DB_PASS', '" + $phpDbPass + "');")
$cfg = $tmp.Value

Write-Utf8NoBom -path $configReal -text $cfg
Log "config.php actualizado: DB_HOST=127.0.0.1 DB_PORT=$DbPort DB_NAME=$DbName DB_USER=$DbUser"
}

# -------------------------
# APP_SECRET persistente
# -------------------------
$secretFile = Join-Path $appDir "storage\app_secret.key"
Ensure-Dir (Split-Path $secretFile -Parent)
Ensure-Dir (Join-Path $appDir "storage\certs")
if (!(Test-Path $secretFile)) {
  try {
    $bytes = New-Object byte[] 32
    [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $secret = -join ($bytes | ForEach-Object { $_.ToString("x2") })
  } catch {
    $secret = ([guid]::NewGuid().ToString("N") + [guid]::NewGuid().ToString("N"))
  }
  Set-Content -Path $secretFile -Value $secret -Encoding ASCII
  Log "APP_SECRET creado en: $secretFile"
} else {
  Log "APP_SECRET ya existe -> OK"
}
Log "Estructura fiscal preparada en: $(Join-Path $appDir 'storage\certs')"

# -------------------------
# Start Apache
# -------------------------
Log "Iniciando $svcWeb..."
Start-Service $svcWeb
Start-Sleep -Seconds 2

if (-not (Wait-ServiceRunning $svcWeb 45)) {
  $st = (Get-Service $svcWeb).Status
  throw "No pudo iniciar $svcWeb (estado: $st). Revisar stack\apache\logs\error.log"
}

# Firewall
try { netsh advfirewall firewall delete rule name="FLUS HTTP $Port" | Out-Null } catch {}
netsh advfirewall firewall add rule name="FLUS HTTP $Port" dir=in action=allow protocol=TCP localport=$Port | Out-Null

# -------------------------
# Acceso directo (.url) con icono
# -------------------------
try {
  $desktop = [Environment]::GetFolderPath("Desktop")
  $icon    = Join-Path $Root "app\public\favicon.ico"
  $rootIcon = Join-Path $Root "flus.ico"
  if (!(Test-Path $icon) -and (Test-Path $rootIcon)) { $icon = $rootIcon }
  $launchUrl = "http://localhost:$Port/"
  $content = "[InternetShortcut]`r`nURL=$launchUrl`r`nIconFile=$icon`r`nIconIndex=0`r`n"

  if (!(Test-Path $icon)) {
    Log "WARN: No encuentro icono en $icon (el acceso directo saldrÃƒÂ¡ sin logo)."
  }

  $rootUrlPath = Join-Path $Root "FLUS.url"
  Set-Content -Path $rootUrlPath -Value $content -Encoding ASCII

  $launchInfoPath = Join-Path $Root "flus_launch_url.txt"
  Set-Content -Path $launchInfoPath -Value $launchUrl -Encoding ASCII

  $oldLnk = Join-Path $desktop "FLUS.lnk"
  if (Test-Path $oldLnk) { Remove-Item $oldLnk -Force -ErrorAction SilentlyContinue }

  $urlPath = Join-Path $desktop "FLUS.url"
  Set-Content -Path $urlPath -Value $content -Encoding ASCII

  Log "Acceso directo creado en instalacion: $rootUrlPath"
  Log "Acceso directo creado en Escritorio: $urlPath"
} catch {
  Log "No pude crear acceso directo: $($_.Exception.Message)"
}

Log "OK -> App: http://localhost:$Port/"
Log "Log: $logFile"
