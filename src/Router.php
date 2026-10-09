<?php
declare(strict_types=1);

/** Router mínimo: asocia método + ruta (con parámetros {id}) a una función. */
class Router
{
    /** @var array<int, array{string, string, callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        // "/productos/{id}" -> "#^/productos/(?P<id>[^/]+)$#"
        $regex = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern);
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler];
    }

    public function dispatch(Request $request): void
    {
        $pathMatched = false;

        foreach ($this->routes as [$method, $regex, $handler]) {
            if (!preg_match($regex, $request->path, $matches)) {
                continue;
            }
            $pathMatched = true;
            if ($method !== $request->method) {
                continue;
            }
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler($request, $params);
            return;
        }

        if ($pathMatched) {
            throw new ApiException(405, 'Método no permitido para esta ruta.');
        }
        throw new ApiException(404, 'Ruta no encontrada.');
    }
}
