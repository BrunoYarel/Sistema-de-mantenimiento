<?php
// tests/usuarios_test.php
declare(strict_types=1);

use Modulos\Auth\Servicio as AuthServicio;
use Modulos\Usuarios\Servicio as UsuariosServicio;
use Nucleo\Conexion;
use Nucleo\ErrorApi;
use Nucleo\Politica;
use Nucleo\Reloj;
use Nucleo\Router;

/**
 * Gestión de usuarios (RF-12): alta con clave temporal, edición, desactivar y reactivar, restablecer clave y desbloquear.
 *  - Sin base de datos: normalización y el cableado de las rutas (permiso, idempotencia, 201).
 *  - Con base de datos (--con-bd): el Servicio real, dentro de una transacción que SIEMPRE se deshace.
 *    Solo se provocan errores que el Servicio ataja antes de tocar la base: un error de PostgreSQL dejaría
 *    abortada la transacción de la prueba.
 */

/** Se lanza al final de cada prueba con base de datos para deshacer todo lo que escribió. */
final class UsuariosRevertir extends Exception
{
}

/** Corre $fn dentro de una transacción y la deshace siempre. Si $fn falla, también se deshace y el fallo sigue su camino. */
function usuariosEnTransaccion(callable $fn): void
{
    try {
        Conexion::transaccion(static function () use ($fn): void {
            $fn();
            throw new UsuariosRevertir();
        });
    } catch (UsuariosRevertir) {
        // lo esperado: no queda nada en la base
    }
}

/** Inserta un usuario como lo haría una carga: $claveHash null = usuario de sistema (sin credenciales). */
function usuariosInsertar(string $usuario, string $rol, ?string $claveHash = 'no-es-un-hash-valido', bool $activo = true): int
{
    $st = Conexion::obtener()->prepare(
        'INSERT INTO usuario (nombre, usuario, clave_hash, rol, activo, creado_en) VALUES (?, ?, ?, ?, ?, ?) RETURNING id'
    );
    $st->execute(['Prueba', $usuario, $claveHash, $rol, $activo ? 'true' : 'false', Reloj::paraBd()]);
    return (int) $st->fetchColumn();
}

/** Quien gestiona (un jefe de informática activo) y un sufijo para que ningún nombre choque con datos reales. */
function usuariosEscenario(): array
{
    $sufijo = bin2hex(random_bytes(4));
    $id = usuariosInsertar("prueba_jefe_$sufijo", 'jefe_informatica');
    return ['actor' => ['id' => $id, 'rol' => 'jefe_informatica'], 'sufijo' => $sufijo];
}

/** Deja a $exceptoId como único gestor activo de la base (dentro de la transacción de la prueba, que se deshace). */
function usuariosDejarUnSoloGestor(int $exceptoId): void
{
    $roles = Politica::rolesCon('usuario.gestionar');
    $st = Conexion::obtener()->prepare(
        'UPDATE usuario SET activo = FALSE WHERE id <> ? AND rol IN (' . implode(', ', array_fill(0, count($roles), '?')) . ')'
    );
    $st->execute([$exceptoId, ...$roles]);
}

