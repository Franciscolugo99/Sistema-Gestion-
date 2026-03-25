# CHANGELOG - FLUS

## [Unreleased]

### Added

- **Facturación / Fase 1 (factura común):**
  - Trazabilidad fiscal mínima para factura común con `fiscal_request_uid`, eventos ARCA y soporte base para recovery simple sin rediseñar todavía el modelo documental.
  - Nuevos smoke tests orientados a validar unificación de entrada, retry manual y compatibilidad baseline + migraciones.
- **Facturación / Fase 2 (base documental mínima):**
  - Nueva base documental mínima con `documentos_comerciales`, `documento_items` y soporte no destructivo para `facturas.documento_id`.
  - Nuevos smoke tests de comportamiento para validar creación/reutilización de documento manual, retry por `request_uid`, persistencia de `documento_id` y reconstrucción de detalle desde `documento_items`.
- **Facturación / Fase 3 (base mínima de cobranzas):**
  - Nueva capa mínima con `cobranzas` y `cobranza_aplicaciones` para registrar cobros reales y su aplicación comercial/documental sin reemplazar todavía `venta_pagos`, caja ni cuenta corriente.
  - Smoke tests iniciales para validar alta idempotente de cobranzas, aplicaciones y enlace con factura/documento cuando corresponda.
- **Facturación / Fase 4 (recibos y aplicaciones mínimas):**
  - Nueva capa mínima con `recibos` y `recibo_aplicaciones` para dejar constancia documental del recibo sin reemplazar todavía cobranzas, caja ni cuenta corriente.
  - Smoke tests orientados a validar alta de recibos, aplicaciones razonables e idempotencia mínima sobre el mismo caso comercial.
- **Facturación / Fase 5 (reportes fiscales y reenvío comercial):**
  - Reportes fiscales mínimos para seguir emisión, estado fiscal, CAE, cliente, comprobante y trazabilidad operativa sin rehacer todo el módulo.
  - Trazabilidad mínima de reenvío comercial/email por factura mediante `envio_ultimo_*` y `envio_intentos`, preparada para mostrar último canal, destino, resultado y error del envío al cliente.
- **Facturación / Fase 6 (presupuestos/remitos documentales):**
  - Nueva evolución documental mínima sobre `documentos_comerciales` para soportar `PRESUPUESTO` y `REMITO`, con relaciones básicas entre documentos y navegación comercial asociada.
  - Nueva UI/listado de documentos comerciales para crear, ver y seguir presupuestos/remitos sin convertir todavía el sistema en un módulo comercial aparte.
  - Smoke tests nuevos para reglas documentales, vínculos, cliente operativo, doble acción y conversión controlada a venta.
- **Facturación / Fase 7 (contingencia fiscal mínima de factura común):**
  - Nueva regularización mínima para factura común con `facturacion_recovery.php`, orientada a `PENDIENTE_ENVIO`, `ERROR_TRANSITORIO` y `ERROR_POST_ARCA`.
  - Nuevo estado `RECUPERADA` y formalización de `ERROR_POST_ARCA` para distinguir mejor un fallo local posterior a una autorización remota.
  - La contingencia fiscal de Fase 7 se apoya en `estado_fiscal`, `fiscal_error_code`, `fiscal_error_message`, `fiscal_requested_at`, `fiscal_approved_at` y `factura_eventos_arca`, sin reutilizar `envio_ultimo_*`.

### Changed

- **Facturación / Fase 1 (factura común):**
  - La emisión desde venta y la emisión manual quedan cerradas sobre la misma capa de negocio, manteniendo `facturas.venta_id` y compatibilidad legacy.
  - `public/factura_nueva.php`, `public/factura_emitir.php` y `public/factura_manual.php` quedan como entrypoints más finos, con mejor resolución de cliente y menos lógica duplicada.
  - Se unifica la preparación/finalización del flujo fiscal para factura común: validaciones, determinación del comprobante, numeración, manejo de disponibilidad ARCA, request UID e idempotencia básica.
  - Factura común pasa a manejar estados fiscales explícitos para evitar ambigüedad cuando ARCA falla o cuando queda un caso recuperable.
  - La emisión manual preserva y reutiliza el mismo `request_uid` y la misma `venta_id` en retries del mismo caso operativo, evitando duplicar ventas manuales por reenvíos con cambios menores no fiscales.
  - La compatibilidad de instalación se mantiene por baseline + migraciones: `install.sql` no absorbe columnas de esta fase y el upgrade sigue soportado vía `scripts/migrate.php`.
- **Facturación / Fase 2 (base documental mínima):**
  - La factura manual pasa a preparar o reutilizar primero una base documental propia y luego convive con la venta manual legacy como puente de compatibilidad.
  - `factura_ver.php` queda preparada para reconstruir detalle desde `documento_items` con fallback a rutas legacy (`factura_items`, `venta_items`, `factura_manual_items`).
  - La fase deja explícitamente lista la transición hacia un modelo documental más limpio, pero todavía sin eliminar `facturas.venta_id` ni la venta manual fake.
- **Facturación / Fase 3 (base mínima de cobranzas):**
  - El sistema empieza a distinguir mejor entre pago real, deuda en cuenta corriente y aplicación a venta/documento/factura, sin rehacer todavía caja ni CC.
  - Los pagos reales de venta y los pagos posteriores de cuenta corriente pueden dejar una base de cobranza propia para fases futuras.
  - La nueva capa se mantiene complementaria y no destructiva respecto del modo no fiscal y de la operación legacy existente.
