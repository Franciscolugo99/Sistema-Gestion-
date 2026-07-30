# FLUS – Sistema de Gestión POS (PHP + MySQL)

Sistema web tipo **POS / gestión** para kioscos y comercios.

**Version:** 4.2.10
**Build:** 2026-07-30
**Release objetivo:** 4.2.10
**PHP:** 8.0+  
**Base de datos:** MySQL/MariaDB

---

## Estado de release 4.2.10 (2026-07-30)

- Smoke tecnico fuente: `173 pruebas / 0 fallidas / 1 omitida` (la prueba de
  alineacion de permisos requiere una base MySQL local disponible).
- Rama base local: `Ver-4.0.0`, creada desde `Ver-3.9.0`
- Nota operativa: la rama conserva el nombre historico `Ver-4.0.0`, aunque la version visible actual es `4.2.10`.
- Ruta fuente local validada: `C:\xampp82\htdocs\kiosco`.
- Licencias: validacion cloud contra FLUS Admin/Wiros con cache offline firmado para tolerar cortes de internet.
- Activacion cloud: el configurador exige URL y token, permite reparar equipos
  4.2.4/4.2.5 sin tocar datos operativos y ofrece diagnostico seguro con `-StatusOnly`.
- Sincronizacion cloud: una tarea local envia la cola automaticamente sin
  bloquear Caja, con idempotencia, exclusividad y reintentos progresivos.
- Presencia cloud: el worker informa un heartbeat cada cinco minutos aunque no
  existan ventas pendientes, para que el portal refleje correctamente si una
  sucursal esta conectada.
- Endpoints cloud: nuevas instalaciones usan `api.flus.com.ar`; el configurador
  valida licencia y sincronizacion antes de habilitar la tarea, conserva URLs
  personalizadas y migra solamente las URLs oficiales anteriores.
- Recuperacion cloud: Tecnico identifica bloqueos del hosting y permite
  reactivar hasta 25 eventos fallidos por vez despues de un preflight correcto.
- Historial cloud: Tecnico permite previsualizar y agregar por lotes ventas
  anteriores a Cloud sin modificar ventas, pagos, caja ni stock, con reintentos
  idempotentes y sin envio inmediato desde la accion manual.
- Cajas cloud: apertura y cierre se reportan una sola vez para visualizar
  estado operativo, ultimo cierre y diferencias por sucursal en el portal.
- Promociones: control global de disponibilidad y pausa diaria configurable para que Caja cobre sin promos en horarios definidos.
- Caja: control de turnos, terminales, permisos de cajero y ventas recientes endurecidos.
- Caja movimientos: el modal y la vista responden JSON de forma consistente en llamadas AJAX, incluso ante sesion/permisos faltantes, y evitan sintaxis PHP 8.1+ para instalaciones con PHP 8.0.
- Reglas de precio: trazabilidad generica de ajustes automaticos y redondeo por item y venta.
- Dashboard: Top productos permite cambiar criterio por unidades, ventas o ganancia y ampliar el limite visible sin recargar toda la pagina.
- UI operativa: Caja, movimientos, detalle de sesion, roles/permisos, usuarios y consultas de productos/stock reducen handlers inline y estilos sueltos, con estados visuales movidos a clases CSS.
- Buscadores de listados: comparten foco persistente, cursor, Enter, `Ctrl+K` y tratamiento de teclado movil; los buscadores operativos con autocompletado conservan su flujo especializado.
- Precios remotos: FLUS consulta ordenes de Wiroos desde el worker, vuelve a
  validar producto y precio esperado, bloquea la fila y registra historial una
  sola vez antes de confirmar el resultado al portal.
- Migraciones y baseline: versionados hasta `046_cloud_command_receipts.sql`.
- Tesoreria v1, facturacion, documentos comerciales, cuenta corriente y notas de credito quedan como base funcional de la linea 4.x.
- Salida actual: `4.2.10`, preparada en fuente; `api.flus.com.ar` supero el
  preflight autenticado de licencia y sincronizacion desde una instalacion
  4.2.6, sin enviar eventos ni modificar su cola.
- Panel Tecnico: consulta migraciones pendientes y permite aplicarlas de forma controlada a usuarios con permiso de configuracion.

### Nota sobre ARCA y entorno simulado

Esta base de trabajo se usa con datos simulados y homologacion tecnica.

- El entorno local hoy responde en `homologacion`, con `Preflight de emision` apto y prueba ARCA/WSFE operativa.
- Eso no equivale a cierre fiscal de produccion: sigue faltando validacion con datos reales del negocio y QA operativa del circuito completo.
- Si ARCA o WSAA no responde, el repo puede validarse tecnicamente, pero no queda probada la salida fiscal real.
- Antes de habilitar produccion en una instalacion real, completar datos reales del emisor, punto de venta, certificados, numeracion y prueba end-to-end.

Ver tambien:

- [docs/RELEASE_4_2_10.md](docs/RELEASE_4_2_10.md)

- [docs/RELEASE_4_2_9.md](docs/RELEASE_4_2_9.md)
- [docs/RELEASE_MESSAGE_4.2.9.md](docs/RELEASE_MESSAGE_4.2.9.md)
- [docs/RELEASE_4_2_8.md](docs/RELEASE_4_2_8.md)
- [docs/RELEASE_MESSAGE_4.2.8.md](docs/RELEASE_MESSAGE_4.2.8.md)

