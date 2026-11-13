// frontend/js/mocks/servidor.js
// =====================================================================
//  "Backend de mentira" que vive en el navegador. Atiende las llamadas a
//  /api con las MISMAS reglas del contrato (docs/api/*.md): sesión, permisos,
//  códigos de error, forma del JSON. El código real (http.js, sesion.js, los
//  *.api.js) no sabe que existe: sigue llamando a /api como siempre.
//
//  Cómo se engancha: instalarEnFetch() envuelve window.fetch. Si la llamada
//  es para /api y hay un mock para esa ruta, responde un Response de verdad;
//  si no, deja pasar la llamada al fetch original (backend real).
//
//  Cada módulo de js/mocks/handlers/ declara sus rutas igual que
//  backend/modulos/<modulo>/rutas.php:
//
//    { metodo: 'GET', ruta: '/usuarios/:id', permiso: PERMISO.USUARIO_GESTIONAR, atender }
//      permiso: PERMISO.X → exige ese permiso      permiso: null → basta con sesión
//      publica: true      → no exige sesión (login, logout)
//      clavePendiente: true → se permite aunque la clave sea temporal (me, clave, logout)
//
//  Extras que el panel de pruebas usa en el objeto que devuelve crearServidor():
//    atender.rapido(true)  → mientras esté activo, las respuestas salen sin la demora simulada
//                            (el panel lo usa al cambiar de perfil: esperar ahí no enseña nada)
//    atender.enCurso()     → cuántas llamadas a /api siguen sin responder
//
//    atender({ params, query, cuerpo, clave, actor, bd }) devuelve el "data" de la respuesta
//    (o creado(data) para un 201) y lanza Errores.xxx(...) para responder un error.
//    `actor` es el usuario con sesión (null si no hay o si 'sesion' no está simulada).
// =====================================================================
import { PERMISOS_VALIDOS, permisosDe } from '../nucleo/dominio.js';

// Se guarda ANTES de reemplazarlo: es el fetch real, para lo que no se simula.
const fetchReal = globalThis.fetch.bind(globalThis);

// ---------- Errores del servidor falso (mismos códigos que backend/nucleo/Errores.php) ----------
export class ErrorServidor extends Error {
  constructor(estado, codigo, mensaje, { campos = {}, datos = null, esperaSegundos = null } = {}) {
    super(mensaje);
    this.name = 'ErrorServidor';
    this.estado = estado;
    this.codigo = codigo;
    this.campos = campos;
    this.datos = datos;
    this.esperaSegundos = esperaSegundos;
  }
}

export const Errores = Object.freeze({
  sinSesion: () => new ErrorServidor(401, 'sin_sesion', 'Inicia sesión para continuar.'),
  prohibido: (codigo = 'prohibido', mensaje = 'No tienes permiso para hacer esto.') => new ErrorServidor(403, codigo, mensaje),
  noEncontrado: (mensaje = 'No se encontró lo que buscabas.') => new ErrorServidor(404, 'no_encontrado', mensaje),
  conflicto: (codigo, mensaje, datos = null) => new ErrorServidor(409, codigo, mensaje, { datos }),
  invalido: (campos, mensaje = 'Revisa los datos ingresados.') => new ErrorServidor(422, 'validacion', mensaje, { campos }),
  demasiados: (segundos, mensaje) => new ErrorServidor(429, 'demasiados_intentos', mensaje, { esperaSegundos: segundos }),
});

// Para responder con un estado distinto de 200 (el alta responde 201).
class Resultado {
  constructor(estado, data) { this.estado = estado; this.data = data; }
}
export const creado = (data) => new Resultado(201, data);

// ---------- Utilidades ----------
const json = (estado, cuerpo, cabeceras = {}) => new Response(JSON.stringify(cuerpo), {
  status: estado,
  headers: { 'Content-Type': 'application/json; charset=utf-8', ...cabeceras },
});

const abortado = () => new DOMException('Abortado', 'AbortError');

// Simula la demora de la red; respeta el timeout de http.js (AbortController).
const esperar = (ms, signal) => new Promise((resolver, rechazar) => {
  if (signal?.aborted) { rechazar(abortado()); return; }
  const reloj = setTimeout(resolver, ms);
  signal?.addEventListener('abort', () => { clearTimeout(reloj); rechazar(abortado()); }, { once: true });
});

function leerCuerpo(texto) {
  if (typeof texto !== 'string' || texto === '') return {};
  try {
    const v = JSON.parse(texto);
    return v !== null && typeof v === 'object' ? v : {};
  } catch { return {}; }
}

// ---------- Tabla de rutas (se arma con la lista de módulos) ----------
function construirTabla(modulos) {
  const tabla = [];
  const vistas = new Set();

  for (const modulo of modulos) {
    for (const def of modulo.rutas) {
      const nombre = `${def.metodo} ${def.ruta}`;
      if (vistas.has(nombre)) throw new Error(`Mock repetido: ${nombre} (módulo "${modulo.id}")`);
      vistas.add(nombre);
      if (typeof def.atender !== 'function') throw new Error(`El mock ${nombre} no tiene una función "atender".`);
      if (!def.publica && !('permiso' in def)) {
        throw new Error(`El mock ${nombre} (módulo "${modulo.id}") no declara "permiso". Si basta con tener sesión, escribe permiso: null.`);
      }
      if (def.permiso && !PERMISOS_VALIDOS.has(def.permiso)) {
        throw new Error(`El permiso "${def.permiso}" del mock ${nombre} no existe en PERMISO (nucleo/dominio.js).`);
      }
      const nombres = [];
      const patron = def.ruta.replace(/:(\w+)/g, (_, n) => { nombres.push(n); return '([^/]+)'; });
      tabla.push({ modulo: modulo.id, def, nombres, regex: new RegExp(`^${patron}/?$`) });
    }
  }
  // Rutas fijas antes que las de :parámetro ('/usuarios/tecnicos' gana a '/usuarios/:id').
  return tabla.sort((a, b) => a.nombres.length - b.nombres.length);
}

