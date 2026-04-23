<?php
declare(strict_types=1);

require_once __DIR__ . '/precio_historial.php';

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
        "Compra #{$compraId} confirmada"
    );
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
