// frontend/js/modulos/incidencias/incidencias.api.js
// =====================================================================
//  Acceso a datos del módulo incidencias (solo habla con /api).
//  Contrato: docs/api/incidencias.md, docs/api/equipos.md, docs/api/catalogos.md.
//
//  Forma ASUMIDA de la incidencia que devuelve POST /incidencias (el contrato aún
//  no fija el cuerpo exacto; ajustar cuando lo complete el backend):
//    { id, equipo_id, equipo, estado, prioridad, impacto, descripcion, canal,
//      fecha_registro, fecha_ultimo_cambio, acciones: [] }
// =====================================================================
import { http } from '../../nucleo/http.js';

export const incidenciasApi = {
  sedes: () => http.get('/sedes'),
  ubicaciones: (sedeId) => http.get('/ubicaciones', { sede: sedeId }),
  // Con `ubicacion` devuelve { items, total } con TODOS los equipos de esa ubicación (sin paginar).
  equipos: (ubicacionId) => http.get('/equipos', { ubicacion: ubicacionId }),
  // Solo quien registra a nombre de otro (RF-02). q: de 2 a 50 caracteres.
  reportantes: (q) => http.get('/usuarios/reportantes', { q }),
  // `clave` = Idempotency-Key (la entrega formulario.js): un reintento no duplica.
  crear: (cuerpo, { clave }) => http.post('/incidencias', cuerpo, { clave }),
};