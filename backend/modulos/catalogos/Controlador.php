<?php
// backend/modulos/catalogos/Controlador.php
declare(strict_types=1);

namespace Modulos\Catalogos;

use Nucleo\Solicitud;
use Nucleo\Validar;

/** Solo HTTP (B1): valida el formato, llama al Servicio y devuelve datos. */
final class Controlador
{
    private const MAX_NOMBRE_UBICACION = 80;

    public function sedes(Solicitud $s): array
    {
        return (new Servicio())->sedes();
    }

    public function ubicaciones(Solicitud $s): array
    {
        $v = new Validar($s->query);
        $sede = $v->entero('sede', false);
        $v->exigir();

        return (new Servicio())->ubicaciones($s->usuario, $sede);
    }

    public function crearUbicacion(Solicitud $s): array
    {
        $v = new Validar($s->cuerpo());
        $sedeId = $v->entero('sede_id');
        $nombre = $v->texto('nombre', max: self::MAX_NOMBRE_UBICACION);
        $v->exigir();

        return (new Servicio())->crearUbicacion($s->usuario, (int) $sedeId, (string) $nombre, (string) $s->claveIdempotencia);
    }

    public function renombrarUbicacion(Solicitud $s): array
    {
        $id = $s->paramEntero('id');
        $v = new Validar($s->cuerpo());
        $nombre = $v->texto('nombre', max: self::MAX_NOMBRE_UBICACION);
        $v->exigir();

        return (new Servicio())->renombrarUbicacion($s->usuario, $id, (string) $nombre);
    }
}