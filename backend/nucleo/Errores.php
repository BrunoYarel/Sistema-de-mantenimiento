<?php
// backend/nucleo/Errores.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Fábricas de errores del contrato, manejador global y log.
 *
 * REGLA B6: 401 significa SOLO "no hay sesión". Una clave actual incorrecta o un error de negocio
 * nunca es 401 (usa validacion() o prohibido()): el frontend lo toma como sesión vencida.
 * La única excepción es el login con credenciales inválidas, que está en el contrato.
 */
final class Errores
{
    public static function sinSesion(string $mensaje = 'Debes iniciar sesión.'): ErrorApi
    {
        return new ErrorApi(401, 'sin_sesion', $mensaje);
    }

    /** Solo para POST /auth/login. */
    public static function credenciales(): ErrorApi
    {
        return new ErrorApi(401, 'credenciales_invalidas', 'Usuario o clave incorrectos.');
    }

    public static function prohibido(string $codigo = 'prohibido', string $mensaje = 'No tienes permiso para esta acción.'): ErrorApi
    {
        return new ErrorApi(403, $codigo, $mensaje);
    }

    /** También para lo que está fuera del ámbito del usuario (RN-09): no se revela que existe. */
    public static function noEncontrado(string $mensaje = 'No se encontró.'): ErrorApi
    {
        return new ErrorApi(404, 'no_encontrado', $mensaje);
    }

    public static function conflicto(string $mensaje, mixed $datos = null, string $codigo = 'conflicto'): ErrorApi
    {
        return new ErrorApi(409, $codigo, $mensaje, [], $datos);
    }

    /**
     * Otra petición cambió el registro entre que se leyó y se escribió (UPDATE con rowCount() = 0).
     * $sujeto con su artículo: 'el equipo', 'la ubicación', 'el usuario'.
     */
    public static function modificadoPorOtro(string $sujeto): ErrorApi
    {
        return self::conflicto("Otra persona modificó $sujeto mientras tanto. Recarga e inténtalo de nuevo.");
    }

    /** @param array<string,string> $campos */
    public static function validacion(array $campos, string $mensaje = 'Revisa los datos enviados.'): ErrorApi
    {
        return new ErrorApi(422, 'validacion', $mensaje, $campos);
    }

    public static function limite(int $esperaSegundos): ErrorApi
    {
        return new ErrorApi(429, 'demasiados_intentos', 'Demasiados intentos. Espera un momento.', [], null, $esperaSegundos);
    }

    public static function interno(): ErrorApi
    {
        return new ErrorApi(500, 'error_interno', 'Ocurrió un error. Inténtalo de nuevo.');
    }

    // ------------------------------------------------------------------ arranque

    public static function registrar(): void
    {
        error_reporting(E_ALL);
        ini_set('display_errors', '0'); // B3: nunca se muestran errores al navegador

        set_error_handler(static function (int $nivel, string $mensaje, string $archivo, int $linea): bool {
            if (!(error_reporting() & $nivel)) {
                return false;
            }
            throw new \ErrorException($mensaje, 0, $nivel, $archivo, $linea);
        });

        set_exception_handler([self::class, 'manejar']);

        register_shutdown_function(static function (): void {
            $ultimo = error_get_last();
            if ($ultimo !== null && in_array($ultimo['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::log('error', "fatal: {$ultimo['message']} en {$ultimo['file']}:{$ultimo['line']}");
                if (!headers_sent()) {
                    Respuesta::error(self::interno());
                }
            }
        });
    }

    /** El handler global siempre responde JSON (B3). */
    public static function manejar(\Throwable $t): void
    {
        if ($t instanceof ErrorApi) {
            Respuesta::error($t);
            return;
        }
        self::log('error', self::describir($t));
        Respuesta::error($t instanceof \PDOException ? self::desdePdo($t) : self::interno());
    }

    // ------------------------------------------------------------------ PostgreSQL

    public static function sqlstate(\PDOException $e): string
    {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }

    /** Traducción por defecto. Los servicios capturan antes los casos que conocen con deRestriccion() (p. ej. código de equipo repetido). */
    public static function desdePdo(\PDOException $e): ErrorApi
    {
        return match (self::sqlstate($e)) {
            '23505' => new ErrorApi(409, 'duplicado', 'Ya existe un registro con esos datos.'),
            '23503' => new ErrorApi(422, 'referencia_invalida', 'Se hace referencia a un dato que no existe.'),
            '23502', '23514' => new ErrorApi(422, 'dato_invalido', 'Algún dato no cumple las reglas del sistema.'),
            '40001', '40P01' => new ErrorApi(409, 'reintentar', 'Hubo un cruce con otra operación. Inténtalo de nuevo.'),
            '55P03' => new ErrorApi(409, 'ocupado', 'Otro usuario está modificando este registro. Inténtalo de nuevo.'),
            default => self::interno(),
        };
    }

    /**
     * Traduce una violación de restricción (SQLSTATE 23xxx) a un 422 por campo, según el mapa del módulo.
     * Devuelve null si ninguna restricción del mapa aparece en el error: quien llama relanza el original
     * (`throw Errores::deRestriccion($e, self::RESTRICCIONES) ?? $e;`) y lo traduce desdePdo().
     *
     * @param array<string,array{0:string,1:string}> $mapa nombre de la restricción => [campo, mensaje]
     */
    public static function deRestriccion(\PDOException $e, array $mapa): ?ErrorApi
    {
        if (!str_starts_with(self::sqlstate($e), '23')) {
            return null;
        }
        foreach ($mapa as $restriccion => [$campo, $mensaje]) {
            if (str_contains($e->getMessage(), (string) $restriccion)) {
                return self::validacion([$campo => $mensaje]);
            }
        }
        return null;
    }

    // ------------------------------------------------------------------ log

    /** Log en backend/storage/logs/app.log. Nunca pases claves ni cuerpos de petición. */
    public static function log(string $nivel, string $mensaje): void
    {
        $mensaje = str_replace(["\r", "\n"], ' ', $mensaje);
        $linea = sprintf("[%s] %s %s\n", Reloj::ahora()->format('c'), strtoupper($nivel), $mensaje);
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (@file_put_contents($dir . '/app.log', $linea, FILE_APPEND | LOCK_EX) === false) {
            error_log(trim($linea));
        }
    }

    private static function describir(\Throwable $t): string
    {
        $extra = $t instanceof \PDOException ? ' [SQLSTATE ' . self::sqlstate($t) . ']' : '';
        return get_class($t) . $extra . ': ' . $t->getMessage() . ' en ' . $t->getFile() . ':' . $t->getLine();
    }
}
