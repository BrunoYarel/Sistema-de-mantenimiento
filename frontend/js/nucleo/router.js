// frontend/js/nucleo/router.js
// =====================================================================
//  Router de la SPA (por hash:  index.html#/incidencias/12).
//
//  Qué hace en cada cambio de pantalla:
//    1. Busca la ruta que coincide con la dirección.
//    2. Si no hay sesión → lleva al login (y recuerda a dónde quería ir).
//    2b. Si la clave es temporal (debe_cambiar_clave) → solo deja la ruta
//        marcada con  cambioClave: true  (y cerrar sesión, que está en la barra).
//    3. Si el rol no tiene permiso → muestra "Sin permiso".
//    4. Llama a la vista del módulo:  vista(el, { params, query, ruta, ir })
//       y si la vista falla, muestra el error con botón "Reintentar".
//
//  Una vista puede devolver una función; se ejecuta al salir de la pantalla
//  (úsala para quitar timers o listeners).
// =====================================================================
import { sesion } from './sesion.js';
import { html, pintarError } from './ui.js';
import { PERMISOS_VALIDOS } from './dominio.js';

const TITULO_APP = 'Mantenimiento';

// El permiso de una ruta manda sobre el del módulo. `null` = abierto a cualquiera con sesión
// (hay que escribirlo). Sin permiso declarado NO es abierto: es un error y la app no arranca.
// `in` y no `??`: una ruta con permiso: null debe poder abrirse dentro de un módulo restringido.
function permisoDe(modulo, def) {
  return 'permiso' in def ? def.permiso : modulo.permiso;
}

// ---------- Tabla de rutas (se arma con la lista de módulos) ----------
function construirTabla(modulos) {
  const ids = new Set();
  const rutas = new Set();
  const tabla = [];

  for (const modulo of modulos) {
    if (ids.has(modulo.id)) throw new Error(`Módulo repetido: "${modulo.id}"`);
    ids.add(modulo.id);

    for (const def of modulo.rutas ?? []) {
      if (typeof def.vista !== 'function') {
        throw new Error(`La ruta "${def.ruta}" del módulo "${modulo.id}" no tiene una función "vista".`);
      }
      if (rutas.has(def.ruta)) throw new Error(`Ruta repetida: "${def.ruta}" (módulo "${modulo.id}")`);
      rutas.add(def.ruta);

      if (!def.publica) {
        const permiso = permisoDe(modulo, def);
        if (permiso === undefined) {
          throw new Error(`La ruta "${def.ruta}" del módulo "${modulo.id}" no declara "permiso". Si es abierta, escribe permiso: null.`);
        }
        if (permiso !== null && !PERMISOS_VALIDOS.has(permiso)) {
          throw new Error(`El permiso "${permiso}" (ruta "${def.ruta}", módulo "${modulo.id}") no existe en PERMISO (nucleo/dominio.js).`);
        }
      }

      const nombres = [];
      const patron = def.ruta.replace(/:(\w+)/g, (_, nombre) => {
        nombres.push(nombre);
        return '([^/]+)';
      });
      tabla.push({ modulo, def, nombres, regex: new RegExp(`^${patron}/?$`) });
    }
  }

  if (tabla.filter((e) => e.def.cambioClave).length !== 1) {
    throw new Error('Debe haber exactamente una ruta con cambioClave: true (la pantalla de cambio de clave).');
  }

  // Rutas fijas antes que las que tienen :parámetros
  // (así '/incidencias/nueva' gana a '/incidencias/:id').
  return tabla.sort((a, b) => a.nombres.length - b.nombres.length);
}

function decodificar(texto) {
  try { return decodeURIComponent(texto); } catch { return texto; }
}

function emparejar(tabla, camino) {
  for (const entrada of tabla) {
    const m = entrada.regex.exec(camino);
    if (!m) continue;
    const params = {};
    entrada.nombres.forEach((nombre, i) => { params[nombre] = decodificar(m[i + 1]); });
    return { ...entrada, params };
  }
  return null;
}

