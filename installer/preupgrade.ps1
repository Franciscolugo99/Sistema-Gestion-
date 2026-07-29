[CmdletBinding()]
param(
  [Parameter(Mandatory = $true)][string]$Root,
  [ValidatePattern('^[A-Za-z0-9_.-]+$')][string]$ApacheServiceName = 'FLUS_Apache'
)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$Root = [System.IO.Path]::GetFullPath($Root)
$appRoot = Join-Path $Root 'app'
$configPath = Join-Path $appRoot 'src\config.php'
$backupBase = Join-Path $Root 'upgrade_backups'
$stamp = Get-Date -Format 'yyyyMMdd_HHmmss'
$backupRoot = Join-Path $backupBase ('FLUS_pre_4.2.8_' + $stamp)
$apacheWasRunning = $false

function Ensure-Directory([string]$Path) {
  if (-not (Test-Path -LiteralPath $Path)) {
    New-Item -ItemType Directory -Force -Path $Path | Out-Null
  }
}

function Read-PhpDefine([string]$Content, [string]$Name, [string]$Default = '') {
  $pattern = "(?m)^\s*define\(\s*'$([regex]::Escape($Name))'\s*,\s*'([^']*)'\s*\);"
  $match = [regex]::Match($Content, $pattern)
  if ($match.Success) { return $match.Groups[1].Value }
  return $Default
}

function Read-PhpConstant([string]$Path, [string]$Content, [string]$Name, [string]$Default = '') {
  $literal = Read-PhpDefine $Content $Name ''
  if ($literal -ne '') { return $literal }

  $phpExe = Join-Path $Root 'stack\php\php.exe'
  if (Test-Path -LiteralPath $phpExe) {
    try {
      $probe = '$path=$argv[1]; $name=$argv[2]; require $path; if (!defined($name)) { exit(2); } $value=constant($name); if (!is_scalar($value)) { exit(3); } echo (string)$value;'
      $value = (& $phpExe -r $probe $Path $Name 2>$null | Out-String).Trim().TrimStart([char]0xFEFF)
      if ($LASTEXITCODE -eq 0 -and $value -notmatch '[\r\n]') { return $value }
    } catch {}
  }

  return $Default
}

function Copy-Tree([string]$Source, [string]$Destination, [string[]]$ExtraArgs = @()) {
  if (-not (Test-Path -LiteralPath $Source)) { return }
  Ensure-Directory $Destination
  $arguments = @($Source, $Destination, '/E', '/COPY:DAT', '/DCOPY:DAT', '/R:1', '/W:1', '/XJ', '/NFL', '/NDL', '/NJH', '/NJS', '/NP') + $ExtraArgs
  & robocopy.exe @arguments | Out-Null
  if ($LASTEXITCODE -gt 7) {
    throw 'No se pudo completar una copia de resguardo.'
  }
}