- [docs/RELEASE_4_2_7.md](docs/RELEASE_4_2_7.md)
- [docs/RELEASE_MESSAGE_4.2.7.md](docs/RELEASE_MESSAGE_4.2.7.md)
- [docs/RELEASE_4_2_6.md](docs/RELEASE_4_2_6.md)
- [docs/RELEASE_MESSAGE_4.2.6.md](docs/RELEASE_MESSAGE_4.2.6.md)
- [docs/RELEASE_4_2_5.md](docs/RELEASE_4_2_5.md)
- [docs/RELEASE_MESSAGE_4.2.5.md](docs/RELEASE_MESSAGE_4.2.5.md)
- [docs/RELEASE_4_2_4.md](docs/RELEASE_4_2_4.md)
- [docs/RELEASE_MESSAGE_4.2.4.md](docs/RELEASE_MESSAGE_4.2.4.md)
- [docs/RELEASE_4_2_2.md](docs/RELEASE_4_2_2.md)
- [docs/RELEASE_4_2_1.md](docs/RELEASE_4_2_1.md)
- [docs/TRABAJO_DOS_MAQUINAS.md](docs/TRABAJO_DOS_MAQUINAS.md)
- [docs/QA_PILOTO_CONTROLADO_4_2_1.md](docs/QA_PILOTO_CONTROLADO_4_2_1.md)
- [docs/RELEASE_4_2_0.md](docs/RELEASE_4_2_0.md)
- [docs/RELEASE_4_1_1.md](docs/RELEASE_4_1_1.md)
- [docs/QA_COMPRAS_STOCK_TESORERIA_4_1_1.md](docs/QA_COMPRAS_STOCK_TESORERIA_4_1_1.md)
- [docs/MERCADO_PAGO_QR_ASISTENTE.md](docs/MERCADO_PAGO_QR_ASISTENTE.md)
- [docs/REGLAS_PRECIO_4.1.0.md](docs/REGLAS_PRECIO_4.1.0.md)
- [docs/RELEASE_4_0_0.md](docs/RELEASE_4_0_0.md)
- [docs/RELEASE_3_9_0.md](docs/RELEASE_3_9_0.md)
- [docs/QA_FACTURACION_PRODUCCION.md](docs/QA_FACTURACION_PRODUCCION.md)
- [docs/PLAN_INTEGRACION_FLUS.md](docs/PLAN_INTEGRACION_FLUS.md)
- [docs/CONTRATO_FINANCIERO_FLUS.md](docs/CONTRATO_FINANCIERO_FLUS.md)

## Estado actual (2026-04-17)

### Ventas y anulaciones

- Soporte inicial para anulacion parcial no fiscal de ventas no facturadas.
- Historial de devoluciones dentro del detalle de venta, con motivo, usuario, fecha, monto devuelto y neto vigente.
- Detalle de venta rediseñado para distinguir mejor estado, metricas y productos devueltos parcial o totalmente.
- Listado y vista rapida de ventas con mejor lectura de estados `Parcial` y `Anulada`, mostrando original/devuelto/vigente.
- Criterio de ventas activas alineado para que `PARCIALMENTE_ANULADA` siga apareciendo en dashboard, reportes y exportaciones.

### Cuenta corriente

- Fix de compatibilidad de esquema para ventas y cobros CC en instalaciones viejas.
- El esquema base y las migraciones quedan alineados con el controlador de cuenta corriente y el arqueo por transferencia.

### Operacion y navegacion

- Nav refactorizado: partial mas limpio, CSS/JS externos y mejor consistencia visual.
- Header con ayuda rapida de atajos y mejor jerarquia para modulos principales.
- Base de perfiles de impresion para ticket, comanda y factura.
- Home principal mas directa: modulos mas claros, mejores tarjetas y accesos coherentes segun permiso real.

### Caja y terminales

- Caja mas robusta: recupera tickets pendientes por terminal/apertura y permite seguir cobrando despues de reabrir.
- Caja fullscreen ahora prioriza el flujo producto -> cantidad -> cobro, con estado vacio visible, ayudas contextuales y foco mas consistente para el trabajo de mostrador.
- Nuevo selector operativo de ticket: `Auto imprimir`, `Vista previa` o `No abrir`.
- El cobro rapido suma atajos directos para `Efectivo`, `Mercado Pago` y `Debito`, ademas de una restauracion real del item quitado y un mejor regreso al flujo principal despues de cobrar cuenta corriente.
- Pantalla de apertura de caja mas clara y alineada con el resto del sistema.
- Terminales renovado: estados operativos, edicion inline y bloqueos para no desactivar cajas abiertas o terminales en uso.
- Terminales ahora admite override de ticket/papel por caja sin tocar esquema.
- Flujo de permisos separado para `abrir_caja`, `realizar_ventas` y `cerrar_caja`, evitando rebotes a historial no autorizado.

### Clientes y relacion comercial

