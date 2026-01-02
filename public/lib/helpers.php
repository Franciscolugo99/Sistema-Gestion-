<?php
// public/lib/helpers.php
declare(strict_types=1);

/**
 * Wrapper de compatibilidad:
 * - Las utilidades viven en src/helpers.php (helpers centralizados).
 * - Este archivo queda para no romper require_once existentes (bootstrap.php).
 */
require_once __DIR__ . '/../../src/helpers.php';
