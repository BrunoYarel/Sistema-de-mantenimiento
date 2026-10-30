<?php
// backend/public/index.php
declare(strict_types=1);

/**
 * Único archivo del backend expuesto a la web. Todo /api/* llega aquí.
 * Desarrollo: php -S localhost:8000 -t frontend bin/router-dev.php
 * Producción: el servidor web apunta /api a esta carpeta (ver backend/README.md).
 */
require __DIR__ . '/../nucleo/autoload.php';

Nucleo\Aplicacion::ejecutar();
