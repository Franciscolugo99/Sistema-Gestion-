<?php
declare(strict_types=1);

function csrf_token(): string {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['csrf_token'];
}

function csrf_check(?string $token): bool {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $session = (string)($_SESSION['csrf_token'] ?? '');
  $token   = (string)($token ?? '');
  return $session !== '' && $token !== '' && hash_equals($session, $token);
}
