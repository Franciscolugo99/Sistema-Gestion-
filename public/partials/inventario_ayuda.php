<?php
declare(strict_types=1);
/**
 * inventario_ayuda.php
 * Componentes de ayuda contextual para el módulo de Análisis de Inventario
 * 
 * Este archivo provee:
 * - Tooltips explicativos para métricas
 * - Panel de acciones recomendadas
 * - Glosario para comerciantes
 * 
 * @version 1.0.0
 */

/**
 * Definiciones de ayuda para cada métrica
 * Orientado a dueños de kioscos/supermercados
 */
$AYUDA_METRICAS = [
    'capital_invertido' => [
        'titulo' => 'Capital Invertido',
        'descripcion' => 'Es la suma de dinero que tenés "parado" en mercadería. Se calcula multiplicando el costo de compra de cada producto por su stock actual.',
        'ejemplo' => 'Si tenés 10 unidades de un producto que te costó $500 cada uno, tenés $5.000 invertidos en ese producto.',
        'accion' => 'Un capital muy alto puede significar exceso de stock. Un capital bajo puede indicar que necesitás reponer.',
        'icono' => '💰'
    ],
    
    'valor_venta' => [
        'titulo' => 'Valor de Venta Potencial',
        'descripcion' => 'Es lo que ganarías si vendieras TODO el stock actual a precio de venta. Es el valor teórico máximo de tu mercadería.',
        'ejemplo' => 'Si tenés 10 productos a $800 cada uno, tu valor de venta potencial es $8.000.',
        'accion' => 'Compará esto con tu capital invertido para ver tu ganancia potencial.',
        'icono' => '📈'
    ],
    
    'margen_teorico' => [
        'titulo' => 'Margen Teórico',
        'descripcion' => 'Es la diferencia entre el Valor de Venta y el Capital Invertido. Representa la ganancia que tendrías si vendieras todo al precio actual.',
        'ejemplo' => 'Si invertiste $100.000 y el valor de venta es $150.000, tu margen teórico es $50.000 (50%).',
        'accion' => 'Un margen bajo puede indicar que necesitás revisar tus precios de venta.',
        'advertencia' => 'Se llama "teórico" porque asume que vendés todo sin descuentos ni pérdidas.',
        'icono' => '📊'
    ],
    
    'productos_parados' => [
        'titulo' => 'Productos Parados',
        'descripcion' => 'Son productos que tenés en stock pero que NO se vendieron en los últimos 30 días (o el período que elijas).',
        'ejemplo' => 'Si compraste 20 unidades de un producto hace 2 meses y no vendiste ninguno, es un producto "parado".',
        'accion' => '¡Atención! Este capital está "dormido". Considerá hacer ofertas, combos, o revisar si el precio está muy alto.',
        'icono' => '😴'
    ],
    
    'clasificacion_abc' => [
        'titulo' => 'Clasificación ABC',
        'descripcion' => 'Sistema que agrupa tus productos según cuánto venden:',
        'detalle' => [
            'A' => 'Productos ESTRELLA: Los más vendidos, generan el 80% de tus ingresos. ¡Nunca te quedes sin stock de estos!',
            'B' => 'Productos REGULARES: Venta media, generan el 15% de ingresos. Mantené un stock moderado.',
            'C' => 'Productos de BAJA ROTACIÓN: Pocas ventas, generan solo el 5% de ingresos. Evaluá si conviene seguir comprándolos.'
        ],
        'accion' => 'Enfocá tu atención y dinero en los productos "A". Los productos "C" pueden estar ocupando espacio y capital innecesariamente.',
        'icono' => '🏆'
    ],
    
    'dias_stock_restante' => [
        'titulo' => 'Días de Stock Restante',
        'descripcion' => 'Estimación de cuántos días te queda stock de un producto, basado en el promedio de ventas diarias.',
        'ejemplo' => 'Si vendés 3 unidades por día y tenés 30 en stock, te quedan aproximadamente 10 días de stock.',
        'accion' => 'Si ves menos de 7 días, ¡es hora de hacer el pedido al proveedor!',
        'advertencia' => 'Es una estimación. Si las ventas cambian (fin de semana, promociones), el cálculo puede variar.',
        'icono' => '⏰'
    ],
    
    'stock_bajo' => [
        'titulo' => 'Stock Bajo Mínimo',
        'descripcion' => 'Productos donde el stock actual está por debajo del mínimo que vos definiste. ¡Necesitan reposición urgente!',
        'ejemplo' => 'Si definiste que siempre querés tener mínimo 10 unidades de Coca-Cola y tenés 3, aparece acá.',
        'accion' => 'Hacé el pedido al proveedor lo antes posible para no perder ventas.',
        'icono' => '🔴'
    ],
    
    'proximos_agotarse' => [
        'titulo' => 'Próximos a Agotarse',
        'descripcion' => 'Productos que según las ventas actuales, se van a acabar en los próximos 7 días.',
        'ejemplo' => 'Aunque tengas stock suficiente hoy, si vendés mucho, se puede acabar pronto.',
        'accion' => 'Anticipate y hacé el pedido antes de quedarte sin stock.',
        'icono' => '⚠️'
    ],
    
    'cantidad_reponer' => [
        'titulo' => 'Cantidad a Reponer',
        'descripcion' => 'Sugerencia de cuántas unidades deberías comprar para cubrir la demanda de los próximos 7 días más el stock mínimo.',
        'ejemplo' => 'Si vendés 5 por día y tu mínimo es 10, te sugerimos comprar al menos 35 + 10 = 45 unidades.',
        'accion' => 'Es una guía, ajustala según tu criterio y relación con el proveedor.',
        'icono' => '🛒'
    ],
    
    'rotacion' => [
        'titulo' => 'Rotación de Productos',
        'descripcion' => 'Muestra qué tan rápido se vende cada producto. Alta rotación = vende mucho. Baja rotación = vende poco.',
        'ejemplo' => 'Las gaseosas suelen tener alta rotación. Productos de limpieza suelen tener menor rotación.',
        'accion' => 'Los productos de alta rotación necesitan más atención en el stock. Los de baja rotación ocupan espacio y capital.',
        'icono' => '🔄'
    ],
    
    'inversion_categoria' => [
        'titulo' => 'Inversión por Categoría',
        'descripcion' => 'Te muestra cuánto dinero tenés invertido en cada rubro (bebidas, golosinas, limpieza, etc.).',
        'accion' => 'Te ayuda a ver si estás equilibrado o si tenés demasiado capital en una sola categoría.',
        'icono' => '📁'
    ],
    
    'inversion_proveedor' => [
        'titulo' => 'Inversión por Proveedor',
        'descripcion' => 'Te muestra cuánto dinero tenés invertido en mercadería de cada proveedor.',
        'accion' => 'Útil para negociar mejores condiciones con proveedores donde comprás más.',
        'icono' => '🏭'
    ],
];

