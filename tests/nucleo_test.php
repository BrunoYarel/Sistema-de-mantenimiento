<?php
// tests/nucleo_test.php
declare(strict_types=1);

/** Piezas compartidas de nucleo/ que antes se copiaban en cada módulo (no necesitan base de datos). */

use Nucleo\Cambios;
use Nucleo\Errores;
use Nucleo\Paginacion;
use Nucleo\Texto;
use Nucleo\Validar;

prueba('Texto::espacios: un solo espacio entre palabras y sin espacios en los bordes', function () {
    exigirIgual('Ana María Pérez', Texto::espacios("  Ana   María\tPérez "));
    exigirIgual('', Texto::espacios("  \t "));
});

prueba('Texto::exigir: devuelve el texto limpio o 422 en ese campo si queda vacío', function () {
    exigirIgual('Falla de red', Texto::exigir('motivo', '  Falla   de red '));
    try {
        Texto::exigir('motivo', '   ');
        throw new RuntimeException('Se esperaba un 422');
    } catch (Nucleo\ErrorApi $e) {
        exigirIgual(422, $e->estado);
        exigirIgual(['motivo' => 'Obligatorio.'], $e->campos);
    }
});

prueba('Texto::patronBusqueda: minúsculas, sin tildes y comodines de LIKE escapados', function () {
    exigirIgual('%perez%', Texto::patronBusqueda('  PÉREZ '));
    exigirIgual('%100\%%', Texto::patronBusqueda('100%'));
    exigirIgual('%a\_b%', Texto::patronBusqueda('a_b'));
});

prueba('Paginacion::desdeQuery: por defecto 1; fuera de rango es un error de campo', function () {
    exigirIgual(1, Paginacion::desdeQuery(new Validar([])));
    exigirIgual(3, Paginacion::desdeQuery(new Validar(['pagina' => '3'])));
    foreach (['0', '-1', (string) (Paginacion::MAX_PAGINA + 1)] as $malo) {
        $v = new Validar(['pagina' => $malo]);
        Paginacion::desdeQuery($v);
        exigirError(fn () => $v->exigir(), 422);
    }
});

prueba('Paginacion: desplazamiento y forma de la respuesta', function () {
    exigirIgual(0, Paginacion::desplazamiento(1));
    exigirIgual(2 * Paginacion::POR_PAGINA, Paginacion::desplazamiento(3));
    exigirIgual(
        ['items' => [['id' => 1]], 'total' => 40, 'pagina' => 2, 'por_pagina' => Paginacion::POR_PAGINA],
        Paginacion::respuesta([['id' => 1]], 40, 2)
    );
});

prueba('Cambios: lo que llega igual que está no cuenta como cambio', function () {
    $c = new Cambios();
    exigirIgual(false, $c->campo('nombre', 'Ana', 'Ana', 'cambiar_nombre'));
    exigirIgual(false, $c->hay());
    exigirIgual([], $c->nuevos());
});

prueba('Cambios: acumula columnas nuevas, una o varias por registro', function () {
    $c = new Cambios();
    exigirIgual(true, $c->campo('rol', 'docente', 'admin', 'cambiar_rol'));
    exigirIgual(false, $c->campo('sede_id', 1, 1, 'cambiar_sede'));
    $c->varias(['ubicacion_id' => 7, 'sede_id' => 2], 'cambiar_ubicacion', ['antes' => 1, 'despues' => 7]);
    exigirIgual(true, $c->hay());
    exigirIgual(['rol' => 'admin', 'ubicacion_id' => 7, 'sede_id' => 2], $c->nuevos());
});

prueba('Cambios::auditar: exige estar dentro de una transacción', function () {
    $c = new Cambios();
    $c->campo('rol', 'docente', 'admin', 'cambiar_rol');
    try {
        $c->auditar(1, 'usuario', 1);
        throw new RuntimeException('Se esperaba un LogicException');
    } catch (LogicException) {
        // correcto: Auditoria::registrar solo se llama dentro de Conexion::transaccion()
    }
});

prueba('Errores::deRestriccion: traduce solo las restricciones del mapa', function () {
    $e = new PDOException('duplicate key value violates unique constraint "equipo_codigo_key"');
    $e->errorInfo = ['23505', 7, 'duplicate key value violates unique constraint "equipo_codigo_key"'];

    $mapa = ['equipo_codigo_key' => ['codigo', 'Ya existe un equipo con ese código.']];
    $error = Errores::deRestriccion($e, $mapa);
    exigir($error !== null, 'debía traducirse');
    exigirIgual(422, $error->estado);
    exigirIgual(['codigo' => 'Ya existe un equipo con ese código.'], $error->campos);

    exigirIgual(null, Errores::deRestriccion($e, ['otra_restriccion' => ['x', 'y']]));

    $otro = new PDOException('connection lost "equipo_codigo_key"');
    $otro->errorInfo = ['08006', 7, 'connection lost'];
    exigirIgual(null, Errores::deRestriccion($otro, $mapa));
});

prueba('Errores::modificadoPorOtro: 409 con el sujeto en el mensaje', function () {
    $e = Errores::modificadoPorOtro('el equipo');
    exigirIgual(409, $e->estado);
    exigir(str_contains($e->getMessage(), 'el equipo'));
});
