<?php
declare(strict_types=1);

// ✅ FIX v2.1.2: No incluir bootstrap.php completo para evitar side-effects en APIs
// Solo cargar las dependencias necesarias
if (!defined('APP_BOOTSTRAPPED')) {
  // Si no está bootstrapped, cargar solo lo mínimo necesario
  require_once __DIR__ . '/lib/root.php';
  if (file_exists(FLUS_ROOT . '/src/config.php')) {
    require_once FLUS_ROOT . '/src/config.php';
  }
}

require_once __DIR__ . '/includes/PromoEngine.php';

/**
 * Wrapper para no romper código viejo.
 * - Mantiene nombres de funciones existentes.
 * - Centraliza un único PromoEngine.
 */

function promo_engine_instance(): PromoEngine
{
    static $engine = null;

    // Compat: muchos lados tienen $pdo global ya armado por bootstrap.php
    global $pdo;

    if (!($pdo instanceof PDO)) {
        $pdo = getPDO();
    }

    if ($engine === null) {
        $engine = new PromoEngine($pdo);
    }

    return $engine;
}

function obtenerPromosActivas(PDO $pdo, bool $forceRefresh = false): array
{
    // Si te llaman pasando $pdo, lo respetamos creando engine “temporal”.
    // (pero en el sistema normal, usás promo_engine_instance()).
    $engine = new PromoEngine($pdo);
    return $engine->obtenerPromosActivas($forceRefresh);
}

function aplicarPromosACarrito(array $items, ?array $promos = null): array
{
    return promo_engine_instance()->aplicarPromosACarrito($items, $promos);
}

function invalidarCachePromos(): void
{
    promo_engine_instance()->invalidarCache();
}