/**
 * Genera el HTML de un tooltip de ayuda
 */
function renderTooltipAyuda(string $clave): string {
    global $AYUDA_METRICAS;
    
    if (!isset($AYUDA_METRICAS[$clave])) {
        return '';
    }
    
    $ayuda = $AYUDA_METRICAS[$clave];
    $id = 'help-' . $clave;
    
    return sprintf(
        '<button type="button" class="inv-help-btn" data-help="%s" aria-label="Ayuda sobre %s">
            <span class="inv-help-icon">?</span>
        </button>',
        htmlspecialchars($clave),
        htmlspecialchars($ayuda['titulo'])
    );
}

/**
 * Genera el HTML del modal/drawer de ayuda
 */
function renderModalAyuda(): string {
    global $AYUDA_METRICAS;
    
    $html = '<div id="inv-help-modal" class="inv-help-modal" aria-hidden="true">
        <div class="inv-help-backdrop"></div>
        <div class="inv-help-drawer">
            <button type="button" class="inv-help-close" aria-label="Cerrar">&times;</button>
            <div class="inv-help-content" id="inv-help-content">
                <!-- Contenido dinámico -->
            </div>
        </div>
    </div>';
    
    // Datos para JS
    $html .= '<script>
    window.FLUS_AYUDA_METRICAS = ' . json_encode($AYUDA_METRICAS, JSON_UNESCAPED_UNICODE) . ';
    </script>';
    
    return $html;
}

/**
 * Genera panel de acciones recomendadas basado en los datos
 */
