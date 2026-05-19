# FLUS / Sistema-Gestion-

Este archivo es la guia corta para trabajar en `Ver-4.0.0` sin releer todo el repo.
La memoria previa, commits y ramas anteriores son pistas, no fuente de verdad.
La fuente de verdad siempre es el repo actual.

## Stack y limites

- FLUS usa PHP, MySQL/MariaDB, JavaScript vanilla y CSS propio.
- No introducir React, Tailwind, shadcn ni frameworks nuevos salvo pedido explicito.
- No tocar `install.sql` ni `migrations/` sin justificar instalacion limpia y upgrade.
- No mezclar logica fiscal con UI.
- No mezclar trazabilidad ARCA con envio de email.
- Mantener compatibilidad legacy de ventas, facturas, caja, notas de credito y reportes.

## Lectura minima

- Antes de leer archivos grandes, usar `rg`, `git diff`, `git log`, `git blame` o Graphify si esta disponible.
- Leer primero el archivo directamente involucrado y sus dependencias reales.
- No recorrer carpetas completas si una busqueda puntual alcanza.
- No leer ni commitear `storage/`, backups, dumps, exports, `vendor/`, `node_modules/`, uploads ni configs sensibles.
- No asumir tablas, columnas, rutas, endpoints ni helpers: verificarlos en el repo.

## Validaciones

- Para PHP modificado, correr `php -l` sobre los archivos tocados.
- Si el cambio toca logica critica, permisos, migraciones, caja, ventas, facturacion o helpers compartidos, correr `php tests/smoke.php`.
- Usar `tests/integration_db.php` solo si el cambio necesita DB real o migraciones completas.
- Si una validacion no se puede correr, explicar el motivo exacto.

## Base actual

- Rama base candidata: `Ver-4.0.0`.
- Remoto esperado: `origin/Ver-4.0.0` cuando exista remoto publicado.
- `.codex_worktrees/` es temporal local y no debe commitearse.

## Agent Workflows / Skills probados para FLUS

Esta seccion complementa las reglas existentes del repo. FLUS es un sistema PHP/MySQL para ventas, caja, stock, facturacion, notas de credito y trazabilidad. En este repo se detectaron como puntos reales de trabajo `install.sql`, `migrations/`, `scripts/migrate.php`, `tests/smoke.php`, `src/`, `public/` y documentacion en `docs/`.

### Reglas globales para todos los workflows

- Trabajar siempre sobre evidencia del repo.
- No inventar funciones, tablas, columnas, rutas, endpoints ni modulos.
- Antes de opinar, buscar el archivo real involucrado.
- Preferir parches chicos, verificables y compatibles con la estructura actual.
- Si algo no se pudo verificar, decirlo explicitamente.
- Mantener FLUS usable aunque facturacion este desactivada.
- No mezclar logica fiscal con UI.
- No mezclar trazabilidad ARCA con envio de email.
- No romper compatibilidad legacy de venta/factura.
- Agregar pruebas cuando el cambio afecte logica critica.

### Senior Code Reviewer

Uso: revision general de cambios.

Debe buscar:
- bugs reales;
- regresiones;
- permisos inconsistentes;
- SQL inseguro;
- compatibilidad rota;
- flujos fiscales inconsistentes.

No debe:
- felicitar sin aportar;
- inventar problemas;
- proponer reescrituras grandes sin necesidad.

Checklist FLUS:
- Revisar primero los archivos modificados y sus dependencias reales.
- Confirmar que los permisos se validan en backend, no solo ocultando botones.
- Verificar que caja, ventas, facturacion, notas de credito y reportes historicos no pierdan compatibilidad.
- Separar hallazgos comprobados de riesgos no verificados.

### Bug Hunter

Uso: encontrar causas raiz.

Debe:
- reproducir mentalmente el flujo completo;
- revisar frontend, backend y DB;
- buscar errores silenciosos;
- proponer el parche minimo;
- agregar test si aplica.

Checklist FLUS:
- Seguir el camino desde `public/` hasta helpers o servicios en `src/`.
- Revisar queries, validaciones de sesion, permisos y estados de DB.
- Si el bug toca facturacion, comprobar tambien el modo con facturacion desactivada.
- Si el bug toca caja o ventas, cuidar el flujo operativo antes de cambiar UI.

### Database Migration Auditor

Uso: revisar `install.sql`, `migrations/` y upgrades.

Debe verificar:
- columnas duplicadas entre baseline y migraciones;
- migraciones idempotentes;
- permisos agregados correctamente;
- compatibilidad con instalaciones existentes;
- `scripts/migrate.php` si aplica.

