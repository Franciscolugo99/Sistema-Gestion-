# tools/apply-caja-quickwins.ps1
# Aplica quickwins reales para Caja: buscar_productos + sugerencias + STORAGE_KEY estable.
# Requiere: git. Recomendado: correr desde el repo (o cualquier subcarpeta del repo).

$ErrorActionPreference = "Stop"

function Fail($msg) { throw "[FLUS] $msg" }

function ReadFileUtf8([string]$path) {
  if (!(Test-Path $path)) { Fail "No existe el archivo: $path" }
  return Get-Content -Raw -Encoding UTF8 $path
}

function WriteFileUtf8([string]$path, [string]$content) {
  $dir = Split-Path -Parent $path
  if ($dir -and !(Test-Path $dir)) { New-Item -ItemType Directory -Force $dir | Out-Null }
  Set-Content -Encoding UTF8 -NoNewline -Path $path -Value $content
}

function InsertAfterFirstRegex([string]$text, [string]$pattern, [string]$insert) {
  $m = [regex]::Match($text, $pattern, [System.Text.RegularExpressions.RegexOptions]::Singleline)
  if (!$m.Success) { return $null }
  $idx = $m.Index + $m.Length
  return $text.Substring(0, $idx) + $insert + $text.Substring($idx)
}

# --- ubicarse en la raíz del repo ---
$repoRoot = (git rev-parse --show-toplevel 2>$null)
if (!$repoRoot) { Fail "No estoy dentro de un repo git (git rev-parse falló)." }
Set-Location $repoRoot.Trim()

Write-Host "[FLUS] Repo:" (Get-Location)

# --- crear/cambiar a rama (PowerShell no debe romper por stderr de git) ---
$branch = "fix/caja-quickwins"
$curr = (git branch --show-current).Trim()

if ($curr -ne $branch) {
  & git checkout -b $branch *> $null
  if ($LASTEXITCODE -ne 0) {
    & git checkout $branch *> $null
    if ($LASTEXITCODE -ne 0) { Fail "No pude cambiar a la rama $branch" }
  }
}

Write-Host "[FLUS] Rama actual:" (git branch --show-current)


# --- 1) Crear action buscar_productos ---
$actionsDir = Join-Path $repoRoot "public\api\actions"
New-Item -ItemType Directory -Force $actionsDir | Out-Null

$buscarFile = Join-Path $actionsDir "buscar_productos.php"

$buscarContent = @'
<?php
// public/api/actions/buscar_productos.php
// Endpoint: ?action=buscar_productos&q=...&limit=5
// Diseñado para ser robusto con distintos nombres de columnas (nombre/descripcion, precio/precio_venta, stock/stock_actual).

if (function_exists('require_login')) {
  // Si tu API ya valida sesión arriba, esto no molesta.
  require_login();
}

$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') {
  if (function_exists('json_fail')) json_fail('Query vacía', 422);
  http_response_code(422);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok'=>false,'error'=>'Query vacía'], JSON_UNESCAPED_UNICODE);
  exit;
}

$limit = (int)($_GET['limit'] ?? 5);
$limit = max(1, min($limit, 20));

// Cache estático de columnas (evita SHOW COLUMNS cada request)
static $cols = null;
if ($cols === null) {
  try {
    $cols = $pdo->query("SHOW COLUMNS FROM productos")->fetchAll(PDO::FETCH_COLUMN);
  } catch (Throwable $e) {
    $cols = [];
  }
}

$has = fn(string $c): bool => in_array($c, $cols, true);

// Elegir columnas compatibles
$nameCol  = $has('nombre') ? 'nombre' : ($has('descripcion') ? 'descripcion' : 'nombre');
$codeCol  = $has('codigo') ? 'codigo' : 'codigo';

$priceCol = $has('precio') ? 'precio'
         : ($has('precio_venta') ? 'precio_venta'
         : ($has('precio_unitario') ? 'precio_unitario' : null));

$stockCol = $has('stock') ? 'stock'
        : ($has('stock_actual') ? 'stock_actual'
        : ($has('existencia') ? 'existencia' : null));

