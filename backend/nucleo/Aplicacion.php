<?php
// backend/nucleo/Aplicacion.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Arranque. backend/public/index.php llama a ejecutar(); los scripts de bin/ llaman a iniciarCli().
 * Antes de usar esta clase hay que cargar backend/nucleo/autoload.php.
 */
final class Aplicacion
{
    /** Configuración y zona horaria, sin manejadores HTTP. Lo usan bin/ y tests/. */
    public static function iniciarCli(): void
    {
        Config::cargar(dirname(__DIR__) . '/.env');
        Reloj::configurar();
    }

    public static function ejecutar(): void
    {
        self::iniciarCli();
        Errores::registrar();

        try {
            $solicitud = Solicitud::desdeGlobales();
            $router = new Router();

            /** @var list<string> $ids */
            $ids = require dirname(__DIR__) . '/modulos/index.php';
            foreach ($ids as $id) {
                if (preg_match('/^[a-z_]+$/', $id) !== 1) {
                    throw new \LogicException("Id de módulo inválido: $id");
                }
                $registrar = require dirname(__DIR__) . "/modulos/$id/rutas.php";
                $registrar($router);
            }

            $router->despachar($solicitud);
        } catch (\Throwable $t) {
            Errores::manejar($t);
        }
    }
}
