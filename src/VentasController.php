<?php
// src/VentasController.php
declare(strict_types=1);

final class VentasController extends BaseController
{
  public function index(): void
  {
    // Seguridad (igual que antes)
    $this->requirePermission('ver_reportes');

    // Compatibilidad: el legacy espera $pdo y $user
    $pdo  = $this->pdo;
    $user = $this->user;

    require __DIR__ . '/../public/ventas_body.php';
  }
}