function renderAccionesRecomendadas(array $resumen, array $stockBajo, array $parados): string {
    $acciones = [];
    
    // Verificar stock bajo
    if (count($stockBajo) > 0) {
        $acciones[] = [
            'prioridad' => 'alta',
            'icono' => '🔴',
            'titulo' => count($stockBajo) . ' productos bajo mínimo',
            'descripcion' => 'Necesitan reposición urgente para no perder ventas.',
            'link' => '?tab=alertas',
            'accion' => 'Ver productos'
        ];
    }
    
    // Verificar productos parados
    $capitalParado = array_sum(array_column($parados, 'capital_parado'));
    if ($capitalParado > 0) {
        $acciones[] = [
            'prioridad' => 'media',
            'icono' => '😴',
            'titulo' => '$' . number_format($capitalParado, 0, ',', '.') . ' en productos sin vender',
            'descripcion' => count($parados) . ' productos no se vendieron en 30+ días. Considerá hacer ofertas.',
            'link' => '?tab=parados',
            'accion' => 'Revisar'
        ];
    }
    
    // Verificar productos sin costo
    if (($resumen['productos_sin_costo'] ?? 0) > 0) {
        $acciones[] = [
            'prioridad' => 'baja',
            'icono' => '⚠️',
            'titulo' => $resumen['productos_sin_costo'] . ' productos sin costo cargado',
            'descripcion' => 'No podemos calcular el margen real sin conocer el costo.',
            'link' => 'productos.php',
            'accion' => 'Cargar costos'
        ];
    }
    
    // Si no hay acciones
    if (empty($acciones)) {
        return '<div class="inv-acciones-panel inv-acciones-ok">
            <div class="inv-acciones-header">
                <span class="inv-acciones-icon">✅</span>
                <h3>¡Todo en orden!</h3>
            </div>
            <p>No hay alertas urgentes en este momento. Tu inventario está saludable.</p>
        </div>';
    }
    
    // Renderizar acciones
    $html = '<div class="inv-acciones-panel">
        <div class="inv-acciones-header">
            <span class="inv-acciones-icon">📋</span>
            <h3>Acciones Recomendadas</h3>
            <span class="inv-acciones-badge">' . count($acciones) . '</span>
        </div>
        <div class="inv-acciones-list">';
    
    foreach ($acciones as $accion) {
        $prioridadClass = 'inv-accion-' . $accion['prioridad'];
        $html .= sprintf(
            '<div class="inv-accion-item %s">
                <span class="inv-accion-icon">%s</span>
                <div class="inv-accion-content">
                    <strong>%s</strong>
                    <p>%s</p>
                </div>
                <a href="%s" class="btn btn-sm btn-primary">%s</a>
            </div>',
            $prioridadClass,
            $accion['icono'],
            htmlspecialchars($accion['titulo']),
            htmlspecialchars($accion['descripcion']),
            htmlspecialchars($accion['link']),
            htmlspecialchars($accion['accion'])
        );
    }
    
    $html .= '</div></div>';
    
    return $html;
}

/**
 * Genera el glosario completo para comerciantes
 */
function renderGlosario(): string {
    global $AYUDA_METRICAS;
    
    $html = '<div class="inv-glosario">
        <div class="inv-glosario-header">
            <h3>📚 Glosario: ¿Qué significa cada cosa?</h3>
            <p class="inv-glosario-sub">Explicaciones simples de cada métrica para entender mejor tu negocio.</p>
        </div>
        <div class="inv-glosario-grid">';
    
    foreach ($AYUDA_METRICAS as $clave => $ayuda) {
        $html .= sprintf(
            '<div class="inv-glosario-item">
                <div class="inv-glosario-icon">%s</div>
                <div class="inv-glosario-content">
                    <h4>%s</h4>
                    <p>%s</p>
                    %s
                </div>
            </div>',
            $ayuda['icono'],
            htmlspecialchars($ayuda['titulo']),
            htmlspecialchars($ayuda['descripcion']),
            isset($ayuda['accion']) ? '<div class="inv-glosario-tip">💡 <em>' . htmlspecialchars($ayuda['accion']) . '</em></div>' : ''
        );
    }
    
    $html .= '</div></div>';
    
    return $html;
}
