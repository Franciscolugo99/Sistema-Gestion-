# Release 4.0.0

Fecha base: 2026-05-19

Objetivo: dejar FLUS 4.0.0 como nueva base estable para el siguiente ciclo de trabajo, partiendo de `Ver-3.9.0` y del repositorio real servido por Apache/XAMPP en `C:\xampp82\htdocs\kiosco`.

## Estado de salida

- version visible: `4.0.0`
- build: `2026-05-19`
- rama local: `Ver-4.0.0`
- base anterior: `Ver-3.9.0`
- PHP de validacion: `C:\xampp82\php\php.exe`
- smoke tecnico: `123 OK / 0 fallidas / 0 skipped`
- working tree de partida: limpio

## Alcance

4.0.0 no agrega migraciones ni cambia el baseline SQL. Este corte ordena la base despues del cierre de 3.9.0 y deja documentado que el entorno activo ya no es `C:\xampp\htdocs\kiosco`, sino `C:\xampp82\htdocs\kiosco`.

Entran como base estable:

- Caja con hardening de cobro, estado de procesamiento y accesibilidad.
- Cuenta corriente, facturacion, notas de credito y documentos comerciales con smoke tecnico en verde.
- Tesoreria v1 incluida como modulo operativo de la linea actual.
- Reglas de agentes compactas en `AGENTS.md`.
- Impeccable instalado como apoyo local de trabajo.
- Graphify disponible como mapa auxiliar, sin versionar salidas locales.

## Validaciones realizadas

- `git status --short --branch`
- `git branch -vv`
- `git log --oneline --decorate -5`
- `C:\xampp82\php\php.exe tests\smoke.php`

Resultado del smoke:

```text
Total: 123, failed: 0, skipped: 0
```

## Reglas para el siguiente ciclo

- Usar `C:\xampp82\htdocs\kiosco` como fuente real.
- Usar `C:\xampp82\php\php.exe` para validaciones PHP.
- No tocar `install.sql` ni `migrations/` salvo cambio de esquema justificado.
- Validar con archivos reales, `rg`, Git y smoke antes de cerrar fixes criticos.
- Mantener compatibilidad legacy de ventas, caja, facturacion, notas de credito, cuenta corriente y reportes.

## Pendientes conocidos

- Publicar `origin/Ver-4.0.0` si se decide subir la rama.
- Crear tag `v4.0.0` si se usa tag de release.
- Hacer QA funcional manual de produccion en el entorno final del cliente cuando corresponda.
