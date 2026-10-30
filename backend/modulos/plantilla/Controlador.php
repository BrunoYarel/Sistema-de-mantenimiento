<?php
// backend/modulos/plantilla/Controlador.php
declare(strict_types=1);

namespace Modulos\Plantilla;

use Nucleo\Solicitud;
use Nucleo\Validar;

/**
 * CONTROLADOR: solo HTTP (B1). Lee la Solicitud, valida el FORMATO de lo recibido, llama al Servicio.
 * Sin SQL y sin reglas de negocio. El permiso por rol ya lo comprobó el Router.
 */
final class Controlador
{
    public function listar(Solicitud $s): array
    {
        return (new Servicio())->listar();
    }

    public function ver(Solicitud $s): array
    {
        return (new Servicio())->ver($s->paramEntero('id'));
    }

    public function crear(Solicitud $s): array
    {
        $v = new Validar($s->cuerpo());
        $nombre = $v->texto('nombre', max: 80);
        $v->exigir(); // 422 con { campos: { nombre: "Obligatorio." } }

        return (new Servicio())->crear((string) $nombre, $s->usuario, (string) $s->claveIdempotencia);
    }
}
