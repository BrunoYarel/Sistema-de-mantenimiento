<?php
// backend/modulos/catalogos/Servicio.php
declare(strict_types=1);

namespace Modulos\Catalogos;

use Nucleo\Auditoria;
use Nucleo\Conexion;
use Nucleo\Errores;
use Nucleo\Idempotencia;
use Nucleo\Politica;
use Nucleo\Texto;
use PDOException;

/**
 * Catálogos que usan TODOS los demás módulos y que no dependen de ninguno (por eso están abajo del grafo, B2):
 * las sedes y las ubicaciones. Los demás módulos NO leen las tablas `sede` ni `ubicacion`: preguntan aquí
 * (exigirSede, ubicacion, nombresDeUbicaciones).
 */
final class Servicio
{
    private const UBICACION_REPETIDA = 'Ya existe una ubicación con ese nombre en esa sede.';

    /**
     * Restricciones de la base que este módulo sabe explicar (nombre => [campo, mensaje]). Normalmente no llegan:
     * las comprobaciones previas las atajan. Quedan por si dos peticiones se cruzan. El resto lo traduce Errores::desdePdo.
     */
    private const RESTRICCIONES = [
        'ubicacion_sede_id_nombre_key' => ['nombre', self::UBICACION_REPETIDA],
    ];

    private Repositorio $repo;

    public function __construct()
    {
        $this->repo = new Repositorio();
    }

    /**
     * Nombre de ubicación: sin espacios sobrantes ("Lab   2 " -> "Lab 2"). Mayúsculas y tildes se respetan como
     * se escribieron, pero dos nombres que solo difieren en mayúsculas cuentan como el mismo (ver exigirNombreLibre).
     */
    public static function normalizarNombre(string $nombre): string
    {
        return Texto::espacios($nombre);
    }

    // ------------------------------------------------------------------ sedes

    /**
     * Todas las sedes, para cualquier usuario con sesión. usuario.sede_id no restringe nada: es solo la sede
     * por defecto (preseleccionada) y puede estar vacía.
     *
     * @return list<array{id:int,nombre:string}>
     */
    public function sedes(): array
    {
        return array_map([self::class, 'sedeAApi'], $this->repo->listar());
    }

    /**
     * 422 en `$campo` si la sede no existe. Con $bloquear, la fila de la sede queda bloqueada hasta el fin de la
     * transacción de quien llama: serializa las altas y renombres de ubicaciones de esa sede (ver Repositorio::bloquear).
     */
    public function exigirSede(int $id, bool $bloquear = false, string $campo = 'sede_id'): void
    {
        $existe = $bloquear ? $this->repo->bloquear($id) : $this->repo->existe($id);
        if (!$existe) {
            throw Errores::validacion([$campo => 'La sede no existe.']);
        }
    }

    // ------------------------------------------------------------------ ubicaciones

    /**
     * Ubicaciones de una sede (o de todas). Las piden quienes eligen un equipo y quienes gestionan equipos:
     * sin ninguno de los dos permisos, 403 (la ruta solo pide sesión: el jefe de informática gestiona equipos
     * pero no tiene equipo.elegir, y exigir uno solo de los dos dejaría a alguien fuera).
     *
     * @param array{rol:string} $usuario
     * @return list<array{id:int,sede_id:int,nombre:string}>
     */
    public function ubicaciones(array $usuario, ?int $sedeId): array
    {
        if (!Politica::puede($usuario, 'equipo.gestionar') && !Politica::puede($usuario, 'equipo.elegir')) {
            throw Errores::prohibido();
        }
        return array_map([self::class, 'ubicacionAApi'], $this->repo->listarUbicaciones($sedeId));
    }

    /**
     * Una ubicación, o null si no existe. Para otros módulos (equipos la usa al registrar o mover un equipo).
     *
     * @return ?array{id:int,sede_id:int,nombre:string}
     */
    public function ubicacion(int $id): ?array
    {
        $fila = $this->repo->obtenerUbicacion($id);
        return $fila === null ? null : self::ubicacionAApi($fila);
    }

    /**
     * Nombre de cada ubicación pedida, para que otros módulos muestren la de un equipo sin leer la tabla `ubicacion`.
     *
     * @param list<int> $ids
     * @return array<int,string> id => nombre
     */
    public function nombresDeUbicaciones(array $ids): array
    {
        return $this->repo->nombresDeUbicaciones(array_values(array_unique($ids)));
    }

