<?php
// public/includes/ClienteController.php
declare(strict_types=1);

require_once __DIR__ . '/CuitValidator.php';

/**
 * ClienteController - Compatible con diferentes esquemas de BD
 * Detecta automáticamente qué columnas existen y se adapta.
 */
class ClienteController
{
    private PDO $pdo;
    private ?array $availableColumns = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public static function getCondIvaOptions(): array
    {
        return [
            ''   => 'Sin especificar',
            'CF' => 'Consumidor final',
            'RI' => 'Responsable inscripto',
            'MT' => 'Monotributo',
            'EX' => 'Exento',
        ];
    }
    
    public static function getTipoClienteOptions(): array
    {
        return [
            'MINORISTA'   => 'Minorista',
            'MAYORISTA'   => 'Mayorista',
            'CORPORATIVO' => 'Corporativo',
        ];
    }
    
    /**
     * Obtiene las columnas disponibles en la tabla clientes (cached)
     */
    private function getAvailableColumns(): array
    {
        if ($this->availableColumns === null) {
            try {
                $stmt = $this->pdo->query("SHOW COLUMNS FROM clientes");
                $cols = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
                $this->availableColumns = array_flip($cols);
            } catch (Throwable $e) {
                $this->availableColumns = [];
            }
        }
        return $this->availableColumns;
    }
    
    /**
     * Verifica si una columna existe en la tabla clientes
     */
    public function hasColumn(string $column): bool
    {
        $cols = $this->getAvailableColumns();
        return isset($cols[$column]);
    }
    
    /**
     * Verifica si la tabla tiene soporte para cuenta corriente
     */
    public function hasColumnCC(): bool
    {
        return $this->hasColumn('cc_habilitado');
    }
    
