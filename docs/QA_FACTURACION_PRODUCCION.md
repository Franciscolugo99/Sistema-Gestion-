# QA Facturacion Produccion

Checklist manual para validar el modulo de Facturacion antes de habilitar emision real en un cliente.

Fecha base: 2026-03-26

Este checklist esta aterrizado al estado actual del repo. No exige features que hoy no estan cerradas end-to-end.

## Alcance real hoy

Incluye:

- configuracion fiscal
- preflight de emision
- emision desde venta
- factura manual
- visualizacion del comprobante
- PDF y QR
- recovery fiscal
- notas de credito
- navegacion del modulo

No incluye como criterio de salida obligatorio:

- tributos, percepciones o retenciones complejas
- Libro IVA Digital
- multi-moneda operativa end-to-end
- notas de debito

## Preparacion

1. Confirmar backup de base y archivos.
2. Confirmar migraciones aplicadas.

```powershell
C:\xampp\php\php.exe scripts\migrate.php
```

3. Entrar con un usuario que tenga permisos de facturacion y configuracion.
4. Verificar que la rama desplegada coincida con la release a validar.
5. Hacer una recarga forzada del navegador una vez.

## P0 salida a produccion

### 1. Configuracion fiscal

- Abrir [facturacion_config.php](/C:/xampp/htdocs/kiosco/public/facturacion_config.php).
- Confirmar que esten completos:
  - razon social
  - CUIT
  - domicilio fiscal
  - ingresos brutos
  - inicio de actividades
  - condicion IVA
  - punto de venta
  - modo fiscal
- Confirmar que el modo sea el esperado:
  - `demo` para pruebas internas
  - `homologacion` para QA con ARCA
  - `produccion` solo para salida real
- Confirmar que certificados y clave existan y sean legibles si el modo requiere ARCA.
- Confirmar que el bloque `Preflight de emision` marque el sistema como listo.
- Si el preflight bloquea, no seguir hasta corregirlo.

### 2. Navegacion del modulo

- Verificar que Facturacion aparezca en el nav principal como grupo.
- Confirmar que cada item del dropdown lleve a la pantalla correcta:
  - panel fiscal
  - factura manual
  - documentos
  - notas de credito
  - incidencias
  - configuracion
- Confirmar que dentro del dropdown quede activo solo el item actual.
- Confirmar que no haya una segunda barra interna duplicando acciones.

### 3. Numeracion y sincronizacion

- Desde [facturacion_config.php](/C:/xampp/htdocs/kiosco/public/facturacion_config.php), ejecutar la prueba o sincronizacion disponible con ARCA si el entorno lo permite.
- Confirmar que el punto de venta y la numeracion local sean coherentes.
- Emitir un comprobante de prueba y verificar que el numero generado sea el esperado.
- Confirmar que no aparezcan duplicados ni conflictos de numeracion.
- Si el entorno es homologacion o produccion, revisar que no haya divergencia visible entre numeracion local y remota.

### 4. Emision desde venta

- Abrir una venta apta para facturar y entrar por [factura_nueva.php](/C:/xampp/htdocs/kiosco/public/factura_nueva.php).
- Confirmar que:
  - la pantalla carga sin error
  - el preflight no bloquea si la configuracion esta correcta
  - el cliente resuelto sea el esperado
  - el tipo de comprobante resulte coherente con el cliente
- Emitir.
- Verificar que:
  - no haya fatal ni error de negocio
  - se genere la factura
  - se pueda abrir [factura_ver.php](/C:/xampp/htdocs/kiosco/public/factura_ver.php)
  - se pueda abrir el PDF

### 5. Emision manual

- Abrir [factura_manual.php](/C:/xampp/htdocs/kiosco/public/factura_manual.php).
- Probar al menos:
  - cliente Responsable Inscripto
  - cliente Monotributo o Consumidor Final
  - cliente Exento si aplica a tu operacion
- Cargar items validos.
- Emitir.
- Verificar que:
  - el preflight se comporte igual que en emision desde venta
  - no se duplique la factura por doble submit
  - el comprobante generado abra correctamente en visor y PDF

### 6. Visor del comprobante

- Abrir [factura_ver.php](/C:/xampp/htdocs/kiosco/public/factura_ver.php) sobre una factura de venta y una manual.
- Confirmar que se vea correctamente:
  - encabezado fiscal
  - cliente
  - detalle de items
  - importes
  - CAE
  - vencimiento CAE
  - modo fiscal
