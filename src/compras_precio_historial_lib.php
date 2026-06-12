<?php
declare(strict_types=1);

require_once __DIR__ . '/precio_historial.php';
require_once __DIR__ . '/db_schema.php';

function flus_compras_actualizar_costo_con_historial(PDO $pdo, int $productoId, float $costoNuevo, int $compraId): void
{
    if ($productoId <= 0 || $costoNuevo <= 0) {
        return;
    }

    $stCostoActual = $pdo->prepare('SELECT costo FROM productos WHERE id = ? FOR UPDATE');
    $stCostoActual->execute([$productoId]);
    $costoAnterior = (float)($stCostoActual->fetchColumn() ?: 0);

    $stUpdCosto = $pdo->prepare('UPDATE productos SET costo = :costo WHERE id = :pid');
    $stUpdCosto->execute([':costo' => $costoNuevo, ':pid' => $productoId]);

    if (abs($costoAnterior - $costoNuevo) < 0.001) {
        return;
    }

    precio_registrar_cambio(
        $productoId,
        $costoAnterior,
        $costoNuevo,
        'COSTO',
        "Compra #{$compraId} confirmada",
        null,
        $pdo
    );
}

/**
 * Revierte el costo solo cuando el ultimo cambio sigue perteneciendo a la
 * compra anulada. Si hubo otro cambio posterior, conserva el costo vigente.
 *
 * @param array<int,int|string> $productoIds
 */
function flus_compras_revertir_costos_al_anular(PDO $pdo, array $productoIds, int $compraId): void
{
    if ($compraId <= 0 || !function_exists('flus_table_exists') || !flus_table_exists($pdo, 'producto_precios_hist')) {
        return;
    }

    $productoIds = array_values(array_unique(array_filter(
        array_map('intval', $productoIds),
        static fn(int $id): bool => $id > 0
    )));
    if ($productoIds === []) {
        return;
    }

    $motivoEsperado = "Compra #{$compraId} confirmada";
    $stHistorial = $pdo->prepare("
        SELECT id, precio_anterior, precio_nuevo, motivo
        FROM producto_precios_hist
        WHERE producto_id = ?
          AND tipo = 'COSTO'
        ORDER BY id DESC
        FOR UPDATE
    ");
    $stCosto = $pdo->prepare('SELECT costo FROM productos WHERE id = ? FOR UPDATE');
    $stUpdate = $pdo->prepare('UPDATE productos SET costo = ? WHERE id = ?');

    foreach ($productoIds as $productoId) {
        $stHistorial->execute([$productoId]);
        $historialRows = $stHistorial->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $cambiosCompra = [];
        foreach ($historialRows as $historial) {
            if (trim((string)($historial['motivo'] ?? '')) !== $motivoEsperado) {
                break;
            }
            $cambiosCompra[] = $historial;
        }

        if ($cambiosCompra === []) {
            continue;
        }

        $ultimoCambio = $cambiosCompra[0];
        $primerCambio = $cambiosCompra[count($cambiosCompra) - 1];
        $costoAnterior = round((float)($primerCambio['precio_anterior'] ?? 0), 2);
        $costoCompra = round((float)($ultimoCambio['precio_nuevo'] ?? 0), 2);
        $stCosto->execute([$productoId]);
        $costoActual = round((float)($stCosto->fetchColumn() ?: 0), 2);
        if (abs($costoActual - $costoCompra) >= 0.01) {
            continue;
        }

        $stUpdate->execute([$costoAnterior, $productoId]);
        precio_registrar_cambio(
            $productoId,
            $costoActual,
            $costoAnterior,
            'COSTO',
            "Anulacion compra #{$compraId}",
            null,
            $pdo
        );
    }
}

function flus_compras_margenes_para_compra(PDO $pdo, int $compraId, float $umbralBajo = 20.0, float $margenSugerido = 30.0): array
{
    if ($compraId <= 0) {
        return ['productos' => [], 'ids' => [], 'bajos' => 0, 'negativos' => 0, 'margen_sugerido' => $margenSugerido];
    }

    $st = $pdo->prepare("
        SELECT
            p.id,
            p.codigo,
            p.nombre,
            p.precio,
            p.costo,
            CASE WHEN p.costo > 0 THEN ROUND(((p.precio - p.costo) / p.costo) * 100, 2) ELSE NULL END AS margen_pct,
            CASE WHEN p.costo > 0 THEN ROUND(p.costo * (1 + (:margen_sugerido / 100)), 2) ELSE NULL END AS precio_sugerido
        FROM compra_items ci
        JOIN productos p ON p.id = ci.producto_id
        JOIN compras c ON c.id = ci.compra_id
        WHERE ci.compra_id = :compra_id
          AND c.estado = 'CONFIRMADA'
        GROUP BY p.id, p.codigo, p.nombre, p.precio, p.costo
        ORDER BY margen_pct ASC, p.nombre ASC
    ");
    $st->execute([':compra_id' => $compraId, ':margen_sugerido' => $margenSugerido]);
    $productos = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ids = [];
    $bajos = 0;
    $negativos = 0;
    foreach ($productos as $p) {
        $id = (int)($p['id'] ?? 0);
        if ($id > 0) {
            $ids[] = $id;
        }

        $margen = $p['margen_pct'];
        if ($margen !== null && $margen !== '') {
            $margenF = (float)$margen;
            if ($margenF < $umbralBajo) {
                $bajos++;
            }
            if ($margenF < 0) {
                $negativos++;
            }
        }
    }

    return [
        'productos' => $productos,
        'ids' => array_values(array_unique($ids)),
        'bajos' => $bajos,
        'negativos' => $negativos,
        'margen_sugerido' => $margenSugerido,
    ];
}
