<?php
// tests/equipos_test.php
declare(strict_types=1);

use Modulos\Catalogos\Servicio as CatalogosServicio;
use Modulos\Equipos\Servicio as EquiposServicio;
use Nucleo\Conexion;
use Nucleo\Reloj;
use Nucleo\Router;

/**
 * Alta, edición, baja y reactivación de equipos; historial y fuera de servicio. Las ubicaciones se prueban en
 * tests/catalogos_test.php; aquí solo se usan (por el Servicio de catalogos) para armar los escenarios.
 *  - Sin base de datos: normalización de código y el cableado de las rutas (permiso, idempotencia, 201).
 *  - Con base de datos (--con-bd): el Servicio real, dentro de una transacción que SIEMPRE se deshace.
 *    Solo se provocan errores que el Servicio ataja antes de tocar la base: un error de PostgreSQL dejaría
 *    abortada la transacción de la prueba.
 */

/** Se lanza al final de cada prueba con base de datos para deshacer todo lo que escribió. */
final class EquiposRevertir extends Exception
{
}

/** Corre $fn dentro de una transacción y la deshace siempre. Si $fn falla, también se deshace y el fallo sigue su camino. */
function equiposEnTransaccion(callable $fn): void
{
    try {
        Conexion::transaccion(static function () use ($fn): void {
            $fn();
            throw new EquiposRevertir();
        });
    } catch (EquiposRevertir) {
        // lo esperado: no queda nada en la base
    }
}

