<?php
// backend/nucleo/Cambios.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Acumula lo que de verdad cambia en una edición (PATCH) y lo deja en auditoría: lo que llega igual que está
 * no escribe nada ni deja rastro. Antes cada Servicio armaba a mano `$nuevos` y `$registros`.
 *
 *   $c = new Cambios();
 *   $c->campo('nombre', $actual['nombre'], $nombreNuevo, 'cambiar_nombre');
 *   if (!$c->hay()) { return $actualComoApi; }
 *   if ($repo->actualizar($id, $c->nuevos()) !== 1) { throw Errores::modificadoPorOtro('el usuario'); }
 *   $c->auditar($actor['id'], 'usuario', $id);     // dentro de la misma transacción
 */
final class Cambios
{
    /** @var array<string,mixed> columna => valor nuevo */
    private array $nuevos = [];

    /** @var list<array{0:string,1:array<string,mixed>}> [acción de auditoría, detalle] */
    private array $registros = [];

    /** Anota el cambio de UNA columna si el valor realmente cambia (comparación estricta). Devuelve si cambió. */
    public function campo(string $columna, mixed $antes, mixed $despues, string $accion): bool
    {
        if ($antes === $despues) {
            return false;
        }
        $this->nuevos[$columna] = $despues;
        $this->registros[] = [$accion, ['antes' => $antes, 'despues' => $despues]];
        return true;
    }

    /**
     * Varias columnas con un solo registro de auditoría (p. ej. la sede de un equipo sigue a su ubicación).
     * Quien llama ya comprobó que hay un cambio real.
     *
     * @param array<string,mixed> $columnas columna => valor nuevo
     * @param array<string,mixed> $detalle
     */
    public function varias(array $columnas, string $accion, array $detalle): void
    {
        $this->nuevos = array_merge($this->nuevos, $columnas);
        $this->registros[] = [$accion, $detalle];
    }

    public function hay(): bool
    {
        return $this->nuevos !== [];
    }

    /** @return array<string,mixed> */
    public function nuevos(): array
    {
        return $this->nuevos;
    }

    /** Un registro en `auditoria` por cada cambio, en el orden en que se anotaron. */
    public function auditar(int $actorId, string $entidad, int $entidadId): void
    {
        foreach ($this->registros as [$accion, $detalle]) {
            Auditoria::registrar($actorId, $entidad, $entidadId, $accion, null, $detalle);
        }
    }
}
