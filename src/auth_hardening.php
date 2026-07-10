<?php
declare(strict_types=1);

function flus_enforce_initial_password_change(array $user): void {
    if (
        defined('FLUS_FORCE_PASSWORD_CHANGE_BYPASS') ||
        empty($_SESSION['force_password_change']) ||
        (int)($_SESSION['force_password_change_user_id'] ?? 0) !== (int)($user['id'] ?? 0)
    ) {
        return;
    }

    $uriPath = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    $base = strtolower(basename($uriPath));
    $isAsset = (bool)preg_match('~\.(css|js|png|jpg|jpeg|gif|svg|ico|woff2?|ttf|eot|map)$~i', $uriPath)
        || str_contains($uriPath, '/assets/');
    $allowed = $isAsset || in_array($base, ['usuario_editar.php', 'logout.php'], true);

    if ($allowed) {
        return;
    }

    $isApiContext = (
        defined('FLUS_API_CONTEXT') ||
        str_contains((string)($_SERVER['REQUEST_URI'] ?? ''), '/api/') ||
        (isset($_SERVER['HTTP_ACCEPT']) && str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json'))
    );

    if ($isApiContext) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        if (ob_get_length()) {
            @ob_clean();
        }
        http_response_code(403);
        echo json_encode([
            'ok' => false,
            'error' => 'PASSWORD_CHANGE_REQUIRED',
            'hint' => 'Cambia la clave inicial antes de operar FLUS.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: usuario_editar.php?id=' . (int)$user['id'] . '&force_password=1');
    exit;
}