/** @return list<string> acciones de auditoría de ese usuario, en el orden en que se registraron */
function usuariosAuditoria(int $id): array
{
    $st = Conexion::obtener()->prepare("SELECT accion FROM auditoria WHERE entidad = 'usuario' AND entidad_id = ? ORDER BY id");
    $st->execute([$id]);
    return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Todo el detalle (datos) que la auditoría guardó de ese usuario, como texto: sirve para comprobar que no hay claves. */
function usuariosAuditoriaTexto(int $id): string
{
    $st = Conexion::obtener()->prepare("SELECT coalesce(string_agg(datos::text || coalesce(motivo, ''), ' '), '') FROM auditoria WHERE entidad = 'usuario' AND entidad_id = ?");
    $st->execute([$id]);
    return (string) $st->fetchColumn();
}

function usuariosHash(int $id): string
{
    $st = Conexion::obtener()->prepare('SELECT clave_hash FROM usuario WHERE id = ?');
    $st->execute([$id]);
    return (string) $st->fetchColumn();
}

/**
 * Una incidencia en ese estado asignada a ese técnico, con su sede, ubicación y equipo propios: el módulo incidencias aún
 * no escribe, así que el escenario se arma a mano. resuelta y cerrada llevan sus fechas (los CHECK de la base).
 */
function usuariosIncidencia(int $tecnicoId, int $reportanteId, string $estado): int
{
    $pdo = Conexion::obtener();
    $sufijo = bin2hex(random_bytes(4));
    $fecha = Reloj::paraBd();

    $sede = $pdo->prepare('INSERT INTO sede (nombre) VALUES (?) RETURNING id');
    $sede->execute(["__prueba_$sufijo"]);
    $sedeId = (int) $sede->fetchColumn();

    $ubicacion = $pdo->prepare('INSERT INTO ubicacion (sede_id, nombre) VALUES (?, ?) RETURNING id');
    $ubicacion->execute([$sedeId, 'Aula']);
    $ubicacionId = (int) $ubicacion->fetchColumn();

    $equipo = $pdo->prepare(
        "INSERT INTO equipo (codigo, tipo, sede_id, ubicacion_id, criticidad, creado_en) VALUES (?, 'computadora', ?, ?, 'baja', ?) RETURNING id"
    );
    $equipo->execute(['PRUEBA-' . strtoupper($sufijo), $sedeId, $ubicacionId, $fecha]);
    $equipoId = (int) $equipo->fetchColumn();

    $incidencia = $pdo->prepare(
        "INSERT INTO incidencia (equipo_id, sede_id, reportante_id, registrador_id, canal, descripcion, prioridad, estado, tecnico_id,
                                 fecha_registro, fecha_resuelta, fecha_cierre, fecha_ultimo_cambio)
         VALUES (?, ?, ?, ?, 'sistema', 'No enciende', 'media', ?, ?, ?, ?, ?, ?) RETURNING id"
    );
    $incidencia->execute([
        $equipoId, $sedeId, $reportanteId, $reportanteId, $estado, $tecnicoId, $fecha,
        in_array($estado, ['resuelta', 'cerrada'], true) ? $fecha : null,
        $estado === 'cerrada' ? $fecha : null,
        $fecha,
    ]);
    return (int) $incidencia->fetchColumn();
}

/** El ErrorApi que lanza $fn; falla si no lanza ninguno. */
function usuariosErrorDe(callable $fn): ErrorApi
{
    try {
        $fn();
    } catch (ErrorApi $e) {
        return $e;
    }
    throw new RuntimeException('Se esperaba un ErrorApi y no se lanzó nada');
}

/** @param array{items:list<array<string,mixed>>} $lista */
function usuariosItem(array $lista, int $id): ?array
{
    foreach ($lista['items'] as $item) {
        if ($item['id'] === $id) {
            return $item;
        }
    }
    return null;
}

/** @return array{q:?string,rol:?string,sede:?int,activo:?bool} */
function usuariosFiltros(?string $q = null, ?bool $activo = null): array
{
    return ['q' => $q, 'rol' => null, 'sede' => null, 'activo' => $activo];
}

// ---------------------------------------------------------------- sin base de datos

prueba('normalizarUsuario: minúsculas y sin espacios en los bordes; normalizarNombre: un solo espacio entre palabras', function () {
    exigirIgual('ana.perez', UsuariosServicio::normalizarUsuario('  Ana.Perez '));
    exigirIgual('Ana María Pérez', UsuariosServicio::normalizarNombre("  Ana   María\tPérez "));
    exigirIgual('', UsuariosServicio::normalizarNombre("  \t "));
});

