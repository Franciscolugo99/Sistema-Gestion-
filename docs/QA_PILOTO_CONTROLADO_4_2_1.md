# QA piloto controlado FLUS 4.2.1

Fecha: 2026-07-08

Objetivo: validar FLUS como POS/gestion para kiosco o comercio chico antes de instalarlo en clientes reales sin supervision cercana.

## Preparacion

- Usar una PC limpia o una base de datos nueva de prueba.
- Confirmar que la fuente local sea la ultima rama `Ver-4.0.0`.
- Confirmar que la pantalla muestre `FLUS v4.2.1`.
- No usar datos reales de cliente hasta pasar este checklist.
- Guardar evidencia minima: fecha, maquina, usuario probado, resultado y observaciones.

## Instalacion limpia

1. Crear base vacia.
2. Importar `install.sql`.
3. Ejecutar `scripts/migrate.php`.
4. Entrar con el usuario inicial.
5. Cambiar la clave inicial de administrador antes de operar.
6. Confirmar que `install.php` no permita reconfigurar una instalacion ya configurada.
7. Confirmar que la app funcione sin internet para pantallas que no requieren servicios externos.

Resultado esperado:

- La instalacion inicia sin editar codigo.
- Las migraciones terminan sin errores.
- No queda dependencia obligatoria de CDN para operar graficos basicos.

## Operacion POS

1. Crear productos normales.
2. Crear productos pesables si el negocio los usa.
3. Cargar stock inicial.
4. Abrir caja.
5. Hacer ventas con:
   - efectivo exacto;
   - efectivo con vuelto;
   - Mercado Pago QR;
   - QR cancelado y registro manual;
   - internet cortado y fallback manual;
   - cuenta corriente, si aplica.
6. Reimprimir ticket desde Caja.
7. Cerrar caja con conteo exacto.
8. Cerrar caja con diferencia chica y observacion.

Resultado esperado:

- No se duplica venta, pago ni movimiento de stock.
- Caja informa diferencias de forma entendible.
- El cajero no ve KPIs sensibles ni accesos de administracion.

## Compras y stock

1. Registrar compra con un producto existente.
2. Confirmar que el stock sube una sola vez.
3. Intentar confirmar dos veces la misma compra.
4. Anular compra con y sin reversion de stock segun corresponda.
5. Revisar movimientos de stock.

Resultado esperado:

- No hay doble suma de stock.
- La anulacion no deja stock negativo salvo configuracion explicita.
- El historial explica que paso.

## Permisos

Probar como minimo:

- Administrador.
- Cajero.
- Supervisor u operador, si se usa.

Validar:

- Cajero puede vender, abrir/cerrar caja si el negocio lo permite y reimprimir ticket.
- Cajero no puede anular venta si no tiene permiso.
- Cajero no accede a usuarios, permisos, diagnostico, backups, licencias ni reportes sensibles.
- Acciones sensibles fallan tambien por backend si se intenta entrar por URL o API.

## Facturacion y Mercado Pago

- Mercado Pago QR crea orden y registra pago verificado cuando hay internet.
- Cancelar QR permite registrar manual solo como contingencia explicita.
- Sin internet, el fallback manual no intenta confirmar contra Mercado Pago.
- Facturacion demo/homologacion no debe confundirse con produccion.
- NC total/parcial solo aparece para usuarios con permiso.

## Backup y recuperacion

1. Crear backup.
2. Descargar backup.
3. Restaurar en entorno de prueba.
4. Confirmar que ventas, productos, usuarios, caja y licencia quedan consultables.

Resultado esperado:

- Backup no expone rutas ni secretos en pantalla.
- Restore no queda en mantenimiento si el archivo es invalido.

## Cierre del piloto

El piloto se considera aprobado si:

- Smoke queda en verde.
- Integracion DB queda en verde cuando se use DB temporal.
- No aparecen duplicaciones de ventas, pagos, caja, stock ni compras.
- Los errores son entendibles para cajero o administrador.
- Las observaciones quedan documentadas antes de vender o instalar en otro cliente.

