<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'ts'=>date('c')], JSON_UNESCAPED_UNICODE);