    /**
     * Alta de una ubicación. Es un catálogo: nunca hay ubicación en texto libre (RF-04, RF-09). Cualquiera con
     * equipo.gestionar (lo exige la ruta). Queda en auditoría. Reintento con la misma clave = mismo resultado.
     *
     * @param array{id:int} $usuario
     * @return array{id:int,sede_id:int,nombre:string}
     */
    public function crearUbicacion(array $usuario, int $sedeId, string $nombre, string $claveIdempotencia): array
    {
        $nombre = Texto::exigir('nombre', $nombre);

        try {
            return Idempotencia::ejecutar(
                $usuario['id'],
                $claveIdempotencia,
                'ubicacion.crear',
                function () use ($usuario, $sedeId, $nombre): int {
                    $this->exigirSede($sedeId, bloquear: true);
                    $this->exigirNombreLibre($sedeId, $nombre, null);
                    $id = $this->repo->insertarUbicacion($sedeId, $nombre);
                    Auditoria::registrar($usuario['id'], 'ubicacion', $id, 'crear', null, ['sede_id' => $sedeId, 'nombre' => $nombre]);
                    return $id;
                },
                fn (int $id): array => $this->ubicacionPorId($id)
            );
        } catch (PDOException $e) {
            throw Errores::deRestriccion($e, self::RESTRICCIONES) ?? $e;
        }
    }

    /**
     * Renombra una ubicación. La sede de una ubicación no cambia (sus equipos dependen de ella).
     * Un nombre igual al actual no escribe nada ni deja rastro.
     *
     * @param array{id:int} $usuario
     * @return array{id:int,sede_id:int,nombre:string}
     */
    public function renombrarUbicacion(array $usuario, int $id, string $nombre): array
    {
        $nombre = Texto::exigir('nombre', $nombre);

        try {
            return Conexion::transaccion(function () use ($usuario, $id, $nombre): array {
                $actual = $this->repo->obtenerUbicacion($id, true) ?? throw Errores::noEncontrado('No se encontró la ubicación.');
                if ($actual['nombre'] === $nombre) {
                    return self::ubicacionAApi($actual);
                }

                $sedeId = (int) $actual['sede_id'];
                $this->exigirSede($sedeId, bloquear: true);
                $this->exigirNombreLibre($sedeId, $nombre, $id);

                if ($this->repo->renombrarUbicacion($id, $nombre) !== 1) {
                    throw Errores::modificadoPorOtro('la ubicación');
                }
                Auditoria::registrar($usuario['id'], 'ubicacion', $id, 'renombrar', null, ['antes' => (string) $actual['nombre'], 'despues' => $nombre]);

                return self::ubicacionAApi(['nombre' => $nombre] + $actual);
            });
        } catch (PDOException $e) {
            throw Errores::deRestriccion($e, self::RESTRICCIONES) ?? $e;
        }
    }

    // ------------------------------------------------------------------ interno

    /** Dentro de la sede, "Lab 2" y "lab 2" son la misma ubicación: así no nacen duplicados que solo difieren en mayúsculas. */
    private function exigirNombreLibre(int $sedeId, string $nombre, ?int $exceptoId): void
    {
        if ($this->repo->existeNombreUbicacion($sedeId, $nombre, $exceptoId)) {
            throw Errores::validacion(['nombre' => self::UBICACION_REPETIDA]);
        }
    }

    /** @return array{id:int,sede_id:int,nombre:string} */
    private function ubicacionPorId(int $id): array
    {
        return $this->ubicacion($id) ?? throw Errores::noEncontrado('No se encontró la ubicación.');
    }

    /** SERIALIZADOR: la forma exacta que ve el frontend. */
    private static function sedeAApi(array $fila): array
    {
        return ['id' => (int) $fila['id'], 'nombre' => (string) $fila['nombre']];
    }

    /** SERIALIZADOR: la forma exacta que ve el frontend. */
    private static function ubicacionAApi(array $fila): array
    {
        return ['id' => (int) $fila['id'], 'sede_id' => (int) $fila['sede_id'], 'nombre' => (string) $fila['nombre']];
    }
}