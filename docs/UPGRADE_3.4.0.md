# Upgrade Guide - FLUS 3.4.0

Guia pensada para actualizar instalaciones existentes con datos reales.

## Alcance de esta version

- Mejora modulos operativos: productos, stock, proveedores y ventas.
- Corrige flujo de compras para guardar hora real y evitar cambios de esquema en runtime.
- Agrega y aplica migraciones SQL nuevas.

## Migraciones incluidas

- `migrations/004_facturas_unique_scope.sql`
- `migrations/005_compras_descuentos_schema.sql`

## Impacto esperado

- Facturacion:
  - Si el cliente no usa facturacion integrada, no deberia haber impacto operativo.
  - La migracion `004_facturas_unique_scope.sql` solo afecta tabla `facturas`.
- Compras:
  - Nuevas compras se guardan con fecha y hora reales.
  - Compras legacy con hora `00:00:00` completan hora al confirmar sin cambiar el dia original.
- Productos / Proveedores:
  - Se mantiene compatibilidad con instalaciones donde el usuario MySQL tiene permisos limitados sobre `information_schema`.

## Preparacion previa

1. Confirmar ventana de mantenimiento.
2. Hacer backup completo de la base de datos.
3. Hacer backup de la carpeta del proyecto actual.
4. Confirmar que `src/config.php` y `storage/` quedan preservados.
5. Tener a mano el PHP del servidor para correr migraciones.

## Pasos de actualizacion

1. Copiar la nueva version del proyecto sobre la instancia existente.
2. Verificar que `src/config.php` no haya sido sobrescrito.
3. Ejecutar:

```powershell
C:\xampp\php\php.exe scripts\migrate.php
```

4. Confirmar que el runner termine con `OK - Migraciones aplicadas`.
5. Abrir el sistema y validar flujos criticos.

## Pruebas minimas post deploy

- Login y carga del dashboard.
- Ventas:
  - aplicar filtros
  - cambiar de pagina
  - exportar CSV
- Compras:
  - crear borrador
  - editar compra con descuentos por item
  - confirmar compra
- Proveedores:
  - abrir modal de proveedor
  - revisar ultima compra e historial
- Productos:
  - abrir edicion
  - revisar proveedor vinculado
- Stock:
  - abrir ajuste rapido
  - verificar tipos de ajuste

## Riesgos a vigilar

- Instalaciones con JS cacheado: si una pantalla parece vieja, hacer recarga forzada.
- Usuarios MySQL con permisos muy limitados: validar productos/proveedores despues del deploy.
- Si una instancia usa facturacion en el futuro, confirmar que `004_facturas_unique_scope.sql` se haya aplicado correctamente.

## Rollback

1. Restaurar backup de archivos.
2. Restaurar backup de base de datos.
3. Verificar acceso con una prueba basica de login.

## Archivos clave de la release

- `src/version.php`
- `CHANGELOG.md`
- `README.md`
- `migrations/004_facturas_unique_scope.sql`
- `migrations/005_compras_descuentos_schema.sql`
