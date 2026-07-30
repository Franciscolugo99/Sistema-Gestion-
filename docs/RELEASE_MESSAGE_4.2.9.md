# FLUS 4.2.9

Actualizacion Cloud para consultar ventas unificadas y recuperar historial local
de forma gradual.

- Agrega cajero y anulaciones al historial sincronizado.
- Permite previsualizar y cargar ventas anteriores desde Tecnico.
- Evita duplicados al repetir un lote.
- No modifica ventas, pagos, caja ni stock durante la carga historica.
- Conserva configuracion, licencia y base de datos durante la actualizacion.

Antes de actualizar una instalacion productiva, verificar el backup automatico
y confirmar que la tarea `FLUS_CloudSync` quede activa.
