<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../src/facturacion_lib.php';
require_once __DIR__ . '/../../../src/venta_anulaciones_lib.php';

/**
 * Implementación concreta de FiscalRecoveryService.
 *
 * Maneja casos en que ARCA aprobó la NC pero la TX3 (aplicación comercial)
 * falló, dejando estado_fiscal = 'ERROR_POST_ARCA'.
 *
 * El recovery reaplica solo la parte comercial local:
 *   - reposición de stock
 *   - reversión proporcional de CC
 *   - estado de venta y anulación
 *
 * NO re-emite ante ARCA. La NC ya existe y tiene CAE. Solo cierra el lado local.
 */
final class DbFiscalRecoveryService implements FiscalRecoveryService
{
    public function __construct(
        private PDO $pdo,
        private FacturaFiscalRepository $repository
    ) {
    }

    public function recoverByRequestUid(string $requestUid, int $usuarioId): RecoveryResult
    {
        if ($requestUid === '') {
            return RecoveryResult::error($requestUid, 'INVALID_UID', 'request_uid vacío.');
        }

        $anulacion = $this->repository->findVentaAnulacionByRequestUid($requestUid);
        if (!$anulacion) {
            return RecoveryResult::error($requestUid, 'NOT_FOUND', 'No se encontró anulación con ese request_uid.');
        }

        $estadoFiscal = strtoupper(trim((string)($anulacion['estado_fiscal'] ?? '')));
        if ($estadoFiscal !== 'ERROR_POST_ARCA') {
            return RecoveryResult::error(
                $requestUid,
                'ESTADO_NO_RECUPERABLE',
                "La anulación tiene estado_fiscal '{$estadoFiscal}', solo se pueden recuperar las en ERROR_POST_ARCA."
            );
        }

        $anulacionId = (int)$anulacion['id'];
        $ventaId     = (int)$anulacion['venta_id'];

        try {
            $this->reaplicarComercial($anulacion, $ventaId, $anulacionId, $usuarioId, $requestUid);
        } catch (\Throwable $e) {
            return RecoveryResult::error($requestUid, 'RECOVERY_FAILED', $e->getMessage());
        }

        $ncFacturaId = (int)($anulacion['nc_factura_id'] ?? 0) ?: null;
        return RecoveryResult::ok($requestUid, $anulacionId, $ncFacturaId, 'Aplicación comercial reaplicada correctamente.');
    }

    /**
     * @return array<int,RecoveryResult>
     */
    public function recoverPendings(int $limit = 50): array
    {
        if (!flus_table_exists($this->pdo, 'venta_anulaciones')
            || !flus_column_exists($this->pdo, 'venta_anulaciones', 'estado_fiscal')
            || !flus_column_exists($this->pdo, 'venta_anulaciones', 'fiscal_request_uid')
        ) {
            return [];
        }

        $st = $this->pdo->prepare(
            "SELECT * FROM venta_anulaciones
             WHERE COALESCE(estado_fiscal, 'NO_APLICA') = 'ERROR_POST_ARCA'
             ORDER BY anulado_en DESC
             LIMIT ?"
        );
        $st->bindValue(1, max(1, $limit), \PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) {
            return [];
        }

        $results = [];
        foreach ($rows as $anulacion) {
            $requestUid  = (string)($anulacion['fiscal_request_uid'] ?? '');
            $anulacionId = (int)$anulacion['id'];
            $ventaId     = (int)$anulacion['venta_id'];

            try {
                $this->reaplicarComercial($anulacion, $ventaId, $anulacionId, null, $requestUid);
                $ncFacturaId = (int)($anulacion['nc_factura_id'] ?? 0) ?: null;
                $results[]   = RecoveryResult::ok($requestUid, $anulacionId, $ncFacturaId, 'Reaplicado.');
            } catch (\Throwable $e) {
                $results[] = RecoveryResult::error($requestUid, 'RECOVERY_FAILED', $e->getMessage());
            }
        }

        return $results;
    }

    // ──────────────────────────────────────────────────────────────────────────

