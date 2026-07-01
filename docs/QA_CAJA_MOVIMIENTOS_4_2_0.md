# QA caja movimientos operativos 4.2.0

Fecha: 2026-07-01

## Alcance

- Caja ya no muestra acumulados ni listado de movimientos dentro de la pantalla principal.
- El boton Movimientos abre un modal operativo para registrar ingresos o egresos sin salir de la venta.
- El formulario del modal envia JSON a `public/caja_movimientos.php` y conserva fallback por pagina completa.
- El endpoint valida sesion, permiso minimo `realizar_ventas`, turno propio, CSRF, tipo, concepto y monto.
- Cada envio usa `request_uid` de sesion para reducir riesgo de doble carga por doble click, timeout o reintento.
- El historial de ultimos movimientos solo se ofrece a usuarios con permiso de supervision de caja.

## Permisos esperados

- Cajero con `realizar_ventas`: puede registrar un movimiento en su propio turno, sin ver resumen ni historial sensible.
- Supervisor o administrador: puede registrar movimientos y consultar los ultimos movimientos del turno desde el modal.
- Usuario sin turno abierto: no puede registrar movimientos.
- Usuario operando sobre un turno ajeno: queda bloqueado.
- Usuario sin permiso de supervision: no puede consultar el JSON de historial aunque conozca la URL.

## Flujos a comprobar

1. Abrir Caja con un turno propio.
2. Tocar Movimientos y verificar que se abre el modal sin navegar fuera de Caja.
3. Registrar un ingreso con concepto y monto validos.
4. Confirmar feedback de exito y que el foco vuelve al campo de producto.
5. Intentar doble click en Guardar y confirmar que no duplica el movimiento.
6. Como cajero sin supervision, confirmar que no aparece el bloque Ultimos movimientos.
7. Como supervisor o administrador, tocar Mostrar y verificar los ultimos movimientos del turno.
8. Probar monto vacio, monto cero y concepto vacio: deben responder con error operativo claro.
9. Abrir `caja_movimientos.php` directamente y confirmar que el fallback sigue funcionando.
10. Cerrar caja o cambiar de terminal y confirmar que el endpoint bloquea el alta.

## Areas afectadas

- `public/caja.php`
- `public/caja_movimientos.php`
- `public/assets/js/caja.js`
- `public/assets/js/caja_movimientos_modal.js`
- `public/assets/css/caja.base.css`
- `public/assets/css/caja.neo.css`
- `public/assets/css/caja.pos.css`
- `public/assets/css/caja_movimientos.css`
- `tests/smoke.php`

## Validaciones tecnicas

- `C:\xampp82\php\php.exe -l public\caja.php`
- `C:\xampp82\php\php.exe -l public\caja_movimientos.php`
- `C:\xampp82\php\php.exe -l tests\smoke.php`
- `node --check public\assets\js\caja.js`
- `node --check public\assets\js\caja_movimientos_modal.js`
- `C:\xampp82\php\php.exe tests\smoke.php`
- `git diff --check`

## Riesgos a vigilar

- No usar el historial de movimientos como reemplazo de reportes de control de caja.
- Mantener la accion de registrar movimientos separada de cierre de caja y anulaciones.
- No mostrar montos agregados sensibles en Caja para roles de cajero.
- Si se modifica la logica de permisos, repetir el caso de acceso directo al JSON de historial.