- Si hay documento comercial asociado, confirmar que el bloque asociado aparezca bien.
- Si hay recibos/cobranzas asociados, confirmar que aparezcan bien.
- Si falta algun dato del emisor, confirmar que la alerta sea clara.

### 7. PDF y QR

- Abrir [factura_pdf.php](/C:/xampp/htdocs/kiosco/public/factura_pdf.php) desde una factura emitida.
- Confirmar que el PDF se genere sin error.
- Validar que el QR:
  - exista cuando hay CAE real
  - no aparezca como valido en modo demo
- Confirmar que los datos visibles del PDF coincidan con el visor.

### 8. Recovery fiscal

- Abrir [facturacion_recovery.php](/C:/xampp/htdocs/kiosco/public/facturacion_recovery.php).
- Confirmar que la pantalla abra sin fatal.
- Si existen incidencias:
  - revisar listado
  - abrir regularizacion
  - ejecutar un recovery controlado
  - confirmar que el estado final quede coherente
- Si no existen incidencias:
  - confirmar que el estado vacio sea claro

### 9. Notas de credito

- Abrir [facturacion_nc.php](/C:/xampp/htdocs/kiosco/public/facturacion_nc.php).
- Generar una NC sobre una factura valida.
- Confirmar:
  - seleccion correcta del comprobante origen
  - emision sin error
  - visor correcto del comprobante resultante
  - trazabilidad con el comprobante origen
- Si existe caso de recovery NC, probar [facturacion_nc_recovery.php](/C:/xampp/htdocs/kiosco/public/facturacion_nc_recovery.php).

### 10. Permisos

- Probar con un perfil con permisos de visualizacion.
- Probar con un perfil con permisos de emision.
- Probar con un perfil con permisos de configuracion.
- Confirmar que:
  - quien no puede configurar no vea ni ejecute configuracion
  - quien no puede emitir no emita
  - quien no puede ver recovery no acceda por URL si aplica

## Criterio de aprobacion

El modulo queda apto para salir a produccion si:

- el preflight de emision da OK en el entorno objetivo
- la navegacion fiscal funciona sin duplicaciones ni rutas rotas
- emision desde venta y emision manual funcionan sin errores
- `factura_ver.php` y `factura_pdf.php` muestran datos coherentes
- el QR/CAE se comporta correctamente segun el modo
- recovery abre y funciona en casos reales o queda accesible sin errores
- notas de credito pasan el flujo minimo esperado
- no aparecen errores fatales, conflictos de numeracion ni duplicaciones por retry

## Hallazgos que bloquean salida

- preflight en error
- configuracion fiscal incompleta
- certificados o clave invalidos en entorno real
- conflicto de numeracion
- factura emitida que no puede verse o imprimirse
- CAE/CAE vto incoherente
- recovery con fatal o con resultado inconsistente
- NC que no conserva trazabilidad con el origen
- permisos que permiten emitir o configurar a quien no corresponde

## Recorrido UIX recomendado

Este recorrido sirve para validar el modulo sin tener que improvisar el orden.

### 1. Configuracion y preflight

Abrir [facturacion_config.php](/C:/xampp/htdocs/kiosco/public/facturacion_config.php).

Verificar por UI:

- que la pantalla abra sin error
- que el grupo `Facturación` del nav marque `Configuracion`
- que el bloque `Preflight de emision` se vea
- que el estado final sea apto para emitir en el modo elegido

Se considera OK si:

- no hay alertas rojas de configuracion faltante
- el preflight no bloquea
- los datos fiscales visibles coinciden con el comercio real

### 2. Panel fiscal

Abrir [facturacion.php](/C:/xampp/htdocs/kiosco/public/facturacion.php).

Verificar por UI:

- que el nav principal marque `Panel fiscal`
- que no haya doble fila de navegacion
- que filtros, KPIs y tabla carguen
- que `Exportar CSV` funcione si hay resultados

Se considera OK si:

- el dropdown de `Facturación` muestra una sola opcion activa
- la pantalla carga sin fatal
- los filtros responden y la paginacion funciona

### 3. Emision desde venta

