// frontend/js/app.js
// =====================================================================
//  Arranque del sistema. Orden:
//    0. (Solo localhost) Enciende los datos de prueba si la URL trae ?mocks=.
//    1. Carga cada módulo por separado (uno roto no tumba a los demás).
//    2. Carga el CSS de cada módulo y arma el router.
//    3. Pregunta si hay sesión iniciada.
//    4. Arranca el router y mantiene al día la barra superior.
//  Normalmente NO necesitas tocar este archivo.
// =====================================================================
import { config, EN_LOCAL } from './config.js';
import { sesion } from './nucleo/sesion.js';
import { iniciarRouter } from './nucleo/router.js';
import { html, pintarError, cargarCss } from './nucleo/ui.js';
import { notify } from './nucleo/notify.js';
import { cargarModulos } from './modulos/index.js';

const vista = document.querySelector('#vista');
const barra = document.querySelector('#barra');
const menu = document.querySelector('#menu');
const textoUsuario = document.querySelector('#usuario');

let router;
let modulos = [];

// Se llama en cada cambio de pantalla: muestra u oculta la barra y marca el enlace activo.
function pintarBarra(rutaActual) {
  const u = sesion.usuario;
  barra.hidden = !u;
  if (!u) {
    menu.innerHTML = '';
    return;
  }

  textoUsuario.textContent = `${u.nombre} · ${String(u.rol ?? '').replace(/_/g, ' ')}`;

  // Con clave temporal solo existe la pantalla de cambio de clave: sin menú.
  const entradas = u.debe_cambiar_clave ? [] : modulos
    .filter((m) => m.menu && sesion.puede(m.permiso ?? null))
    .sort((a, b) => (a.menu.orden ?? 99) - (b.menu.orden ?? 99));

  const activo = (ruta) => (ruta === '/' ? rutaActual === '/' : rutaActual === ruta || rutaActual.startsWith(`${ruta}/`));

  menu.innerHTML = html`${entradas.map((m) => html`
    <a href="#${m.menu.ruta}" class="${activo(m.menu.ruta) ? 'activo' : ''}">${m.menu.etiqueta}</a>`)}`;
}

async function arrancar() {
  try {
    await sesion.cargar();
  } catch (err) {
    pintarError(vista, err, arrancar);   // sin conexión al abrir: botón Reintentar
    return;
  }
  router.iniciar();
}

// ---------- Datos de prueba (SOLO desarrollo; ver js/mocks/index.js) ----------
// Para quitar los mocks: borra la carpeta js/mocks/, este bloque y el manejo de ?mocks= en config.js.
// Fuera de localhost no hace nada.
async function activarMocks() {
  if (!EN_LOCAL || !config.MOCKS) return;
  try {
    const { activar } = await import('./mocks/index.js');
    activar(config);
  } catch (err) {
    console.error('[mocks] No se pudieron activar (¿se borró js/mocks/ y la URL sigue con ?mocks=?):', err);
    notify.error('No se pudieron activar los datos de prueba (mira la consola).');
  }
}

async function principal() {
  await activarMocks();   // antes de cualquier llamada a /api

  let fallidos = [];
  try {
    ({ modulos, fallidos } = await cargarModulos());
    modulos.forEach((m) => cargarCss(new URL(`./modulos/${m.id}/${m.id}.css`, import.meta.url).href));
    router = iniciarRouter({ modulos, contenedor: vista, alCambiar: pintarBarra });
  } catch (err) {
    // Error de programación (ruta repetida, vista que falta...): se muestra claro en pantalla.
    console.error(err);
    vista.innerHTML = html`<div class="estado estado--error"><h1>Error de configuración</h1><p>${err.message}</p></div>`;
    return;
  }

  for (const { id, error } of fallidos) {
    console.error(`Módulo "${id}" desactivado:`, error);
    notify.error(`El módulo "${id}" no se pudo cargar y quedó desactivado.`);
  }

  document.querySelector('#salir').addEventListener('click', async () => {
    await sesion.salir();
    router.ir('/login');
  });

  // Cualquier llamada al backend que responda 401 → sesión vencida → al login.
  window.addEventListener('sesion:expirada', () => {
    if (!sesion.estaActiva()) return;
    sesion.limpiar();
    notify.error('Tu sesión expiró. Inicia sesión de nuevo.');
    // Los formularios abiertos ya guardaron su borrador: se restaura al volver tras el login.
    router.ir(`/login?volver=${encodeURIComponent(location.hash.slice(1) || '/')}`);
  });

  arrancar();
}

principal();