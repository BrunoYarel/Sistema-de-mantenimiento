// frontend/js/mocks/handlers/plantilla.js

//  ─── CÓMO CREAR EL MOCK DE TU MÓDULO ─────────────────────────────────
//  1. Copia este archivo como  js/mocks/handlers/<tu_modulo>.js  y cambia:
//       · el id (abajo, en  export default): el nombre de tu módulo, igual que
//         tu carpeta y que lo que se escribe en  ?mocks=<tu_modulo>.
//       · las rutas: las mismas de backend/modulos/<tu_modulo>/rutas.php y de
//         docs/api/<tu_modulo>.md (mismo método, misma ruta).
//  2. Si necesita datos propios, agrégalos en js/mocks/semilla.js (y sube
//     VERSION_SEMILLA) y su getter en js/mocks/bd.js. Si tus rutas no guardan
//     nada (solo devuelven datos fijos), no hace falta.
//  3. Registra el archivo en js/mocks/index.js: descomenta tus dos líneas
//     (el import y la entrada de MODULOS) o, si tu módulo no está, agrégalas.
//  ─────────────────────────────────────────────────────────────────────
//
//  ─── QUÉ LLEVA CADA RUTA (cada una es un objeto dentro de  rutas: [ ... ]) ──
//    metodo     'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
//    ruta       Sin el prefijo /api. Un tramo variable se escribe con dos puntos
//               (/equipos/:id) y llega a atender() en  params.id  (siempre texto).
//    permiso    OBLIGATORIO en toda ruta, aunque sea null (si falta, ningún mock arranca):
//                 · null              basta con tener sesión iniciada
//                 · PERMISO.ALGO      exige ese permiso (PERMISO se importa de nucleo/dominio.js)
//               CUIDADO: un nombre mal escrito (PERMISO.ALGOO) da undefined y la ruta
//               queda abierta a cualquiera con sesión, sin ningún aviso. Cópialo de
//               nucleo/dominio.js y pruébalo con un rol que NO tenga ese permiso.
//    atender    La función que responde. Recibe un solo objeto con:
//                 params   lo variable de la ruta       → params.id
//                 query    lo que va tras el ?          → query.sede_id
//                 cuerpo   el JSON enviado (POST/PUT/PATCH; {} si no hay)
//                 actor    la persona con sesión (null si no hay sesión simulada)
//                 clave    la cabecera Idempotency-Key (para altas, si tu contrato la pide)
//                 bd       la base de prueba: bd.plantilla, bd.sedes, bd.usuarios...
//               Lo que devuelve  return  es el  data  de la respuesta (200).
//               Para responder 201 (alta) importa  creado  de '../servidor.js'  y
//               devuelve  creado(datos).
//               Para responder un error:  throw Errores.xxx(...)  (los posibles son
//               los de Errores en servidor.js: sinSesion, prohibido, noEncontrado,
//               conflicto, invalido, demasiados). Usa el código y el mensaje del contrato.
//    publica    (opcional) true → no exige sesión. Solo login y logout.
//    clavePendiente  (opcional) true → se permite aunque la clave sea temporal. Solo /me, clave y logout.
//  ─────────────────────────────────────────────────────────────────────
//
//  REGLAS QUE SE VERIFICAN
//    · bin/verificar.sh comprueba que cada ruta (método + ruta) de este archivo
//      exista en el rutas.php de su módulo. Si no existe, la verificación falla.
//    · Dos rutas iguales (en este o en otro handler) hacen que ningún mock arranque
//      («Mock repetido»).
//    · Devuelve copias ({ ...item }), no el objeto de bd: si no, la pantalla podría
//      modificar la base de prueba sin querer.
//    · Si tu atender() modifica datos (alta, edición, borrado), no hace falta guardar
//      nada: servidor.js llama a bd.guardar() después de cada petición.
// =====================================================================

import { PERMISO, ESTADO, permisosDe } from '../../nucleo/dominio.js';
import { Errores, creado } from '../servidor.js';

const TIPOS_VALIDOS = new Set(['reparacion_externa', 'compra', 'reemplazo']);
const ESTADOS_VALIDOS = new Set(['pendiente', 'aprobada', 'rechazada']);

function puedeVer(actor, aut) {
  const permisos = actor?.permisos || permisosDe(actor?.rol);
  if (permisos.includes(PERMISO.AUTORIZACION_DECIDIR)) return true;
  if (permisos.includes(PERMISO.AUTORIZACION_SOLICITAR)) {
    return aut.solicitante_id === actor.id || aut.solicitante === actor.nombre;
  }
  return false;
}

