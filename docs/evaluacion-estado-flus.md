# Evaluacion del estado de FLUS

Fecha de corte: 2026-03-26

## Versiones y fechas ordenadas

Lectura normalizada del repo:

- `3.7.0` - `2026-03-22`
- `3.7.1` - `2026-03-22`
- `3.8.0` - `2026-03-22`
- `3.8.1` - `2026-03-23`
- `Unreleased` - trabajo posterior a `3.8.1`

Fuentes tomadas como referencia:

- `CHANGELOG.md`
- `README.md`
- `src/version.php`

La normalizacion anterior deja alineados:

- `README.md`: version `3.8.1`, build `2026-03-23`
- `src/version.php`: version `3.8.1`, build `2026-03-23`
- `CHANGELOG.md`: `3.8.0` antes de `3.8.1`

## 1. Resumen ejecutivo

FLUS hoy se ve como un sistema real y operable, no como un prototipo fragil. El repo muestra un POS/gestion con base comercial amplia y con una evolucion tecnica visible: instalacion limpia por `install.sql`, upgrades por migraciones, smoke tests orientados a contratos, y una expansion fiscal hecha por fases en vez de meter todo de golpe. Eso es una senal clara de madurez.

La lectura critica es esta: FLUS esta bien parado como producto, especialmente para comercio minorista, pero ya entro en una etapa donde el principal riesgo no es que falten funciones, sino que la complejidad empiece a crecer mas rapido que la claridad del modelo. Hoy conviven venta, factura, documento comercial, cobranza, recibo, recovery fiscal y nota de credito. Eso lo acerca a un sistema mucho mas serio, pero tambien mas costoso de mantener si no se siguen separando dominios con rigor. Esa tension aparece de forma explicita en `README.md`, donde se documentan capas fiscales y comerciales sucesivas con compatibilidad legacy preservada.

La ausencia de archivos sensibles fuera del repo, como configuraciones concretas o credenciales reales, limita validar ejecucion end-to-end, conectividad externa y pruebas contra una base real ya preparada. Igual no invalida esta evaluacion: la estructura, el modelo y la direccion tecnica si son visibles. Lo no verificado en entorno real debe leerse como limite de comprobacion, no como defecto probado.

## 2. Lo mejor que tiene hoy

### a) Valor operativo real

El sistema no muestra una cobertura superficial de modulos. El repo expone caja, ventas, clientes, cuenta corriente, compras, inventario, roles, backups, panel tecnico, facturacion, documentos comerciales, cobranzas y recovery. Eso lo posiciona como un sistema usable en negocio real y no solo como un POS basico con ABMs alrededor.

### b) Evolucion ordenada

La evolucion reciente se ve deliberada. El `CHANGELOG.md` documenta una secuencia clara: Fase 1 factura comun, Fase 2 base documental, Fase 3 cobranzas, Fase 4 recibos, Fase 5 trazabilidad comercial, Fase 6 presupuestos/remitos y Fase 7 contingencia fiscal. Eso no prueba calidad por si solo, pero si evidencia criterio incremental.

### c) Base tecnica mejor que la media

Hay baseline `install.sql`, runner de migraciones, seeds de permisos, rol admin, usuario inicial y un contrato de instalacion limpio vs upgrades que el propio repo declara de forma consistente. Eso vale mucho en este tipo de producto.

### d) Operabilidad mientras crece

Panel tecnico, diagnostico, smoke tests desde UI, checklist de release y smoke minimo post-deploy muestran preocupacion real por soporte. Muchos sistemas chicos crecen en features pero no en capacidad de recuperacion. Aca si aparece esa inversion.

### e) Cuenta corriente con criterio transaccional

La fuente de verdad esta en `cuenta_corriente_movimientos`, mientras `clientes.cc_saldo` funciona como cache. El uso de transacciones, `SELECT ... FOR UPDATE` y reversas en vez de editar historial es conceptualmente correcto y bastante solido.

### f) Capa fiscal con robustez real

No se ve solo conectividad con ARCA. Se ve `request_uid`, estados fiscales, eventos, recovery, regularizacion y separacion explicita entre trazabilidad fiscal y reenvio comercial. Eso esta por encima de la madurez habitual de sistemas chicos.

### g) Modo no fiscal como fortaleza comercial

El sistema mantiene una operacion no fiscal usable aun cuando el modulo fiscal esta apagado. Eso mejora el encaje comercial porque permite vender FLUS por escalones de complejidad.

## 3. Lo mas debil o riesgoso hoy

### a) El riesgo principal ya es semantico

Hoy el sistema maneja venta, factura, documento comercial, cobranza, recibo, aplicacion, recovery y nota de credito. Eso aumenta potencia, pero tambien el riesgo de solapar conceptos. El propio changelog ya muestra correcciones orientadas a que trazas distintas no compartan significado por error.

### b) Persistencia de peso legacy

`README.md` deja explicito que siguen conviviendo `facturas.venta_id`, la venta manual legacy y varios fallbacks no destructivos. Eso fue correcto para no romper produccion, pero aumenta ramas de compatibilidad y costo de mantenimiento.

### c) Logica importante aun concentrada en paginas publicas

Archivos como `public/facturacion.php` no operan solo como vistas: concentran filtros, SQL, KPIs, exportaciones, incidencias y acciones de navegacion operativa. Eso indica que aun hay logica de aplicacion pegada a entrypoints publicos.

