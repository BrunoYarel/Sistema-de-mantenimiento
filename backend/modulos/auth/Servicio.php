<?php
// backend/modulos/auth/Servicio.php
declare(strict_types=1);

namespace Modulos\Auth;

use DateTimeImmutable;
use Nucleo\Config;
use Nucleo\Conexion;
use Nucleo\Errores;
use Nucleo\Reloj;
use Nucleo\Sesion;

/**
 * Login, logout y cambio de clave (RNF-01).
 * - Límite de intentos por (usuario, IP), no por usuario solo. Se acepta y documenta que un atacante
 *   interno en la misma IP puede bloquear al jefe; hay desbloqueo manual: php bin/usuarios.php desbloquear <usuario>
 *   y, para quien tiene usuario.gestionar, POST /usuarios/{id}/desbloquear (lo llama el módulo usuarios).
 * - Las credenciales inválidas responden igual exista o no el usuario, esté activo o no.
 */
final class Servicio
{
    /** Costo fijo: igual en todos los PHP y el mismo que el hash de relleno de abajo. */
    public const COSTO_BCRYPT = 12;

    /** Hash válido de una clave que nadie conoce: se verifica contra él cuando el usuario no existe. */
    private const HASH_FALSO = '$2y$12$M0.OH3tzscdIYZmQLLck4eZeJE4PbGxrnh2g2GHlXs6pEiNQTnfMi';

    public const CLAVE_MINIMO = 10;

    private Repositorio $repo;

    public function __construct()
    {
        $this->repo = new Repositorio();
    }

    public static function hashear(string $clave): string
    {
        return password_hash($clave, PASSWORD_BCRYPT, ['cost' => self::COSTO_BCRYPT]);
    }

    /**
     * Clave temporal: 14 caracteres aleatorios (letras y números). ÚNICO lugar que la genera: la usan el alta y el
     * restablecimiento de usuarios (módulo usuarios) y bin/usuarios.php. Se muestra UNA sola vez y no se guarda en claro.
     */
    public static function claveTemporal(): string
    {
        return substr(strtr(base64_encode(random_bytes(12)), '+/=', 'xyz'), 0, 14);
    }

    /** Forma exacta del contrato: GET /me y respuesta de login. */
    public static function perfil(?array $usuario): array
    {
        if ($usuario === null) {
            throw Errores::sinSesion();
        }
        return [
            'id' => $usuario['id'],
            'nombre' => $usuario['nombre'],
            'rol' => $usuario['rol'],
            'permisos' => $usuario['permisos'],
            'debe_cambiar_clave' => $usuario['debe_cambiar_clave'],
        ];
    }

    public function login(string $usuario, string $clave, string $ip): array
    {
        $usuario = mb_strtolower(trim($usuario));
        $ahora = Reloj::ahora();

        $espera = $this->repo->segundosDeBloqueo($usuario, $ip, $ahora);
        if ($espera > 0) {
            throw Errores::limite($espera);
        }

        $fila = $this->repo->buscarPorUsuario($usuario);
        $hash = (string) ($fila['clave_hash'] ?? '') !== '' ? (string) $fila['clave_hash'] : self::HASH_FALSO;
        $correcta = password_verify($clave, $hash); // siempre se verifica: el tiempo no delata si el usuario existe

        if ($fila === null || !Conexion::aBool($fila['activo']) || $fila['clave_hash'] === null || !$correcta) {
            $this->registrarFallo($usuario, $ip, $ahora);
            throw Errores::credenciales();
        }

        $this->repo->limpiarBloqueo($usuario, $ip);
        if (password_needs_rehash((string) $fila['clave_hash'], PASSWORD_BCRYPT, ['cost' => self::COSTO_BCRYPT])) {
            $this->repo->guardarClave((int) $fila['id'], self::hashear($clave), Conexion::aBool($fila['debe_cambiar_clave']));
        }

        Sesion::iniciar((int) $fila['id']);
        return self::perfil(Sesion::cargarUsuario((int) $fila['id']));
    }

    public function logout(): void
    {
        Sesion::cerrar();
    }

    /** B6: una clave actual incorrecta es 422 sobre el campo, NUNCA 401. */
    public function cambiarClave(array $usuario, string $actual, string $nueva): array
    {
        $hash = $this->repo->hashDe($usuario['id']);
        if ($hash === null || !password_verify($actual, $hash)) {
            throw Errores::validacion(['clave_actual' => 'La clave actual no es correcta.']);
        }
        if (mb_strlen($nueva) < self::CLAVE_MINIMO) {
            throw Errores::validacion(['clave_nueva' => 'Debe tener al menos ' . self::CLAVE_MINIMO . ' caracteres.']);
        }
        if (hash_equals($actual, $nueva)) {
            throw Errores::validacion(['clave_nueva' => 'Debe ser distinta de la actual.']);
        }

        $this->repo->guardarClave($usuario['id'], self::hashear($nueva), false);
        Sesion::iniciar($usuario['id']); // id de sesión nuevo
        return self::perfil(Sesion::cargarUsuario($usuario['id']));
    }

    /**
     * Deja una clave temporal NUEVA, obliga a cambiarla al entrar y la devuelve (el único momento en que existe en claro).
     * La usa el módulo usuarios (RF-12): quien restablece una clave no toca `clave_hash` por su cuenta.
     * La sesión abierta de esa persona sigue viva pero queda limitada a cambiar la clave (Router: debe_cambiar_clave).
     */
    public function restablecerClave(int $usuarioId): string
    {
        $temporal = self::claveTemporal();
        $this->repo->guardarClave($usuarioId, self::hashear($temporal), true);
        return $temporal;
    }

    /**
     * De esa lista de nombres de usuario, los bloqueados ahora mismo por intentos fallidos.
     *
     * @param list<string> $usuarios
     * @return list<string>
     */
    public function bloqueados(array $usuarios): array
    {
        return $this->repo->bloqueados($usuarios, Reloj::ahora());
    }

    /** Libera el bloqueo y los fallos de un usuario en todas las IP. Devuelve cuántas filas tenían algo que liberar. */
    public function desbloquear(string $usuario): int
    {
        return $this->repo->liberarBloqueos(mb_strtolower(trim($usuario)));
    }

    private function registrarFallo(string $usuario, string $ip, DateTimeImmutable $ahora): void
    {
        $maximo = (int) Config::get('LOGIN_MAX_FALLOS', '5');
        $minutos = (int) Config::get('LOGIN_BLOQUEO_MINUTOS', '15');
        $bloqueado = $this->repo->registrarFallo($usuario, $ip, $ahora, $maximo, $minutos);

        $usuarioLog = preg_replace('/[^\w.@-]/u', '?', mb_substr($usuario, 0, 60));
        Errores::log($bloqueado ? 'aviso' : 'info', "login fallido usuario=$usuarioLog ip=$ip" . ($bloqueado ? ' (BLOQUEADO)' : ''));
    }
}
