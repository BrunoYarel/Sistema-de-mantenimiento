<?php
// backend/modulos/catalogos/rutas.php
declare(strict_types=1);

use Modulos\Catalogos\Controlador;
use Nucleo\Router;

/** Rutas y contrato: docs/api/catalogos.md */
return static function (Router $r): void {
    // Todos los roles entran a Inicio, así que basta con tener sesión (el jefe de informática no tiene equipo.elegir).
    $r->get('/sedes', [Controlador::class, 'sedes'], ['permiso' => null]);

    // Igual que /sedes: el jefe de informática gestiona equipos pero no tiene equipo.elegir. Si el Router exigiera
    // uno solo de los dos permisos, alguien quedaría fuera. El Servicio exige equipo.gestionar o equipo.elegir
    // (403 si no tiene ninguno).
    $r->get('/ubicaciones', [Controlador::class, 'ubicaciones'], ['permiso' => null]);

    // Alta y renombre: solo quien gestiona equipos. Las ubicaciones son un catálogo y no tienen permiso propio.
    // Todo POST que inserta lleva Idempotency-Key.
    $r->post('/ubicaciones', [Controlador::class, 'crearUbicacion'], [
        'permiso' => 'equipo.gestionar',
        'idempotente' => true,
        'estado' => 201,
    ]);
    $r->patch('/ubicaciones/{id}', [Controlador::class, 'renombrarUbicacion'], ['permiso' => 'equipo.gestionar']);
};