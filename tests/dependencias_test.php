<?php
// tests/dependencias_test.php
declare(strict_types=1);

/**
 * Quién llama a quién entre módulos del backend (B2). No necesita base de datos.
 *
 *  1. Un módulo solo usa el Servicio de otro: nunca su Repositorio ni su Controlador.
 *  2. Entre módulos no hay ciclos, salvo las aristas de DEPENDENCIAS_ACEPTADAS (cada una con su motivo).
 *  3. Cada arista aceptada debe existir de verdad en el código: si ya no se usa, se quita de la lista.
 *
 * Se lee el código sin comentarios (token_get_all): un `use Modulos\X\Servicio` o un nombre completo cuentan;
 * mencionar un módulo en un comentario no.
 *
 * Si de verdad hace falta una arista que cierra un ciclo, se agrega aquí con su motivo y se documenta en
 * backend/README.md. Hoy solo existe una: usuarios -> incidencias (RN-02).
 */

/** "módulo que llama" => ["módulo al que llama" => motivo]. */
const DEPENDENCIAS_ACEPTADAS = [
    'usuarios' => [
        'incidencias' => 'RN-02: un técnico con incidencias abiertas no se desactiva ni cambia de rol (usuarios pregunta a incidencias; '
            . 'incidencias pregunta a usuarios quién es técnico y si sigue activo).',
    ],
];

/**
 * Qué módulos y qué clases usa cada módulo.
 *
 * @return array<string,array<string,list<string>>> módulo => (módulo usado => clases usadas, p. ej. ['Servicio'])
 */
function dependenciasLeer(string $raiz): array
{
    $grafo = [];
    foreach (glob($raiz . '/*', GLOB_ONLYDIR) ?: [] as $carpeta) {
        $modulo = basename($carpeta);
        $grafo[$modulo] ??= [];
        foreach (glob($carpeta . '/*.php') ?: [] as $archivo) {
            $codigo = '';
            foreach (token_get_all((string) file_get_contents($archivo)) as $token) {
                if (is_array($token) && ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT)) {
                    continue;
                }
                $codigo .= is_array($token) ? $token[1] : $token;
            }
            if (preg_match_all('/Modulos\\\\([A-Za-z0-9_]+)\\\\([A-Za-z0-9_]+)/', $codigo, $coincidencias, PREG_SET_ORDER) === false) {
                continue;
            }
            foreach ($coincidencias as [, $espacio, $clase]) {
                $usado = strtolower($espacio);
                if ($usado !== $modulo) {
                    $grafo[$modulo][$usado][] = $clase;
                }
            }
        }
    }
    return $grafo;
}

/**
 * Un ciclo del grafo (lista de módulos, el primero repetido al final) o null si no hay ninguno.
 *
 * @param array<string,list<string>> $aristas módulo => módulos que usa
 * @return ?list<string>
 */
function dependenciasCiclo(array $aristas): ?array
{
    $estado = []; // 1 = en el camino actual, 2 = ya revisado
    $camino = [];
    $visitar = function (string $nodo) use (&$visitar, &$estado, &$camino, $aristas): ?array {
        $estado[$nodo] = 1;
        $camino[] = $nodo;
        foreach ($aristas[$nodo] ?? [] as $vecino) {
            if (($estado[$vecino] ?? 0) === 1) {
                return [...array_slice($camino, (int) array_search($vecino, $camino, true)), $vecino];
            }
            if (!isset($estado[$vecino]) && ($ciclo = $visitar($vecino)) !== null) {
                return $ciclo;
            }
        }
        array_pop($camino);
        $estado[$nodo] = 2;
        return null;
    };
    foreach (array_keys($aristas) as $nodo) {
        if (!isset($estado[$nodo]) && ($ciclo = $visitar($nodo)) !== null) {
            return $ciclo;
        }
    }
    return null;
}

/**
 * Las aristas que cuentan para buscar ciclos: todas menos las aceptadas.
 *
 * @param array<string,array<string,list<string>>> $grafo
 * @return array<string,list<string>>
 */
function dependenciasSinAceptadas(array $grafo): array
{
    $aristas = [];
    foreach ($grafo as $modulo => $usados) {
        $aristas[$modulo] = [];
        foreach (array_keys($usados) as $usado) {
            if (!isset(DEPENDENCIAS_ACEPTADAS[$modulo][$usado])) {
                $aristas[$modulo][] = $usado;
            }
        }
    }
    return $aristas;
}

$grafoReal = dependenciasLeer(__DIR__ . '/../backend/modulos');

prueba('dependencias: el detector de ciclos encuentra uno y deja pasar un grafo sin ciclos', function () {
    exigirIgual(null, dependenciasCiclo(['a' => ['b', 'c'], 'b' => ['c'], 'c' => []]));
    exigirIgual(['a', 'b', 'a'], dependenciasCiclo(['a' => ['b'], 'b' => ['a']]));
    exigirIgual(['b', 'c', 'd', 'b'], dependenciasCiclo(['a' => ['b'], 'b' => ['c'], 'c' => ['d'], 'd' => ['b']]));
});

prueba('dependencias: cada módulo de backend/modulos/index.php tiene carpeta y cada carpeta está en la lista', function () use ($grafoReal) {
    // Se lee el texto de index.php (no se ejecuta: decidir si entra `plantilla` depende del entorno).
    preg_match_all("/^\\s*'([a-z0-9_]+)',/m", (string) file_get_contents(__DIR__ . '/../backend/modulos/index.php'), $coincidencias);
    $lista = $coincidencias[1];
    exigir($lista !== [], 'No se pudo leer la lista de módulos de backend/modulos/index.php');
    foreach ($lista as $id) {
        exigir(isset($grafoReal[$id]), "El módulo $id está en modulos/index.php pero no tiene carpeta");
    }
    foreach (array_keys($grafoReal) as $id) {
        // `plantilla` es el ejemplo para copiar: solo se agrega en local, por eso no figura en la lista fija.
        exigir($id === 'plantilla' || in_array($id, $lista, true), "La carpeta $id no está en modulos/index.php");
    }
});

prueba('dependencias: ningún módulo usa el Repositorio ni el Controlador de otro (solo su Servicio)', function () use ($grafoReal) {
    $malos = [];
    foreach ($grafoReal as $modulo => $usados) {
        foreach ($usados as $usado => $clases) {
            foreach (array_unique($clases) as $clase) {
                if ($clase !== 'Servicio') {
                    $malos[] = "$modulo usa $usado\\$clase";
                }
            }
        }
    }
    exigirIgual([], $malos, 'entre módulos solo se llama al Servicio (B2).');
});

prueba('dependencias: entre módulos no hay ciclos, salvo las aristas aceptadas', function () use ($grafoReal) {
    $ciclo = dependenciasCiclo(dependenciasSinAceptadas($grafoReal));
    exigir($ciclo === null, 'Ciclo entre módulos: ' . implode(' -> ', $ciclo ?? [])
        . '. Quita la dependencia o, si de verdad hace falta, acéptala en DEPENDENCIAS_ACEPTADAS con su motivo.');
});

prueba('dependencias: cada arista aceptada existe en el código y tiene motivo', function () use ($grafoReal) {
    foreach (DEPENDENCIAS_ACEPTADAS as $modulo => $usados) {
        foreach ($usados as $usado => $motivo) {
            exigir(isset($grafoReal[$modulo][$usado]), "$modulo ya no usa a $usado: quítala de DEPENDENCIAS_ACEPTADAS.");
            exigir(trim($motivo) !== '', "$modulo -> $usado necesita su motivo.");
        }
    }
});