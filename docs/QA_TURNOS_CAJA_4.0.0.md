# QA turnos de caja - FLUS 4.0.0

Fecha de relevo: 2026-05-25
Rama: `Ver-4.0.0`

## Objetivo

Dejar funcional el control por cajero/turno:

- Cada caja abierta queda asociada al usuario que la abrio.
- Otro cajero no puede vender, mover caja ni cobrar sobre un turno ajeno.
- El cierre queda controlado por el duenio del turno o por un supervisor/admin.
- El historial pasa a funcionar como "Control de turnos".
- El control muestra cajero, horario de apertura/cierre, duracion, tickets, ventas, medios de pago y diferencia.

## Cambios principales

- `public/caja_lib.php`: helpers para detectar duenio del turno y permisos operativos.
- `public/caja.php`: bloquea operacion sobre turno ajeno y muestra estado de turno protegido.
- `public/caja_cerrar.php`: valida que solo el cajero duenio o supervisor/admin pueda cerrar.
- `public/api/actions/registrar_venta.php`: rechaza ventas sobre turno ajeno con `CAJA_TURNO_AJENO`.
- `public/caja_movimientos.php`: bloquea movimientos de efectivo sobre turno ajeno.
- `public/api/cuenta_corriente_api.php` y `public/api/factura_cobranza_api.php`: bloquean cobros operativos sobre turno ajeno.
- `public/api/actions/terminal_select.php` y `terminal_switch.php`: permiten cambiar de terminal si la caja abierta no pertenece al usuario actual.
- `public/assets/js/caja_terminal_modal.js`: soporta multiples botones para cambiar terminal.
- `public/caja_historial.php`: renombrado funcional a Control de turnos, con columnas de cajero, tickets y medios de pago.
- `public/caja_sesion_detalle.php`, `print` y `export`: usan "Efectivo sistema" y "Efectivo declarado" para evitar confundirlo con ventas totales.
- `public/assets/css/caja.base.css`: alertas de caja con estilo visual consistente.
- `migrations/034_caja_turno_control.sql`: agrega compatibilidad para columnas de cierre/supervision del turno.

## QA manual realizado

Usuarios locales creados para prueba:

- `turno1`
- `turno2`

No guardar ni subir passwords en este documento.

Flujo probado:

1. Admin cerro caja vieja abierta.
2. `turno1` abrio Caja 1.
3. `turno1` vendio producto `5555` por efectivo.
4. `turno1` cerro caja declarando efectivo correcto.
5. `turno2` abrio Caja 1.
6. `turno2` vendio producto `5555` por Mercado Pago.
7. `turno2` cerro caja declarando efectivo `0`.
8. Control de turnos mostro ambos turnos con cajero, duracion, tickets, ventas y diferencia en cero.
9. Detalle del turno MP mostro:
   - Total ventas: `$2.000,00`
   - Mercado Pago: `$2.000,00`
   - Efectivo sistema: `$0,00`
   - Efectivo declarado: `$0,00`
   - Diferencia: `$0,00`
10. Aviso `Caja cerrada correctamente.` quedo renderizado como alerta verde.

## Verificacion automatica

Comando:

```powershell
C:\xampp\php\php.exe tests\smoke.php
```

Resultado al cierre del relevo:

- Total: 131
- Failed: 0
- Skipped: 0

Tambien se corrio `php -l` sobre archivos PHP tocados durante el ajuste final.

## Posibles bugs o decisiones para testear manana

1. Permisos de Control de turnos
   - Hoy el rol Cajero no entra al historial global, da 403.
   - Esto parece correcto para control interno.
   - Pendiente decidir si crear rol Encargado/Supervisor con acceso limitado.

2. Ticket pendiente al cambiar de caja
   - El ticket recuperado se guarda por apertura de caja: `kiosco-caja-v3:caja:{CAJA_ID}`.
   - No se transfiere automaticamente a otra caja.
   - Recomendacion: mantenerlo asi. Si se quiere mover un ticket, hacerlo con boton explicito y auditoria.

3. Turno protegido
   - Probar que si `turno1` deja caja abierta, `turno2` no pueda vender ni cerrar, pero si pueda cambiar de terminal.
   - Verificar copy y botones en desktop/mobile.

4. Cierre por supervisor/admin
   - Probar cierre de un turno ajeno por admin/supervisor.
   - Revisar si debe registrar `cerrado_por_user_id` y motivo en todas las instalaciones.

5. Medios en historial
   - Probar ventas mixtas: efectivo + MP, efectivo + debito, transferencia y cuenta corriente.
   - Confirmar que `Ventas`, `Suma medios`, `Base medios` y `Diff medios` no marquen falso positivo.

6. Instalaciones viejas
   - Correr migraciones desde una DB vieja.
   - Confirmar que instalaciones sin `total_transferencia` siguen mostrando transferencia en cero sin romper.

7. UX de apertura/cierre
   - Probar cerrar caja con ticket activo.
   - Probar abrir caja despues de cambiar terminal.
   - Probar refresh en medio de una venta pendiente.

## Notas para el proximo Codex

- No revertir cambios ajenos en esta rama.
- Antes de tocar permisos, revisar `public/partials/nav.php`, `public/auth.php` y helpers de permisos existentes.
- Antes de cambiar recuperacion de ticket, revisar `public/assets/js/caja.js` alrededor de `STORAGE_PREFIX`, `STORAGE_SCOPE`, `guardarEstado()` y `cargarEstado()`.
- Para pruebas visibles, usar el navegador con `http://localhost/kiosco/public/caja.php` y `http://localhost/kiosco/public/caja_historial.php`.
- Evitar subir credenciales locales de XAMPP o passwords de usuarios de prueba.