- **Facturación / Fase 4 (recibos y aplicaciones mínimas):**
  - El sistema empieza a distinguir mejor entre cobranza, recibo y aplicación comercial, sin reemplazar todavía el flujo operativo existente.
  - Los recibos pueden vincularse a cobranzas, documentos o facturas cuando corresponde, manteniendo un puente compatible con los flujos legacy.
- **Facturación / Fase 5 (reportes fiscales y reenvío comercial):**
  - Se agregan vistas/reportes fiscales más operativos para seguir emisión, autorizaciones, rechazos y vínculos asociados.
  - La trazabilidad `envio_ultimo_*` queda reservada al reenvío comercial/email al cliente y no se reutiliza para la interacción fiscal con ARCA.
- **Facturación / Fase 6 (presupuestos/remitos documentales):**
  - `PRESUPUESTO` y `REMITO` pueden existir como borrador documental sin cliente, pero requieren cliente para pasar a operación real: generar remito, generar venta, emitir factura o vincular una venta existente.
  - La UI documental ahora muestra con más claridad el siguiente paso operativo, el impacto real en stock y cuándo un documento ya cumplió la acción principal esperada.
  - La conversión desde presupuesto/remito a venta puede usar operatoria real con `ventas`, `venta_items`, descuento de stock y `movimientos_stock` cuando todos los ítems matchean por `productos.codigo` y hay stock suficiente; si no, cae a venta manual legacy sin tocar stock.
  - Se mantiene compatibilidad con la función legacy de conversión, pero la semántica real queda unificada bajo una capa de conversión documental más clara.
- **Facturación / Fase 7 (contingencia fiscal mínima de factura común):**
  - La regularización de factura común reutiliza `request_uid`, eventos ARCA y recovery simple, sin abrir una arquitectura paralela ni introducir CAEA.
  - `facturacion.php` y `factura_ver.php` pasan a mostrar mejor los casos pendientes/transitorios/post-ARCA y ofrecen acciones mínimas de regularización cuando corresponde.
  - La traza fiscal visible se apoya en `estado_fiscal`, `fiscal_*` y `factura_eventos_arca`, mientras que la traza comercial/email sigue separada.

### Fixed

- **Facturación / Fase 1 (factura común):**
  - Se reduce la duplicación real entre emisión desde venta y emisión manual sin reescribir toda la arquitectura.
  - Si ARCA falla, la factura común deja estado fiscal consistente y trazabilidad mínima para reintento/recovery.
  - Se corrige la deuda de baseline vs migraciones para que columnas de Fase 1 como `estado_fiscal` vivan únicamente en migraciones y no en `install.sql`.
- **Facturación / Fase 2 (base documental mínima):**
  - Se endurece el retry manual para evitar duplicar documento base del mismo caso y para reutilizar la misma venta manual legacy cuando ya estaba vinculada al documento.
  - Se reduce la posibilidad de relink accidental entre documento y otra `venta_id` distinta en reintentos o reaplicaciones.
- **Facturación / Fase 3 (base mínima de cobranzas):**
  - Se evita tratar la porción `CC` de una venta como si fuera una cobranza real de caja.
  - Se refuerza la idempotencia de cobranzas/aplicaciones para no duplicar el mismo cobro en retries razonables del flujo.
- **Facturación / Fase 4 (recibos y aplicaciones mínimas):**
  - Se reduce la posibilidad de duplicar recibos o aplicaciones del mismo caso comercial en retries razonables.
  - Se desacopla mejor el hecho documental del recibo respecto del cobro real ya registrado.
- **Facturación / Fase 5 (reportes fiscales y reenvío comercial):**
  - Se mejora la visibilidad operativa de la facturación sin mezclar estado fiscal con el reenvío comercial por email.
  - La trazabilidad de email queda preservada para Fase 5 y deja de pisarse con la contingencia fiscal posterior.
- **Facturación / Fase 6 (presupuestos/remitos documentales):**
  - Se endurece la conversión operativa a venta bajo transacción, con `SELECT ... FOR UPDATE`, descuento condicional de stock y suma de cantidades repetidas del mismo SKU.
  - Se evita la doble acción silenciosa: si un documento ya tiene remito, venta o factura vinculada, la UI y el backend dejan de ofrecer o aceptar repetir esa misma acción.
  - Se bloquea el vínculo con ventas de otro cliente y se permite completar `cliente_id` en la venta cuando el documento sí lo tiene y la venta todavía no.
- **Facturación / Fase 7 (contingencia fiscal mínima de factura común):**
  - Se modela `ERROR_POST_ARCA` como caso propio para factura común, evitando tratarlo como un error transitorio genérico.
  - La regularización ya no reenvía ciegamente a ARCA cuando hay indicios de autorización remota; primero intenta recovery simple y deja el caso visible para intervención mínima.
  - Se corrige la mezcla de dominios entre trazabilidad fiscal y trazabilidad de email: `envio_ultimo_*` vuelve a quedar exclusivamente para reenvío comercial al cliente.

### Migrations

- `016_factura_comun_fiscal_flow.sql`
- `017_facturacion_documentos_manual.sql`
- `018_cobranzas_base.sql`
- `019_recibos_aplicaciones.sql`
- `020_facturas_envio_trazabilidad.sql`
- `021_documentos_relaciones_presupuestos_remitos.sql`
- `022_facturas_fiscal_contingencia.sql`

## [3.8.1] - 2026-03-22

### Added

- **Facturación / Notas de Crédito (NC):**
  - Nuevo módulo `facturacion_nc.php` para gestionar notas de crédito fiscales sobre comprobantes emitidos.
  - Soporte para **NC total** y **NC parcial por ítem**, mostrando cantidad original, acreditada y saldo fiscal disponible.
  - Nueva pantalla `facturacion_nc_recovery.php` para resolver casos `ERROR_POST_ARCA` reaplicando la parte comercial/local sin reemitir fiscalmente.
  - Nuevo permiso específico `emitir_nota_credito`.

