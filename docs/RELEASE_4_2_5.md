# Release 4.2.5

Fecha: 2026-07-26

Objetivo: actualizar instalaciones 4.2.x preservando datos y dejar Cloud
operativo de forma automatica, sin depender del boton de Tecnico.

## Version

- Version visible: `4.2.5`
- Build: `2026-07-26`
- Rama base: `Ver-4.0.0`
- Ultima migracion: `045_cloud_sync_queue.sql`

## Cloud automatico

- `cloud_sync_task_setup.ps1` crea la tarea local `FLUS_CloudSync` como SYSTEM.
- El disparador corre al iniciar Windows y cada minuto.
- La tarea solo recibe rutas locales; URL y token permanecen en `src/config.php`.
- Caja y ventas solo encolan eventos. El worker envia fuera del request operativo.
- Un lock local y otro MySQL impiden dos despachos simultaneos.
- Los fallos de red conservan la cola y reintentan con backoff hasta 60 minutos.
- Tecnico muestra estado y permite un reintento manual.
- `cloud_sync_setup.ps1 -DisableCloud` quita la tarea explicitamente.

## Upgrade protegido

Antes de copiar archivos, el instalador servidor:

1. Detiene Apache si estaba activo.
2. Crea un dump MySQL usando un archivo temporal de credenciales.
3. Respalda configuracion, storage, codigo y archivos DB.
4. Cancela el upgrade si el dump no puede verificarse.
5. Preserva `config.php`, `config.local.php`, `config_arca.php`, `config_mp.php`,
   licencia, storage, DB y backups existentes.
6. Ejecuta migraciones y restaura el estado previo de Apache.
7. Instala o repara la tarea Cloud si la licencia/configuracion lo requiere.

El nombre de base se obtiene de la configuracion efectiva, incluso cuando usa
`flus_env()`. Como respaldo solo se acepta el `manifest.json` del dump previo
verificado; nunca se elige una base por aproximacion.

Los respaldos quedan en `C:\FLUS\upgrade_backups`.

## Artefactos

- `FLUS_Server_Setup_4.2.5.exe`
- `FLUS_Terminal_Setup_4.2.5.exe`
- `SHA256SUMS.txt`
- `SOURCE_SHA256SUMS.txt`
- `RUNTIME_SHA256SUMS.txt`
- `BUILD_INFO.txt`

Los endpoints y la clave privada del mock local de licencias se excluyen del
payload productivo.

## Piloto antes de produccion

1. Verificar SHA256 del instalador.
2. Usar una instalacion descartable o copia de prueba, nunca la primera PC real.
3. Confirmar backup en `upgrade_backups` antes de continuar.
4. Verificar version 4.2.5, login, licencia, Caja y una venta de prueba.
5. Abrir Tecnico y comprobar migracion 045, tarea automatica y cola.
6. Simular red caida, confirmar que Caja no se bloquea y que queda pendiente.
7. Restaurar red y confirmar envio automatico exactamente una vez.
8. Solo entonces actualizar una PC productiva; observarla antes de la segunda.

El backup previo puede validarse sin tocar servicios reales con
`installer/test_preupgrade_pilot.ps1`; usa una base `flus_it_*` y un nombre de
servicio aislado.

## Riesgo residual

Los ejecutables no quedan firmados digitalmente mientras no exista un
certificado de firma de codigo. Windows puede mostrar advertencia de editor.
