<?php
// public/api/license_status.php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

// Auth (API)
if (function_exists('require_login_json')) {
  require_login_json();
} elseif (function_exists('require_login')) {
  require_login();
}

$lic = (defined('FLUS_LICENSE') && is_array(FLUS_LICENSE)) ? FLUS_LICENSE : null;

api_json([
  'ok' => true,
  'limited' => defined('FLUS_LIMITED') ? (bool)FLUS_LIMITED : null,
  'plan' => defined('FLUS_PLAN') ? (string)FLUS_PLAN : null,
  'license' => $lic,
], 200);