- Ficha de cliente nueva con resumen comercial, fiscal y de cuenta corriente.
- Clientes ahora enlaza mejor con ventas, facturacion y cuenta corriente.
- Drawer de clientes con actividad vinculada para no tratar al cliente como un ABM aislado.

### Inventario y compras

- Analisis de inventario mas operativo: conserva filtros, exporta segun la vista activa y agrega acciones rapidas por producto.
- Nuevas vistas de solo consulta para `productos` y `stock`, separadas del ABM completo.
- Compras e inventario fisico pueden abrir con busqueda precargada desde analisis.

### Compras

- Borradores automaticos mientras se carga una compra.
- Recuperacion del borrador al volver al modulo.
- Mejor responsive en el formulario de carga y en anchos intermedios.

### Dashboard, roles y administracion

- Dashboard parcialmente desmontado del monolito: filtros, cache y metricas extraidos a `src/Dashboard/`.
- Graficos estabilizados despues de filtros y cache, con mejor recuperacion de datasets.
- Modulo de licencia mas administrativo y menos expuesto a detalles internos.
- Rol `Operador` agregado para negocios chicos con caja + stock + compras sin abrir administracion sensible.
- Pantalla de roles/permisos reorganizada por areas de negocio, impacto real y vista previa de accesos.
- Cuenta admin de resguardo protegida: no permite cambiar rol, estado, usuario ni vaciar su rol base.

### Base tecnica

- Panel Tecnico interno para soporte, diagnostico y ejecucion de smoke tests desde la UI.
- Smoke tests minimos en `tests/` para helpers criticos y chequeos rapidos del sistema.
- Hardening general en login, backups/restore, usuarios y links publicos.
- Runner `scripts/migrate.php` + carpeta `migrations/` (**idempotente**).
- Baseline `install.sql` para instalacion limpia + migraciones para actualizar instalaciones existentes.
- Chequeos de esquema movidos fuera de `INFORMATION_SCHEMA` runtime en zonas calientes como nav, ventas, precios, caja, install y soporte.
- Politica de hotspots y budgets de archivos grandes documentada en `docs/HOTSPOT_POLICY.md`, con control basico desde el smoke para evitar rebotes de monolitos.

### Facturación electrónica

FLUS incorpora ahora un módulo completo de **facturación electrónica** que permite emitir comprobantes fiscales (A/B/C) de manera integrada al POS.  
Las principales capacidades son:

- **Listado de facturas:** busque y filtre facturas por fecha, cliente, número, tipo y estado, con accesos rápidos (Hoy/Semana/Mes). Se muestran KPIs del periodo (total facturado, ticket promedio, cantidad de facturas) y se puede exportar a CSV.
- **Emisión manual de facturas:** cree facturas sin venta previa. Seleccione el concepto (productos o servicios), busque productos con autocompletado, agregue líneas con cantidad, precio e IVA y visualice el total antes de emitir. El sistema calcula automáticamente alícuotas y solicita el CAE a ARCA/AFIP, generando el PDF oficial.
- **Facturación desde ventas:** desde el detalle de una venta puede emitirse la factura fiscal correspondiente. La librería de facturación determina el tipo de comprobante según la condición IVA del cliente y del emisor, genera la numeración y registra el CAE.
- **Configuración fiscal:** nuevo panel en `Administración → Configuración` para definir datos de la empresa (razón social, CUIT, domicilio, IIBB, punto de venta, condición IVA), subir certificados y claves para AFIP/ARCA, elegir el modo (Demo/Homologación/Producción), cargar el logo de la factura y establecer un límite de ítems por comprobante. Incluye pruebas de conexión y sincronización de numeración con ARCA.
- **Biblioteca de facturación:** funciones en `src/facturacion_lib.php` centralizan la lógica de emisión, cálculo de importes y comunicación con AFIP/ARCA. Soporta emisión desde ventas y manuales, validación de datos fiscales y generación de PDFs.

Estas funciones marcan el primer paso hacia un sistema de gestión más completo, permitiendo emitir comprobantes fiscales directamente desde FLUS.

### Estado actual de Facturación – Fase 1 (factura común)

Esta primera fase no rediseña todavía el modelo documental completo. El foco quedó puesto en **endurecer y unificar la emisión fiscal actual** sin romper compatibilidad legacy.

Avances concretos de Fase 1:

- **Entrada unificada:** la emisión desde venta y la emisión manual terminan en la misma capa de negocio, evitando seguir separando la lógica fiscal principal.
- **Entrypoints más finos:** `factura_nueva.php`, `factura_emitir.php` y `factura_manual.php` quedan más enfocados en entrada/resolución de datos y menos cargados de lógica fiscal.
- **Estados fiscales claros para factura común:** la factura común pasa a manejar estados explícitos para evitar ambigüedad ante fallas, rechazos o casos pendientes/reintentables.
- **Idempotencia y trazabilidad:** se incorpora `fiscal_request_uid` para reforzar el control de reenvíos, doble submit y retries.
- **Eventos ARCA:** se guarda rastro mínimo de request/response y errores para recovery simple y diagnóstico.
- **Retry manual endurecido:** si una factura manual ya inició un intento fiscal recuperable, el sistema reutiliza el mismo `request_uid` y la misma `venta_id` en vez de crear otra venta manual por cambios menores no fiscales.
- **Compatibilidad de esquema preservada:** esta fase se soporta por migración nueva y mantiene el contrato del proyecto: instalación limpia con `install.sql` y upgrades con `php scripts/migrate.php`.
- **Sin ampliar alcance:** todavía **no** se crean `documentos/documento_items`, no se elimina `venta_id`, y no se abre todavía Fase 2 ni Fase 3.

