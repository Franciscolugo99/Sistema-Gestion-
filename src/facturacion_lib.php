<?php
// src/facturacion_lib.php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Crea una factura desde una venta existente
 * 
 * @param int $ventaId ID de la venta
 * @param int $clienteId ID del cliente (puede ser 0 para consumidor final)
 * @param array $opciones Opciones adicionales:
 *   - modo: 'demo' o 'produccion' (default: según config)
 *   - tipo_cbte: int (forzar tipo de comprobante)
 *   - concepto: int (1=Productos, 2=Servicios, 3=Ambos)
 * @return int ID de la factura creada
 * @throws Exception Si hay error
 */
function crearFacturaDesdeVenta(int $ventaId, int $clienteId, array $opciones = []): int
{
    $pdo = getPDO();
    
    // Verificar si facturación está habilitada
    $facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
    if (!$facturacionHabilitada) {
        throw new Exception("El módulo de facturación no está habilitado.");
    }

    $pdo->beginTransaction();

    try {
        // 1) Leer la venta
        $st = $pdo->prepare("SELECT * FROM ventas WHERE id = ?");
        $st->execute([$ventaId]);
        $venta = $st->fetch(PDO::FETCH_ASSOC);

        if (!$venta) {
            throw new Exception("Venta no encontrada.");
        }

        // 2) Verificar que no tenga factura previa
        $st = $pdo->prepare("SELECT id FROM facturas WHERE venta_id = ?");
        $st->execute([$ventaId]);
        if ($st->fetchColumn()) {
            throw new Exception("La venta ya tiene una factura emitida.");
        }

        // 3) Leer cliente (si hay)
        $cliente = null;
        if ($clienteId > 0) {
            $st = $pdo->prepare("SELECT * FROM clientes WHERE id = ?");
            $st->execute([$clienteId]);
            $cliente = $st->fetch(PDO::FETCH_ASSOC);
        }

        // 4) Leer config de facturación activa
        $st = $pdo->prepare("
            SELECT *
            FROM config_facturacion
            WHERE activo = 1
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $st->execute();
        $config = $st->fetch(PDO::FETCH_ASSOC);

        if (!$config) {
            throw new Exception("No hay configuración de facturación activa. Configure un punto de venta primero.");
        }

        $puntoVenta = (int)$config['punto_venta'];
        $condIvaEmisor = (string)($config['cond_iva'] ?? 'RI');
        $modoDemo = ($config['modo'] ?? 'demo') === 'demo';
        
        // Override desde opciones
        if (isset($opciones['modo'])) {
            $modoDemo = $opciones['modo'] === 'demo';
        }

        // 5) Determinar tipo de comprobante
        $condIvaReceptor = determinarCondIvaReceptor($cliente);
        $tipoCbte = $opciones['tipo_cbte'] ?? determinarTipoComprobante($condIvaEmisor, $condIvaReceptor);
        
        // 6) Obtener próximo número
        $numero = (int)$config['proximo_numero'];
        
        // En modo producción, consultar a AFIP el último número
        if (!$modoDemo) {
            require_once __DIR__ . '/../public/includes/ArcaWsfe.php';
            $ultimoAfip = ArcaWsfe::getUltimoAutorizado($puntoVenta, $tipoCbte);
            if ($ultimoAfip !== null) {
                $numero = $ultimoAfip + 1;
            }
        }

        // 7) Calcular importes
        $importes = calcularImportesFactura($pdo, $ventaId, $venta, $tipoCbte);

        // 8) Preparar datos del comprobante
        $comprobante = [
            'tipo_cbte' => $tipoCbte,
            'punto_venta' => $puntoVenta,
            'numero' => $numero,
            'concepto' => $opciones['concepto'] ?? 1, // Productos
            'fecha' => date('Y-m-d'),
            'importe_total' => $importes['total'],
            'importe_neto' => $importes['neto'],
            'importe_iva' => $importes['iva'],
            'importe_exento' => $importes['exento'],
            'importe_no_gravado' => $importes['no_gravado'],
            'moneda_id' => 'PES',
            'moneda_cotiz' => 1,
        ];

        // Datos del cliente
        $docData = determinarDocumentoCliente($cliente);
        $comprobante['tipo_doc'] = $docData['tipo'];
        $comprobante['nro_doc'] = $docData['numero'];

        // IVA detallado si corresponde
        if ($importes['iva'] > 0 && !empty($importes['iva_detalle'])) {
            $comprobante['iva'] = $importes['iva_detalle'];
        }

        // 9) Solicitar CAE (o simular en demo)
        $cae = null;
        $caeVto = null;
        $estado = 'EMITIDA';

        if ($modoDemo) {
            // Modo demo: generar CAE ficticio
            $cae = 'DEMO' . str_pad((string)$numero, 14, '0', STR_PAD_LEFT);
            $caeVto = date('Ymd', strtotime('+10 days'));
        } else {
            // Modo producción: solicitar a AFIP
            require_once __DIR__ . '/../public/includes/ArcaWsfe.php';
            
            $resultado = ArcaWsfe::solicitarCAE($comprobante);
            
            // P1: Si falla con error 10016 (número duplicado), reintentar
            if (!$resultado) {
                $errorMsg = ArcaWsfe::getLastError() ?: '';
                
                // Error 10016 = "El número de comprobante ya fue registrado"
                if (strpos($errorMsg, '10016') !== false || stripos($errorMsg, 'ya fue') !== false) {
                    // Resincronizar número con AFIP
                    $ultimoAfip = ArcaWsfe::getUltimoAutorizado($puntoVenta, $tipoCbte);
                    if ($ultimoAfip !== null) {
                        $numero = $ultimoAfip + 1;
                        $comprobante['numero'] = $numero;
                        
                        // Reintentar una vez
                        $resultado = ArcaWsfe::solicitarCAE($comprobante);
                    }
                }
            }
            
            if (!$resultado) {
                throw new Exception("Error de AFIP: " . (ArcaWsfe::getLastError() ?: 'Error desconocido'));
            }

            $cae = $resultado['cae'];
            $caeVto = $resultado['vencimiento'];
            $numero = $resultado['numero']; // Por si AFIP lo ajustó
        }

        // 10) Insertar factura
        $st = $pdo->prepare("
            INSERT INTO facturas
              (venta_id, cliente_id, tipo, punto_venta, numero, 
               importe_neto, importe_iva, importe_exento, total, 
               cae, cae_vto, estado, modo, creado_en)
            VALUES
              (:venta_id, :cliente_id, :tipo, :punto_venta, :numero,
               :importe_neto, :importe_iva, :importe_exento, :total,
               :cae, :cae_vto, :estado, :modo, NOW())
        ");

        $tipoStr = obtenerNombreTipoComprobante($tipoCbte);

        $st->execute([
            ':venta_id' => $ventaId,
            ':cliente_id' => $clienteId ?: null,
            ':tipo' => $tipoStr,
            ':punto_venta' => $puntoVenta,
            ':numero' => $numero,
            ':importe_neto' => $importes['neto'],
            ':importe_iva' => $importes['iva'],
            ':importe_exento' => $importes['exento'],
            ':total' => $importes['total'],
            ':cae' => $cae,
            ':cae_vto' => $caeVto,
            ':estado' => $estado,
            ':modo' => $modoDemo ? 'demo' : 'produccion',
        ]);

        $facturaId = (int)$pdo->lastInsertId();

        // 11) Actualizar próximo número en config
        $st = $pdo->prepare("
            UPDATE config_facturacion
            SET proximo_numero = :nuevo
            WHERE id = :id
        ");
        $st->execute([
            ':nuevo' => $numero + 1,
            ':id' => $config['id'],
        ]);

        // 12) Marcar venta como facturada
        $st = $pdo->prepare("UPDATE ventas SET facturada = 1 WHERE id = ?");
        $st->execute([$ventaId]);

        $pdo->commit();
        return $facturaId;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Determina la condición de IVA del receptor
 */
function determinarCondIvaReceptor(?array $cliente): string
{
    if (!$cliente) {
        return 'CF'; // Consumidor final
    }

    $condIva = strtoupper(trim((string)($cliente['cond_iva'] ?? '')));
    
    // Mapear valores posibles
    $mapa = [
        'RI' => 'RI',
        'RESPONSABLE INSCRIPTO' => 'RI',
        'MT' => 'MT',
        'MONOTRIBUTISTA' => 'MT',
        'MONOTRIBUTO' => 'MT',
        'EX' => 'EX',
        'EXENTO' => 'EX',
        'CF' => 'CF',
        'CONSUMIDOR FINAL' => 'CF',
    ];

    return $mapa[$condIva] ?? 'CF';
}

/**
 * Determina el tipo de comprobante según condiciones de IVA
 */
function determinarTipoComprobante(string $condIvaEmisor, string $condIvaReceptor): int
{
    require_once __DIR__ . '/../public/includes/ArcaWsfe.php';
    return ArcaWsfe::determinarTipoComprobante($condIvaEmisor, $condIvaReceptor);
}

/**
 * Determina tipo y número de documento del cliente
 */
function determinarDocumentoCliente(?array $cliente): array
{
    require_once __DIR__ . '/../public/includes/ArcaWsfe.php';
    
    $cuit = $cliente['cuit'] ?? null;
    $dni = $cliente['dni'] ?? $cliente['documento'] ?? null;
    
    return ArcaWsfe::determinarTipoDocumento($cuit, $dni);
}

/**
 * Calcula los importes de la factura
 */
function calcularImportesFactura(PDO $pdo, int $ventaId, array $venta, int $tipoCbte): array
{
    $total = (float)($venta['total'] ?? 0);
    
    // Para Facturas C (monotributistas/exentos), no se discrimina IVA
    $esFacturaC = in_array($tipoCbte, [11, 12, 13]); // C, NC-C, ND-C
    
    if ($esFacturaC) {
        return [
            'total' => $total,
            'neto' => $total,
            'iva' => 0,
            'exento' => 0,
            'no_gravado' => 0,
            'iva_detalle' => [],
        ];
    }
    
    // Para Facturas A y B, discriminar IVA
    // Buscar si hay info de IVA en los items
    $st = $pdo->prepare("
        SELECT 
            COALESCE(p.iva_porcentaje, 21) as iva_porcentaje,
            SUM(vi.subtotal) as subtotal
        FROM venta_items vi
        LEFT JOIN productos p ON vi.producto_id = p.id
        WHERE vi.venta_id = ?
        GROUP BY COALESCE(p.iva_porcentaje, 21)
    ");
    $st->execute([$ventaId]);
    $ivaGroups = $st->fetchAll(PDO::FETCH_ASSOC);
    
    $neto = 0;
    $iva = 0;
    $ivaDetalle = [];
    
    if ($ivaGroups) {
        foreach ($ivaGroups as $group) {
            $pct = (float)$group['iva_porcentaje'];
            $subtotal = (float)$group['subtotal'];
            
            // El subtotal ya incluye IVA, hay que extraerlo
            $baseImp = $subtotal / (1 + $pct / 100);
            $impIva = $subtotal - $baseImp;
            
            $neto += $baseImp;
            $iva += $impIva;
            
            // ID de alícuota AFIP
            $idAlicuota = obtenerIdAlicuotaAfip($pct);
            
            $ivaDetalle[] = [
                'id' => $idAlicuota,
                'base' => round($baseImp, 2),
                'importe' => round($impIva, 2),
            ];
        }
    } else {
        // Fallback: asumir 21%
        $neto = $total / 1.21;
        $iva = $total - $neto;
        $ivaDetalle[] = [
            'id' => 5, // 21%
            'base' => round($neto, 2),
            'importe' => round($iva, 2),
        ];
    }
    
    // P0: Ajustar diferencia de redondeo para que ImpTotal = ImpNeto + ImpIVA + ...
    // AFIP es sensible a que los totales cuadren exactamente
    $neto = round($neto, 2);
    $iva = round($iva, 2);
    $exento = 0;
    $noGravado = 0;
    
    $calculado = round($neto + $iva + $exento + $noGravado, 2);
    $diferencia = round($total - $calculado, 2);
    
    // Si hay diferencia pequeña (≤ 0.02), ajustar al neto
    if (abs($diferencia) > 0 && abs($diferencia) <= 0.02) {
        $neto = round($neto + $diferencia, 2);
        // También ajustar el último detalle de IVA si existe
        if (!empty($ivaDetalle)) {
            $lastIdx = count($ivaDetalle) - 1;
            $ivaDetalle[$lastIdx]['base'] = round($ivaDetalle[$lastIdx]['base'] + $diferencia, 2);
        }
    }
    
    return [
        'total' => round($total, 2),
        'neto' => $neto,
        'iva' => $iva,
        'exento' => $exento,
        'no_gravado' => $noGravado,
        'iva_detalle' => $ivaDetalle,
    ];
}

/**
 * Obtiene el ID de alícuota AFIP según porcentaje
 */
function obtenerIdAlicuotaAfip(float $porcentaje): int
{
    // IDs de alícuota en AFIP (keys como string para evitar conversión float→int)
    $mapa = [
        '0.0'  => 3,   // 0%
        '2.5'  => 9,   // 2.5%
        '5.0'  => 8,   // 5%
        '10.5' => 4,   // 10.5%
        '21.0' => 5,   // 21%
        '27.0' => 6,   // 27%
    ];
    
    $key = sprintf('%.1f', round($porcentaje, 1));
    return $mapa[$key] ?? 5; // Default 21%
}

/**
 * Obtiene el nombre del tipo de comprobante
 */
function obtenerNombreTipoComprobante(int $tipo): string
{
    $nombres = [
        1 => 'FA',   // Factura A
        2 => 'NDA',  // Nota de Débito A
        3 => 'NCA',  // Nota de Crédito A
        6 => 'FB',   // Factura B
        7 => 'NDB',  // Nota de Débito B
        8 => 'NCB',  // Nota de Crédito B
        11 => 'FC',  // Factura C
        12 => 'NDC', // Nota de Débito C
        13 => 'NCC', // Nota de Crédito C
    ];
    
    return $nombres[$tipo] ?? 'FC';
}

/**
 * Verifica el estado de la conexión con AFIP
 */
function verificarConexionAfip(): array
{
    $resultado = [
        'conectado' => false,
        'mensaje' => '',
        'detalles' => [],
    ];

    // Verificar extensiones
    if (!extension_loaded('soap')) {
        $resultado['mensaje'] = 'Extensión SOAP no habilitada.';
        return $resultado;
    }
    if (!extension_loaded('openssl')) {
        $resultado['mensaje'] = 'Extensión OpenSSL no habilitada.';
        return $resultado;
    }

    // Verificar configuración
    $certPath = defined('FLUS_ARCA_CERT_PEM') ? FLUS_ARCA_CERT_PEM : '';
    $keyPath = defined('FLUS_ARCA_KEY_PEM') ? FLUS_ARCA_KEY_PEM : '';
    $cuit = defined('FLUS_ARCA_CUIT') ? FLUS_ARCA_CUIT : '';

    if ($certPath === '' || $keyPath === '') {
        $resultado['mensaje'] = 'Falta configurar certificado y clave (FLUS_ARCA_CERT_PEM, FLUS_ARCA_KEY_PEM).';
        return $resultado;
    }

    if (!file_exists($certPath)) {
        $resultado['mensaje'] = 'No se encuentra el certificado: ' . $certPath;
        return $resultado;
    }

    if (!file_exists($keyPath)) {
        $resultado['mensaje'] = 'No se encuentra la clave privada: ' . $keyPath;
        return $resultado;
    }

    if ($cuit === '') {
        $resultado['mensaje'] = 'Falta configurar CUIT del emisor (FLUS_ARCA_CUIT).';
        return $resultado;
    }

    // Intentar obtener TA
    require_once __DIR__ . '/../public/includes/ArcaWsaa.php';
    $ta = ArcaWsaa::getTA('wsfe');
    
    if (!$ta) {
        $resultado['mensaje'] = 'Error de autenticación: ' . (ArcaWsaa::getLastError() ?: 'Error desconocido');
        return $resultado;
    }

    $resultado['conectado'] = true;
    $resultado['mensaje'] = 'Conexión exitosa con AFIP/ARCA.';
    $resultado['detalles'] = [
        'token_expira' => date('Y-m-d H:i:s', $ta['expires_at']),
        'ambiente' => defined('FLUS_ARCA_ENV') ? FLUS_ARCA_ENV : 'prod',
    ];

    return $resultado;
}

/**
 * Obtiene los puntos de venta habilitados en AFIP
 */
function obtenerPuntosVentaAfip(): ?array
{
    require_once __DIR__ . '/../public/includes/ArcaWsfe.php';
    return ArcaWsfe::getPuntosVenta();
}