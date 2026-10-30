<?php
// backend/nucleo/Idempotencia.php
declare(strict_types=1);

namespace Nucleo;

use PDO;

/**
 * Idempotencia de todo POST que inserta (RNF-07, D14, B7).
 *
 * Misma (usuario, clave) = mismo resultado, sin duplicar. La clave se guarda en la MISMA transacción
 * que el éxito: un rechazo (4xx) no la guarda, porque el frontend genera otra.
 *
 * Dos peticiones simultáneas con la misma clave se serializan con un advisory lock de PostgreSQL:
 * la segunda espera a que la primera confirme y entonces encuentra la clave y devuelve el mismo recurso.
 *
 * Uso en un Servicio (la comprobación va ANTES de cualquier detección de duplicados, RF-03):
 *
 *   return Idempotencia::ejecutar(
 *       $usuario['id'], $clave, 'incidencia.registrar',
 *       fn () => $this->repo->insertar(...),   // devuelve el id nuevo
 *       fn (int $id) => $this->repo->obtener($id)
 *   );
 */
final class Idempotencia
{
    /** Lee y valida la cabecera Idempotency-Key. Lo llama el Router en rutas con 'idempotente' => true. */
    public static function exigirClave(Solicitud $solicitud): string
    {
        $clave = $solicitud->cabecera('idempotency-key');
        if ($clave === null || preg_match('/^[A-Za-z0-9_-]{8,100}$/', $clave) !== 1) {
            throw Errores::validacion(['Idempotency-Key' => 'Falta la clave de idempotencia o no es válida.']);
        }
        return $clave;
    }

    /**
     * @param callable():int $crear    inserta y devuelve el id del recurso
     * @param callable(int):mixed $leer  devuelve el recurso (ya serializado) a partir de su id
     */
    public static function ejecutar(int $usuarioId, string $clave, string $accion, callable $crear, callable $leer): mixed
    {
        return Conexion::transaccion(function (PDO $pdo) use ($usuarioId, $clave, $accion, $crear, $leer): mixed {
            $bloqueo = $pdo->prepare('SELECT pg_advisory_xact_lock(?::int, hashtext(?::text))');
            $bloqueo->execute([$usuarioId, $clave]);

            $previo = $pdo->prepare('SELECT accion, recurso_id FROM idempotencia WHERE usuario_id = ? AND clave = ?');
            $previo->execute([$usuarioId, $clave]);
            $fila = $previo->fetch();

            if ($fila) {
                if ($fila['accion'] !== $accion) {
                    throw Errores::validacion(['Idempotency-Key' => 'Esta clave ya se usó en otra operación.']);
                }
                return $leer((int) $fila['recurso_id']);
            }

            $id = $crear();

            $registro = $pdo->prepare(
                'INSERT INTO idempotencia (usuario_id, clave, accion, recurso_id, creado_en) VALUES (?, ?, ?, ?, ?)'
            );
            $registro->execute([$usuarioId, $clave, $accion, $id, Reloj::paraBd()]);

            return $leer($id);
        });
    }
}
