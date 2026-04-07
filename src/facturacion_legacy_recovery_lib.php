<?php
declare(strict_types=1);

function flus_facturacion_log_event(string $event, array $context = []): void
{
    $payload = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    error_log('[facturacion] ' . $event . $payload);
}

/**
 * @param array<int,array<string,mixed>> $candidates
 */
function flus_facturacion_resolver_fallback_factura(array $candidates, string $scope, int $entityId): ?array
{
    $count = count($candidates);
    if ($count === 0) {
        return null;
    }

    if ($count > 1) {
        flus_facturacion_log_event('fallback_factura_ambiguous', [
            'scope' => $scope,
            'entity_id' => $entityId,
            'factura_ids' => array_values(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $candidates)),
        ]);
        throw new RuntimeException('Se encontraron multiples facturas candidatas para este ' . $scope . '. Requiere revision manual.');
    }

    $factura = $candidates[0];
    flus_facturacion_log_event('fallback_factura_used', [
        'scope' => $scope,
        'entity_id' => $entityId,
        'factura_id' => (int)($factura['id'] ?? 0),
    ]);

    return $factura;
}