### Changed

- **Facturación:** se endureció el flujo fiscal de NC con validación explícita entre factura origen y venta asociada.
- **Facturación:** se incorporó control de estados fiscales en `venta_anulaciones`:
  - `NO_APLICA`
  - `PENDIENTE`
  - `ENVIANDO`
  - `APROBADA_PENDIENTE_APLICACION`
  - `APLICADA`
  - `RECHAZADA`
  - `ERROR_POST_ARCA`
- **Facturación:** se agregó `fiscal_request_uid` único para reforzar idempotencia y evitar duplicados por doble envío o reintentos.
- **Instalación y permisos:** `install.sql`, catálogo de permisos y seeds quedaron alineados con `emitir_nota_credito` para instalaciones limpias y upgrades.
- **UI / Navegación:** se incorporó breadcrumb contextual en vistas clave para mejorar orientación dentro del sistema.

### Fixed

- **Facturación / NC:** se corrigieron inconsistencias entre código, migraciones y permisos efectivos del rol administrador.
- **Facturación / NC:** se reforzó la compatibilidad de esquema previa a ejecutar el flujo fiscal.
- **Facturación / NC:** se mejoró el manejo de datos legacy para controlar saldo fiscal e ítems reconstruidos en comprobantes más viejos.

### Migrations

- `010_anulaciones_parciales.sql`
- `011_cc_schema_compat.sql`
- `012_venta_anulaciones_fiscal.sql`
- `013_facturas_fiscal_ext.sql`
- `014_factura_items_eventos_arca.sql`
- `015_fiscal_nc_hardening.sql`
- Sin cambios documentados todavía.

## [3.8.0] - 2026-03-23

### Added

- **Nuevo módulo de facturación electrónica:**
  - **Listado de facturas** con filtros por fecha, estado, cliente, tipo y número. Se muestran KPIs del periodo (total facturado, ticket promedio, cantidad de facturas) y se pueden exportar los resultados a CSV.
  - **Emisión manual de facturas** desde `factura_manual.php`: permite seleccionar el concepto (productos o servicios), buscar productos/servicios con autocompletado, agregar múltiples líneas con cantidad, precio e IVA y visualizar el total antes de emitir. El módulo calcula automáticamente alícuotas y solicita CAE a ARCA/AFIP, generando el PDF oficial.
  - **Emisión de facturas desde ventas:** desde el detalle de una venta puede emitirse la factura fiscal correspondiente. La librería de facturación determina el tipo de comprobante, genera la numeración y registra el CAE, marcando la venta como facturada.
  - **Panel de configuración de facturación** (`facturacion_config.php`): permite definir datos de la empresa (razón social, CUIT, domicilio, ingreso bruto, fecha de inicio de actividades), punto de venta y condición IVA, subir certificados y claves para AFIP/ARCA, elegir el modo (Demo/Homologación/Producción), cargar el logo de la factura y establecer un límite máximo de ítems por comprobante. Incluye pruebas de conectividad y sincronización de numeración con ARCA.
  - **Biblioteca de facturación** (`src/facturacion_lib.php`) que centraliza la lógica de emisión, cálculo de importes, determinación de tipo de factura y comunicación con AFIP/ARCA. Incluye funciones para emitir desde ventas y manualmente.

### Changed

- **Navegación principal** actualizada para incluir el módulo de facturación y enlaces cruzados desde clientes y ventas hacia las facturas asociadas.
- **Esquema de base de datos** (`install.sql` y migraciones) actualizado con tablas y columnas para facturas y configuración fiscal.
- **Módulos de clientes y cuenta corriente** enlazados a facturas para mostrar documentos fiscales vinculados.

### Fixed

- Correcciones menores en la visualización de clientes y ventas para soportar la nueva relación con facturas.

## [3.7.1] - 2026-03-22

### Fixed

- Cuenta Corriente: se alineo el esquema versionado con el controlador agregando soporte para `autorizado_por`, `caja_movimiento_id`, `cc_movimiento_id`, `medio_pago` y `total_transferencia`.
- Cuenta Corriente: `CuentaCorrienteController` ahora degrada mejor en instalaciones viejas al insertar columnas opcionales solo si existen.
- Caja/CC: los pagos de cuenta corriente desde caja ya no se rompen en instalaciones con drift de esquema y mantienen el arqueo correcto para transferencia.

### Changed

- Base tecnica: `install.sql` y `migrations/007_support_modules_schema.sql` quedan sincronizados con la migracion `011_cc_schema_compat.sql` para instalaciones limpias y upgrades.

## [3.7.0] - 2026-03-22

### Added

- Ventas: soporte inicial para anulaciones parciales no fiscales con migracion `010_anulaciones_parciales.sql`, tablas `venta_anulaciones` y `venta_anulacion_items`, helper compartido y permiso `anular_items_venta`.
- Ventas: nuevo endpoint `anular_items_venta.php` y modal/UI para devolver items de una venta no facturada sin reponer stock ni cuenta corriente de más.
- Ventas: historial visible de devoluciones dentro de `venta_detalle.php`, con resumen de anulaciones, neto vigente, items afectados y trazabilidad por usuario/motivo.
- Docs: plan operativo inicial para seguir el track de anulaciones y futura integración ARCA en `docs/anulaciones-parciales-plan.md`.

### Changed