/** Una sede, un usuario que gestiona equipos y un sufijo para que ningún nombre choque con datos reales. */
function equiposEscenario(): array
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
function equiposAuditoria(string $entidad, int $id): array
{
    $st = Conexion::obtener()->prepare('SELECT accion FROM auditoria WHERE entidad = ? AND entidad_id = ? ORDER BY id');
    $st->execute([$entidad, $id]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Inserta una incidencia como la dejaría el módulo incidencias (que aún no escribe): solo arma el escenario.
 * Los estados con técnico (asignada...resuelta) piden $tecnicoId; resuelta y cerrada llevan sus fechas (los CHECK de la base).
 */
function equiposIncidencia(array $e, int $equipoId, string $estado, string $falla, ?int $tecnicoId = null, ?DateTimeInterface $cuando = null): int
{
    $fecha = Reloj::paraBd($cuando);
    $st = Conexion::obtener()->prepare(
        "INSERT INTO incidencia (equipo_id, sede_id, reportante_id, registrador_id, canal, descripcion, prioridad, estado, tecnico_id,
                                 fecha_registro, fecha_resuelta, fecha_cierre, fecha_ultimo_cambio)
         VALUES (?, ?, ?, ?, 'sistema', ?, 'media', ?, ?, ?, ?, ?, ?) RETURNING id"
    );
    $st->execute([
        $equipoId, $e['sede_id'], $e['usuario']['id'], $e['usuario']['id'], $falla, $estado, $tecnicoId, $fecha,
        in_array($estado, ['resuelta', 'cerrada'], true) ? $fecha : null,
        $estado === 'cerrada' ? $fecha : null,
        $fecha,
    ]);
    return (int) $st->fetchColumn();
}

function equiposAtencion(int $incidenciaId, int $tecnicoId, string $acciones, ?string $resultado, DateTimeInterface $cuando): void
{
    $st = Conexion::obtener()->prepare(
        'INSERT INTO atencion (incidencia_id, tecnico_id, diagnostico, acciones, resultado, fecha) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $st->execute([$incidenciaId, $tecnicoId, 'Diagnóstico', $acciones, $resultado, Reloj::paraBd($cuando)]);
}

// ---------------------------------------------------------------- sin base de datos

prueba('normalizarCodigo: mayúsculas y espacios a guion, sin espacios en los bordes', function () {
    exigirIgual('PC-01', EquiposServicio::normalizarCodigo('pc 01'));
    exigirIgual('PC-01', EquiposServicio::normalizarCodigo('  PC-01 '));
    exigirIgual('LAB-2-PC-01', EquiposServicio::normalizarCodigo("lab   2\tpc 01"));
});

prueba('rutas de equipos: alta y edición piden equipo.gestionar; los POST que insertan exigen Idempotency-Key y dan 201', function () {
    $router = new Router();
    (require __DIR__ . '/../backend/modulos/equipos/rutas.php')($router);
    $rutas = (fn (): array => $this->rutas)->call($router);

    // ruta => [¿idempotente?, código de éxito]
    $esperadas = [
        'GET /equipos/{id}' => [false, 200],
        'POST /equipos' => [true, 201],
        'PATCH /equipos/{id}' => [false, 200],
    ];
    foreach ($esperadas as $ruta => [$idempotente, $estado]) {
        exigir(isset($rutas[$ruta]), "Falta la ruta $ruta");
        $opc = $rutas[$ruta]['opc'];
        exigirIgual('equipo.gestionar', $opc['permiso'], "permiso de $ruta");
        exigirIgual($idempotente, !empty($opc['idempotente']), "idempotencia de $ruta");
        exigirIgual($estado, (int) ($opc['estado'] ?? 200), "estado de $ruta");
    }

    // Baja y reactivación: solo equipo.dar_de_baja (el jefe de soporte). Son cambios de estado, no altas: sin Idempotency-Key.
    foreach (['POST /equipos/{id}/baja', 'POST /equipos/{id}/reactivar'] as $ruta) {
        exigir(isset($rutas[$ruta]), "Falta la ruta $ruta");
        $opc = $rutas[$ruta]['opc'];
        exigirIgual('equipo.dar_de_baja', $opc['permiso'], "permiso de $ruta");
        exigirIgual(false, !empty($opc['idempotente']), "idempotencia de $ruta");
        exigirIgual(200, (int) ($opc['estado'] ?? 200), "estado de $ruta");
    }

    // Historial y fuera de servicio (RF-09): solo lectura, con equipo.historial (también lo tiene el jefe de informática).
    foreach (['GET /equipos/fuera-de-servicio', 'GET /equipos/{id}/historial'] as $ruta) {
        exigir(isset($rutas[$ruta]), "Falta la ruta $ruta");
        $opc = $rutas[$ruta]['opc'];
        exigirIgual('equipo.historial', $opc['permiso'], "permiso de $ruta");
        exigirIgual(false, !empty($opc['idempotente']), "idempotencia de $ruta");
        exigirIgual(200, (int) ($opc['estado'] ?? 200), "estado de $ruta");
    }

    // Las lecturas de la lista siguen abiertas a quien tenga sesión: el Servicio decide qué ve cada quien.
    exigirIgual(null, $rutas['GET /equipos']['opc']['permiso'], 'permiso de GET /equipos');

    // Las ubicaciones ya no son de este módulo: sus rutas se declaran (y se prueban) en catalogos.
    foreach (['GET /ubicaciones', 'POST /ubicaciones', 'PATCH /ubicaciones/{id}'] as $ruta) {
        exigir(!isset($rutas[$ruta]), "$ruta ya no debe declararse en equipos (vive en catalogos)");
    }
});

// ---------------------------------------------------------------- con base de datos

pruebaBd('equipos: alta normaliza el código, toma la sede de la ubicación, es idempotente y queda en auditoría', function () {
    equiposEnTransaccion(function () {
        $e = equiposEscenario();
        $servicio = new EquiposServicio();
        $catalogos = new CatalogosServicio();
        $u = $catalogos->crearUbicacion($e['usuario'], $e['sede_id'], 'Lab 1', "eq-u-{$e['sufijo']}");
        $codigo = 'PRUEBA-' . strtoupper($e['sufijo']);

        $eq = $servicio->crearEquipo($e['usuario'], 'prueba ' . $e['sufijo'], 'computadora', $u['id'], 'alta', "eq-{$e['sufijo']}");
        exigirIgual($codigo, $eq['codigo'], 'código normalizado');
        exigirIgual($e['sede_id'], $eq['sede_id'], 'la sede es la de la ubicación');
        exigirIgual($u['id'], $eq['ubicacion_id']);
        exigirIgual('Lab 1', $eq['ubicacion']);
        exigirIgual('alta', $eq['criticidad']);
        exigirIgual(true, $eq['activo']);

        $reintento = $servicio->crearEquipo($e['usuario'], 'prueba ' . $e['sufijo'], 'computadora', $u['id'], 'alta', "eq-{$e['sufijo']}");
        exigirIgual($eq['id'], $reintento['id'], 'el reintento con la misma clave no duplica');
        exigirIgual(['crear'], equiposAuditoria('equipo', $eq['id']));

        exigirError(fn () => $servicio->crearEquipo($e['usuario'], strtolower($codigo), 'impresora', $u['id'], 'baja', "eq-2-{$e['sufijo']}"), 422);
        exigirError(fn () => $servicio->crearEquipo($e['usuario'], 'OTRO-' . $e['sufijo'], 'impresora', 999999999, 'baja', "eq-3-{$e['sufijo']}"), 422);
    });
});

pruebaBd('equipos: editar mueve la sede con la ubicación, deja un registro por cambio y no deja cambiar el código', function () {
    equiposEnTransaccion(function () {
        $e = equiposEscenario();
        $servicio = new EquiposServicio();
        $catalogos = new CatalogosServicio();

        $otraSede = Conexion::obtener()->prepare('INSERT INTO sede (nombre) VALUES (?) RETURNING id');
        $otraSede->execute(["__prueba_2_{$e['sufijo']}"]);
        $otraSedeId = (int) $otraSede->fetchColumn();

        $u1 = $catalogos->crearUbicacion($e['usuario'], $e['sede_id'], 'Lab 1', "ed-u1-{$e['sufijo']}");
        $u2 = $catalogos->crearUbicacion($e['usuario'], $otraSedeId, 'Lab 2', "ed-u2-{$e['sufijo']}");
        $eq = $servicio->crearEquipo($e['usuario'], 'ed ' . $e['sufijo'], 'computadora', $u1['id'], 'baja', "ed-{$e['sufijo']}");

        $r = $servicio->editarEquipo($e['usuario'], $eq['id'], ['ubicacion_id' => $u2['id'], 'criticidad' => 'alta', 'tipo' => 'proyector'], null);
        exigirIgual($u2['id'], $r['ubicacion_id']);
        exigirIgual('Lab 2', $r['ubicacion']);
        exigirIgual($otraSedeId, $r['sede_id'], 'la sede sigue a la ubicación');
        exigirIgual('alta', $r['criticidad']);
        exigirIgual('proyector', $r['tipo']);
        exigirIgual($eq['codigo'], $r['codigo'], 'el código no cambia');

        $acciones = equiposAuditoria('equipo', $eq['id']);
        sort($acciones);
        exigirIgual(['cambiar_criticidad', 'cambiar_tipo', 'cambiar_ubicacion', 'crear'], $acciones, 'un registro por cambio');

        // Lo que llega igual que está no escribe nada; el código, escrito de otra forma pero igual, se acepta.
        $igual = $servicio->editarEquipo($e['usuario'], $eq['id'], ['criticidad' => 'alta'], strtolower($eq['codigo']));
        exigirIgual($eq['codigo'], $igual['codigo']);
        exigirIgual(4, count(equiposAuditoria('equipo', $eq['id'])), 'sin cambio real no hay registro nuevo');

        exigirError(fn () => $servicio->editarEquipo($e['usuario'], $eq['id'], ['criticidad' => 'media'], 'OTRO-CODIGO'), 422);
        exigirError(fn () => $servicio->editarEquipo($e['usuario'], $eq['id'], ['ubicacion_id' => 999999999], null), 422);
        exigirError(fn () => $servicio->editarEquipo($e['usuario'], 999999999, ['criticidad' => 'media'], null), 404);

        exigirIgual('alta', $servicio->ver($eq['id'])['criticidad'], 'un rechazo no cambia nada');
        exigirIgual($u2['id'], $servicio->ver($eq['id'])['ubicacion_id'], 'un rechazo no cambia nada');
    });
});

pruebaBd('equipos: la baja pide motivo, deja auditoría, es reversible y repetirla da 409; no se borra nada', function () {
    equiposEnTransaccion(function () {
        $e = equiposEscenario();
        $servicio = new EquiposServicio();
        $catalogos = new CatalogosServicio();
        $jefe = ['id' => $e['usuario']['id'], 'rol' => 'jefe_soporte'];
        $u = $catalogos->crearUbicacion($jefe, $e['sede_id'], 'Lab 1', "baja-u-{$e['sufijo']}");
        $eq = $servicio->crearEquipo($jefe, 'baja ' . $e['sufijo'], 'computadora', $u['id'], 'media', "baja-{$e['sufijo']}");

        // Sin motivo (o en blanco) no se da de baja, y el rechazo no cambia nada.
        exigirError(fn () => $servicio->darDeBaja($jefe, $eq['id'], '   '), 422);
        exigirIgual(true, $servicio->ver($eq['id'])['activo'], 'un rechazo no cambia nada');

        $baja = $servicio->darDeBaja($jefe, $eq['id'], "  No   reparable:  placa quemada ");
        exigirIgual(false, $baja['activo']);
        exigirIgual($eq['codigo'], $baja['codigo'], 'el equipo sigue ahí');
        exigirIgual(['crear', 'dar_de_baja'], equiposAuditoria('equipo', $eq['id']));

        $st = Conexion::obtener()->prepare("SELECT motivo FROM auditoria WHERE entidad = 'equipo' AND entidad_id = ? AND accion = 'dar_de_baja'");
        $st->execute([$eq['id']]);
        exigirIgual('No reparable: placa quemada', $st->fetchColumn(), 'el motivo queda limpio en auditoría');

        // Repetir la baja: 409 y sin registro nuevo. Un equipo que no existe: 404.
        exigirError(fn () => $servicio->darDeBaja($jefe, $eq['id'], 'otra vez'), 409);
        exigirError(fn () => $servicio->darDeBaja($jefe, 999999999, 'no existe'), 404);
        exigirIgual(2, count(equiposAuditoria('equipo', $eq['id'])), 'una baja repetida no deja registro');

        // Dado de baja deja de poder elegirse, pero quien gestiona lo sigue viendo (activo: false).
        $docente = ['id' => $e['usuario']['id'], 'rol' => 'docente'];
        $filtros = ['sede' => null, 'ubicacion' => $u['id'], 'tipo' => null, 'q' => null, 'activo' => null];
        exigirIgual(0, $servicio->equipos($docente, $filtros, 1)['total'], 'un equipo de baja no se elige');
        $gestion = $servicio->equipos($jefe, $filtros, 1);
        exigirIgual(1, $gestion['total'], 'la gestión lo sigue viendo');
        exigirIgual(false, $gestion['items'][0]['activo']);

        // Un equipo de baja todavía se puede editar (docs/api/equipos.md).
        $editado = $servicio->editarEquipo($jefe, $eq['id'], ['criticidad' => 'alta'], null);
        exigirIgual(false, $editado['activo'], 'editar no lo reactiva');
    });
});

pruebaBd('equipos: reactivar devuelve el equipo a activo, el motivo es opcional y repetirlo da 409', function () {
    equiposEnTransaccion(function () {
        $e = equiposEscenario();
        $servicio = new EquiposServicio();
        $catalogos = new CatalogosServicio();
        $jefe = ['id' => $e['usuario']['id'], 'rol' => 'jefe_soporte'];
        $u = $catalogos->crearUbicacion($jefe, $e['sede_id'], 'Lab 1', "rea-u-{$e['sufijo']}");
        $eq = $servicio->crearEquipo($jefe, 'rea ' . $e['sufijo'], 'impresora', $u['id'], 'baja', "rea-{$e['sufijo']}");

        exigirError(fn () => $servicio->reactivarEquipo($jefe, $eq['id'], null), 409); // ya estaba activo

        $servicio->darDeBaja($jefe, $eq['id'], 'Sin repuestos');
        $r = $servicio->reactivarEquipo($jefe, $eq['id'], null);
        exigirIgual(true, $r['activo']);
        exigirIgual(['crear', 'dar_de_baja', 'reactivar'], equiposAuditoria('equipo', $eq['id']));

        $st = Conexion::obtener()->prepare("SELECT motivo FROM auditoria WHERE entidad = 'equipo' AND entidad_id = ? AND accion = 'reactivar'");
        $st->execute([$eq['id']]);
        exigirIgual(null, $st->fetchColumn() ?: null, 'sin motivo, la auditoría guarda NULL');

        // Con motivo: se guarda. Un motivo en blanco no se acepta.
        $servicio->darDeBaja($jefe, $eq['id'], 'Se dañó de nuevo');
        exigirError(fn () => $servicio->reactivarEquipo($jefe, $eq['id'], '  '), 422);
        exigirIgual(false, $servicio->ver($eq['id'])['activo'], 'un rechazo no cambia nada');
        $servicio->reactivarEquipo($jefe, $eq['id'], 'Reparado por el proveedor');
        exigirIgual(true, $servicio->ver($eq['id'])['activo']);

        exigirError(fn () => $servicio->reactivarEquipo($jefe, 999999999, null), 404);
    });
});

pruebaBd('equipos: fuera de servicio es derivado: solo activos con incidencia abierta, el que lleva más tiempo primero', function () {
    equiposEnTransaccion(function () {
        $e = equiposEscenario();
        $servicio = new EquiposServicio();
        $catalogos = new CatalogosServicio();
        $jefe = ['id' => $e['usuario']['id'], 'rol' => 'jefe_soporte'];
        $tecnico = $e['usuario']['id'];
        $u = $catalogos->crearUbicacion($jefe, $e['sede_id'], 'Lab 1', "fs-u-{$e['sufijo']}");
        $nuevo = fn (string $nombre, string $tipo) => $servicio->crearEquipo($jefe, "$nombre {$e['sufijo']}", $tipo, $u['id'], 'media', "fs-$nombre-{$e['sufijo']}");

        $hace3 = Reloj::ahora()->modify('-3 days');
        $hace5 = Reloj::ahora()->modify('-5 days');
        $a = $nuevo('a', 'computadora');   // dos abiertas: la más antigua, hace 3 días
        $b = $nuevo('b', 'impresora');     // una abierta, hace 5 días: lleva más tiempo, sale primero
        $c = $nuevo('c', 'computadora');   // solo cerrada: ya anda
        $d = $nuevo('d', 'computadora');   // resuelta: esperando confirmación, no cuenta como abierta
        $f = $nuevo('f', 'computadora');   // abierta pero dado de baja: ya no se repara
        $nuevo('g', 'computadora');        // sin incidencias

        equiposIncidencia($e, $a['id'], 'registrada', 'No enciende', null, $hace3);
        equiposIncidencia($e, $a['id'], 'en_atencion', 'Se apaga sola', $tecnico);
        equiposIncidencia($e, $b['id'], 'registrada', 'No imprime', null, $hace5);
        equiposIncidencia($e, $c['id'], 'cerrada', 'Pantalla azul', $tecnico, $hace5);
        equiposIncidencia($e, $d['id'], 'resuelta', 'Sin red', $tecnico, $hace5);
        equiposIncidencia($e, $f['id'], 'registrada', 'Quemada', null, $hace5);
        $servicio->darDeBaja($jefe, $f['id'], 'No reparable');

        $lista = $servicio->fueraDeServicio(['sede' => $e['sede_id'], 'ubicacion' => null, 'tipo' => null], 1);
        exigirIgual([$b['codigo'], $a['codigo']], array_column($lista['items'], 'codigo'), 'el más antiguo primero; solo activos con abierta');
        exigirIgual(2, $lista['total']);
        exigirIgual(1, $lista['pagina']);
        exigirIgual(EquiposServicio::POR_PAGINA, $lista['por_pagina']);
        exigirIgual(1, $lista['items'][0]['incidencias_abiertas']);
        exigirIgual(2, $lista['items'][1]['incidencias_abiertas']);
        exigirIgual(Reloj::iso($hace5), $lista['items'][0]['desde']);
        exigirIgual(Reloj::iso($hace3), $lista['items'][1]['desde'], 'desde = la abierta más antigua');
        exigirIgual($u['id'], $lista['items'][0]['ubicacion_id'], 'trae el equipo completo');
        exigirIgual('Lab 1', $lista['items'][0]['ubicacion'], 'el nombre de la ubicación lo pone catalogos');
        exigirIgual('media', $lista['items'][0]['criticidad'], 'trae el equipo completo');

        // Filtros: por tipo y por ubicación; página 2 vacía pero con el mismo total.
        $soloImpresoras = $servicio->fueraDeServicio(['sede' => $e['sede_id'], 'ubicacion' => null, 'tipo' => 'impresora'], 1);
        exigirIgual([$b['codigo']], array_column($soloImpresoras['items'], 'codigo'));
        exigirIgual(1, $soloImpresoras['total']);
        exigirIgual(2, $servicio->fueraDeServicio(['sede' => $e['sede_id'], 'ubicacion' => $u['id'], 'tipo' => null], 1)['total']);
        $pagina2 = $servicio->fueraDeServicio(['sede' => $e['sede_id'], 'ubicacion' => null, 'tipo' => null], 2);
        exigirIgual([], $pagina2['items']);
        exigirIgual(2, $pagina2['total']);

        // Es derivado: dar de baja saca al equipo de la lista; reactivarlo lo devuelve (su incidencia sigue abierta).
        $servicio->darDeBaja($jefe, $a['id'], 'Sin arreglo');
        exigirIgual([$b['codigo']], array_column($servicio->fueraDeServicio(['sede' => $e['sede_id'], 'ubicacion' => null, 'tipo' => null], 1)['items'], 'codigo'));
        $servicio->reactivarEquipo($jefe, $a['id'], null);
        exigirIgual(2, $servicio->fueraDeServicio(['sede' => $e['sede_id'], 'ubicacion' => null, 'tipo' => null], 1)['total']);
    });
});

pruebaBd('equipos: historial trae solo fecha, falla, solución y técnico; lo más reciente primero; también de un equipo de baja', function () {
    equiposEnTransaccion(function () {
        $e = equiposEscenario();
        $servicio = new EquiposServicio();
        $catalogos = new CatalogosServicio();
        $jefe = ['id' => $e['usuario']['id'], 'rol' => 'jefe_soporte'];
        $u = $catalogos->crearUbicacion($jefe, $e['sede_id'], 'Lab 1', "hi-u-{$e['sufijo']}");
        $eq = $servicio->crearEquipo($jefe, 'hi ' . $e['sufijo'], 'computadora', $u['id'], 'alta', "hi-{$e['sufijo']}");

        $st = Conexion::obtener()->prepare("INSERT INTO usuario (nombre, usuario, rol, creado_en) VALUES ('Marta Ruiz', ?, 'soporte_tecnico', ?) RETURNING id");
        $st->execute(["prueba_tec_{$e['sufijo']}", Reloj::paraBd()]);
        $tecnicoId = (int) $st->fetchColumn();

        $hace10 = Reloj::ahora()->modify('-10 days');
        $vieja = equiposIncidencia($e, $eq['id'], 'cerrada', 'No enciende', $tecnicoId, $hace10);
        equiposAtencion($vieja, $tecnicoId, 'Revisó los cables', null, Reloj::ahora()->modify('-9 days'));
        equiposAtencion($vieja, $tecnicoId, 'Cambió la fuente', 'reparado', Reloj::ahora()->modify('-8 days'));
        equiposIncidencia($e, $eq['id'], 'registrada', 'Se apaga sola');

        $h = $servicio->historial($eq['id'], 1);
        exigirIgual(2, $h['total']);
        exigirIgual(1, $h['pagina']);
        exigirIgual(EquiposServicio::POR_PAGINA, $h['por_pagina']);
        exigirIgual(['Se apaga sola', 'No enciende'], array_column($h['items'], 'falla'), 'la más reciente primero');

        // Exactamente fecha, falla, solución y técnico (RN-09): sin reportante, registrador ni vinculados.
        exigirIgual(['fecha', 'falla', 'solucion', 'tecnico'], array_keys($h['items'][0]));
        exigirIgual(null, $h['items'][0]['solucion'], 'sin atención todavía');
        exigirIgual(null, $h['items'][0]['tecnico'], 'sin técnico todavía');
        exigirIgual('Cambió la fuente', $h['items'][1]['solucion'], 'las acciones de la última atención');
        exigirIgual('Marta Ruiz', $h['items'][1]['tecnico']);
        exigirIgual(Reloj::iso($hace10), $h['items'][1]['fecha']);

        exigirIgual([], $servicio->historial($eq['id'], 2)['items'], 'pasada la última página no hay items');
        exigirIgual(2, $servicio->historial($eq['id'], 2)['total']);

        $servicio->darDeBaja($jefe, $eq['id'], 'No reparable');
        exigirIgual(2, $servicio->historial($eq['id'], 1)['total'], 'un equipo de baja conserva su historial (RN-07)');

        exigirError(fn () => $servicio->historial(999999999, 1), 404);
    });
});