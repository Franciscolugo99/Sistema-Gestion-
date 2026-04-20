# Release 3.9.0

Fecha base: 2026-04-17

Objetivo: sacar FLUS 3.9.0 como release estable, con alcance congelado y criterio de salida claro.

## Estado de partida

- smoke tecnico en verde: `118 OK / 0 fallidas / 0 skipped`
- migraciones versionadas y baseline presentes
- modulos principales operativos:
  - ventas
  - caja
  - compras
  - clientes
  - cuenta corriente
  - tesoreria v1
  - facturacion
  - documentos comerciales
  - notas de credito
  - panel tecnico

## Regla de esta release

Desde este punto, 3.9.0 entra en modo cierre:

- no agregar features grandes nuevas
- solo entran:
  - fixes
  - hardening
  - QA
  - ajustes chicos de UX operativa
  - alineacion de docs y versionado

## Bloqueantes de salida

La release no sale si falla cualquiera de estos puntos:

1. smoke tecnico con fallas
2. migraciones con drift o instalaciones que no actualizan limpio
3. preflight fiscal en error para el entorno objetivo
4. errores visibles en emision fiscal, visor o PDF
5. errores visibles en caja, compras o tesoreria
6. permisos rotos en modulos sensibles

## Camino propuesto

### Fase 1 - Base tecnica

- [x] dejar smoke tecnico en verde
- [x] correr `php scripts/migrate.php` sobre una base real de prueba
- [x] validar instalacion limpia con `install.sql`
- [x] validar upgrade sobre instalacion existente

Resultado validado el 2026-04-17:

- `scripts/migrate.php` sobre la base local actual: OK, sin pendientes
- instalacion limpia sobre base temporal + migraciones `002` a `028`: OK

### Fase 2 - QA operativa corta

#### Caja

- [ ] venta simple
- [ ] split payment
- [ ] cuenta corriente
- [ ] impresion / vista previa
- [ ] reapertura o continuidad de caja si aplica

#### Compras

- [ ] alta de compra
- [ ] borrador automatico
- [ ] confirmacion
- [ ] anulacion controlada

#### Tesoreria v1

- [ ] alta de cuenta
- [ ] alta de categoria
- [ ] registrar movimiento
- [ ] registrar obligacion
- [ ] revisar reportes

#### Facturacion

- [ ] configuracion fiscal completa
- [ ] preflight de emision en estado apto
- [ ] emision desde venta
- [ ] factura manual
- [ ] visor de comprobante
- [ ] PDF
- [ ] recovery fiscal
- [ ] nota de credito

Resultado objetivo relevado el 2026-04-17:

- [x] sintaxis PHP valida en:
  - `public/caja.php`
  - `public/compras.php`
  - `public/tesoreria.php`
  - `public/tesoreria_reportes.php`
  - `public/facturacion.php`
  - `public/facturacion_config.php`
  - `public/factura_manual.php`
  - `public/factura_ver.php`
  - `public/factura_pdf.php`
  - `public/facturacion_recovery.php`
  - `public/facturacion_nc.php`
- [x] permisos base presentes para configuracion, ventas, tesoreria y facturacion
- [x] esquema documental/cobranzas/recibos listo segun helpers del sistema
- [ ] QA manual funcional pendiente por modulo

### Fase 3 - Validacion fiscal real

Si 3.9.0 va a salir con facturacion real habilitada:

- [ ] completar IIBB
- [ ] completar inicio de actividades
- [ ] validar CUIT del emisor
- [ ] validar punto de venta
- [ ] validar certificados y clave
- [ ] validar entorno ARCA correcto (`demo`, `homologacion`, `produccion`)
- [ ] ejecutar prueba de conexion
- [ ] validar numeracion

Estado actual relevado el 2026-04-17:

- `facturacion_habilitada = 1`
- `facturacion_modo = homologacion`
- `iibb = NULL`
- `inicio_actividades = NULL`
- `facturacion_arca_status = unavailable`
- ultimo error ARCA/WSAA: autenticacion con `Zero length BigInteger`
- entorno de referencia: simulado, sin datos reales de negocio para `iibb` ni `inicio_actividades`

Interpretacion:

- hoy facturacion existe y compila, pero la salida fiscal real no esta lista para release estable
- 3.9.0 solo puede salir con fiscal real si primero se completa configuracion y se resuelve ARCA
- si no se resuelve ese frente, conviene declarar salida controlada o no fiscal
- en este repo puntual no puede cerrarse la validacion fiscal real porque no hay datos de negocio reales para completar el emisor

Siguiente chequeo recomendado para ARCA:

1. completar `iibb`
2. completar `inicio_actividades`
3. volver a ejecutar la prueba de conexion desde configuracion fiscal
4. si sigue en `Zero length BigInteger`, revisar en WSASS/ARCA que el certificado actual este asociado al servicio de facturacion del CUIT correcto
5. solo si eso esta bien y aun falla, tratarlo como incidencia externa o intermitencia del entorno homo

Si la salida no incluye fiscal real:

- [x] dejar documentado que 3.9.0-rc1 sale con modo demo/controlado

### Fase 4 - Cierre de release

- [x] actualizar `CHANGELOG.md`
- [x] actualizar `README.md`
- [x] subir version visible a `3.9.0-rc1`
- [x] preparar nota corta de release
- [ ] generar tag o commit de release

## Criterio de aprobacion

3.9.0 queda lista para salir cuando:

- smoke tecnico termina en verde
- caja, compras, tesoreria y facturacion pasan QA corta
- instalacion limpia y upgrade actualizan sin sorpresas
- el entorno fiscal objetivo queda consistente
- changelog y version quedan alineados

## Orden recomendado de trabajo

1. migraciones e instalacion
2. QA de caja
3. QA de compras
4. QA de tesoreria
5. QA de facturacion
6. cierre de docs y version

## Decisiones que conviene dejar explicitas

Antes de cerrar 3.9.0, definir:

- si facturacion real sale habilitada o no
- si tesoreria v1 entra como modulo estable o como primera entrega controlada
- si la release se publica como `3.9.0` final o se corta antes una `3.9.0-rc1`

## Salida minima sugerida

Si no aparece ningun hallazgo fuerte en QA:

- release recomendada: `3.9.0`

Si aparece algun hallazgo menor pero no estructural:

- release recomendada: `3.9.0-rc1`

Si hoy hubiera que cortar sin resolver ARCA:

- release recomendada: `3.9.0-rc1`
- condicion: dejar explicitado que la validacion fiscal real queda pendiente

## Foto actual

Al cierre de esta revision:

- base tecnica: OK
- smoke tecnico: OK
- upgrade real: OK
- instalacion limpia + migraciones: OK
- superficie principal PHP: OK
- bloqueo objetivo pendiente: configuracion fiscal / ARCA
