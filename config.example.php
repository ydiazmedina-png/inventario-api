<?php
// Copia este archivo como config.php y ajusta tus credenciales.
// config.php está en .gitignore: nunca subas tus credenciales reales.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'inventario_api',
        'user' => 'root',
        'pass' => '',
    ],
    // Horas de validez de un token de acceso.
    'token_horas' => 12,
    // Un producto con stock igual o menor a este valor cuenta como "stock bajo".
    'stock_bajo' => 5,
];