Migración asociada:

- `016_factura_comun_fiscal_flow.sql`

Esto deja la base preparada para una etapa posterior de recovery más fuerte y para la futura evolución documental, pero sin forzar ese cambio en esta fase.

> Ver `CHANGELOG.md` para el detalle histórico por versión.

### Estado actual de Facturación – Fase 2 (base documental mínima)

Esta segunda fase **no elimina todavía la venta manual legacy** ni rehace el modelo comercial completo. El objetivo fue introducir una base documental mínima, no destructiva y compatible con el flujo actual.

Avances concretos de Fase 2:

- **Capa documental mínima:** se incorporan `documentos_comerciales` y `documento_items` como base propia para preparar comprobantes manuales sin depender conceptualmente solo de `ventas`.
- **Compatibilidad preservada:** `facturas.venta_id` se mantiene vigente para el flujo legacy y se agrega `facturas.documento_id` como vínculo complementario, no destructivo.
- **Factura manual con base documental real:** la factura manual ahora puede crear o reutilizar primero un documento comercial interno y luego convivir con la venta manual legacy como puente de compatibilidad.
- **Idempotencia documental:** el flujo manual reutiliza el mismo documento por `request_uid`, evitando duplicar la base documental del mismo caso operativo.
- **Retry manual endurecido:** si el documento ya quedó vinculado a una `venta_id` base, el retry reutiliza esa misma venta manual legacy en vez de crear otra.
- **Compatibilidad del visor:** `factura_ver.php` queda preparado para reconstruir detalle desde `documento_items` cuando corresponda, con fallback legacy a `factura_items`, `venta_items` o `factura_manual_items`.
- **Modo no fiscal intacto:** si el módulo de facturación está desactivado, FLUS sigue funcionando como sistema no fiscal sin forzar lógica documental/fiscal adicional.
- **Sin agrandar alcance:** esta fase todavía **no** elimina la venta manual fake, **no** migra masivamente ventas a documentos y **no** abre todavía el desacople final del modelo.

Migración asociada:

- `017_facturacion_documentos_manual.sql`

Esto deja preparada una base documental real para una etapa posterior, pero manteniendo el puente legacy actual para no romper compatibilidad.

### Estado actual de Facturación – Fase 3 (base mínima de cobranzas)

Esta tercera fase **no implementa todavía recibos ni una conciliación completa**, y tampoco reemplaza `venta_pagos`, caja o cuenta corriente. El foco fue introducir una base mínima para empezar a separar **venta**, **cobro real**, **deuda CC** y **aplicación a documento/factura**.

Avances concretos de Fase 3:

- **Base mínima de cobranzas:** se incorporan `cobranzas` y `cobranza_aplicaciones` para registrar el hecho de cobro y su aplicación comercial/documental.
- **Complemento no destructivo:** la nueva capa convive con `venta_pagos`, `cuenta_corriente_movimientos` y `caja_movimientos`, sin reemplazar esos flujos en esta etapa.
- **Pagos reales de venta:** los pagos efectivamente cobrados pueden registrar una cobranza base ligada a la venta.
- **Cuenta corriente diferenciada:** la porción `CC` de una venta no se trata como cobranza real; el cobro posterior de CC puede registrar su propia cobranza ligada al movimiento correspondiente.
- **Enlace con factura/documento:** cuando una venta termina vinculada a factura o documento, la aplicación de cobranza puede enriquecerse con `factura_id` y `documento_id`.
- **Idempotencia básica:** la capa nueva contempla claves externas y aplicaciones para evitar duplicación del mismo cobro en retries razonables.
- **Modo no fiscal intacto:** esta fase no fuerza facturación ni altera el comportamiento del sistema cuando el módulo fiscal está apagado.
- **Sin abrir alcance mayor:** todavía **no** hay recibos, **no** hay notas de débito, **no** hay aplicación múltiple avanzada y **no** se reemplaza la operatoria actual de caja/CC.

Migración asociada:

- `018_cobranzas_base.sql`

Esto deja lista una base mínima para fases posteriores donde sí pueda crecer un subsistema más completo de cobranzas y aplicaciones, pero sin romper la operación actual.

### Estado actual de Facturación – Fase 4 (recibos y aplicaciones mínimas)

Esta cuarta fase **no reemplaza todavía cobranzas, caja, cuenta corriente ni recibos impresos legacy**. El foco fue sumar una capa mínima para distinguir mejor el **recibo** como constancia documental del cobro y su aplicación comercial.

Avances concretos de Fase 4:

