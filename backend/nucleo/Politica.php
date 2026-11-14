<?php
// backend/nucleo/Politica.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Autorización central, con DEFAULT DENY (B4, RN-09, RF-12). Dos niveles:
 *
 * 1. Permiso por rol  -> puede() / exigir(). Lo usa el Router antes de llamar al controlador.
 * 2. Acción sobre un objeto (rol x relación x estado) -> acciones() / exigirAccion().
 *    Una sola función calcula las acciones permitidas Y valida la que se ejecuta, así lo que la
 *    pantalla muestra (`acciones: []`) y lo que el servicio acepta nunca discrepan (RF-12).
 *
 * La tabla de acciones es DATO, no if anidados. Ver backend/modulos/incidencias/acciones.php.
 * Cada alternativa admite:
 *   permiso   (obligatorio) permiso del rol
 *   roles     (opcional)    lista: solo esos roles
 *   estados   (opcional)    lista: solo en esos estados
 *   relacion  (opcional)    texto o lista: el usuario debe tener alguna de esas relaciones con el objeto
 *   cuando    (opcional)    lista de "hechos" que deben ser verdaderos (p. ej. cierre_vencido)
 * Una acción se permite si CUALQUIERA de sus alternativas se cumple.
 */
final class Politica
{
    /** @return list<string> */
    public static function permisosDe(string $rol): array
    {
        return Dominio::PERMISOS_POR_ROL[$rol] ?? [];
    }

    /**
     * Roles que tienen un permiso, derivados de la matriz (no hay otra lista que mantener):
     * rolesCon('incidencia.atender') son los técnicos; rolesCon('incidencia.registrar_propia'), quienes reportan.
     *
     * @return list<string>
     */
    public static function rolesCon(string $permiso): array
    {
        $roles = [];
        foreach (Dominio::PERMISOS_POR_ROL as $rol => $permisos) {
            if (in_array($permiso, $permisos, true)) {
                $roles[] = $rol;
            }
        }
        return $roles;
    }

    public static function puede(?array $usuario, string $permiso): bool
    {
        return $usuario !== null && in_array($permiso, self::permisosDe((string) $usuario['rol']), true);
    }

    public static function exigir(?array $usuario, string $permiso): void
    {
        if ($usuario === null) {
            throw Errores::sinSesion();
        }
        if (!self::puede($usuario, $permiso)) {
            throw Errores::prohibido();
        }
    }

    /**
     * @param array<string,list<array<string,mixed>>> $tabla
     * @param list<string> $relaciones relaciones del usuario con el objeto: 'reportante', 'tecnico'...
     * @param list<string> $hechos     condiciones verdaderas ahora: 'cierre_vencido'...
     * @return list<string> acciones permitidas
     */
    public static function acciones(array $usuario, array $tabla, string $estado, array $relaciones = [], array $hechos = []): array
    {
        $permitidas = [];
        foreach ($tabla as $accion => $alternativas) {
            foreach ($alternativas as $alternativa) {
                if (self::cumple($usuario, $alternativa, $estado, $relaciones, $hechos)) {
                    $permitidas[] = $accion;
                    break;
                }
            }
        }
        return $permitidas;
    }

    /**
     * Valida una acción antes de ejecutarla (dentro de la transacción, tras el SELECT ... FOR UPDATE).
     *   - 403 si esa persona nunca podría hacerla;
     *   - 409 si podría, pero el estado ya no lo permite ("ya estaba hecho"); lleva $datosActuales.
     * El 404 por estar fuera de ámbito (RN-09) lo decide el servicio antes de llegar aquí.
     */
    public static function exigirAccion(
        string $accion,
        array $usuario,
        array $tabla,
        string $estado,
        array $relaciones = [],
        array $hechos = [],
        mixed $datosActuales = null,
    ): void {
        if (in_array($accion, self::acciones($usuario, $tabla, $estado, $relaciones, $hechos), true)) {
            return;
        }
        foreach ($tabla[$accion] ?? [] as $alternativa) {
            if (self::cumple($usuario, $alternativa, $estado, $relaciones, $hechos, true)) {
                throw Errores::conflicto('La acción ya no es posible en el estado actual.', $datosActuales, 'transicion_invalida');
            }
        }
        throw Errores::prohibido('accion_no_permitida');
    }

    private static function cumple(
        array $usuario,
        array $alternativa,
        string $estado,
        array $relaciones,
        array $hechos,
        bool $soloQuien = false,
    ): bool {
        if (!self::puede($usuario, (string) $alternativa['permiso'])) {
            return false;
        }
        if (isset($alternativa['roles']) && !in_array($usuario['rol'], $alternativa['roles'], true)) {
            return false;
        }
        if (isset($alternativa['relacion']) && array_intersect((array) $alternativa['relacion'], $relaciones) === []) {
            return false;
        }
        if ($soloQuien) {
            return true; // ignora estado y hechos: sirve para distinguir 403 de 409
        }
        if (isset($alternativa['estados']) && !in_array($estado, $alternativa['estados'], true)) {
            return false;
        }
        foreach ($alternativa['cuando'] ?? [] as $hecho) {
            if (!in_array($hecho, $hechos, true)) {
                return false;
            }
        }
        return true;
    }
}