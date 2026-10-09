<?php
declare(strict_types=1);

class ProductoController
{
    private const COLUMNAS = 'id, nombre, sku, categoria, precio, stock, creado_en, actualizado_en';

    /** GET /api/productos?q=&categoria=&pagina=&por_pagina= */
    public function listar(Request $request, array $params): void
    {
        $userId = AuthController::authenticate($request);

        $pagina = max(1, (int) ($request->query['pagina'] ?? 1));
        $porPagina = min(100, max(1, (int) ($request->query['por_pagina'] ?? 10)));

        $where = 'usuario_id = ?';
        $args = [$userId];

        $q = trim((string) ($request->query['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $where .= ' AND (nombre LIKE ? OR sku LIKE ?)';
            array_push($args, $like, $like);
        }
        $categoria = trim((string) ($request->query['categoria'] ?? ''));
        if ($categoria !== '') {
            $where .= ' AND categoria = ?';
            $args[] = $categoria;
        }

        $stmt = db()->prepare("SELECT COUNT(*) FROM productos WHERE $where");
        $stmt->execute($args);
        $total = (int) $stmt->fetchColumn();

        // $porPagina y $offset son enteros ya validados, por eso se pueden interpolar.
        $offset = ($pagina - 1) * $porPagina;
        $stmt = db()->prepare(
            'SELECT ' . self::COLUMNAS . " FROM productos WHERE $where
             ORDER BY id DESC LIMIT $porPagina OFFSET $offset"
        );
        $stmt->execute($args);

        respond(200, [
            'datos' => array_map([$this, 'formatear'], $stmt->fetchAll()),
            'paginacion' => [
                'pagina' => $pagina,
                'por_pagina' => $porPagina,
                'total' => $total,
                'paginas' => (int) ceil($total / $porPagina),
            ],
        ]);
    }

    /** GET /api/productos/{id} */
    public function ver(Request $request, array $params): void
    {
        $userId = AuthController::authenticate($request);
        respond(200, ['producto' => $this->formatear($this->buscar((int) $params['id'], $userId))]);
    }

    /** POST /api/productos */
    public function crear(Request $request, array $params): void
    {
        $userId = AuthController::authenticate($request);
        $datos = $this->validar($request->json(), false);

        try {
            $stmt = db()->prepare(
                'INSERT INTO productos (usuario_id, nombre, sku, categoria, precio, stock)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $userId, $datos['nombre'], $datos['sku'], $datos['categoria'] ?? null,
                $datos['precio'], $datos['stock'],
            ]);
        } catch (PDOException $e) {
            $this->errorSkuDuplicado($e);
        }

        $producto = $this->buscar((int) db()->lastInsertId(), $userId);
        header('Location: /api/productos/' . $producto['id']);
        respond(201, ['producto' => $this->formatear($producto)]);
    }

    /** PUT (reemplazo completo) o PATCH (parcial) /api/productos/{id} */
    public function actualizar(Request $request, array $params): void
    {
        $userId = AuthController::authenticate($request);
        $id = (int) $params['id'];
        $this->buscar($id, $userId); // 404 si no existe o no es suyo

        $datos = $this->validar($request->json(), $request->method === 'PATCH');
        if (!$datos) {
            throw new ApiException(400, 'No hay campos para actualizar.');
        }

        $sets = [];
        $args = [];
        foreach ($datos as $campo => $valor) {
            // $campo sale de la lista blanca de validar(), nunca del usuario.
            $sets[] = "$campo = ?";
            $args[] = $valor;
        }
        array_push($args, $id, $userId);

        try {
            $stmt = db()->prepare(
                'UPDATE productos SET ' . implode(', ', $sets) . ' WHERE id = ? AND usuario_id = ?'
            );
            $stmt->execute($args);
        } catch (PDOException $e) {
            $this->errorSkuDuplicado($e);
        }

        respond(200, ['producto' => $this->formatear($this->buscar($id, $userId))]);
    }

    /** DELETE /api/productos/{id} */
    public function eliminar(Request $request, array $params): void
    {
        $userId = AuthController::authenticate($request);
        $stmt = db()->prepare('DELETE FROM productos WHERE id = ? AND usuario_id = ?');
        $stmt->execute([(int) $params['id'], $userId]);
        if ($stmt->rowCount() === 0) {
            throw new ApiException(404, 'Producto no encontrado.');
        }
        http_response_code(204);
    }

    /** GET /api/resumen */
    public function resumen(Request $request, array $params): void
    {
        $userId = AuthController::authenticate($request);
        $limite = (int) (config()['stock_bajo'] ?? 5);

        $stmt = db()->prepare(
            'SELECT COUNT(*) AS productos,
                    COALESCE(SUM(stock), 0) AS unidades,
                    COALESCE(SUM(precio * stock), 0) AS valor_inventario,
                    COALESCE(SUM(stock <= ?), 0) AS stock_bajo
             FROM productos WHERE usuario_id = ?'
        );
        $stmt->execute([$limite, $userId]);
        $r = $stmt->fetch();

        respond(200, ['resumen' => [
            'productos' => (int) $r['productos'],
            'unidades' => (int) $r['unidades'],
            'valor_inventario' => round((float) $r['valor_inventario'], 2),
            'stock_bajo' => (int) $r['stock_bajo'],
            'umbral_stock_bajo' => $limite,
        ]]);
    }

    private function buscar(int $id, int $userId): array
    {
        $stmt = db()->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM productos WHERE id = ? AND usuario_id = ?'
        );
        $stmt->execute([$id, $userId]);
        $producto = $stmt->fetch();
        if (!$producto) {
            throw new ApiException(404, 'Producto no encontrado.');
        }
        return $producto;
    }

