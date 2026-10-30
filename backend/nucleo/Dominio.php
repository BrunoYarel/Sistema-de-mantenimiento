<?php
// backend/nucleo/Dominio.php
declare(strict_types=1);

namespace Nucleo;

/**
 * Valores fijos del negocio (§4, §9). FUENTE DE VERDAD: el frontend la refleja en frontend/js/nucleo/dominio.js.
 * Nunca aparece un estado, prioridad, canal, rol o permiso como texto suelto fuera de este archivo (F7).
 *
 * Si cambias algo aquí, cambia también frontend/js/nucleo/dominio.js en el mismo PR:
 *   - tests/dominio_front_test.php falla si los valores o la matriz de permisos difieren.
 *   - tests/dominio_test.php falla si los CHECK de database/migraciones o tests/matriz.json difieren de este archivo.
 */
final class Dominio
{
    public const ROLES = ['docente', 'admin', 'soporte_tecnico', 'soporte_informatica', 'jefe_soporte', 'jefe_informatica'];

    public const ESTADOS = ['registrada', 'asignada', 'en_atencion', 'en_espera', 'resuelta', 'cerrada'];
    /** "Pendientes" (RF-11): ni Resuelta ni Cerrada. */
    public const ESTADOS_ABIERTOS = ['registrada', 'asignada', 'en_atencion', 'en_espera'];

    public const PRIORIDADES = ['alta', 'media', 'baja'];
    public const CRITICIDADES = ['alta', 'media', 'baja'];
    public const CANALES = ['sistema', 'llamada', 'mensaje', 'formulario'];
    /** RN-04: lo que afecta la falla. */
    public const IMPACTOS = ['ninguno', 'clase', 'evaluacion'];
    public const TIPOS_EQUIPO = ['computadora', 'impresora', 'proyector', 'dispositivo_red', 'equipo_laboratorio'];

    public const TIPOS_AUTORIZACION = ['reparacion_externa', 'compra', 'reemplazo'];
    public const ESTADOS_AUTORIZACION = ['pendiente', 'aprobada', 'rechazada'];
    /** [S] Confirmar la lista de resultados de una atención con el cliente. */
    public const RESULTADOS = ['reparado', 'no_reparable'];

    /** Catálogo de permisos. Una ruta con un permiso que no esté aquí impide arrancar (F6). */
    public const PERMISOS = [
        'incidencia.ver',                    // RF-08 (el ámbito -propias, asignadas, todas- lo decide el servicio)
        'incidencia.registrar_propia',       // RF-01
        'incidencia.registrar_a_nombre',     // RF-02
        'incidencia.priorizar',              // RF-05
        'incidencia.asignar',                // RF-06
        'incidencia.atender',                // RF-07
        'incidencia.confirmar',              // confirmar o rechazar la solución
        'incidencia.cerrar_sin_confirmacion', // RN-06, D9
        'equipo.elegir',                     // RF-01/02: selects en cascada, sin criticidad
        'equipo.gestionar',                  // RF-04: buscar y registrar equipos
        'equipo.historial',                  // RF-09
        'equipo.dar_de_baja',                // [S] no_reparable
        'autorizacion.solicitar',            // RF-10
        'autorizacion.decidir',              // RF-10
        'indicadores.ver',                   // RF-11
        'usuario.gestionar',                 // RF-12
        'sede.todas',                        // D-A: elegir "Todas las sedes" en Inicio (la sede es un filtro, no un permiso de datos)
    ];

    /** Matriz de la sección 4. Rol desconocido o permiso ausente = denegado (default deny). */
    public const PERMISOS_POR_ROL = [
        'docente' => [
            'incidencia.ver', 'incidencia.registrar_propia', 'incidencia.confirmar', 'equipo.elegir',
        ],
        'admin' => [
            'incidencia.ver', 'incidencia.registrar_propia', 'incidencia.confirmar', 'equipo.elegir',
        ],
        'soporte_tecnico' => [
            'incidencia.ver', 'incidencia.registrar_a_nombre', 'incidencia.atender',
            'equipo.elegir', 'equipo.gestionar', 'equipo.historial',
            'autorizacion.solicitar',
        ],
        'soporte_informatica' => [
            'incidencia.ver', 'incidencia.registrar_a_nombre', 'incidencia.atender',
            'equipo.elegir', 'equipo.gestionar', 'equipo.historial',
            'autorizacion.solicitar',
        ],
        'jefe_soporte' => [
            'incidencia.ver', 'incidencia.registrar_a_nombre', 'incidencia.priorizar', 'incidencia.asignar',
            'incidencia.cerrar_sin_confirmacion',
            'equipo.elegir', 'equipo.gestionar', 'equipo.historial', 'equipo.dar_de_baja',
            'autorizacion.solicitar', 'indicadores.ver', 'sede.todas',
        ],
        'jefe_informatica' => [
            'incidencia.ver', 'equipo.gestionar', 'equipo.historial',
            'autorizacion.decidir', 'indicadores.ver', 'usuario.gestionar', 'sede.todas',
        ],
    ];
}