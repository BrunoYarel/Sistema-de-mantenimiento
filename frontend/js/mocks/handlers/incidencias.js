
// =====================================================================
//  Mock de backend/modulos/incidencias. Contrato: docs/api/incidencias.md.
//  Hoy solo POST /incidencias (RF-01, RF-02) con duplicados (RF-03), idempotencia y prioridad inicial (RN-04).
// =====================================================================
import { PERMISO, ESTADO, PRIORIDAD, CANAL, permisosDe } from '../../nucleo/dominio.js';
import { Errores, creado } from '../servidor.js';

const P = PERMISO;
const IMPACTOS = ['ninguno', 'clase', 'evaluacion'];
const CANALES_A_NOMBRE = [CANAL.LLAMADA, CANAL.MENSAJE, CANAL.FORMULARIO];
const ABIERTAS = [ESTADO.REGISTRADA, ESTADO.ASIGNADA, ESTADO.EN_ATENCION, ESTADO.EN_ESPERA];

const puede = (persona, permiso) => permisosDe(persona?.rol).includes(permiso);

// RN-04: Alta si el equipo es crítico o afecta una evaluación; Media si afecta una clase; Baja en los demás casos.
const prioridadInicial = (equipo, impacto) => {
  if (equipo.criticidad === 'alta' || impacto === 'evaluacion') return PRIORIDAD.ALTA;
  return impacto === 'clase' ? PRIORIDAD.MEDIA : PRIORIDAD.BAJA;
};

// Lo que ve quien reporta: sin técnico, registrador ni vinculados (RN-09).
const aApi = (i, bd) => ({
  id: i.id,
  equipo_id: i.equipo_id,
  equipo: bd.equipos.find((e) => e.id === i.equipo_id)?.codigo ?? '',
  estado: i.estado,
  prioridad: i.prioridad,
  impacto: i.impacto,
  descripcion: i.descripcion,
  canal: i.canal,
  fecha_registro: i.fecha_registro,
  fecha_ultimo_cambio: i.fecha_ultimo_cambio,
  acciones: [],
});

function crear({ cuerpo, clave, actor, bd }) {
  if (!actor) throw Errores.sinSesion();
  const aNombre = puede(actor, P.INCIDENCIA_REGISTRAR_A_NOMBRE);
  if (!aNombre && !puede(actor, P.INCIDENCIA_REGISTRAR_PROPIA)) {
    throw Errores.prohibido('prohibido', 'No tienes permiso para esta acción.');
  }

  // La clave se comprueba ANTES de buscar duplicados (RF-03): reintento = misma incidencia.
  const llave = `${actor.id}:${clave}`;
  if (clave && bd.idempotencia[llave] !== undefined) {
    const previa = bd.incidencias.find((i) => i.id === bd.idempotencia[llave]);
    if (previa) return creado(aApi(previa, bd));
  }

  const campos = {};
  const equipo = Number.isInteger(cuerpo.equipo_id) ? bd.equipos.find((e) => e.id === cuerpo.equipo_id) : undefined;
  if (!equipo || !equipo.activo) campos.equipo_id = 'El equipo no existe o está dado de baja.';
  const descripcion = String(cuerpo.descripcion ?? '').trim();
  if (!descripcion) campos.descripcion = 'Obligatoria.';
  if (!IMPACTOS.includes(cuerpo.impacto)) campos.impacto = 'Valor no permitido.';

  let reportanteId = actor.id;   // registrar_propia: reportante_id y canal del cuerpo se ignoran
  let canal = CANAL.SISTEMA;
  if (aNombre) {
    canal = cuerpo.canal;
    if (!CANALES_A_NOMBRE.includes(canal)) campos.canal = 'Elige llamada, mensaje o formulario.';
    const persona = Number.isInteger(cuerpo.reportante_id) ? bd.usuarios.find((u) => u.id === cuerpo.reportante_id) : undefined;
    if (!persona || !persona.activo || !puede(persona, P.INCIDENCIA_REGISTRAR_PROPIA)) {
      campos.reportante_id = 'Elige a un docente o administrativo activo.';
    } else {
      reportanteId = persona.id;
    }
  }
  if (Object.keys(campos).length) throw Errores.invalido(campos);

  const delEquipo = bd.incidencias.filter((i) => i.equipo_id === equipo.id);
  const enCurso = delEquipo.find((i) => ABIERTAS.includes(i.estado)) ?? delEquipo.find((i) => i.estado === ESTADO.RESUELTA);
  const ahora = new Date().toISOString();

  // Vincular a la existente (RF-03): no se crea otra incidencia.
  if (cuerpo.vincular_a !== undefined) {
    const destino = delEquipo.find((i) => i.id === cuerpo.vincular_a && [...ABIERTAS, ESTADO.RESUELTA].includes(i.estado));
    if (!destino) throw Errores.invalido({ vincular_a: 'No es una incidencia en curso de este equipo.' });
    destino.vinculados.push({ reportante_id: reportanteId, registrador_id: actor.id, canal, descripcion, fecha: ahora });
    // "Sigue fallando" sobre una Resuelta rechaza la solución. [DECIDIR] el estado de destino: aquí, En atención.
    if (destino.estado === ESTADO.RESUELTA) destino.estado = ESTADO.EN_ATENCION;
    destino.fecha_ultimo_cambio = ahora;
    if (clave) bd.idempotencia[llave] = destino.id;
    return creado(aApi(destino, bd));
  }

  if (enCurso && cuerpo.confirmar_nueva !== true) {
    throw Errores.conflicto(
      enCurso.estado === ESTADO.RESUELTA ? 'incidencia_resuelta' : 'incidencia_abierta',
      'Este equipo ya tiene una incidencia en curso.',
      { id: enCurso.id, estado: enCurso.estado, fecha_ultimo_cambio: enCurso.fecha_ultimo_cambio },
    );
  }

  const nueva = {
    id: Math.max(0, ...bd.incidencias.map((i) => i.id)) + 1,
    equipo_id: equipo.id,
    sede_id: equipo.sede_id,
    reportante_id: reportanteId,
    registrador_id: actor.id,
    canal,
    descripcion,
    impacto: cuerpo.impacto,
    prioridad: prioridadInicial(equipo, cuerpo.impacto),
    estado: ESTADO.REGISTRADA,
    tecnico_id: null,
    fecha_registro: ahora,
    fecha_resuelta: null,
    fecha_ultimo_cambio: ahora,
    vinculados: [],
  };
  bd.incidencias.push(nueva);
  if (clave) bd.idempotencia[llave] = nueva.id;
  return creado(aApi(nueva, bd));
}

export default {
  id: 'incidencias',
  rutas: [
    // permiso null: el rol se comprueba dentro (el router no puede exigir uno de dos permisos).
    { metodo: 'POST', ruta: '/incidencias', permiso: null, atender: crear },
  ],
};