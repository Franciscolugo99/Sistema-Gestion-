<?php
declare(strict_types=1);

require_once __DIR__ . '/tesoreria_lib.php';

function flus_compras_crear_obligacion_tesoreria(PDO $pdo, int $compraId, bool $canManage): array
{
    if (!$canManage) {
        return ['success' => false, 'error' => 'No tenes permiso para gestionar tesoreria.'];
    }
    if ($compraId <= 0) {
        return ['success' => false, 'error' => 'ID invalido.'];
    }

    try {
        $res = flus_tesoreria_create_purchase_obligation($pdo, $compraId);
        if (($res['success'] ?? false) !== true) {
            return ['success' => false, 'error' => (string)($res['error'] ?? 'No se pudo crear la obligacion.')];
        }
        return [
            'success' => true,
            'saved' => !empty($res['already_exists']) ? 'obligation_exists' : 'obligation_created',
        ];
    } catch (Throwable $e) {
        if (function_exists('flus_log_error')) {
            flus_log_error('compras crear_obligacion_tesoreria failed', [
                'compra_id' => $compraId,
                'error' => $e->getMessage(),
            ]);
        }
        return ['success' => false, 'error' => 'Error al crear deuda en tesoreria: ' . $e->getMessage()];
    }
}
