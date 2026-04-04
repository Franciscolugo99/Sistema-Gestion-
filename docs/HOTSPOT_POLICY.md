# Politica de hotspots y particion incremental

Fecha de corte: 2026-04-03

## Objetivo

Frenar el crecimiento accidental de archivos gigantes sin abrir una reescritura masiva.

La regla es simple:

- si un archivo operativo supera las `800` lineas, entra en zona de alerta
- si supera las `1000` lineas, entra en plan obligatorio de particion
- si ya esta por encima de ese umbral, no puede seguir creciendo sin extraccion paralela

## Archivos actualmente bajo control

Estos archivos quedan con presupuesto explicito en `tests/smoke.php` para evitar regresiones estructurales:

- `src/facturacion_lib.php`: maximo `2000` lineas
- `src/facturacion_manual_lib.php`: maximo `1350` lineas
- `public/includes/CuentaCorrienteController.php`: maximo `1550` lineas
- `public/productos.php`: maximo `1850` lineas
- `public/compras.php`: maximo `1650` lineas
- `public/assets/js/caja.js`: maximo `3850` lineas
- `public/api/index.php`: maximo `675` lineas
- `public/bootstrap.php`: maximo `350` lineas

Los dos ultimos quedan controlados aunque hoy ya bajaron, porque no queremos que vuelvan al estado de monolito.

Los budgets siguen el conteo fisico que hace PHP dentro del smoke, para evitar diferencias entre editores o herramientas del sistema.

Ultimo avance visible:

- `src/facturacion_lib.php` ya empezo a descargar helpers comunes en `src/facturacion_runtime_lib.php`
- `src/facturacion_lib.php` ya descargo preflight y estado ARCA en `src/facturacion_preflight_lib.php`
- `src/facturacion_lib.php` ya descargo helpers de contexto/payload en `src/facturacion_context_lib.php`

## Regla de trabajo

Cuando haya que tocar un hotspot:

- agregar logica nueva en un archivo extraido siempre que sea posible
- dejar el hotspot como fachada, router, orquestador o compat layer
- aprovechar el cambio para mover validaciones, helpers, render o acceso a datos fuera del archivo grande
- si por una urgencia el archivo necesita crecer, compensarlo con extraccion en el mismo cambio o en el inmediatamente siguiente

## Que extraer primero

Orden sugerido de extraccion dentro de cada hotspot:

- validaciones y normalizacion de input
- helpers de persistencia o queries
- mapping de respuestas o render UI
- reglas de negocio reutilizables
- utilidades compartidas entre pantalla legacy y API

## Criterio de cierre de una extraccion

Una particion cuenta como mejora real cuando:

- el archivo principal deja de absorber responsabilidad nueva
- la pieza extraida tiene nombre y responsabilidad claros
- la compatibilidad legacy sigue intacta
- el smoke sigue pasando

## Excepciones

Si un cambio urgente obliga a superar un presupuesto:

- dejar nota en el commit o changelog tecnico
- abrir la extraccion compensatoria en el mismo frente de trabajo
- volver a bajar el archivo antes de cerrar la etapa
