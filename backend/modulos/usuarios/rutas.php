<?php
// backend/modulos/usuarios/rutas.php
declare(strict_types=1);

use Modulos\Usuarios\Controlador;
use Nucleo\Router;

/** Rutas y contrato: docs/api/usuarios.md */
return static function (Router $r): void {
    // Lecturas que necesita incidencias: quién puede atender y a nombre de quién se puede registrar.
    $r->get('/usuarios/tecnicos', [Controlador::class, 'tecnicos'], ['permiso' => 'incidencia.asignar']);
    $r->get('/usuarios/reportantes', [Controlador::class, 'reportantes'], ['permiso' => 'incidencia.registrar_a_nombre']);

    // Gestión (RF-12, paso 4): solo quien tiene usuario.gestionar. Solo el alta inserta, así que solo ella lleva
    // Idempotency-Key; desactivar, reactivar, restablecer y desbloquear son cambios de estado, no altas.
    $r->get('/usuarios', [Controlador::class, 'listar'], ['permiso' => 'usuario.gestionar']);
    // Las rutas fijas (/usuarios/tecnicos, /usuarios/reportantes) ganan siempre a esta con {id}.
    $r->get('/usuarios/{id}', [Controlador::class, 'ver'], ['permiso' => 'usuario.gestionar']);
    $r->post('/usuarios', [Controlador::class, 'crear'], [
        'permiso' => 'usuario.gestionar',
        'idempotente' => true,
        'estado' => 201,
    ]);
    $r->patch('/usuarios/{id}', [Controlador::class, 'editar'], ['permiso' => 'usuario.gestionar']);
    $r->post('/usuarios/{id}/desactivar', [Controlador::class, 'desactivar'], ['permiso' => 'usuario.gestionar']);
    $r->post('/usuarios/{id}/reactivar', [Controlador::class, 'reactivar'], ['permiso' => 'usuario.gestionar']);
    $r->post('/usuarios/{id}/restablecer-clave', [Controlador::class, 'restablecerClave'], ['permiso' => 'usuario.gestionar']);
    $r->post('/usuarios/{id}/desbloquear', [Controlador::class, 'desbloquear'], ['permiso' => 'usuario.gestionar']);
};
