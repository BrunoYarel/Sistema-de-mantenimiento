<?php
// backend/modulos/equipos/Controlador.php
declare(strict_types=1);

namespace Modulos\Equipos;

use Nucleo\Dominio;
use Nucleo\Errores;
use Nucleo\Paginacion;
use Nucleo\Solicitud;
use Nucleo\Validar;

/** Solo HTTP (B1): valida el formato, llama al Servicio y devuelve datos. */
final class Controlador
{
    /** Igual que el filtro `q` de la búsqueda: un código más largo no se podría buscar completo. */
    private const MAX_CODIGO = 50;
    private const MAX_MOTIVO = 300;

    public function listar(Solicitud $s): array
    {
        $v = new Validar($s->query);
        $sede = $v->entero('sede', false);
        $ubicacion = $v->entero('ubicacion', false);
        $tipo = $v->enum('tipo', Dominio::TIPOS_EQUIPO, false);
        $q = $v->texto('q', false, max: 50);
        $activo = $v->enum('activo', ['true', 'false'], false);
        $pagina = Paginacion::desdeQuery($v);
        $v->exigir();

        return (new Servicio())->equipos(
            $s->usuario,
            ['sede' => $sede, 'ubicacion' => $ubicacion, 'tipo' => $tipo, 'q' => $q, 'activo' => $activo === null ? null : $activo === 'true'],
            $pagina
        );
    }

    /** Equipos activos con al menos una incidencia abierta. Filtros opcionales: sede, ubicacion, tipo, pagina. */
    public function fueraDeServicio(Solicitud $s): array
    {
        $v = new Validar($s->query);
        $sede = $v->entero('sede', false);
        $ubicacion = $v->entero('ubicacion', false);
        $tipo = $v->enum('tipo', Dominio::TIPOS_EQUIPO, false);
        $pagina = Paginacion::desdeQuery($v);
        $v->exigir();

        return (new Servicio())->fueraDeServicio(['sede' => $sede, 'ubicacion' => $ubicacion, 'tipo' => $tipo], $pagina);
    }

    public function historial(Solicitud $s): array
    {
        $id = $s->paramEntero('id');
        $v = new Validar($s->query);
        $pagina = Paginacion::desdeQuery($v);
        $v->exigir();

        return (new Servicio())->historial($id, $pagina);
    }

    public function ver(Solicitud $s): array
    {
        return (new Servicio())->ver($s->paramEntero('id'));
    }

    public function crear(Solicitud $s): array
    {
        $v = new Validar($s->cuerpo());
        $codigo = $v->texto('codigo', max: self::MAX_CODIGO);
        $tipo = $v->enum('tipo', Dominio::TIPOS_EQUIPO);
        $ubicacionId = $v->entero('ubicacion_id');
        $criticidad = $v->enum('criticidad', Dominio::CRITICIDADES);
        $v->exigir();

        return (new Servicio())->crearEquipo(
            $s->usuario,
            (string) $codigo,
            (string) $tipo,
            (int) $ubicacionId,
            (string) $criticidad,
            (string) $s->claveIdempotencia
        );
    }

    /** Baja de un equipo (RN-07: no se borra). El motivo es obligatorio. */
    public function baja(Solicitud $s): array
    {
        $id = $s->paramEntero('id');
        $v = new Validar($s->cuerpo());
        $motivo = $v->texto('motivo', max: self::MAX_MOTIVO);
        $v->exigir();

        return (new Servicio())->darDeBaja($s->usuario, $id, (string) $motivo);
    }

    /** Reactiva un equipo dado de baja. El motivo es opcional. */
    public function reactivar(Solicitud $s): array
    {
        $id = $s->paramEntero('id');
        $v = new Validar($s->cuerpo());
        $motivo = $v->texto('motivo', false, max: self::MAX_MOTIVO);
        $v->exigir();

        return (new Servicio())->reactivarEquipo($s->usuario, $id, $motivo);
    }

    /**
     * Edita tipo, ubicación y criticidad; solo cambia lo que llega. El código no se edita: si viene, el Servicio
     * comprueba que sea el actual. La sede no se envía: sigue a la ubicación. Cualquier otro campo se ignora.
     */
    public function editar(Solicitud $s): array
    {
        $id = $s->paramEntero('id');
        $v = new Validar($s->cuerpo());
        $tipo = $v->enum('tipo', Dominio::TIPOS_EQUIPO, false);
        $ubicacionId = $v->entero('ubicacion_id', false);
        $criticidad = $v->enum('criticidad', Dominio::CRITICIDADES, false);
        $codigo = $v->texto('codigo', false, max: self::MAX_CODIGO);
        $v->exigir();

        $cambios = array_filter(
            ['tipo' => $tipo, 'ubicacion_id' => $ubicacionId, 'criticidad' => $criticidad],
            static fn (mixed $valor): bool => $valor !== null
        );
        if ($cambios === []) {
            throw Errores::validacion([], 'Indica qué cambiar: tipo, ubicacion_id o criticidad.');
        }

        return (new Servicio())->editarEquipo($s->usuario, $id, $cambios, $codigo);
    }
}