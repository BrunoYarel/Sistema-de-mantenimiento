<?php
// tests/humo_evento_test.php
declare(strict_types=1);

use Nucleo\Conexion;
use Nucleo\Errores;

/**
 * Pruebas de humo contra la base real, con el usuario de la APLICACIÓN (no el dueño).
 * Comprueban lo que la base debe hacer cumplir sola: `evento` no se modifica (RN-07, RNF-06).
 */
function sqlstateDe(callable $fn): ?string
{
    try {
        $fn();
    } catch (PDOException $e) {
        return Errores::sqlstate($e);
    }
    return null;
}

pruebaBd('el usuario de la app NO puede hacer UPDATE sobre evento (42501)', function () {
    $pdo = Conexion::obtener();
    exigirIgual('42501', sqlstateDe(fn () => $pdo->exec('UPDATE evento SET accion = accion WHERE false')));
});

pruebaBd('el usuario de la app NO puede hacer DELETE sobre evento (42501)', function () {
    $pdo = Conexion::obtener();
    exigirIgual('42501', sqlstateDe(fn () => $pdo->exec('DELETE FROM evento WHERE false')));
});

pruebaBd('el usuario de la app NO puede hacer TRUNCATE sobre evento (42501)', function () {
    $pdo = Conexion::obtener();
    exigirIgual('42501', sqlstateDe(fn () => $pdo->exec('TRUNCATE evento')));
});

pruebaBd('rowCount de un UPDATE: 0 = conflicto real, mismo valor = 1 (no hace falta FOUND_ROWS)', function () {
    $pdo = Conexion::obtener();
    $pdo->exec('CREATE TEMP TABLE t_humo (id int, v int)');
    $pdo->exec('INSERT INTO t_humo VALUES (1, 10)');
    exigirIgual(1, $pdo->exec('UPDATE t_humo SET v = 10 WHERE id = 1'), 'mismo valor');
    exigirIgual(0, $pdo->exec('UPDATE t_humo SET v = 10 WHERE id = 2'), 'fila inexistente');
    $pdo->exec('DROP TABLE t_humo');
});