- Ventas: el detalle se rediseñó para mostrar mejor estado, motivo de anulación, métricas comerciales y estado real de cada item vendido/devuelto.
- Ventas: el listado general y la vista rápida ahora distinguen mejor ventas parciales/anuladas y muestran montos originales, devueltos y neto vigente.
- Reportes/KPIs: las ventas `PARCIALMENTE_ANULADA` se mantienen dentro del criterio de venta activa y dejan de desaparecer de listados, dashboard y exportaciones.
- Permisos/instalacion: el catálogo base, `install.sql` y la migración de sync de permisos quedan alineados con `anular_items_venta`.

### Fixed

- Ventas: `anular_venta.php` ya no duplica reposición de stock ni reversa de cuenta corriente cuando la venta tenía anulaciones parciales previas.
- UX: se corrigió la notificación final del flujo de devolución y se agregó protección contra doble submit en anulaciones parciales.
- Tests: smoke tests actualizados para reflejar el nuevo criterio de venta activa y verificar que el permiso `anular_items_venta` exista en código e instalación.

## [3.6.0] - 2026-03-21

### Added

- Clientes: nueva `cliente_detalle.php` como ficha ejecutiva con resumen comercial, fiscal y de cuenta corriente.
- Roles: nuevo rol `Operador` para negocios chicos con caja, stock, compras y cuenta corriente operativa sin abrir administración sensible.
- Catalogo/Inventario: nuevas vistas de solo consulta `productos_consulta.php` y `stock_consulta.php` para dar sentido real a `ver_productos` y `ver_stock`.

### Changed

- Home: panel principal más directo, con mejor jerarquía visual y accesos coherentes según permiso real.
- Clientes: acciones mejor distribuidas, enlaces cruzados con ventas/facturación/CC y drawer con bloque de actividad vinculada.
- Inventario: `inventario_analisis.php` ahora conserva filtros, exporta según la pestaña activa y suma acciones rápidas a productos, compras, reposición y conteo.
- Roles y permisos: pantalla rediseñada por áreas de negocio, niveles de impacto y preview de modulos visibles.
- Caja: se separó mejor el flujo de `abrir_caja`, `realizar_ventas` y `cerrar_caja`.

### Fixed

- Caja: al cerrar ya no redirige a historial si el usuario no tiene permiso para verlo.
- Seguridad: la cuenta admin de resguardo ya no permite cambiar rol, estado, usuario ni editar su contraseña desde otro usuario.
- Seguridad: el rol base de la cuenta admin de resguardo queda bloqueado en Roles/Permisos para evitar vaciarlo por error.
- Performance/mantenibilidad: se eliminaron chequeos directos a `INFORMATION_SCHEMA`/`SHOW COLUMNS` en runtime en nav, ventas, precios, caja, soporte, instalacion y otros puntos calientes.
- UI: `rol_permisos.php` ahora abre con categorias cerradas por defecto y se corrigieron detalles visuales de iconos/buscador.

## [3.5.0] - 2026-03-20

### Added

- Caja: selector de salida del ticket (`Auto imprimir`, `Vista previa`, `No abrir`) con modal propio de vista previa.
- Configuracion: nueva seccion `Perfiles de impresion` para ticket, comanda y factura.
- Terminales: overrides de ticket/papel por terminal sin requerir cambios de esquema.
- Compras: autosave de borradores con persistencia al navegar o cerrar la pestaña.
- Nav: ayuda rapida de atajos accesible desde el header.

### Changed

- Nav refactorizado: markup mas limpio, CSS/JS externos, mejor jerarquia tipografica y espaciado general.
- Caja: estado de apertura rediseñado, recuperacion del ticket en curso mas robusta y flujo de impresion preparado para perfiles futuros.
- Dashboard: extraccion de filtros, cache y metricas a `src/Dashboard/*`, uso de partials para render y menor dependencia del monolito original.
- Terminales: pantalla administrativa renovada con estados operativos, edicion inline y mayor claridad para cajas activas/bloqueadas.
- Licencias: pantalla administrativa mas orientada a operacion y con menor exposicion de detalles internos.
- Compras: mejor respuesta visual en anchos intermedios y mejor distribucion del formulario de carga.

### Fixed

- Dashboard: correccion de warnings, datasets vacios, invalidaciones de cache y graficos que dejaban de renderizar al aplicar filtros.
- Caja: el ticket en curso ya no se pierde facilmente al cerrar pestaña/navegador y puede recuperarse para seguir cobrando.
- Caja: se restauro `F5` como refresh normal del navegador.
- Nav/Caja: saneado de textos visibles con problemas de codificacion en botones y mensajes operativos.
- Terminales: bloqueo seguro para evitar desactivar la terminal actual, una caja abierta o una terminal con lock activo.

## [3.4.0] - 2026-03-12

### Added

- Panel Tecnico interno para soporte y mantenimiento con acceso desde el menu de administracion.
- Base de pruebas minima en `tests/` con `bootstrap.php` y `smoke.php`.
- Documentacion operativa inicial: roadmap POS e inventario de duplicacion legacy/API.
- Migracion `005_compras_descuentos_schema.sql` para versionar columnas de descuentos y totales en compras.
- Guia operativa de actualizacion para despliegues en instalaciones existentes.
- Baseline `install.sql` para instalaciones limpias.
- Migracion `006_diagnostics_permission.sql` para compatibilidad del permiso de diagnostico.
- Migracion `007_support_modules_schema.sql` para tablas/permisos de soporte y actualizaciones sobre instalaciones existentes.

### Changed

