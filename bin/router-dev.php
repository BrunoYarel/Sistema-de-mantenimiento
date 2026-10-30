<?php
// bin/router-dev.php
declare(strict_types=1);

/**
 * Servidor de desarrollo: sirve el frontend y manda /api/* al backend, en el mismo origen
 * (la API se llama en /api, ruta absoluta). Desde la raíz del repositorio:
 *
 *     php -S localhost:8000 -t frontend bin/router-dev.php
 *
 * Solo para desarrollo. En el servidor real lo hace Apache/Nginx (ver backend/README.md).
 */
$ruta = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

if ($ruta === '/api' || str_starts_with($ruta, '/api/')) {
    require __DIR__ . '/../backend/public/index.php';
    return true;
}

return false; // el servidor integrado entrega el archivo estático de frontend/
