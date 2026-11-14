<?php
// backend/nucleo/Paginacion.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Paginación de TODAS las listas: mismo tamaño, mismo tope y misma forma de respuesta
 * { items, total, pagina, por_pagina } (docs/api/*.md). Ningún módulo define la suya.
 *
 *   Controlador:  $pagina = Paginacion::desdeQuery($v);  $v->exigir();
 *   Servicio:     $filas = $repo->buscar($filtros, Paginacion::POR_PAGINA, Paginacion::desplazamiento($pagina));
 *                 return Paginacion::respuesta($items, $repo->contar($filtros), $pagina);
 */
final class Paginacion
{
    public const POR_PAGINA = 25;
    public const MAX_PAGINA = 10000;

    /** `pagina` (desde 1) de la query. Fuera de rango queda como error de campo; quien llama cierra con $v->exigir(). */
    public static function desdeQuery(Validar $v): int
    {
        $pagina = $v->entero('pagina', false) ?? 1;
        if ($pagina < 1 || $pagina > self::MAX_PAGINA) {
            $v->error('pagina', 'Debe estar entre 1 y ' . self::MAX_PAGINA . '.');
            return 1;
        }
        return $pagina;
    }

    /** Filas que se saltan antes de la página pedida (OFFSET). */
    public static function desplazamiento(int $pagina): int
    {
        return ($pagina - 1) * self::POR_PAGINA;
    }

    /**
     * @param list<array<string,mixed>> $items ya serializados
     * @return array{items:list<array<string,mixed>>,total:int,pagina:int,por_pagina:int}
     */
    public static function respuesta(array $items, int $total, int $pagina): array
    {
        return ['items' => $items, 'total' => $total, 'pagina' => $pagina, 'por_pagina' => self::POR_PAGINA];
    }
}
