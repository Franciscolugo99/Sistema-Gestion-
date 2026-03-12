# Legacy / API Inventory

## Panorama actual

- Paginas PHP en `public/`: 116
- Endpoints PHP en `public/api/`: 29
- No hay una separacion completa entre UI legacy, endpoints JSON y servicios de dominio compartidos.

## Dominios con mayor riesgo de duplicacion

### Alta prioridad

#### Ventas

UI / legacy:

- `public/ventas.php`
- `public/venta_detalle.php`
- `public/ticket.php`
- `public/ticket_publico.php`

API / acciones:

- `public/api/ventas_api.php`
- `public/api/actions/anular_venta.php`
- `public/api/ventas_kpis.php`

Riesgo:

- reglas de estado, anulacion, links publicos, KPIs y tickets repartidos

Avance marzo 2026:

- detalle, ticket publico, anulación y estadisticas base ya comparten helpers de estado y criterio de ventas emitidas

#### Usuarios, roles y permisos

UI / legacy:

- `public/usuarios.php`
- `public/usuario_nuevo.php`
- `public/usuario_guardar.php`
- `public/usuario_editar.php`
- `public/roles.php`
- `public/rol_guardar.php`
- `public/rol_permisos.php`

API:

- `public/api/usuario_toggle_estado.php`
- `public/api/usuario_eliminar.php`
- `public/api/rol_eliminar.php`

Riesgo:

- reglas de ultimo admin y permisos sensibles duplicadas entre UI/API

Avance marzo 2026:

- altas, edicion, toggle, eliminacion y roles criticos ya usan helpers compartidos para validacion y guards

#### Backups y diagnostico

UI / legacy:

- `public/backups.php`
- `public/diagnostico.php`
- `public/diagnostico_download.php`

API:

- `public/api/system_api.php`

Servicios:

- `src/backup_lib.php`
- `src/diagnostics_lib.php`

Riesgo:

- dos superficies para acciones sensibles y soporte tecnico

### Prioridad media

#### Productos, precios y promos

UI / legacy:

- `public/productos.php`
- `public/precios_historial.php`
- `public/promos.php`
- `public/promo_form.php`
- `public/promo_combo_form.php`
- `public/promo_delete.php`

API:

- `public/api/precios_api.php`
- `public/api/promo_actualizar.php`
- `public/api/promo_eliminar.php`
- `public/api/promo_obtener.php`
- `public/api/promo_productos.php`
- `public/api/actions/buscar_productos.php`

Riesgo:

- activacion, historial, promociones y consultas con logica repartida

#### Cuenta corriente y clientes

UI / legacy:

- `public/clientes.php`
- `public/cuenta_corriente.php`
- `public/cuenta_corriente_cliente.php`
- `public/cuenta_corriente_print.php`

API:

- `public/api/cuenta_corriente_api.php`
- `public/api/actions/buscar_clientes_cc.php`
- `public/api/actions/verificar_cc.php`
- `public/api/actions/cliente_consultar_cuit.php`

Riesgo:

- validaciones de clientes y saldo repartidas

#### Inventario, stock y reposicion

UI / legacy:

- `public/stock.php`
- `public/inventario_fisico.php`
- `public/inventario_analisis.php`
- `public/reposicion.php`
- `public/movimientos.php`

API:

- `public/api/inventario_api.php`
- `public/stock_ajax.php`

Riesgo:

- consultas y exportes mezclados con calculos operativos

### Prioridad media / baja

#### Compras y proveedores

UI / legacy:

- `public/compras.php`
- `public/proveedores.php`

API:

- `public/api/compra_detalle.php`

#### Caja

UI / legacy:

- `public/caja.php`
- `public/caja_cerrar.php`
- `public/caja_movimientos.php`
- `public/caja_historial.php`
- `public/caja_sesion_detalle.php`

API:

- no aparece una capa API consolidada equivalente

Riesgo:

- mucho flujo de operacion todavia montado directo sobre paginas

## Recomendacion de refactor

### Paso 1

Consolidar servicios compartidos en `src/` para:

- ventas
- usuarios/permisos
- backups/diagnostico
- caja
- stock

### Paso 2

Hacer que la UI legacy y la API consuman la misma regla de negocio.

### Paso 3

Recien despues simplificar pantallas legacy o reemplazarlas por endpoints mas consistentes.

## Con que arrancar

1. Ventas
2. Usuarios/permisos
3. Caja
4. Stock
5. Backups/diagnostico
