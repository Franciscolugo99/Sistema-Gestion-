<?php
declare(strict_types=1);

if (defined('FLUS_FISCAL_BOOTSTRAP_LOADED')) {
    return;
}
define('FLUS_FISCAL_BOOTSTRAP_LOADED', true);

require_once __DIR__ . '/../db_schema.php';
require_once __DIR__ . '/Contracts/NotaCreditoService.php';
require_once __DIR__ . '/Contracts/FiscalRecoveryService.php';
require_once __DIR__ . '/Contracts/FacturaFiscalRepository.php';
require_once __DIR__ . '/Contracts/AnulacionFiscalCoordinator.php';
require_once __DIR__ . '/DTO/EmitirNotaCreditoCommand.php';
require_once __DIR__ . '/DTO/EmitirNotaCreditoResult.php';
require_once __DIR__ . '/DTO/RecoveryResult.php';
require_once __DIR__ . '/DTO/AnulacionFiscalOutcome.php';
require_once __DIR__ . '/Repository/PdoFacturaFiscalRepository.php';
require_once __DIR__ . '/Service/StubNotaCreditoService.php';
require_once __DIR__ . '/Service/StubFiscalRecoveryService.php';
require_once __DIR__ . '/Service/StubAnulacionFiscalCoordinator.php';
require_once __DIR__ . '/Service/ArcaNotaCreditoService.php';
require_once __DIR__ . '/Service/DbAnulacionFiscalCoordinator.php';
require_once __DIR__ . '/Service/DbFiscalRecoveryService.php';

/**
 * Verifica que las migraciones necesarias para el módulo fiscal NC estén aplicadas.
 *
 * El coordinator y el service asumen columnas de la migración 012 que no existen
 * si solo se corrió la 010. Esta función falla explícito en lugar de dejar que
 * un INSERT falle con un error de columna desconocida o valor inválido de ENUM.
 *
 * Llamar antes de instanciar DbAnulacionFiscalCoordinator en cualquier endpoint.
 *
 * @throws RuntimeException si faltan columnas o el ENUM de estado no está actualizado.
 */
function flus_fiscal_nc_assert_schema_ready(PDO $pdo): void
{
    // Columnas de la migración 012 que el coordinator usa en TX1.
    $required = [
        'venta_anulaciones' => [
            'estado_fiscal',
            'requiere_nc',
            'fiscal_request_uid',
            'fiscal_intentos',
            'fiscal_requested_at',
            'factura_origen_id',
            'nc_factura_id',
        ],
    ];

    $missing = [];
    foreach ($required as $table => $cols) {
        foreach ($cols as $col) {
            if (!flus_column_exists($pdo, $table, $col)) {
                $missing[] = "{$table}.{$col}";
            }
        }
    }

    if ($missing !== []) {
        throw new RuntimeException(
            'El módulo de Notas de Crédito requiere que las migraciones 010 y 012 estén '
            . 'ambas aplicadas. Faltan columnas: ' . implode(', ', $missing) . '. '
            . 'Ejecutá migrations/012_venta_anulaciones_fiscal.sql antes de continuar.'
        );
    }

    // Verificar que el ENUM de estado incluye PENDIENTE (agregado en 012).
    // Lo hacemos leyendo COLUMN_TYPE desde INFORMATION_SCHEMA; si falla o no contiene
    // 'pendiente', la migración 012 no fue aplicada correctamente.
    $st = $pdo->prepare(
        "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME   = 'venta_anulaciones'
           AND COLUMN_NAME  = 'estado'
         LIMIT 1"
    );
    $st->execute();
    $columnType = strtolower((string)($st->fetchColumn() ?: ''));

    if ($columnType !== '' && strpos($columnType, 'pendiente') === false) {
        throw new RuntimeException(
            'El ENUM de venta_anulaciones.estado no incluye el valor PENDIENTE. '
            . 'Aplicá migrations/012_venta_anulaciones_fiscal.sql para habilitarlo.'
        );
    }
}
