# QA caja, terminales y permisos - FLUS 4.0.0

Fecha: 2026-05-27

## Alcance

Este ajuste cubre controles operativos de caja en entornos con varios cajeros,
terminales compartidas y kioscos 24 horas.

- Acceso rapido desde Caja a ventas recientes.
- Reimpresion de ticket desde Caja sin entrar al modulo Ventas.
- Anulacion total o parcial desde ventas recientes solo con permisos dedicados.
- Heartbeat de terminal restringido a paginas POS.
- Limpieza de locks de terminal asociados a sesiones cerradas o revocadas.
- Backfill de permisos minimos del rol cajero por `slug`.
- Bloqueo al guardar roles operativos de caja sin `realizar_ventas`.

## Causa raiz corregida

El selector podia mostrar una terminal ocupada por un usuario cuya sesion ya no
estaba activa. La causa era que algunas APIs autenticadas no ejecutaban la misma
validacion de `user_sessions.status` que las paginas HTML, por lo que una sesion
revocada todavia podia renovar su `terminal_lock`.

El cierre se hizo en backend:

- `require_login_json()` valida que la sesion siga `ACTIVE`.
- Si la sesion esta revocada o cerrada, se libera su terminal y la API responde
  `SESSION_REVOKED`.
- `terminal_locks_gc()` elimina locks vencidos y locks de sesiones no activas.
- `terminal_heartbeat` exige contexto de caja para evitar renovaciones fuera del
  POS.

## Comportamiento esperado

### Terminales

- Una terminal ocupada debe corresponder a una sesion activa y con lock vigente.
- Una sesion `REVOKED` o `LOGGED_OUT` no debe mantener una terminal ocupada.
- Abrir reportes, diagnostico o imprimir tickets no debe renovar terminales.
- Si un admin libera una terminal, el cajero debe volver al selector.

### Turno protegido

- La terminal y el turno abierto son conceptos distintos.
- Un usuario puede tener seleccionada una terminal libre, pero si esa terminal
  tiene un turno abierto por otro cajero, Caja debe bloquear la operacion.
- Para conservar control por cajero, el turno abierto por `admin` no debe ser
  operado por `caja1`; debe cerrarlo el responsable o un supervisor.

### Rol cajero

Permisos minimos esperados:

- `abrir_caja`
- `cerrar_caja`
- `realizar_ventas`
- `ver_clientes`
- `ver_stock`
- `registrar_cargo_cc`

La migracion `035_cajero_role_operational_permissions.sql` agrega esos permisos
por `slug` sin quitar permisos personalizados existentes.

## Checklist manual

1. Iniciar sesion como admin y seleccionar Caja 1.
2. Abrir un turno en Caja 1.
3. Cerrar sesion o revocar esa sesion desde Diagnostico.
4. Confirmar que Caja 1 no queda ocupada por la sesion revocada.
5. Iniciar sesion como cajero y seleccionar Caja 1.
6. Confirmar que Caja informa turno protegido si el turno abierto pertenece a
   admin.
7. Cerrar el turno con admin/supervisor.
8. Confirmar que el cajero puede operar Caja con `realizar_ventas`.
9. Desde Caja, abrir Ventas recientes y verificar reimpresion de ticket.
10. Confirmar que los botones de anulacion aparecen solo con permisos
    `anular_venta` o `anular_items_venta`.

## Validaciones automaticas

```text
php -l public/auth.php
php -l public/lib/terminal.php
php -l public/rol_permisos.php
php -l public/api/actions/terminal_heartbeat.php
php -l public/api/actions/caja_ventas_recientes.php
php -l public/caja.php
php -l tests/smoke.php
git diff --check
php tests/smoke.php
```