Entrar desde una venta apta para facturar a [factura_nueva.php](/C:/xampp/htdocs/kiosco/public/factura_nueva.php).

Verificar por UI:

- que no aparezca bloqueo de preflight si la config es correcta
- que cliente, tipo y total tengan sentido
- que al emitir redirija o permita abrir el comprobante generado

Se considera OK si:

- no hay error de negocio ni pantalla en blanco
- se genera una factura visible en [factura_ver.php](/C:/xampp/htdocs/kiosco/public/factura_ver.php)
- el comprobante aparece luego en [facturacion.php](/C:/xampp/htdocs/kiosco/public/facturacion.php)

### 4. Factura manual

Abrir [factura_manual.php](/C:/xampp/htdocs/kiosco/public/factura_manual.php).

Verificar por UI:

- selector o carga de cliente
- carga de items
- total calculado
- boton de emitir

Probar:

- una factura manual simple
- doble click rapido en emitir

Se considera OK si:

- el formulario responde bien
- no duplica la factura por doble submit
- el comprobante emitido abre correctamente

### 5. Visor del comprobante

Abrir [factura_ver.php](/C:/xampp/htdocs/kiosco/public/factura_ver.php) sobre una factura emitida.

Verificar por UI:

- encabezado fiscal
- cliente
- items
- importes
- CAE
- vencimiento CAE
- modo
- bloques asociados si existen

Se considera OK si:

- el comprobante se entiende sin mirar base
- los datos principales coinciden con lo emitido
- no aparecen zonas vacias raras o errores de render

### 6. PDF

Desde el visor abrir [factura_pdf.php](/C:/xampp/htdocs/kiosco/public/factura_pdf.php).

Verificar por UI:

- que abra o descargue sin error
- que el contenido coincida con el visor
- que el QR aparezca solo cuando corresponde

Se considera OK si:

- el PDF no falla
- CAE, numero, cliente e importes coinciden

### 7. Recovery

Abrir [facturacion_recovery.php](/C:/xampp/htdocs/kiosco/public/facturacion_recovery.php).

Verificar por UI:

- que abra sin fatal
- que el nav marque `Incidencias`
- que el estado vacio o la lista de incidencias tenga sentido

Si hay casos reales:

- ejecutar una regularizacion controlada
- volver al visor del comprobante
- confirmar cambio de estado

Se considera OK si:

- no aparece el error de collations
- el flujo de recovery deja un estado coherente

### 8. Notas de credito

Abrir [facturacion_nc.php](/C:/xampp/htdocs/kiosco/public/facturacion_nc.php).

Verificar por UI:

- seleccion del comprobante origen
- carga del flujo de NC
- emision sin error

Luego abrir la NC en [factura_ver.php](/C:/xampp/htdocs/kiosco/public/factura_ver.php).

Se considera OK si:

- la NC se genera
- se puede ver
- queda clara la relacion con el comprobante origen

### 9. Documentos comerciales

Abrir [documentos_comerciales.php](/C:/xampp/htdocs/kiosco/public/documentos_comerciales.php).

Verificar por UI:

- que el nav marque `Documentos`
- que listado, filtros y accesos carguen
- que desde ahi se pueda volver naturalmente al circuito fiscal

Se considera OK si:

- la navegacion entre documentos, panel y comprobantes no rompe contexto

## Como saber rapido si "funciona bien"

Tomalo con esta regla simple:

- Configuracion: el preflight da OK.
- Panel: carga, filtra y exporta.
- Emision: genera comprobante sin error.
- Visor: muestra datos coherentes.
- PDF: abre y coincide.
- Recovery: abre sin fatal y regulariza si hay caso.
- NC: emite y conserva trazabilidad.
- Navegacion: cada pantalla correcta, un solo item activo, sin duplicaciones.

Si esos 8 puntos pasan, el modulo esta bien encaminado para producción real en el alcance actual.

## Fuera de alcance por ahora

Estos puntos no deberian bloquear la salida actual salvo que el cliente los necesite explicitamente:

- tributos/percepciones/retenciones complejas
- exportacion al Libro IVA Digital
- comprobantes en moneda extranjera con circuito completo de cotizacion
- notas de debito

Si alguno de esos puntos es requisito comercial inmediato, no alcanza con este checklist: hay que abrir primero ese frente funcional y validarlo de punta a punta.
