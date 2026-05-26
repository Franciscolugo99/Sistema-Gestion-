# QA caja y rendimiento de cajeros 4.0.0

## Alcance

Este documento describe el comportamiento operativo agregado para cierre de caja, control de turnos y analisis de rendimiento de cajeros.

## Cierre de caja

- El cierre vuelve siempre a `caja.php` con mensaje operativo.
- No redirige a Control de turnos, porque algunos cajeros no tienen permiso para ver ese modulo.
- El cajero declara el efectivo contado real.
- Ya no se exige repartir el efectivo entre fondo proximo turno y retiro.
- Si el efectivo declarado no coincide con el esperado, el sistema muestra la diferencia en vivo.
- La caja puede cerrarse con diferencia, pero exige observacion cuando corresponde.
- La diferencia queda registrada en la sesion de caja para revision posterior.

## Apertura de caja

- La apertura pide el efectivo real que recibe el cajero.
- Si queda cambio o fondo del turno anterior, debe contarse y cargarse como saldo inicial.
- No se arrastra automaticamente un fondo de una caja anterior, para evitar confusiones cuando una PC usa distintas cajas o terminales.

## Control de turnos

- Sigue protegido por `ver_historial_caja`.
- La tabla se compacto para 1366x768.
- Los medios se agrupan para reducir scroll horizontal.
- Se mantiene el foco en diferencias, medios inconsistentes, turnos largos y auditoria.

## Rendimiento de cajeros

Ruta: `public/cajeros_rendimiento.php`

Permisos:

- `administrar_usuarios`, o
- `administrar_config`.

El modulo aparece en el menu de administracion y no en el menu principal. Tambien valida permisos en backend, por lo que no alcanza con conocer la URL.

Metricas actuales:

- cajeros con actividad;
- dias trabajados;
- turnos;
- horas trabajadas;
- ventas totales;
- tickets;
- ticket promedio;
- ventas por hora;
- productos por hora;
- diferencia total de caja;
- cierres con diferencia;
- anulaciones;
- turnos abiertos.

## Franjas de turno

La franja se calcula por la hora de apertura de la caja:

- Manana: 06:00 a 13:59.
- Tarde: 14:00 a 21:59.
- Noche: 22:00 a 05:59.

Esto permite comparar usuarios dentro de turnos equivalentes en negocios 24 hs sin crear tablas nuevas. Si un cliente necesita horarios propios, el proximo paso recomendado es agregar una configuracion editable de franjas por negocio.

## Validacion recomendada

1. Abrir una caja con saldo inicial real.
2. Registrar ventas en efectivo y otros medios.
3. Cerrar con una diferencia chica y verificar que pida observacion.
4. Confirmar que el cajero vuelve a `caja.php`.
5. Entrar como administrador a Rendimiento de cajeros.
6. Filtrar por fecha, usuario, terminal y turno.
7. Exportar CSV.
8. Entrar con usuario sin permisos administrativos y confirmar que no ve el menu ni puede acceder por URL.