- UI HTML forzada a UTF-8 para evitar textos corruptos en navegadores/servidores con charset inconsistente.
- Diagnostico mejorado: overview mas confiable, bundle de soporte mas compartible y mejor deteccion de estados activos.
- Panel Tecnico localizado al espanol para uso diario desde la interfaz.
- Productos: reglas de estado extraidas a helper compartido, busqueda con prioridad por codigo y mejor UX para editar sin cerrar el modal al guardar.
- Productos/Stock: visualizacion de pesables unificada para que tabla, edicion y detalle usen cantidades legibles para el usuario.
- Stock: tabla y ajuste rapido alineados con productos, con historial reciente en el modal y mejor soporte para unidades KG/G/LT/ML.
- Proveedores: modulo enriquecido con resumen operativo, ultimas compras, productos asociados y acciones de re-vinculacion puntual/global.
- Ventas: paginacion y export respetan filtros activos de forma consistente.
- Compras: las nuevas compras guardan fecha y hora reales, y la confirmacion completa horas faltantes en borradores legacy sin cambiar el dia original.
- Compras: los cambios de esquema pasan al runner de migraciones y dejan de ejecutarse en tiempo de request.
- Factura manual: la tabla de items deja de autocrearse en runtime y pasa a esquema versionado.

### Fixed

- Login endurecido: errores genericos, preservacion segura de `next` y throttling basico contra fuerza bruta.
- Productos: cambio de estado migrado a `POST + CSRF`, evitando operaciones mutantes por `GET`.
- Usuarios: proteccion del ultimo administrador activo en flujos API y legacy.
- Tickets publicos: generacion de links sin confiar en `HTTP_HOST` del request.
- Instalador: proteccion CSRF en el flujo inicial.
- Backups y restore: quoting mas seguro en Windows, deteccion de restore activo y bloqueo de acciones incompatibles durante restauracion.
- Panel Tecnico: deteccion correcta de `php.exe` para ejecutar smoke tests desde la UI.
- Smoke tests: expectativas alineadas con la configuracion real de la app para evitar falsos negativos.
- Productos: correccion de errores JS en el modal de edicion y sincronizacion visual despues de guardar.
- Proveedores: sincronizacion del nombre visible en productos al renombrar y deteccion de productos legacy sin `proveedor_id`.
- Compras: correccion del texto corrupto al editar items con descuento.
- Productos/Proveedores: fallback restaurado para instalaciones con permisos limitados sobre `information_schema`.

## [3.3.0] - 2026-03-05

### Fixed

- Cuenta Corriente: al anular una venta con pago CC se genera REVERSA, se marca el CARGO como ANULADO y se actualiza el saldo/límite del cliente.

### Changed

- Notificaciones globales: reemplazo de alert/confirm/prompt nativos por modales/toasts (SweetAlert2) funcionando offline.
- Caja: mensajes operativos se unifican a toasts (sin “pill” inferior).

### UX

- Clientes/Productos: “Cambios sin guardar” pulido: elegir “Quedarme” no cierra (incluye cerrar por click afuera y Escape).
- Cuenta Corriente: links entre movimiento original/reversa con navegación consistente (aunque el original esté oculto por filtros/anulados/paginación).

## [3.2.2] - 2026-01-29

### Added

- Diagnóstico exportable (ZIP) para soporte.
- Inventario físico (conteo) + aplicación de ajustes con movimientos de stock.
- Reposición sugerida + export CSV.
- Historial de precios / ajustes masivos.
- `system_api`: endpoints para operación (health/diagnóstico/backups) y soporte de módulos (inventario/reposición/precios).

### Changed

- Refactor: alineación entre scripts API/UI + install + helpers.
- Migraciones: runner `scripts/migrate.php` + `migrations/` (idempotente).
- BD: views portables sin `DEFINER` + limpieza de inconsistencias (FKs duplicadas).

### Security

- Contrato JSON estándar (`ok/error`) en APIs.
- CSRF reforzado en endpoints JSON.

### Repo / Maintenance

- `.gitignore`: se ignora el estado local de licencia (no se versiona).
- Se versionan migraciones SQL (`migrations/*.sql`).

### Docs

- README/CHANGELOG actualizados + bump de versión + ajustes de navegación (según permisos).

---

## [2.3.1] - (tag v2.3.1)

- Release histórico (ver tag `v2.3.1`).

## [2.3.0] - 2026-01-22

### ✨ Ventas - módulo avanzado (Historial / Reportes)

- Historial de ventas con **filtros avanzados** (fecha, rango horario, estado, medio, cliente, ID).
- KPIs del período filtrado + vista de gráficos (Chart.js).
- **Exportación CSV** respetando filtros.
- Preview de venta en modal (items, totales) y acciones rápidas.

#### Ticket público compartible (link firmado)

- Nuevo `public/ticket_publico.php` para acceder a un ticket vía link con **token**.
- La API puede generar el link/token para compartir y preparar envío por WhatsApp/Email.
- El link incluye `ts` y el token **expira**: TTL por defecto 7 días (configurable con `TICKET_TOKEN_TTL_SECONDS`).
- **Consideración**: definir un `APP_SECRET` propio en el servidor (evitar secreto por defecto).
  - Recomendado persistirlo en `storage/app_secret.key` para que no cambie en upgrades.

#### Autocompletado de clientes en Ventas

- Nuevo dropdown con estilo FLUS (CSS dedicado) + navegación por teclado.
- Búsqueda con debounce para evitar saturar el servidor.
- Mejora UX: permite seleccionar cliente sin conocer el `cliente_id`.

### 🧰 Backups - robustez y UX

- Ajustes en pantalla de backups y librería de restore.
- Archivo `storage/restore.lock` para evitar restores simultáneos.
  - **NO commitear** este archivo (agregar a `.gitignore`).

### 📁 Archivos modificados / nuevos

