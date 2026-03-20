<?php
/**
 * public/partials/license_widget.php
 * Widget "Licencia" reutilizable.
 */
declare(strict_types=1);

if (!function_exists('flus_license_widget')) {
  function flus_license_widget(): string {
    if (function_exists('flus_license_meta')) {
      $meta = flus_license_meta();
    } else {
      $meta = [
        'status' => 'N/D',
        'status_tone' => 'muted',
        'plan' => 'N/D',
        'valid_until' => 'N/D',
        'days_left' => 'N/D',
      ];
    }

    $badgeBg = match ((string)($meta['status_tone'] ?? 'muted')) {
      'success' => 'rgba(34, 197, 94, 0.18)',
      'warning' => 'rgba(245, 158, 11, 0.18)',
      'danger' => 'rgba(239, 68, 68, 0.18)',
      'info' => 'rgba(14, 165, 233, 0.18)',
      default => 'rgba(148, 163, 184, 0.18)',
    };

    $badgeColor = match ((string)($meta['status_tone'] ?? 'muted')) {
      'success' => '#22c55e',
      'warning' => '#f59e0b',
      'danger' => '#ef4444',
      'info' => '#0ea5e9',
      default => '#94a3b8',
    };

    $html  = '<div style="border:1px solid var(--panel-border, rgba(148,163,184,.25));border-radius:16px;padding:16px;';
    $html .= 'background:var(--panel, #111827);color:var(--text, #e5e7eb);box-shadow:var(--panel-shadow, 0 2px 8px rgba(0,0,0,.25));">';
    $html .= '<div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px;">';
    $html .= '<div>';
    $html .= '<div style="font-size:12px;font-weight:800;letter-spacing:.14em;text-transform:uppercase;color:var(--muted,#94a3b8);margin-bottom:4px;">Licencia</div>';
    $html .= '<h3 style="margin:0;font-size:18px;font-weight:800;color:var(--text-strong, var(--text, #e5e7eb));">Estado actual</h3>';
    $html .= '</div>';
    $html .= '<span style="display:inline-flex;align-items:center;justify-content:center;padding:4px 10px;border-radius:999px;';
    $html .= 'background:' . htmlspecialchars($badgeBg, ENT_QUOTES, 'UTF-8') . ';color:' . htmlspecialchars($badgeColor, ENT_QUOTES, 'UTF-8') . ';font-weight:800;font-size:12px;">';
    $html .= htmlspecialchars((string)($meta['status'] ?? 'N/D'), ENT_QUOTES, 'UTF-8');
    $html .= '</span>';
    $html .= '</div>';

    $html .= '<div style="display:grid;grid-template-columns:140px 1fr;gap:8px 12px;font-size:14px;">';
    $html .= '<div style="color:var(--muted,#94a3b8);">Plan</div><div><strong>' . htmlspecialchars((string)($meta['plan'] ?? 'N/D'), ENT_QUOTES, 'UTF-8') . '</strong></div>';
    $html .= '<div style="color:var(--muted,#94a3b8);">Vence</div><div>' . htmlspecialchars((string)($meta['valid_until'] ?? 'N/D'), ENT_QUOTES, 'UTF-8') . '</div>';
    $html .= '<div style="color:var(--muted,#94a3b8);">Días restantes</div><div>' . htmlspecialchars((string)($meta['days_left'] ?? 'N/D'), ENT_QUOTES, 'UTF-8') . '</div>';
    $html .= '</div>';

    if (!empty($meta['reason']) && !empty($meta['reason_label'])) {
      $html .= '<div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--panel-border, rgba(148,163,184,.18));font-size:13px;color:var(--muted,#94a3b8);">';
      $html .= '<strong style="color:var(--text-strong, var(--text, #e5e7eb));">Motivo:</strong> ';
      $html .= htmlspecialchars((string)$meta['reason_label'], ENT_QUOTES, 'UTF-8');
      $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
  }
}
