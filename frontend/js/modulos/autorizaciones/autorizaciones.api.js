// frontend/js/modulos/plantilla/plantilla.api.js
// =====================================================================
//  Acceso a datos del módulo: solo habla con el backend real (contrato: docs/api,
//  backend/modulos/plantilla). El resto del módulo solo usa  plantillaApi.listar()  etc.
//
//  Datos de prueba: no viven aquí. Para trabajar sin backend, agrega el mock de tu
//  módulo en js/mocks/handlers/<modulo>.js (copia handlers/plantilla.js). Debe devolver
//  LA MISMA FORMA de datos y los mismos errores que el contrato: { id, nombre }.
// =====================================================================

import { http, seg } from '../../nucleo/http.js';

export const autorizacionesApi = {
  listar: ({ estado = '', incidencia = '', pagina = 1 } = {}) => {
    const params = new URLSearchParams();
    if (estado) params.set('estado', estado);
    if (incidencia) params.set('incidencia', String(incidencia));
    if (pagina > 1) params.set('pagina', String(pagina));
    const q = params.toString();
    return http.get(`/autorizaciones${q ? `?${q}` : ''}`);
  },

  obtener: (id) => http.get(`/autorizaciones/${seg(id)}`),

  solicitar: (incidenciaId, datos, claveIdempotencia) =>
    http.post(`/incidencias/${seg(incidenciaId)}/autorizaciones`, datos, {
      headers: { 'Idempotency-Key': claveIdempotencia },
    }),

  decidir: (id, datos) =>
    http.post(`/autorizaciones/${seg(id)}/decision`, datos),
};

