<?php
// backend/nucleo/ErrorApi.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Error esperado que se responde con el formato del contrato:
 *   { "error": { "codigo", "mensaje", "campos": { "campo": "texto" } } }
 * y, en un 409 de transición, un "data" con el recurso actual.
 * Se crea con las fábricas de Errores (Errores::noEncontrado(), Errores::validacion()...).
 */
final class ErrorApi extends \RuntimeException
{
    /**
     * @param array<string,string> $campos errores por campo (422)
     * @param mixed $datos recurso actual (409): la pantalla dice "ya estaba hecho", no "error"
     */
    public function __construct(
        public int $estado,
        public string $codigo,
        string $mensaje,
        public array $campos = [],
        public mixed $datos = null,
        public ?int $esperaSegundos = null,
    ) {
        parent::__construct($mensaje);
    }
}
