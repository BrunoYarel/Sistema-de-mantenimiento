<?php
// tests/dominio_test.php
declare(strict_types=1);

use Nucleo\Dominio;
use Nucleo\Politica;

/**
 * Dominio.php dice lo mismo que la base de datos y que la matriz escrita a mano. No necesita base de datos.
 *
 *  1. Cada CHECK (col IN (...)) de database/migraciones/*.sql tiene los mismos valores que su lista de Dominio.
 *     Falla si un valor existe en un lado y no en el otro, si la migración tiene un CHECK sin lista en Dominio,
 *     o si Dominio tiene una lista que no está registrada aquí (en LISTAS_CHECK o en SIN_CHECK).
 *  2. tests/matriz.json (QA, escrita a mano desde la §4) es igual a Dominio::PERMISOS_POR_ROL.
 *
 * Para agregar un rol, estado, canal, etc.: el CHECK en una migración nueva, la lista en Dominio.php y,
 * si es una lista nueva, una línea en LISTAS_CHECK.
 */

/** Columna de la base => constante de Dominio con sus valores. */
const LISTAS_CHECK = [
    'usuario.rol' => 'ROLES',
    'equipo.tipo' => 'TIPOS_EQUIPO',
    'equipo.criticidad' => 'CRITICIDADES',
    'incidencia.canal' => 'CANALES',
    'incidencia.impacto' => 'IMPACTOS',
    'incidencia.prioridad' => 'PRIORIDADES',
    'incidencia.estado' => 'ESTADOS',
    'reporte_vinculado.canal' => 'CANALES',
    'atencion.resultado' => 'RESULTADOS',
    'autorizacion.tipo' => 'TIPOS_AUTORIZACION',
    'autorizacion.estado' => 'ESTADOS_AUTORIZACION',
    'evento.estado_antes' => 'ESTADOS',
    'evento.estado_despues' => 'ESTADOS',
];

/** Constantes de Dominio que no son un CHECK de la base (no hay nada que comparar). */
const SIN_CHECK = ['ESTADOS_ABIERTOS', 'PERMISOS', 'PERMISOS_POR_ROL'];

/**
 * Lee los CHECK (col IN ('a', 'b')) de los CREATE TABLE de todas las migraciones.
 *
 * @return array<string,list<string>> 'tabla.columna' => valores
 */
function checksDeMigraciones(): array
{
    $archivos = glob(__DIR__ . '/../database/migraciones/*.sql') ?: [];
    sort($archivos);
    exigir($archivos !== [], 'No hay migraciones en database/migraciones');

    $sql = '';
    foreach ($archivos as $archivo) {
        $sql .= "\n" . file_get_contents($archivo);
    }
    $sql = (string) preg_replace('/--[^\n]*/', '', $sql); // sin comentarios

    $checks = [];
    preg_match_all('/CREATE TABLE\s+(\w+)\s*\((.*?)\n\);/s', $sql, $tablas, PREG_SET_ORDER);
    foreach ($tablas as $tabla) {
        preg_match_all('/CHECK\s*\(\s*(\w+)\s+IN\s*\(([^)]*)\)\s*\)/s', $tabla[2], $listas, PREG_SET_ORDER);
        foreach ($listas as $lista) {
            preg_match_all("/'([^']*)'/", $lista[2], $valores);
            $checks["{$tabla[1]}.{$lista[1]}"] = $valores[1];
        }
    }
    exigir($checks !== [], 'No se pudo leer ningún CHECK de las migraciones');
    return $checks;
}

/**
 * @param list<string> $enOtro     valores del otro lado (la migración o matriz.json)
 * @param list<string> $enDominio
 */
function exigirMismaLista(array $enOtro, array $enDominio, string $que, string $otro = 'la migración'): void
{
    $soloOtro = array_values(array_diff($enOtro, $enDominio));
    $soloDominio = array_values(array_diff($enDominio, $enOtro));
    exigir(
        $soloOtro === [] && $soloDominio === [],
        "$que difiere. Solo en $otro: " . json_encode($soloOtro, JSON_UNESCAPED_UNICODE)
        . ' | solo en Dominio.php: ' . json_encode($soloDominio, JSON_UNESCAPED_UNICODE)
    );
}

foreach (LISTAS_CHECK as $columna => $constante) {
    prueba("CHECK de $columna = Dominio::$constante", function () use ($columna, $constante) {
        $checks = checksDeMigraciones();
        exigir(isset($checks[$columna]), "La migración no tiene un CHECK ($columna IN (...))");
        exigirMismaLista($checks[$columna], constant(Dominio::class . '::' . $constante), $columna);
    });
}

prueba('toda lista CHECK de la migración tiene su lista en Dominio.php', function () {
    $sinLista = array_values(array_diff(array_keys(checksDeMigraciones()), array_keys(LISTAS_CHECK)));
    exigir($sinLista === [], 'CHECK en la migración sin registrar en LISTAS_CHECK (¿falta la lista en Dominio.php?): '
        . implode(', ', $sinLista));
});

prueba('toda constante de Dominio.php está comparada con la base o declarada SIN_CHECK', function () {
    $conocidas = array_merge(array_values(LISTAS_CHECK), SIN_CHECK);
    $sueltas = array_values(array_diff(array_keys((new ReflectionClass(Dominio::class))->getConstants()), $conocidas));
    exigir($sueltas === [], 'Constantes de Dominio sin registrar en LISTAS_CHECK ni en SIN_CHECK: ' . implode(', ', $sueltas));
});

prueba('tests/matriz.json es igual a Dominio::PERMISOS_POR_ROL', function () {
    $json = json_decode((string) file_get_contents(__DIR__ . '/matriz.json'), true);
    exigir(is_array($json) && is_array($json['roles'] ?? null), "matriz.json no tiene el objeto 'roles'");
    $matriz = $json['roles'];

    exigirMismaLista(array_keys($matriz), array_keys(Dominio::PERMISOS_POR_ROL), 'roles', 'matriz.json');
    foreach (Dominio::PERMISOS_POR_ROL as $rol => $permisos) {
        exigir(count($matriz[$rol]) === count(array_unique($matriz[$rol])), "matriz.json repite permisos en el rol $rol");
        exigirMismaLista($matriz[$rol], $permisos, "permisos del rol $rol", 'matriz.json');
    }
});

prueba('todo permiso de la matriz existe en Dominio::PERMISOS (un nombre mal escrito quedaría denegado sin avisar)', function () {
    foreach (Dominio::PERMISOS_POR_ROL as $rol => $permisos) {
        $desconocidos = array_values(array_diff($permisos, Dominio::PERMISOS));
        exigir($desconocidos === [], "El rol $rol tiene permisos que no están en Dominio::PERMISOS: " . implode(', ', $desconocidos));
    }
});

prueba('quién atiende y quién reporta sale de la matriz: técnicos y docentes/administrativos (D4, RF-01)', function () {
    exigirMismaLista(['soporte_tecnico', 'soporte_informatica'], Politica::rolesCon('incidencia.atender'), 'roles que atienden', 'lo esperado');
    exigirMismaLista(['docente', 'admin'], Politica::rolesCon('incidencia.registrar_propia'), 'roles que reportan', 'lo esperado');
});