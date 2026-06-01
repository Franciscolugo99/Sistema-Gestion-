param(
  [string]$StoreExternalId = "",
  [string]$PosExternalId = "",
  [string]$StoreName = "FLUS Test Store",
  [string]$PosName = "FLUS Caja 1",
  [int]$Category = 0
)

$ErrorActionPreference = "Stop"

$root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$configPath = Join-Path $root "src\config_mp.php"

if (-not $StoreExternalId) {
  $StoreExternalId = "FLUSTESTSTORE" + (Get-Date -Format "yyyyMMddHHmmss")
}
if (-not $PosExternalId) {
  $PosExternalId = "FLUSTESTPOS" + (Get-Date -Format "yyyyMMddHHmmss")
}

function Normalize-ExternalId {
  param(
    [string]$Value,
    [int]$MaxLength
  )
  $normalized = ([string]$Value).ToUpperInvariant() -replace '[^A-Z0-9]', ''
  if ([string]::IsNullOrWhiteSpace($normalized)) {
    throw "external_id vacio despues de normalizar."
  }
  if ($normalized.Length -gt $MaxLength) {
    $normalized = $normalized.Substring(0, $MaxLength)
  }
  $normalized
}

$StoreExternalId = Normalize-ExternalId -Value $StoreExternalId -MaxLength 60
$PosExternalId = Normalize-ExternalId -Value $PosExternalId -MaxLength 40

Write-Host "Pega el Access Token de prueba de Mercado Pago." -ForegroundColor Cyan
$secureToken = Read-Host "Access Token" -AsSecureString
$bstr = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureToken)
try {
  $accessToken = [Runtime.InteropServices.Marshal]::PtrToStringBSTR($bstr)
} finally {
  if ($bstr -ne [IntPtr]::Zero) {
    [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
  }
}

$accessToken = [string]$accessToken
if ([string]::IsNullOrWhiteSpace($accessToken)) {
  throw "Access Token vacio."
}

$headers = @{
  Authorization = "Bearer $accessToken"
  "Content-Type" = "application/json"
  Accept = "application/json"
}

function Invoke-MpJson {
  param(
    [string]$Method,
    [string]$Uri,
    [object]$Body = $null
  )

  $args = @{
    Method = $Method
    Uri = $Uri
    Headers = $headers
    TimeoutSec = 30
  }
  if ($null -ne $Body) {
    $args.Body = ($Body | ConvertTo-Json -Depth 12)
  }
  Invoke-RestMethod @args
}

function Invoke-MpJsonOrDetail {
  param(
    [string]$Method,
    [string]$Uri,
    [object]$Body = $null
  )

  try {
    return Invoke-MpJson -Method $Method -Uri $Uri -Body $Body
  } catch {
    $raw = ""
    try {
      $stream = $_.Exception.Response.GetResponseStream()
      if ($stream) {
        $reader = [System.IO.StreamReader]::new($stream)
        $raw = $reader.ReadToEnd()
      }
    } catch {}
    if ($raw) {
      throw $raw
    }
    throw
  }
}

Write-Host "Consultando usuario de prueba..." -ForegroundColor Cyan
$me = Invoke-MpJsonOrDetail -Method GET -Uri "https://api.mercadopago.com/users/me"
$userId = [string]$me.id
if ([string]::IsNullOrWhiteSpace($userId)) {
  throw "Mercado Pago no devolvio user_id."
}

Write-Host "Creando sucursal $StoreExternalId..." -ForegroundColor Cyan
$storePayload = @{
  name = $StoreName
  external_id = $StoreExternalId
  location = @{
    street_number = "123"
    street_name = "FLUS Test"
    city_name = "Mendoza"
    state_name = "Mendoza"
    latitude = -32.8895
    longitude = -68.8458
    reference = "Prueba local FLUS"
  }
}
$store = Invoke-MpJsonOrDetail -Method POST -Uri "https://api.mercadopago.com/users/$userId/stores" -Body $storePayload
$storeId = [int]$store.id
if ($storeId -le 0) {
  throw "Mercado Pago no devolvio store_id."
}

Write-Host "Creando caja QR $PosExternalId..." -ForegroundColor Cyan
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
$lastPosError = $null
for ($attempt = 1; $attempt -le 5; $attempt++) {
  try {
    if ($attempt -gt 1) {
      Write-Host "Reintentando caja QR ($attempt/5)..." -ForegroundColor Yellow
      Start-Sleep -Seconds 3
    }
    $pos = Invoke-MpJsonOrDetail -Method POST -Uri "https://api.mercadopago.com/pos" -Body $posPayload
    break
  } catch {
    $lastPosError = [string]$_
    if ($lastPosError -notmatch 'non_existent_external_store_id|INEXISTENT_EXTERNAL_STORE_ID') {
      throw
    }
  }
}

if ($null -eq $pos) {
  Write-Host "Mercado Pago no reconocio external_store_id todavia; pruebo usando store_id solamente..." -ForegroundColor Yellow
  $posPayloadNoExternalStore = @{
    name = $PosName
    fixed_amount = $true
    store_id = $storeId
    external_id = $PosExternalId
  }
  if ($Category -gt 0) {
    $posPayloadNoExternalStore.category = $Category
  }
  try {
    $pos = Invoke-MpJsonOrDetail -Method POST -Uri "https://api.mercadopago.com/pos" -Body $posPayloadNoExternalStore
  } catch {
    if ($lastPosError) {
      Write-Host "Ultimo error con external_store_id: $lastPosError" -ForegroundColor Yellow
    }
    throw
  }
}

$qrImage = [string]$pos.qr.image
$qrTemplateDocument = [string]$pos.qr.template_document
$qrTemplateImage = [string]$pos.qr.template_image

$php = @"
<?php
declare(strict_types=1);

define('FLUS_MP_ACCESS_TOKEN', '$accessToken');
define('FLUS_MP_QR_EXTERNAL_POS_ID', '$PosExternalId');
define('FLUS_MP_QR_MODE', 'hybrid');
define('FLUS_MP_QR_DESCRIPTION', 'Prueba FLUS QR');
define('FLUS_MP_QR_IMAGE_URL', '$qrImage');
define('FLUS_MP_QR_TEMPLATE_DOCUMENT_URL', '$qrTemplateDocument');
define('FLUS_MP_QR_TEMPLATE_IMAGE_URL', '$qrTemplateImage');
define('FLUS_MP_POINT_TERMINAL_ID', '');
"@

Set-Content -Path $configPath -Value $php -Encoding ASCII

Write-Host ""
Write-Host "Listo. Se creo src\config_mp.php" -ForegroundColor Green
Write-Host "User ID: $userId"
Write-Host "Store external_id: $StoreExternalId"
Write-Host "Store id: $storeId"
Write-Host "POS external_id: $PosExternalId"
Write-Host "POS id: $($pos.id)"
if ($pos.qr.image) {
  Write-Host "QR image: $($pos.qr.image)"
}
if ($pos.qr.template_document) {
  Write-Host "QR PDF: $($pos.qr.template_document)"
}