Checklist FLUS:
- Comparar cualquier cambio de esquema contra `install.sql`.
- Revisar que la migracion nueva no repita columnas, indices o permisos ya existentes.
- Confirmar que el alta de permisos queda alineada con roles/admin y smoke tests cuando corresponda.
- Validar tanto instalacion limpia como upgrade desde una DB existente cuando el cambio lo requiera.

### Refactor Guardian

Uso: controlar refactors.

Debe:
- preservar comportamiento existente;
- evitar rewrites innecesarios;
- mantener APIs legacy;
- preferir cambios incrementales;
- explicar riesgos antes de tocar modulos grandes.

Checklist FLUS:
- No refactorizar modulos criticos solo por estilo.
- Mantener contratos usados por `public/`, `src/`, tests y scripts.
- En archivos grandes o hotspots, extraer solo piezas justificadas y cubiertas.
- Si el refactor toca venta/factura legacy, documentar el riesgo y validar compatibilidad.

### UI/UX Reviewer

Uso: revisar caja, modales, responsive y pantallas.

Debe priorizar:
- usabilidad real en 1366x768;
- claridad visual;
- scrolls correctos;
- SweetAlert/flus_notif en vez de `alert()`/`confirm()` nativos;
- no romper flujo operativo de caja.

Checklist FLUS:
- Revisar la pantalla real antes de proponer cambios.
- Priorizar velocidad operativa, jerarquia visual y feedback claro.
- Cuidar modales, tablas, filtros, acciones peligrosas y estados vacios.
- No introducir redisenos decorativos que no mejoren la operacion.

### Security Auditor

Uso: endpoints, permisos, sesiones y acciones sensibles.

Debe revisar:
- permisos backend, no solo botones ocultos;
- CSRF cuando aplique;
- SQL preparado;
- tokens de PDF/factura;
- acciones peligrosas como anular, facturar, emitir NC, borrar y exportar.

Checklist FLUS:
- Buscar validaciones reales en el endpoint o accion que ejecuta el cambio.
- Revisar que acciones sensibles exijan sesion, rol/permisos y datos validos.
- Confirmar que links publicos, tickets, PDFs y facturas no expongan tokens debiles.
- Evitar filtrar errores internos, rutas locales o datos fiscales sensibles al usuario final.

### Release Engineer

Uso: preparar version entregable.

Debe verificar:
- `php -l` en archivos PHP modificados;
- `php tests/smoke.php`;
- migraciones limpias;
- instalacion limpia;
- upgrade desde DB existente;
- version/build si aplica;
- dependencias locales no ignoradas por `.gitignore`.

Checklist FLUS:
- Revisar `src/version.php` y documentacion de release solo si el cambio lo requiere.
- Confirmar que no quedaron archivos temporales, backups locales o dependencias sin control.
- Si no se puede correr una validacion, explicar el motivo exacto.

### Test Engineer

Uso: agregar cobertura.

Debe:
- priorizar smoke tests utiles;
- cubrir bugs corregidos;
- evitar tests decorativos;
- validar permisos, migraciones y flujos criticos.

Checklist FLUS:
- Usar `tests/smoke.php` para cobertura rapida cuando sea suficiente.
- Considerar `tests/integration_db.php` solo para cambios que necesitan DB real o migraciones completas.
- Cubrir el caso de facturacion desactivada si el cambio toca flujos fiscales o comerciales.
- Preferir tests que fallen por el bug real y no por detalles cosmeticos.

### Legacy Compatibility Maintainer

Uso: proteger clientes existentes.

Debe asegurar:
- ventas existentes siguen funcionando;
- facturas legacy siguen visibles;
- modo sin facturacion sigue operativo;
- cambios DB son aditivos cuando sea posible;
- no se rompen reportes historicos.

Checklist FLUS:
- Revisar paths legacy de ventas, factura comun, notas de credito y reportes.
- Evitar renombrar columnas, estados o funciones usadas por datos existentes.
- Al cambiar DB, preferir defaults, backfills seguros e idempotencia.
- Validar que documentos antiguos sigan siendo consultables.

### Production Hardening

Uso: endurecer antes de release.

Debe buscar:
- estados inconsistentes;
- errores no manejados;
- race conditions simples;
- fallos de permisos;
- problemas de recuperacion fiscal;
- mensajes de error poco claros.

Checklist FLUS:
- Revisar transacciones, estados fiscales, reintentos y recovery antes de tocar ARCA.
- Confirmar que no se reemite contra ARCA a ciegas si ya existe autorizacion o `request_uid`.
- Mantener separada la trazabilidad fiscal de la trazabilidad comercial/email.
- Mejorar errores operativos sin exponer detalles sensibles.
