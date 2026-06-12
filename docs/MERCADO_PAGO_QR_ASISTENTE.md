# Asistente Mercado Pago QR de prueba

FLUS incluye un asistente para crear la sucursal y la caja QR de prueba sin
copiar comandos `curl`.

## Antes de empezar

En Mercado Pago abre:

`Tus integraciones > Tu aplicacion > Pruebas > Credenciales de prueba`

Necesitas el `Access Token` mostrado en la pestaña `Prueba`. Mercado Pago
actualmente puede mostrarlo con prefijo `APP_USR-`; el prefijo no indica por si
solo si la credencial es de prueba o productiva. No uses Public Key, Client ID
ni Client Secret.

Tambien copia el `User ID` que aparece en
`Credenciales > Prueba > Detalles > User ID`. No debe reemplazarse por el
identificador devuelto por `/users/me`, ya que Mercado Pago puede mostrar
valores diferentes para estas credenciales.

Tambien necesitas la direccion real del comercio y sus coordenadas decimales.
Para evitar rechazos de la API, escribe el nombre de sucursal, calle, ciudad y
provincia solo con letras y espacios. Por ejemplo, usa `Canaan` y `Guaymallen`,
sin numeros ni tildes.

En `Calle real` ingresa solamente el nombre de la calle principal, no una
interseccion. Por ejemplo, usa `Buenos Vecinos` y coloca `5893` en `Numero`.

Si Mercado Pago rechaza la solicitud, el asistente crea
`scripts/mp_qr_ultimo_error.json` con el pedido y la respuesta, sin guardar el
Access Token.

## Ejecutar

En una instalacion FLUS abre:

`C:\FLUS\app\scripts\Configurar Mercado Pago Prueba.cmd`

El asistente solicita los datos, muestra un resumen y solo crea los recursos
cuando se escribe `CREAR`.

Al finalizar:

1. Crea una copia de seguridad de `src/config_mp.php` si ya existia.
2. Guarda el Access Token de prueba y el POS externo generado.
3. Configura QR hibrido, modo automatico y fallback manual.
4. Conserva los enlaces del QR estatico devueltos por Mercado Pago.

## Verificar

En FLUS abre `Administracion > Mercado Pago` y pulsa `Probar conexion`.

Luego realiza una venta usando una cuenta compradora de prueba distinta de la
cuenta vendedora.

## Simulacion tecnica

Para validar el formulario sin llamar a Mercado Pago:

```powershell
powershell.exe -ExecutionPolicy Bypass -File scripts\mp_qr_setup.ps1 `
  -DryRun `
  -AccessToken "APP_USR-SIMULACION" `
  -CollectorUserId "2180750003" `
  -StreetName "Calle de prueba" `
  -StreetNumber "123" `
  -CityName "Mendoza" `
  -StateName "Mendoza" `
  -Latitude -32.8895 `
  -Longitude -68.8458
```
# Configuracion desde FLUS

El panel `Configuracion > Mercado Pago` permite elegir explicitamente `Prueba` o
`Produccion`. El prefijo `APP_USR-` no identifica por si solo el ambiente.

FLUS guarda el avance de sucursal y caja por ambiente. Si Mercado Pago crea la
sucursal pero rechaza temporalmente la caja, el siguiente intento reutiliza la
misma sucursal y el mismo identificador externo.

Para notificaciones:

1. Publicar `public/mercadopago_webhook.php` mediante una URL HTTPS accesible.
2. En Mercado Pago, configurar el evento `Order (Mercado Pago)`.
3. Copiar la clave secreta generada al campo `Clave secreta Webhook`.
4. Mantener el polling de caja habilitado; el webhook funciona como respaldo y
   trazabilidad, no como unico mecanismo de confirmacion.
