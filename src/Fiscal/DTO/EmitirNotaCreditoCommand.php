<?php
declare(strict_types=1);

final class EmitirNotaCreditoCommand
{
    public int $ventaId = 0;
    public int $ventaAnulacionId = 0;
    public int $usuarioId = 0;
    public string $scope = 'TOTAL';
    public string $modo = 'demo';
    public string $requestUid = '';
    public bool $rebuildOriginalItemsIfMissing = true;
    public float $legacyTolerance = 0.05;

    /** @var array<int,array{item_id:int,cantidad:float}> */
    public array $partialItems = [];

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $dto = new self();
        $dto->ventaId = (int)($data['ventaId'] ?? $data['venta_id'] ?? 0);
        $dto->ventaAnulacionId = (int)($data['ventaAnulacionId'] ?? $data['venta_anulacion_id'] ?? 0);
        $dto->usuarioId = (int)($data['usuarioId'] ?? $data['usuario_id'] ?? 0);
        $dto->scope = strtoupper(trim((string)($data['scope'] ?? 'TOTAL')));
        $dto->modo = strtolower(trim((string)($data['modo'] ?? 'demo')));
        $dto->requestUid = trim((string)($data['requestUid'] ?? $data['request_uid'] ?? ''));
        $dto->rebuildOriginalItemsIfMissing = (bool)($data['rebuildOriginalItemsIfMissing'] ?? $data['rebuild_original_items_if_missing'] ?? true);
        $dto->legacyTolerance = max(0.01, round((float)($data['legacyTolerance'] ?? $data['legacy_tolerance'] ?? 0.05), 2));
        $dto->partialItems = self::normalizePartialItems((array)($data['partialItems'] ?? $data['partial_items'] ?? []));

        return $dto;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'venta_id' => $this->ventaId,
            'venta_anulacion_id' => $this->ventaAnulacionId,
            'usuario_id' => $this->usuarioId,
            'scope' => $this->scope,
            'modo' => $this->modo,
            'request_uid' => $this->requestUid,
            'rebuild_original_items_if_missing' => $this->rebuildOriginalItemsIfMissing,
            'legacy_tolerance' => $this->legacyTolerance,
            'partial_items' => $this->partialItems,
        ];
    }

    /**
     * @param array<int,mixed> $items
     * @return array<int,array{item_id:int,cantidad:float}>
     */
    private static function normalizePartialItems(array $items): array
    {
        $acc = [];

        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }

            $itemId = (int)($row['item_id'] ?? $row['itemId'] ?? 0);
            $cantidad = round((float)($row['cantidad'] ?? 0), 3);

            if ($itemId <= 0 || $cantidad <= 0) {
                continue;
            }

            if (!isset($acc[$itemId])) {
                $acc[$itemId] = 0.0;
            }

            $acc[$itemId] = round($acc[$itemId] + $cantidad, 3);
        }

        ksort($acc, SORT_NUMERIC);

        $out = [];
        foreach ($acc as $itemId => $cantidad) {
            if ($cantidad <= 0) {
                continue;
            }

            $out[] = [
                'item_id' => (int)$itemId,
                'cantidad' => round((float)$cantidad, 3),
            ];
        }

        return $out;
    }
}
