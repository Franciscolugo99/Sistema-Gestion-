---
name: FLUS
description: Sistema POS y gestion comercial para operacion diaria con caja, stock, facturacion y control.
colors:
  surface-app: "#f5f7fb"
  surface-app-dark: "#020617"
  surface-panel: "#ffffff"
  surface-panel-dark: "#111827"
  surface-soft: "#f8fbff"
  surface-soft-dark: "#101722"
  text-primary: "#0f172a"
  text-primary-dark: "#eef2ff"
  text-muted: "#6b7280"
  text-muted-dark: "#9ca3af"
  border-soft: "#e2e8f0"
  accent-cyan: "#06b6d4"
  accent-blue: "#0ea5e9"
  accent-green: "#22c55e"
  danger: "#ef4444"
  warning: "#eab308"
typography:
  headline:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "1.5rem"
    fontWeight: 900
    lineHeight: 1.15
    letterSpacing: "0"
  title:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "1.125rem"
    fontWeight: 800
    lineHeight: 1.2
    letterSpacing: "0"
  body:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "1rem"
    fontWeight: 400
    lineHeight: 1.5
    letterSpacing: "0"
  label:
    fontFamily: "Inter, system-ui, sans-serif"
    fontSize: "0.75rem"
    fontWeight: 800
    lineHeight: 1.2
    letterSpacing: "0.12em"
  mono:
    fontFamily: "Fira Code, Courier New, monospace"
    fontSize: "0.875rem"
    fontWeight: 600
    lineHeight: 1.3
    letterSpacing: "0"
rounded:
  sm: "8px"
  md: "12px"
  lg: "16px"
  xl: "20px"
  full: "999px"
spacing:
  xs: "6px"
  sm: "10px"
  md: "14px"
  lg: "18px"
  xl: "24px"
  xxl: "30px"
components:
  button-primary:
    backgroundColor: "{colors.accent-green}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.md}"
    padding: "0 14px"
    height: "38px"
  button-secondary:
    backgroundColor: "{colors.surface-soft}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.md}"
    padding: "0 14px"
    height: "38px"
  button-danger:
    backgroundColor: "#fee2e2"
    textColor: "#7f1d1d"
    rounded: "{rounded.md}"
    padding: "0 14px"
    height: "38px"
  panel:
    backgroundColor: "{colors.surface-panel}"
    textColor: "{colors.text-primary}"
    rounded: "{rounded.lg}"
    padding: "18px"
---

# Design System: FLUS

## 1. Overview

**Creative North Star: "Mostrador de Control"**

FLUS es una interfaz de trabajo para comercio real. Su diseno debe sentirse como un mostrador ordenado: cada herramienta esta cerca, cada estado se entiende rapido y cada accion sensible tiene su lugar. El sistema puede ser moderno y visualmente cuidado, pero nunca debe competir con el ritmo de venta.

La estetica base es producto operativo: superficies claras, bordes definidos, jerarquia compacta, indicadores de estado, botones consistentes y tablas escaneables. En caja, la densidad es una virtud si mejora velocidad y control. En administracion, la claridad de permisos, filtros, reportes y auditoria pesa mas que el impacto visual.

FLUS rechaza la decoracion de landing, los gradientes sin funcion, los modales de mas y los botones que cambian de lenguaje entre pantallas.

**Key Characteristics:**

- Operativo antes que promocional.
- Compacto, claro y confiable.
- Color usado para estado y accion, no para relleno decorativo.
- Permisos y acciones peligrosas visibles solo cuando corresponden.
- Modo claro y oscuro con la misma estructura funcional.

## 2. Colors

La paleta combina neutrales frios con acentos cyan, azul y verde para separar accion, informacion y exito sin convertir toda la app en una sola familia cromatica.

### Primary