    public function getZonasReparto(): array
    {
        try {
            // Verificar si existe la tabla
            $check = $this->pdo->query("SHOW TABLES LIKE 'zonas_reparto'");
            if (!$check->fetch()) {
                return [];
            }
            
            $stmt = $this->pdo->query("
                SELECT codigo, nombre, costo_envio, tiempo_estimado_min
                FROM zonas_reparto
                WHERE activo = 1
                ORDER BY orden ASC, nombre ASC
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    public function toggleActivo(array $post): bool
    {
        $id    = (int)($post['id'] ?? 0);
        $valor = (int)($post['valor'] ?? 0);

        if ($id <= 0) {
            return false;
        }
        
        $userId = function_exists('session_user_id') ? session_user_id() : (int)($_SESSION['usuario_id'] ?? ($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0)));
        
        // Verificar si existe updated_by
        if ($this->hasColumn('updated_by')) {
            $st = $this->pdo->prepare("
                UPDATE clientes 
                SET activo = :v, updated_by = :uid
                WHERE id = :id
            ");
            return $st->execute([
                ':v' => ($valor ? 1 : 0), 
                ':id' => $id,
                ':uid' => $userId > 0 ? $userId : null
            ]);
        } else {
            $st = $this->pdo->prepare("
                UPDATE clientes 
                SET activo = :v
                WHERE id = :id
            ");
            return $st->execute([
                ':v' => ($valor ? 1 : 0), 
                ':id' => $id
            ]);
        }
    }

    public function validateForm(array $post): array
    {
        $errores = [];
        
        $nombre = trim((string)($post['nombre'] ?? ''));
        $cuit   = trim((string)($post['cuit'] ?? ''));
        $email  = trim((string)($post['email'] ?? ''));
        $condIva = trim((string)($post['cond_iva'] ?? ''));
        
        // Nombre obligatorio
        if ($nombre === '') {
            $errores[] = 'El nombre es obligatorio.';
        } elseif (strlen($nombre) > 200) {
            $errores[] = 'El nombre es demasiado largo (máx. 200 caracteres).';
        }
        
        // Validar CUIT si viene y existe CuitValidator
        if ($cuit !== '' && class_exists('CuitValidator')) {
            if (!CuitValidator::validar($cuit)) {
                $errorDetallado = CuitValidator::obtenerError($cuit);
                $errores[] = $errorDetallado ?: 'El CUIT/CUIL no es válido.';
            } else {
                $id = isset($post['id']) && $post['id'] !== '' ? (int)$post['id'] : null;
                if ($this->existeCuitDuplicado($cuit, $id)) {
                    $errores[] = 'Ya existe un cliente activo con ese CUIT/CUIL.';
                }
            }
        }
        
        // RI DEBE tener CUIT
        if ($condIva === 'RI' && $cuit === '') {
            $errores[] = 'Los Responsables Inscriptos deben tener un CUIT/CUIL válido.';
        }
        
        // Email
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El email no tiene un formato válido.';
        }

        // Condición IVA
        if ($condIva !== '' && !array_key_exists($condIva, self::getCondIvaOptions())) {
            $errores[] = 'Condición IVA inválida.';
        }
        
        // Validar descuento si existe la columna
        if ($this->hasColumn('descuento_porcentaje')) {
            $descuentoPct = (float)($post['descuento_porcentaje'] ?? 0);
            if ($descuentoPct < 0 || $descuentoPct > 100) {
                $errores[] = 'El descuento debe estar entre 0% y 100%.';
            }
        }
        
        // Validar cuenta corriente si existe
        if ($this->hasColumnCC()) {
            $ccHabilitado = isset($post['cc_habilitado']);
            $ccLimite = (float)($post['cc_limite'] ?? 0);
            
            if ($ccHabilitado && $ccLimite < 0) {
                $errores[] = 'El límite de crédito no puede ser negativo.';
            }
        }

        return $errores;
    }
    
    private function existeCuitDuplicado(string $cuit, ?int $excludeId = null): bool
    {
        if (!$this->hasColumn('cuit')) {
            return false;
        }
        
        $cuitLimpio = class_exists('CuitValidator') ? CuitValidator::limpiar($cuit) : preg_replace('/[^0-9]/', '', $cuit);
        
        $sql = "
            SELECT COUNT(*) 
            FROM clientes 
            WHERE REPLACE(REPLACE(cuit, '-', ''), ' ', '') = :cuit
            AND activo = 1
        ";
        
        $params = [':cuit' => $cuitLimpio];
        
        if ($excludeId) {
            $sql .= " AND id != :id";
            $params[':id'] = $excludeId;
        }
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return (int)$stmt->fetchColumn() > 0;
    }
    
    /**
     * Construye dinámicamente los campos para INSERT/UPDATE según columnas disponibles
     */
    private function buildFieldsAndValues(array $post, bool $isUpdate = false): array
    {
        $fields = [];
        $values = [];
        $placeholders = [];
        
        // Campo obligatorio
        $fields[] = 'nombre';
        $values[] = trim($post['nombre']);
        $placeholders[] = '?';
        
        // Campos opcionales - solo si existen en la tabla
        $optionalFields = [
            'cuit' => function($post) {
                $cuit = trim((string)($post['cuit'] ?? ''));
                if ($cuit !== '' && class_exists('CuitValidator')) {
                    $cuit = CuitValidator::formatear($cuit) ?? $cuit;
                }
                return $this->nullIfEmpty($cuit);
            },
            'cond_iva' => fn($post) => $this->nullIfEmpty($post['cond_iva'] ?? ''),
            'tipo_cliente' => fn($post) => $this->nullIfEmpty($post['tipo_cliente'] ?? 'MINORISTA'),
            'descuento_porcentaje' => fn($post) => (float)($post['descuento_porcentaje'] ?? 0),
            'direccion' => fn($post) => $this->nullIfEmpty($post['direccion'] ?? ''),
            'zona_reparto' => fn($post) => $this->nullIfEmpty($post['zona_reparto'] ?? ''),
            'email' => fn($post) => $this->nullIfEmpty($post['email'] ?? ''),
            'telefono' => fn($post) => $this->nullIfEmpty($post['telefono'] ?? ''),
            'notas' => fn($post) => $this->nullIfEmpty($post['notas'] ?? ''),
            'activo' => fn($post) => isset($post['activo']) ? 1 : 0,
            'cc_habilitado' => fn($post) => isset($post['cc_habilitado']) ? 1 : 0,
            'cc_limite' => fn($post) => (float)($post['cc_limite'] ?? 0),
        ];
        
        foreach ($optionalFields as $field => $getValue) {
            if ($this->hasColumn($field)) {
                $fields[] = $field;
                $values[] = $getValue($post);
                $placeholders[] = '?';
            }
        }
        
        // Campo de auditoría
        $userId = function_exists('session_user_id') ? session_user_id() : (int)($_SESSION['usuario_id'] ?? ($_SESSION['user']['id'] ?? ($_SESSION['user_id'] ?? 0)));
        $userIdValue = $userId > 0 ? $userId : null;
        
        if ($isUpdate && $this->hasColumn('updated_by')) {
            $fields[] = 'updated_by';
            $values[] = $userIdValue;
            $placeholders[] = '?';
        } elseif (!$isUpdate && $this->hasColumn('created_by')) {
            $fields[] = 'created_by';
            $values[] = $userIdValue;
            $placeholders[] = '?';
        }
        
        return [
            'fields' => $fields,
            'values' => $values,
            'placeholders' => $placeholders,
        ];
    }

    public function create(array $post): bool
    {
        $data = $this->buildFieldsAndValues($post, false);
        
        $fieldList = implode(', ', $data['fields']);
        $placeholderList = implode(', ', $data['placeholders']);
        
        $sql = "INSERT INTO clientes ({$fieldList}) VALUES ({$placeholderList})";
        
        $st = $this->pdo->prepare($sql);
        return $st->execute($data['values']);
    }

    public function update(int $id, array $post): bool
    {
        $data = $this->buildFieldsAndValues($post, true);
        
        $setClause = implode(' = ?, ', $data['fields']) . ' = ?';
        
        $sql = "UPDATE clientes SET {$setClause} WHERE id = ?";
        
        $values = $data['values'];
        $values[] = $id;
        
        $st = $this->pdo->prepare($sql);
        return $st->execute($values);
    }

    public function getById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM clientes WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $result = $st->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    public function getList(array $filters): array
    {
        $q       = trim($filters['q'] ?? '');
        $estado  = $filters['estado'] ?? '';
        $tipo    = $filters['tipo'] ?? '';
        $estadoCC = $filters['estado_cc'] ?? '';
        $perPage = (int)($filters['per_page'] ?? 50);
        $page    = max(1, (int)($filters['page'] ?? 1));

        $where  = ['1=1'];
        $params = [];
        
        $hasCC = $this->hasColumnCC();
        $hasCuit = $this->hasColumn('cuit');
        $hasEmail = $this->hasColumn('email');
        $hasTelefono = $this->hasColumn('telefono');
        $hasTipo = $this->hasColumn('tipo_cliente');

        if ($q !== '') {
            $searchFields = ['nombre LIKE :q'];
            if ($hasCuit) $searchFields[] = 'cuit LIKE :q';
            if ($hasEmail) $searchFields[] = 'email LIKE :q';
            if ($hasTelefono) $searchFields[] = 'telefono LIKE :q';
            
            $where[] = '(' . implode(' OR ', $searchFields) . ')';
            $params[':q'] = '%' . $q . '%';
        }

        if ($estado === 'activos') {
            $where[] = 'activo = 1';
        } elseif ($estado === 'inactivos') {
            $where[] = 'activo = 0';
        }
        
        if ($hasTipo && $tipo !== '' && $tipo !== 'TODOS') {
            $where[] = 'tipo_cliente = :tipo';
            $params[':tipo'] = $tipo;
        }
        
        // Filtros de cuenta corriente
        if ($hasCC && $estadoCC !== '') {
            switch ($estadoCC) {
                case 'cc_activa':
                    $where[] = 'cc_habilitado = 1';
                    break;
                case 'cc_con_deuda':
                    $where[] = 'cc_habilitado = 1 AND cc_saldo > 0';
                    break;
                case 'cc_excedido':
                    $where[] = 'cc_habilitado = 1 AND cc_saldo > cc_limite';
                    break;
                case 'cc_al_dia':
                    $where[] = 'cc_habilitado = 1 AND cc_saldo <= 0';
                    break;
                case 'sin_cc':
                    $where[] = '(cc_habilitado = 0 OR cc_habilitado IS NULL)';
                    break;
            }
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $st = $this->pdo->prepare("SELECT COUNT(*) FROM clientes {$whereSql}");
        $st->execute($params);
        $totalRows = (int)$st->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        
        $offset = ($page - 1) * $perPage;

        $sqlList = "
            SELECT *
            FROM clientes
            {$whereSql}
            ORDER BY nombre ASC
            LIMIT :limit OFFSET :offset
        ";

        $st = $this->pdo->prepare($sqlList);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':limit',  $perPage, PDO::PARAM_INT);
        $st->bindValue(':offset', $offset,  PDO::PARAM_INT);
        $st->execute();

        return [
            'clientes'    => $st->fetchAll(PDO::FETCH_ASSOC),
            'totalRows'   => $totalRows,
            'totalPages'  => $totalPages,
            'currentPage' => $page,
            'hasCC'       => $hasCC,
        ];
    }
    
    /**
     * Estadísticas de cuenta corriente
     */
    public function getEstadisticasCC(): array
    {
        if (!$this->hasColumnCC()) {
            return [
                'total_con_cc' => 0,
                'total_deuda' => 0,
                'clientes_con_deuda' => 0,
                'clientes_excedidos' => 0,
            ];
        }
        
        try {
            $st = $this->pdo->query("
                SELECT 
                    COUNT(CASE WHEN cc_habilitado = 1 THEN 1 END) AS total_con_cc,
                    COALESCE(SUM(CASE WHEN cc_habilitado = 1 THEN cc_saldo ELSE 0 END), 0) AS total_deuda,
                    COUNT(CASE WHEN cc_habilitado = 1 AND cc_saldo > 0 THEN 1 END) AS clientes_con_deuda,
                    COUNT(CASE WHEN cc_habilitado = 1 AND cc_saldo > cc_limite THEN 1 END) AS clientes_excedidos
                FROM clientes
                WHERE activo = 1
            ");
            return $st->fetch(PDO::FETCH_ASSOC) ?: [
                'total_con_cc' => 0,
                'total_deuda' => 0,
                'clientes_con_deuda' => 0,
                'clientes_excedidos' => 0,
            ];
        } catch (Throwable $e) {
            return [
                'total_con_cc' => 0,
                'total_deuda' => 0,
                'clientes_con_deuda' => 0,
                'clientes_excedidos' => 0,
            ];
        }
    }

    private function nullIfEmpty(string $value): ?string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
    
    public function exportarCSV(array $filters): void
    {
        $filters['per_page'] = 999999;
        $filters['page'] = 1;
        $data = $this->getList($filters);
        
        $condIvaOptions = self::getCondIvaOptions();
        $tipoOptions = self::getTipoClienteOptions();
        $hasCC = $data['hasCC'] ?? false;
        $hasDescuento = $this->hasColumn('descuento_porcentaje');
        $hasTipo = $this->hasColumn('tipo_cliente');
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="clientes_' . date('Y-m-d') . '.csv"');
        
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        
        $headers = ['ID', 'Nombre', 'CUIT', 'Condición IVA'];
        if ($hasTipo) $headers[] = 'Tipo Cliente';
        if ($hasDescuento) $headers[] = 'Descuento %';
        $headers = array_merge($headers, ['Email', 'Teléfono', 'Dirección', 'Estado']);
        if ($hasCC) {
            $headers = array_merge($headers, ['CC Habilitada', 'CC Límite', 'CC Saldo']);
        }
        
        fputcsv($out, $headers);
        
        foreach ($data['clientes'] as $c) {
            $row = [
                $c['id'],
                $c['nombre'],
                $c['cuit'] ?? '',
                $condIvaOptions[$c['cond_iva'] ?? ''] ?? '',
            ];
            
            if ($hasTipo) {
                $row[] = $tipoOptions[$c['tipo_cliente'] ?? 'MINORISTA'] ?? '';
            }
            if ($hasDescuento) {
                $row[] = number_format((float)($c['descuento_porcentaje'] ?? 0), 2);
            }
            
            $row[] = $c['email'] ?? '';
            $row[] = $c['telefono'] ?? '';
            $row[] = $c['direccion'] ?? '';
            $row[] = (int)($c['activo'] ?? 0) === 1 ? 'Activo' : 'Inactivo';
            
            if ($hasCC) {
                $row[] = (int)($c['cc_habilitado'] ?? 0) === 1 ? 'Sí' : 'No';
                $row[] = number_format((float)($c['cc_limite'] ?? 0), 2);
                $row[] = number_format((float)($c['cc_saldo'] ?? 0), 2);
            }
            
            fputcsv($out, $row);
        }
        
        fclose($out);
    }
}
