<?php
// public/api/license_status.php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

require_login_json();
require_perm_json('administrar_config');

$lic = (defined('FLUS_LICENSE') && is_array(FLUS_LICENSE)) ? FLUS_LICENSE : null;

api_json([
  'ok' => true,
  'limited' => defined('FLUS_LIMITED') ? (bool)FLUS_LIMITED : null,
  'plan' => defined('FLUS_PLAN') ? (string)FLUS_PLAN : null,
  'status' => is_array($lic) ? (string)($lic['status'] ?? '') : '',
  'days_left' => is_array($lic) ? ($lic['days_left'] ?? null) : null,
  'cloud_enabled' => is_array($lic) ? (bool)($lic['cloud_enabled'] ?? false) : false,
  'cloud_status' => is_array($lic) ? (string)($lic['cloud_status'] ?? '') : '',
  'cloud_last_success_at' => is_array($lic) ? (string)($lic['cloud_last_success_at'] ?? '') : '',
  'cloud_next_check_at' => is_array($lic) ? (string)($lic['cloud_next_check_at'] ?? '') : '',
], 200);