- **Verde Operativo** (#22c55e): confirma venta, caja abierta, cobro exitoso y accion primaria de avance.
- **Cyan FLUS** (#06b6d4): identidad del sistema, foco, informacion activa y acentos de navegacion.
- **Azul de Sistema** (#0ea5e9): enlaces, estados de informacion y seleccion secundaria.

### Secondary

- **Rojo de Control** (#ef4444): peligro, anular, cerrar, rechazo, error y acciones irreversibles.
- **Ambar de Revision** (#eab308): diferencias, advertencias, pendientes de conciliacion o situaciones que requieren atencion.

### Neutral

- **Fondo Claro** (#f5f7fb): ambiente general en modo claro.
- **Fondo Oscuro** (#020617): ambiente general en modo oscuro.
- **Panel Claro** (#ffffff): tarjetas, formularios, modales y paneles.
- **Panel Oscuro** (#111827): equivalente oscuro para superficies.
- **Texto Principal** (#0f172a): lectura primaria y datos.
- **Texto Muted** (#6b7280): ayudas, labels y metadata.
- **Borde Suave** (#e2e8f0): separacion estructural sin ruido.

### Named Rules

**The Money State Rule.** Verde confirma dinero recibido o flujo exitoso; rojo advierte perdida, cierre, anulacion o peligro. No usar esos colores como decoracion neutral.

**The One Accent Per Task Rule.** En cada panel operativo debe haber una accion dominante. El resto acompana con secundarios sobrios.

## 3. Typography

**Display Font:** Inter, system-ui, sans-serif  
**Body Font:** Inter, system-ui, sans-serif  
**Label/Mono Font:** Fira Code, Courier New, monospace para IDs, importes tecnicos, tickets y datos tabulares.

**Character:** La tipografia de FLUS es de producto: directa, compacta y legible. Usa peso para jerarquia, no extravagancia.

### Hierarchy

- **Display** (900, 1.875rem a 2.25rem, 1.1): solo para titulos de modulos importantes, nunca dentro de controles densos.
- **Headline** (900, 1.5rem, 1.15): encabezados de paginas, modales grandes y paneles tecnicos.
- **Title** (800, 1.125rem, 1.2): secciones, tarjetas y grupos de formulario.
- **Body** (400 a 600, 1rem, 1.5): explicaciones breves, ayudas y contenido comun. Prosa maxima 65 a 75 caracteres por linea.
- **Label** (800, 0.75rem, 0.12em, uppercase): etiquetas cortas como "terminal", "cajero", "medio de pago". No usar en textos largos.
- **Mono** (600, 0.875rem, 1.3): IDs, fechas, codigos, importes de auditoria y referencias.

### Named Rules

**The No Display In Controls Rule.** Botones, filtros, tablas y paneles compactos usan escala chica y consistente. Los titulos grandes quedan para paginas y modales amplios.

## 4. Elevation

FLUS usa una mezcla de bordes, capas tonales y sombras suaves. La sombra no debe convertir cada seccion en tarjeta flotante. En pantallas densas, el borde y el contraste de fondo suelen alcanzar. Los modales y dropdowns si pueden elevarse porque interrumpen el flujo y necesitan prioridad visual.

### Shadow Vocabulary

- **Small** (`0 2px 6px rgba(0, 0, 0, 0.1)`): pequenos elementos interactivos y estados sutiles.
- **Medium** (`0 8px 20px rgba(0, 0, 0, 0.1)`): paneles secundarios cuando necesitan separarse del fondo.
- **Large** (`0 18px 40px rgba(0, 0, 0, 0.1)`): modales, paneles principales y superficies destacadas.
- **Caja Neo** (`0 18px 48px rgba(15, 23, 42, 0.08)`): caja en modo claro.
- **Caja Neo Dark** (`0 18px 54px rgba(0, 0, 0, 0.34)`): caja en modo oscuro.

### Named Rules

**The Border First Rule.** Si una superficie puede separarse con borde y fondo, no necesita sombra. Reservar sombra para overlays, foco operativo y jerarquia real.

## 5. Components

### Buttons

- **Shape:** radio medio en producto (`12px`) y pill solo para badges o controles compactos ya establecidos.
- **Primary:** verde o cyan segun flujo. Verde para avanzar/cobrar/confirmar. Cyan o azul para acciones de sistema.
- **Danger:** rojo suave con texto rojo oscuro, reservado para cerrar, anular, eliminar o forzar.
- **Hover / Focus:** cambio de fondo/borde y foco visible. No cambiar familia tipografica, peso o altura entre botones vecinos.
- **Copy:** verbos cortos: Cobrar, Reimprimir, Cerrar caja, Guardar configuracion. Evitar labels ambiguos como "OK" en acciones sensibles.

### Chips

- **Style:** fondo tonal, borde suave, texto de alto peso.
- **State:** exito, warning, danger e info deben tener color semantico consistente.
- **Use:** estados de caja, turnos, ventas, permisos, facturacion y filtros activos.

### Cards / Containers

- **Corner Style:** `16px` a `20px` para paneles principales; `12px` para controles internos.
- **Background:** panel claro u oscuro segun theme, con segunda superficie para zonas de caja.
- **Shadow Strategy:** bordes por defecto, sombra solo si eleva una herramienta o modal.
- **Internal Padding:** usar 14px, 18px o 24px segun densidad. No usar padding enorme en pantallas operativas.

### Inputs / Fields

- **Style:** fondo de panel, borde visible, radio `10px` a `12px`.
- **Focus:** borde cyan/azul y foco claro, sin mover layout.
- **Error / Disabled:** error rojo con texto accionable; disabled debe verse inactivo y no confundirse con informacion.

### Navigation

- **Pattern:** top nav con menus por area y acciones principales visibles segun permiso.
- **Active State:** gradiente cyan/verde ya existente o estado tonal equivalente.
- **Rule:** no mostrar modulos que el usuario no puede usar. Si conoce la URL, backend tambien debe bloquear.

### Modals

- **Use:** confirmaciones peligrosas, vista previa de ticket, QR Mercado Pago, terminales y acciones que interrumpen venta.
- **Shape:** dialogo centrado, max height con scroll interno y botones claros al pie.
- **Rule:** modales operativos tienen que devolver foco a caja cuando cierran.

### Tables

- **Style:** cabecera fuerte, filas alternadas o separadores claros, numeros tabulares a la derecha.
- **Use:** ventas, turnos, rendimiento, inventario y reportes.
- **Rule:** si una tabla tiene acciones sensibles, el permiso debe estar en backend y la accion no debe depender solo de ocultar botones.

## 6. Do's and Don'ts

Do:

- Priorizar el flujo de caja en 1366x768.
- Mantener botones de la misma fila con la misma altura, fuente y radio.
- Usar verde, rojo, ambar y azul con significado estable.
- Mostrar mensajes breves y accionables.
- Usar skeleton o estados vacios utiles en listas y modales.
- Cuidar permisos en frontend y backend.
- Mantener modales con scroll interno y foco recuperable.

Don't:

- No crear pantallas tipo landing dentro del producto.
- No usar tarjetas anidadas para resolver layout.
- No usar gradientes u orbes decorativos como fondo de producto.
- No mostrar anular, borrar, cerrar o forzar si el usuario no tiene permiso.
- No depender del color solamente para comunicar estados.
- No agrandar tipografia dentro de tablas, toolbars o paneles compactos.
- No mezclar confirmacion automatica de pago con declaracion manual de contingencia.
