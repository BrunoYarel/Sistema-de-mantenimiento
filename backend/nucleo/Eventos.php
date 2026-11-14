<?php
// backend/nucleo/Eventos.php
declare(strict_types=1);

namespace Nucleo;

/**
 * ÚNICO que escribe en `evento` (RN-07, RNF-06). La tabla es solo-INSERT para el usuario de la
 * aplicación (database/grants.sql). De este registro salen el historial y los indicadores.
 *
 * Lo que un indicador filtre va en columnas tipadas; $datos es solo para detalle que nadie filtra.
 * Se llama SIEMPRE dentro de la transacción de la transición, después del SELECT ... FOR UPDATE.
 */
final class Eventos
{
    /** @param array<string,mixed> $datos detalle libre (nunca algo que un indicador deba filtrar) */
    public static function registrar(
        int $incidenciaId,
        int $usuarioId,
        string $accion,
        ?string $estadoAntes,
        ?string $estadoDespues,
        ?string $motivo = null,
        array $datos = [],
    ): int {
        if (!Conexion::enTransaccion()) {
            throw new \LogicException('Eventos::registrar debe llamarse dentro de Conexion::transaccion().');
        }

        $st = Conexion::obtener()->prepare(
            'INSERT INTO evento (incidencia_id, usuario_id, accion, estado_antes, estado_despues, motivo, datos, fecha)
             VALUES (?, ?, ?, ?, ?, ?, ?::jsonb, ?)
             RETURNING id'
        );
        $st->execute([
            $incidenciaId,
            $usuarioId,
            $accion,
            $estadoAntes,
            $estadoDespues,
            $motivo,
            json_encode($datos === [] ? new \stdClass() : $datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            Reloj::paraBd(),
        ]);

        return (int) $st->fetchColumn();
    }
}
