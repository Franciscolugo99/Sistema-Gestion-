<?php
// includes/ClienteController.php
declare(strict_types=1);

class ClienteController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Opciones de condición IVA
     */
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

    /**
     * Toggle estado activo/inactivo
     */
    public function toggleActivo(array $post): bool
    {
        $id    = (int)($post['id'] ?? 0);
        $valor = (int)($post['valor'] ?? 0);

        if ($id <= 0) {
            return false;
        }

        $st = $this->pdo->prepare("UPDATE clientes SET activo = :v WHERE id = :id");
        return $st->execute([':v' => ($valor ? 1 : 0), ':id' => $id]);
    }

    /**
     * Validar datos del formulario
     */
    public function validateForm(array $post): array
    {
        $errores = [];
        
        $nombre = trim((string)($post['nombre'] ?? ''));
        $cuit   = trim((string)($post['cuit'] ?? ''));
        $email  = trim((string)($post['email'] ?? ''));
        $condIva = trim((string)($post['cond_iva'] ?? ''));

        if ($nombre === '') {
            $errores[] = 'El nombre es obligatorio.';
        }

        if ($cuit !== '' && strlen($cuit) > 20) {
            $errores[] = 'El CUIT/CUIL es demasiado largo (máx. 20 caracteres).';
        }

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El email no tiene un formato válido.';
        }

        if ($condIva !== '' && !array_key_exists($condIva, self::getCondIvaOptions())) {
            $errores[] = 'Condición IVA inválida.';
        }

        return $errores;
    }

    /**
     * Crear nuevo cliente
     */
    public function create(array $post): bool
    {
        $st = $this->pdo->prepare("
            INSERT INTO clientes (nombre, cuit, cond_iva, direccion, email, telefono, activo)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        return $st->execute([
            trim($post['nombre']),
            $this->nullIfEmpty($post['cuit'] ?? ''),
            $this->nullIfEmpty($post['cond_iva'] ?? ''),
            $this->nullIfEmpty($post['direccion'] ?? ''),
            $this->nullIfEmpty($post['email'] ?? ''),
            $this->nullIfEmpty($post['telefono'] ?? ''),
            isset($post['activo']) ? 1 : 0
        ]);
    }

    /**
     * Actualizar cliente existente
     */
    public function update(int $id, array $post): bool
    {
        $st = $this->pdo->prepare("
            UPDATE clientes SET
                nombre = ?, cuit = ?, cond_iva = ?, direccion = ?,
                email = ?, telefono = ?, activo = ?
            WHERE id = ?
        ");

        return $st->execute([
            trim($post['nombre']),
            $this->nullIfEmpty($post['cuit'] ?? ''),
            $this->nullIfEmpty($post['cond_iva'] ?? ''),
            $this->nullIfEmpty($post['direccion'] ?? ''),
            $this->nullIfEmpty($post['email'] ?? ''),
            $this->nullIfEmpty($post['telefono'] ?? ''),
            isset($post['activo']) ? 1 : 0,
            $id
        ]);
    }

    /**
     * Obtener cliente por ID
     */
    public function getById(int $id): ?array
    {
        $st = $this->pdo->prepare("SELECT * FROM clientes WHERE id = ? LIMIT 1");
        $st->execute([$id]);
        $result = $st->fetch(PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Listar clientes con filtros
     */
    public function getList(array $filters): array
    {
        $q       = trim($filters['q'] ?? '');
        $estado  = $filters['estado'] ?? '';
        $perPage = (int)($filters['per_page'] ?? 50);
        $page    = max(1, (int)($filters['page'] ?? 1));

        $where  = ['1=1'];
        $params = [];

        if ($q !== '') {
            $where[] = '(nombre LIKE :q OR cuit LIKE :q OR email LIKE :q)';
            $params[':q'] = '%' . $q . '%';
        }

        if ($estado === 'activos') {
            $where[] = 'activo = 1';
        } elseif ($estado === 'inactivos') {
            $where[] = 'activo = 0';
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // Total
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM clientes {$whereSql}");
        $st->execute($params);
        $totalRows = (int)$st->fetchColumn();
        $totalPages = max(1, (int)ceil($totalRows / $perPage));
        
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        
        $offset = ($page - 1) * $perPage;

        // Listado
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
        ];
    }

    /**
     * Helper: convierte string vacío a null
     */
    private function nullIfEmpty(string $value): ?string
    {
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}