    private function reaplicarComercial(
        array $anulacion,
        int $ventaId,
        int $anulacionId,
        ?int $usuarioId,
        string $requestUid
    ): void {
        $this->pdo->beginTransaction();
        try {
            $venta = $this->repository->lockVenta($ventaId);
            if (!$venta) {
                throw new \RuntimeException("Venta #{$ventaId} no encontrada.");
            }

            $anulacionLocked = $this->repository->lockVentaAnulacion($anulacionId);
            if (!$anulacionLocked) {
                throw new \RuntimeException("Anulación #{$anulacionId} no encontrada.");
            }

            // Re-verificar estado; puede haber sido recuperado por otra instancia.
            $estadoFiscalActual = strtoupper(trim((string)($anulacionLocked['estado_fiscal'] ?? '')));
            if ($estadoFiscalActual !== 'ERROR_POST_ARCA') {
                throw new \RuntimeException(
                    "La anulación ya fue procesada (estado_fiscal actual: '{$estadoFiscalActual}'). No se requiere recovery."
                );
            }

            $tipo   = strtoupper(trim((string)($anulacion['tipo'] ?? 'TOTAL')));
            $motivo = 'Recovery post-ARCA ' . $requestUid;

            // ── Stock ──
            $ventaItems  = flus_venta_items_cargar($this->pdo, $ventaId);
            $snapshotRows = flus_venta_anulacion_items_cargar($this->pdo, $anulacionId);

            if ($tipo === 'TOTAL') {
                $yaAnulado     = flus_venta_items_anulados_map($this->pdo, $ventaId);
                $itemsRestantes = flus_venta_items_restantes($ventaItems, $yaAnulado);
                if ($itemsRestantes !== []) {
                    flus_venta_stock_reponer_items($this->pdo, $itemsRestantes, $ventaId, $usuarioId, "Recovery NC total venta #{$ventaId}");
                }
            } else {
                // Parcial: reponer solo los items del snapshot.
                $stockItems = [];
                foreach ($snapshotRows as $row) {
                    $ventaItemId = (int)($row['venta_item_id'] ?? 0);
                    if ($ventaItemId <= 0 || !isset($ventaItems[$ventaItemId])) {
                        continue;
                    }
                    $stockItems[] = [
                        'item'     => $ventaItems[$ventaItemId],
                        'cantidad' => round((float)($row['cantidad_anulada'] ?? 0), 3),
                    ];
                }
                if ($stockItems !== []) {
                    flus_venta_stock_reponer_items($this->pdo, $stockItems, $ventaId, $usuarioId, "Recovery NC parcial venta #{$ventaId}");
                }
            }

            // ── CC ──
            $montoNcLocal = round((float)($anulacion['monto_total'] ?? 0), 2);
            if ($montoNcLocal > 0) {
                flus_venta_cc_revertir_monto($this->pdo, $venta, $ventaId, $montoNcLocal, $usuarioId, "Recovery NC venta #{$ventaId}");
            }

            // ── Estado de venta ──
            if ($tipo === 'TOTAL') {
                $nuevoEstado = 'ANULADA';
            } else {
                $yaAnulado2     = flus_venta_items_anulados_map($this->pdo, $ventaId);
                $restantes2     = flus_venta_items_restantes($ventaItems, $yaAnulado2);
                $nuevoEstado    = $restantes2 === [] ? 'ANULADA' : 'PARCIALMENTE_ANULADA';
            }

            $sets   = ['estado = :estado'];
            $params = [':estado' => $nuevoEstado, ':id' => $ventaId];
            if ($nuevoEstado === 'ANULADA' && flus_column_exists($this->pdo, 'ventas', 'anulado_en')) {
                $sets[] = 'anulado_en = NOW()';
            }
            $this->pdo->prepare('UPDATE ventas SET ' . implode(', ', $sets) . ' WHERE id = :id')->execute($params);

            // ── Cerrar anulación ──
            $this->pdo->prepare("UPDATE venta_anulaciones SET estado = 'CONFIRMADA' WHERE id = ?")->execute([$anulacionId]);
            $this->repository->updateVentaAnulacionFiscalState($anulacionId, 'APLICADA', [
                'fiscal_applied_at'    => date('Y-m-d H:i:s'),
                'fiscal_error_code'    => null,
                'fiscal_error_message' => null,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