### d) Controladores demasiado centrales

`CuentaCorrienteController` parece conceptualmente bueno, pero tambien muy cargado: validaciones monetarias, duplicados, compatibilidad de esquema, transacciones, caja, cobranzas, recibos, reversas, KPIs, listados, ajustes y recalculos. Eso lo vuelve una pieza valiosa pero fragil a cambios cruzados.

### e) Distancia creciente entre baseline y estado actual

El baseline sigue representando una foto mas simple que el sistema vigente, mientras gran parte del estado real vive en migraciones sucesivas. Esa disciplina es correcta, pero exige mucha consistencia para no terminar en instalaciones heterogeneas.

### f) Smoke tests fuertes, pero no equivalentes a integracion real

`tests/smoke.php` cubre invariantes valiosas: permisos, baseline vs migraciones, fiscalidad, idempotencia, documentos, cobranzas, recibos y recovery. Eso esta bien. Pero la fuerte presencia de `FakePdo` muestra que una parte importante de la confianza viene de simulacion logica y no de integracion completa sobre DB real.

### g) Riesgo de sobrecargar la UX

La riqueza funcional ya puede empezar a sentirse mas ERP que gestion agil si no se jerarquiza bien por permiso, perfil y contexto. Esto no es un defecto actual probado, pero si una zona de tension para comercio chico.

## 4. Evaluacion de la arquitectura y la evolucion

FLUS si esta creciendo con criterio, pero se acerca al punto donde ese criterio necesita volverse mas rigido para no degradarse.

Lo positivo:

- evolucion fiscal fragmentada por fases
- documentacion de alcance e intencion en `README.md` y `CHANGELOG.md`
- disciplina de baseline + migraciones
- smoke tests orientados a contratos importantes

Lo preocupante:

- demasiada logica relevante aun vive en paginas publicas o controladores grandes
- la compatibilidad legacy sigue siendo parte importante del costo estructural
- el crecimiento del dominio ya supera lo que suele tolerar comodamente una base pensada primero como POS

El crecimiento no parece descontrolado. El riesgo hacia adelante no es un bug aislado, sino la acumulacion de acoplamientos semanticos.

## 5. Evaluacion especifica del modulo fiscal

La direccion actual del modulo fiscal es conceptualmente correcta.

Las mejores senales son estas:

- existe una capa fiscal con bootstrap, contratos, DTOs, repositorio y servicios para nota de credito y recovery
- se valida explicitamente el esquema requerido antes de habilitar ciertos flujos fiscales
- la UI ya distingue `estado_fiscal`, incidencias, regularizacion y recovery

La robustez gano mucho con:

- `request_uid`
- idempotencia
- `ERROR_POST_ARCA`
- `RECUPERADA`
- separacion entre traza fiscal y traza comercial/email

Los principales riesgos siguen siendo:

1. Superposicion de dominios.
2. Compatibilidad legacy prolongada.
3. Dependencia alta de disciplina interna mas que de una bateria amplia de integracion end-to-end.

La capa fiscal hoy mejora la calidad del sistema; no parece solo complejidad agregada. Pero esa mejora depende de sostener con mucha disciplina la separacion conceptual.

## 6. Que mejoraria primero

### P0

1. Blindar aun mas la separacion de dominios: fiscal, comercial, email, cobranza, recibo, documento y anulacion.
2. Reducir gradualmente concentracion de logica en paginas y controladores grandes, sobre todo en `public/facturacion.php` y en `CuentaCorrienteController`.
3. Hacer mas explicito el contrato operativo entre baseline y migraciones.

### P1

1. Sumar pruebas de integracion reales sobre una DB temporal:
   - baseline + migraciones
   - emision manual
   - emision desde venta
   - documento a venta
   - cobranza + recibo
   - recovery
   - NC parcial y total
2. Mejorar observabilidad y chequeos previos de entorno fiscal.
3. Simplificar experiencia para perfiles no avanzados.

### P2

1. Consolidar documentacion operativa mas corta por flujo critico.
2. Reducir deuda legacy de forma mas explicita.
3. Afinar el posicionamiento del producto por tipo de cliente.

## 7. Como posicionaria FLUS

No conviene venderlo como "software con muchas funciones". Conviene venderlo como:

1. Sistema de operacion real para comercio minorista.
2. Producto que crece por etapas sin forzar complejidad fiscal desde el dia uno.
3. Plataforma con foco en robustez y soporte.
4. Facturacion integrada con criterio operativo, no solo con emision.
5. Herramienta pensada para comercios reales y no para demos.

## 8. Veredicto final

FLUS hoy esta bastante bien.

No se ve fragil ni improvisado. Se ve como un sistema de gestion serio para comercio minorista, con una evolucion tecnica mejor que la media de proyectos chicos de este tipo. Tiene valor operativo real, una base de soporte util y una capa fiscal que, aunque compleja, esta siendo tratada con bastante mas criterio del habitual.

Al mismo tiempo, ya esta en el punto donde crecer mas sin consolidar semantica y responsabilidades puede salir caro. El mayor riesgo actual no es la falta de features ni la falta de configuracion sensible en el repo. El mayor riesgo es que la riqueza funcional erosione mantenibilidad, claridad de modelo y simplicidad operativa.

Veredicto:

FLUS ya es un producto real y con buena base, pero su proxima mejora importante no deberia ser sumar mas, sino consolidar con dureza lo que ya construyo.