prueba('clave temporal: 14 caracteres letras y números, distinta cada vez y que cumple el mínimo de auth', function () {
    $a = AuthServicio::claveTemporal();
    $b = AuthServicio::claveTemporal();
    exigirIgual(14, strlen($a));
    exigir(preg_match('/^[A-Za-z0-9]{14}$/', $a) === 1, "la clave temporal tiene caracteres raros: $a");
    exigir($a !== $b, 'dos claves temporales seguidas salieron iguales');
    exigir(strlen($a) >= AuthServicio::CLAVE_MINIMO, 'la clave temporal no cumpliría el mínimo de auth');
});

prueba('rutas de usuarios: la gestión pide usuario.gestionar; solo el alta exige Idempotency-Key y da 201', function () {
    $router = new Router();
    (require __DIR__ . '/../backend/modulos/usuarios/rutas.php')($router);
    $rutas = (fn (): array => $this->rutas)->call($router);

    // ruta => [permiso, ¿idempotente?, código de éxito]
    $esperadas = [
        'GET /usuarios/tecnicos' => ['incidencia.asignar', false, 200],
        'GET /usuarios/reportantes' => ['incidencia.registrar_a_nombre', false, 200],
        'GET /usuarios' => ['usuario.gestionar', false, 200],
        'GET /usuarios/{id}' => ['usuario.gestionar', false, 200],
        'POST /usuarios' => ['usuario.gestionar', true, 201],
        'PATCH /usuarios/{id}' => ['usuario.gestionar', false, 200],
        'POST /usuarios/{id}/desactivar' => ['usuario.gestionar', false, 200],
        'POST /usuarios/{id}/reactivar' => ['usuario.gestionar', false, 200],
        'POST /usuarios/{id}/restablecer-clave' => ['usuario.gestionar', false, 200],
        'POST /usuarios/{id}/desbloquear' => ['usuario.gestionar', false, 200],
    ];
    foreach ($esperadas as $ruta => [$permiso, $idempotente, $estado]) {
        exigir(isset($rutas[$ruta]), "Falta la ruta $ruta");
        $opc = $rutas[$ruta]['opc'];
        exigirIgual($permiso, $opc['permiso'], "permiso de $ruta");
        exigirIgual($idempotente, !empty($opc['idempotente']), "idempotencia de $ruta");
        exigirIgual($estado, (int) ($opc['estado'] ?? 200), "estado de $ruta");
    }
    exigirIgual(count($esperadas), count($rutas), 'hay rutas de usuarios que esta prueba no conoce: agrégalas arriba');
});

// ---------------------------------------------------------------- con base de datos

pruebaBd('usuarios: alta con clave temporal una sola vez, idempotente y sin la clave en la auditoría', function () {
    usuariosEnTransaccion(function () {
        $e = usuariosEscenario();
        $servicio = new UsuariosServicio();
        $nombreUsuario = 'prueba_nuevo_' . $e['sufijo'];

        $u = $servicio->crear($e['actor'], '  Ana   Pérez ', strtoupper($nombreUsuario), 'docente', null, "alta-usu-{$e['sufijo']}");
        exigirIgual('Ana Pérez', $u['nombre'], 'nombre normalizado');
        exigirIgual($nombreUsuario, $u['usuario'], 'usuario en minúsculas');
        exigirIgual('docente', $u['rol']);
        exigirIgual(null, $u['sede_id'], 'la sede por defecto es opcional');
        exigirIgual(true, $u['activo']);
        exigirIgual(true, $u['debe_cambiar_clave'], 'nace obligado a cambiar la clave');
        exigirIgual(false, $u['bloqueado']);
        $claves = array_keys($u);
        sort($claves);
        exigirIgual(['activo', 'bloqueado', 'clave_temporal', 'debe_cambiar_clave', 'id', 'nombre', 'rol', 'sede_id', 'usuario'], $claves, 'forma de la respuesta (sin hash)');

        exigir(strlen($u['clave_temporal']) >= AuthServicio::CLAVE_MINIMO, 'la clave temporal debe cumplir el mínimo');
        exigir(password_verify($u['clave_temporal'], usuariosHash($u['id'])), 'la clave temporal debe abrir la cuenta');

        $reintento = $servicio->crear($e['actor'], 'Ana Pérez', $nombreUsuario, 'docente', null, "alta-usu-{$e['sufijo']}");
        exigirIgual($u['id'], $reintento['id'], 'el reintento con la misma clave no duplica');
        exigirIgual('', $reintento['clave_temporal'], 'el reintento NO repite la clave: se usa restablecer-clave');
        exigirIgual(['crear'], usuariosAuditoria($u['id']));
        exigir(!str_contains(usuariosAuditoriaTexto($u['id']), $u['clave_temporal']), 'la auditoría no debe guardar la clave');

        exigirError(fn () => $servicio->crear($e['actor'], 'Otra', $nombreUsuario, 'docente', null, "alta-usu-2-{$e['sufijo']}"), 422);
        exigirError(fn () => $servicio->crear($e['actor'], 'Otra', 'Ñandú', 'docente', null, "alta-usu-3-{$e['sufijo']}"), 422);
        exigirError(fn () => $servicio->crear($e['actor'], 'Otra', 'ab', 'docente', null, "alta-usu-4-{$e['sufijo']}"), 422);
        exigirError(fn () => $servicio->crear($e['actor'], 'Otra', 'otra_' . $e['sufijo'], 'docente', 999999999, "alta-usu-5-{$e['sufijo']}"), 422);
    });
});

