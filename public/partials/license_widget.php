<?php
/**
 * public/partials/license_widget.php
 * Widget "Licencia" para Acerca de FLUS.
 */
declare(strict_types=1);

if (!function_exists('flus_license_widget')) {
  function flus_license_widget(): string {

    // Preferimos el estado calculado por bootstrap (enforcement)
    $lic = (defined('FLUS_LICENSE') && is_array(FLUS_LICENSE)) ? FLUS_LICENSE : null;

    // Fallback (por si alguna pantalla no cargó bootstrap)
    if (!$lic) {
      $root = dirname(__DIR__); // public/
      $licPathCandidates = [
        $root . '/storage/license.json',
        dirname($root) . '/storage/license.json',
      ];
      foreach ($licPathCandidates as $p) {
        if (is_file($p)) {
          $json = json_decode((string)file_get_contents($p), true);
          if (is_array($json)) { $lic = $json; break; }
        }
      }

      // Si es el JSON crudo, calculamos estado simple (compat)
      $plan   = (string)($lic['plan'] ?? 'N/D');
      $expStr = $lic['expires_at'] ?? ($lic['valid_until'] ?? null);
      $estado = 'N/D';
      $dias   = 'N/D';

      if ($expStr) {
        try {
          $hoy   = new DateTime('today');
          $vence = new DateTime((string)$expStr);
          $diff  = (int)$hoy->diff($vence)->format('%r%a');
          $dias  = (string)$diff;
          if ($diff < 0)      { $estado = 'vencida'; }
          elseif ($diff <= 7) { $estado = 'por vencer'; }
          else                { $estado = 'activa'; }
          $expStr = $vence->format('Y-m-d');
        } catch (Throwable $e) {
          $expStr = (string)$expStr;
        }
      } else {
        $expStr = 'N/D';
      }

      $lic = [
        'plan_label'   => $plan,
        'status_label' => $estado,
        'valid_until'  => $expStr,
        'days_left'    => $dias,
      ];
    }

    $plan   = (string)($lic['plan_label'] ?? $lic['plan'] ?? 'N/D');
    $estado = (string)($lic['status_label'] ?? $lic['status'] ?? 'N/D');
    $vence  = (string)($lic['valid_until'] ?? $lic['expires_at'] ?? 'N/D');
    $dias   = $lic['days_left'] ?? 'N/D';
    $dias   = ($dias === null) ? 'N/D' : (string)$dias;

    // --- HTML ---
    $card  = '<div style="border:1px solid var(--border-color,#333);border-radius:12px;padding:14px;margin:10px 0;';
    $card .= 'background:var(--bg2,#111);color:var(--fg,#ddd);box-shadow:0 2px 8px rgba(0,0,0,.25);">';

    $badgeColor = ($estado === 'activa') ? '#22c55e' : (($estado === 'por vencer') ? '#f59e0b' : '#ef4444');
    $badge  = '<span style="display:inline-block;padding:2px 8px;border-radius:999px;background:';
    $badge .= $badgeColor;
    $badge .= ';color:#fff;font-weight:600;font-size:12px;vertical-align:middle;">';
    $badge .= htmlspecialchars((string)$estado, ENT_QUOTES, 'UTF-8');
    $badge .= '</span>';

    $html  = $card;
    $html .= '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">';
    $html .= '<h3 style="margin:0;font-size:18px;font-weight:700;">Licencia</h3>';
    $html .= $badge;
    $html .= '</div>';
    $html .= '<div style="display:grid;grid-template-columns:160px 1fr;row-gap:6px;column-gap:10px;font-size:14px;">';
    $html .= '<div style="opacity:.8;">Plan</div><div><strong>' . htmlspecialchars($plan, ENT_QUOTES, 'UTF-8') . '</strong></div>';
    $html .= '<div style="opacity:.8;">Vence</div><div>' . htmlspecialchars($vence, ENT_QUOTES, 'UTF-8') . '</div>';
    $html .= '<div style="opacity:.8;">Días restantes</div><div>' . htmlspecialchars($dias, ENT_QUOTES, 'UTF-8') . '</div>';
    $html .= '</div>';
    $html .= '</div>';

    return $html;
  }
}
