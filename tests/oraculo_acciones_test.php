<?php
// tests/oraculo_acciones_test.php
declare(strict_types=1);

use Nucleo\Politica;

/**
 * Compara Politica::acciones() con tests/oraculo_acciones.json (RN-09, RF-12).
 * El JSON lo escribe QA a mano desde §4 y §5 y lo revisa otra persona; NUNCA se genera desde el código.
 */
$oraculo = json_decode((string) file_get_contents(__DIR__ . '/oraculo_acciones.json'), true, 16, JSON_THROW_ON_ERROR);
$tabla = require __DIR__ . '/../backend/modulos/incidencias/acciones.php';

foreach ($oraculo['casos'] as $caso) {
    prueba($caso['caso'], function () use ($caso, $tabla) {
        $reales = Politica::acciones(['rol' => $caso['rol']], $tabla, $caso['estado'], $caso['relaciones'], $caso['hechos']);
        $esperadas = $caso['esperadas'];
        sort($reales);
        sort($esperadas);
        exigirIgual($esperadas, $reales);
    });
}

prueba('un rol desconocido no puede nada (default deny)', function () use ($tabla) {
    exigirIgual([], Politica::acciones(['rol' => 'inventado'], $tabla, 'registrada', ['tecnico', 'reportante'], ['cierre_vencido']));
});
