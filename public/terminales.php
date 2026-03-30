<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/terminal.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/caja_lib.php';

require_login();
require_permission('administrar_config');

$csrfToken = csrf_token();

$msg = null;
$err = null;
$sessionTerminalId = terminal_current_id($pdo);
$terminalColumns = function_exists('terminal__columns') ? terminal__columns($pdo, 'terminales') : [];
$hasCodigo = in_array('codigo', $terminalColumns, true);
$hasCreatedAt = in_array('created_at', $terminalColumns, true);

$formatDateTime = static function (?string $value): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($raw))->format('d/m/Y H:i');
    } catch (Throwable $e) {
        return $raw;
    }
};

$buildUserLabels = static function (array $userIds) use ($pdo): array {
    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if ($userIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "SELECT id, username, nombre FROM users WHERE id IN ({$placeholders})";
    $st = $pdo->prepare($sql);
    $st->execute($userIds);

    $labels = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $userId = (int)($row['id'] ?? 0);
        if ($userId <= 0) {
            continue;
        }

        $username = trim((string)($row['username'] ?? ''));
        $nombre = trim((string)($row['nombre'] ?? ''));

        if ($nombre !== '' && $username !== '' && strcasecmp($nombre, $username) !== 0) {
            $labels[$userId] = $nombre . ' · ' . $username;
        } else {
            $labels[$userId] = $nombre !== '' ? $nombre : ($username !== '' ? $username : ('Usuario #' . $userId));
        }
    }

    return $labels;
};

$loadTerminalRows = static function () use ($pdo, $hasCodigo, $hasCreatedAt): array {
    $select = [
        'id',
        'nombre',
        'activo',
        $hasCodigo ? 'codigo' : "NULL AS codigo",
        $hasCreatedAt ? 'created_at' : "NULL AS created_at",
    ];

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM terminales ORDER BY activo DESC, nombre ASC, id ASC';
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
};

$terminalPrintConfigKeys = static function (int $terminalId): array {
    return [
        'mode' => 'terminal_' . $terminalId . '_ticket_print_mode',
        'paper' => 'terminal_' . $terminalId . '_ticket_paper',
    ];
};

$loadTerminalPrintPrefs = static function (array $rows) use ($pdo, $terminalPrintConfigKeys): array {
    $prefs = [];
    foreach ($rows as $row) {
        $terminalId = (int)($row['id'] ?? 0);
        if ($terminalId <= 0) {
            continue;
        }
        $keys = $terminalPrintConfigKeys($terminalId);
        $mode = trim((string)config_get($pdo, $keys['mode'], 'inherit'));
        $paper = trim((string)config_get($pdo, $keys['paper'], 'inherit'));
        $prefs[$terminalId] = [
            'mode' => in_array($mode, ['inherit', 'autoprint', 'preview', 'none'], true) ? $mode : 'inherit',
            'paper' => in_array($paper, ['inherit', '80', '58'], true) ? $paper : 'inherit',
        ];
    }
    return $prefs;
};

$findDuplicateTerminal = static function (string $field, string $value, int $exceptId = 0) use ($pdo, $hasCodigo): ?array {
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if ($field === 'codigo' && !$hasCodigo) {
        return null;
    }

    if (!in_array($field, ['nombre', 'codigo'], true)) {
        throw new InvalidArgumentException('Campo de terminal no soportado.');
    }

    $sql = "SELECT id, nombre, " . ($hasCodigo ? 'codigo' : "NULL AS codigo") . "
            FROM terminales
            WHERE LOWER(TRIM({$field})) = LOWER(TRIM(:value))";
    if ($exceptId > 0) {
        $sql .= ' AND id <> :except_id';
    }
    $sql .= ' LIMIT 1';

    $st = $pdo->prepare($sql);
    $params = [':value' => $value];
    if ($exceptId > 0) {
        $params[':except_id'] = $exceptId;
    }
    $st->execute($params);

    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
};

