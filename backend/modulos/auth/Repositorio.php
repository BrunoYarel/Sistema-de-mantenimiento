<?php
// backend/modulos/auth/Repositorio.php
declare(strict_types=1);

namespace Modulos\Auth;

use DateTimeImmutable;
use Nucleo\Conexion;
use Nucleo\Reloj;
use PDO;

/** Solo SQL (B1). Tablas propias de este módulo: bloqueo_login. Lectura/escritura de clave en `usuario`. */
final class Repositorio
{
    public function buscarPorUsuario(string $usuario): ?array
    {
        $st = Conexion::obtener()->prepare(
            'SELECT id, clave_hash, activo, debe_cambiar_clave FROM usuario WHERE usuario = ?'
        );
        $st->execute([$usuario]);
        return $st->fetch() ?: null;
    }

    public function hashDe(int $usuarioId): ?string
    {
        $st = Conexion::obtener()->prepare('SELECT clave_hash FROM usuario WHERE id = ?');
        $st->execute([$usuarioId]);
        $hash = $st->fetchColumn();
        return $hash === false || $hash === null ? null : (string) $hash;
    }

    /** Guarda un hash nuevo. $debeCambiar se escribe como texto: un false de PHP llegaría vacío a PostgreSQL. */
    public function guardarClave(int $usuarioId, string $hash, bool $debeCambiar): void
    {
        $st = Conexion::obtener()->prepare('UPDATE usuario SET clave_hash = ?, debe_cambiar_clave = ? WHERE id = ?');
        $st->execute([$hash, $debeCambiar ? 'true' : 'false', $usuarioId]);
    }

    /** Segundos que faltan para que termine el bloqueo; 0 si no está bloqueado. */
    public function segundosDeBloqueo(string $usuario, string $ip, DateTimeImmutable $ahora): int
    {
        $st = Conexion::obtener()->prepare('SELECT bloqueado_hasta FROM bloqueo_login WHERE usuario = ? AND ip = ?');
        $st->execute([$usuario, $ip]);
        $hasta = $st->fetchColumn();
        if ($hasta === false || $hasta === null) {
            return 0;
        }
        $resta = Reloj::desde((string) $hasta)->getTimestamp() - $ahora->getTimestamp();
        return $resta > 0 ? $resta : 0;
    }

    /** Suma un fallo; devuelve true si con este fallo queda bloqueado. */
    public function registrarFallo(string $usuario, string $ip, DateTimeImmutable $ahora, int $maximo, int $minutos): bool
    {
        return (bool) Conexion::transaccion(function (PDO $pdo) use ($usuario, $ip, $ahora, $maximo, $minutos): bool {
            $actual = $pdo->prepare('SELECT fallos, ultimo_fallo FROM bloqueo_login WHERE usuario = ? AND ip = ? FOR UPDATE');
            $actual->execute([$usuario, $ip]);
            $fila = $actual->fetch();

            $ventana = $ahora->modify("-{$minutos} minutes");
            $fallos = ($fila && Reloj::desde((string) $fila['ultimo_fallo']) > $ventana) ? (int) $fila['fallos'] + 1 : 1;
            $bloqueadoHasta = $fallos >= $maximo ? $ahora->modify("+{$minutos} minutes") : null;

            $guardar = $pdo->prepare(
                'INSERT INTO bloqueo_login (usuario, ip, fallos, ultimo_fallo, bloqueado_hasta)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (usuario, ip) DO UPDATE SET
                     fallos = EXCLUDED.fallos,
                     ultimo_fallo = EXCLUDED.ultimo_fallo,
                     bloqueado_hasta = EXCLUDED.bloqueado_hasta'
            );
            $guardar->execute([
                $usuario,
                $ip,
                $fallos,
                Reloj::paraBd($ahora),
                $bloqueadoHasta === null ? null : Reloj::paraBd($bloqueadoHasta),
            ]);

            return $bloqueadoHasta !== null;
        });
    }

    public function limpiarBloqueo(string $usuario, string $ip): void
    {
        $st = Conexion::obtener()->prepare(
            'UPDATE bloqueo_login SET fallos = 0, bloqueado_hasta = NULL WHERE usuario = ? AND ip = ?'
        );
        $st->execute([$usuario, $ip]);
    }

    /**
     * De esa lista de nombres de usuario, los que tienen el acceso bloqueado AHORA (en cualquier IP).
     *
     * @param list<string> $usuarios
     * @return list<string>
     */
    public function bloqueados(array $usuarios, DateTimeImmutable $ahora): array
    {
        if ($usuarios === []) {
            return [];
        }
        $st = Conexion::obtener()->prepare(
            'SELECT DISTINCT usuario FROM bloqueo_login
             WHERE bloqueado_hasta > ? AND usuario IN (' . implode(', ', array_fill(0, count($usuarios), '?')) . ')'
        );
        $st->execute([Reloj::paraBd($ahora), ...$usuarios]);
        return array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Quita el bloqueo y los fallos acumulados de un usuario, en todas las IP.
     *
     * @return int filas que de verdad tenían un bloqueo o fallos (0 = no había nada que liberar)
     */
    public function liberarBloqueos(string $usuario): int
    {
        $st = Conexion::obtener()->prepare(
            'UPDATE bloqueo_login SET fallos = 0, bloqueado_hasta = NULL
             WHERE usuario = ? AND (fallos > 0 OR bloqueado_hasta IS NOT NULL)'
        );
        $st->execute([$usuario]);
        return $st->rowCount();
    }
}