    /**
     * Valida y normaliza los datos. Con $parcial = true solo valida los campos presentes.
     * Devuelve únicamente campos de la lista blanca.
     */
    private function validar(array $in, bool $parcial): array
    {
        $out = [];
        $errors = [];

        if (!$parcial || array_key_exists('nombre', $in)) {
            $v = trim((string) ($in['nombre'] ?? ''));
            if ($v === '' || mb_strlen($v) > 120) {
                $errors['nombre'] = 'Obligatorio, máximo 120 caracteres.';
            }
            $out['nombre'] = $v;
        }
        if (!$parcial || array_key_exists('sku', $in)) {
            $v = trim((string) ($in['sku'] ?? ''));
            if (!preg_match('/^[A-Za-z0-9._-]{1,40}$/', $v)) {
                $errors['sku'] = 'Obligatorio, 1 a 40 caracteres (letras, números, . _ -).';
            }
            $out['sku'] = $v;
        }
        if (!$parcial || array_key_exists('categoria', $in)) {
            $v = trim((string) ($in['categoria'] ?? ''));
            if (mb_strlen($v) > 60) {
                $errors['categoria'] = 'Máximo 60 caracteres.';
            }
            $out['categoria'] = $v !== '' ? $v : null;
        }
        if (!$parcial || array_key_exists('precio', $in)) {
            $v = $in['precio'] ?? null;
            if (!is_numeric($v) || (float) $v < 0 || (float) $v > 9999999999) {
                $errors['precio'] = 'Debe ser un número mayor o igual a 0.';
            }
            $out['precio'] = round((float) $v, 2);
        }
        if (!$parcial || array_key_exists('stock', $in)) {
            $v = $in['stock'] ?? 0;
            if (filter_var($v, FILTER_VALIDATE_INT) === false || (int) $v < 0 || (int) $v > 100000000) {
                $errors['stock'] = 'Debe ser un entero mayor o igual a 0.';
            }
            $out['stock'] = (int) $v;
        }

        if ($errors) {
            throw new ApiException(422, 'Datos inválidos.', $errors);
        }
        return $out;
    }

    private function errorSkuDuplicado(PDOException $e): void
    {
        if ($e->getCode() === '23000') {
            throw new ApiException(409, 'Ya tienes un producto con ese SKU.');
        }
        throw $e;
    }

    private function formatear(array $p): array
    {
        return [
            'id' => (int) $p['id'],
            'nombre' => $p['nombre'],
            'sku' => $p['sku'],
            'categoria' => $p['categoria'],
            'precio' => (float) $p['precio'],
            'stock' => (int) $p['stock'],
            'creado_en' => $p['creado_en'],
            'actualizado_en' => $p['actualizado_en'],
        ];
    }
}