$buildTerminalStates = static function (array $rows) use ($pdo, $sessionTerminalId, $buildUserLabels, $formatDateTime): array {
    $locks = [];
    $openCajas = [];
    $userIds = [];

    foreach ($rows as $row) {
        $terminalId = (int)($row['id'] ?? 0);
        if ($terminalId <= 0) {
            continue;
        }

        $locks[$terminalId] = terminal_lock_status($pdo, $terminalId);
        $openCajas[$terminalId] = caja_get_abierta($pdo, $terminalId);

        if (!empty($locks[$terminalId]['user_id'])) {
            $userIds[] = (int)$locks[$terminalId]['user_id'];
        }
        if (!empty($openCajas[$terminalId]['user_id'])) {
            $userIds[] = (int)$openCajas[$terminalId]['user_id'];
        }
    }

    $userLabels = $buildUserLabels($userIds);
    $states = [];

    foreach ($rows as $row) {
        $terminalId = (int)($row['id'] ?? 0);
        $isActive = (int)($row['activo'] ?? 0) === 1;
        $lockInfo = $locks[$terminalId] ?? null;
        $openCaja = $openCajas[$terminalId] ?? null;
        $badges = [];
        $detailLines = [];
        $blockers = [];
        $usageLabel = 'Disponible';
        $usageTone = 'success';

        if ($sessionTerminalId === $terminalId) {
            $badges[] = ['label' => 'Actual', 'tone' => 'info'];
            $blockers[] = 'Es la terminal actual de tu sesión.';
        }

        if (!$isActive) {
            $badges[] = ['label' => 'Inactiva', 'tone' => 'muted'];
            $usageLabel = 'Inactiva';
            $usageTone = 'muted';
            $detailLines[] = 'No aparece para seleccionar terminal.';
        } elseif (is_array($openCaja) && !empty($openCaja['id'])) {
            $openCajaUserId = (int)($openCaja['user_id'] ?? 0);
            $openCajaUser = $userLabels[$openCajaUserId] ?? trim((string)($openCaja['username'] ?? ''));
            $openDate = $formatDateTime((string)($openCaja['fecha_apertura'] ?? ''));

            $badges[] = ['label' => 'Caja abierta', 'tone' => 'warning'];
            $usageLabel = 'Caja abierta';
            $usageTone = 'warning';
            $detailLines[] = $openCajaUser !== '' ? 'Operando: ' . $openCajaUser : 'Hay una caja abierta en esta terminal.';
            if ($openDate !== '') {
                $detailLines[] = 'Apertura: ' . $openDate;
            }
            $blockers[] = 'Tiene una caja abierta.';
        } elseif (is_array($lockInfo)) {
            $lockUserId = (int)($lockInfo['user_id'] ?? 0);
            $lockUser = $userLabels[$lockUserId] ?? ('Usuario #' . max($lockUserId, 0));
            $lockUntil = $formatDateTime((string)($lockInfo['expires_at'] ?? ''));

            $badges[] = ['label' => 'En uso', 'tone' => 'warning'];
            $usageLabel = 'En uso';
            $usageTone = 'warning';
            $detailLines[] = 'Bloqueada por: ' . $lockUser;
            if ($lockUntil !== '') {
                $detailLines[] = 'Lock hasta: ' . $lockUntil;
            }
            $blockers[] = 'Tiene un lock activo.';
        } else {
            $badges[] = ['label' => 'Disponible', 'tone' => 'success'];
            $detailLines[] = 'Sin caja abierta ni lock activo.';
        }

        if ($isActive && empty($detailLines)) {
            $detailLines[] = 'Lista para operar.';
        }

        $states[$terminalId] = [
            'lock' => $lockInfo,
            'open_caja' => $openCaja,
            'badges' => $badges,
            'usage_label' => $usageLabel,
            'usage_tone' => $usageTone,
            'detail_lines' => array_values(array_filter($detailLines, static fn(string $line): bool => trim($line) !== '')),
            'can_toggle' => !$isActive || $blockers === [],
            'toggle_reason' => $blockers === [] ? '' : implode(' ', $blockers),
        ];
    }

    return $states;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');

    if (!csrf_verify($token)) {
        $err = 'CSRF inválido.';
    } else {
        $accion = (string)($_POST['accion'] ?? '');

        if ($accion === 'crear' || $accion === 'guardar') {
            $id = $accion === 'guardar' ? (int)($_POST['id'] ?? 0) : 0;
            $nombre = trim((string)($_POST['nombre'] ?? ''));
            $codigo = trim((string)($_POST['codigo'] ?? ''));
            $terminalActual = $id > 0 ? terminal_get($pdo, $id) : null;

            if ($accion === 'guardar' && !$terminalActual) {
                $err = 'La terminal que querés editar ya no existe.';
            } elseif ($nombre === '') {
                $err = 'El nombre de la terminal es obligatorio.';
            } elseif ($findDuplicateTerminal('nombre', $nombre, $id)) {
                $err = 'Ya existe otra terminal con ese nombre.';
            } elseif ($codigo !== '' && $findDuplicateTerminal('codigo', $codigo, $id)) {
                $err = 'Ya existe otra terminal con ese código.';
            } else {
                if ($accion === 'crear') {
                    $fields = ['nombre', 'activo'];
                    $values = [':nombre', '1'];
                    $params = [':nombre' => $nombre];

                    if ($hasCodigo) {
                        $fields[] = 'codigo';
                        $values[] = ':codigo';
                        $params[':codigo'] = $codigo !== '' ? $codigo : null;
                    }
                    if ($hasCreatedAt) {
                        $fields[] = 'created_at';
                        $values[] = 'NOW()';
                    }

                    $sql = 'INSERT INTO terminales (' . implode(', ', $fields) . ') VALUES (' . implode(', ', $values) . ')';
                    $st = $pdo->prepare($sql);
                    $st->execute($params);
                    $msg = 'Terminal creada.';
                } else {
                    $set = ['nombre = :nombre'];
                    $params = [
                        ':id' => $id,
                        ':nombre' => $nombre,
                    ];

                    if ($hasCodigo) {
                        $set[] = 'codigo = :codigo';
                        $params[':codigo'] = $codigo !== '' ? $codigo : null;
                    }

                    $sql = 'UPDATE terminales SET ' . implode(', ', $set) . ' WHERE id = :id';
                    $st = $pdo->prepare($sql);
                    $st->execute($params);

                    $keys = $terminalPrintConfigKeys($id);
                    $ticketPrintMode = trim((string)($_POST['ticket_print_mode'] ?? 'inherit'));
                    $ticketPaper = trim((string)($_POST['ticket_paper'] ?? 'inherit'));
                    config_set(
                        $pdo,
                        $keys['mode'],
                        in_array($ticketPrintMode, ['inherit', 'autoprint', 'preview', 'none'], true) ? $ticketPrintMode : 'inherit'
                    );
                    config_set(
                        $pdo,
                        $keys['paper'],
                        in_array($ticketPaper, ['inherit', '80', '58'], true) ? $ticketPaper : 'inherit'
                    );
                    $msg = 'Terminal actualizada.';
                }
            }
        } elseif ($accion === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);

            if ($id <= 0) {
                $err = 'Terminal inválida.';
            } else {
                $terminal = terminal_get($pdo, $id);

                if (!$terminal) {
                    $err = 'La terminal no existe.';
                } else {
                    $isActive = (int)($terminal['activo'] ?? 0) === 1;
                    $openCaja = caja_get_abierta($pdo, $id);
                    $lockInfo = terminal_lock_status($pdo, $id);

                    if ($isActive && $sessionTerminalId === $id) {
                        $err = 'No podés desactivar la terminal actual de tu sesión.';
                    } elseif ($isActive && is_array($openCaja) && !empty($openCaja['id'])) {
                        $err = 'No podés desactivar una terminal con caja abierta.';
                    } elseif ($isActive && is_array($lockInfo)) {
                        $err = 'No podés desactivar una terminal que está en uso.';
                    } else {
                        $pdo->prepare('UPDATE terminales SET activo = IF(activo = 1, 0, 1) WHERE id = ?')->execute([$id]);
                        $msg = $isActive ? 'Terminal desactivada.' : 'Terminal activada.';
                    }
                }
            }
        }
    }
}

