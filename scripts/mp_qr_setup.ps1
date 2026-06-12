[CmdletBinding()]
param(
  [string]$StoreExternalId = "",
  [string]$PosExternalId = "",
  [string]$StoreName = "Sucursal FLUS Prueba",
  [string]$PosName = "Caja FLUS Prueba 1",
  [string]$StreetNumber = "",
  [string]$StreetName = "",
  [string]$CityName = "",
  [string]$StateName = "",
  [double]$Latitude = [double]::NaN,
  [double]$Longitude = [double]::NaN,
  [int]$Category = 0,
  [string]$CollectorUserId = "",
  [string]$AccessToken = "",
  [switch]$DryRun,
  [switch]$NonInteractive
)

$ErrorActionPreference = "Stop"
$ProgressPreference = "SilentlyContinue"

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$configPath = Join-Path $root "src\config_mp.php"
$diagnosticPath = Join-Path (Split-Path -Parent $MyInvocation.MyCommand.Path) "mp_qr_ultimo_error.json"
$timestamp = Get-Date -Format "yyyyMMddHHmmss"

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

function Read-Coordinate([string]$Label, [double]$Current) {
  $culture = [System.Globalization.CultureInfo]::InvariantCulture
  while ($true) {
    $default = if ([double]::IsNaN($Current)) { "" } else { $Current.ToString($culture) }
    $raw = Read-Required -Label $Label -Current $default
    $parsed = 0.0
    if ([double]::TryParse($raw.Replace(",", "."), [System.Globalization.NumberStyles]::Float, $culture, [ref]$parsed)) {
      return $parsed
    }
    Write-Host "Usa coordenadas decimales, por ejemplo -34.6037." -ForegroundColor Yellow
  }
}

function Normalize-ExternalId([string]$Value, [int]$MaxLength) {
  $normalized = ([string]$Value).ToUpperInvariant() -replace '[^A-Z0-9]', ''
  if ([string]::IsNullOrWhiteSpace($normalized)) {
    throw "El identificador quedo vacio despues de normalizarlo."
  }
  if ($normalized.Length -gt $MaxLength) {
    $normalized = $normalized.Substring(0, $MaxLength)
  }
  return $normalized
}

function ConvertTo-MpPlainText([string]$Value) {
  $normalized = $Value.Normalize([Text.NormalizationForm]::FormD)
  $builder = New-Object Text.StringBuilder
  foreach ($character in $normalized.ToCharArray()) {
    $category = [Globalization.CharUnicodeInfo]::GetUnicodeCategory($character)
    if ($category -ne [Globalization.UnicodeCategory]::NonSpacingMark) {
      [void]$builder.Append($character)
    }
  }
  return ($builder.ToString().Normalize([Text.NormalizationForm]::FormC) -replace '\s+', ' ').Trim()
}

function Assert-MpTextField([string]$Label, [string]$Value) {
  if ($Value -notmatch '^[A-Za-z ]+$') {
    throw "$Label debe contener solamente letras y espacios. Ejemplo: Canaan o Guaymallen."
  }
}

