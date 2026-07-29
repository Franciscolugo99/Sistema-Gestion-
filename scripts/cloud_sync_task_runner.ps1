[CmdletBinding()]
param(
  [string]$Root = ""
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

if ($Root -eq "") {
  $Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
}
$Root = [System.IO.Path]::GetFullPath($Root)

$phpCandidates = @(
  (Join-Path (Split-Path -Parent $Root) "stack\php\php.exe"),
  (Join-Path $Root "..\stack\php\php.exe"),
  "C:\FLUS\stack\php\php.exe"
)
$php = $phpCandidates | Where-Object { Test-Path -LiteralPath $_ } | Select-Object -First 1
$tick = Join-Path $Root "scripts\cloud_sync_tick.php"

if (-not $php -or -not (Test-Path -LiteralPath $tick)) {
  exit 2
}

try {
  & $php $tick 250 50 *> $null
  exit $LASTEXITCODE
} catch {
  exit 1
}
