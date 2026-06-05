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

function flus_compras_cancelar_obligacion_al_anular(PDO $pdo, int $compraId): array
{
    if ($compraId <= 0 || !flus_tesoreria_obligaciones_compras_ready($pdo)) {
        return ['success' => true, 'cancelled' => false];
    }

    $st = $pdo->prepare("
        SELECT id, estado, importe_pagado, movimiento_pago_id, observaciones
        FROM tesoreria_obligaciones
        WHERE compra_id = ?
          AND external_key = ?
        LIMIT 1
        FOR UPDATE
    ");
    $st->execute([$compraId, 'compra:' . $compraId]);
    $obligacion = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($obligacion)) {
        return ['success' => true, 'cancelled' => false];
    }

    $estado = strtoupper(trim((string)($obligacion['estado'] ?? '')));
    if ($estado === 'CANCELADO') {
        return ['success' => true, 'cancelled' => false];
    }

    $importePagado = round((float)($obligacion['importe_pagado'] ?? 0), 2);
    $movimientoPagoId = (int)($obligacion['movimiento_pago_id'] ?? 0);
    if ($importePagado > 0.009 || $movimientoPagoId > 0 || in_array($estado, ['PARCIAL', 'PAGADO'], true)) {
        return [
            'success' => false,
            'error' => 'La compra tiene pagos registrados en Tesoreria. Regulariza o revierte esos pagos antes de anularla.',
        ];
    }

    $nota = 'Cancelada automaticamente al anular la compra #' . $compraId . '.';
    $observaciones = trim((string)($obligacion['observaciones'] ?? ''));
    if ($observaciones !== '') {
        $observaciones .= ' ';
    }
    $observaciones .= $nota;

    $stUpdate = $pdo->prepare("
        UPDATE tesoreria_obligaciones
        SET estado = 'CANCELADO',
            observaciones = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stUpdate->execute([mb_substr($observaciones, 0, 255), (int)$obligacion['id']]);

    return ['success' => true, 'cancelled' => true];
}
