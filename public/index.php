<?php
declare(strict_types=1);

/**
 * Punto de entrada de la API (front controller).
 * Todas las rutas /api/* llegan aquí y se despachan con el Router.
 */

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Router.php';
require __DIR__ . '/../src/Database.php';
require __DIR__ . '/../src/AuthController.php';
require __DIR__ . '/../src/ProductoController.php';

// Respuestas siempre JSON; nada de errores de PHP en pantalla.
ini_set('display_errors', '0');
header('X-Content-Type-Options: nosniff');

$request = Request::fromGlobals();

// Quita el prefijo /api para registrar las rutas de forma limpia.
if (!str_starts_with($request->path, '/api')) {
    respond(404, ['error' => 'Ruta no encontrada.']);
    exit;
}
$request->path = substr($request->path, 4) ?: '/';

$auth = new AuthController();
$productos = new ProductoController();

$router = new Router();
$router->add('POST', '/auth/register', [$auth, 'register']);
$router->add('POST', '/auth/login', [$auth, 'login']);
$router->add('POST', '/auth/logout', [$auth, 'logout']);

$router->add('GET', '/productos', [$productos, 'listar']);
$router->add('POST', '/productos', [$productos, 'crear']);
$router->add('GET', '/productos/{id}', [$productos, 'ver']);
$router->add('PUT', '/productos/{id}', [$productos, 'actualizar']);
$router->add('PATCH', '/productos/{id}', [$productos, 'actualizar']);
$router->add('DELETE', '/productos/{id}', [$productos, 'eliminar']);

$router->add('GET', '/resumen', [$productos, 'resumen']);

try {
    $router->dispatch($request);
} catch (ApiException $e) {
    $body = ['error' => $e->getMessage()];
    if ($e->errors) {
        $body['detalles'] = $e->errors;
    }
    respond($e->status, $body);
} catch (Throwable $e) {
    error_log($e);
    respond(500, ['error' => 'Error interno del servidor.']);
}
