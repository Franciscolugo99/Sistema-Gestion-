<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/config.php';
require_once __DIR__ . '/lib/terminal.php';

header('Content-Type: text/plain; charset=utf-8');

echo "RAW COOKIE: " . ($_SERVER['HTTP_COOKIE'] ?? '(none)') . PHP_EOL;
echo "terminal_cookie_id(): " . terminal_cookie_id() . PHP_EOL;
echo "_COOKIE['terminal_id']: " . ($_COOKIE['terminal_id'] ?? '(none)') . PHP_EOL;
