<?php
// backend/modulos/plantilla/Repositorio.php
declare(strict_types=1);

namespace Modulos\Plantilla;

use Nucleo\Conexion;

/**
 * REPOSITORIO: solo SQL (B1). Sentencias preparadas siempre (B3). Ninguna regla de negocio.
 * Las fechas las pasa quien llama con Reloj::paraBd(); en SQL no existen las funciones de hora.
 */
final class Repositorio
{
    /** @return list<array<string,mixed>> */
    public function listar(): array
    {
        return Conexion::obtener()->query('SELECT id, nombre FROM sede ORDER BY nombre')->fetchAll();
    }

    public function obtener(int $id): ?array
    {
        $st = Conexion::obtener()->prepare('SELECT id, nombre FROM sede WHERE id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function insertar(string $nombre): int
    {
        $st = Conexion::obtener()->prepare('INSERT INTO sede (nombre) VALUES (?) RETURNING id');
        $st->execute([$nombre]);
        return (int) $st->fetchColumn();
    }
}