$rows = $loadTerminalRows();
$terminalPrintPrefs = $loadTerminalPrintPrefs($rows);
$terminalStates = $buildTerminalStates($rows);

$totalTerminales = count($rows);
$terminalesActivas = 0;
$terminalesConCaja = 0;
$terminalesOcupadas = 0;

foreach ($rows as $row) {
    $terminalId = (int)($row['id'] ?? 0);
    $isActive = (int)($row['activo'] ?? 0) === 1;
    $state = $terminalStates[$terminalId] ?? null;

    if ($isActive) {
        $terminalesActivas++;
    }
    if (!empty($state['open_caja'])) {
        $terminalesConCaja++;
    }
    if ($isActive && (!empty($state['open_caja']) || !empty($state['lock']))) {
        $terminalesOcupadas++;
    }
}

$pageTitle = 'Terminales';
$currentSection = 'configuracion';
$bodyClass = trim(($bodyClass ?? '') . ' terminales-page');
$extraCss = array_merge($extraCss ?? [], ['assets/css/terminales.css']);
$extraJs = $extraJs ?? [];

require __DIR__ . '/partials/header.php';
?>

<div class="panel terminales-shell">
  <header class="page-header module-header terminales-header">
    <div class="page-header-main module-header-main">
      <div class="module-header-hero">
        <span class="module-header-icon" aria-hidden="true">
          <svg viewBox="0 0 24 24" fill="none" stroke-width="2">
            <rect x="3" y="4" width="18" height="14" rx="2"></rect>
            <path d="M8 20h8"></path>
            <path d="M12 18v2"></path>
          </svg>
        </span>
        <div class="module-header-copy">
          <span class="page-eyebrow module-eyebrow">Puntos de venta</span>
          <h1 class="page-title">Terminales / Cajas</h1>
          <p class="page-sub">Administrá terminales con guardas para evitar desactivar cajas activas o terminales bloqueadas.</p>
        </div>
      </div>
    </div>
  </header>

  <?php if ($msg): ?>
    <div class="alert alert-success terminales-alert"><?= h($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="alert alert-error terminales-alert"><?= h($err) ?></div>
  <?php endif; ?>

  <section class="terminales-stats" aria-label="Resumen de terminales">
    <article class="terminales-stat-card">
      <span class="terminales-stat-label">Total</span>
      <strong class="terminales-stat-value"><?= $totalTerminales ?></strong>
      <span class="terminales-stat-help">Terminales registradas</span>
    </article>
    <article class="terminales-stat-card terminales-stat-card--success">
      <span class="terminales-stat-label">Activas</span>
      <strong class="terminales-stat-value"><?= $terminalesActivas ?></strong>
      <span class="terminales-stat-help">Disponibles para operar</span>
    </article>
    <article class="terminales-stat-card terminales-stat-card--warning">
      <span class="terminales-stat-label">Con caja abierta</span>
      <strong class="terminales-stat-value"><?= $terminalesConCaja ?></strong>
      <span class="terminales-stat-help">Requieren cierre antes de desactivar</span>
    </article>
    <article class="terminales-stat-card terminales-stat-card--info">
      <span class="terminales-stat-label">Ocupadas</span>
      <strong class="terminales-stat-value"><?= $terminalesOcupadas ?></strong>
      <span class="terminales-stat-help">Con lock o caja activa</span>
    </article>
  </section>

  <section class="panel terminales-card">
    <div class="terminales-card-head">
      <div>
        <h2>Crear terminal</h2>
        <p>Alta rápida con validación de nombre y código para no generar duplicados.</p>
      </div>
    </div>

    <form method="post" class="terminales-create-form">
      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
      <input type="hidden" name="accion" value="crear">

      <label class="terminales-field">
        <span>Nombre</span>
        <input type="text" name="nombre" placeholder="Caja Mostrador" required>
      </label>

      <label class="terminales-field">
        <span>Código</span>
        <input type="text" name="codigo" placeholder="CAJA-01" <?= $hasCodigo ? '' : 'disabled' ?>>
      </label>

      <button class="btn btn-primary terminales-submit" type="submit">Crear terminal</button>
    </form>
  </section>

  <section class="panel terminales-card">
    <div class="terminales-card-head">
      <div>
        <h2>Listado operativo</h2>
        <p>Podés editar nombre y código, y ver rápido si una terminal está ocupada, actual o bloqueada.</p>
      </div>
    </div>

    <?php if ($rows === []): ?>
      <div class="terminales-empty">
        <strong>No hay terminales cargadas todavía.</strong>
        <span>Creá la primera desde el formulario superior para empezar a operar.</span>
      </div>
    <?php else: ?>
      <div class="terminales-table-wrap">
        <table class="table terminales-table">
          <thead>
            <tr>
              <th>ID</th>
              <th>Ticket</th>
              <th>Papel</th>
              <th>Terminal</th>
              <th>Código</th>
              <th>Estado</th>
              <th>Detalle de uso</th>
              <th class="right">Acciones</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php
                $terminalId = (int)($row['id'] ?? 0);
                $formId = 'terminal-edit-' . $terminalId;
                $state = $terminalStates[$terminalId] ?? [
                    'badges' => [],
                    'detail_lines' => ['Sin información de estado.'],
                    'usage_label' => 'Desconocido',
                    'usage_tone' => 'muted',
                    'can_toggle' => true,
                    'toggle_reason' => '',
                    'lock' => null,
                    'open_caja' => null,
                ];
                $isActive = (int)($row['activo'] ?? 0) === 1;
                $printPrefs = $terminalPrintPrefs[$terminalId] ?? ['mode' => 'inherit', 'paper' => 'inherit'];
              ?>
              <tr>
                <td class="terminales-id"><?= $terminalId ?></td>
                <td>
                  <label class="terminales-inline-field">
                    <span class="sr-only">Perfil de ticket terminal <?= $terminalId ?></span>
                    <select name="ticket_print_mode" form="<?= h($formId) ?>">
                      <option value="inherit" <?= $printPrefs['mode'] === 'inherit' ? 'selected' : '' ?>>Usar global</option>
                      <option value="autoprint" <?= $printPrefs['mode'] === 'autoprint' ? 'selected' : '' ?>>Auto imprimir</option>
                      <option value="preview" <?= $printPrefs['mode'] === 'preview' ? 'selected' : '' ?>>Vista previa</option>
                      <option value="none" <?= $printPrefs['mode'] === 'none' ? 'selected' : '' ?>>No abrir</option>
                    </select>
                  </label>
                </td>
                <td>
                  <label class="terminales-inline-field">
                    <span class="sr-only">Papel de ticket terminal <?= $terminalId ?></span>
                    <select name="ticket_paper" form="<?= h($formId) ?>">
                      <option value="inherit" <?= $printPrefs['paper'] === 'inherit' ? 'selected' : '' ?>>Usar global</option>
                      <option value="80" <?= $printPrefs['paper'] === '80' ? 'selected' : '' ?>>80 mm</option>
                      <option value="58" <?= $printPrefs['paper'] === '58' ? 'selected' : '' ?>>58 mm</option>
                    </select>
                  </label>
                </td>
                <td>
                  <label class="terminales-inline-field">
                    <span class="sr-only">Nombre de terminal <?= $terminalId ?></span>
                    <input type="text" name="nombre" form="<?= h($formId) ?>" value="<?= h((string)($row['nombre'] ?? '')) ?>" required>
                  </label>
                </td>
                <td>
                  <label class="terminales-inline-field">
                    <span class="sr-only">Código de terminal <?= $terminalId ?></span>
                    <input type="text" name="codigo" form="<?= h($formId) ?>" value="<?= h((string)($row['codigo'] ?? '')) ?>" <?= $hasCodigo ? '' : 'disabled' ?>>
                  </label>
                </td>
                <td>
                  <div class="terminales-badges">
                    <?php foreach ($state['badges'] as $badge): ?>
                      <span class="terminales-badge terminales-badge--<?= h((string)($badge['tone'] ?? 'muted')) ?>">
                        <?= h((string)($badge['label'] ?? 'Estado')) ?>
                      </span>
                    <?php endforeach; ?>
                  </div>
                  <span class="terminales-usage terminales-usage--<?= h((string)($state['usage_tone'] ?? 'muted')) ?>">
                    <?= h((string)($state['usage_label'] ?? 'Estado')) ?>
                  </span>
                </td>
                <td>
                  <div class="terminales-detail-lines">
                    <span>
                      Ticket: <?= h(match ($printPrefs['mode']) {
                        'autoprint' => 'Auto imprimir',
                        'preview' => 'Vista previa',
                        'none' => 'No abrir',
                        default => 'Usa perfil global',
                      }) ?>
                      - Papel: <?= h(match ($printPrefs['paper']) {
                        '80' => '80 mm',
                        '58' => '58 mm',
                        default => 'Global',
                      }) ?>
                    </span>
                    <?php foreach (($state['detail_lines'] ?? []) as $line): ?>
                      <span><?= h((string)$line) ?></span>
                    <?php endforeach; ?>
                    <?php if (!empty($row['created_at'])): ?>
                      <span>Creada: <?= h($formatDateTime((string)$row['created_at'])) ?></span>
                    <?php endif; ?>
                  </div>
                </td>
                <td class="right">
                  <div class="terminales-actions">
                    <form id="<?= h($formId) ?>" method="post">
                      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                      <input type="hidden" name="accion" value="guardar">
                      <input type="hidden" name="id" value="<?= $terminalId ?>">
                    </form>
                    <button class="btn btn-primary" type="submit" form="<?= h($formId) ?>">Guardar</button>
                    <form method="post">
                      <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                      <input type="hidden" name="accion" value="toggle">
                      <input type="hidden" name="id" value="<?= $terminalId ?>">
                      <button
                        class="btn <?= $isActive ? 'btn-secondary' : 'btn-primary' ?>"
                        type="submit"
                        <?= !empty($state['can_toggle']) ? '' : 'disabled' ?>
                        title="<?= h((string)($state['toggle_reason'] ?? '')) ?>"
                      >
                        <?= $isActive ? 'Desactivar' : 'Activar' ?>
                      </button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
