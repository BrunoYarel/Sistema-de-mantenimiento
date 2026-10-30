<?php
// backend/modulos/auth/Controlador.php
declare(strict_types=1);

namespace Modulos\Auth;

use Nucleo\Solicitud;
use Nucleo\Validar;

/** Solo HTTP (B1): lee la Solicitud, valida el formato, llama al Servicio y devuelve datos. */
final class Controlador
{
    public function me(Solicitud $s): array
    {
        return Servicio::perfil($s->usuario);
    }

    public function login(Solicitud $s): array
    {
        $v = new Validar($s->cuerpo());
        $usuario = $v->texto('usuario', max: 60);
        $clave = $v->texto('clave', max: 200, recortar: false); // una clave no se recorta
        $v->exigir();

        return (new Servicio())->login((string) $usuario, (string) $clave, $s->ip);
    }

    public function logout(Solicitud $s): mixed
    {
        (new Servicio())->logout();
        return null;
    }

    public function clave(Solicitud $s): array
    {
        $v = new Validar($s->cuerpo());
        $actual = $v->texto('clave_actual', max: 200, recortar: false);
        $nueva = $v->texto('clave_nueva', max: 200, recortar: false);
        $v->exigir();

        return (new Servicio())->cambiarClave($s->usuario, (string) $actual, (string) $nueva);
    }
}