function Read-SecureToken {
  Write-Host "El token no se mostrara mientras escribes." -ForegroundColor DarkGray
  Write-Host "Copialo desde Credenciales > Prueba. Actualmente puede comenzar con APP_USR-." -ForegroundColor DarkGray
  $secureToken = Read-Host "Access Token de prueba" -AsSecureString
  $bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
  try {
    return [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
  } finally {
    if ($bstr -ne [IntPtr]::Zero) {
      [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
    }
  }
}

function Get-MpErrorDetail($ErrorRecord) {
  $raw = ""
  try {
    if ($ErrorRecord.ErrorDetails -and $ErrorRecord.ErrorDetails.Message) {
      $raw = [string]$ErrorRecord.ErrorDetails.Message
    }
    if ($raw -eq "" -and $ErrorRecord.Exception.Response) {
      $stream = $ErrorRecord.Exception.Response.GetResponseStream()
      if ($stream) {
        $reader = New-Object System.IO.StreamReader($stream)
        $raw = $reader.ReadToEnd()
      }
    }
  } catch {}

  if ($raw -ne "") {
    try {
      $body = $raw | ConvertFrom-Json
      $parts = @($body.message, $body.error, $body.status) | Where-Object { $_ }
      if ($body.cause) {
        foreach ($cause in @($body.cause)) {
          $causeParts = @($cause.code, $cause.description, $cause.message) | Where-Object { $_ }
          if ($causeParts.Count -gt 0) {
            $parts += ($causeParts -join ": ")
          } else {
            $parts += ($cause | ConvertTo-Json -Depth 8 -Compress)
          }
        }
      }
      if ($parts.Count -gt 0) {
        return ($parts -join " | ")
      }
    } catch {}
    return $raw
  }

  return [string]$ErrorRecord.Exception.Message
}

function Invoke-MpJson([string]$Method, [string]$Uri, [object]$Body = $null) {
  $bodyJson = $null
  $args = @{
    Method = $Method
    Uri = $Uri
    Headers = @{
      Authorization = "Bearer $AccessToken"
      Accept = "application/json"
    }
    ContentType = "application/json"
    TimeoutSec = 30
  }
  if ($null -ne $Body) {
    $bodyJson = $Body | ConvertTo-Json -Depth 12
    $args.Body = $bodyJson
  }

  try {
    return Invoke-RestMethod @args
  } catch {
    $detail = Get-MpErrorDetail $_
    $diagnostic = [ordered]@{
      date = (Get-Date).ToString("o")
      method = $Method
      uri = $Uri
      request = $Body
      response = $detail
    }
    $diagnostic | ConvertTo-Json -Depth 12 | Set-Content -LiteralPath $diagnosticPath -Encoding UTF8
    Write-Host "Diagnostico guardado en: $diagnosticPath" -ForegroundColor Yellow
    throw "Mercado Pago rechazo la solicitud: $detail"
  }
}

function ConvertTo-PhpString([string]$Value) {
  return "'" + $Value.Replace("\", "\\").Replace("'", "\'") + "'"
}

function Write-FlusConfig($Pos) {
  $qrImage = [string]$Pos.qr.image
  $qrTemplateDocument = [string]$Pos.qr.template_document
  $qrTemplateImage = [string]$Pos.qr.template_image

  $lines = @(
    "<?php",
    "// src/config_mp.php",
    "// Generado por scripts/mp_qr_setup.ps1. No versionar este archivo.",
    "declare(strict_types=1);",
    "",
    "define('FLUS_MP_ACCESS_TOKEN', $(ConvertTo-PhpString $AccessToken));",
    "define('FLUS_MP_CASHIER_MODE', 'automatic');",
    "define('FLUS_MP_MANUAL_FALLBACK', true);",
    "define('FLUS_MP_QR_EXTERNAL_POS_ID', $(ConvertTo-PhpString $PosExternalId));",
    "define('FLUS_MP_QR_MODE', 'hybrid');",
    "define('FLUS_MP_QR_DESCRIPTION', 'Prueba FLUS QR');",
    "define('FLUS_MP_QR_IMAGE_URL', $(ConvertTo-PhpString $qrImage));",
    "define('FLUS_MP_QR_TEMPLATE_DOCUMENT_URL', $(ConvertTo-PhpString $qrTemplateDocument));",
    "define('FLUS_MP_QR_TEMPLATE_IMAGE_URL', $(ConvertTo-PhpString $qrTemplateImage));",
    "define('FLUS_MP_POINT_TERMINAL_ID', '');",
    ""
  )

  if (Test-Path -LiteralPath $configPath) {
    $backupPath = $configPath + ".bak." + (Get-Date -Format "yyyyMMdd_HHmmss")
    Copy-Item -LiteralPath $configPath -Destination $backupPath -Force
    Write-Host "Respaldo creado: $backupPath" -ForegroundColor DarkGray
  }

  [System.IO.File]::WriteAllLines(
    $configPath,
    $lines,
    (New-Object System.Text.UTF8Encoding($false))
  )
}

if ($DryRun) {
  $NonInteractive = $true
}

if (-not $NonInteractive) {
  Clear-Host
}
Write-Host "====================================================" -ForegroundColor Cyan
Write-Host " FLUS | Asistente Mercado Pago QR de prueba" -ForegroundColor White
Write-Host "====================================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Este asistente:" -ForegroundColor White
Write-Host "  1. valida el Access Token copiado desde la pestana Prueba"
Write-Host "  2. usa el User ID mostrado junto a las credenciales de prueba"
Write-Host "  3. crea una sucursal de prueba"
Write-Host "  4. crea una caja QR asociada"
Write-Host "  5. configura FLUS en modo automatico con fallback manual"
Write-Host ""
Write-Host "No sirve para produccion. La configuracion real se hara por separado." -ForegroundColor Yellow

if ($AccessToken -eq "") {
  $AccessToken = Read-SecureToken
}
$AccessToken = $AccessToken.Trim()
if (
  -not $AccessToken.StartsWith("APP_USR-", [System.StringComparison]::OrdinalIgnoreCase) -and
  -not $AccessToken.StartsWith("TEST-", [System.StringComparison]::OrdinalIgnoreCase)
) {
  throw "El valor no parece un Access Token de Mercado Pago. Copia Access Token desde Credenciales > Prueba, no la Public Key."
}

if ($StoreExternalId -eq "") {
  $StoreExternalId = "FLUSTESTSUC$timestamp"
}
if ($PosExternalId -eq "") {
  $PosExternalId = "FLUSTESTCAJA$timestamp"
}

Write-Step "Datos de la credencial de prueba"
if ($NonInteractive) {
  if ([string]::IsNullOrWhiteSpace($CollectorUserId)) {
    throw "Falta el parametro CollectorUserId de las credenciales de prueba."
  }
} else {
  Write-Host "Busca este valor en Credenciales > Prueba > Detalles > User ID." -ForegroundColor DarkGray
  $CollectorUserId = Read-Required -Label "User ID de prueba" -Current $CollectorUserId
}
if ($CollectorUserId -notmatch '^\d+$') {
  throw "El User ID de prueba debe contener solamente numeros."
}

Write-Step "Datos de la sucursal"
if ($NonInteractive) {
  $requiredValues = @{
    "Nombre de la sucursal" = $StoreName
    "Calle" = $StreetName
    "Numero" = $StreetNumber
    "Ciudad" = $CityName
    "Provincia" = $StateName
    "Nombre de la caja QR" = $PosName
  }
  foreach ($entry in $requiredValues.GetEnumerator()) {
    if ([string]::IsNullOrWhiteSpace([string]$entry.Value)) {
      throw "Falta el parametro obligatorio: $($entry.Key)."
    }
  }
  if ([double]::IsNaN($Latitude) -or [double]::IsNaN($Longitude)) {
    throw "Debes indicar Latitude y Longitude."
  }
} else {
  $StoreName = Read-Required -Label "Nombre de la sucursal" -Current $StoreName
  $StreetName = Read-Required -Label "Calle real" -Current $StreetName
  $StreetNumber = Read-Required -Label "Numero" -Current $StreetNumber
  $CityName = Read-Required -Label "Ciudad" -Current $CityName
  $StateName = Read-Required -Label "Provincia" -Current $StateName
  $Latitude = Read-Coordinate -Label "Latitud" -Current $Latitude
  $Longitude = Read-Coordinate -Label "Longitud" -Current $Longitude
  $PosName = Read-Required -Label "Nombre de la caja QR" -Current $PosName
}

$StoreName = ConvertTo-MpPlainText $StoreName
$StreetName = ConvertTo-MpPlainText $StreetName
$CityName = ConvertTo-MpPlainText $CityName
$StateName = ConvertTo-MpPlainText $StateName

Assert-MpTextField -Label "Nombre de la sucursal" -Value $StoreName
Assert-MpTextField -Label "Calle" -Value $StreetName
Assert-MpTextField -Label "Ciudad" -Value $CityName
Assert-MpTextField -Label "Provincia" -Value $StateName

$StoreExternalId = Normalize-ExternalId -Value $StoreExternalId -MaxLength 60
$PosExternalId = Normalize-ExternalId -Value $PosExternalId -MaxLength 40

Write-Step "Confirmacion"
Write-Host "User ID prueba:  $CollectorUserId"
Write-Host "Sucursal:       $StoreName"
Write-Host "ID externo:     $StoreExternalId"
Write-Host "Direccion:      $StreetName $StreetNumber, $CityName, $StateName"
Write-Host "Coordenadas:    $Latitude, $Longitude"
Write-Host "Caja QR:        $PosName"
Write-Host "ID POS externo: $PosExternalId"
Write-Host ""

if (-not $NonInteractive) {
  $confirm = Read-Host "Escribe CREAR para continuar"
  if ($confirm.Trim().ToUpperInvariant() -ne "CREAR") {
    Write-Host "Operacion cancelada. No se creo nada." -ForegroundColor Yellow
    exit 0
  }
}

if ($DryRun) {
  Write-Host ""
  Write-Host "Simulacion correcta. No se llamo a Mercado Pago ni se modifico FLUS." -ForegroundColor Green
  exit 0
}

Write-Step "Validando credencial"
$me = Invoke-MpJson -Method GET -Uri "https://api.mercadopago.com/users/me"
$tokenUserId = [string]$me.id
if ($tokenUserId -eq "") {
  throw "Mercado Pago no pudo validar el Access Token."
}
Write-Host "Credencial valida." -ForegroundColor Green
if ($tokenUserId -ne $CollectorUserId) {
  Write-Host "La API identifica el token como $tokenUserId, pero para crear la sucursal se usara el User ID de prueba $CollectorUserId." -ForegroundColor Yellow
}

Write-Step "Creando sucursal"
$storePayload = @{
  name = $StoreName
  external_id = $StoreExternalId
  business_hours = @{
    monday = @(
      @{
        open = "08:00"
        close = "20:00"
      }
    )
  }
  location = @{
    street_number = $StreetNumber
    street_name = $StreetName
    city_name = $CityName
    state_name = $StateName
    latitude = $Latitude
    longitude = $Longitude
    reference = "Configurada por FLUS"
  }
}
$store = Invoke-MpJson -Method POST -Uri "https://api.mercadopago.com/users/$CollectorUserId/stores" -Body $storePayload
$storeId = [int]$store.id
if ($storeId -le 0) {
  throw "La sucursal fue creada sin un store_id valido."
}
Write-Host "Sucursal creada. Store ID: $storeId" -ForegroundColor Green

Write-Step "Creando caja QR"
$posPayload = @{
  name = $PosName
  fixed_amount = $true
  store_id = $storeId
  external_store_id = $StoreExternalId
  external_id = $PosExternalId
}
if ($Category -gt 0) {
  $posPayload.category = $Category
}

$pos = $null
for ($attempt = 1; $attempt -le 5; $attempt++) {
  try {
    $pos = Invoke-MpJson -Method POST -Uri "https://api.mercadopago.com/pos" -Body $posPayload
    break
  } catch {
    if ($attempt -eq 5 -or [string]$_ -notmatch "external_store|INEXISTENT") {
      throw
    }
    Write-Host "Mercado Pago aun esta registrando la sucursal. Reintento $attempt/5..." -ForegroundColor Yellow
    Start-Sleep -Seconds 3
  }
}

if ($null -eq $pos -or [string]$pos.external_id -eq "") {
  throw "Mercado Pago no devolvio una caja QR valida."
}

Write-FlusConfig -Pos $pos

Write-Host ""
Write-Host "====================================================" -ForegroundColor Green
Write-Host " CONFIGURACION DE PRUEBA COMPLETADA" -ForegroundColor Green
Write-Host "====================================================" -ForegroundColor Green
Write-Host "User ID prueba:  $CollectorUserId"
Write-Host "Store ID:        $storeId"
Write-Host "Sucursal externa: $StoreExternalId"
Write-Host "POS externo QR:   $PosExternalId"
Write-Host "POS interno MP:   $($pos.id)"
Write-Host ""
Write-Host "Siguiente paso en FLUS:" -ForegroundColor Cyan
Write-Host "Administracion > Mercado Pago > Probar conexion"
Write-Host "Luego abre Caja, elige Mercado Pago y cobra con una cuenta compradora de prueba."
Write-Host ""
Write-Host "Configuracion guardada en: $configPath" -ForegroundColor DarkGray
