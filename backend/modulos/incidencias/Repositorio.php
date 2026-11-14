<?php
// backend/modulos/incidencias/Repositorio.php
declare(strict_types=1);

namespace Modulos\Incidencias;

use Nucleo\Conexion;

/**
 * Solo SQL (B1). Sentencias preparadas (B3). Tabla del módulo: incidencia.
 *
 * Por ahora solo trae las lecturas que otros módulos necesitan (el módulo se irá llenando copiando backend/modulos/plantilla).
 * Une `equipo` solo para leer el código: es una lectura, nunca escribe (excepción de lectura de backend/README.md).
 */
final class Repositorio
{
    /**
     * Incidencias en alguno de esos estados asignadas a ese técnico, las más antiguas primero.
     *
     * @param list<string> $estados estados "abiertos" (Dominio::ESTADOS_ABIERTOS): lo decide el Servicio
     * @return list<array<string,mixed>>
     */
    public function deTecnicoEnEstados(int $tecnicoId, array $estados): array
    {
        if ($estados === []) {
            return []; // default deny: sin estados no hay nada que listar
        }
        $st = Conexion::obtener()->prepare(
            'SELECT i.id, i.equipo_id, e.codigo AS equipo, i.estado, i.prioridad, i.fecha_registro
             FROM incidencia i JOIN equipo e ON e.id = i.equipo_id
             WHERE i.tecnico_id = ? AND i.estado IN (' . implode(', ', array_fill(0, count($estados), '?')) . ')
             ORDER BY i.fecha_registro, i.id'
        );
        $st->execute([$tecnicoId, ...$estados]);
        return $st->fetchAll();
    }
}
