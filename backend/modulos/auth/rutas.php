<?php
// backend/modulos/auth/rutas.php
declare(strict_types=1);

use Modulos\Auth\Controlador;
use Nucleo\Router;

/** Contrato: docs/api/auth.md */
return static function (Router $r): void {
    $r->get('/me', [Controlador::class, 'me'], ['permiso' => null, 'clave_pendiente' => true]);
    $r->post('/auth/login', [Controlador::class, 'login'], ['permiso' => null, 'sesion' => false]);
    $r->post('/auth/logout', [Controlador::class, 'logout'], ['permiso' => null, 'sesion' => false]);
    $r->post('/auth/clave', [Controlador::class, 'clave'], ['permiso' => null, 'clave_pendiente' => true]);
};