- **Base mínima de recibos:** se incorporan `recibos` y `recibo_aplicaciones` para dejar constancia documental del recibo sin rehacer todavía el resto de la operatoria.
- **Complemento no destructivo:** la nueva capa convive con cobranzas, caja y cuenta corriente ya existentes.
- **Vínculos básicos:** un recibo puede asociarse a la cobranza, venta, documento o factura cuando corresponda.
- **Idempotencia mínima:** el alta y la aplicación del recibo evitan duplicaciones razonables del mismo caso comercial.
- **Modo no fiscal intacto:** el sistema sigue pudiendo operar como no fiscal sin exigir recibos/documentos nuevos en todos los flujos.
- **Sin agrandar alcance:** esta fase todavía **no** reemplaza el circuito completo de cobranzas/recibos, **no** rehace caja y **no** introduce conciliación avanzada.

Migración asociada:

- `019_recibos_aplicaciones.sql`

### Estado actual de Facturación – Fase 5 (reportes fiscales y reenvío comercial)

Esta quinta fase **no rehace todavía el módulo de facturación completo**. El objetivo fue volver más operativa la lectura de la facturación y sumar trazabilidad mínima del reenvío comercial al cliente.

Avances concretos de Fase 5:

- **Reportes fiscales mínimos:** la facturación gana mejor lectura operativa por estado fiscal, cliente, comprobante, CAE y vínculos asociados.
- **Trazabilidad de reenvío comercial:** se incorporan `envio_ultimo_canal`, `envio_ultimo_destino`, `envio_ultimo_estado`, `envio_ultimo_error`, `envio_ultimo_at` y `envio_intentos` para seguir el último reenvío comercial/email de la factura.
- **Reenvío mínimo al cliente:** la fase deja preparada una base de seguimiento de reenvíos sin acoplarla a la lógica fiscal con ARCA.
- **Compatibilidad preservada:** esta trazabilidad comercial convive con las fases fiscales anteriores sin cambiar el modelo principal de factura.
- **Sin mezclar dominios:** la semántica de `envio_ultimo_*` queda reservada al envío comercial al cliente y no a la interacción fiscal con ARCA.

Migración asociada:

- `020_facturas_envio_trazabilidad.sql`

### Estado actual de Facturación – Fase 6 (presupuestos/remitos documentales)

Esta sexta fase **no convierte todavía FLUS en un módulo comercial completo** ni elimina la compatibilidad legacy. El objetivo fue aprovechar la base documental existente para soportar **presupuestos** y **remitos** con una operatoria mínima pero realista.

Avances concretos de Fase 6:

- **Documentos comerciales reales:** `documentos_comerciales` pasa a soportar `PRESUPUESTO` y `REMITO` como documentos operativos visibles desde su propio listado y detalle.
- **Relaciones documentales mínimas:** queda lista la base para vincular presupuesto → remito → factura / venta sin rehacer todavía toda la cadena comercial.
- **Borrador vs operación real:** un presupuesto o remito puede existir sin cliente solo como borrador; para generar remito, generar venta, vincular venta o emitir factura debe tener cliente.
- **Acciones guiadas en UI:** el detalle documental muestra mejor el siguiente paso operativo, el impacto real en stock y cuándo el documento ya hizo “lo suyo”.
- **No hacer dos veces lo mismo:** si el documento ya tiene remito, venta o factura vinculada, la UI y el backend dejan de ofrecer o aceptar repetir esa misma acción.
- **Conversión operativa real a venta:** cuando todos los ítems matchean de forma estricta por `productos.codigo` y hay stock suficiente, la conversión usa `ventas`, `venta_items`, baja stock y registra `movimientos_stock`.
- **Fallback legacy controlado:** si el documento no puede mapear confiablemente a productos reales, la conversión cae a venta manual legacy sin tocar stock.
- **Hardening de stock:** la conversión operativa valida de nuevo dentro de transacción, bloquea productos con `FOR UPDATE`, suma demanda por SKU repetido y evita dobles descargas silenciosas.
- **Cliente cruzado protegido:** al vincular una venta existente se bloquean clientes cruzados y, si la venta no tenía cliente, se puede completar con el del documento.
- **Modo no fiscal intacto:** toda la capa documental sigue conviviendo con el módulo de facturación apagado, sin romper anulaciones ni la operación no fiscal.

Migración asociada:

- `021_documentos_relaciones_presupuestos_remitos.sql`

### Estado actual de Facturación – Fase 7 (contingencia fiscal mínima de factura común)

Esta séptima fase **no introduce CAEA, colas en background ni una arquitectura nueva de conciliación**. El foco fue cerrar una contingencia mínima y más segura para factura común cuando el problema ocurre entre la autorización remota y la aplicación local.

Avances concretos de Fase 7:

- **Regularización mínima de factura común:** se incorpora `facturacion_recovery.php` para seguir y regularizar casos `PENDIENTE_ENVIO`, `ERROR_TRANSITORIO` y `ERROR_POST_ARCA`.
- **Estados fiscales más claros:** `ERROR_POST_ARCA` queda formalizado para distinguir fallos locales posteriores a una autorización remota, y `RECUPERADA` permite marcar un cierre correcto por recovery.
- **Recovery simple reutilizado:** la regularización reutiliza `request_uid`, `factura_eventos_arca` y la consulta/recovery simple ya disponible, sin reemitir a ARCA a ciegas.
- **Visibilidad operativa:** `facturacion.php` y `factura_ver.php` muestran mejor qué casos requieren intervención mínima y permiten iniciar la regularización.
- **Separación correcta de dominios:** la contingencia fiscal se apoya en `estado_fiscal`, `fiscal_error_code`, `fiscal_error_message`, `fiscal_requested_at`, `fiscal_approved_at` y `factura_eventos_arca`; la trazabilidad `envio_ultimo_*` queda reservada exclusivamente al reenvío comercial/email al cliente.
- **Sin agrandar alcance:** esta fase todavía **no** implementa CAEA, **no** agrega workers/schedulers y **no** rehace el modelo fiscal entero.

