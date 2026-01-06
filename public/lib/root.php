<?php
declare(strict_types=1);

/**
 * FLUS_ROOT: raíz del proyecto (carpeta que contiene /public y /src).
 * Este archivo se incluye desde public/* y public/api/* para que las rutas no dependan del "cwd".
 */

if (!defined('FLUS_ROOT')) {
  // __DIR__ = .../public/lib
  $project = realpath(__DIR__ . '/..');   // .../public
  $project = $project ? realpath($project . '/..') : null; // .../(root)

  if (!$project) {
    $project = dirname(__DIR__, 2);
  }

  define('FLUS_ROOT', $project);
}

return FLUS_ROOT;