// '#/incidencias/5?x=1' → { camino: '/incidencias/5', consulta: URLSearchParams, completo: '/incidencias/5?x=1' }
function leerHash() {
  let completo = location.hash.slice(1);
  if (!completo.startsWith('/')) completo = `/${completo}`;
  const i = completo.indexOf('?');
  return {
    camino: i < 0 ? completo : completo.slice(0, i),
    consulta: new URLSearchParams(i < 0 ? '' : completo.slice(i + 1)),
    completo,
  };
}

const poner = (titulo) => { document.title = titulo ? `${titulo} · ${TITULO_APP}` : TITULO_APP; };

// ---------- Pantallas del propio router ----------
function vistaNoEncontrada(el, { ruta }) {
  el.innerHTML = html`
    <div class="estado">
      <h1>Página no encontrada</h1>
      <p>No existe <code>${ruta}</code>.</p>
      <p><a href="#/">Ir al inicio</a></p>
    </div>`;
}

function vistaSinPermiso(el) {
  el.innerHTML = html`
    <div class="estado">
      <h1>Sin permiso</h1>
      <p>Tu rol no tiene acceso a esta pantalla.</p>
      <p><a href="#/">Ir al inicio</a></p>
    </div>`;
}

// ---------- Router ----------
export function iniciarRouter({ modulos, contenedor, alCambiar = () => {} }) {
  const tabla = construirTabla(modulos);
  const rutaCambioClave = tabla.find((e) => e.def.cambioClave).def.ruta;
  let limpiar = null;    // función de limpieza de la vista actual
  let navegacion = 0;    // número de la navegación en curso (evita que una vista lenta pise a otra)

  // Navegar desde código:  ir('/incidencias/5')
  function ir(ruta) {
    const destino = `#${ruta}`;
    if (location.hash === destino) resolver();   // misma dirección: el navegador no avisaría
    else location.hash = destino;
  }

  async function resolver() {
    const mia = ++navegacion;

    if (limpiar) {
      try { limpiar(); } catch (e) { console.error(e); }
      limpiar = null;
    }

    const { camino, consulta, completo } = leerHash();
    const hallada = emparejar(tabla, camino);

    // Sin sesión solo se entra a rutas públicas (el login).
    if ((!hallada || !hallada.def.publica) && !sesion.estaActiva()) {
      ir(`/login?volver=${encodeURIComponent(completo)}`);
      return;
    }

    // Clave temporal: la única pantalla permitida es la de cambio de clave.
    if (sesion.usuario?.debe_cambiar_clave && !hallada?.def.cambioClave) {
      ir(rutaCambioClave);
      return;
    }

    // Cada navegación dibuja en su propio contenedor: si una vista lenta termina
    // tarde, escribe en uno que ya no está en pantalla.
    const el = document.createElement('section');
    el.className = 'vista';
    contenedor.replaceChildren(el);
    window.scrollTo(0, 0);

    const ctx = { params: {}, query: consulta, ruta: camino, ir };
    let vista;

    if (!hallada) {
      vista = vistaNoEncontrada;
      poner('No encontrada');
    } else {
      ctx.params = hallada.params;
      const permiso = permisoDe(hallada.modulo, hallada.def) ?? null;
      vista = !hallada.def.publica && !sesion.puede(permiso) ? vistaSinPermiso : hallada.def.vista;
      poner(hallada.def.titulo ?? hallada.modulo.titulo);
    }
    alCambiar(camino);

    try {
      const salida = await vista(el, ctx);
      if (mia !== navegacion) {                       // el usuario ya se fue a otra pantalla
        if (typeof salida === 'function') salida();
        return;
      }
      if (typeof salida === 'function') limpiar = salida;
    } catch (err) {
      if (mia !== navegacion) return;
      console.error(err);
      pintarError(el, err, resolver);
    }
  }

  return {
    ir,
    recargar: resolver,
    iniciar() {
      window.addEventListener('hashchange', resolver);
      resolver();
    },
  };
}