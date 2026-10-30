<?php
// tests/ejecutar.php
declare(strict_types=1);

/**
 * Corre todas las pruebas tests/*_test.php.
 *
 *   php tests/ejecutar.php              pruebas sin base de datos
 *   php tests/ejecutar.php --con-bd     todas (necesita PostgreSQL migrado, con grants y backend/.env)
 *
 * Sale con código 1 si algo falla (lo usa el PR). Las omitidas se muestran: no cuentan como éxito silencioso.
 */
require __DIR__ . '/../backend/nucleo/autoload.php';
require __DIR__ . '/lib.php';

Nucleo\Aplicacion::iniciarCli();
Prueba::$conBd = in_array('--con-bd', $argv, true);

$archivos = glob(__DIR__ . '/*_test.php') ?: [];
sort($archivos);
foreach ($archivos as $archivo) {
    echo "\n# " . basename($archivo) . "\n";
    require $archivo;
}

exit(Prueba::resumen());
