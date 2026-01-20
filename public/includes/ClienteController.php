<?php
// public/includes/ClienteController.php
declare(strict_types=1);

require_once __DIR__ . '/CuitValidator.php';

class ClienteController
{
    private PDO $pdo;

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
    
    public function getZonasReparto(): array
    {
        try {
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
        
        $userId = (int)($_SESSION['usuario_id'] ?? $_SESSION['user']['id'] ?? 0);

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
    }

    public function validateForm(array $post): array
    {
        $errores = [];
        
        $nombre = trim((string)($post['nombre'] ?? ''));
        $cuit   = trim((string)($post['cuit'] ?? ''));
        $email  = trim((string)($post['email'] ?? ''));
        $condIva = trim((string)($post['cond_iva'] ?? ''));
        $descuentoPct = (string)($post['descuento_porcentaje'] ?? '0');
        
        // Nombre obligatorio
        if ($nombre === '') {
            $errores[] = 'El nombre es obligatorio.';
        } elseif (strlen($nombre) > 200) {
            $errores[] = 'El nombre es demasiado largo (máx. 200 caracteres).';
        }
        
        // Validar CUIT si viene
        if ($cuit !== '') {
            if (!CuitValidator::validar($cuit)) {
                $errorDetallado = CuitValidator::obtenerError($cuit);
                $errores[] = $errorDetallado ?: 'El CUIT/CUIL no es válido.';
            } else {
                // Verificar duplicados
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
        
        if ($email !== '' && strlen($email) > 100) {
            $errores[] = 'El email es demasiado largo (máx. 100 caracteres).';
        }

        // Condición IVA
        if ($condIva !== '' && !array_key_exists($condIva, self::getCondIvaOptions())) {
            $errores[] = 'Condición IVA inválida.';
        }
        
        // Descuento
        if ($descuentoPct !== '') {
            $descuento = (float)$descuentoPct;
            if ($descuento < 0 || $descuento > 100) {
                $errores[] = 'El descuento debe estar entre 0% y 100%.';
            }
        }

        return $errores;
    }
    
    private function existeCuitDuplicado(string $cuit, ?int $excludeId = null): bool
    {
        $cuitLimpio = CuitValidator::limpiar($cuit);
        
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

    public function create(array $post): bool
    {
        $userId = (int)($_SESSION['usuario_id'] ?? $_SESSION['user']['id'] ?? 0);
        
        $cuit = trim((string)($post['cuit'] ?? ''));
        if ($cuit !== '') {
            $cuit = CuitValidator::formatear($cuit) ?? $cuit;
        }
        
        $st = $this->pdo->prepare("
            INSERT INTO clientes (
                nombre, cuit, cond_iva, tipo_cliente, descuento_porcentaje,
                direccion, zona_reparto, email, telefono, notas, activo,
                created_by
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        return $st->execute([
            trim($post['nombre']),
            $this->nullIfEmpty($cuit),
            $this->nullIfEmpty($post['cond_iva'] ?? ''),
            $this->nullIfEmpty($post['tipo_cliente'] ?? 'MINORISTA'),
            (float)($post['descuento_porcentaje'] ?? 0),
            $this->nullIfEmpty($post['direccion'] ?? ''),
            $this->nullIfEmpty($post['zona_reparto'] ?? ''),
            $this->nullIfEmpty($post['email'] ?? ''),
            $this->nullIfEmpty($post['telefono'] ?? ''),
            $this->nullIfEmpty($post['notas'] ?? ''),
            isset($post['activo']) ? 1 : 0,
            $userId > 0 ? $userId : null
        ]);
    }

    public function update(int $id, array $post): bool
    {
        $userId = (int)($_SESSION['usuario_id'] ?? $_SESSION['user']['id'] ?? 0);
        
        $cuit = trim((string)($post['cuit'] ?? ''));
        if ($cuit !== '') {
            $cuit = CuitValidator::formatear($cuit) ?? $cuit;
        }
        
        $st = $this->pdo->prepare("
            UPDATE clientes SET
                nombre = ?, cuit = ?, cond_iva = ?, tipo_cliente = ?,
                descuento_porcentaje = ?, direccion = ?, zona_reparto = ?,
                email = ?, telefono = ?, notas = ?, activo = ?,
                updated_by = ?
            WHERE id = ?
        ");

        return $st->execute([
            trim($post['nombre']),
            $this->nullIfEmpty($cuit),
            $this->nullIfEmpty($post['cond_iva'] ?? ''),
            $this->nullIfEmpty($post['tipo_cliente'] ?? 'MINORISTA'),
            (float)($post['descuento_porcentaje'] ?? 0),
            $this->nullIfEmpty($post['direccion'] ?? ''),
            $this->nullIfEmpty($post['zona_reparto'] ?? ''),
            $this->nullIfEmpty($post['email'] ?? ''),
            $this->nullIfEmpty($post['telefono'] ?? ''),
            $this->nullIfEmpty($post['notas'] ?? ''),
            isset($post['activo']) ? 1 : 0,
            $userId > 0 ? $userId : null,
            $id
        ]);
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
        $perPage = (int)($filters['per_page'] ?? 50);
        $page    = max(1, (int)($filters['page'] ?? 1));

        $where  = ['1=1'];
        $params = [];

        if ($q !== '') {
            $where[] = '(nombre LIKE :q OR cuit LIKE :q OR email LIKE :q OR telefono LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        if ($estado === 'activos') {
            $where[] = 'activo = 1';
        } elseif ($estado === 'inactivos') {
            $where[] = 'activo = 0';
        }
        
        if ($tipo !== '' && $tipo !== 'TODOS') {
            $where[] = 'tipo_cliente = :tipo';
            $params[':tipo'] = $tipo;
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
            SELECT c.*
            FROM clientes c
            {$whereSql}
            ORDER BY c.nombre ASC
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
        ];
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
        
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="clientes_' . date('Y-m-d') . '.csv"');
        
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($out, [
            'ID', 'Nombre', 'CUIT', 'Condición IVA', 'Tipo Cliente', 
            'Descuento %', 'Email', 'Teléfono', 'Dirección', 'Zona Reparto',
            'Estado', 'Fecha Creación'
        ]);
        
        foreach ($data['clientes'] as $c) {
            fputcsv($out, [
                $c['id'],
                $c['nombre'],
                $c['cuit'] ?? '',
                $condIvaOptions[$c['cond_iva'] ?? ''] ?? '',
                $tipoOptions[$c['tipo_cliente'] ?? 'MINORISTA'] ?? '',
                number_format((float)($c['descuento_porcentaje'] ?? 0), 2),
                $c['email'] ?? '',
                $c['telefono'] ?? '',
                $c['direccion'] ?? '',
                $c['zona_reparto'] ?? '',
                (int)($c['activo'] ?? 0) === 1 ? 'Activo' : 'Inactivo',
                $c['created_at'] ?? ''
            ]);
        }
        
        fclose($out);
    }
}