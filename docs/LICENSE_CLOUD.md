# FLUS Cloud License Validation

FLUS keeps the offline signed license in `storage/license.json`. Cloud validation is optional and disabled by default.

To enable it in a test installation, define these constants in the local config before `src/license.php` is loaded:

```php
define('FLUS_LICENSE_CLOUD_URL', 'https://flus.com.ar/admin/api/license-check.php');
define('FLUS_LICENSE_CLOUD_REQUIRED', true);
define('FLUS_LICENSE_CLOUD_INTERVAL_SEC', 300);
define('FLUS_LICENSE_CLOUD_OFFLINE_GRACE_DAYS', 7);
define('FLUS_LICENSE_CLOUD_TIMEOUT_SEC', 4);
define('FLUS_LICENSE_CLOUD_TOKEN', 'TOKEN_COMPARTIDO_OPCIONAL');
define('FLUS_LICENSE_CLOUD_PUBKEY_PEM', "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----");
define('FLUS_LICENSE_CLOUD_CHECK_EVERY_REQUEST', false);
```

## Local Behavior

- If `FLUS_LICENSE_CLOUD_URL` is not set, FLUS behaves exactly like the offline license flow.
- If `FLUS_LICENSE_CLOUD_REQUIRED` is true and the cloud URL is missing, FLUS enters limited mode.
- When enabled, FLUS sends `license_key`, `installation_id`, version/build and current local status.
- If `FLUS_LICENSE_CLOUD_TOKEN` is set, FLUS sends it as a Bearer token.
- The response must be signed. FLUS verifies it before trusting any status.
- FLUS verifies that the signed payload belongs to the same `license_key` and local `installation_id`.
- `FLUS_LICENSE_CLOUD_PUBKEY_PEM` should be the public key paired with the cloud/admin private key. It can differ from the offline license public key.
- The last valid response is cached in `storage/license_cloud_state.json`.
- FLUS caps the remote `next_check_at` using the local `FLUS_LICENSE_CLOUD_INTERVAL_SEC`; the cloud cannot force a longer delay than the installation allows.
- If `FLUS_LICENSE_CLOUD_CHECK_EVERY_REQUEST` is enabled, FLUS asks the cloud on every request after successful checks. If the cloud fails, it uses a cooldown to avoid repeated timeouts.
- If the cloud cannot be reached, FLUS keeps using the last signed response during the grace window.
- A cloud `suspended` or `revoked` response limits the local installation even if the offline license has not expired.
- While the cached cloud status is `suspended`, `expired` or `revoked`, FLUS retries validation immediately on the next status evaluation so a reactivation in the panel can unlock the installation quickly.
- A cloud `active` response can reactivate a locally expired license when the signed license key matches.

## Expected API Response

The API returns a signed document:

```json
{
  "format": "FLUS-CLOUD-LICENSE-1",
  "alg": "RSA-SHA256",
  "payload_b64": "BASE64(JSON)",
  "sig_b64": "BASE64(SIGNATURE)"
}
```

The signed payload must include:

```json
{
  "license_key": "FLUS-XXXX-XXXX-XXXX",
  "installation_id": "ABCDEF0123456789ABCDEF0123456789",
  "status": "active",
  "plan": "Mensual",
  "expires_at": "2026-12-31",
  "checked_at": "2026-07-04T12:00:00+00:00",
  "next_check_at": "2026-07-04T18:00:00+00:00",
  "message": ""
}
```

Allowed status values are `active`, `suspended`, `expired` and `revoked`.

Do not send database errors, paths, stack traces, private keys or secrets in the response.
