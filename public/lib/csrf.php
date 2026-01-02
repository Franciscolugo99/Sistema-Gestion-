<?php
// public/lib/csrf.php
declare(strict_types=1);

/**
 * CSRF centralizado:
 * - La implementación vive en src/helpers.php (csrf_init/csrf_token/csrf_verify).
 * - Este archivo queda para compatibilidad con endpoints /api que lo requieren.
 */
require_once __DIR__ . '/../../src/helpers.php';
