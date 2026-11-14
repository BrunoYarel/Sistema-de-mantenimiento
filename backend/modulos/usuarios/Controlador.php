<?php
// backend/modulos/usuarios/Controlador.php
declare(strict_types=1);

namespace Modulos\Usuarios;

use Nucleo\Dominio;
use Nucleo\Errores;
use Nucleo\Paginacion;
use Nucleo\Solicitud;
use Nucleo\Validar;

/** Solo HTTP (B1): valida el formato, llama al Servicio y devuelve datos. */
final class Controlador
{
    private const MAX_NOMBRE = 100;
    /** Igual que el login (auth): un nombre de usuario más largo no se podría escribir al entrar. */
    private const MAX_USUARIO = 60;

    public function tecnicos(Solicitud $s): array
    {
        return (new Servicio())->tecnicos();
    }

    public function reportantes(Solicitud $s): array
    {
        $v = new Validar($s->query);
        $q = $v->texto('q', min: 2, max: 50);
        $v->exigir();

        return (new Servicio())->reportantes((string) $q);
    }

    public function listar(Solicitud $s): array
    {
        $v = new Validar($s->query);
        $q = $v->texto('q', false, max: 50);
        $rol = $v->enum('rol', Dominio::ROLES, false);
        $sede = $v->entero('sede', false);
        $activo = $v->enum('activo', ['true', 'false'], false);
        $pagina = Paginacion::desdeQuery($v);
        $v->exigir();

        return (new Servicio())->usuarios(
            ['q' => $q, 'rol' => $rol, 'sede' => $sede, 'activo' => $activo === null ? null : $activo === 'true'],
            $pagina
        );
    }

    public function ver(Solicitud $s): array
    {
        return (new Servicio())->usuario($s->paramEntero('id'));
    }

    public function crear(Solicitud $s): array
    {
        $v = new Validar($s->cuerpo());
        $nombre = $v->texto('nombre', max: self::MAX_NOMBRE);
        $usuario = $v->texto('usuario', max: self::MAX_USUARIO);
        $rol = $v->enum('rol', Dominio::ROLES);
        $sedeId = $v->entero('sede_id', false); // la sede por defecto es opcional
        $v->exigir();

        return (new Servicio())->crear(
            $s->usuario,
            (string) $nombre,
            (string) $usuario,
            (string) $rol,
            $sedeId,
            (string) $s->claveIdempotencia
        );
    }

    /**
     * Edita nombre, rol y sede por defecto; solo cambia lo que llega. `sede_id: null` quita la sede por defecto.
     * El nombre de usuario no se edita: si viene, el Servicio comprueba que sea el actual. Cualquier otro campo se ignora.
     */
    public function editar(Solicitud $s): array
    {
        $id = $s->paramEntero('id');
        $cuerpo = $s->cuerpo();
        $v = new Validar($cuerpo);

        // Un campo presente pero vacío es un error, no "no quiero cambiarlo": solo se lee lo que la clave indica.
        $cambios = [];
        if (array_key_exists('nombre', $cuerpo)) {
            $cambios['nombre'] = $v->texto('nombre', max: self::MAX_NOMBRE);
        }
        if (array_key_exists('rol', $cuerpo)) {
            $cambios['rol'] = $v->enum('rol', Dominio::ROLES);
        }
        if (array_key_exists('sede_id', $cuerpo)) {
            $cambios['sede_id'] = $cuerpo['sede_id'] === null || $cuerpo['sede_id'] === '' ? null : $v->entero('sede_id');
        }
        $usuario = $v->texto('usuario', false, max: self::MAX_USUARIO);
        $v->exigir();

        if ($cambios === []) {
            throw Errores::validacion([], 'Indica qué cambiar: nombre, rol o sede_id.');
        }

        return (new Servicio())->editar($s->usuario, $id, $cambios, $usuario);
    }

    public function desactivar(Solicitud $s): array
    {
        return (new Servicio())->desactivar($s->usuario, $s->paramEntero('id'));
    }

    public function reactivar(Solicitud $s): array
    {
        return (new Servicio())->reactivar($s->usuario, $s->paramEntero('id'));
    }

    public function restablecerClave(Solicitud $s): array
    {
        return (new Servicio())->restablecerClave($s->usuario, $s->paramEntero('id'));
    }

    public function desbloquear(Solicitud $s): array
    {
        return (new Servicio())->desbloquear($s->usuario, $s->paramEntero('id'));
    }
}
