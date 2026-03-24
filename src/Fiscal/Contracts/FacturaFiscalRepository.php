<?php
declare(strict_types=1);

interface FacturaFiscalRepository
{
    public function lockVenta(int $ventaId): array;

    public function lockVentaAnulacion(int $ventaAnulacionId): array;

    public function findVentaAnulacionByRequestUid(string $requestUid): ?array;

    public function findFacturaOrigenByVentaId(int $ventaId): ?array;

    public function findFacturaOrigenByDocumentoId(int $documentoId): ?array;

    public function findFacturaById(int $facturaId): ?array;

    public function lockFacturaById(int $facturaId): array;

    public function findFacturaByRequestUid(string $requestUid): ?array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function findFacturaItems(int $facturaId): array;

    public function insertFactura(array $header): int;

    /**
     * @param array<int,array<string,mixed>> $items
     */
    public function insertFacturaItems(int $facturaId, array $items): void;

    public function insertArcaEvent(array $event): int;

    public function findArcaEventByRequestUid(string $requestUid): ?array;

    public function updateArcaEventResult(string $requestUid, array $patch): void;

    public function updateFactura(int $facturaId, array $patch): void;

    public function updateFacturaFiscalState(int $facturaId, string $estadoFiscal, array $patch = []): void;

    public function updateVentaAnulacionFiscalState(int $ventaAnulacionId, string $estadoFiscal, array $patch = []): void;

    public function updateVentaAnulacionLinkage(
        int $ventaAnulacionId,
        ?int $facturaOrigenId,
        ?int $ncFacturaId,
        bool $updateFacturaOrigenId = false,
        bool $updateNcFacturaId = false
    ): void;

    public function linkNotaCreditoToAnulacion(int $ventaAnulacionId, int $ncFacturaId): void;
}
