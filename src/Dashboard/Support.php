<?php
declare(strict_types=1);

if (!function_exists('dashboard_kpi_tooltips')) {
  function dashboard_kpi_tooltips(): array {
    return [
      'ventas' => [
        'title' => 'Que son las ventas?',
        'desc' => 'Numero total de tickets o transacciones completadas en el periodo seleccionado.',
        'calc' => 'Cuenta de ventas con estado EMITIDA',
        'tip' => 'Compara con periodos anteriores para identificar tendencias. Un aumento constante indica crecimiento saludable.',
      ],
      'facturacion' => [
        'title' => 'Que es la facturacion?',
        'desc' => 'Suma total del dinero recibido por todas las ventas. Incluye todos los medios de pago.',
        'calc' => 'Suma del total de cada venta emitida',
        'tip' => 'Este es tu ingreso bruto. Para conocer la ganancia real, revisa el analisis de rentabilidad.',
      ],
      'ticket_promedio' => [
        'title' => 'Que es el ticket promedio?',
        'desc' => 'Cuanto gasta en promedio cada cliente por compra. Indica el valor tipico de una transaccion.',
        'calc' => 'Facturacion / numero de ventas',
        'tip' => 'Para aumentarlo: ofrece productos complementarios, promociones por monto minimo o combos.',
      ],
      'unidades' => [
        'title' => 'Que son las unidades vendidas?',
        'desc' => 'Cantidad total de productos vendidos, sumando todas las lineas de venta.',
        'calc' => 'Suma de la cantidad de cada linea de venta',
        'tip' => 'Util para planificar reposicion de stock y detectar productos estrella.',
      ],
      'ganancia' => [
        'title' => 'Que es la ganancia bruta?',
        'desc' => 'Diferencia entre lo que vendiste y lo que te costo la mercaderia. Es tu utilidad antes de gastos operativos.',
        'calc' => 'Facturacion - costo de mercaderia vendida',
        'tip' => 'Si es negativa, estas vendiendo por debajo del costo. Revisa precios urgentemente.',
      ],
      'margen' => [
        'title' => 'Que es el margen?',
        'desc' => 'Porcentaje de cada peso vendido que queda como ganancia. Indica que tan rentable es tu operacion.',
        'calc' => '(Ganancia / facturacion) * 100',
        'tip' => 'Un margen del 30 al 40 por ciento es saludable para retail. Menos del 20 por ciento puede ser problematico.',
      ],
      'costos' => [
        'title' => 'Que es el total de costos?',
        'desc' => 'Suma de lo que pagaste por la mercaderia que vendiste.',
        'calc' => 'Suma de cantidad por costo unitario de productos vendidos',
        'tip' => 'Manten actualizados los costos de tus productos para que este calculo sea preciso.',
      ],
      'descuentos' => [
        'title' => 'Que son los descuentos por promos?',
        'desc' => 'Total de dinero descontado a clientes por promociones activas.',
        'calc' => 'Suma de descuentos aplicados por promociones',
        'tip' => 'Monitorea que las promos generen mas ventas de las que cuestan en descuentos.',
      ],
      'anulaciones' => [
        'title' => 'Que son las ventas anuladas?',
        'desc' => 'Ventas que se cancelaron o revirtieron despues de emitirse.',
        'calc' => 'Cuenta de ventas con estado ANULADA',
        'tip' => 'Una tasa mayor al 5 por ciento indica problemas. Investiga las causas: errores, devoluciones, etc.',
      ],
      'tasa_anulacion' => [
        'title' => 'Que es la tasa de anulacion?',
        'desc' => 'Porcentaje de ventas que terminaron siendo anuladas respecto al total.',
        'calc' => '(Anuladas / total de ventas) * 100',
        'tip' => 'Menos del 2 por ciento es excelente, 2 a 5 por ciento es aceptable, mas del 5 por ciento requiere atencion.',
      ],
      'monto_anulado' => [
        'title' => 'Que es el monto anulado?',
        'desc' => 'Suma del valor de todas las ventas que fueron anuladas.',
        'calc' => 'Suma del total de ventas anuladas',
        'tip' => 'Representa dinero que esperabas recibir pero no se concreto.',
      ],
    ];
  }
}
