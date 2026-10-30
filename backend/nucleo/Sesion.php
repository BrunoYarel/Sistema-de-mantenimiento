<?php
// backend/nucleo/Sesion.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Sesión PHP nativa (RNF-01). En la sesión solo viaja el id del usuario y la hora de inicio:
 * rol, permisos y estado activo se leen de la base en cada petición, así desactivar a alguien
 * (RF-12) o cambiarle el rol surte efecto de inmediato.
 *
 * - Cookie HttpOnly + SameSite=Strict (+ Secure si se sirve por HTTPS).
 * - La lectura abre y cierra la sesión enseguida (read_and_close): si no, el bloqueo del archivo
 *   de sesión serializa las peticiones paralelas del SPA.
 * - Al iniciar sesión o cambiar la clave se regenera el id (fijación de sesión).
 */
final class Sesion
{
    private const NOMBRE = 'CASOA_SID';

    private static bool $configurada = false;
    private static bool $cargado = false;
    private static ?array $usuario = null;

    /** Usuario autenticado de esta petición, o null. Forma: ver cargarUsuario(). */
    public static function usuarioActual(): ?array
    {
        if (self::$cargado) {
            return self::$usuario;
        }
        self::$cargado = true;

        $datos = self::leer();
        if (empty($datos['uid']) || empty($datos['inicio'])) {
            return self::$usuario = null;
        }
        if (Reloj::ahora()->getTimestamp() - (int) $datos['inicio'] > self::duracion()) {
            return self::$usuario = null;
        }
        return self::$usuario = self::cargarUsuario((int) $datos['uid']);
    }

    /** @return array{id:int,nombre:string,usuario:string,rol:string,sede_id:?int,debe_cambiar_clave:bool,permisos:list<string>}|null */
    public static function cargarUsuario(int $id): ?array
    {
        $st = Conexion::obtener()->prepare(
            'SELECT id, nombre, usuario, rol, sede_id, activo, debe_cambiar_clave FROM usuario WHERE id = ?'
        );
        $st->execute([$id]);
        $fila = $st->fetch();
        if (!$fila || !Conexion::aBool($fila['activo'])) {
            return null;
        }
        return [
            'id' => (int) $fila['id'],
            'nombre' => (string) $fila['nombre'],
            'usuario' => (string) $fila['usuario'],
            'rol' => (string) $fila['rol'],
            'sede_id' => $fila['sede_id'] === null ? null : (int) $fila['sede_id'],
            'debe_cambiar_clave' => Conexion::aBool($fila['debe_cambiar_clave']),
            'permisos' => Politica::permisosDe((string) $fila['rol']),
        ];
    }

    /** Crea una sesión nueva (login o cambio de clave). Siempre con id nuevo. */
    public static function iniciar(int $usuarioId): void
    {
        self::configurar();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        session_regenerate_id(true);
        $_SESSION = ['uid' => $usuarioId, 'inicio' => Reloj::ahora()->getTimestamp()];
        session_write_close();
        self::$cargado = false;
        self::$usuario = null;
    }

    public static function cerrar(): void
    {
        self::configurar();
        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!isset($_COOKIE[self::NOMBRE])) {
                return;
            }
            session_start();
        }
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(self::NOMBRE, '', [
            'expires' => 1,
            'path' => $p['path'],
            'secure' => (bool) $p['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_destroy();
        self::$cargado = true;
        self::$usuario = null;
    }

    // ------------------------------------------------------------------ interno

    private static function leer(): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return $_SESSION;
        }
        self::configurar();
        if (!isset($_COOKIE[self::NOMBRE])) {
            return [];
        }
        session_start(['read_and_close' => true]);
        return is_array($_SESSION ?? null) ? $_SESSION : [];
    }

    private static function configurar(): void
    {
        if (self::$configurada) {
            return;
        }
        $dir = dirname(__DIR__) . '/storage/sesiones';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        session_save_path($dir);
        session_name(self::NOMBRE);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::duracion());
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => self::esHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        self::$configurada = true;
    }

    private static function esHttps(): bool
    {
        if (Config::get('COOKIE_SEGURA') === '1') {
            return true;
        }
        return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    }

    /** Segundos de vida de una sesión (por defecto una jornada: 8 horas). */
    private static function duracion(): int
    {
        return max(5, (int) Config::get('SESION_MINUTOS', '480')) * 60;
    }
}
