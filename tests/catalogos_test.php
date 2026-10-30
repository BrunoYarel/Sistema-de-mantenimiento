<?php
// tests/catalogos_test.php
declare(strict_types=1);

use Modulos\Catalogos\Servicio as CatalogosServicio;
use Nucleo\Conexion;
use Nucleo\Reloj;
use Nucleo\Router;

/**
 * Catálogos: sedes y ubicaciones (alta, renombre y consulta para otros módulos).
 *  - Sin base de datos: normalización del nombre y el cableado de las rutas (permiso, idempotencia, 201).
 *  - Con base de datos (--con-bd): el Servicio real, dentro de una transacción que SIEMPRE se deshace.
 *    Solo se provocan errores que el Servicio ataja antes de tocar la base: un error de PostgreSQL dejaría
 *    abortada la transacción de la prueba.
 */

/** Se lanza al final de cada prueba con base de datos para deshacer todo lo que escribió. */
final class CatalogosRevertir extends Exception
{
}

/** Corre $fn dentro de una transacción y la deshace siempre. Si $fn falla, también se deshace y el fallo sigue su camino. */
function catalogosEnTransaccion(callable $fn): void
{
    try {
        Conexion::transaccion(static function () use ($fn): void {
            $fn();
            throw new CatalogosRevertir();
        });
    } catch (CatalogosRevertir) {
        // lo esperado: no queda nada en la base
    }
}

/** Una sede, un usuario que gestiona equipos y un sufijo para que ningún nombre choque con datos reales. */
function catalogosEscenario(): array
{
    $pdo = Conexion::obtener();
    $sufijo = bin2hex(random_bytes(4));

    $sede = $pdo->prepare('INSERT INTO sede (nombre) VALUES (?) RETURNING id');
    $sede->execute(["__prueba_$sufijo"]);
    $sedeId = (int) $sede->fetchColumn();

    $usuario = $pdo->prepare("INSERT INTO usuario (nombre, usuario, rol, creado_en) VALUES ('Prueba', ?, 'soporte_tecnico', ?) RETURNING id");
    $usuario->execute(["prueba_$sufijo", Reloj::paraBd()]);
    $usuarioId = (int) $usuario->fetchColumn();

    return ['usuario' => ['id' => $usuarioId, 'rol' => 'soporte_tecnico'], 'sede_id' => $sedeId, 'sufijo' => $sufijo];
}

