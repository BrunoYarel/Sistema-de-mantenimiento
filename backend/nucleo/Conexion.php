<?php
// backend/nucleo/Conexion.php
declare(strict_types=1);

namespace Nucleo;

use PDO;

/**
 * PDO para PostgreSQL. Dos perfiles:
 *   'app'        usuario de la aplicación: sin DDL, sin DELETE, solo INSERT en evento (database/grants.sql)
 *   'migracion'  dueño del esquema: solo lo usan bin/migrar.php y bin/respaldo.php
 *
 * Diferencias con MySQL que importan al equipo:
 *   - rowCount() de un UPDATE cuenta filas que cumplen el WHERE aunque el valor no cambie:
 *     0 filas = conflicto real (409). No hace falta nada parecido a FOUND_ROWS.
 *   - Los ids se obtienen con INSERT ... RETURNING id, no con lastInsertId().
 *   - Un booleano PHP en execute([...]) llega como texto vacío y PostgreSQL lo rechaza:
 *     usa literales TRUE/FALSE en el SQL o los textos 'true'/'false'.
 */
final class Conexion
{
    /** @var array<string,PDO> */
    private static array $pdo = [];
    private static int $profundidad = 0;

    public static function obtener(string $perfil = 'app'): PDO
    {
        if (isset(self::$pdo[$perfil])) {
            return self::$pdo[$perfil];
        }

        $prefijo = $perfil === 'migracion' ? 'BD_MIGRACION_' : 'BD_';
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s',
            Config::get('BD_HOST', 'localhost'),
            Config::get('BD_PUERTO', '5432'),
            Config::requerido('BD_NOMBRE')
        );

        $pdo = new PDO($dsn, Config::requerido($prefijo . 'USUARIO'), Config::get($prefijo . 'CLAVE', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        $pdo->exec("SET client_encoding TO 'UTF8'");
        $pdo->exec("SET TIME ZONE '" . Reloj::ZONA . "'"); // hora de Lima en un solo lugar (RN-10)
        if ($perfil === 'app') {
            $pdo->exec('SET statement_timeout = 10000'); // ms: una consulta colgada no tumba al resto
            $pdo->exec('SET lock_timeout = 5000');       // ms: un FOR UPDATE no espera para siempre
        }

        return self::$pdo[$perfil] = $pdo;
    }

    /**
     * Booleano de PostgreSQL a bool de PHP. pdo_pgsql ya devuelve bool; esto lo hace explícito y,
     * si algún día llegara otra cosa, falla cerrado (falso) en vez de dejar pasar a un usuario inactivo.
     */
    public static function aBool(mixed $valor): bool
    {
        return $valor === true || $valor === 't' || $valor === 'true' || $valor === 1 || $valor === '1';
    }

    public static function enTransaccion(): bool
    {
        return self::$profundidad > 0;
    }

    /**
     * Ejecuta $fn en una transacción. Si ya hay una abierta, se une a ella (un servicio puede
     * llamar a otro sin romper la atomicidad). Cualquier excepción deshace todo.
     *
     * @param callable(PDO):mixed $fn
     */
    public static function transaccion(callable $fn): mixed
    {
        $pdo = self::obtener();
        if (self::$profundidad > 0) {
            return $fn($pdo);
        }

        $pdo->beginTransaction();
        self::$profundidad++;
        try {
            $resultado = $fn($pdo);
            $pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        } finally {
            self::$profundidad--;
        }
    }
}
