<?php
$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$dashboardBootData = [
  'from' => $from,
  'to' => $to,
  'categoriaFiltro' => $categoriaFiltro,
  'ventasLabels' => $ventasLabels,
  'ventasData' => $ventasData,
  'topProdLabels' => $topProductosLabels,
  'topProdData' => $topProductosData,
  'topProdRows' => $topProductosRows,
  'topProdSets' => $topProductosSets,
  'topProdMetric' => $topProductosMetric ?? 'unidades',
  'topProdLimit' => $topProductosLimit ?? 10,
  'metodosPago' => $metodosPago,
  'categorias' => $categorias,
  'ventasPorHora' => $ventasPorHora,
  'ventasPorDiaSemana' => $ventasPorDiaSemana,
  'productosRentables' => $productosRentables,
];
?>
</div>
</div>

<script id="dashboard-data" type="application/json"><?= json_encode($dashboardBootData, $jsonFlags) ?></script>
<script id="dashboard-kpi-tooltips" type="application/json"><?= json_encode($kpiTooltips, $jsonFlags) ?></script>

<script>
(function () {
  const dashboardJsVersion = <?= json_encode((string)(@filemtime(__DIR__ . '/../../assets/js/dashboard.js') ?: '6')) ?>;
  const dashboardHelpJsVersion = <?= json_encode((string)(@filemtime(__DIR__ . '/../../assets/js/dashboard-help.js') ?: '2')) ?>;
  function parseJsonScript(id, fallback) {
    const el = document.getElementById(id);
    if (!el) return fallback;
    try {
      return JSON.parse(el.textContent || "");
    } catch (err) {
      console.error("No se pudo parsear " + id, err);
      return fallback;
    }
  }

  window.dashboardData = parseJsonScript("dashboard-data", {});
  window.kpiTooltips = parseJsonScript("dashboard-kpi-tooltips", {});

  function load(src, onload, onerror) {
    const s = document.createElement("script");
    s.src = src;
    s.async = false;
    s.onload = onload || null;
    s.onerror = onerror || null;
    document.head.appendChild(s);
  }

  function loadDash() {
    load("assets/js/dashboard.js?v=" + dashboardJsVersion, function() {
      load("assets/js/dashboard-help.js?v=" + dashboardHelpJsVersion);
    });
  }

  load(
    "https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js",
    function () {
      if (window.Chart) loadDash();
      else load("assets/vendor/chartjs/chart.umd.min.js?v=4.4.1", loadDash);
    },
    function () {
      load("assets/vendor/chartjs/chart.umd.min.js?v=4.4.1", loadDash);
    }
  );
})();
</script>

<?php require __DIR__ . '/../footer.php'; ?>
