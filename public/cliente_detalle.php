<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/includes/ClienteController.php';
require_once __DIR__ . '/includes/CuentaCorrienteController.php';

require_login();
require_any_permission(['ver_clientes', 'editar_clientes']);

$controller = new ClienteController($pdo);
$clienteId = (int)($_GET['id'] ?? 0);

if ($clienteId <= 0) {
    header('Location: clientes.php');
    exit;
}

$cliente = $controller->getById($clienteId);
if (!$cliente) {
    header('Location: clientes.php');
    exit;
}

$canEditClientes = function_exists('user_has_permission') && user_has_permission('editar_clientes');
$canViewVentas = function_exists('user_has_permission') && user_has_permission('ver_reportes');
$canViewCuentaCorriente = function_exists('user_has_permission') && user_has_permission('ver_cuenta_corriente');
$canViewFacturacion = function_exists('user_has_permission') && (user_has_permission('ver_facturacion') || user_has_permission('emitir_factura'));
$facturacionHabilitada = config_get($pdo, 'facturacion_habilitada', '0') === '1';
$canViewFacturacion = $canViewFacturacion && $facturacionHabilitada;

$hasCC = $controller->hasColumnCC();
$hasTipo = $controller->hasColumn('tipo_cliente');
$hasDescuento = $controller->hasColumn('descuento_porcentaje');
$hasZona = $controller->hasColumn('zona_reparto');
$hasNotas = $controller->hasColumn('notas');
$hasDireccion = $controller->hasColumn('direccion');

$condIvaOptions = ClienteController::getCondIvaOptions();
$tipoOptions = ClienteController::getTipoClienteOptions();
$resumen = $controller->getRelacionResumen($clienteId);
$ultimasVentas = $canViewVentas ? $controller->getUltimasVentas($clienteId, 6) : [];
$ultimasFacturas = $canViewFacturacion ? $controller->getUltimasFacturas($clienteId, 6) : [];
$movimientosCC = [];
$movimientosCCTotal = 0;

if ($canViewCuentaCorriente && $hasCC && (int)($cliente['cc_habilitado'] ?? 0) === 1) {
    $ccController = new CuentaCorrienteController($pdo);
    $movimientosData = $ccController->getMovimientos($clienteId, [
        'page' => 1,
        'per_page' => 6,
        'incluir_anulados' => 1,
    ]);
    $movimientosCC = $movimientosData['movimientos'] ?? [];
    $movimientosCCTotal = (int)($movimientosData['total'] ?? 0);
}

$clienteNombre = trim((string)($cliente['nombre'] ?? 'Cliente'));
$clienteActivo = (int)($cliente['activo'] ?? 0) === 1;
$clienteCondIva = $condIvaOptions[(string)($cliente['cond_iva'] ?? '')] ?? ((string)($cliente['cond_iva'] ?? '') ?: 'Sin especificar');
$clienteTipo = $hasTipo ? ($tipoOptions[(string)($cliente['tipo_cliente'] ?? 'MINORISTA')] ?? 'Minorista') : null;
$clienteDescuento = $hasDescuento ? (float)($cliente['descuento_porcentaje'] ?? 0) : 0.0;
$ccSaldo = (float)($cliente['cc_saldo'] ?? 0);
$ccLimite = (float)($cliente['cc_limite'] ?? 0);
$ccDisponible = $ccLimite - $ccSaldo;
$ccHabilitada = $hasCC && (int)($cliente['cc_habilitado'] ?? 0) === 1;

