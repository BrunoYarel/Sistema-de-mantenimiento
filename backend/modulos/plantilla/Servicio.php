<?php
// backend/modulos/plantilla/Servicio.php
declare(strict_types=1);

namespace Modulos\Plantilla;

use Nucleo\Errores;
use Nucleo\Idempotencia;
use PDOException;

/**
 * SERVICIO: reglas de negocio + transacción (B1). Llama al Repositorio de SU módulo y, para otros
 * módulos, a sus SERVICIOS (nunca a sus tablas, B2).
 *
 * Si esto fuera una transición de incidencia, dentro de la transacción irían, en este orden:
 *   1. SELECT ... FOR UPDATE de la fila (en el Repositorio)
 *   2. Politica::exigirAccion(...)        (403 / 409)
 *   3. UPDATE de columnas                 (rowCount() = 0 -> conflicto real, 409)
 *   4. Eventos::registrar(...)            (un evento por cambio)
 *   y todo envuelto en Idempotencia::ejecutar(...) si es un POST que inserta.
 */
final class Servicio
{
    private Repositorio $repo;

    public function __construct()
    {
        $this->repo = new Repositorio();
    }

    public function listar(): array
    {
        return array_map([self::class, 'aApi'], $this->repo->listar());
    }

    public function ver(int $id): array
    {
        $fila = $this->repo->obtener($id);
        if ($fila === null) {
            throw Errores::noEncontrado('No se encontró la sede.');
        }
        return self::aApi($fila);
    }

    public function crear(string $nombre, array $usuario, string $claveIdempotencia): array
    {
        try {
            return Idempotencia::ejecutar(
                $usuario['id'],
                $claveIdempotencia,
                'plantilla.crear_sede',
                fn (): int => $this->repo->insertar($nombre),
                fn (int $id): array => $this->ver($id)
            );
        } catch (PDOException $e) {
            // Traduce el error de la base a un error del contrato; lo desconocido se relanza.
            if (Errores::sqlstate($e) === '23505') {
                throw Errores::validacion(['nombre' => 'Ya existe una sede con ese nombre.']);
            }
            throw $e;
        }
    }

    /** SERIALIZADOR: la forma exacta que ve el frontend. Aquí se decide qué campos salen (RN-09). */
    private static function aApi(array $fila): array
    {
        return ['id' => (int) $fila['id'], 'nombre' => (string) $fila['nombre']];
    }
}
