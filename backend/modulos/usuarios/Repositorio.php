<?php
// backend/modulos/usuarios/Repositorio.php
declare(strict_types=1);

namespace Modulos\Usuarios;

use Nucleo\Conexion;
use PDO;

/**
 * Solo SQL (B1). Sentencias preparadas (B3). Tabla del módulo: usuario.
 * La clave y el login los lleva auth: aquí solo se guarda el hash que auth generó al crear, y nunca se lee.
 *
 * USUARIO DE SISTEMA = sin credenciales (clave_hash NULL, p. ej. carga_inicial). La gestión no lo ve: ni en la lista ni
 * por id (obtener devuelve null). Así nadie lo reactiva ni le restablece una clave para entrar con él.
 */
final class Repositorio
{
    private const COLUMNAS = 'u.id, u.nombre, u.usuario, u.rol, u.sede_id, u.activo, u.debe_cambiar_clave';
    private const CON_CLAVE = 'u.clave_hash IS NOT NULL';

    /** Columnas que una edición puede tocar (lista fija: nunca viene del cliente, B3). */
    private const COLUMNAS_EDITABLES = ['nombre', 'rol', 'sede_id'];

    /**
     * Usuarios ACTIVOS con alguno de esos roles, por nombre. Con $patron (ya con comodines) busca en el nombre
     * (sin tildes ni mayúsculas) y en el nombre de usuario.
     *
     * @param list<string> $roles
     * @return list<array<string,mixed>>
     */
    public function activosConRol(array $roles, ?string $patron, int $limite): array
    {
        if ($roles === []) {
            return []; // default deny: sin roles no hay a quién listar
        }
        $parametros = $roles;
        $sql = 'SELECT id, nombre, rol FROM usuario
                WHERE activo AND rol IN (' . implode(', ', array_fill(0, count($roles), '?')) . ')';
        if ($patron !== null) {
            $sql .= " AND (lower(translate(nombre, 'áéíóúüÁÉÍÓÚÜ', 'aeiouuAEIOUU')) LIKE ? OR usuario LIKE ?)";
            $parametros[] = $patron;
            $parametros[] = $patron;
        }
        $sql .= ' ORDER BY nombre, id LIMIT ?';
        $parametros[] = $limite;

        $st = Conexion::obtener()->prepare($sql);
        $st->execute($parametros);
        return $st->fetchAll();
    }

    /**
     * Nombre de cada id pedido, activo o no: el historial de un equipo sigue mostrando a quien ya no trabaja aquí.
     * Solo se lee el nombre, nunca la clave ni el rol.
     *
     * @param list<int> $ids
     * @return array<int,string> id => nombre (los ids que no existen no aparecen)
     */
    public function nombresPorId(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $st = Conexion::obtener()->prepare(
            'SELECT id, nombre FROM usuario WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
        );
        $st->execute($ids);
        $nombres = [];
        foreach ($st->fetchAll() as $fila) {
            $nombres[(int) $fila['id']] = (string) $fila['nombre'];
        }
        return $nombres;
    }

