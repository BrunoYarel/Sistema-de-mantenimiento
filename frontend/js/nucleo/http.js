// =====================================================================
//  Único lugar donde se llama al backend (fetch). Los módulos usan:
//     http.get('/incidencias', { estado: 'abierta' })
//     http.get(`/equipos/${seg(id)}`)                      ← ids SIEMPRE con seg()
//     http.post('/incidencias', datos, { clave })          ← clave = Idempotency-Key
//     http.put / http.patch / http.delete
//  Devuelve el contenido de "data" de la respuesta del backend.
//  Si algo falla lanza un ErrorApi con un mensaje listo para mostrar.
//
//  ErrorApi:
//     estado         código HTTP (0 = no hubo respuesta: sin red o timeout)
//     codigo         texto corto del backend, ej. 'validacion'
//     campos         errores por campo (422): { descripcion: 'Obligatoria' }
//     datos          "data" que el 409 trae con el recurso actual ("ya estaba hecho")
//     esperaSegundos tiempo de espera sugerido (429)
// =====================================================================
import { config } from '../config.js';

const TIMEOUT_MS = 15000;

export class ErrorApi extends Error {
  constructor(estado, codigo, mensaje, { campos = null, datos = null, esperaSegundos = null } = {}) {
    super(mensaje);
    this.name = 'ErrorApi';
    this.estado = estado;
    this.codigo = codigo;
    this.campos = campos;
    this.datos = datos;
    this.esperaSegundos = esperaSegundos;
  }
}

const MENSAJES = {
  401: 'Tu sesión expiró. Inicia sesión de nuevo.',
  403: 'No tienes permiso para hacer esto.',
  404: 'No se encontró lo que buscabas.',
  409: 'La acción ya no es posible: el estado cambió. Actualiza la pantalla.',
  422: 'Revisa los datos ingresados.',
};

function textoEspera(segundos) {
  if (segundos < 60) return `${Math.ceil(segundos)} segundos`;
  const min = Math.ceil(segundos / 60);
  return min === 1 ? '1 minuto' : `${min} minutos`;
}

// Clave única para Idempotency-Key. Funciona también sin HTTPS (randomUUID lo exige).
export function nuevaClave() {
  const c = globalThis.crypto;
  if (c?.randomUUID) return c.randomUUID();
  const b = new Uint8Array(16);
  c.getRandomValues(b);
  return Array.from(b, (x) => x.toString(16).padStart(2, '0')).join('');
}

// Un id dentro de una ruta. Solo letras, números, "_" y "-": nada de "..", "/" ni "%2F".
// Si el id no cumple, es como si no existiera (404) y NO se llama al backend.
export function seg(valor) {
  const s = String(valor ?? '');
  if (!/^[A-Za-z0-9_-]+$/.test(s)) throw new ErrorApi(404, 'no_encontrado', MENSAJES[404]);
  return s;
}

// Error de programación (no del usuario): la ruta debe ser "/algo/otro", sin "?", "#", ".." ni "//".
function validarRuta(ruta) {
  const mala = typeof ruta !== 'string'
    || !ruta.startsWith('/')
    || ruta.startsWith('//')
    || /[?#\\]/.test(ruta)
    || ruta.split('/').some((s) => s === '.' || s === '..' || /%2e|%2f|%5c/i.test(s));
  if (mala) throw new Error(`http: ruta no válida "${ruta}". Usa seg(id) para los ids y "params" para la consulta.`);
}

async function pedir(metodo, ruta, { cuerpo, params, clave, sinEventoSesion = false, timeout = TIMEOUT_MS } = {}) {
  validarRuta(ruta);
  let url = `${config.API_URL}${ruta}`;
  if (params) {
    const consulta = new URLSearchParams(
      Object.entries(params).filter(([, v]) => v !== undefined && v !== null && v !== '')
    ).toString();
    if (consulta) url += `?${consulta}`;
  }

  const control = new AbortController();
  const reloj = setTimeout(() => control.abort(), timeout);
  let resp;
  let texto;
  try {
    resp = await fetch(url, {
      method: metodo,
      credentials: 'same-origin',
      cache: 'no-store',
      signal: control.signal,
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'fetch',   // solo informativo: el backend NO lo revisa. La defensa CSRF real es SameSite=Strict + Origin (Solicitud::validarOrigen)
        ...(cuerpo !== undefined ? { 'Content-Type': 'application/json' } : {}),
        ...(clave ? { 'Idempotency-Key': clave } : {}),
      },
      body: cuerpo !== undefined ? JSON.stringify(cuerpo) : undefined,
    });
    texto = await resp.text();         // dentro del timeout: una respuesta a medias también cuenta
  } catch {
    if (control.signal.aborted) {
      throw new ErrorApi(0, 'timeout', 'El servidor tardó demasiado en responder. Inténtalo de nuevo.');
    }
    throw new ErrorApi(0, 'sin_conexion', 'No hay conexión con el servidor. Revisa tu red e inténtalo de nuevo.');
  } finally {
    clearTimeout(reloj);
  }

  let json = null;
  try { json = JSON.parse(texto); } catch { /* sin cuerpo JSON */ }

  if (!resp.ok) {
    // 401 = no hay sesión. app.js lo escucha y lleva al login (los formularios guardan su borrador).
    // Login y cambio de clave piden sinEventoSesion: ahí un 401 es un dato, no una sesión vencida.
    if (resp.status === 401 && !sinEventoSesion) window.dispatchEvent(new CustomEvent('sesion:expirada'));

    const e = json?.error ?? {};
    const cabecera = Number(resp.headers.get('Retry-After'));
    const esperaSegundos = Number(e.espera_segundos) > 0 ? Number(e.espera_segundos) : (cabecera > 0 ? cabecera : null);

    let mensaje = e.mensaje ?? MENSAJES[resp.status];
    if (!mensaje && resp.status === 429) {
      mensaje = esperaSegundos
        ? `Demasiados intentos. Espera ${textoEspera(esperaSegundos)} e inténtalo de nuevo.`
        : 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.';
    }
    mensaje ??= resp.status >= 500 ? 'Error del servidor. Inténtalo más tarde.' : 'No se pudo completar la acción.';

    throw new ErrorApi(resp.status, e.codigo ?? 'error', mensaje, {
      campos: e.campos ?? null,
      datos: json?.data ?? null,       // el 409 de transición trae aquí el recurso actual
      esperaSegundos,
    });
  }

  if (resp.status === 204) return null;
  if (json === null) {
    // Respuesta correcta pero que no es JSON (suele ser un warning de PHP impreso)
    throw new ErrorApi(resp.status, 'respuesta_invalida', 'El servidor devolvió una respuesta inesperada.');
  }
  return json.data ?? null;
}

// op = { clave, sinEventoSesion, timeout }
export const http = {
  get: (ruta, params, op) => pedir('GET', ruta, { params, ...op }),
  post: (ruta, cuerpo, op) => pedir('POST', ruta, { cuerpo, ...op }),
  put: (ruta, cuerpo, op) => pedir('PUT', ruta, { cuerpo, ...op }),
  patch: (ruta, cuerpo, op) => pedir('PATCH', ruta, { cuerpo, ...op }),
  delete: (ruta, op) => pedir('DELETE', ruta, { ...op }),
};