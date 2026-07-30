# Release 4.2.10

FLUS 4.2.10 habilita cambios de precio remotos, auditados e idempotentes desde
el portal movil hacia una sucursal concreta.

- Version visible: `4.2.10`
- Servidor previsto: `FLUS_Server_Setup_4.2.10.exe`
- Terminal previsto: `FLUS_Terminal_Setup_4.2.10.exe`

## Meta y estado actual

La meta de esta entrega es permitir que un usuario autorizado cambie el precio
de un producto desde el portal movil y que la sucursal correcta lo aplique una
sola vez, con validacion local, conflicto ante precios desactualizados e
historial auditable.

Al 30/07/2026, el codigo de FLUS 4.2.10 esta implementado y validado en fuente,
payload portable, smoke, integracion MariaDB y piloto de upgrade. El staging no
contiene configuraciones ni secretos reales. El ejecutable definitivo todavia
debe compilarse despues de publicar y validar primero el lado Wiroos; ninguna
sucursal productiva fue actualizada con 4.2.10 durante este trabajo.

Proximo hito: publicar Wiroos con `cloud_commands` y sus endpoints, ejecutar una
prueba controlada, compilar el instalador final y actualizar primero una sola
sucursal piloto antes de extenderlo a la segunda.

## Flujo

1. Un dueno o encargado autorizado elige producto, precio y motivo en el portal.
2. Wiroos valida usuario, CSRF, cliente, sucursal e instalacion y crea una orden.
3. La tarea `FLUS_CloudSync` reclama la orden sin exponer el token en argumentos.
4. FLUS bloquea el producto y compara el precio local contra el esperado.
5. Precio e historial se guardan en una transaccion y el resultado vuelve al portal.
6. Si hay timeout o reintento, el recibo local devuelve el mismo resultado sin
   repetir el cambio ni el historial.

## Migracion

La migracion `046_cloud_command_receipts.sql` agrega recibos tecnicos para los
comandos. Es aditiva y no modifica ventas, pagos, caja ni movimientos de stock.

## Compatibilidad

- Upgrade compatible desde 4.2.8 y 4.2.9.
- Conserva base, licencia, URL/token Cloud, Mercado Pago, ARCA y storage.
- Sin Wiroos actualizado, la sincronizacion saliente continua funcionando pero
  no se entregan comandos de precio.

## Publicacion coordinada

1. Respaldar Wiroos y publicar tabla, portal y endpoints de comandos.
2. Verificar `command-poll.php` y `command-ack.php` por HTTPS.
3. Construir y validar instalador 4.2.10 sin secretos.
4. Actualizar primero una sucursal piloto y aplicar migracion 046.
5. Probar un producto no critico, confirmar historial y luego actualizar la otra.