| Archivo                                     | Cambio                                             |
| ------------------------------------------- | -------------------------------------------------- |
| `public/ventas.php`                         | Filtros, KPIs, export, integración de autocomplete |
| `public/assets/js/ventas.js`                | UX/preview + mejoras de seguridad (escape HTML)    |
| `public/assets/css/ventas.css`              | Ajustes visuales                                   |
| `public/assets/css/ventas-autocomplete.css` | ✨ Nuevo (UI autocomplete)                         |
| `public/api/ventas_api.php`                 | Reportes/stats + acciones (ticket/whatsapp/email)  |
| `public/ticket_publico.php`                 | ✨ Nuevo (ticket con token)                        |
| `public/backups.php`                        | Ajustes                                            |
| `public/assets/js/backups.js`               | Ajustes                                            |
| `public/assets/css/backups.css`             | Ajustes                                            |
| `src/backup_lib.php`                        | Ajustes                                            |
| `public/api/index.php`                      | Ajustes menores                                    |
| `public/bootstrap.php`                      | Ajustes menores                                    |

---

## [2.2.5] - 2026-01-16

### ✨ Autocompletado Visual en Caja

#### Nuevo dropdown de sugerencias

- **Antes**: Usaba `<datalist>` HTML nativo (limitado, depende del navegador)
- **Ahora**: Dropdown visual personalizado con estilo del sistema

#### Características del nuevo autocompletado:

- 📝 Muestra **nombre**, código, stock y precio de cada producto
- ⌨️ Navegación con **flechas ↑↓** y selección con **Enter**
- 🖱️ Click o hover para seleccionar
- 🔍 Busca a partir de **2 caracteres**
- ⚡ Debounce de 150ms para no saturar el servidor

#### Ejemplo de uso:

1. Escribir "coc" → Aparece dropdown con:
   - Coca Cola 500ml - Código: 123 - Stock: 50 - $1500.00
   - Coca Cola 1.5L - Código: 124 - Stock: 30 - $2500.00
2. Seleccionar con mouse o flechas + Enter
3. Se agrega automáticamente al ticket

### 📁 Archivos Modificados

| Archivo                    | Cambio                                 |
| -------------------------- | -------------------------------------- |
| `public/assets/js/caja.js` | Nuevo sistema de autocompletado visual |
| `src/version.php`          | Actualizado a v2.2.5                   |

---

## [2.2.4] - 2026-01-16

### ✨ Mejora de Búsqueda en Caja

#### Buscar producto por nombre (no solo código)

- **Problema**: Al escribir "coca" o "Agua Graciani" y dar Enter, devolvía "Producto no encontrado"
- El endpoint `buscar_producto` solo buscaba por código exacto (`WHERE codigo = :cod`)
- **Resultado**: No se podía agregar productos escribiendo el nombre

#### Solución

El endpoint ahora busca en este orden de prioridad:

1. Código exacto
2. Nombre exacto
3. Código o nombre parcial (LIKE) - toma el más relevante

**Ejemplo**: Escribir "coca" ahora encuentra "Coca Cola 500ml" aunque el código sea "7790895000515"

### 📁 Archivos Modificados

| Archivo                | Cambio                              |
| ---------------------- | ----------------------------------- |
| `public/api/index.php` | Mejorado endpoint `buscar_producto` |
| `src/version.php`      | Actualizado a v2.2.4                |

---

## [2.2.3] - 2026-01-16

### 🔴 Corrección Crítica

#### Error "Cannot redeclare json_ok()"

- **Problema**: Conflicto de funciones duplicadas entre `api_helpers.php` y otros archivos
- `buscar_productos.php` y `stock_ajax.php` definían `json_ok()` que ya existía en `api_helpers.php`
- **Resultado**: Error 500 "Cannot redeclare json_ok()" al buscar productos

#### Solución

- `buscar_productos.php`: Agregado check `if (!function_exists('json_ok'))`
- `stock_ajax.php`: Renombradas funciones a `stock_json_ok()` / `stock_json_fail()` (usa formato diferente: `success` en vez de `ok`)

### 📁 Archivos Modificados

| Archivo                                   | Cambio                        |
| ----------------------------------------- | ----------------------------- |
| `public/api/actions/buscar_productos.php` | Fix redefinición json_ok      |
| `public/stock_ajax.php`                   | Renombradas funciones locales |
| `src/version.php`                         | Actualizado a v2.2.3          |

---

## [2.2.2] - 2026-01-16

### 🔴 Corrección Crítica

#### Autocompletado de productos en Caja

- **Problema**: El endpoint `buscar_productos` (plural) no existía en la API
- El autocompletado en caja usaba `action=buscar_productos` pero solo existía `buscar_producto` (singular)
- **Resultado**: No aparecían sugerencias al escribir nombre/código de producto

#### Solución

- Agregado nuevo endpoint `buscar_productos` en `api/index.php`
- Busca por código o nombre parcial (LIKE)
- Ordenamiento por relevancia (código exacto primero)
- Límite configurable (default 10, max 20)

### 📁 Archivos Modificados

| Archivo                | Cambio                               |
| ---------------------- | ------------------------------------ |
| `public/api/index.php` | Agregado endpoint `buscar_productos` |
| `src/version.php`      | Actualizado a v2.2.2                 |

---

## [2.2.1] - 2026-01-16

### ✨ Mejoras

#### 1. Validación de Formularios de Usuario

- **Nuevo archivo `public/assets/js/usuario_form.js`**
- Validación client-side con mensajes en español
- Toggle de contraseña (mostrar/ocultar)
- Limpieza de errores en tiempo real
- Compatible con `usuario_nuevo.php` y `usuario_editar.php`

