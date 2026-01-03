<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
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