$activeCol = $has('activo') ? 'activo'
          : ($has('active') ? 'active' : null);

// Armado de SELECT
$select = "id, {$codeCol} AS codigo, {$nameCol} AS nombre";
if ($priceCol) $select .= ", {$priceCol} AS precio";
if ($stockCol) $select .= ", {$stockCol} AS stock";

// WHERE (activo si existe)
$where = [];
$params = [':q' => '%' . $q . '%'];

$where[] = "{$codeCol} LIKE :q";
$where[] = "{$nameCol} LIKE :q";

if ($has('categoria')) $where[] = "categoria LIKE :q";

$w = '(' . implode(' OR ', $where) . ')';
if ($activeCol) {
  $w = "({$activeCol} = 1) AND " . $w;
}

$sql = "
  SELECT {$select}
  FROM productos
  WHERE {$w}
  ORDER BY nombre
  LIMIT {$limit}
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (function_exists('json_ok')) {
  json_ok(['productos' => $rows]);
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>true,'productos'=>$rows], JSON_UNESCAPED_UNICODE);
exit;
'@

WriteFileUtf8 $buscarFile $buscarContent
Write-Host "[FLUS] OK creado:" $buscarFile

# --- 2) Parchar public/api/index.php para despachar actions por archivo ---
$apiIndex = Join-Path $repoRoot "public\api\index.php"
$apiText = ReadFileUtf8 $apiIndex

if ($apiText -notmatch 'FLUS:\s*action file dispatch') {
  $dispatch = @'

/* ================================
   FLUS: action file dispatch
   Permite agregar endpoints en public/api/actions/{action}.php
   sin ensuciar el switch principal.
================================ */
$__actionFile = __DIR__ . '/actions/' . $action . '.php';
if (is_file($__actionFile)) {
  require $__actionFile;
  exit;
}

'@

  # Insertar después de la primera asignación a $action (robusto)
  $patched = InsertAfterFirstRegex $apiText '(\$action\s*=\s*[^;]+;)' $dispatch
  if ($null -eq $patched) {
    Fail "No encontré la asignación de `$action en public/api/index.php (busqué: `$action = ...;)."
  }

  WriteFileUtf8 $apiIndex $patched
  Write-Host "[FLUS] OK patch index.php: dispatch por actions/"
} else {
  Write-Host "[FLUS] index.php ya tenía dispatch (skip)."
}

# --- 3) Parchar public/assets/js/caja.js: STORAGE_KEY estable + sugerencias ---
$cajaJs = Join-Path $repoRoot "public\assets\js\caja.js"
$js = ReadFileUtf8 $cajaJs

# 3A) STORAGE_KEY estable: reemplaza 1ra ocurrencia si existe, si no inserta después de API_TIMEOUT_MS
$storageBlock = @'
  // FLUS: Storage key estable por terminal + sesión (evita colisiones y CAJA_ID=0)
  const FLUS_TERMINAL_ID =
    (window.TERMINAL_ID ?? window.terminalId ?? document.body?.dataset?.terminalId ?? 0);

  const __flusSidKey = "kiosco-caja-session-id";
  let __flusSid = sessionStorage.getItem(__flusSidKey);
  if (!__flusSid) {
    __flusSid = (crypto?.randomUUID?.() || (Date.now() + "-" + Math.random().toString(16).slice(2)));
    sessionStorage.setItem(__flusSidKey, __flusSid);
  }

  const STORAGE_KEY = `kiosco-caja-v2:${FLUS_TERMINAL_ID}:${__flusSid}`;
'@