#### 2. Fix Permiso de Stock

- Corregido permiso en menú: `ver_stock` → `editar_stock`
- Ahora consistente con la funcionalidad real de la página

### 📁 Archivos Modificados

| Archivo                            | Cambio               |
| ---------------------------------- | -------------------- |
| `public/assets/js/usuario_form.js` | ✨ NUEVO             |
| `public/index.php`                 | Fix permiso stock    |
| `src/version.php`                  | Actualizado a v2.2.1 |

---

## [2.2.0] - 2026-01-16

### 🏗️ Refactorización Mayor

#### 1. Helpers API Centralizados

- **Nuevo archivo `src/api_helpers.php`**: Funciones compartidas para todas las APIs
- Incluye: `json_ok()`, `json_fail()`, `json_error()`, `json_response()`
- Incluye: `parse_num()`, `norm_medio_pago()`, helpers de DB
- Incluye: `setup_api_error_handlers()` para configurar exception handlers
- **Resultado**: Eliminada duplicación de código en 4 archivos

#### 2. Limpieza de Código Muerto

- ❌ Eliminada carpeta `views/` (era redundante, solo hacía require de `public/partials/`)
- ❌ Eliminado archivo `d` (output de grep commiteado por error)

#### 3. API Principal Simplificada

- `public/api/index.php` ahora usa helpers centralizados
- Reducidas ~100 líneas de código duplicado
- Mejor mantenibilidad

### 🔧 Correcciones de Base de Datos

#### 4. Script de Upgrade SQL

- **Nuevo archivo `scripts/upgrade_v220.sql`**
- Fix crítico: Foreign Key `promo_combo_items.producto_id` ahora tiene `ON DELETE CASCADE`
- Índices de optimización para ventas, movimientos y promos
- Verificaciones automáticas post-upgrade

### 📁 Archivos Modificados

| Archivo                    | Cambio                                  |
| -------------------------- | --------------------------------------- |
| `src/api_helpers.php`      | ✨ NUEVO - Helpers centralizados        |
| `src/version.php`          | Actualizado a v2.2.0                    |
| `public/api/index.php`     | Refactorizado para usar api_helpers.php |
| `scripts/upgrade_v220.sql` | ✨ NUEVO - Script de upgrade DB         |
| `views/`                   | ❌ ELIMINADO                            |
| `d`                        | ❌ ELIMINADO                            |

### ⚠️ Instrucciones de Upgrade

1. Hacer backup de la base de datos
2. Reemplazar archivos del sistema
3. Ejecutar `scripts/upgrade_v220.sql` en phpMyAdmin

### 📊 Métricas de la Refactorización

| Métrica                           | Antes   | Después          |
| --------------------------------- | ------- | ---------------- |
| Definiciones de json_ok/json_fail | 4       | 1 (centralizada) |
| Carpeta views/                    | Existía | Eliminada        |
| Archivos basura                   | 1       | 0                |

---

## [2.1.3] - 2026-01-10

### 🔴 Corrección Crítica

#### Inconsistencia de permisos entre calcular_carrito y registrar_venta

**Problema v2.1.2:**

- `registrar_venta` anulaba silenciosamente `desc_global` si el usuario no tenía permiso
- `calcular_carrito` lo aplicaba siempre, sin verificar permiso
- Resultado: El sync mostraba un total con descuento, pero al registrar la venta el descuento desaparecía → "Pago insuficiente"

**Solución v2.1.3:**

1. **Misma validación en ambos endpoints**: Si viene `desc_global` sin permiso `caja_modificar_precio` → Error 403 (no anular silencioso)
2. **Frontend no envía lo que no puede usar**: Solo envía `desc_global` si tiene permiso
3. **Triple capa de protección**:
   - UI: Botón deshabilitado si no tiene permiso
   - JS: No envía `desc_global` en sync si no tiene permiso
   - PHP: Devuelve error 403 si llega `desc_global` sin permiso

**Código consistente:**

```php
// calcular_carrito Y registrar_venta:
if ($descGlobalReq !== null && !$puedeCambiarPrecio) {
  json_fail('No tiene permiso para aplicar descuentos', 403);
}
```

### 📁 Archivos Modificados

| Archivo                    | Cambio                                            |
| -------------------------- | ------------------------------------------------- |
| `public/api/index.php`     | Validación de permiso idéntica en ambos endpoints |
| `public/assets/js/caja.js` | Solo envía desc_global si CAN_MOD_PRECIO          |

---

## [2.1.2] - 2026-01-10 (SUPERSEDED - Inconsistencia de permisos)

### 🔴 Correcciones Críticas

#### 1. Server-sync ahora incluye desc_global

- **Problema v2.1.1**: El frontend no enviaba `desc_global` al sincronizar, el server devolvía total sin descuento global
- **Solución**: `sincronizarCarritoConServidor()` ahora envía `desc_global` y el backend lo aplica
- **Resultado**: Total verificado = Total con descuento global aplicado

#### 2. Server-sync ahora respeta precio manual

- **Problema v2.1.1**: El backend forzaba `precio_actual = precio_lista` ignorando cambios manuales
- **Solución**: El frontend envía `precio` y el backend lo respeta si tiene permiso `caja_modificar_precio`
- **Resultado**: Precio manual se verifica correctamente

#### 3. Condición de carrera corregida

- **Problema v2.1.1**: Si había sync en background, `cobrar()` recibía `null` y usaba total local
- **Solución**: Sync forzado ahora espera hasta 2 segundos si hay sync en curso
- **Resultado**: `cobrar()` siempre obtiene total del servidor

#### 4. BUG FATAL en logout corregido

