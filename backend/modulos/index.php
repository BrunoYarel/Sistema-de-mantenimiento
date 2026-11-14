<?php
// backend/modulos/index.php
declare(strict_types=1);

use Nucleo\Config;

/**
 * Lista de módulos del backend (equivalente a frontend/js/modulos/index.js).
 * Cada id es una carpeta backend/modulos/<id>/ que contiene un rutas.php.
 * Un módulo vacío (solo con comentarios) es válido: se va llenando copiando backend/modulos/plantilla.
 */
$modulos = [
    'auth',
    'catalogos',
    'usuarios',
    'equipos',
    'incidencias',
    'autorizaciones',
    'indicadores',
];

if (Config::esLocal()) {
    $modulos[] = 'plantilla'; // ejemplo para copiar: nunca en producción
}

return $modulos;