Migración asociada:

- `022_facturas_fiscal_contingencia.sql`

### Notas de Crédito fiscales

FLUS incorpora gestión de **Notas de Crédito (NC)** sobre comprobantes ya emitidos, integrada al módulo de Facturación.

Capacidades principales:

- **NC total** sobre una factura origen.
- **NC parcial por ítem**, acreditando solo cantidades seleccionadas.
- Visualización de:
  - total original,
  - cantidad ya acreditada,
  - saldo fiscal restante,
  - estado comercial/fiscal asociado.
- Protección contra reenvíos y doble submit mediante idempotencia reforzada.
- Pantalla de recovery para casos `ERROR_POST_ARCA`, donde la parte fiscal fue aprobada pero falló la aplicación local/comercial.

Permiso específico:

- `emitir_nota_credito`

Archivos principales:

- `public/facturacion_nc.php`
- `public/facturacion_nc_emitir.php`
- `public/facturacion_nc_recovery.php`
- `src/Fiscal/Service/DbAnulacionFiscalCoordinator.php`
- `src/Fiscal/Service/DbFiscalRecoveryService.php`

Migraciones relacionadas:

- `010_anulaciones_parciales.sql`
- `011_cc_schema_compat.sql`
- `012_venta_anulaciones_fiscal.sql`
- `013_facturas_fiscal_ext.sql`
- `014_factura_items_eventos_arca.sql`
- `015_fiscal_nc_hardening.sql`
- `016_factura_comun_fiscal_flow.sql`

## Actualizacion de instalaciones existentes

- Hacer backup de archivos y base de datos antes de desplegar.
- Copiar la nueva version y ejecutar `php scripts/migrate.php`.
- Verificar que corran las migraciones pendientes hasta `046_cloud_command_receipts.sql`.
- Validar modulos criticos despues del deploy, incluyendo facturación, documentos comerciales, cobranzas/recibos y recovery fiscal mínimo.
- Usar la guia de [docs/UPGRADE_3.8.3.md](docs/UPGRADE_3.8.3.md).

Si la instalacion va a usar facturacion real:

- completar los datos fiscales reales del negocio
- probar conexion con ARCA desde configuracion
- no tomar este entorno demo/simulado como validacion fiscal final

## Instalacion limpia

1. Configurar DB y credenciales en `src/config.php` (o el flujo de `public/install.php`).
2. Importar `install.sql`.
3. Ejecutar `php scripts/migrate.php`.
4. Ingresar con:
   - usuario: `admin`
   - clave: `flusadmin123`
5. Cambiar la clave inicial apenas termine la instalacion. Si se ingresa con la clave provisoria, FLUS fuerza el cambio antes de operar.
6. Dejar `public/install.php` solo como verificador: si existe `src/config.php`, no reconfigura ni crea tablas desde el navegador.

## Requisitos

- PHP 8.0+
- MySQL/MariaDB
- Apache/Nginx
- Extensiones PHP típicas: `pdo_mysql`, `json`, `mbstring`, `zip`

---

## Instalación rápida (dev)

1. Configurar DB y credenciales en `src/config.php` (o el flujo de `public/install.php`).
2. Si la base esta vacia, importar `install.sql`.
3. Ejecutar migraciones:
   ```bash
   php scripts/migrate.php
   ```
4. Abrir el sistema desde `public/`.

## Checklist de release / deploy

1. Confirmar backup de base y proyecto.
2. Copiar archivos nuevos al servidor.
3. Ejecutar `php scripts/migrate.php`.
4. Validar login, ventas, anulaciones parciales, compras, productos, stock y proveedores.
5. Validar facturación, documentos comerciales (presupuesto/remito), cobranzas/recibos y recovery fiscal mínimo.
6. Revisar `src/version.php` en la instancia desplegada.

---

## Smoke test (minimo)

- Login + cambio de tema
- Panel Tecnico: abrir, ejecutar smoke tests y validar salida en verde
- Caja: venta simple / anulacion (si aplica) / no duplica por doble click
- Ventas: anulacion parcial no fiscal, anulacion total posterior y detalle con historial consistente
- Productos: busqueda por codigo, edicion basica, pesables y cambio de estado
- Stock: ajuste rapido, historial reciente y visualizacion de pesables
- Proveedores: editar, ver historial/productos y probar re-vinculacion puntual o global
- Backups: crear + validar
- Diagnostico: generar paquete ZIP
- Inventario fisico: crear sesion + conteo + cerrar + aplicar ajustes
- Facturación: emitir desde venta, emitir manual, revisar `factura_ver.php` y probar filtro/listado fiscal
- Documentos comerciales: crear presupuesto, convertir a remito/venta cuando corresponda y validar bloqueo de doble acción
- Cobranzas/Recibos: registrar caso base, revisar vínculo con factura/documento y validar que no duplique razonablemente
- Recovery fiscal mínimo: revisar un caso `PENDIENTE_ENVIO`/`ERROR_TRANSITORIO`/`ERROR_POST_ARCA` y confirmar que la regularización no pisa la trazabilidad de email

