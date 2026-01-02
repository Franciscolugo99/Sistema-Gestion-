<?php
// DEPRECADO: usar public/api/index.php?action=terminal_heartbeat
$_GET['action'] = 'terminal_heartbeat';
require __DIR__ . '/index.php';
