<?php
declare(strict_types=1);

interface AnulacionFiscalCoordinator
{
    public function procesarTotal(int $ventaId, int $usuarioId, string $motivo, array $options = []): AnulacionFiscalOutcome;

    /**
     * @param array<int,array{item_id:int,cantidad:float}> $items
     */
    public function procesarParcial(int $ventaId, array $items, int $usuarioId, string $motivo, array $options = []): AnulacionFiscalOutcome;
}
