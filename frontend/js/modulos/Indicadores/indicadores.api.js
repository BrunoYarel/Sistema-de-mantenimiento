// frontend/js/modulos/indicadores/indicadores.api.js
// Acceso a datos del módulo indicadores: solo habla con el backend real.
// Contrato: docs/api/indicadores.md (GET /indicadores?anio=&mes=&sede=) y docs/api/catalogos.md (GET /sedes).
// Datos de prueba: no viven aquí; los responde js/mocks/handlers/indicadores.js.
import { http } from '../../nucleo/http.js';

export const indicadoresApi = {
  // http.get arma el "?..." y descarta los valores vacíos: la ruta va SIN "?" (si lleva, http.js lanza error).
  consultar: ({ anio, mes, sede }) => http.get('/indicadores', { anio, mes, sede }),
  sedes: () => http.get('/sedes'),
};