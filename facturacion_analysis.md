# Análisis del estado de FLUS con el nuevo módulo de facturación

En **FLUS** (version `3.8.1`, build `2026-03-23`) ya se ve consolidado el ingreso del **modulo de facturacion electronica** introducido en `3.8.0` y endurecido luego en `3.8.1`. La funcionalidad visible en el repo incluye:

- **Listado de facturas** (`public/facturacion.php`):
  - Permite buscar y filtrar facturas por rango de fechas, cliente, número, tipo (A/B/C) y estado (emitida/anulada).
  - Proporciona filtros rápidos (Hoy, Semana, Mes) y estadísticas clave como total facturado, cantidad de facturas y ticket promedio.
  - Muestra la información fiscal (CAE, vencimiento, tipo de comprobante, cliente) y enlaza con la venta asociada.
  - Permite exportar las facturas filtradas a CSV.

- **Emisión de facturas manuales** (`public/factura_manual.php`):
  - Formulario interactivo para crear facturas sin depender de una venta previa.
  - Selección del **concepto** (Productos o Servicios) y búsqueda rápida de productos/servicios a través de autocompletado.
  - Permite elegir un cliente (busqueda con lookup AFIP/ARCA), añadir varias líneas con cantidad, precio, IVA e indica el límite de ítems configurado.
  - Calcula automáticamente los totales (neto, IVA y total) y muestra una previsualización antes de emitir.
  - Integra con ARCA/AFIP para solicitar CAE y generar el PDF oficial de la factura.

- **Emisión de facturas desde ventas** (`src/facturacion_lib.php`):
  - Ofrece funciones para emitir facturas fiscales a partir de una venta ya registrada (`crearFacturaDesdeVenta`).
  - Determina automáticamente el tipo de comprobante según la condición IVA del cliente y del negocio.
  - Maneja numeración correlativa por punto de venta y calcula importes netos, IVA y totales.
  - Registra el CAE y su vencimiento y marca la venta como facturada.

- **Configuración de facturación** (`public/facturacion_config.php`):
  - Panel disponible para usuarios con permiso `administrar_config` donde se configuran datos fiscales de la empresa: razón social, CUIT, domicilio, ingreso bruto, fecha de inicio de actividades, punto de venta y condición IVA.
  - Permite elegir el **modo de funcionamiento**: Demo, Homologación (ARCA) o Producción; y subir los certificados y claves requeridos para AFIP/ARCA.
  - Incluye pruebas de conectividad a ARCA y sincronización de la numeración fiscal con AFIP.
  - Permite cargar un logo para la factura y definir el límite máximo de ítems por comprobante.
  - Presenta una guía paso a paso para homologación y producción, con instrucciones sobre certificados y configuraciones necesarias.

- **Biblioteca de facturación** (`src/facturacion_lib.php`):
  - Implementa todas las funciones necesarias para la facturación: generación de números de comprobante, cálculo de importes, determinación de tipo de factura (A/B/C), validación de datos del cliente y comunicación con ARCA/AFIP.
  - Contiene utilidades para comprobar la configuración, insertar facturas y actualizar ventas, así como para obtener estadísticas del módulo (número de facturas, total facturado, etc.).

- **Migraciones y cambios en base de datos**: existen migraciones SQL (`sql/005_facturacion_electronica_PATCH_config.sql`) que añaden tablas y campos para almacenar facturas, datos fiscales de la empresa y configuraciones de facturación.