---

## Documentación histórica (v2.3.x)

Contenido recuperado desde commit 3c12bdf para no perder notas operativas.

# FLUS – Sistema de Gestión POS (PHP + MySQL)

Sistema web tipo **POS / gestión** para kioscos y comercios.

**Version:** 2.3.1  
**PHP:** 8.0+  
**Base de datos:** MySQL/MariaDB

---

## 🆕 Novedades v2.3.1 (2026-01-28)

- ✅ Correcciones de estabilidad en endpoints (p. ej. `strict_types` en acciones PHP).
- ✅ Ajustes y refactors menores en APIs: **cuenta corriente**, **inventario**, **clientes**.
- ✅ Mejoras de consistencia en exportaciones (sesión de caja / dashboard).

> Ver [CHANGELOG.md](CHANGELOG.md) para el detalle y el histórico.

---

## 🆕 Novedades v2.3.0

- ✅ **Ventas**: Historial avanzado con filtros, KPIs, gráficos y **exportación CSV**
- ✅ **Ventas**: **Autocompletado de clientes** (dropdown visual + teclado)
- ✅ **Ventas**: **Ticket público compartible** (link con token) + acciones WhatsApp/Email
- ✅ **Backups**: mejoras de robustez/UX + lock de restore

> Ver [CHANGELOG.md](CHANGELOG.md) para el detalle completo y notas de upgrade.

---

## 🧠 Arquitectura recomendada (LAN)

- **Servidor**: PC donde corre Apache/PHP + BD (y donde vive `storage/`).
- **Terminales**: PCs que ingresan por navegador vía LAN (no ejecutan PHP local).

**Importante:** funcionalidades como _ticket público_ se validan en el **servidor**, por eso secretos como `APP_SECRET` deben existir y mantenerse estables ahí.

---

## ✨ Características Principales

### 🏪 Punto de Venta (Caja)

- Carga rápida por código de barras o búsqueda
- Soporte para productos **pesables** (carnicería, fiambres, frutas)
- **Split payments** (pagos con 2 medios)
- Descuentos globales por monto o porcentaje
- Generación de ticket térmico (58mm/80mm)
- Atajos de teclado (F2 cobrar, F4 cancelar, F5 foco)

### Productos & Stock

- ABM con panel lateral de edicion
- Stock minimo con alertas
- Productos pesables (KG/G/LT/ML) con visualizacion consistente en tabla, modal y detalle
- Filtros por pesables y busqueda priorizada por codigo
- Stock con ajuste rapido e historial reciente por producto
- Categorias, marcas y proveedores

### Proveedores

- ABM con drawer lateral y resumen operativo
- Ultima compra, compras recientes y productos asociados
- Re-vinculacion de productos legacy por proveedor o global
- Mejor soporte para mantener sincronizado proveedor – productos

### 🎁 Promociones

- **NxM**: Llevás N, pagás M (ej: 3x2)
- **N° al X%**: Cada N unidades, descuento del X%
- **Combos fijos**: Productos combinados a precio especial
- Motor centralizado (PromoEngine)

### 🖥️ Multi-Terminal

- Soporte para múltiples cajas/terminales
- Sistema de locks para evitar conflictos
- Heartbeat para detectar cajas inactivas

### 👥 Usuarios & Seguridad

- RBAC (Roles y Permisos)
- CSRF protection en todos los forms
- Auditoría de acciones
- Sesiones seguras

### 📊 Reportes

- Dashboard con estadísticas
- Historial de ventas con filtros
- Exportación CSV
- Historial de caja

---

## 🚀 Instalación Rápida

### 1. Requisitos

- PHP 8.0+ (recomendado 8.1/8.2)
- MySQL/MariaDB 5.7+
- Apache con mod_rewrite (XAMPP recomendado en Windows)
- Extensiones PHP: `pdo_mysql`, `mbstring`, `openssl`

### 2. Configuración

```bash
# Clonar o descomprimir
cd /htdocs/flus

# Copiar configuración
cp src/config.example.php src/config.php

# Editar credenciales de BD
nano src/config.php
```

### 3. Base de Datos

