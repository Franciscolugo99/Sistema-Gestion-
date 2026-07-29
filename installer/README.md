# Instalacion multi-caja (Server + Terminales)

## Fuente reproducible

- `build_release.ps1` arma `.build/` desde archivos versionados o no ignorados de Git.
- El runtime portable se toma de `-RuntimeRoot` o `FLUS_RUNTIME_ROOT`.
- El staging rechaza configs reales, licencias, backups y claves privadas.
- `.build/` es temporal, local y no se versiona.

## Compilacion (Inno Setup)
- Ejecutar:
  - `powershell -ExecutionPolicy Bypass -File .\build_release.ps1 -SourceRoot "C:\ruta\al\repo\kiosco" -RuntimeRoot "C:\ruta\stack"`
- El script:
  - lee version/build desde `src\version.php`
  - crea un payload limpio y valida que no contenga secretos
  - corre smoke sobre fuente y payload portable
  - actualiza las versiones de Inno Setup en el staging
  - compila `FLUS_Server_Setup_<version>.exe` y `FLUS_Terminal_Setup_<version>.exe`
  - genera manifiestos y hashes SHA256

## Instalacion del servidor
- Ejecuta `FLUS_Server_Setup_<version>.exe` como administrador.
- Si detecta una instalacion valida existente, entra en modo actualizacion y preserva datos/configuracion.
- Si es una instalacion nueva, reinicializa la base desde cero desde `install.sql` y deja el usuario `admin` del baseline.
- Al finalizar, abre FLUS en `http://localhost:8080/`.

## Instalacion de terminales
- Ejecuta `FLUS_Terminal_Setup_<version>.exe` en cada PC cliente.
- Ingresa la URL base del servidor FLUS, por ejemplo `http://192.168.0.10:8080/`.
- El instalador crea accesos directos para abrir FLUS y para ir directo a `terminal_select.php`.
- La terminal no instala stack local ni base local: trabaja contra el servidor.

## Notas
- El baseline `payload\db\install.sql` no incluye ventas, clientes, productos ni compras.
- En instalacion nueva quedan seeds minimos del sistema: roles, permisos, terminal inicial, config base y usuario `admin`.
- Las actualizaciones detienen Apache y exigen backup verificado de DB, configs,
  storage y codigo antes de copiar, aunque no haya migraciones pendientes.
- Se preservan `config.php`, `config.local.php`, `config_arca.php` y
  `config_mp.php`.
- Para planes Cloud se instala o repara la tarea automatica `FLUS_CloudSync`.
- El payload actual debe incluir tambien `migrations/`, `tests/`, `docs/` y `README.md` dentro de `payload\app` para que `php scripts\migrate.php` y el Panel Tecnico funcionen en instalaciones nuevas.