$pageTitle = 'Cliente: ' . $clienteNombre . ' - FLUS';
$currentSection = 'clientes';
$bodyClass = 'cliente-detalle-page';
$extraCss = ['assets/css/cliente_detalle.css'];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap cliente-detail-page">
    <div class="panel cliente-detail-shell">
        <header class="cliente-detail-hero">
            <div class="cliente-detail-hero__main">
                <a href="clientes.php" class="cliente-detail-back">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                    Volver a clientes
                </a>

                <div class="cliente-detail-header">
                    <div class="cliente-detail-avatar" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                        </svg>
                    </div>

                    <div class="cliente-detail-copy">
                        <span class="cliente-detail-eyebrow">Ficha de cliente</span>
                        <h1><?= h($clienteNombre) ?></h1>
                        <p>Resumen comercial, fiscal y de credito del cliente dentro de FLUS.</p>

                        <div class="cliente-detail-tags">
                            <span class="tag <?= $clienteActivo ? 'tag-ok' : 'tag-inactivo' ?>">
                                <?= $clienteActivo ? 'Activo' : 'Inactivo' ?>
                            </span>
                            <span class="tag"><?= h($clienteCondIva) ?></span>
                            <?php if ($clienteTipo !== null): ?>
                                <span class="tag"><?= h($clienteTipo) ?></span>
                            <?php endif; ?>
                            <?php if ($ccHabilitada): ?>
                                <span class="tag tag-cc">Cuenta corriente</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cliente-detail-actions">
                <?php if ($canEditClientes): ?>
                    <a href="clientes.php?editar=<?= $clienteId ?>" class="btn btn-primary">Editar cliente</a>
                <?php endif; ?>
                <?php if ($canViewVentas): ?>
                    <a href="ventas.php?cliente_id=<?= $clienteId ?>" class="btn btn-secondary">Ver ventas</a>
                <?php endif; ?>
                <?php if ($canViewFacturacion): ?>
                    <a href="facturacion.php?cliente_id=<?= $clienteId ?>" class="btn btn-secondary">Ver facturacion</a>
                <?php endif; ?>
                <?php if ($canViewCuentaCorriente && $ccHabilitada): ?>
                    <a href="cuenta_corriente_cliente.php?id=<?= $clienteId ?>" class="btn btn-secondary">Ver cuenta corriente</a>
                <?php endif; ?>
            </div>
        </header>

        <section class="cliente-detail-grid">
            <article class="cliente-card cliente-card--identity">
                <h2>Datos base</h2>
                <dl class="cliente-kv">
                    <div>
                        <dt>CUIT</dt>
                        <dd><?= h((string)($cliente['cuit'] ?? 'Sin cargar')) ?></dd>
                    </div>
                    <?php if (!empty($cliente['email'])): ?>
                        <div>
                            <dt>Email</dt>
                            <dd><?= h((string)$cliente['email']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($cliente['telefono'])): ?>
                        <div>
                            <dt>Telefono</dt>
                            <dd><?= h((string)$cliente['telefono']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($hasDireccion && !empty($cliente['direccion'])): ?>
                        <div class="cliente-kv--wide">
                            <dt>Direccion</dt>
                            <dd><?= h((string)$cliente['direccion']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($hasZona && !empty($cliente['zona_reparto'])): ?>
                        <div>
                            <dt>Zona</dt>
                            <dd><?= h((string)$cliente['zona_reparto']) ?></dd>
                        </div>
                    <?php endif; ?>
                    <?php if ($hasDescuento): ?>
                        <div>
                            <dt>Descuento</dt>
                            <dd><?= $clienteDescuento > 0 ? h(number_format($clienteDescuento, 0)) . '%' : 'Sin descuento' ?></dd>
                        </div>
                    <?php endif; ?>
                </dl>

                <?php if ($hasNotas && !empty($cliente['notas'])): ?>
                    <div class="cliente-notes">
                        <span class="cliente-notes__label">Notas internas</span>
                        <p><?= nl2br(h((string)$cliente['notas'])) ?></p>
                    </div>
                <?php endif; ?>
            </article>

            <article class="cliente-card cliente-card--metric">
                <h2>Ventas</h2>
                <div class="cliente-metric-main"><?= (int)($resumen['ventas']['total'] ?? 0) ?></div>
                <p><?= money_ar((float)($resumen['ventas']['total_facturado'] ?? 0)) ?> acumulado</p>
                <small>
                    <?php if (!empty($resumen['ventas']['ultima_fecha'])): ?>
                        Ultima venta: <?= h(date('d/m/Y H:i', strtotime((string)$resumen['ventas']['ultima_fecha']))) ?>
                    <?php else: ?>
                        Sin ventas registradas
                    <?php endif; ?>
                </small>
            </article>

            <article class="cliente-card cliente-card--metric">
                <h2>Facturacion</h2>
                <div class="cliente-metric-main"><?= (int)($resumen['facturas']['total'] ?? 0) ?></div>
                <p><?= $canViewFacturacion ? 'Comprobantes asociados' : 'Sin acceso al modulo' ?></p>
                <small>
                    <?php if ($canViewFacturacion && !empty($resumen['facturas']['ultima_fecha'])): ?>
                        Ultima factura: <?= h(date('d/m/Y H:i', strtotime((string)$resumen['facturas']['ultima_fecha']))) ?>
                    <?php elseif ($canViewFacturacion): ?>
                        Todavia no hay comprobantes
                    <?php else: ?>
                        El usuario no tiene permisos de facturacion
                    <?php endif; ?>
                </small>
            </article>

            <article class="cliente-card cliente-card--metric <?= $ccHabilitada ? 'cliente-card--cc' : '' ?>">
                <h2>Cuenta corriente</h2>
                <div class="cliente-metric-money"><?= $ccHabilitada ? money_ar($ccSaldo) : 'No activa' ?></div>
                <p>
                    <?php if ($ccHabilitada): ?>
                        Limite <?= money_ar($ccLimite) ?> · Disponible <?= money_ar($ccDisponible) ?>
                    <?php else: ?>
                        Se habilita desde la edicion del cliente
                    <?php endif; ?>
                </p>
                <small>
                    <?php if ($ccHabilitada && !empty($cliente['cc_fecha_ultimo_pago'])): ?>
                        Ultimo pago: <?= h(date('d/m/Y', strtotime((string)$cliente['cc_fecha_ultimo_pago']))) ?>
                    <?php elseif ($ccHabilitada): ?>
                        Sin pagos registrados
                    <?php else: ?>
                        No participa del flujo de credito
                    <?php endif; ?>
                </small>
            </article>
        </section>

        <?php if ($canViewVentas || $canViewFacturacion || $canViewCuentaCorriente): ?>
        <section class="cliente-activity-grid">
            <?php if ($canViewVentas): ?>
                <article class="cliente-activity-card">
                    <div class="cliente-activity-head">
                        <h2>Ultimas ventas</h2>
                        <span><?= count($ultimasVentas) ?> de <?= (int)($resumen['ventas']['total'] ?? 0) ?></span>
                    </div>

                    <?php if ($ultimasVentas): ?>
                        <div class="cliente-activity-list">
                            <?php foreach ($ultimasVentas as $venta): ?>
                                <div class="cliente-activity-row">
                                    <div>
                                        <strong>Venta #<?= (int)$venta['id'] ?></strong>
                                        <small>
                                            <?= !empty($venta['fecha']) ? h(date('d/m/Y H:i', strtotime((string)$venta['fecha']))) : 'Sin fecha' ?>
                                            <?= !empty($venta['medio_pago']) ? '· ' . h((string)$venta['medio_pago']) : '' ?>
                                        </small>
                                    </div>
                                    <div class="cliente-activity-meta">
                                        <span class="cliente-state <?= strtoupper((string)($venta['estado'] ?? 'EMITIDA')) === 'ANULADA' ? 'is-danger' : 'is-ok' ?>">
                                            <?= h((string)($venta['estado'] ?? 'EMITIDA')) ?>
                                        </span>
                                        <strong><?= money_ar((float)($venta['total'] ?? 0)) ?></strong>
                                        <a href="venta_detalle.php?id=<?= (int)$venta['id'] ?>">Abrir</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="cliente-empty-state">Todavia no hay ventas asociadas a este cliente.</div>
                    <?php endif; ?>
                </article>
            <?php endif; ?>

            <?php if ($canViewFacturacion): ?>
                <article class="cliente-activity-card">
                    <div class="cliente-activity-head">
                        <h2>Ultimas facturas</h2>
                        <span><?= count($ultimasFacturas) ?> de <?= (int)($resumen['facturas']['total'] ?? 0) ?></span>
                    </div>

                    <?php if ($ultimasFacturas): ?>
                        <div class="cliente-activity-list">
                            <?php foreach ($ultimasFacturas as $factura): ?>
                                <?php
                                $numeroFactura = $factura['numero'] !== null ? (int)$factura['numero'] : null;
                                $puntoVenta = $factura['punto_venta'] !== null ? (int)$factura['punto_venta'] : null;
                                $comprobante = trim((string)($factura['tipo'] ?? 'Factura'));
                                if ($numeroFactura !== null && $puntoVenta !== null) {
                                    $comprobante .= ' ' . sprintf('%04d-%08d', $puntoVenta, $numeroFactura);
                                } elseif ($numeroFactura !== null) {
                                    $comprobante .= ' #' . $numeroFactura;
                                }
                                ?>
                                <div class="cliente-activity-row">
                                    <div>
                                        <strong><?= h($comprobante) ?></strong>
                                        <small>
                                            <?= !empty($factura['fecha']) ? h(date('d/m/Y H:i', strtotime((string)$factura['fecha']))) : 'Sin fecha' ?>
                                            <?= !empty($factura['cae']) ? '· CAE ' . h((string)$factura['cae']) : '' ?>
                                        </small>
                                    </div>
                                    <div class="cliente-activity-meta">
                                        <span class="cliente-state <?= strtoupper((string)($factura['estado'] ?? 'EMITIDA')) === 'ANULADA' ? 'is-danger' : 'is-ok' ?>">
                                            <?= h((string)($factura['estado'] ?? 'EMITIDA')) ?>
                                        </span>
                                        <strong><?= money_ar((float)($factura['total'] ?? 0)) ?></strong>
                                        <a href="factura_ver.php?id=<?= (int)$factura['id'] ?>">Abrir</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="cliente-empty-state">Todavia no hay comprobantes emitidos para este cliente.</div>
                    <?php endif; ?>
                </article>
            <?php endif; ?>

            <?php if ($canViewCuentaCorriente): ?>
                <article class="cliente-activity-card">
                    <div class="cliente-activity-head">
                        <h2>Movimientos CC</h2>
                        <span><?= $movimientosCCTotal ?> registrados</span>
                    </div>

                    <?php if ($ccHabilitada && $movimientosCC): ?>
                        <div class="cliente-activity-list">
                            <?php foreach ($movimientosCC as $mov): ?>
                                <?php
                                $tipoMov = (string)($mov['tipo'] ?? '-');
                                $estadoMov = (string)($mov['estado'] ?? 'ACTIVO');
                                ?>
                                <div class="cliente-activity-row">
                                    <div>
                                        <strong><?= h($tipoMov) ?> #<?= (int)($mov['id'] ?? 0) ?></strong>
                                        <small>
                                            <?= !empty($mov['created_at']) ? h(date('d/m/Y H:i', strtotime((string)$mov['created_at']))) : 'Sin fecha' ?>
                                            <?= !empty($mov['concepto']) ? '· ' . h((string)$mov['concepto']) : '' ?>
                                        </small>
                                    </div>
                                    <div class="cliente-activity-meta">
                                        <span class="cliente-state <?= strtoupper($estadoMov) === 'ANULADO' ? 'is-danger' : 'is-ok' ?>">
                                            <?= h($estadoMov) ?>
                                        </span>
                                        <strong><?= money_ar((float)($mov['monto'] ?? 0)) ?></strong>
                                        <a href="cuenta_corriente_cliente.php?id=<?= $clienteId ?>#mov-<?= (int)($mov['id'] ?? 0) ?>">Abrir</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php elseif ($ccHabilitada): ?>
                        <div class="cliente-empty-state">La cuenta corriente esta activa, pero aun no tiene movimientos.</div>
                    <?php else: ?>
                        <div class="cliente-empty-state">Este cliente no tiene cuenta corriente habilitada.</div>
                    <?php endif; ?>
                </article>
            <?php endif; ?>
        </section>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