```sql
CREATE DATABASE kiosco CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Luego importar `install.sql` y ejecutar `php scripts/migrate.php`.

### 4. Acceder

```
http://localhost/flus/public/install.php
```

---

## 📁 Estructura del Proyecto

```
flus/
├── public/              # Archivos web accesibles
│   ├── api/            # Endpoints REST
│   ├── assets/         # CSS, JS
│   ├── includes/       # PromoEngine, Controllers
│   ├── lib/            # Helpers core
│   └── partials/       # Fragmentos HTML
├── src/                # Lógica backend
│   ├── config.php      # Configuración (crear desde example)
│   ├── helpers.php     # Funciones globales
│   └── *.php           # Controllers y libs
├── storage/            # Archivos generados (backups, logs)
└── scripts/            # Scripts CLI
```

---

## 🔐 Permisos Disponibles

| Permiso                 | Descripción                                     |
| ----------------------- | ----------------------------------------------- |
| `realizar_ventas`       | Usar la caja                                    |
| `cerrar_caja`           | Cerrar sesión de caja                           |
| `editar_productos`      | ABM de productos                                |
| `editar_stock`          | Ajustar stock                                   |
| `ver_reportes`          | Ver historial de ventas                         |
| `administrar_usuarios`  | Gestionar usuarios y roles                      |
| `gestionar_backups`     | Crear/restaurar backups                         |
| `ver_diagnostico`       | Ver diagnostico y descargar paquetes de soporte |
| `administrar_config`    | Configuración del sistema                       |
| `caja_modificar_precio` | Cambiar precios en caja                         |

---

## 📡 API Endpoints

| Endpoint                                     | Método | Descripción                            |
| -------------------------------------------- | ------ | -------------------------------------- |
| `?action=buscar_producto`                    | GET    | Buscar por código                      |
| `?action=buscar_productos`                   | GET    | Buscar por nombre (autocomplete)       |
| `?action=calcular_carrito`                   | POST   | Calcular precios con promos            |
| `?action=registrar_venta`                    | POST   | Registrar venta                        |
| `?action=listar_promos_activas`              | GET    | Promociones vigentes                   |
| `?action=terminal_list`                      | GET    | Listar terminales                      |
| `ventas_api.php?action=listar_ventas`        | GET    | Listado de ventas (filtros/paginación) |
| `ventas_api.php?action=venta_preview`        | GET    | Preview de venta (modal)               |
| `ventas_api.php?action=stats`                | GET    | KPIs/series para gráficos              |
| `ventas_api.php?action=buscar_clientes`      | GET    | Autocomplete de clientes               |
| `ventas_api.php?action=ticket_publico_url`   | GET    | Generar URL/token de ticket público    |
| `ventas_api.php?action=send_ticket_whatsapp` | POST   | Preparar envío por WhatsApp (wa.me)    |
| `ventas_api.php?action=send_ticket_email`    | POST   | Envío por Email (si está habilitado)   |

---

## 🛠️ Desarrollo

### Convenciones

- PHP: `declare(strict_types=1)` en todos los archivos
- SQL: Prepared statements obligatorios
- JS: ES6+ sin transpilación
- CSS: BEM-like con prefijos por módulo

### Testing

```bash
# Verificar sintaxis PHP
find . -name "*.php" -exec php -l {} \;

# Verificar que la API responde
curl http://localhost/flus/public/api/index.php?action=health
```

---

### 🔄 Upgrade desde versiones anteriores

### Desde v2.3.0 a v2.3.1

1. **Backup recomendado** (BD + carpeta `storage/`)
2. **Reemplazar archivos**
   - Conservar `src/config.php`
   - **No pisar `storage/`** (logs, backups, uploads, locks, etc.)
3. **Migraciones**
   - Esta versión es principalmente de mantenimiento. Si tu branch incluyó cambios de BD, agregá y documentá un script `scripts/upgrade_v231.sql`.
4. **Verificación rápida**
   - Caja / Productos: búsqueda y autocompletado
   - Inventario: consultas principales
   - Reportes / exportaciones: generar CSV sin errores

---

### Desde v2.2.x a v2.3.0

1. **Backup obligatorio** (BD + carpeta `storage/`)
2. **Reemplazar archivos**
   - Conservar `src/config.php`
   - **No pisar `storage/`** (logs, backups, uploads, locks, etc.)
3. **Cosas a considerar**
   - Agregar `storage/restore.lock` al `.gitignore` (runtime, no va al repo).
   - Si vas a usar **ticket público**, definir un `APP_SECRET` real en el **servidor** (no usar el secreto por defecto).
     - Recomendado: persistirlo en `storage/app_secret.key` para que no cambie entre upgrades.
     - El link incluye `ts` y el token **expira**: TTL por defecto 7 días (configurable con `TICKET_TOKEN_TTL_SECONDS`).
   - Si tu instalación usa pagos mixtos: la tabla `venta_pagos` mejora la calidad de los reportes; si no existe, FLUS funciona igual (modo compat).

4. **Verificación rápida**
   - Ventas → filtrar y exportar CSV
   - Abrir preview de una venta
   - Generar link de ticket público y abrirlo (debe validar token)

---

### Desde v2.1.x a v2.2.0

1. **Backup obligatorio**

```bash
mysqldump -u root -p kiosco > backup_antes_v220.sql
```

2. **Reemplazar archivos**

- Subir los nuevos archivos (conservar `src/config.php`)

3. **Ejecutar script SQL**

```bash
mysql -u root -p kiosco < scripts/upgrade_v220.sql
```

4. **Verificar**

- Entrar al sistema y probar crear/eliminar un producto que esté en un combo
- Si funciona sin errores, el upgrade fue exitoso

## 🧭 Roadmap corto (WIP)

- Licencias: en **Acerca de** mostrar plan/vencimiento/días restantes leyendo `storage/license.json` (pendiente).
- Documentar upgrade SQL por versión (un archivo por release cuando aplique).
