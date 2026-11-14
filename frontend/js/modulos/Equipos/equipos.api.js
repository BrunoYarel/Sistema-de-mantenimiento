// frontend/js/modulos/equipos/equipos.api.js
// Todas las llamadas del módulo. Contrato: docs/api/equipos.md y docs/api/catalogos.md.
// http (nucleo/http.js) antepone /api, arma la consulta desde el objeto `params`
// (omite vacíos) y devuelve el "data" de la respuesta ya desempaquetado.
// Los ids van siempre con seg(); la Idempotency-Key se pasa como { clave }.
// Los tipos de equipo no tienen ruta: son Dominio::TIPOS_EQUIPO (ver equipos.js).
import { http, seg, nuevaClave } from '../../nucleo/http.js';

export const equiposApi = {
  // filtros: { q, tipo, sede, ubicacion, activo: 'true' | 'false' | '', pagina }
  // → { items, total, pagina, por_pagina }  (con `ubicacion`: { items, total }, sin paginar)
  listar: (filtros) => http.get('/equipos', filtros),
  obtener: (id) => http.get(`/equipos/${seg(id)}`),
  // datos: { codigo, tipo, ubicacion_id, criticidad }. La sede no se envía.
  crear: (datos, clave = nuevaClave()) => http.post('/equipos', datos, { clave }),
  // datos: { tipo?, ubicacion_id?, criticidad? }. El código no se edita.
  corregir: (id, datos) => http.patch(`/equipos/${seg(id)}`, datos),
  // Baja y reactivación son cambios de estado: sin Idempotency-Key.
  darDeBaja: (id, motivo) => http.post(`/equipos/${seg(id)}/baja`, { motivo }),
  reactivar: (id, motivo) => http.post(`/equipos/${seg(id)}/reactivar`, motivo ? { motivo } : {}),
  // → { items: [{ fecha, falla, solucion, tecnico }], total, pagina, por_pagina }
  historial: (id, pagina) => http.get(`/equipos/${seg(id)}/historial`, { pagina }),
  // filtros: { sede, ubicacion, tipo, pagina }
  // → { items: [equipo + incidencias_abiertas + desde], total, pagina, por_pagina }
  fueraDeServicio: (filtros) => http.get('/equipos/fuera-de-servicio', filtros),
};

export const catalogosApi = {
  sedes: () => http.get('/sedes'),                                   // [{ id, nombre }]
  ubicaciones: (sede) => http.get('/ubicaciones', { sede }),         // [{ id, sede_id, nombre }]
  crearUbicacion: (datos, clave = nuevaClave()) => http.post('/ubicaciones', datos, { clave }),   // { sede_id, nombre }
};