# Mejoras FLUS

Fecha de corte: 2026-04-13

Este documento baja a trabajo concreto el analisis de deuda y mejoras detectadas sobre la rama `codex/fiscal-nc-total`.

## Estado de partida

- Smoke local inicial: `107` checks, `0` fallas. Luego de este bloque: `109` checks, `0` fallas.
- Worktree inicial: limpio salvo `.tmp_flus_review/`, usado como paquete temporal para el sitio institucional.
- Riesgo principal: el sistema ya no falla por falta de modulos, sino por crecimiento de complejidad fiscal/comercial y hotspots grandes.

## P0 - Orden antes de crecer

1. Mantener separacion semantica entre venta, factura, documento comercial, cobranza, recibo, nota de credito y recovery fiscal.
2. Evitar que los hotspots sigan creciendo; si se toca un archivo grande, extraer validaciones, queries o render a helpers.
3. Mantener alineados `src/version.php`, guia de upgrade, README, CHANGELOG y migraciones publicadas.
4. Revisar logs de arranque para que usen la version real y no generen ruido operativo.

## P1 - Confianza operativa

1. Agregar una prueba de integracion con DB temporal para baseline + migraciones.
2. Agregar escenarios de integracion para caja, factura manual, factura desde venta, NC total/parcial, recovery fiscal, cobranza/recibo y cuenta corriente.
3. Centralizar mas compatibilidad de esquema fuera de paginas publicas y controladores grandes.
4. Mejorar observabilidad de eventos fiscales y comerciales importantes.

## P2 - Producto y UX

1. Separar mejor la experiencia de mostrador de la experiencia administrativa/fiscal.
2. Revisar textos y jerarquia para perfiles no avanzados.
3. Cerrar el sitio institucional en `.tmp_flus_review/flus-web-main` o moverlo fuera del repo principal si no se va a publicar desde aca.

## Hotspots a vigilar

- `public/assets/css/caja.neo.css`
- `public/assets/js/caja.js`
- `tests/smoke.php`
- `public/assets/css/facturacion.css`
- `src/facturacion_lib.php`
- `public/productos.php`
- `public/proveedores.php`
- `public/includes/CuentaCorrienteController.php`

## Proximo bloque

La primera prueba de integracion con DB temporal ya quedo implementada. Es la pieza que mejor protege los cambios fiscales/documentales sin obligar a una reescritura.

Ya queda disponible un primer runner opt-in:

```powershell
$env:FLUS_TEST_DB='1'
$env:FLUS_TEST_DB_HOST='127.0.0.1'
$env:FLUS_TEST_DB_USER='root'
$env:FLUS_TEST_DB_PASS=''
C:\xampp\php\php.exe tests\integration_db.php
```

Por seguridad, el script solo usa bases temporales con prefijo `flus_it_` y las borra al terminar salvo que se defina `FLUS_TEST_DB_KEEP=1`.

El runner ya valida un caso minimo de venta POS con `venta_items`, pago mixto, descuento de stock y movimiento de stock.
Tambien valida un caso fiscal no remoto con documento comercial, item documental, factura demo autorizada localmente, item fiscal y evento ARCA local.
Ademas cubre notas de credito no remotas:
- NC total: crea la anulacion total, vincula la NC a la factura original, persiste item fiscal, registra evento ARCA local, deja la venta anulada y repone stock con movimiento de anulacion.
- NC parcial: crea una anulacion parcial independiente, acredita una unidad, deja la venta parcialmente anulada y repone solo el stock acreditado.

El runner tambien cubre recovery fiscal no remoto: simula un intento `ERROR_TRANSITORIO`, recupera el CAE localmente, marca la factura como `RECUPERADA` y conserva un unico evento ARCA local con operacion `FACTURA_RECOVERY`.
Finalmente, cubre cobranzas y recibos para una venta facturada: registra la cobranza con idempotencia por `external_key`, aplica contra factura/documento, genera un recibo documental y valida que se pueda recuperar por factura.
Tambien cubre cuenta corriente sobre una venta facturada: habilita CC, registra cargo, registra pago con `request_uid` idempotente, genera cobranza/recibo aplicado a factura y confirma el saldo recalculado en cero.

Tambien se hizo una extraccion chica del hotspot `src/facturacion_lib.php`: los helpers de PDF quedaron en `src/facturacion_pdf_lib.php`, con smoke dedicado para evitar que vuelvan al archivo principal.
El runner quedo formalizado como ritual de release en `docs/INTEGRATION_DB_RUNNER.md`: prerequisitos, comando definitivo, cobertura, momento de ejecucion y manejo de fallas.
Se completo un segundo corte chico del hotspot fiscal: envio ARCA, finalizacion de factura autorizada, recovery simple y procesamiento de factura registrada quedaron en `src/facturacion_emision_lib.php`, con smoke dedicado.
El contrato fiscal/comercial corto quedo documentado en `docs/CONTRATO_FISCAL_COMERCIAL.md`: venta, documento comercial, factura, NC, cobranza, recibo y recovery con invariantes minimas.

Siguiente paso recomendado: preparar el proximo corte de bajo riesgo o cerrar el paquete como release candidata.