const decodificar = (t) => { try { return decodeURIComponent(t); } catch { return t; } };

// ---------- El servidor ----------
// opciones: { modulos, habilitado(id), bd, apiUrl, latenciaMs, sesionSimulada, todoSimulado }
// Devuelve atender(entrada, init) → Response, o null si la llamada no es para este servidor.
export function crearServidor({ modulos, habilitado, bd, apiUrl, latenciaMs, sesionSimulada, todoSimulado }) {
  const tabla = construirTabla(modulos);
  const base = apiUrl.replace(/\/+$/, '');
  let rapido = false;   // true = sin demora simulada (lo enciende el panel al cambiar de perfil)

  const atender = async function atender(entrada, init = {}) {
    const texto = entrada instanceof Request ? entrada.url : String(entrada);
    const url = new URL(texto, location.href);
    if (url.origin !== location.origin) return null;
    if (url.pathname !== base && !url.pathname.startsWith(`${base}/`)) return null;

    const metodo = String(init.method ?? (entrada instanceof Request ? entrada.method : 'GET')).toUpperCase();
    const camino = url.pathname.slice(base.length) || '/';

    let hallada = null;
    let params = {};
    for (const e of tabla) {
      if (e.def.metodo !== metodo) continue;
      const m = e.regex.exec(camino);
      if (!m) continue;
      hallada = e;
      params = Object.fromEntries(e.nombres.map((n, i) => [n, decodificar(m[i + 1])]));
      break;
    }

    if (!hallada) {
      if (!todoSimulado) return null;   // simulación parcial: lo que no es de un mock va al backend real
      console.warn(`[mocks] Sin mock para ${metodo} ${camino}. Agrégalo en js/mocks/handlers/.`);
      return json(501, { error: { codigo: 'sin_mock', mensaje: `Esta pantalla pide ${metodo} ${camino} y todavía no tiene datos de prueba.`, campos: {} } });
    }
    if (!habilitado(hallada.modulo)) return null;   // ese módulo se usa real

    const { def } = hallada;
    try {
      // Con demora 0 ni se programa el temporizador: la respuesta sale en el mismo turno.
      const demora = rapido ? 0 : latenciaMs;
      if (demora > 0) await esperar(demora, init.signal);
      else if (init.signal?.aborted) throw abortado();

      const actor = sesionSimulada ? bd.usuarioEnSesion() : null;
      if (sesionSimulada && !def.publica) {
        if (!actor) throw Errores.sinSesion();
        if (actor.debe_cambiar_clave && !def.clavePendiente) {
          throw Errores.prohibido('cambiar_clave', 'Debes cambiar tu clave antes de continuar.');
        }
        if (def.permiso && !permisosDe(actor.rol).includes(def.permiso)) throw Errores.prohibido();
      }

      const resultado = await def.atender({
        params,
        query: Object.fromEntries(url.searchParams),
        cuerpo: leerCuerpo(init.body),
        clave: new Headers(init.headers ?? {}).get('Idempotency-Key'),
        actor,
        bd,
      });
      const r = resultado instanceof Resultado ? resultado : new Resultado(200, resultado ?? null);
      console.debug(`[mocks] ${metodo} ${camino} → ${r.estado}`);
      return json(r.estado, { data: r.data });
    } catch (err) {
      if (err instanceof ErrorServidor) {
        console.debug(`[mocks] ${metodo} ${camino} → ${err.estado} ${err.codigo}`);
        const error = { codigo: err.codigo, mensaje: err.message, campos: err.campos };
        const cabeceras = {};
        if (err.esperaSegundos !== null) {
          error.espera_segundos = err.esperaSegundos;
          cabeceras['Retry-After'] = String(err.esperaSegundos);
        }
        return json(err.estado, { error, ...(err.datos !== null ? { data: err.datos } : {}) }, cabeceras);
      }
      if (err?.name === 'AbortError') throw err;   // http.js lo convierte en "timeout"
      console.error(`[mocks] Error en el mock ${metodo} ${camino}:`, err);
      return json(500, { error: { codigo: 'error_interno', mensaje: 'Error en el mock (mira la consola).', campos: {} } });
    } finally {
      bd.guardar();
    }
  };

  atender.rapido = (activo) => { rapido = Boolean(activo); };
  return atender;
}

// Reemplaza window.fetch: primero el servidor falso, y si no atiende, el fetch real.
// También cuenta las llamadas que siguen en vuelo (simuladas o reales): el panel lo usa para saber
// si la pantalla está esperando datos o simplemente ya terminó de dibujar.
export function instalarEnFetch(atender) {
  let enCurso = 0;
  atender.enCurso = () => enCurso;
  globalThis.fetch = async (entrada, init) => {
    enCurso += 1;
    try {
      const simulada = await atender(entrada, init);
      return simulada ?? await fetchReal(entrada, init);   // "await" aquí: el contador baja al recibir la respuesta real
    } finally {
      enCurso -= 1;
    }
  };
}