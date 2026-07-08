# Trabajo FLUS en dos maquinas

Fecha: 2026-07-08

Objetivo: poder trabajar FLUS desde la PC de casa y la PC del trabajo sin pisar cambios, sin copiar configs sensibles y manteniendo GitHub como fuente de verdad.

## Regla principal

La rama de trabajo sigue siendo `Ver-4.0.0` por compatibilidad historica, aunque la version visible actual sea `4.2.1`.

No se empieza un bloque nuevo si la otra maquina quedo con cambios sin commitear o sin pushear.

## Al empezar en cualquier maquina

Desde `C:\xampp82\htdocs\kiosco`:

```powershell
& "C:\Program Files\Git\bin\git.exe" status --short --branch
& "C:\Program Files\Git\bin\git.exe" pull --ff-only origin Ver-4.0.0
```

Si `status` muestra cambios locales, esa maquina es la duena del bloque hasta resolverlos. No hacer `pull`, `reset` ni copiar archivos a mano sin revisar el diff.

## Al cerrar un bloque

Validar segun lo tocado:

```powershell
& "C:\xampp82\php\php.exe" -l public\archivo.php
node --check public\assets\js\archivo.js
& "C:\xampp82\php\php.exe" tests\smoke.php
```

Luego:

```powershell
& "C:\Program Files\Git\bin\git.exe" add rutas\tocadas
& "C:\Program Files\Git\bin\git.exe" commit -m "Mensaje corto y claro"
& "C:\Program Files\Git\bin\git.exe" push origin Ver-4.0.0
```

## Archivos que no se copian ni se versionan

- `src/config.php`
- `src/config_mp.php`
- `storage/`
- backups, dumps, exports y uploads
- `vendor/`
- `node_modules/`
- archivos temporales de instalador o pruebas locales

Cada maquina debe tener su propia configuracion local. Si una prueba necesita cambiar credenciales, token de Mercado Pago, licencia o DB, hacerlo en la maquina correspondiente y no subirlo al repo.

## Orden de trabajo recomendado

1. Pull limpio.
2. Cambios chicos.
3. Validacion.
4. Commit.
5. Push.
6. Recien ahi seguir desde la otra maquina.

Si un bloque queda a medias, anotar en el commit o en una nota local que maquina lo tiene abierto. Evitar trabajar el mismo archivo grande desde ambas PCs el mismo dia.

