// frontend/js/config.js
// =====================================================================
//  Configuración única del frontend: MOCKS (datos de prueba sí/no) y
//  API_URL. Los parámetros ?mocks= y ?latencia= solo se leen en
//  localhost; fuera de localhost se ignoran y lo desplegado nunca usa
//  mocks. Qué significa cada valor y cómo funcionan los mocks:
//  js/mocks/index.js (única explicación).
// =====================================================================

export const EN_LOCAL = ['localhost', '127.0.0.1', '[::1]'].includes(location.hostname);

const base = {
  // Datos de prueba (mocks): true | false | lista de ids de módulo.
  // El valor desplegado es siempre false; en localhost lo pisa ?mocks=.
  MOCKS: false,

  // Dirección base de la API: absoluta y del mismo origen (el servidor
  // reescribe /api/* hacia el backend).
  API_URL: '/api',
};

// Convierte el valor de ?mocks= en true, false o una lista de ids.
function leerMocks(valor) {
  if (valor === null) return base.MOCKS;
  const v = valor.trim().toLowerCase();
  if (['1', 'true', 'si', 'sí'].includes(v)) return true;
  if (['', '0', 'false', 'no'].includes(v)) return false;
  return v.split(',').map((id) => id.trim()).filter(Boolean);
}

const local = {};
if (EN_LOCAL) {
  const parametros = new URLSearchParams(location.search);
  local.MOCKS = leerMocks(parametros.get('mocks'));

  const latencia = parametros.get('latencia');
  if (latencia !== null && latencia.trim() !== '' && Number.isFinite(Number(latencia))) {
    local.MOCK = { latenciaMs: Number(latencia) };
  }
}

export const config = Object.freeze({ ...base, ...local });