    /**
     * Lista de gestión: activos e inactivos, sin usuarios de sistema, por nombre.
     *
     * @param array{q:?string,rol:?string,sede:?int,activo:?bool} $filtros q ya con comodines
     * @return list<array<string,mixed>>
     */
    public function buscar(array $filtros, int $limite, int $desplazamiento): array
    {
        [$donde, $parametros] = self::donde($filtros);
        $st = Conexion::obtener()->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM usuario u ' . $donde . ' ORDER BY u.nombre, u.id LIMIT ? OFFSET ?'
        );
        $st->execute([...$parametros, $limite, $desplazamiento]);
        return $st->fetchAll();
    }

    /** @param array{q:?string,rol:?string,sede:?int,activo:?bool} $filtros */
    public function contar(array $filtros): int
    {
        [$donde, $parametros] = self::donde($filtros);
        $st = Conexion::obtener()->prepare('SELECT count(*) FROM usuario u ' . $donde);
        $st->execute($parametros);
        return (int) $st->fetchColumn();
    }

    /**
     * Un usuario gestionable (nunca uno de sistema). Con $bloquear, la fila queda bloqueada hasta el fin de la
     * transacción (SELECT ... FOR NO KEY UPDATE de toda escritura). NO KEY UPDATE no estorba a quien solo
     * referencia al usuario (incidencias, auditoría).
     *
     * @return ?array<string,mixed>
     */
    public function obtener(int $id, bool $bloquear = false): ?array
    {
        $st = Conexion::obtener()->prepare(
            'SELECT ' . self::COLUMNAS . ' FROM usuario u WHERE u.id = ? AND ' . self::CON_CLAVE
            . ($bloquear ? ' FOR NO KEY UPDATE' : '')
        );
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /**
     * Bloquea (hasta el fin de la transacción) a todos los usuarios ACTIVOS con alguno de esos roles y devuelve sus ids.
     * Siempre en orden de id y ANTES de bloquear al usuario que se modifica: así dos jefes que se tocan entre sí a
     * la vez se turnan en lugar de trabarse. Sirve para comprobar que quede al menos un gestor.
     *
     * @param list<string> $roles
     * @return list<int>
     */
    public function bloquearActivosConRol(array $roles): array
    {
        if ($roles === []) {
            return [];
        }
        $st = Conexion::obtener()->prepare(
            'SELECT id FROM usuario WHERE activo AND rol IN (' . implode(', ', array_fill(0, count($roles), '?')) . ')
             ORDER BY id FOR NO KEY UPDATE'
        );
        $st->execute($roles);
        return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /** ¿Existe ese nombre de usuario? Incluye los de sistema: el nombre es único en toda la tabla. */
    public function existeUsuario(string $usuario): bool
    {
        $st = Conexion::obtener()->prepare('SELECT 1 FROM usuario WHERE usuario = ? LIMIT 1');
        $st->execute([$usuario]);
        return $st->fetchColumn() !== false;
    }

    /**
     * Alta. $claveHash lo genera auth (Servicio::hashear): nace con debe_cambiar_clave y activo.
     * $creadoEn lo pone quien llama con Reloj::paraBd(): en SQL no existen las funciones de hora.
     */
    public function insertar(string $nombre, string $usuario, string $claveHash, string $rol, ?int $sedeId, string $creadoEn): int
    {
        $st = Conexion::obtener()->prepare(
            'INSERT INTO usuario (nombre, usuario, clave_hash, rol, sede_id, debe_cambiar_clave, creado_en)
             VALUES (?, ?, ?, ?, ?, TRUE, ?)
             RETURNING id'
        );
        $st->execute([$nombre, $usuario, $claveHash, $rol, $sedeId, $creadoEn]);
        return (int) $st->fetchColumn();
    }

    /**
     * Cambia solo las columnas indicadas (de COLUMNAS_EDITABLES; el resto se ignora). El nombre de usuario,
     * la clave y `activo` no se editan por aquí. sede_id puede ser null (sin sede por defecto).
     *
     * @param array<string,int|string|null> $campos columna => valor nuevo
     * @return int filas cambiadas (0 = conflicto real)
     */
    public function actualizar(int $id, array $campos): int
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

        $st = Conexion::obtener()->prepare('UPDATE usuario SET ' . implode(', ', $asignaciones) . ' WHERE id = ?');
        $st->execute($parametros);
        return $st->rowCount();
    }

    /** @return int filas cambiadas (0 = conflicto real). Un false de PHP llegaría vacío a PostgreSQL: va como texto. */
    public function cambiarActivo(int $id, bool $activo): int
    {
        $st = Conexion::obtener()->prepare('UPDATE usuario SET activo = ? WHERE id = ?');
        $st->execute([$activo ? 'true' : 'false', $id]);
        return $st->rowCount();
    }

    /**
     * Solo se concatenan fragmentos fijos; todo valor va como parámetro (B3).
     *
     * @param array{q:?string,rol:?string,sede:?int,activo:?bool} $f
     * @return array{0:string,1:list<int|string>}
     */
    private static function donde(array $f): array
    {
        $condiciones = [self::CON_CLAVE];
        $parametros = [];
        if ($f['rol'] !== null) {
            $condiciones[] = 'u.rol = ?';
            $parametros[] = $f['rol'];
        }
        if ($f['sede'] !== null) {
            $condiciones[] = 'u.sede_id = ?';
            $parametros[] = $f['sede'];
        }
        if ($f['activo'] !== null) {
            $condiciones[] = 'u.activo = ?';
            $parametros[] = $f['activo'] ? 'true' : 'false';
        }
        if ($f['q'] !== null) {
            $condiciones[] = "(lower(translate(u.nombre, 'áéíóúüÁÉÍÓÚÜ', 'aeiouuAEIOUU')) LIKE ? OR u.usuario LIKE ?)";
            $parametros[] = $f['q'];
            $parametros[] = $f['q'];
        }
        return ['WHERE ' . implode(' AND ', $condiciones), $parametros];
    }
}