pruebaBd('usuarios: ver un usuario devuelve la misma forma que la lista y 404 si no existe', function () {
    usuariosEnTransaccion(function () {
        $e = usuariosEscenario();
        $servicio = new UsuariosServicio();
        $u = $servicio->crear($e['actor'], 'Rosa Vega', 'prueba_ver_' . $e['sufijo'], 'docente', null, "ver-{$e['sufijo']}");

        $visto = $servicio->usuario($u['id']);
        unset($u['clave_temporal']);
        exigirIgual($u, $visto, 'ver devuelve lo mismo que el alta (sin la clave temporal)');
        exigirIgual(usuariosItem($servicio->usuarios(usuariosFiltros('prueba_ver_' . $e['sufijo']), 1), $u['id']), $visto, 'y lo mismo que la lista');
        exigirError(fn () => $servicio->usuario(999999999), 404);
    });
});

pruebaBd('usuarios: editar deja un registro por cambio, no cambia el nombre de usuario y quita la sede por defecto', function () {
    usuariosEnTransaccion(function () {
        $e = usuariosEscenario();
        $servicio = new UsuariosServicio();
        $u = $servicio->crear($e['actor'], 'Luis Soto', 'prueba_ed_' . $e['sufijo'], 'docente', null, "ed-{$e['sufijo']}");

        $sede = Conexion::obtener()->prepare('INSERT INTO sede (nombre) VALUES (?) RETURNING id');
        $sede->execute(["__prueba_{$e['sufijo']}"]);
        $sedeId = (int) $sede->fetchColumn();

        $r = $servicio->editar($e['actor'], $u['id'], ['nombre' => '  Luis   Soto Ríos ', 'rol' => 'admin', 'sede_id' => $sedeId], null);
        exigirIgual('Luis Soto Ríos', $r['nombre']);
        exigirIgual('admin', $r['rol']);
        exigirIgual($sedeId, $r['sede_id']);
        exigirIgual($u['usuario'], $r['usuario'], 'el nombre de usuario no cambia');

        $acciones = usuariosAuditoria($u['id']);
        sort($acciones);
        exigirIgual(['cambiar_nombre', 'cambiar_rol', 'cambiar_sede', 'crear'], $acciones, 'un registro por cambio');

        // Lo que llega igual que está no escribe nada; el nombre de usuario, escrito de otra forma pero igual, se acepta.
        $servicio->editar($e['actor'], $u['id'], ['rol' => 'admin', 'sede_id' => $sedeId], strtoupper($u['usuario']));
        exigirIgual(4, count(usuariosAuditoria($u['id'])), 'sin cambio real no hay registro nuevo');

        $sinSede = $servicio->editar($e['actor'], $u['id'], ['sede_id' => null], null);
        exigirIgual(null, $sinSede['sede_id'], 'sede_id null quita la sede por defecto');
        exigirIgual(5, count(usuariosAuditoria($u['id'])));

        exigirError(fn () => $servicio->editar($e['actor'], $u['id'], ['rol' => 'docente'], 'otro_usuario'), 422);
        exigirError(fn () => $servicio->editar($e['actor'], $u['id'], ['sede_id' => 999999999], null), 422);
        exigirError(fn () => $servicio->editar($e['actor'], 999999999, ['rol' => 'docente'], null), 404);
        exigirIgual('admin', $servicio->usuarios(usuariosFiltros($u['usuario']), 1)['items'][0]['rol'], 'un rechazo no cambia nada');
    });
});

