<?php
// bin/respaldo.php
declare(strict_types=1);

/**
 * Respaldo y restauración con las herramientas de PostgreSQL (RNF-03).
 *
 *   php bin/respaldo.php crear                          -> backend/storage/respaldos/caso_a-AAAAMMDD-HHMMSS.dump
 *   php bin/respaldo.php restaurar <archivo> <base>     restaura en una base NUEVA y vacía (créala antes)
 *
 * Programa "crear" cada semana (Programador de tareas de Windows o cron) y PRUEBA restaurar:
 * un respaldo que nunca se restauró no es un respaldo. Requiere pg_dump y pg_restore en el PATH.
 * Se conecta como dueño del esquema (BD_MIGRACION_*).
 */
require __DIR__ . '/../backend/nucleo/autoload.php';

use Nucleo\Aplicacion;
use Nucleo\Config;
use Nucleo\Reloj;

Aplicacion::iniciarCli();

/** @param list<string> $comando */
function ejecutar(array $comando): int
{
    $entorno = array_merge(getenv(), ['PGPASSWORD' => Config::get('BD_MIGRACION_CLAVE', '')]);
    $proceso = proc_open($comando, [1 => STDOUT, 2 => STDERR], $tuberias, null, $entorno);
    if (!is_resource($proceso)) {
        fwrite(STDERR, "No se pudo ejecutar {$comando[0]}. ¿Está en el PATH?\n");
        return 1;
    }
    return proc_close($proceso);
}

$comando = $argv[1] ?? '';
$conexion = [
    '-h', (string) Config::get('BD_HOST', 'localhost'),
    '-p', (string) Config::get('BD_PUERTO', '5432'),
    '-U', Config::requerido('BD_MIGRACION_USUARIO'),
];

if ($comando === 'crear') {
    $dir = dirname(__DIR__) . '/backend/storage/respaldos';
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $archivo = $dir . '/caso_a-' . Reloj::ahora()->format('Ymd-His') . '.dump';
    $codigo = ejecutar(array_merge(['pg_dump'], $conexion, ['-Fc', '-f', $archivo, Config::requerido('BD_NOMBRE')]));
    echo $codigo === 0 ? "Respaldo creado: $archivo\n" : "El respaldo falló.\n";
    exit($codigo);
}

if ($comando === 'restaurar') {
    $archivo = $argv[2] ?? '';
    $base = $argv[3] ?? '';
    if (!is_file($archivo) || $base === '') {
        fwrite(STDERR, "Uso: php bin/respaldo.php restaurar <archivo.dump> <base_nueva>\n");
        exit(2);
    }
    $codigo = ejecutar(array_merge(['pg_restore'], $conexion, ['--no-owner', '-d', $base, $archivo]));
    echo $codigo === 0 ? "Restauración completa en la base $base.\n" : "La restauración terminó con errores.\n";
    exit($codigo);
}

fwrite(STDERR, "Comandos: crear, restaurar\n");
exit(2);
