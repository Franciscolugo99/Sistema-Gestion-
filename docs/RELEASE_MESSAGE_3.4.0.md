# Release Message - FLUS 3.4.0

Plantilla reusable para entrega interna o comunicacion a clientes.

## Version corta

Se actualizo FLUS a la version `3.4.0` con mejoras operativas en ventas, compras, productos, stock y proveedores.

Puntos principales:

- Compras deja de modificar el esquema de base en tiempo de uso.
- Las compras nuevas guardan fecha y hora reales.
- Se corrigieron problemas de edicion de compras con descuentos por item.
- Ventas conserva filtros correctamente al paginar y exportar.
- Se reforzo compatibilidad con instalaciones MySQL que tienen permisos limitados sobre `information_schema`.

La actualizacion requiere ejecutar migraciones:

```powershell
C:\xampp\php\php.exe scripts\migrate.php
```

Documentacion asociada:

- [CHANGELOG.md](/C:/xampp/htdocs/kiosco/CHANGELOG.md)
- [README.md](/C:/xampp/htdocs/kiosco/README.md)
- [UPGRADE_3.4.0.md](/C:/xampp/htdocs/kiosco/docs/UPGRADE_3.4.0.md)
- [QA_RELEASE_3.4.0.md](/C:/xampp/htdocs/kiosco/docs/QA_RELEASE_3.4.0.md)

## Version para cliente

Asunto sugerido: `Actualizacion FLUS 3.4.0`

Texto sugerido:

Se preparo una nueva version de FLUS con mejoras de estabilidad y mantenimiento del sistema.

Cambios destacados:

- mejora en compras para guardar correctamente fecha y hora
- mejoras en ventas para conservar filtros al navegar y exportar
- mejoras en productos, stock y proveedores
- ajustes internos para que futuras actualizaciones sean mas seguras

Antes de dejarla operativa se realiza backup y actualizacion controlada de base de datos.

Luego de instalar, se validan los flujos principales del sistema:

- ventas
- compras
- productos
- stock
- proveedores

Si hace falta, tambien podemos dejar una breve ventana de control post actualizacion para confirmar que todo quedo correcto en uso real.

## Version interna tecnica

Release `3.4.0`

Incluye:

- bump de version/build
- actualizacion de `README` y `CHANGELOG`
- nueva guia de upgrade
- nueva guia de QA manual
- migracion `005_compras_descuentos_schema.sql`

Validaciones recomendadas antes de deploy:

- correr migraciones
- ejecutar checklist de QA
- revisar cache del navegador en pantallas con JS actualizado
