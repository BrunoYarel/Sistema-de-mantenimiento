<?php
// backend/nucleo/Respuesta.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Salida JSON del contrato:
 *   correcto -> { "data": ... }
 *   error    -> { "error": { "codigo", "mensaje", "campos" } }  (+ "data" en el 409 de transición)
 */
final class Respuesta
{
    /** @param array<string,string> $cabeceras */
    public static function enviar(int $estado, array $cuerpo, array $cabeceras = []): void
    {
        $json = json_encode(
            $cuerpo,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION
        );

        if (!headers_sent()) {
            http_response_code($estado);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            foreach ($cabeceras as $nombre => $valor) {
                header($nombre . ': ' . $valor);
            }
        }
        echo $json;
    }

    public static function ok(mixed $data, int $estado = 200): void
    {
        self::enviar($estado, ['data' => $data]);
    }

    public static function error(ErrorApi $e): void
    {
        $error = ['codigo' => $e->codigo, 'mensaje' => $e->getMessage(), 'campos' => (object) $e->campos];
        $cabeceras = [];
        if ($e->esperaSegundos !== null) {
            $error['espera_segundos'] = $e->esperaSegundos; // [P] alinear con lo que lea http.js (err.esperaSegundos)
            $cabeceras['Retry-After'] = (string) $e->esperaSegundos;
        }
        $cuerpo = ['error' => $error];
        if ($e->datos !== null) {
            $cuerpo['data'] = $e->datos;
        }
        self::enviar($e->estado, $cuerpo, $cabeceras);
    }
}
