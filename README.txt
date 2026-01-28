FLUS_PATCH_licencias_v1

Qué incluye:
- Enforcement offline leyendo storage/license.json + anti-rollback (storage/license_state.json)
- Widget “Licencia” en Acerca de FLUS usando estado calculado (FLUS_LICENSE)
- Pantalla admin: /licencia.php para subir/renovar JSON
- Endpoint API: /api/license_status.php
- Bloqueo por licencia en exports:
  - /dashboard_export.php
  - /caja_sesion_export.php
- Link “Licencia” en menú admin (⚙️)

Cómo instalar:
1) Copiá el contenido del ZIP sobre la raíz del proyecto (misma carpeta donde están /public, /src, /storage).
2) Asegurate que /storage tenga permisos de escritura (necesario para license.json y license_state.json).
3) Entrá como admin a: Licencia (⚙️ -> Licencia) y subí tu license.json.

Formato mínimo de license.json:
{
  "plan": "PRO",
  "expires_at": "2026-03-31"
}

Firma (opcional):
- license.json puede incluir:
  - payload: "..."
  - sig: "base64..."
- Definí FLUS_LICENSE_PUBKEY_B64 en src/config.php
