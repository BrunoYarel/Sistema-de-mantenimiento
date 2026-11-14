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

export const plantillaApi = {
  listar: () => http.get('/plantilla/sedes'),
  // seg(): el id nunca cuela "..", "/" ni "%2F"
  obtener: (id) => http.get(`/plantilla/sedes/${seg(id)}`),
};