pruebaBd('usuarios: un usuario de sistema (sin credenciales) no aparece en la lista ni se puede gestionar', function () {
    usuariosEnTransaccion(function () {
        $e = usuariosEscenario();
        $servicio = new UsuariosServicio();
        $nombreSistema = 'prueba_sistema_' . $e['sufijo'];
        $sistema = usuariosInsertar($nombreSistema, 'jefe_soporte', null, false);

        exigirIgual(0, $servicio->usuarios(usuariosFiltros($nombreSistema), 1)['total'], 'no aparece en la lista');
        exigirError(fn () => $servicio->editar($e['actor'], $sistema, ['rol' => 'docente'], null), 404);
        exigirError(fn () => $servicio->reactivar($e['actor'], $sistema), 404);
        exigirError(fn () => $servicio->restablecerClave($e['actor'], $sistema), 404);
        exigirError(fn () => $servicio->desbloquear($e['actor'], $sistema), 404);
        exigirError(fn () => $servicio->desactivar($e['actor'], $sistema), 404);
        exigirError(fn () => $servicio->usuario($sistema), 404);
        $hash = Conexion::obtener()->prepare('SELECT clave_hash FROM usuario WHERE id = ?');
        $hash->execute([$sistema]);
        exigirIgual(null, $hash->fetchColumn(), 'sigue sin clave y sin poder entrar');
    });
});

pruebaBd('usuarios: desactivar y reactivar dejan registro, no se repiten y nadie se desactiva a sí mismo; la lista filtra', function () {
    usuariosEnTransaccion(function () {
        $e = usuariosEscenario();
        $servicio = new UsuariosServicio();
        $u = $servicio->crear($e['actor'], "Zoé Pérez {$e['sufijo']}", 'prueba_des_' . $e['sufijo'], 'docente', null, "des-{$e['sufijo']}");
        $busqueda = "zoe perez {$e['sufijo']}"; // sin tildes ni mayúsculas: encuentra "Zoé Pérez ..."

        $lista = $servicio->usuarios(usuariosFiltros($busqueda, true), 1);
        exigirIgual(25, $lista['por_pagina']);
        exigirIgual(1, $lista['pagina']);
        exigir(usuariosItem($lista, $u['id']) !== null, 'el usuario activo debe estar en la lista de activos');

        $d = $servicio->desactivar($e['actor'], $u['id']);
        exigirIgual(false, $d['activo']);
        exigirIgual(['crear', 'desactivar'], usuariosAuditoria($u['id']));
        exigirError(fn () => $servicio->desactivar($e['actor'], $u['id']), 409);
        exigirError(fn () => $servicio->desactivar($e['actor'], $e['actor']['id']), 403);
        exigirError(fn () => $servicio->desactivar($e['actor'], 999999999), 404);

        exigirIgual(null, usuariosItem($servicio->usuarios(usuariosFiltros($busqueda, true), 1), $u['id']), 'ya no está entre los activos');
        exigir(usuariosItem($servicio->usuarios(usuariosFiltros($busqueda, false), 1), $u['id']) !== null, 'sí está entre los inactivos');
        exigirIgual([], (new UsuariosServicio())->reportantes($busqueda), 'un desactivado no se ofrece como reportante');

        $r = $servicio->reactivar($e['actor'], $u['id']);
        exigirIgual(true, $r['activo']);
        exigirIgual(['crear', 'desactivar', 'reactivar'], usuariosAuditoria($u['id']));
        exigirError(fn () => $servicio->reactivar($e['actor'], $u['id']), 409);
    });
});

