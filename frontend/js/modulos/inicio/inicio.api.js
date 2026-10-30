// frontend/js/modulos/inicio/inicio.api.js
// =====================================================================
//  Acceso a datos del módulo inicio: solo habla con el backend real.
//  Contrato: docs/api/catalogos.md → GET /sedes.
//
//  Todos los roles ven todas las sedes. usuario.sede_id es solo la sede por defecto (preseleccionada)
//  y puede estar vacía; no restringe nada. La pantalla muestra lo que llega.
//
//  Datos de prueba: no viven aquí. Para trabajar sin backend, js/mocks/ responde
//  a esta misma llamada (ver js/mocks/index.js).
// =====================================================================
import { http } from '../../nucleo/http.js';

export const inicioApi = {
  sedes: () => http.get('/sedes'),
};
