<?php
/**
 * Router para el servidor de desarrollo de PHP:
 *   php -S localhost:8000 -t public public/router-dev.php
 * Envía /api/* a index.php y sirve el resto como archivos estáticos.
 * En Apache/Nginx no se usa: ahí basta con public/.htaccess.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/') {
    header('Location: /cliente/');
    exit;
}
if (str_starts_with($path, '/api')) {
    require __DIR__ . '/index.php';
    return true;
}
return false; // archivo estático (cliente/, etc.)
