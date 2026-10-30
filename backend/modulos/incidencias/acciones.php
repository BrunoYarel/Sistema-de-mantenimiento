<?php
// backend/modulos/incidencias/acciones.php
declare(strict_types=1);

/**
 * Tabla de acciones sobre una incidencia (RN-09, RF-12; matriz §4 y flujos §5).
 * La lee Nucleo\Politica::acciones() / exigirAccion(). Formato de cada alternativa: ver Politica.php.
 *
 * Relaciones que calcula el servicio de incidencias para cada usuario:
 *   'reportante'  es el reportante titular
 *   'vinculado'   es reportante de un reporte vinculado (solo puede rechazar, RN-06)
 *   'tecnico'     es el técnico asignado
 * Hechos que calcula el servicio:
 *   'cierre_vencido'  pasaron 3 días hábiles desde max(fecha_resuelta, último vínculo) (RN-06, D9)
 *   'no_reparable'    el resultado fue no_reparable y el equipo sigue activo [S]
 *
 * PRIMER BORRADOR. La verdad es tests/oraculo_acciones.json (escrito a mano por QA desde §4 y §5,
 * revisado por otra persona, nunca generado desde este archivo). Si discrepan, se corrige este archivo.
 */
$abiertas = ['registrada', 'asignada', 'en_atencion', 'en_espera'];

return [
    'asignar' => [
        ['permiso' => 'incidencia.asignar', 'estados' => ['registrada']],
    ],
    'reasignar' => [
        ['permiso' => 'incidencia.asignar', 'estados' => ['asignada', 'en_atencion', 'en_espera']],
    ],
    'cambiar_prioridad' => [
        ['permiso' => 'incidencia.priorizar', 'estados' => $abiertas],
    ],
    'iniciar_atencion' => [
        ['permiso' => 'incidencia.atender', 'estados' => ['asignada'], 'relacion' => 'tecnico'],
    ],
    'resolver' => [
        ['permiso' => 'incidencia.atender', 'estados' => ['en_atencion'], 'relacion' => 'tecnico'],
    ],
    'solicitar_autorizacion' => [
        ['permiso' => 'autorizacion.solicitar', 'estados' => ['en_atencion'], 'relacion' => 'tecnico'],
        ['permiso' => 'autorizacion.solicitar', 'estados' => ['en_atencion'], 'roles' => ['jefe_soporte']],
    ],
    'decidir_autorizacion' => [
        ['permiso' => 'autorizacion.decidir', 'estados' => ['en_espera']],
    ],
    'confirmar_solucion' => [
        ['permiso' => 'incidencia.confirmar', 'estados' => ['resuelta'], 'relacion' => 'reportante'],
    ],
    'rechazar_solucion' => [
        ['permiso' => 'incidencia.confirmar', 'estados' => ['resuelta'], 'relacion' => ['reportante', 'vinculado']],
    ],
    'cerrar_sin_confirmacion' => [
        ['permiso' => 'incidencia.cerrar_sin_confirmacion', 'estados' => ['resuelta'], 'cuando' => ['cierre_vencido']],
    ],
    'dar_de_baja' => [
        ['permiso' => 'equipo.dar_de_baja', 'estados' => ['resuelta'], 'cuando' => ['no_reparable']],
    ],
];