function Write-MySqlDefaults([string]$Path, [string]$ServerHost, [int]$Port, [string]$User, [string]$Password) {
  $escapedPassword = $Password.Replace('\', '\\').Replace('"', '\"')
  $text = "[client]`r`nhost=$ServerHost`r`nport=$Port`r`nuser=$User`r`npassword=`"$escapedPassword`"`r`n"
  [System.IO.File]::WriteAllText($Path, $text, (New-Object System.Text.UTF8Encoding($false)))
}

if (-not (Test-Path -LiteralPath $configPath)) {
  throw 'No se detecto una instalacion FLUS actualizable.'
}
if (-not $backupRoot.StartsWith($backupBase, [StringComparison]::OrdinalIgnoreCase)) {
  throw 'La ruta de backup calculada no es segura.'
}

$configContent = [System.IO.File]::ReadAllText($configPath)
$dbHost = Read-PhpConstant $configPath $configContent 'DB_HOST' '127.0.0.1'
$dbName = Read-PhpConstant $configPath $configContent 'DB_NAME' ''
$dbPortText = Read-PhpConstant $configPath $configContent 'DB_PORT' '3307'
$dbPort = 3307
[void][int]::TryParse($dbPortText, [ref]$dbPort)
$dbUser = Read-PhpConstant $configPath $configContent 'DB_USER' 'root'
$dbPass = Read-PhpConstant $configPath $configContent 'DB_PASS' ''
if ($dbHost -notmatch '^[A-Za-z0-9.:-]+$') {
  throw 'El host de base configurado no es seguro.'
}
if ($dbName -notmatch '^[A-Za-z0-9_]+$') {
  throw 'No se pudo determinar de forma segura el nombre de la base configurada.'
}
if ($dbUser -notmatch '^[A-Za-z0-9_.@-]+$' -or $dbPass -match '[\r\n]') {
  throw 'Las credenciales de base contienen caracteres no admitidos.'
}
if ($dbPort -lt 1 -or $dbPort -gt 65535) {
  throw 'El puerto de base configurado no es valido.'
}

$mysqldump = Join-Path $Root 'stack\mysql\bin\mysqldump.exe'
if (-not (Test-Path -LiteralPath $mysqldump)) {
  throw 'No se encontro la herramienta de backup de base de datos.'
}

try {
  $apache = Get-Service -Name $ApacheServiceName -ErrorAction SilentlyContinue
  $apacheWasRunning = $null -ne $apache -and $apache.Status -eq 'Running'
  if ($apacheWasRunning) {
    Stop-Service -Name $ApacheServiceName -Force -ErrorAction Stop
    $apache.WaitForStatus('Stopped', [TimeSpan]::FromSeconds(20))
  }

  Ensure-Directory $backupRoot
  Ensure-Directory (Join-Path $backupRoot 'config')

  foreach ($name in @('config.php', 'config.local.php', 'config_arca.php', 'config_mp.php')) {
    $source = Join-Path $appRoot ('src\' + $name)
    if (Test-Path -LiteralPath $source) {
      Copy-Item -LiteralPath $source -Destination (Join-Path $backupRoot ('config\' + $name)) -Force
    }
  }

  Copy-Tree (Join-Path $appRoot 'storage') (Join-Path $backupRoot 'storage')
  Copy-Tree $appRoot (Join-Path $backupRoot 'app_code') @('/XD', (Join-Path $appRoot 'storage'))
  Copy-Tree (Join-Path $Root 'db') (Join-Path $backupRoot 'db_files') @('/XD', (Join-Path $Root 'db\backups'))

  $defaultsFile = Join-Path $env:TEMP ('flus_mysql_' + [guid]::NewGuid().ToString('N') + '.cnf')
  $dumpPath = Join-Path $backupRoot ($dbName + '.sql')
  $dumpError = Join-Path $env:TEMP ('flus_dump_' + [guid]::NewGuid().ToString('N') + '.err')
  try {
    Write-MySqlDefaults $defaultsFile $dbHost $dbPort $dbUser $dbPass
    $arguments = @(
      ('--defaults-extra-file="' + $defaultsFile + '"'),
      '--single-transaction', '--routines', '--triggers', '--events', '--hex-blob',
      '--default-character-set=utf8mb4', $dbName
    )
    $process = Start-Process -FilePath $mysqldump -ArgumentList $arguments -NoNewWindow -Wait -PassThru -RedirectStandardOutput $dumpPath -RedirectStandardError $dumpError
    if ($process.ExitCode -ne 0 -or -not (Test-Path -LiteralPath $dumpPath) -or (Get-Item -LiteralPath $dumpPath).Length -lt 128) {
      throw 'No se pudo generar el backup de base de datos.'
    }
  } finally {
    Remove-Item -LiteralPath $defaultsFile -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $dumpError -Force -ErrorAction SilentlyContinue
  }

  if ($apacheWasRunning) {
    New-Item -ItemType File -Force -Path (Join-Path $backupRoot 'apache_was_running.flag') | Out-Null
  }

  $manifest = [ordered]@{
    release = '4.2.8'
    created_at = (Get-Date).ToString('o')
    database = $dbName
    database_dump = [System.IO.Path]::GetFileName($dumpPath)
    apache_was_running = $apacheWasRunning
    config_files = @(Get-ChildItem -LiteralPath (Join-Path $backupRoot 'config') -File | Select-Object -ExpandProperty Name)
  }
  $manifest | ConvertTo-Json -Depth 3 | Set-Content -LiteralPath (Join-Path $backupRoot 'manifest.json') -Encoding UTF8
  Ensure-Directory $backupBase
  [System.IO.File]::WriteAllText((Join-Path $backupBase 'last_upgrade_backup.txt'), $backupRoot, (New-Object System.Text.UTF8Encoding($false)))
  Write-Host 'Backup previo verificado.'
  exit 0
} catch {
  if ($apacheWasRunning) {
    Start-Service -Name $ApacheServiceName -ErrorAction SilentlyContinue
  }
  throw
}
