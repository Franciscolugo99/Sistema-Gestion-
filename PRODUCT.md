# Product

## Register

product

## Users

FLUS lo usan comercios reales: kioscos, autoservicios, dieteticas, ferreterias, locales de ropa, negocios 24 hs y administradores que necesitan caja, ventas, stock, compras, facturacion, cuenta corriente, tesoreria y control operativo en una sola instalacion.

Los usuarios principales no estan explorando una app por curiosidad. Estan atendiendo gente, cobrando rapido, cerrando turnos, revisando diferencias, consultando stock o controlando permisos. Muchos flujos ocurren en una PC de mostrador, con teclado, scanner, ticketera, pantalla no siempre grande y poco margen para leer explicaciones largas.

## Product Purpose

FLUS existe para que un comercio pueda operar ventas y administracion diaria con control, trazabilidad y baja friccion. La caja debe permitir vender rapido, pero sin perder disciplina: cada turno, terminal, medio de pago, anulacion, cierre, recargo, redondeo y movimiento sensible debe quedar entendible y auditable.

El producto tiene que servir tanto al cajero como al administrador. El cajero necesita foco y velocidad. El administrador necesita control, permisos, reportes, conciliacion y recuperacion ante errores. Exito significa que el negocio puede confiar en FLUS durante un dia de trabajo completo, no solo en una demo.

## Brand Personality

FLUS debe sentirse claro, confiable y operativo.

La personalidad es practica y sobria, con energia de producto moderno sin ponerse decorativa. Puede tener momentos de color y feedback vivo en caja, pero siempre al servicio de una accion: vender, cobrar, reimprimir, confirmar, cerrar, auditar o corregir.

La voz de la interfaz debe sonar directa y humana. Mejor explicar "Tu usuario no tiene permiso para anular ventas" que ocultar acciones o mostrar errores tecnicos. Mejor mostrar estado y proximo paso que llenar la pantalla de texto educativo.

## Anti-references

FLUS no debe parecer una landing SaaS, una plantilla generica, un panel inflado con tarjetas decorativas ni una app que prioriza estetica por encima del flujo de caja.

Evitar:

- Heroes, slogans o composiciones de marketing dentro de pantallas operativas.
- Gradientes decorativos dominantes sin funcion.
- Botones con estilos distintos para acciones del mismo nivel.
- Modales innecesarios cuando una accion puede resolverse inline.
- Texto largo que el cajero tenga que leer mientras atiende.
- Acciones peligrosas visibles para usuarios sin permiso.
- Interfaces que parezcan simples solo porque esconden control.
- Paletas de un solo color que hagan perder jerarquia entre venta, alerta, exito y peligro.

## Design Principles

1. La caja primero. En superficies de mostrador, el flujo producto, cantidad, medio de pago y ticket siempre tiene prioridad sobre explicaciones secundarias.
2. Control visible, no burocracia visible. Las restricciones, permisos y auditoria deben existir en backend y mostrarse cuando ayudan, sin cargar al cajero con tareas administrativas.
3. Cada estado tiene que decir que paso y que hacer. Error, contingencia, pago manual, diferencia de caja o permiso faltante necesitan un mensaje breve, operativo y accionable.
4. La velocidad no justifica perder trazabilidad. Reimprimir puede ser rapido; anular, cerrar, modificar precios o registrar contingencias requieren permisos y registro.
5. Consistencia antes que sorpresa. Botones, modales, tablas, filtros, estados y alertas deben comportarse igual en todos los modulos.

## Accessibility & Inclusion

FLUS debe apuntar a una base WCAG AA razonable para pantallas administrativas y de caja: contraste suficiente, foco visible, estados no dependientes solo del color, formularios con labels, mensajes asociados a acciones y compatibilidad con teclado.

La interfaz debe funcionar bien en 1366x768, monitores de mostrador, modo claro y modo oscuro. Las animaciones deben ser breves y respetar `prefers-reduced-motion`. El texto debe evitar abreviaturas ambiguas cuando afecte dinero, permisos o cierre de turno.
