<?php
// backend/nucleo/Auditoria.php
declare(strict_types=1);

namespace Nucleo;

/**
 * ÚNICO que escribe en `auditoria` (RN-07). La tabla es solo-INSERT para el usuario de la aplicación
 * (database/grants.sql). Registra lo que `evento` no puede, porque `evento` exige una incidencia:
 * altas y cambios de rol de usuarios, bajas y reactivaciones de equipos, cambios de criticidad, ubicaciones.
 *
 * Misma forma que Eventos: se llama SIEMPRE dentro de la transacción del cambio que registra,
 * para que o quedan los dos o no queda ninguno. $datos es solo detalle que nadie filtra.
 */
final class Auditoria
{
    /** @param array<string,mixed> $datos detalle libre (p. ej. valores antes y después) */
    public static function registrar(
        int $usuarioId,
        string $entidad,
        int $entidadId,
        string $accion,
        ?string $motivo = null,
        array $datos = [],
    ): int {
        if (!Conexion::enTransaccion()) {
            throw new \LogicException('Auditoria::registrar debe llamarse dentro de Conexion::transaccion().');
        }

        $st = Conexion::obtener()->prepare(
            'INSERT INTO auditoria (usuario_id, entidad, entidad_id, accion, motivo, datos, fecha)
             VALUES (?, ?, ?, ?, ?, ?::jsonb, ?)
             RETURNING id'
        );
        $st->execute([
            $usuarioId,
            $entidad,
            $entidadId,
            $accion,
            $motivo,
            json_encode($datos === [] ? new \stdClass() : $datos, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            Reloj::paraBd(),
        ]);

        return (int) $st->fetchColumn();
    }
}