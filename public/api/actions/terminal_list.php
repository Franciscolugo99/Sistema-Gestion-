<?php
declare(strict_types=1);
// public/api/actions/terminal_list.php

$pdo = $pdo ?? getPDO();
$terminales = terminal_list($pdo);
$currentTid = terminal_current_id($pdo);
if ($currentTid > 0) {
  $_SESSION['terminal_id'] = $currentTid;
}

json_ok([
  'terminales' => $terminales,
  'current' => $currentTid,
  'terminals' => $terminales,
  'current_terminal_id' => $currentTid,
]);