pruebaBd('usuarios: nunca se queda el sistema sin quien gestione usuarios', function () {
    usuariosEnTransaccion(function () {
        $e = usuariosEscenario();
        $servicio = new UsuariosServicio();
        usuariosDejarUnSoloGestor($e['actor']['id']);
        $otro = ['id' => usuariosInsertar("prueba_otro_{$e['sufijo']}", 'jefe_soporte'), 'rol' => 'jefe_soporte'];

        // Es el único gestor: ni él puede dejar de serlo, ni otra persona desactivarlo.
        exigirError(fn () => $servicio->editar($e['actor'], $e['actor']['id'], ['rol' => 'docente'], null), 409);
        exigirError(fn () => $servicio->desactivar($otro, $e['actor']['id']), 409);
        exigirIgual('jefe_informatica', $servicio->usuarios(usuariosFiltros("prueba_jefe_{$e['sufijo']}"), 1)['items'][0]['rol'], 'un rechazo no cambia nada');

        // Con un segundo gestor sí se puede; y entonces el segundo pasa a ser el único.
        $segundo = $servicio->crear($e['actor'], 'Segundo Jefe', "prueba_segundo_{$e['sufijo']}", 'jefe_informatica', null, "gestor-{$e['sufijo']}");
        $r = $servicio->editar($e['actor'], $e['actor']['id'], ['rol' => 'docente'], null);
        exigirIgual('docente', $r['rol']);
        exigirIgual(['cambiar_rol'], array_slice(usuariosAuditoria($e['actor']['id']), 0, 1), 'el cambio de rol quedó en auditoría');
        exigirError(fn () => $servicio->desactivar($otro, $segundo['id']), 409);
    });
});

pruebaBd('usuarios: restablecer clave deja otra temporal, invalida la anterior, obliga a cambiarla y no deja la clave en la auditoría', function () {
    usuariosEnTransaccion(function () {
        $e = usuariosEscenario();
        $servicio = new UsuariosServicio();
        $u = $servicio->crear($e['actor'], 'Rosa Vega', 'prueba_rst_' . $e['sufijo'], 'admin', null, "rst-{$e['sufijo']}");
        $anterior = $u['clave_temporal'];

        // Simula que la persona ya la cambió.
        Conexion::obtener()->exec("UPDATE usuario SET debe_cambiar_clave = FALSE WHERE id = {$u['id']}");

        $r = $servicio->restablecerClave($e['actor'], $u['id']);
        exigir(strlen($r['clave_temporal']) >= AuthServicio::CLAVE_MINIMO, 'la clave nueva debe cumplir el mínimo');
        exigir($r['clave_temporal'] !== $anterior, 'la clave nueva debe ser otra');
        exigirIgual(true, $r['debe_cambiar_clave'], 'obliga a cambiarla al entrar');
        exigir(password_verify($r['clave_temporal'], usuariosHash($u['id'])), 'la clave nueva abre la cuenta');
        exigir(!password_verify($anterior, usuariosHash($u['id'])), 'la clave anterior ya no vale');
        exigirIgual(['crear', 'restablecer_clave'], usuariosAuditoria($u['id']));
        exigir(!str_contains(usuariosAuditoriaTexto($u['id']), $r['clave_temporal']), 'la auditoría no debe guardar la clave');

        exigirError(fn () => $servicio->restablecerClave($e['actor'], 999999999), 404);
    });
});

