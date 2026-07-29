param(
  [Parameter(Mandatory=$true)]
  [ValidateSet("start","stop","status")]
  [string]$Action
)

$ErrorActionPreference = "Stop"

$preferred = @("FLUS_Apache","FLUS_MariaDB")
$fallback  = @("Apache2.4","MySQL","MariaDB")

function Resolve-ServiceNames {
  $found = @()
  foreach ($n in $preferred) {
    if (Get-Service -Name $n -ErrorAction SilentlyContinue) { $found += $n }
  }
  if ($found.Count -eq 0) {
    foreach ($n in $fallback) {
      if (Get-Service -Name $n -ErrorAction SilentlyContinue) { $found += $n }
    }
  }
  return $found
}

$services = Resolve-ServiceNames

Write-Host ""
Write-Host "========================================"
Write-Host " FLUS - Control de servicios"
Write-Host " Acción: $Action"
Write-Host "========================================"
Write-Host ""

if ($services.Count -eq 0) {
  Write-Host "No se encontraron servicios."
  Write-Host "Esperados: FLUS_Apache / FLUS_MariaDB"
  Write-Host ""
  Read-Host "Enter para cerrar"
  exit 2
}

try {
  if ($Action -eq "start") {
    foreach ($s in $services) {
      Write-Host "Iniciando: $s ..."
      Start-Service -Name $s -ErrorAction Stop
    }
  }
  elseif ($Action -eq "stop") {
    foreach ($s in $services) {
      Write-Host "Deteniendo: $s ..."
      Stop-Service -Name $s -Force -ErrorAction Stop
    }
  }

  Write-Host ""
  Write-Host "Estado actual:"
  Get-Service -Name $services | Select-Object Name, Status | Format-Table -AutoSize
  Write-Host ""
}
catch {
  Write-Host "ERROR: $($_.Exception.Message)"
  Write-Host ""
}

Read-Host "Enter para cerrar"
