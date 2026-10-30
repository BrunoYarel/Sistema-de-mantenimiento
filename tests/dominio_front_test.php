<?php
// tests/dominio_front_test.php
declare(strict_types=1);

use Nucleo\Dominio;

/**
 * frontend/js/nucleo/dominio.js dice lo mismo que Nucleo\Dominio. No necesita base de datos ni Node:
 * lee el .js con expresiones regulares. Por eso dominio.js conserva su formato: una constante por línea
 * y, en PERMISOS_POR_ROL, un rol por bloque con sus permisos como P.NOMBRE.
 *
 * Compara con Dominio.php: ROL, ESTADO, PRIORIDAD, CANAL, PERMISO (valores) y PERMISOS_POR_ROL (matriz).
 * Además exige un texto en pantalla (*_ETIQUETA) para cada rol, estado, prioridad y canal.
 */

/** Código de dominio.js sin los comentarios //. */
function frontCodigoDominioJs(): string
{
    $codigo = @file_get_contents(__DIR__ . '/../frontend/js/nucleo/dominio.js');
    exigir($codigo !== false, 'No se pudo leer frontend/js/nucleo/dominio.js');
    return (string) preg_replace('~//[^\n]*~', '', $codigo);
}

/** Cuerpo de `export const NOMBRE = Object.freeze({ ... });`. */
function frontCuerpo(string $codigo, string $nombre): string
{
    $hay = preg_match('/export const ' . $nombre . ' = Object\.freeze\(\{(.*?)\n?\}\);/s', $codigo, $m) === 1;
    exigir($hay, "dominio.js no tiene 'export const $nombre = Object.freeze({ ... });' (¿cambió el formato?)");
    return $m[1];
}

/**
 * `NOMBRE: 'valor'` de una constante de dominio.js.
 *
 * @return array<string,string> NOMBRE => valor
 */
function frontConstantes(string $codigo, string $nombre): array
{
    preg_match_all("/(\w+):\s*'([^']*)'/", frontCuerpo($codigo, $nombre), $pares, PREG_SET_ORDER);
    $salida = [];
    foreach ($pares as $par) {
        $salida[$par[1]] = $par[2];
    }
    exigir($salida !== [], "$nombre no tiene valores en dominio.js (¿cambió el formato?)");
    exigir(count($salida) === count(array_unique($salida)), "$nombre repite un valor en dominio.js");
    return $salida;
}

/**
 * Nombres con texto en pantalla: las claves `[ROL.NOMBRE]:` de una tabla *_ETIQUETA.
 *
 * @return list<string>
 */
function frontEtiquetados(string $codigo, string $tabla, string $constante): array
{
    preg_match_all('/\[' . $constante . '\.(\w+)\]\s*:/', frontCuerpo($codigo, $tabla), $m);
    return $m[1];
}

/**
 * Matriz rol => permisos de dominio.js, ya traducida a los valores ('docente' => ['incidencia.ver', ...]).
 *
 * @return array<string,list<string>>
 */
function frontMatriz(string $codigo): array
{
    $hay = preg_match('/export const PERMISOS_POR_ROL = Object\.freeze\(\{(.*?)\n\}\);/s', $codigo, $m) === 1;
    exigir($hay, "dominio.js no tiene 'export const PERMISOS_POR_ROL = Object.freeze({ ... });' (¿cambió el formato?)");

    $roles = frontConstantes($codigo, 'ROL');
    $permisos = frontConstantes($codigo, 'PERMISO');

    preg_match_all('/\[ROL\.(\w+)\]\s*:\s*\[(.*?)\]/s', $m[1], $bloques, PREG_SET_ORDER);
    exigir($bloques !== [], 'PERMISOS_POR_ROL no tiene ningún bloque [ROL.X]: [...]');

    $matriz = [];
    foreach ($bloques as $bloque) {
        exigir(isset($roles[$bloque[1]]), "PERMISOS_POR_ROL usa ROL.{$bloque[1]}, que no existe en ROL");
        preg_match_all('/\bP\.(\w+)/', $bloque[2], $usados);
        $lista = [];
        foreach ($usados[1] as $nombre) {
            exigir(isset($permisos[$nombre]), "PERMISOS_POR_ROL usa P.$nombre, que no existe en PERMISO");
            $lista[] = $permisos[$nombre];
        }
        exigir(count($lista) === count(array_unique($lista)), "dominio.js repite permisos en el rol {$roles[$bloque[1]]}");
        $matriz[$roles[$bloque[1]]] = $lista;
    }
    return $matriz;
}

/**
 * @param list<string> $front   valores de dominio.js
 * @param list<string> $dominio valores de Dominio.php
 */
function frontExigirMismaLista(array $front, array $dominio, string $que): void
{
    $soloFront = array_values(array_diff($front, $dominio));
    $soloDominio = array_values(array_diff($dominio, $front));
    exigir(
        $soloFront === [] && $soloDominio === [],
        "$que difiere. Solo en dominio.js: " . json_encode($soloFront, JSON_UNESCAPED_UNICODE)
        . ' | solo en Dominio.php: ' . json_encode($soloDominio, JSON_UNESCAPED_UNICODE)
    );
}

// constante de dominio.js => lista de Dominio.php
foreach (['ROL' => 'ROLES', 'ESTADO' => 'ESTADOS', 'PRIORIDAD' => 'PRIORIDADES', 'CANAL' => 'CANALES', 'PERMISO' => 'PERMISOS'] as $js => $php) {
    prueba("dominio.js $js = Dominio::$php", function () use ($js, $php) {
        frontExigirMismaLista(
            array_values(frontConstantes(frontCodigoDominioJs(), $js)),
            constant(Dominio::class . '::' . $php),
            $js
        );
    });
}

// tabla de textos => constante que etiqueta
foreach (['ROL_ETIQUETA' => 'ROL', 'ESTADO_ETIQUETA' => 'ESTADO', 'PRIORIDAD_ETIQUETA' => 'PRIORIDAD', 'CANAL_ETIQUETA' => 'CANAL'] as $tabla => $constante) {
    prueba("dominio.js: cada $constante tiene su texto en $tabla", function () use ($tabla, $constante) {
        $codigo = frontCodigoDominioJs();
        frontExigirMismaLista(
            frontEtiquetados($codigo, $tabla, $constante),
            array_keys(frontConstantes($codigo, $constante)),
            $tabla
        );
    });
}

prueba('dominio.js PERMISOS_POR_ROL = Dominio::PERMISOS_POR_ROL', function () {
    $front = frontMatriz(frontCodigoDominioJs());

    frontExigirMismaLista(array_keys($front), array_keys(Dominio::PERMISOS_POR_ROL), 'roles de la matriz');
    foreach (Dominio::PERMISOS_POR_ROL as $rol => $permisos) {
        frontExigirMismaLista($front[$rol], $permisos, "permisos del rol $rol");
    }
});
