<?php
// backend/nucleo/Config.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Configuración leída de backend/.env (no se sube al repositorio) o de variables de entorno.
 * Ningún secreto vive en el código.
 */
final class Config
{
    /** @var array<string,string> */
    private static array $valores = [];

    public static function cargar(string $archivoEnv): void
    {
        self::$valores = [];
        if (!is_file($archivoEnv)) {
            return;
        }
        foreach (file($archivoEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $linea = trim($linea);
            if ($linea === '' || $linea[0] === '#' || !str_contains($linea, '=')) {
                continue;
            }
            [$clave, $valor] = explode('=', $linea, 2);
            self::$valores[trim($clave)] = trim(trim($valor), "\"'");
        }
    }

    public static function get(string $clave, ?string $porDefecto = null): ?string
    {
        if (array_key_exists($clave, self::$valores)) {
            return self::$valores[$clave];
        }
        $env = getenv($clave);
        return $env !== false ? $env : $porDefecto;
    }

    public static function requerido(string $clave): string
    {
        $valor = self::get($clave);
        if ($valor === null || $valor === '') {
            throw new \RuntimeException("Falta la variable de configuración $clave (ver backend/.env.example).");
        }
        return $valor;
    }

    /** APP_ENV=local habilita el módulo plantilla y los mensajes de desarrollo. Por defecto: producción. */
    public static function esLocal(): bool
    {
        return self::get('APP_ENV', 'produccion') === 'local';
    }
}
