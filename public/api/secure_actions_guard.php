<?php
/**
 * secure_actions_guard.php
 * Enforce: POST + CSRF para acciones no-lectura (send_ticket_*).
 */
declare(strict_types=1);
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

$action = $action ?? ($_GET['action'] ?? $_POST['action'] ?? '');
$action = is_string($action) ? trim($action) : '';

$requiresPost = ['send_ticket_email', 'send_ticket_whatsapp'];

if ($action !== '' && in_array($action, $requiresPost, true)) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        if (!function_exists('json_response')) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(405);
            echo json_encode(['success'=>false,'error'=>'METHOD_NOT_ALLOWED']);
            exit;
        }
        json_response(['success'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
    }

    $csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $valid = false;

    if (function_exists('csrf_verify')) {
        $valid = csrf_verify($csrf);
    } else {
        $sessToken = $_SESSION['csrf_token'] ?? '';
        $valid = is_string($csrf) && $csrf !== '' && $sessToken !== '' && hash_equals((string)$sessToken, (string)$csrf);
    }

    if (!$valid) {
        if (!function_exists('json_response')) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(403);
            echo json_encode(['success'=>false,'error'=>'CSRF_INVALID']);
            exit;
        }
        json_response(['success'=>false,'error'=>'CSRF_INVALID'], 403);
    }
}
