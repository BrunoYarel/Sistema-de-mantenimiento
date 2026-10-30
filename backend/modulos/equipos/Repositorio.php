<?php
// backend/modulos/equipos/Repositorio.php
declare(strict_types=1);

namespace Modulos\Equipos;

use Nucleo\Conexion;

/**
 * Solo SQL (B1). Sentencias preparadas (B3). Tabla del módulo: equipo. `sede` y `ubicacion` son del módulo catalogos:
 * aquí no se leen (ni con JOIN); el nombre de la ubicación lo agrega el Servicio.
 * El historial y la lista de fuera de servicio LEEN `incidencia` y `atencion` (excepción de lectura de backend/README.md):
 * nunca las escriben, y los nombres de las personas se piden al Servicio de usuarios, no a su tabla.
 * Esas dos lecturas se mudan al módulo incidencias cuando se construya (docs/api/incidencias.md).
 */
final class Repositorio
{
    /** Columnas de equipo que una edición puede tocar (lista fija: nunca viene del cliente, B3). */
    private const COLUMNAS_EDITABLES = ['tipo', 'sede_id', 'ubicacion_id', 'criticidad'];

    /**
     * Un equipo (sin el nombre de su ubicación: lo agrega el Servicio). Con $bloquear, la fila queda bloqueada
     * hasta el fin de la transacción (SELECT ... FOR UPDATE de toda edición).
     *
     * @return ?array<string,mixed>
     */
    public function obtenerEquipo(int $id, bool $bloquear = false): ?array
    {
        $st = Conexion::obtener()->prepare(
            'SELECT e.id, e.codigo, e.tipo, e.sede_id, e.ubicacion_id, e.criticidad, e.activo
             FROM equipo e
             WHERE e.id = ?' . ($bloquear ? ' FOR UPDATE' : '')
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function existeCodigo(string $codigo): bool
    {
        $st = Conexion::obtener()->prepare('SELECT 1 FROM equipo WHERE codigo = ? LIMIT 1');
        $st->execute([$codigo]);
        return $st->fetchColumn() !== false;
    }

    /** $creadoEn lo pone quien llama con Reloj::paraBd(): en SQL no existen las funciones de hora. */
    public function insertarEquipo(string $codigo, string $tipo, int $sedeId, int $ubicacionId, string $criticidad, string $creadoEn): int
    {
        $st = Conexion::obtener()->prepare(
            'INSERT INTO equipo (codigo, tipo, sede_id, ubicacion_id, criticidad, creado_en)
             VALUES (?, ?, ?, ?, ?, ?)
             RETURNING id'
        );
        $st->execute([$codigo, $tipo, $sedeId, $ubicacionId, $criticidad, $creadoEn]);
        return (int) $st->fetchColumn();
    }

    /**
     * Cambia solo las columnas indicadas (de COLUMNAS_EDITABLES; el resto se ignora). El código y `activo` no se
     * editan por aquí. Ubicación y sede se cambian juntas: la base exige que la ubicación pertenezca a la sede.
     *
     * @param array<string,int|string> $campos columna => valor nuevo
     * @return int filas cambiadas (0 = conflicto real)
     */
    public function actualizarEquipo(int $id, array $campos): int
    {
        $asignaciones = [];
        $parametros = [];
        foreach (self::COLUMNAS_EDITABLES as $columna) {
            if (array_key_exists($columna, $campos)) {
                $asignaciones[] = $columna . ' = ?'; // solo nombres de la lista fija; el valor va como parámetro
                $parametros[] = $campos[$columna];
            }
        }
        if ($asignaciones === []) {
            return 0;
        }
        $parametros[] = $id;

        $st = Conexion::obtener()->prepare('UPDATE equipo SET ' . implode(', ', $asignaciones) . ' WHERE id = ?');
        $st->execute($parametros);
        return $st->rowCount();
    }

    /**
     * Baja (false) o reactivación (true). `activo` no se toca por actualizarEquipo: solo por aquí.
     * Un false de PHP llegaría vacío a PostgreSQL: va como texto.
     *
     * @return int filas cambiadas (0 = conflicto real)
     */
    public function cambiarActivo(int $id, bool $activo): int
    {
        $st = Conexion::obtener()->prepare('UPDATE equipo SET activo = ? WHERE id = ?');
        $st->execute([$activo ? 'true' : 'false', $id]);
        return $st->rowCount();
    }

    /**
     * @param array{sede:?int,ubicacion:?int,tipo:?string,q:?string,activo:?bool} $filtros
     * @param ?int $limite null = todos
     * @return list<array<string,mixed>>
     */
    public function buscarEquipos(array $filtros, ?int $limite, int $desplazamiento): array
    {
        [$donde, $parametros] = self::donde($filtros);
        $sql = 'SELECT e.id, e.codigo, e.tipo, e.sede_id, e.ubicacion_id, e.criticidad, e.activo
                FROM equipo e '
            . $donde . ' ORDER BY e.codigo';
        if ($limite !== null) {
            $sql .= ' LIMIT ? OFFSET ?';
            $parametros[] = $limite;
            $parametros[] = $desplazamiento;
        }
        $st = Conexion::obtener()->prepare($sql);
        $st->execute($parametros);
        return $st->fetchAll();
    }

    /** @param array{sede:?int,ubicacion:?int,tipo:?string,q:?string,activo:?bool} $filtros */
    public function contarEquipos(array $filtros): int
    {
        [$donde, $parametros] = self::donde($filtros);
        $st = Conexion::obtener()->prepare('SELECT count(*) FROM equipo e ' . $donde);
        $st->execute($parametros);
        return (int) $st->fetchColumn();
    }

    /**
     * Historial de un equipo (RF-09): una fila por incidencia, la más reciente primero. `solucion` son las acciones de la
     * última atención de esa incidencia (NULL si aún no hubo ninguna). Trae el id del técnico, no su nombre.
     *
     * @return list<array<string,mixed>>
     */
    public function historialDeEquipo(int $equipoId, int $limite, int $desplazamiento): array
    {
        $st = Conexion::obtener()->prepare(
            'SELECT i.fecha_registro, i.descripcion, i.tecnico_id,
                    (SELECT a.acciones FROM atencion a WHERE a.incidencia_id = i.id ORDER BY a.fecha DESC, a.id DESC LIMIT 1) AS solucion
             FROM incidencia i
             WHERE i.equipo_id = ?
             ORDER BY i.fecha_registro DESC, i.id DESC
             LIMIT ? OFFSET ?'
        );
        $st->execute([$equipoId, $limite, $desplazamiento]);
        return $st->fetchAll();
    }

    public function contarHistorialDeEquipo(int $equipoId): int
    {
        $st = Conexion::obtener()->prepare('SELECT count(*) FROM incidencia WHERE equipo_id = ?');
        $st->execute([$equipoId]);
        return (int) $st->fetchColumn();
    }

    /**
     * Equipos FUERA DE SERVICIO: dato derivado, no guardado. Un equipo activo con al menos una incidencia en alguno de
     * $estadosAbiertos. Con cuántas y desde cuándo (la abierta más antigua); el que lleva más tiempo, primero.
     *
     * @param array{sede:?int,ubicacion:?int,tipo:?string} $filtros
     * @param list<string> $estadosAbiertos Dominio::ESTADOS_ABIERTOS: lo decide el Servicio
     * @return list<array<string,mixed>>
     */
    public function buscarFueraDeServicio(array $filtros, array $estadosAbiertos, int $limite, int $desplazamiento): array
    {
        if ($estadosAbiertos === []) {
            return []; // default deny
        }
        [$donde, $parametros] = self::donde($filtros + ['q' => null, 'activo' => true]);
        $st = Conexion::obtener()->prepare(
            'SELECT e.id, e.codigo, e.tipo, e.sede_id, e.ubicacion_id, e.criticidad, e.activo,
                    a.abiertas AS incidencias_abiertas, a.desde
             FROM equipo e
             JOIN (' . self::abiertasPorEquipo($estadosAbiertos) . ') a ON a.equipo_id = e.id '
            . $donde . ' ORDER BY a.desde, e.codigo LIMIT ? OFFSET ?'
        );
        $st->execute([...$estadosAbiertos, ...$parametros, $limite, $desplazamiento]);
        return $st->fetchAll();
    }

    /**
     * @param array{sede:?int,ubicacion:?int,tipo:?string} $filtros
     * @param list<string> $estadosAbiertos
     */
    public function contarFueraDeServicio(array $filtros, array $estadosAbiertos): int
    {
        if ($estadosAbiertos === []) {
            return 0;
        }
        [$donde, $parametros] = self::donde($filtros + ['q' => null, 'activo' => true]);
        $st = Conexion::obtener()->prepare(
            'SELECT count(*) FROM equipo e JOIN (' . self::abiertasPorEquipo($estadosAbiertos) . ') a ON a.equipo_id = e.id ' . $donde
        );
        $st->execute([...$estadosAbiertos, ...$parametros]);
        return (int) $st->fetchColumn();
    }

    /** Subconsulta: por equipo, cuántas incidencias abiertas tiene y desde cuándo. Un `?` por estado, en este orden. */
    private static function abiertasPorEquipo(array $estados): string
    {
        return 'SELECT equipo_id, count(*) AS abiertas, min(fecha_registro) AS desde FROM incidencia WHERE estado IN ('
            . implode(', ', array_fill(0, count($estados), '?')) . ') GROUP BY equipo_id';
    }

    /**
     * Solo se concatenan fragmentos fijos; todo valor va como parámetro (B3).
     *
     * @return array{0:string,1:list<int|string>}
     */
    private static function donde(array $f): array
    {
        $condiciones = [];
        $parametros = [];
        if ($f['sede'] !== null) {
            $condiciones[] = 'e.sede_id = ?';
            $parametros[] = $f['sede'];
        }
        if ($f['ubicacion'] !== null) {
            $condiciones[] = 'e.ubicacion_id = ?';
            $parametros[] = $f['ubicacion'];
        }
        if ($f['tipo'] !== null) {
            $condiciones[] = 'e.tipo = ?';
            $parametros[] = $f['tipo'];
        }
        if ($f['activo'] !== null) {
            $condiciones[] = 'e.activo = ?';
            $parametros[] = $f['activo'] ? 'true' : 'false'; // un false de PHP llegaría vacío a PostgreSQL
        }
        if ($f['q'] !== null) {
            $condiciones[] = 'e.codigo LIKE ?';
            $parametros[] = '%' . addcslashes($f['q'], '%_\\') . '%'; // los códigos se guardan en mayúsculas
        }
        return [$condiciones === [] ? '' : 'WHERE ' . implode(' AND ', $condiciones), $parametros];
    }
}