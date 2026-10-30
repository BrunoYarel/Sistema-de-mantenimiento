<?php
// backend/modulos/equipos/rutas.php
declare(strict_types=1);

use Modulos\Equipos\Controlador;
use Nucleo\Router;

/** Rutas y contrato: docs/api/equipos.md */
return static function (Router $r): void {
    // /sedes y /ubicaciones viven en el módulo catalogos.

    // El jefe de informática gestiona equipos pero no tiene equipo.elegir. Si el Router exigiera uno solo de los dos
    // permisos, alguien quedaría fuera. El Servicio exige equipo.gestionar o equipo.elegir (403 si no tiene ninguno)
    // y decide qué campos devuelve.
    $r->get('/equipos', [Controlador::class, 'listar'], ['permiso' => null]);

    // Historial y fuera de servicio (RF-09): equipo.historial, que tienen los técnicos y los jefes (también el de informática).
    // Se mudarán al módulo incidencias con la MISMA URL cuando se construya (docs/api/incidencias.md).
    // /equipos/{id} no se come a /equipos/fuera-de-servicio: el Router da prioridad a las rutas fijas.
    $r->get('/equipos/fuera-de-servicio', [Controlador::class, 'fueraDeServicio'], ['permiso' => 'equipo.historial']);
    $r->get('/equipos/{id}/historial', [Controlador::class, 'historial'], ['permiso' => 'equipo.historial']);

    // Alta y edición: solo quien gestiona equipos. Todo POST que inserta lleva Idempotency-Key.
    $r->get('/equipos/{id}', [Controlador::class, 'ver'], ['permiso' => 'equipo.gestionar']);
    $r->post('/equipos', [Controlador::class, 'crear'], [
        'permiso' => 'equipo.gestionar',
        'idempotente' => true,
        'estado' => 201,
    ]);
    $r->patch('/equipos/{id}', [Controlador::class, 'editar'], ['permiso' => 'equipo.gestionar']);

    // Baja y reactivación: solo el jefe de soporte (equipo.dar_de_baja). Son cambios de estado, no altas:
    // no llevan Idempotency-Key (repetirlas da 409 transicion_invalida, igual que desactivar/reactivar usuarios).
    $r->post('/equipos/{id}/baja', [Controlador::class, 'baja'], ['permiso' => 'equipo.dar_de_baja']);
    $r->post('/equipos/{id}/reactivar', [Controlador::class, 'reactivar'], ['permiso' => 'equipo.dar_de_baja']);
};