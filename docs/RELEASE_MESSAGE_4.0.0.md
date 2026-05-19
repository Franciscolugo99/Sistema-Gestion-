# FLUS 4.0.0

FLUS 4.0.0 queda como nueva base estable para el siguiente ciclo del sistema.

Este corte formaliza el cambio de fuente local real a `C:\xampp82\htdocs\kiosco`, actualiza la version visible, deja la documentacion alineada y conserva intactos `install.sql` y `migrations/`.

Validacion tecnica:

- `C:\xampp82\php\php.exe tests\smoke.php`
- `123 OK / 0 fallidas / 0 skipped`

Alcance destacado:

- Caja queda incorporada con los fixes recientes de estado de cobro, doble accion, cancelacion y accesibilidad.
- Cuenta corriente, facturacion, notas de credito, documentos comerciales y tesoreria quedan como base funcional de la linea 4.0.
- `AGENTS.md` queda apuntando a la nueva base `Ver-4.0.0`.
- Impeccable y Graphify quedan disponibles como apoyos locales de trabajo, sin convertir salidas temporales en codigo de release.

Pendiente operativo:

- Publicar la rama/tag de release si se decide sincronizar `Ver-4.0.0` con el remoto.
