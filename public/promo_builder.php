<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_login();
require_permission('editar_promos');

$pageTitle = 'Nueva promocion - Plantillas';
$currentSection = 'promos';
$extraCss = [
  'assets/css/promos.css',
  'assets/css/promo_builder.css',
];

require __DIR__ . '/partials/header.php';
?>

<div class="page-wrap page-wrap-promos">
  <section class="promo-builder-shell">
    <header class="page-header with-back promo-builder-header">
      <div class="page-header-left">
        <a href="promos.php" class="link-back">&larr; Volver a promociones</a>
        <h1 class="page-title">Nueva promocion</h1>
        <p class="page-sub">Elegi una plantilla y configura una regla que caja detecta mientras cargas productos y que luego queda reflejada en la venta y en el ticket.</p>
      </div>

      <div class="page-header-right">
        <span class="badge badge-pill badge-outline">Plantillas</span>
      </div>
    </header>

    <section class="promo-builder-section promo-builder-section--compact promo-builder-note">
      <div class="promo-builder-note-card">
        <span class="promo-builder-kicker">Aplicacion automatica en caja</span>
        <p class="promo-builder-note-copy">La promocion se detecta al cargar productos y se registra con la venta.</p>
      </div>
    </section>

    <section class="promo-builder-section">
      <div class="promo-builder-section-head">
        <span class="promo-builder-kicker">Plantillas</span>
        <h2 class="promo-builder-section-title">Elegi el tipo de promocion</h2>
        
      </div>

      <div class="promo-template-grid">
        <article class="promo-template-card">
          <span class="badge badge-nxm">NxM</span>
          <h2>Mismo producto, varias unidades</h2>
          <p>Para promociones por cantidad sobre un mismo producto.</p>
          <ul class="promo-template-list">
            <li>Ideal para 2x1, 3x2 y 4x3.</li>
            <li>Ejemplo: Gaseosa 3x2.</li>
          </ul>
          <a href="promo_form.php?tipo=N_PAGA_M" class="v-btn v-btn--primary">Usar plantilla NxM</a>
        </article>

        <article class="promo-template-card">
          <span class="badge badge-nth">% Unidad</span>
          <h2>Descuento en una unidad puntual</h2>
          <p>Para aplicar descuento en una unidad especifica dentro de la compra.</p>
          <ul class="promo-template-list">
            <li>Ideal para 50% en la 2da o 30% en la 3ra.</li>
            <li>Ejemplo: 50% en la 2da unidad.</li>
          </ul>
          <a href="promo_form.php?tipo=NTH_PCT" class="v-btn v-btn--primary">Usar plantilla % unidad</a>
        </article>

        <article class="promo-template-card">
          <span class="badge badge-combo">Combo</span>
          <h2>Varios productos a precio fijo</h2>
          <p>Para vender varios productos juntos con un precio final cerrado.</p>
          <ul class="promo-template-list">
            <li>Ideal para bebida + snack o combinaciones similares.</li>
            <li>Ejemplo: Coca + alfajor a precio cerrado.</li>
          </ul>
          <a href="promo_combo_form.php" class="v-btn v-btn--primary">Usar plantilla combo</a>
        </article>
      </div>
    </section>

    <section class="promo-builder-section promo-builder-section--compact">
      <div class="promo-builder-help promo-builder-help--single">
        <div class="promo-builder-help-card">
          <h2>Que usar en cada caso</h2>
          <p>Usa <span class="badge badge-nxm promo-inline-badge">NxM</span> para promociones sobre varias unidades del mismo producto, <span class="badge badge-nth promo-inline-badge">% Unidad</span> para aplicar descuento en una unidad puntual, y <span class="badge badge-combo promo-inline-badge">Combo</span> cuando la promocion depende de productos distintos en conjunto.</p>
        </div>
      </div>
    </section>
  </section>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>