- **Problema**: `terminal_cookie_id()` no existe, `terminal_lock_release()` recibía 4 params (acepta 3)
- **Solución**: Removida llamada a función inexistente, corregida firma de `terminal_lock_release`
- **Resultado**: Logout funciona, terminal locks se liberan correctamente

### 🔒 Seguridad

#### 5. CSRF en endpoints de usuarios/roles

- Agregado CSRF a `rol_eliminar.php`, `usuario_eliminar.php`, `usuario_toggle_estado.php`
- Estos endpoints modifican datos sensibles y estaban expuestos

### 🛠️ Mejoras Arquitecturales

#### 6. Librerías sin side-effects

- `caja_lib.php` y `promos_logic.php` ya no incluyen `bootstrap.php` completo
- Usan guard `APP_BOOTSTRAPPED` para cargar solo dependencias mínimas
- **Resultado**: APIs no reciben HTML inesperado cuando la DB falla

### 📁 Archivos Modificados

| Archivo                                | Cambio                                               |
| -------------------------------------- | ---------------------------------------------------- |
| `public/logout.php`                    | Fix llamadas a funciones inexistentes                |
| `public/assets/js/caja.js`             | Envía desc_global y precio, fix condición de carrera |
| `public/api/index.php`                 | calcular_carrito acepta desc_global y precio manual  |
| `public/caja_lib.php`                  | No incluye bootstrap.php                             |
| `public/promos_logic.php`              | No incluye bootstrap.php                             |
| `public/api/rol_eliminar.php`          | +CSRF                                                |
| `public/api/usuario_eliminar.php`      | +CSRF                                                |
| `public/api/usuario_toggle_estado.php` | +CSRF                                                |

### ⚠️ Notas sobre el estado actual

**APIs que aún existen pero podrían consolidarse:**

- `ventas_api.php` - Funcional, separado del switch principal
- Los 3 endpoints de usuarios/roles - Ahora con CSRF

**Lo que NO cambió (funciona como está):**

- Motor de cálculo `calcular_totales_con_promos` - Único motor, consistente
- PromoEngine - Se usa internamente por el motor de cálculo

---

## [2.1.1] - 2026-01-10 (SUPERSEDED - Tenía bugs en sync)

### 🔴 Correcciones Críticas

#### Unificación de Lógica de Precios (FIX CRÍTICO)

- **Nuevo endpoint `calcular_carrito`**: El frontend ahora puede consultar al servidor los precios exactos calculados con PromoEngine
- **Sincronización antes de cobrar**: La función `cobrar()` ahora SIEMPRE sincroniza con el servidor antes de procesar la venta
- **Nueva función `sincronizarCarritoConServidor()`**: Permite al frontend obtener precios calculados por el backend
- **Eliminación de riesgo de negocio**: Ya no hay posibilidad de que el cliente vea un precio y pague otro

### 🗑️ Código Eliminado (Limpieza)

Se eliminaron 10 archivos de código muerto/legacy:

- `/d` - Archivo basura (output de grep commiteado por error)
- `public/api/api.php` - Deprecado, solo redirigía
- `public/api/promos_api.php` - Deprecado
- `public/api/terminal_heartbeat.php` - Deprecado
- `public/api/terminal_list.php` - Deprecado
- `public/api/terminal_select.php` - Deprecado
- `public/api/terminal_status.php` - Deprecado
- `public/api/terminal_switch.php` - Deprecado
- `/views/` - Carpeta duplicada (las vistas reales están en `public/partials/`)

### 🔒 Mejoras de Seguridad

#### test_backup.php protegido

- Ahora requiere autenticación (`require_login()`)
- Requiere permiso `gestionar_backups`
- Ya no expone información del sistema a usuarios no autorizados

#### config.example.php mejorado

- Uso de constantes `define()` en lugar de variables globales
- Conexión PDO singleton (evita múltiples conexiones)
- Agregada constante `APP_DEBUG` para controlar información de debug
- Agregadas constantes `APP_NAME` y `APP_VERSION`

### 📁 Archivos Modificados

| Archivo                    | Cambio                                   |
| -------------------------- | ---------------------------------------- |
| `src/config.example.php`   | Refactorizado con constantes y singleton |
| `public/test_backup.php`   | Agregada protección de autenticación     |
| `public/api/index.php`     | Agregado endpoint `calcular_carrito`     |
| `public/assets/js/caja.js` | Agregada sincronización con servidor     |

### 🔧 Cambios Técnicos

#### Nuevo endpoint API: `calcular_carrito`

```
POST /api/index.php?action=calcular_carrito

Request:
{
  "csrf_token": "...",
  "items": [
    {"id": 123, "cantidad": 2},
    {"id": 456, "cantidad": 1.5}
  ]
}

Response:
{
  "ok": true,
  "items": [...],
  "total_bruto": 1500.00,
  "total_neto": 1350.00,
  "descuento_total": 150.00
}
```

#### Nueva función JavaScript: `sincronizarCarritoConServidor()`

- Llama al endpoint `calcular_carrito`
- Actualiza el carrito local con los precios del servidor
- Se ejecuta automáticamente antes de cada cobro
- Tiene debounce para evitar llamadas excesivas

### 📊 Métricas de la Limpieza

| Antes             | Después         | Reducción           |
| ----------------- | --------------- | ------------------- |
| 95 archivos PHP   | 87 archivos PHP | -8 archivos         |
| Lógica duplicada  | Centralizada    | ✅ Riesgo eliminado |
| 8 APIs deprecadas | 0               | -100%               |

---

## [2.0.0] - Versión anterior

- Sistema base con PromoEngine
- Multi-terminal con locks
- Split payments
- Dashboard con estadísticas