pruebaBd('usuarios: desbloquear libera el login, se ve en la lista y sin nada que liberar no deja registro', function () {
    usuariosEnTransaccion(function () {
        $e = usuariosEscenario();
        $servicio = new UsuariosServicio();
        $u = $servicio->crear($e['actor'], 'Pedro Gil', 'prueba_blq_' . $e['sufijo'], 'docente', null, "blq-{$e['sufijo']}");

        $bloquear = Conexion::obtener()->prepare(
            "INSERT INTO bloqueo_login (usuario, ip, fallos, ultimo_fallo, bloqueado_hasta) VALUES (?, '10.0.0.1', 5, ?, ?)"
        );
        $bloquear->execute([$u['usuario'], Reloj::paraBd(), Reloj::paraBd(Reloj::ahora()->modify('+15 minutes'))]);

        $enLista = usuariosItem($servicio->usuarios(usuariosFiltros($u['usuario']), 1), $u['id']);
        exigirIgual(true, $enLista['bloqueado'] ?? null, 'la lista avisa que está bloqueado');

        $r = $servicio->desbloquear($e['actor'], $u['id']);
        exigirIgual(false, $r['bloqueado']);
        exigirIgual(['crear', 'desbloquear'], usuariosAuditoria($u['id']));

        $servicio->desbloquear($e['actor'], $u['id']);
        exigirIgual(['crear', 'desbloquear'], usuariosAuditoria($u['id']), 'sin nada que liberar no hay registro nuevo');
        exigirError(fn () => $servicio->desbloquear($e['actor'], 999999999), 404);
    });
});

pruebaBd('usuarios: RN-02, un técnico con incidencias abiertas no se desactiva ni deja de ser técnico (409 con la lista)', function () {
    usuariosEnTransaccion(function () {
        $e = usuariosEscenario();
        $servicio = new UsuariosServicio();
        $t = $servicio->crear($e['actor'], 'Luis Soto', 'prueba_tec_' . $e['sufijo'], 'soporte_tecnico', null, "tec-{$e['sufijo']}");
        $incidenciaId = usuariosIncidencia($t['id'], $e['actor']['id'], 'en_atencion');

        // Desactivar: 409 con la lista de lo que hay que reasignar.
        $err = usuariosErrorDe(fn () => $servicio->desactivar($e['actor'], $t['id']));
        exigirIgual(409, $err->estado);
        exigirIgual('tecnico_con_incidencias', $err->codigo);
        exigirIgual(1, count($err->datos['incidencias'] ?? []), 'la lista trae la incidencia abierta');
        $item = $err->datos['incidencias'][0];
        exigirIgual($incidenciaId, $item['id']);
        exigirIgual('en_atencion', $item['estado']);
        exigirIgual('media', $item['prioridad']);
        exigir(str_starts_with($item['equipo'], 'PRUEBA-'), 'trae el código del equipo');

        // Dejar de ser técnico: lo mismo. Pasar a otro rol que también atiende está permitido.
        exigirIgual('tecnico_con_incidencias', usuariosErrorDe(fn () => $servicio->editar($e['actor'], $t['id'], ['rol' => 'docente'], null))->codigo);
        exigirIgual('soporte_informatica', $servicio->editar($e['actor'], $t['id'], ['rol' => 'soporte_informatica'], null)['rol']);
        exigirIgual(true, $servicio->usuario($t['id'])['activo'], 'un rechazo no cambia nada');

        // Resuelta ya no es abierta (RF-11): sin incidencias abiertas, se puede desactivar.
        $st = Conexion::obtener()->prepare('UPDATE incidencia SET estado = ?, fecha_resuelta = ?, fecha_ultimo_cambio = ? WHERE id = ?');
        $st->execute(['resuelta', Reloj::paraBd(), Reloj::paraBd(), $incidenciaId]);
        exigirIgual(false, $servicio->desactivar($e['actor'], $t['id'])['activo']);
        exigirIgual(['crear', 'cambiar_rol', 'desactivar'], usuariosAuditoria($t['id']), 'los rechazos no dejan registro');

        // Nombres para otros módulos: también de quien ya no está activo; sin repetidos ni ids que no existen.
        exigirIgual([$t['id'] => 'Luis Soto'], $servicio->nombres([$t['id'], $t['id'], 999999999]));
        exigirIgual([], $servicio->nombres([]));
    });
});
