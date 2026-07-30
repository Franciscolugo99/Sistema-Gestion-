# Release 4.2.9

FLUS 4.2.9 completa la base del listado unificado de ventas Cloud y agrega una
recuperacion historica controlada para instalaciones que empezaron a sincronizar
despues de tener ventas locales.

- Version visible: `4.2.9`
- Servidor previsto: `FLUS_Server_Setup_4.2.9.exe`
- Terminal previsto: `FLUS_Terminal_Setup_4.2.9.exe`

## Cambio principal

- Las ventas nuevas incluyen el nombre del cajero resuelto por el servidor.
- Las anulaciones totales y parciales se informan con eventos idempotentes.
- Tecnico permite previsualizar y agregar ventas historicas en lotes de 25 a
  250, con filtros de fecha y continuidad por ID.
- La carga historica solo completa `cloud_sync_queue`. No modifica ventas,
  pagos, caja ni stock y no ejecuta el envio en la misma solicitud.
- El script CLI se conserva para soporte y comparte la misma libreria que la UI.

## Compatibilidad

- No agrega migraciones nuevas: conserva el esquema hasta
  `045_cloud_sync_queue.sql`.
- El upgrade preserva base, licencia, endpoint, token, Mercado Pago, ARCA y
  storage, y exige un backup previo verificado.
- Es compatible con instalaciones 4.2.7 y 4.2.8. La tarea automatica Cloud
  continua enviando la cola con exclusividad e idempotencia.

## Procedimiento de carga historica

1. Actualizar la sucursal y confirmar que `FLUS_CloudSync` este activa.
2. Abrir Tecnico con un usuario autorizado.
3. En Ventas historicas Cloud, elegir fechas y lote.
4. Ejecutar Vista previa y revisar faltantes y existentes.
5. Agregar un lote y esperar que la tarea automatica reduzca pendientes.
6. Continuar desde el ultimo ID mostrado, evitando cargar todo de una vez.

## Validacion requerida antes de distribuir

1. PHP lint de archivos modificados.
2. Smoke completo sin fallas.
3. Integracion DB con vista previa, alta y reintento idempotente.
4. Piloto descartable de upgrade del instalador.
5. Verificacion de payload y hashes sin secretos.
