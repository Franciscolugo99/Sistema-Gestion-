# FLUS 4.2.8

Actualizacion de estabilidad Cloud para instalaciones con varias sucursales.

- Mantiene el estado online aun cuando no haya ventas pendientes.
- No modifica datos comerciales ni genera eventos duplicados.
- Conserva configuracion, licencia y base de datos durante la actualizacion.
- Mejora el diagnostico de sincronizacion desde Tecnico.

Antes de actualizar una instalacion productiva, verificar el backup automatico
y confirmar que la tarea `FLUS_CloudSync` quede activa.
