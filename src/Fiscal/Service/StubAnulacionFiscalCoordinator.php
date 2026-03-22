<?php
declare(strict_types=1);

final class StubAnulacionFiscalCoordinator implements AnulacionFiscalCoordinator
{
    public function __construct(
        private FacturaFiscalRepository $repository,
        private NotaCreditoService $notaCreditoService,
        private FiscalRecoveryService $recoveryService
    ) {
    }

    public function procesarTotal(int $ventaId, int $usuarioId, string $motivo, array $options = []): AnulacionFiscalOutcome
    {
        $out = new AnulacionFiscalOutcome();
        $out->ventaId = $ventaId;
        $out->estado = 'PENDIENTE';
        $out->estadoFiscal = 'PENDIENTE';
        $out->message = 'La coordinacion fiscal de anulacion total queda pendiente para la Fase 2.';
        return $out;
    }

    public function procesarParcial(int $ventaId, array $items, int $usuarioId, string $motivo, array $options = []): AnulacionFiscalOutcome
    {
        $out = new AnulacionFiscalOutcome();
        $out->ventaId = $ventaId;
        $out->estado = 'PENDIENTE';
        $out->estadoFiscal = 'PENDIENTE';
        $out->message = 'La coordinacion fiscal de anulacion parcial queda pendiente para la Fase 2.';
        return $out;
    }
}
