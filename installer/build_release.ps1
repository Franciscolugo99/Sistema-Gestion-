param(
  [string]$SourceRoot = "",
  [string]$RuntimeRoot = $env:FLUS_RUNTIME_ROOT,
  [string]$OutputRoot = "",
  [string]$IsccPath = "",
  [switch]$SkipSmoke,
  [switch]$SkipCompile
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

function Log([string]$Message) {
  $stamp = Get-Date -Format "yyyy-MM-dd HH:mm:ss"
  Write-Host "[$stamp] $Message"
}

function Ensure-Dir([string]$Path) {
  if (-not (Test-Path $Path)) {
    New-Item -ItemType Directory -Path $Path | Out-Null
  }
}

function Resolve-PhpExe {
  $scriptRoot = if ($PSScriptRoot) { $PSScriptRoot } else { Split-Path -Parent $MyInvocation.MyCommand.Path }
  $candidates = @(
    (Join-Path $scriptRoot ".build\payload\stack\php\php.exe"),
    (Join-Path $scriptRoot ".build\payload\stack\php\windowsXamppPhp\php.exe"),
    "C:\xampp\php\php.exe",
    "C:\php\php.exe"
  )

  foreach ($candidate in $candidates) {
    if (Test-Path $candidate) {
      return $candidate
    }
  }

  $cmd = Get-Command php.exe -ErrorAction SilentlyContinue
  if ($cmd) {
    return $cmd.Source
  }

  throw "No encontre php.exe para correr smoke tests."
}

function Resolve-Iscc([string]$Preferred) {
  if ($Preferred -and (Test-Path $Preferred)) {
    return $Preferred
  }

  $candidates = @(
    "C:\Program Files (x86)\Inno Setup 6\ISCC.exe",
    "C:\Program Files\Inno Setup 6\ISCC.exe"
  )

  foreach ($candidate in $candidates) {
    if (Test-Path $candidate) {
      return $candidate
    }
  }

  $cmd = Get-Command ISCC.exe -ErrorAction SilentlyContinue
  if ($cmd) {
    return $cmd.Source
  }

  throw "No encontre ISCC.exe de Inno Setup."
}

function Resolve-SourceRoot([string]$Preferred) {
  if (-not $Preferred) {
    $Preferred = Split-Path -Parent $PSScriptRoot
  }

  if (-not (Test-Path $Preferred)) {
    throw "No existe SourceRoot: $Preferred"
  }

  $resolved = (Resolve-Path $Preferred).Path
  $versionFile = Join-Path $resolved "src\version.php"
  if (-not (Test-Path $versionFile)) {
    throw "SourceRoot no parece ser un repo FLUS valido: falta $versionFile"
  }

  return $resolved
}

function Get-VersionMeta([string]$VersionFile) {
  $raw = Get-Content -Path $VersionFile -Raw
  $versionMatch = [regex]::Match($raw, "FLUS_VERSION'\)\s*\|\|\s*define\('FLUS_VERSION',\s*'([^']+)'\)")
  $buildMatch = [regex]::Match($raw, "FLUS_BUILD'\)\s*\|\|\s*define\('FLUS_BUILD',\s*'([^']+)'\)")

  if (-not $versionMatch.Success) {
    throw "No pude leer FLUS_VERSION desde $VersionFile"
  }
  if (-not $buildMatch.Success) {
    throw "No pude leer FLUS_BUILD desde $VersionFile"
  }

  return @{
    Version = $versionMatch.Groups[1].Value
    Build = $buildMatch.Groups[1].Value
  }
}

function Get-NumericVersion([string]$Version) {
  $baseVersion = (($Version -split '-', 2)[0]).Trim()
  if (-not $baseVersion) {
    throw "No pude derivar version numerica desde $Version"
  }

  $parts = @($baseVersion -split '\.')
  $normalized = @()
  foreach ($part in $parts) {
    if ($part -match '^\d+$') {
      $normalized += $part
    }
  }

  while ($normalized.Count -lt 4) {
    $normalized += '0'
  }

  if ($normalized.Count -gt 4) {
    $normalized = $normalized[0..3]
  }

  return ($normalized -join '.')
}

function Sync-Dir([string]$Source, [string]$Destination) {
  Ensure-Dir $Destination
  $null = robocopy $Source $Destination /MIR /R:1 /W:1 /NFL /NDL /NJH /NJS /NP
  if ($LASTEXITCODE -gt 7) {
    throw "Robocopy fallo sincronizando $Source -> $Destination (exit=$LASTEXITCODE)"
  }
}

function Copy-FileSafe([string]$Source, [string]$Destination) {
  $parent = Split-Path -Parent $Destination
  if ($parent) {
    Ensure-Dir $parent
  }
  Copy-Item -Path $Source -Destination $Destination -Force
}

function Write-TextIfChanged([string]$Path, [string]$Text) {
  $current = if (Test-Path $Path) { Read-TextUtf8 $Path } else { $null }
  if ($current -ne $Text) {
    $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($Path, $Text, $utf8NoBom)
  }
}

function Resolve-RuntimeRoot([string]$Preferred) {
  $candidates = @()
  if ($Preferred) { $candidates += $Preferred }
  $candidates += @(
    "C:\Users\Francisco\Documents\Versiones de FLUS\FLUS_installer_V4.2.2\release\payload\stack",
    "C:\FLUS\stack"
  )

  foreach ($candidate in $candidates) {
    if (-not $candidate -or -not (Test-Path -LiteralPath $candidate)) { continue }
    $resolved = (Resolve-Path -LiteralPath $candidate).Path
    if ((Test-Path -LiteralPath (Join-Path $resolved "php\php.exe")) -and
        (Test-Path -LiteralPath (Join-Path $resolved "apache\bin\httpd.exe")) -and
        (Test-Path -LiteralPath (Join-Path $resolved "mysql\bin\mysqld.exe"))) {
      return $resolved
    }
  }

  throw "No se encontro un runtime portable FLUS completo. Usa -RuntimeRoot."
}

function Read-TextUtf8([string]$Path) {
  return [System.IO.File]::ReadAllText($Path, [System.Text.Encoding]::UTF8)
}

function Remove-FileSafe([string]$Path) {
  if (Test-Path $Path) {
    Remove-Item -Path $Path -Force
  }
}

function Reset-BuildWorkspace([string]$Workspace, [string]$InstallerRoot) {
  $workspaceFull = [System.IO.Path]::GetFullPath($Workspace)
  $installerFull = [System.IO.Path]::GetFullPath($InstallerRoot).TrimEnd('\') + '\'
  if (-not $workspaceFull.StartsWith($installerFull, [StringComparison]::OrdinalIgnoreCase)) {
    throw "Workspace de build fuera del directorio permitido."
  }
  if (Test-Path -LiteralPath $workspaceFull) {
    Remove-Item -LiteralPath $workspaceFull -Recurse -Force
  }
  Ensure-Dir $workspaceFull
}

function Copy-GitPayload([string]$SourceRoot, [string]$Destination) {
  $pathSpecs = @('public', 'scripts', 'src', 'migrations', 'tests', 'docs', '.htaccess', 'favicon.ico', 'README.md', 'CHANGELOG.md', 'install.sql')
  $files = @(& git -C $SourceRoot ls-files --cached --others --exclude-standard -- @pathSpecs)
  if ($LASTEXITCODE -ne 0 -or $files.Count -eq 0) {
    throw "No se pudo obtener el listado seguro de archivos desde Git."
  }

  foreach ($relative in $files) {
    $relative = ("" + $relative).Trim()
    if ($relative -eq '') { continue }
    $source = Join-Path $SourceRoot $relative
    if (-not (Test-Path -LiteralPath $source -PathType Leaf)) { continue }
    $destinationPath = Join-Path $Destination $relative
    Copy-FileSafe $source $destinationPath
  }
}

function Copy-InstallerSources([string]$SourceDir, [string]$Destination) {
  Ensure-Dir $Destination
  Ensure-Dir (Join-Path $Destination 'assets')
  foreach ($name in @(
    'flus_server_custom.iss', 'flus_terminal_custom.iss',
    'flus_services.ps1', 'postinstall_server.ps1', 'preupgrade.ps1', 'postupgrade.ps1',
    'upgrade_db.ps1', 'start_services.cmd', 'stop_services.cmd', 'status_services.cmd'
  )) {
    Copy-FileSafe (Join-Path $SourceDir $name) (Join-Path $Destination $name)
  }
  Copy-FileSafe (Join-Path $SourceDir 'assets\wizard-left.bmp') (Join-Path $Destination 'assets\wizard-left.bmp')
  Copy-FileSafe (Join-Path $SourceDir 'assets\wizard-small.bmp') (Join-Path $Destination 'assets\wizard-small.bmp')
}

function Assert-NoSensitivePayload([string]$PayloadApp) {
  $forbiddenNames = @(
    'config.php', 'config.local.php', 'config_arca.php', 'config_mp.php',
    'license.json', 'license_state.json', 'license_cloud_state.json', 'license_installation_id'
  )
  $forbidden = @()
  foreach ($file in Get-ChildItem -LiteralPath $PayloadApp -Recurse -File -Force) {
    $name = $file.Name.ToLowerInvariant()
    if ($forbiddenNames -contains $name -or
        $name -match '\.(bak|backup|old|key|pem|csr|pfx)$' -or
        $name -match '\.bak_' -or
        $name -eq '.env' -or $name.StartsWith('.env.')) {
      $forbidden += $file.FullName
      continue
    }

    if ($file.Length -le 2097152 -and $file.Extension -match '^\.(php|ps1|cmd|txt|md|json|ini)$') {
      $content = [System.IO.File]::ReadAllText($file.FullName)
      if ($content -match '-----BEGIN (?:RSA )?PRIVATE KEY-----') {
        $forbidden += $file.FullName
      }
    }
  }

  if ($forbidden.Count -gt 0) {
    $relative = $forbidden | ForEach-Object { $_.Substring($PayloadApp.Length).TrimStart('\') }
    throw "El payload contiene archivos sensibles prohibidos:`n$($relative -join "`n")"
  }
}

function Write-HashManifest([string]$Root, [string]$Destination) {
  $lines = foreach ($file in Get-ChildItem -LiteralPath $Root -Recurse -File | Sort-Object FullName) {
    $relative = $file.FullName.Substring($Root.Length).TrimStart('\').Replace('\', '/')
    $hash = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash
    "$hash  $relative"
  }
  [System.IO.File]::WriteAllLines($Destination, $lines, (New-Object System.Text.UTF8Encoding($false)))
}

function Patch-PortablePayload([string]$PayloadApp) {
  Log "Aplicando ajustes portables al payload..."

  $backupLib = Join-Path $PayloadApp "src\backup_lib.php"
  if (Test-Path $backupLib) {
    $text = Read-TextUtf8 $backupLib

    if ($text -notmatch 'function\s+flus_portable_root\s*\(') {
      $portableHelpers = @'
function flus_portable_root(): string {
    if (defined('FLUS_ROOT')) {
        return dirname((string) FLUS_ROOT);
    }

    return dirname(__DIR__, 2);
}

function flus_stack_candidate(string $relativePath): string {
    $relativePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    return flus_portable_root() . DIRECTORY_SEPARATOR . $relativePath;
}

'@
      $text = [regex]::Replace(
        $text,
        'return escapeshellarg\(\$s\);\r?\n}\r?\n',
        "return escapeshellarg(`$s);`r`n}`r`n`r`n$portableHelpers",
        1
      )
    }

    $text = [regex]::Replace(
      $text,
      "(?m)^\s*'C:\\\\xampp82\\\\mysql\\\\bin\\\\mysqldump\.exe',\r?\n",
      ""
    )
    $text = $text.Replace(
      "            'C:\\xampp\\mysql\\bin\\mysqldump.exe',",
      "            flus_stack_candidate('stack/mysql/bin/mysqldump.exe'),`r`n            flus_stack_candidate('stack/mariadb/bin/mysqldump.exe'),"
    )
    $text = [regex]::Replace(
      $text,
      "(?m)^\s*'C:\\\\xampp82\\\\mysql\\\\bin\\\\mysql\.exe',\r?\n",
      ""
    )
    $text = $text.Replace(
      "            'C:\\xampp\\mysql\\bin\\mysql.exe',",
      "            flus_stack_candidate('stack/mysql/bin/mysql.exe'),`r`n            flus_stack_candidate('stack/mariadb/bin/mysql.exe'),"
    )
    $text = $text.Replace("sin depender de C:\xampp", "sin depender de una ruta fija de XAMPP")
    $text = $text.Replace("array_unshift(`$candidates, `$output[0]);", "`$candidates[] = `$output[0];")

    Write-TextIfChanged $backupLib $text
  }

  $tecnico = Join-Path $PayloadApp "public\tecnico.php"
  if (Test-Path $tecnico) {
    $text = Read-TextUtf8 $tecnico
    $old = @'
    $candidates = array_values(array_unique(array_filter([
        'C:/xampp/php/php.exe',
        dirname($phpBinary) . '/php.exe',
        $phpBinary,
    ])));
'@
    $new = @'
    $portableRoot = defined('FLUS_ROOT') ? dirname((string) FLUS_ROOT) : dirname(__DIR__, 2);
    $candidates = array_values(array_unique(array_filter([
        $portableRoot . '/stack/php/php.exe',
        $portableRoot . '/stack/php/windowsXamppPhp/php.exe',
        dirname($phpBinary) . '/php.exe',
        $phpBinary,
    ])));
'@
    $text = $text.Replace($old, $new)

    $portableFunction = @'
function tecnico_detect_php_binary(): ?string
{
    $phpBinary = defined('PHP_BINARY') ? (string) PHP_BINARY : '';
    $portableRoot = defined('FLUS_ROOT') ? dirname((string) FLUS_ROOT) : dirname(__DIR__, 2);

    $candidates = [];
    $candidates[] = $portableRoot . '/stack/php/php.exe';
    $candidates[] = $portableRoot . '/stack/php/windowsXamppPhp/php.exe';
    if ($phpBinary !== '') {
        $candidates[] = $phpBinary;
        $candidates[] = dirname($phpBinary) . '/php.exe';
    }

    $candidates = array_values(array_unique(array_filter($candidates)));

    foreach ($candidates as $candidate) {
        if (is_string($candidate) && tecnico_is_php_cli_binary($candidate)) {
            return $candidate;
        }
    }

    return null;
}
'@
    $text = [regex]::Replace(
      $text,
      'function tecnico_detect_php_binary\(\): \?string\s*\{.*?\r?\n\}',
      [System.Text.RegularExpressions.MatchEvaluator]{ param($m) $portableFunction },
      [System.Text.RegularExpressions.RegexOptions]::Singleline
    )
    Write-TextIfChanged $tecnico $text
  }

  $testBackup = Join-Path $PayloadApp "public\test_backup.php"
  if (Test-Path $testBackup) {
    $text = Read-TextUtf8 $testBackup
    $old = @'
$isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;
if ($isWindows) {
    $paths = [
        'C:\\xampp\\mysql\\bin\\mysqldump.exe',
        'C:\\wamp64\\bin\\mysql\\mysql8.0.31\\bin\\mysqldump.exe',
    ];
} else {
'@
    $new = @'
$isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;
$portableRoot = defined('FLUS_ROOT') ? dirname((string) FLUS_ROOT) : dirname(__DIR__, 2);
if ($isWindows) {
    $paths = [
        $portableRoot . DIRECTORY_SEPARATOR . 'stack' . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe',
        $portableRoot . DIRECTORY_SEPARATOR . 'stack' . DIRECTORY_SEPARATOR . 'mariadb' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
    ];
} else {
'@
    $text = $text.Replace($old, $new)

    $portablePathsBlock = @'
$isWindows = stripos(PHP_OS_FAMILY, 'Windows') === 0;
$portableRoot = defined('FLUS_ROOT') ? dirname((string) FLUS_ROOT) : dirname(__DIR__, 2);
if ($isWindows) {
    $paths = [
        $portableRoot . DIRECTORY_SEPARATOR . 'stack' . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe',
        $portableRoot . DIRECTORY_SEPARATOR . 'stack' . DIRECTORY_SEPARATOR . 'mariadb' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
    ];
} else {
'@
    $text = [regex]::Replace(
      $text,
      '(?s)\$isWindows = stripos\(PHP_OS_FAMILY, ''Windows''\) === 0;\r?\nif \(\$isWindows\) \{\r?\n\s*\$paths = \[.*?\r?\n\s*\];\r?\n\} else \{',
      [System.Text.RegularExpressions.MatchEvaluator]{ param($m) $portablePathsBlock.TrimEnd() }
    )
    $oldSuggestion = @'
        echo '<div class="alert alert-error">
            <strong>Ã¢Å¡Â Ã¯Â¸Â mysqldump no encontrado</strong>
            <p>AgregÃƒÂ¡ esta lÃƒÂ­nea a tu <code>src/config.php</code>:</p>
            <pre>define(\'MYSQLDUMP_BIN\', \'C:\\xampp\\mysql\\bin\\mysqldump.exe\');</pre>
        </div>';
'@
    $newSuggestion = @'
        $suggested = $paths[0] ?? '';
        $suggestedDefine = "define('MYSQLDUMP_BIN', '" . str_replace('\\', '\\\\', $suggested) . "');";
        echo '<div class="alert alert-error">
            <strong>Ã¢Å¡Â Ã¯Â¸Â mysqldump no encontrado</strong>
            <p>AgregÃƒÂ¡ esta lÃƒÂ­nea a tu <code>src/config.php</code>:</p>
            <pre>' . htmlspecialchars($suggestedDefine, ENT_QUOTES, 'UTF-8') . '</pre>
        </div>';
'@
    $text = $text.Replace($oldSuggestion, $newSuggestion)
    if ($text.Contains("C:\\xampp\\mysql\\bin\\mysqldump.exe")) {
      if ($text -notmatch '\$suggestedDefine') {
        $echoPattern = '(?m)^\s*echo ''<div class="alert alert-error">'
        $echoReplacement = @'
        $suggested = $paths[0] ?? '';
        $suggestedDefine = "define('MYSQLDUMP_BIN', '" . str_replace('\\', '\\\\', $suggested) . "');";
        echo '<div class="alert alert-error">
'@
        $echoRegex = New-Object System.Text.RegularExpressions.Regex($echoPattern)
        $text = $echoRegex.Replace($text, $echoReplacement.TrimEnd(), 1)
      }
      $preReplacement = '            <pre>'' . htmlspecialchars($suggestedDefine, ENT_QUOTES, ''UTF-8'') . ''</pre>'
      $lines = $text -split "\r?\n"
      for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i].Contains("C:\\xampp\\mysql\\bin\\mysqldump.exe")) {
          $lines[$i] = $preReplacement
        }
      }
      $text = $lines -join "`r`n"
    }
    Write-TextIfChanged $testBackup $text
  }

  $compras = Join-Path $PayloadApp "public\compras.php"
  if (Test-Path $compras) {
    $text = Read-TextUtf8 $compras
    $text = $text.Replace(
      ". 'C:\xampp\php\php.exe scripts\migrate.php '",
      ". 'stack\php\php.exe scripts\migrate.php '"
    )
    Write-TextIfChanged $compras $text
  }

  $backupScript = Join-Path $PayloadApp "scripts\backup_db.php"
  if (Test-Path $backupScript) {
    $text = Read-TextUtf8 $backupScript
    $text = $text.Replace(
      "//   C:\xampp\php\php.exe C:\xampp\htdocs\kiosco\scripts\backup_db.php",
      "//   C:\FLUS\stack\php\php.exe C:\FLUS\app\scripts\backup_db.php"
    )
    Write-TextIfChanged $backupScript $text
  }

  $configExample = Join-Path $PayloadApp "src\config.example.php"
  if (Test-Path $configExample) {
    $text = Read-TextUtf8 $configExample
    $text = [regex]::Replace($text, "define\('DB_NAME',\s*'[^']+'\);", "define('DB_NAME', 'flus_db');")
    Write-TextIfChanged $configExample $text
  }
}

function Assert-NoRuntimeXamppReferences([string]$AppRoot) {
  $scanRoots = @("public", "src", "scripts") | ForEach-Object { Join-Path $AppRoot $_ } | Where-Object { Test-Path $_ }
  $hits = @()

  foreach ($root in $scanRoots) {
    $hits += Get-ChildItem -Path $root -Recurse -File -Force -Include *.php |
      Select-String -SimpleMatch -Pattern "C:\xampp", "C:/xampp" |
      ForEach-Object { "{0}:{1}: {2}" -f $_.Path, $_.LineNumber, $_.Line.Trim() }
  }

  if ($hits.Count -gt 0) {
    $preview = ($hits | Select-Object -First 20) -join "`n"
    throw "Se detectaron referencias runtime a XAMPP en el payload:`n$preview`nCorregi el SourceRoot o el payload antes de compilar."
  }
}

function Reset-PortableState([string]$PayloadApp) {
  $storageRoot = Join-Path $PayloadApp "storage"
  $tmpRoot = Join-Path $PayloadApp "tmp"

  if (Test-Path $storageRoot) {
    Get-ChildItem -Path $storageRoot -Force | Remove-Item -Recurse -Force
  } else {
    Ensure-Dir $storageRoot
  }

  if (Test-Path $tmpRoot) {
    Get-ChildItem -Path $tmpRoot -Force | Remove-Item -Recurse -Force
  } else {
    Ensure-Dir $tmpRoot
  }

  Ensure-Dir (Join-Path $storageRoot "backups")
  Ensure-Dir (Join-Path $storageRoot "logs")
  Ensure-Dir (Join-Path $storageRoot "uploads")
  Ensure-Dir (Join-Path $storageRoot "cache")
  Ensure-Dir $tmpRoot
}

function Update-IssVersion([string]$IssPath, [string]$Version, [string]$NumericVersion) {
  $raw = Get-Content -Path $IssPath -Raw
  $updated = [regex]::Replace($raw, '(?m)^#define MyAppVersion "[^"]+"$', ('#define MyAppVersion "{0}"' -f $Version))
  $updated = [regex]::Replace($updated, '(?m)^#define MyAppVersionNumeric "[^"]+"$', ('#define MyAppVersionNumeric "{0}"' -f $NumericVersion))
  $utf8NoBom = New-Object System.Text.UTF8Encoding($false)
  [System.IO.File]::WriteAllText($IssPath, $updated, $utf8NoBom)
}

$InstallerRoot = Split-Path -Parent $MyInvocation.MyCommand.Path
$WorkRoot = Join-Path $InstallerRoot '.build'
$PayloadRoot = Join-Path $WorkRoot 'payload'
$PayloadApp = Join-Path $PayloadRoot 'app'
$PayloadDb = Join-Path $PayloadRoot 'db'
$InstallerDir = Join-Path $WorkRoot 'installer'

$SourceRoot = Resolve-SourceRoot $SourceRoot
$RuntimeRoot = Resolve-RuntimeRoot $RuntimeRoot
$versionMeta = Get-VersionMeta (Join-Path $SourceRoot 'src\version.php')
$version = $versionMeta.Version
$build = $versionMeta.Build
$numericVersion = Get-NumericVersion $version

if (-not $OutputRoot) {
  $OutputRoot = Join-Path $env:USERPROFILE ("Documents\Versiones de FLUS\FLUS_installer_V{0}" -f $version)
}
$OutputRoot = [System.IO.Path]::GetFullPath($OutputRoot)

Log "Version detectada: $version (build $build)"
Log "Fuente: $SourceRoot"
Log "Runtime portable: $RuntimeRoot"
Log "Salida: $OutputRoot"

$sourcePhp = Resolve-PhpExe
if (-not $SkipSmoke) {
  Log 'Corriendo smoke tests sobre la fuente...'
  Push-Location $SourceRoot
  try {
    & $sourcePhp 'tests\smoke.php'
    if ($LASTEXITCODE -ne 0) { throw "Smoke tests fallaron (exit=$LASTEXITCODE)" }
  } finally {
    Pop-Location
  }
}

Log 'Creando staging reproducible y limpio...'
Reset-BuildWorkspace $WorkRoot $InstallerRoot
Ensure-Dir $PayloadApp
Ensure-Dir $PayloadDb
Copy-InstallerSources $InstallerRoot $InstallerDir
Copy-GitPayload $SourceRoot $PayloadApp
Sync-Dir $RuntimeRoot (Join-Path $PayloadRoot 'stack')

Reset-PortableState $PayloadApp
foreach ($relative in @('storage\.htaccess', 'storage\license.example.json', 'storage\backups\.htaccess')) {
  $source = Join-Path $SourceRoot $relative
  if (Test-Path -LiteralPath $source -PathType Leaf) {
    Copy-FileSafe $source (Join-Path $PayloadApp $relative)
  }
}

foreach ($relative in @(
  'src\config.php', 'src\config.local.php', 'src\config_arca.php', 'src\config_mp.php',
  'src\license_cloud_mock.php', 'public\license_cloud_mock.php', 'public\api\license_cloud_mock.php',
  'storage\license.json', 'storage\license_state.json', 'storage\license_cloud_state.json',
  'storage\license_installation_id'
)) {
  Remove-FileSafe (Join-Path $PayloadApp $relative)
}

Patch-PortablePayload $PayloadApp
Assert-NoRuntimeXamppReferences $PayloadApp
Assert-NoSensitivePayload $PayloadApp

Copy-FileSafe (Join-Path $PayloadApp 'install.sql') (Join-Path $PayloadDb 'install.sql')
Sync-Dir (Join-Path $PayloadApp 'migrations') (Join-Path $PayloadDb 'migrations')
Remove-FileSafe (Join-Path $PayloadDb 'seed_permissions.sql')
Remove-FileSafe (Join-Path $PayloadDb 'migrations\manifest.txt')

$portablePhp = Join-Path $PayloadRoot 'stack\php\php.exe'
if (-not (Test-Path -LiteralPath $portablePhp)) {
  throw 'El runtime staged no contiene php.exe.'
}
if (-not $SkipSmoke) {
  Log 'Corriendo smoke tests sobre el payload portable...'
  Push-Location $PayloadApp
  try {
    & $portablePhp -n 'tests\smoke.php'
    if ($LASTEXITCODE -ne 0) { throw "Smoke del payload fallo (exit=$LASTEXITCODE)" }
  } finally {
    Pop-Location
  }
} else {
  Log 'Smoke del payload omitido por parametro explicito.'
}

$serverIss = Join-Path $InstallerDir 'flus_server_custom.iss'
$terminalIss = Join-Path $InstallerDir 'flus_terminal_custom.iss'
Update-IssVersion $serverIss $version $numericVersion
Update-IssVersion $terminalIss $version $numericVersion

if (-not $SkipCompile) {
  $isccExe = Resolve-Iscc $IsccPath
  Log 'Compilando instalador de servidor...'
  & $isccExe $serverIss
  if ($LASTEXITCODE -ne 0) { throw "Fallo compilacion del servidor (exit=$LASTEXITCODE)" }

  Log 'Compilando instalador de terminal...'
  & $isccExe $terminalIss
  if ($LASTEXITCODE -ne 0) { throw "Fallo compilacion del terminal (exit=$LASTEXITCODE)" }
}

Ensure-Dir $OutputRoot
$sourceManifest = Join-Path $OutputRoot 'SOURCE_SHA256SUMS.txt'
$runtimeManifest = Join-Path $OutputRoot 'RUNTIME_SHA256SUMS.txt'
Write-HashManifest $PayloadApp $sourceManifest
Write-HashManifest (Join-Path $PayloadRoot 'stack') $runtimeManifest

$branch = (& git -C $SourceRoot branch --show-current).Trim()
$commit = (& git -C $SourceRoot rev-parse HEAD).Trim()
$dirty = @(& git -C $SourceRoot status --porcelain).Count -gt 0
$buildInfo = @(
  "FLUS_VERSION=$version",
  "FLUS_BUILD=$build",
  "SOURCE_BRANCH=$branch",
  "SOURCE_COMMIT=$commit",
  "SOURCE_DIRTY=$($dirty.ToString().ToLowerInvariant())",
  "BUILT_AT=$((Get-Date).ToString('o'))"
) -join "`r`n"
[System.IO.File]::WriteAllText((Join-Path $OutputRoot 'BUILD_INFO.txt'), $buildInfo + "`r`n", (New-Object System.Text.UTF8Encoding($false)))

$readme = @"
FLUS $version - instaladores

- Servidor: instala o actualiza FLUS preservando base, licencia, storage, ARCA y Mercado Pago.
- Terminal: instala el acceso cliente a un servidor FLUS existente.
- Todo upgrade de servidor exige y verifica un backup previo antes de copiar archivos.
- Cloud queda automatizado mediante una tarea local sin secretos en argumentos.

Antes de actualizar una PC productiva, validar el hash SHA256 y realizar primero el piloto indicado en docs/RELEASE_$($version.Replace('.', '_')).md.
"@
[System.IO.File]::WriteAllText((Join-Path $OutputRoot "README_INSTALADOR_$version.txt"), $readme, (New-Object System.Text.UTF8Encoding($false)))

if (-not $SkipCompile) {
  $compiledRoot = Join-Path $InstallerDir 'output'
  foreach ($name in @("FLUS_Server_Setup_$version.exe", "FLUS_Terminal_Setup_$version.exe")) {
    $compiled = Join-Path $compiledRoot $name
    if (-not (Test-Path -LiteralPath $compiled)) { throw "No se genero $name" }
    Copy-FileSafe $compiled (Join-Path $OutputRoot $name)
  }
}

$hashManifest = Join-Path $OutputRoot 'SHA256SUMS.txt'
Remove-FileSafe $hashManifest
Write-HashManifest $OutputRoot $hashManifest

Log 'Build finalizado.'
Log "Paquete: $OutputRoot"