$storagePattern = 'const\s+STORAGE_KEY\s*=\s*[^;]+;'
if ([regex]::IsMatch($js, $storagePattern)) {
  $js = [regex]::Replace($js, $storagePattern, $storageBlock, 1)
  Write-Host "[FLUS] OK caja.js: STORAGE_KEY reemplazado."
} elseif ($js -notmatch 'FLUS:\s*Storage key estable') {
  # insertar después de const API_TIMEOUT_MS = ...;
  $js2 = InsertAfterFirstRegex $js '(const\s+API_TIMEOUT_MS\s*=\s*[^;]+;)' "`r`n`r`n$storageBlock"
  if ($null -eq $js2) {
    # fallback: después de const API_BASE
    $js2 = InsertAfterFirstRegex $js '(const\s+API_BASE\s*=\s*[^;]+;)' "`r`n`r`n$storageBlock"
  }
  if ($null -eq $js2) { Fail "No pude insertar STORAGE_KEY: no encontré API_TIMEOUT_MS ni API_BASE." }
  $js = $js2
  Write-Host "[FLUS] OK caja.js: STORAGE_KEY insertado."
} else {
  Write-Host "[FLUS] caja.js ya tenía Storage key estable (skip)."
}

# 3B) Sugerencias: insertar después de API_TIMEOUT_MS (así API_BASE existe)
if ($js -notmatch 'FLUS:\s*Sugerencias\s*\(buscar_productos\)') {
  $suggestBlock = @'
  
  // FLUS: Sugerencias (buscar_productos) - no requiere tocar HTML
  (function initSugerenciasProductos() {
    const input = document.getElementById("codigo");
    if (!input) return;

    // datalist auto-creado
    let dl = document.getElementById("sugerencias");
    if (!dl) {
      dl = document.createElement("datalist");
      dl.id = "sugerencias";
      document.body.appendChild(dl);
    }
    input.setAttribute("list", "sugerencias");

    // escape para inyectar options seguro
    const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({
      "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;"
    }[c]));

    // fallback fetchJson (si tu caja.js ya tiene fetchJson, no lo pisa)
    if (!window.fetchJson) {
      window.fetchJson = async (url, opts = {}) => {
        const r = await fetch(url, opts);
        const ct = r.headers.get("content-type") || "";
        const data = ct.includes("application/json") ? await r.json() : { ok: false, error: "NON_JSON" };
        if (!r.ok || data?.ok === false) throw data;
        return data;
      };
    }

    let abort = null;

    const doSuggest = (query) => {
      query = (query || "").trim();
      if (query.length < 2) { dl.innerHTML = ""; return; }

      if (abort) abort.abort();
      abort = new AbortController();

      window.fetchJson(
        `${API_BASE}?action=buscar_productos&q=${encodeURIComponent(query)}&limit=5`,
        { signal: abort.signal }
      ).then((data) => {
        const productos = data?.productos || data?.data?.productos || [];
        dl.innerHTML = productos.map(p =>
          `<option value="${esc(p.codigo)}" label="${esc(p.nombre)}"></option>`
        ).join("");
      }).catch((err) => {
        if (err?.name === "AbortError") return;
        dl.innerHTML = "";
      });
    };

    // Debounce (usa tu debounce global si existe)
    const debounced = (window.debounce)
      ? window.debounce(doSuggest, 120)
      : (() => { let t; return (v) => { clearTimeout(t); t = setTimeout(() => doSuggest(v), 120); }; })();

    input.addEventListener("input", () => debounced(input.value));
  })();
'@

  $js3 = InsertAfterFirstRegex $js '(const\s+API_TIMEOUT_MS\s*=\s*[^;]+;)' $suggestBlock
  if ($null -eq $js3) {
    $js3 = InsertAfterFirstRegex $js '(const\s+API_BASE\s*=\s*[^;]+;)' $suggestBlock
  }
  if ($null -eq $js3) { Fail "No pude insertar sugerencias: no encontré API_TIMEOUT_MS ni API_BASE en caja.js." }
  $js = $js3
  Write-Host "[FLUS] OK caja.js: sugerencias insertadas."
} else {
  Write-Host "[FLUS] caja.js ya tenía sugerencias (skip)."
}

WriteFileUtf8 $cajaJs $js

# --- resumen ---
Write-Host ""
Write-Host "[FLUS] Listo. Revisá el diff:"
git diff --stat

Write-Host ""
Write-Host "[FLUS] Próximo paso recomendado:"
Write-Host "  1) Probá caja -> escribir en #codigo (2+ letras) y ver sugerencias"
Write-Host "  2) Si todo OK: git add -A && git commit -m ""caja: sugerencias + buscar_productos + storage estable"""