export default {
  id: 'autorizaciones',
  rutas: [
    {
      metodo: 'GET',
      ruta: '/autorizaciones',
      permiso: null,
      atender: ({ query, actor, bd }) => {
        const permisos = actor?.permisos || permisosDe(actor?.rol);
        if (!permisos.includes(PERMISO.AUTORIZACION_SOLICITAR) && !permisos.includes(PERMISO.AUTORIZACION_DECIDIR)) {
          throw Errores.prohibido('accion_no_permitida', 'No tienes permiso para ver autorizaciones.');
        }

        const estado = query.estado || '';
        if (estado && !ESTADOS_VALIDOS.has(estado)) {
          throw Errores.invalido({ estado: 'Estado desconocido' }, 'Filtro de estado inválido.');
        }

        const pagina = Number(query.pagina || 1);
        if (!Number.isInteger(pagina) || pagina < 1 || pagina > 10000) {
          throw Errores.invalido({ pagina: 'Debe estar entre 1 y 10000' }, 'Página fuera de rango.');
        }

        const lista = (bd.autorizaciones || []).filter((a) => {
          if (!puedeVer(actor, a)) return false;
          if (estado && a.estado !== estado) return false;
          if (query.incidencia && String(a.incidencia_id) !== String(query.incidencia)) return false;
          return true;
        });

        lista.sort((a, b) => {
          if (a.estado === 'pendiente' && b.estado !== 'pendiente') return -1;
          if (a.estado !== 'pendiente' && b.estado === 'pendiente') return 1;
          return new Date(a.fecha_solicitud) - new Date(b.fecha_solicitud);
        });

        const porPagina = 25;
        const inicio = (pagina - 1) * porPagina;
        const items = lista.slice(inicio, inicio + porPagina).map((a) => ({ ...a }));

        return {
          items,
          total: lista.length,
          pagina,
          por_pagina: porPagina,
        };
      },
    },

    {
      metodo: 'GET',
      ruta: '/autorizaciones/:id',
      permiso: null,
      atender: ({ params, actor, bd }) => {
        const permisos = actor?.permisos || permisosDe(actor?.rol);
        if (!permisos.includes(PERMISO.AUTORIZACION_SOLICITAR) && !permisos.includes(PERMISO.AUTORIZACION_DECIDIR)) {
          throw Errores.prohibido('accion_no_permitida', 'No tienes permiso para ver esta autorización.');
        }

        const id = Number(params.id);
        if (!Number.isInteger(id)) {
          throw Errores.noEncontrado('Autorización no encontrada.');
        }

        const item = (bd.autorizaciones || []).find((a) => a.id === id);
        if (!item || !puedeVer(actor, item)) {
          throw Errores.noEncontrado('Autorización no encontrada.');
        }

        return { ...item };
      },
    },

    {
      metodo: 'POST',
      ruta: '/incidencias/:id/autorizaciones',
      permiso: PERMISO.AUTORIZACION_SOLICITAR,
      atender: ({ params, cuerpo, actor, clave, bd }) => {
        if (!clave || !String(clave).trim()) {
          throw Errores.invalido({ 'Idempotency-Key': 'Obligatoria' }, 'Falta cabecera Idempotency-Key.');
        }

        const claveIdem = `${actor.id}:${clave}`;
        if (bd.idempotencia[claveIdem]) {
          const existente = (bd.autorizaciones || []).find((a) => a.id === bd.idempotencia[claveIdem]);
          if (existente) return creado({ ...existente });
        }

        const tipo = String(cuerpo?.tipo || '').trim();
        const justificacion = String(cuerpo?.justificacion || '').trim();
        const campos = {};
        if (!TIPOS_VALIDOS.has(tipo)) campos.tipo = 'Tipo de autorización inválido';
        if (!justificacion) campos.justificacion = 'La justificación es obligatoria';
        if (Object.keys(campos).length > 0) {
          throw Errores.invalido(campos, 'Datos de solicitud inválidos.');
        }

        const incId = Number(params.id);
        const lista = bd.autorizaciones || [];
        const yaPendiente = lista.find((a) => a.incidencia_id === incId && a.estado === 'pendiente');
        if (yaPendiente) {
          throw Errores.conflicto('transicion_invalida', 'La incidencia ya tiene una autorización pendiente.');
        }

        const inc = (bd.incidencias || []).find((i) => i.id === incId);
        if (inc) {
          if (inc.estado !== ESTADO.EN_ATENCION) {
            throw Errores.conflicto('transicion_invalida', 'Solo se puede solicitar autorización sobre una incidencia En atención.', { ...inc });
          }
          inc.estado = ESTADO.EN_ESPERA;
        }

        const nuevoId = lista.reduce((max, x) => Math.max(max, x.id), 0) + 1;
        const nueva = {
          id: nuevoId,
          incidencia_id: incId,
          equipo: inc?.equipo || 'EQ-SIMULADO',
          tipo,
          justificacion,
          solicitante_id: actor.id,
          solicitante: actor.nombre,
          estado: 'pendiente',
          decidido_por: null,
          motivo: null,
          fecha_solicitud: new Date().toISOString(),
          fecha_decision: null,
        };

        lista.push(nueva);
        bd.idempotencia[claveIdem] = nuevoId;
        return creado({ ...nueva });
      },
    },

    {
      metodo: 'POST',
      ruta: '/autorizaciones/:id/decision',
      permiso: PERMISO.AUTORIZACION_DECIDIR,
      atender: ({ params, cuerpo, actor, bd }) => {
        const id = Number(params.id);
        const item = (bd.autorizaciones || []).find((a) => a.id === id);
        if (!item) {
          throw Errores.noEncontrado('Autorización no encontrada.');
        }

        const decision = String(cuerpo?.decision || '').trim();
        const motivo = String(cuerpo?.motivo || '').trim();
        const campos = {};
        if (decision !== 'aprobada' && decision !== 'rechazada') {
          campos.decision = 'Debe ser aprobada o rechazada';
        }
        if (!motivo || motivo.length > 300) {
          campos.motivo = 'El motivo es obligatorio (máximo 300 caracteres)';
        }
        if (Object.keys(campos).length > 0) {
          throw Errores.invalido(campos, 'Datos de decisión inválidos.');
        }

        if (item.estado !== 'pendiente') {
          throw Errores.conflicto('transicion_invalida', 'Esta autorización ya estaba respondida.', { ...item });
        }

        item.estado = decision;
        item.decidido_por = actor.nombre;
        item.motivo = motivo;
        item.fecha_decision = new Date().toISOString();

        const inc = (bd.incidencias || []).find((i) => i.id === item.incidencia_id);
        if (inc && inc.estado === ESTADO.EN_ESPERA) {
          inc.estado = ESTADO.EN_ATENCION;
        }

        return { ...item };
      },
    },
  ],
};
