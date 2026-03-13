# QA Checklist - FLUS 3.4.0

Checklist manual para validar la release antes de desplegar en clientes con datos reales.

## Preparacion

1. Confirmar backup de base y archivos.
2. Confirmar migraciones aplicadas:

```powershell
C:\xampp\php\php.exe scripts\migrate.php
```

3. Abrir el sistema con un usuario administrador.
4. Si hay JS cacheado, hacer una recarga forzada una vez.

## Smoke general

- Login correcto.
- Menu principal carga sin errores visuales.
- Dashboard abre sin error 500.
- Logout correcto.

## Ventas

- Abrir [ventas.php](/C:/xampp/htdocs/kiosco/public/ventas.php).
- Aplicar filtros por fecha.
- Aplicar filtro por medio de pago.
- Cambiar de pagina y confirmar que los filtros se conservan.
- Exportar CSV y verificar que respete esos filtros.
- Abrir detalle de una venta desde el listado.

## Compras

- Abrir [compras.php](/C:/xampp/htdocs/kiosco/public/compras.php).
- Crear un borrador nuevo.
- Agregar al menos 2 items.
- Aplicar descuento por item a uno de ellos.
- Guardar borrador.
- Reabrir en modo edicion y confirmar que el badge de descuento se vea bien.
- Confirmar la compra.
- Verificar que la fecha/hora no quede en `00:00`.
- Verificar que el stock impacte correctamente.

## Proveedores

- Abrir [proveedores.php](/C:/xampp/htdocs/kiosco/public/proveedores.php).
- Buscar un proveedor existente.
- Abrir el modal o drawer de edicion.
- Verificar ultima compra, monto e historial reciente.
- Confirmar que la ultima compra muestre fecha y hora coherentes.
- Editar un proveedor y guardar.

## Productos

- Abrir [productos.php](/C:/xampp/htdocs/kiosco/public/productos.php).
- Buscar por codigo.
- Abrir edicion de un producto.
- Confirmar que el proveedor vinculado se vea correctamente.
- Guardar una edicion simple.
- Exportar CSV si ese flujo forma parte del uso habitual.

## Stock

- Abrir [stock.php](/C:/xampp/htdocs/kiosco/public/stock.php).
- Abrir ajuste rapido.
- Verificar tipos de ajuste.
- Realizar un ajuste de prueba si la base lo permite.
- Confirmar que el historial reciente siga visible.

## Movimientos

- Abrir [movimientos.php](/C:/xampp/htdocs/kiosco/public/movimientos.php).
- Cambiar de pagina.
- Confirmar que el listado carga sin errores.
- Revisar una fila ligada a compra si existe.

## Caja Historial

- Abrir [caja_historial.php](/C:/xampp/htdocs/kiosco/public/caja_historial.php).
- Paginar.
- Exportar.
- Confirmar que al volver a paginar no arrastre `export`.

## Validacion de base de datos

- Confirmar que existe `schema_migrations`.
- Confirmar que `005_compras_descuentos_schema.sql` aparece como aplicada.
- Confirmar que `006_diagnostics_permission.sql` aparece como aplicada.
- Confirmar que `007_support_modules_schema.sql` aparece como aplicada.
- Confirmar que la tabla `compras` tiene:
  - `total_neto`
  - `total_iva`
  - `total_bruto`
  - `descuento_tipo`
  - `descuento_valor`
  - `descuento_total`
- Confirmar que `compra_items` tiene:
  - `descuento`
  - `descuento_tipo`
  - `descuento_porc`
- Confirmar que existen las tablas:
  - `factura_manual_items`
  - `producto_reposicion`
  - `producto_precios_hist`
  - `inventario_sesiones`
  - `inventario_conteos`
  - `cuenta_corriente_movimientos`

## Criterio de aprobacion

La release queda aprobada si:

- no hay errores fatales o pantallas en blanco
- compras, proveedores, productos, stock y ventas pasan la prueba minima
- las migraciones corren sin error
- no hay inconsistencias visibles de fecha/hora en compras recientes

## Si algo falla

1. Anotar pantalla exacta.
2. Anotar accion realizada.
3. Guardar mensaje de error o captura.
4. No desplegar en clientes hasta corregir y repetir este checklist.
