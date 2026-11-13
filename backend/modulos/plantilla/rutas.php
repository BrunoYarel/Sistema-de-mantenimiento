<?php
// backend/modulos/plantilla/rutas.php
declare(strict_types=1);

use Modulos\Plantilla\Controlador;
use Nucleo\Router;

/**
 * MÓDULO DE EJEMPLO. Para crear uno nuevo:
 *   1. Copia backend/modulos/plantilla a backend/modulos/<nombre>  (carpeta en minúscula).
 *   2. Cambia el namespace de cada archivo a Modulos\<Nombre>.
 *   3. Cambia las rutas, los permisos (de Dominio::PERMISOS) y las reglas.
 *   4. Agrega <nombre> en backend/modulos/index.php y documenta en docs/api/<nombre>.md ANTES de codificar (P1).
 */
return static function (Router $r): void {
    // Lectura: basta con tener sesión (permiso null).
    $r->get('/plantilla/sedes', [Controlador::class, 'listar'], ['permiso' => null]);
    $r->get('/plantilla/sedes/{id}', [Controlador::class, 'ver'], ['permiso' => null]);

    // Escritura que inserta: permiso explícito + Idempotency-Key obligatoria + 201.
    $r->post('/plantilla/sedes', [Controlador::class, 'crear'], [
        'permiso' => 'usuario.gestionar',
        'idempotente' => true,
        'estado' => 201,
    ]);
};
