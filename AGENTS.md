<claude-mem-context>
# Memory Context

# [kiosco] recent context, 2026-05-16 10:31am GMT-3

Legend: 🎯session 🔴bugfix 🟣feature 🔄refactor ✅change 🔵discovery ⚖️decision
Format: ID TIME TYPE TITLE
Fetch details: get_observations([IDs]) | Search: mem-search skill

Stats: 50 obs (11.344t read) | 300.454t work | 96% savings

### Apr 22, 2026
29 1:43p 🟣 Payment processing for transfers via credit card from POS
### Apr 23, 2026
30 9:06a 🔵 Initial session state check
31 9:07a 🟣 Payment processing for transfers via credit card from POS
32 " 🟣 Payment processing with credit card and transfer option
33 " 🔵 Payment processing with credit card and transfer option
34 " 🔵 Total_transferencia is correctly selected and exported, but not used in Medios reconciliation logic.
38 9:31a 🔵 PHP module configuration in Apache
35 " ✅ User confirmed task completion
### Apr 24, 2026
41 11:44a 🟣 Implementación de endpoint para obtener todos los elementos nuevos
43 11:49a 🔵 Git no reconocido en el entorno de shell
44 " 🔵 Comando Git 'remote -v' no reconocido
45 " 🔵 Comando Git 'branch --show-current' no reconocido
46 " 🔵 Git no encontrado en el PATH del sistema
47 " 🔵 Comando 'Get-Command git' no reconocido
42 " 🟣 Implementación de endpoint para obtener todos los elementos nuevos
61 11:52a 🔵 Git merge conflict markers found in code
63 11:55a 🟣 Implemented user profile image upload
### May 5, 2026
72 11:23a 🔵 Search for 'caja_historial tecnico.php' yielded no results
67 " 🔵 Claude-Mem utility for Codex
69 11:24a 🔵 Kiosco health check successful
71 " 🔵 Claude-Mem worker service status confirmed
### May 15, 2026
104 11:20a 🟣 AI Agent for Antigravity Testing
105 " 🔵 Exploración inicial del proyecto Kiosco
106 " 🔵 Identificación de la estructura de directorios y archivos clave
107 " 🔵 Análisis del archivo de configuración principal
108 " 🔵 Identificación de la URL probable de ejecución
109 " 🔵 Ausencia de credenciales de login y datos demo explícitos
110 " 🔵 Identificación de módulos de alto valor para barrido UI
111 " 🔵 Evaluación de riesgos de pruebas que podrían alterar datos
113 11:21a 🔵 Project Directory Structure
122 " 🔵 Agent Documentation Found
112 " 🟣 AI Agent for Antigravity Testing
114 " 🔵 Exploración inicial del proyecto Kiosco
115 " 🔵 Identificación de la estructura de directorios y archivos clave
116 " 🔵 Análisis del archivo de configuración principal
117 " 🔵 Identificación de la URL probable de ejecución
118 " 🔵 Ausencia de credenciales de login y datos demo explícitos
119 " 🔵 Identificación de módulos de alto valor para barrido UI
120 " 🔵 Evaluación de riesgos de pruebas que podrían alterar datos
123 11:37a 🔵 Kiosco App Local Execution and Configuration Findings
124 11:38a 🔵 Kiosco App Local Execution and Configuration Details
### May 16, 2026
125 10:19a 🔴 Kiosco chat visualization issue
126 " 🔵 Chat ID not found in Kiosco project files
127 " 🔵 Windows Sandbox environment error during file listing
128 " 🔵 Windows Sandbox environment error during Git status check
129 " 🔵 Kiosco project directory contents listed successfully
130 " 🔵 Git command not found in Windows Sandbox environment
131 10:22a 🟣 Agent Workflows Documentation for FLUS Repository
132 10:25a 🔵 FLUS Repository File Structure
133 10:29a 🟣 Agent Workflows Documented in AGENTS.md

Access 300k tokens of past work via get_observations([IDs]) or mem-search skill.
</claude-mem-context>

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
