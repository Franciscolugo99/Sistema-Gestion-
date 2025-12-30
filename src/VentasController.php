<?php
// src/VentasController.php
declare(strict_types=1);

final class VentasController extends BaseController
{
  public function index(): void
  {
    // Seguridad (igual que antes)
    $this->requirePermission('ver_reportes');

    // Compatibilidad: tu código viejo espera $pdo y $user
    $pdo  = $this->pdo;
    $user = $this->user;

    // Cargar el “cuerpo” legacy (sin bootstrap/login/permisos)
    require __DIR__ . '/../public/ventas_body.php';
  }
}
