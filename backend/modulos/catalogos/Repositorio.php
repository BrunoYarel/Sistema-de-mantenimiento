<?php
// backend/modulos/catalogos/Repositorio.php
declare(strict_types=1);

namespace Modulos\Catalogos;

use Nucleo\Conexion;

/** Solo SQL (B1). Sentencias preparadas (B3). Tablas del módulo: sede y ubicacion. */
final class Repositorio
{
    // ------------------------------------------------------------------ sedes

    /** @return list<array<string,mixed>> */
    public function listar(): array
    {
        return Conexion::obtener()->query('SELECT id, nombre FROM sede ORDER BY nombre')->fetchAll();
    }

    public function existe(int $id): bool
    {
        $st = Conexion::obtener()->prepare('SELECT 1 FROM sede WHERE id = ?');
        $st->execute([$id]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Bloquea la fila de la sede hasta el fin de la transacción: serializa las altas y los renombres de ubicaciones
     * de esa sede, para que dos nombres iguales (sin importar mayúsculas) no se cuelen por una carrera.
     * NO KEY UPDATE no estorba a quien solo referencia la sede (incidencias, usuarios).
     *
     * @return bool false si la sede no existe
     */
    public function bloquear(int $id): bool
    {
        $st = Conexion::obtener()->prepare('SELECT id FROM sede WHERE id = ? FOR NO KEY UPDATE');
        $st->execute([$id]);
        return $st->fetchColumn() !== false;
    }

    // ------------------------------------------------------------------ ubicaciones

    /** @return list<array<string,mixed>> */
    public function listarUbicaciones(?int $sedeId): array
    {
        $sql = 'SELECT id, sede_id, nombre FROM ubicacion' . ($sedeId === null ? '' : ' WHERE sede_id = ?') . ' ORDER BY nombre, id';
        $st = Conexion::obtener()->prepare($sql);
        $st->execute($sedeId === null ? [] : [$sedeId]);
        return $st->fetchAll();
    }

    /** @return ?array<string,mixed> con $bloquear, la fila queda bloqueada hasta el fin de la transacción */
    public function obtenerUbicacion(int $id, bool $bloquear = false): ?array
    {
        $st = Conexion::obtener()->prepare('SELECT id, sede_id, nombre FROM ubicacion WHERE id = ?' . ($bloquear ? ' FOR UPDATE' : ''));
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Nombre de cada ubicación pedida (para que otros módulos muestren la de un equipo sin leer la tabla).
     *
     * @param list<int> $ids
     * @return array<int,string> id => nombre (los ids que no existen no aparecen)
     */
    public function nombresDeUbicaciones(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $st = Conexion::obtener()->prepare(
            'SELECT id, nombre FROM ubicacion WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
        );
        $st->execute($ids);
        $nombres = [];
        foreach ($st->fetchAll() as $fila) {
            $nombres[(int) $fila['id']] = (string) $fila['nombre'];
        }
        return $nombres;
    }

    /** ¿Hay otra ubicación en esa sede con ese nombre, sin importar mayúsculas? $exceptoId = la que se está renombrando. */
    public function existeNombreUbicacion(int $sedeId, string $nombre, ?int $exceptoId): bool
    {
        $st = Conexion::obtener()->prepare(
            'SELECT 1 FROM ubicacion WHERE sede_id = ? AND lower(nombre) = lower(?::text) AND id <> ? LIMIT 1'
        );
        $st->execute([$sedeId, $nombre, $exceptoId ?? 0]); // los ids empiezan en 1: 0 no excluye a nadie
        return $st->fetchColumn() !== false;
    }

    public function insertarUbicacion(int $sedeId, string $nombre): int
    {
        $st = Conexion::obtener()->prepare('INSERT INTO ubicacion (sede_id, nombre) VALUES (?, ?) RETURNING id');
        $st->execute([$sedeId, $nombre]);
        return (int) $st->fetchColumn();
    }

    /** @return int filas cambiadas (0 = conflicto real) */
    public function renombrarUbicacion(int $id, string $nombre): int
    {
        $st = Conexion::obtener()->prepare('UPDATE ubicacion SET nombre = ? WHERE id = ?');
        $st->execute([$nombre, $id]);
        return $st->rowCount();
    }
}