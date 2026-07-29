# Release 4.2.8

FLUS 4.2.8 mantiene visible cada instalacion Cloud aunque no tenga operaciones
pendientes para enviar.

- Version visible: `4.2.8`
- Servidor previsto: `FLUS_Server_Setup_4.2.8.exe`
- Terminal previsto: `FLUS_Terminal_Setup_4.2.8.exe`

## Cambio principal

- El worker ejecuta un heartbeat idempotente cada cinco minutos.
- El heartbeat actualiza presencia, instalacion y sucursal en Wiroos sin crear
  ventas, pagos ni movimientos de stock.
- Si la red falla, conserva la cola y registra una causa sanitizada; Caja no se
  bloquea y el reintento automatico continua.
- Tecnico conserva el reintento manual y muestra el estado del ultimo intento.

## Compatibilidad

- No agrega migraciones nuevas: conserva el esquema hasta
  `045_cloud_sync_queue.sql`.
- Preserva base, licencia, endpoint, token, Mercado Pago, ARCA y storage durante
  un upgrade.
- Requiere el endpoint Wiroos actualizado para que el preflight registre la
  presencia de la instalacion.

## Validacion requerida antes de distribuir

1. PHP lint de archivos modificados.
2. Smoke completo sin fallas.
3. Integracion DB de la cola cloud.
4. Piloto descartable de upgrade del instalador.
5. Verificacion de payload y hashes sin secretos.
