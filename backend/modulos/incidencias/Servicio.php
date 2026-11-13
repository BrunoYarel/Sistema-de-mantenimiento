<?php
// backend/modulos/incidencias/Servicio.php
declare(strict_types=1);

namespace Modulos\Incidencias;

use Nucleo\Dominio;
use Nucleo\Reloj;

/**
 * Reglas del módulo (B1). Hoy solo ofrece las lecturas que otros módulos piden (B2: ellos llaman a estas funciones y
 * no leen la tabla `incidencia`). El registro, las listas y las acciones se agregan aquí al construir el módulo.
 */
final class Servicio
{
    private Repositorio $repo;

    public function __construct()
    {
        $this->repo = new Repositorio();
    }

    /**
     * Incidencias abiertas (Dominio::ESTADOS_ABIERTOS: ni Resuelta ni Cerrada) asignadas a un técnico, para que usuarios
     * pueda aplicar RN-02: un técnico con incidencias abiertas no se desactiva ni deja de ser técnico. Las más antiguas primero.
     *
     * Quien llama lo hace dentro de la transacción que bloquea al usuario. Al construir la acción "asignar", debe
     * comprobar en SU transacción que el técnico sigue activo (SELECT ... FOR KEY SHARE vía el Servicio de usuarios): así
     * una asignación y una desactivación a la vez no dejan una incidencia huérfana.
     *
     * @return list<array{id:int,equipo_id:int,equipo:string,estado:string,prioridad:string,fecha_registro:string}>
     */
    public function abiertasDeTecnico(int $tecnicoId): array
    {
        return array_map(
            static fn (array $fila): array => [
                'id' => (int) $fila['id'],
                'equipo_id' => (int) $fila['equipo_id'],
                'equipo' => (string) $fila['equipo'],
                'estado' => (string) $fila['estado'],
                'prioridad' => (string) $fila['prioridad'],
                'fecha_registro' => (string) Reloj::iso($fila['fecha_registro']),
            ],
            $this->repo->deTecnicoEnEstados($tecnicoId, Dominio::ESTADOS_ABIERTOS)
        );
    }
}