/** @return list<string> acciones de auditoría de esa entidad, en el orden en que se registraron */
function catalogosAuditoria(string $entidad, int $id): array
{
    $st = Conexion::obtener()->prepare('SELECT accion FROM auditoria WHERE entidad = ? AND entidad_id = ? ORDER BY id');
    $st->execute([$entidad, $id]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

// ---------------------------------------------------------------- sin base de datos

prueba('normalizarNombre: un solo espacio entre palabras; mayúsculas y tildes como se escribieron', function () {
    exigirIgual('Laboratorio 2 Cómputo', CatalogosServicio::normalizarNombre("  Laboratorio   2\tCómputo "));
    exigirIgual('', CatalogosServicio::normalizarNombre("  \t "));
});

prueba('rutas de catalogos: las lecturas piden solo sesión; alta y renombre de ubicaciones piden equipo.gestionar', function () {
    $router = new Router();
    (require __DIR__ . '/../backend/modulos/catalogos/rutas.php')($router);
    $rutas = (fn (): array => $this->rutas)->call($router);

    // ruta => [permiso, ¿idempotente?, código de éxito]
    $esperadas = [
        'GET /sedes' => [null, false, 200],
        'GET /ubicaciones' => [null, false, 200], // el Servicio exige equipo.elegir o equipo.gestionar
        'POST /ubicaciones' => ['equipo.gestionar', true, 201],
        'PATCH /ubicaciones/{id}' => ['equipo.gestionar', false, 200],
    ];
    foreach ($esperadas as $ruta => [$permiso, $idempotente, $estado]) {
        exigir(isset($rutas[$ruta]), "Falta la ruta $ruta");
        $opc = $rutas[$ruta]['opc'];
        exigirIgual($permiso, $opc['permiso'], "permiso de $ruta");
        exigirIgual($idempotente, !empty($opc['idempotente']), "idempotencia de $ruta");
        exigirIgual($estado, (int) ($opc['estado'] ?? 200), "estado de $ruta");
    }
});

prueba('ubicaciones: sin equipo.elegir ni equipo.gestionar, 403 (default deny)', function () {
    exigirError(fn () => (new CatalogosServicio())->ubicaciones(['id' => 1, 'rol' => 'inventado'], null), 403);
});

// ---------------------------------------------------------------- con base de datos

pruebaBd('ubicaciones: alta con idempotencia y auditoría; el nombre no se repite en la sede aunque cambien las mayúsculas', function () {
    catalogosEnTransaccion(function () {
        $e = catalogosEscenario();
        $servicio = new CatalogosServicio();

        $u = $servicio->crearUbicacion($e['usuario'], $e['sede_id'], '  Laboratorio   2 ', "alta-ubi-{$e['sufijo']}");
        exigirIgual('Laboratorio 2', $u['nombre'], 'nombre normalizado');
        exigirIgual($e['sede_id'], $u['sede_id']);

        $reintento = $servicio->crearUbicacion($e['usuario'], $e['sede_id'], 'Laboratorio 2', "alta-ubi-{$e['sufijo']}");
        exigirIgual($u['id'], $reintento['id'], 'el reintento con la misma clave no duplica');
        exigirIgual(['crear'], catalogosAuditoria('ubicacion', $u['id']));

        exigirError(fn () => $servicio->crearUbicacion($e['usuario'], $e['sede_id'], 'LABORATORIO 2', "alta-ubi-2-{$e['sufijo']}"), 422);
        exigirError(fn () => $servicio->crearUbicacion($e['usuario'], 999999999, 'Otra', "alta-ubi-3-{$e['sufijo']}"), 422);
    });
});

pruebaBd('ubicaciones: renombrar deja auditoría, sin cambio real no escribe y no pisa el nombre de otra', function () {
    catalogosEnTransaccion(function () {
        $e = catalogosEscenario();
        $servicio = new CatalogosServicio();

        $a = $servicio->crearUbicacion($e['usuario'], $e['sede_id'], 'Aula A', "ren-a-{$e['sufijo']}");
        $servicio->crearUbicacion($e['usuario'], $e['sede_id'], 'Aula B', "ren-b-{$e['sufijo']}");

        $r = $servicio->renombrarUbicacion($e['usuario'], $a['id'], 'Aula A1');
        exigirIgual('Aula A1', $r['nombre']);
        exigirIgual($e['sede_id'], $r['sede_id'], 'la sede no cambia');
        exigirIgual(['crear', 'renombrar'], catalogosAuditoria('ubicacion', $a['id']));

        $servicio->renombrarUbicacion($e['usuario'], $a['id'], 'Aula A1');
        exigirIgual(['crear', 'renombrar'], catalogosAuditoria('ubicacion', $a['id']), 'sin cambio real no hay registro nuevo');

        $servicio->renombrarUbicacion($e['usuario'], $a['id'], 'AULA A1'); // solo cambian las mayúsculas: se permite
        exigirIgual(['crear', 'renombrar', 'renombrar'], catalogosAuditoria('ubicacion', $a['id']));

        exigirError(fn () => $servicio->renombrarUbicacion($e['usuario'], $a['id'], 'aula b'), 422);
        exigirError(fn () => $servicio->renombrarUbicacion($e['usuario'], 999999999, 'Nada'), 404);
    });
});

pruebaBd('ubicaciones: la lista se filtra por sede y las consultas para otros módulos (ubicacion, nombresDeUbicaciones) responden', function () {
    catalogosEnTransaccion(function () {
        $e = catalogosEscenario();
        $servicio = new CatalogosServicio();

        $otraSede = Conexion::obtener()->prepare('INSERT INTO sede (nombre) VALUES (?) RETURNING id');
        $otraSede->execute(["__prueba_2_{$e['sufijo']}"]);
        $otraSedeId = (int) $otraSede->fetchColumn();

        $a = $servicio->crearUbicacion($e['usuario'], $e['sede_id'], 'Aula A', "lis-a-{$e['sufijo']}");
        $b = $servicio->crearUbicacion($e['usuario'], $otraSedeId, 'Aula B', "lis-b-{$e['sufijo']}");

        exigirIgual([$a], $servicio->ubicaciones($e['usuario'], $e['sede_id']), 'solo las de esa sede');
        exigirIgual([$b], $servicio->ubicaciones($e['usuario'], $otraSedeId));
        $todas = array_column($servicio->ubicaciones($e['usuario'], null), 'id');
        exigir(in_array($a['id'], $todas, true) && in_array($b['id'], $todas, true), 'sin sede, las de todas');

        exigirIgual($a, $servicio->ubicacion($a['id']));
        exigirIgual(null, $servicio->ubicacion(999999999), 'una ubicación que no existe da null');
        $nombres = $servicio->nombresDeUbicaciones([$a['id'], $b['id'], $a['id'], 999999999]);
        ksort($nombres); // el orden de la base no está garantizado
        $esperados = [$a['id'] => 'Aula A', $b['id'] => 'Aula B'];
        ksort($esperados);
        exigirIgual($esperados, $nombres, 'id => nombre; los que no existen no aparecen');
        exigirIgual([], $servicio->nombresDeUbicaciones([]));
    });
});