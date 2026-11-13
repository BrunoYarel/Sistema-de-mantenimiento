// frontend/js/mocks/panel.js
// =====================================================================
//  Panel flotante de "Datos de prueba" (abajo a la izquierda, solo en localhost).
//  Sirve para cambiar de usuario/rol con un clic —sin pasar por el login— y
//  para volver los datos a su estado inicial.
//
//  Cómo se ve:
//    · Cerrado: un botón "Acceso rápido" (con una llave). Si hay sesión, muestra el rol.
//    · Abierto: una tarjeta angosta con los 6 roles por su nombre. Un clic en un rol
//      = entrar con ese perfil (al pasar el cursor aparece una flecha →).
//    · "Más opciones": abre un panel lateral a la derecha con otros perfiles (Ana Pérez,
//      las que crees en Usuarios...), la clave común (botón Copiar), Reiniciar datos y
//      Cerrar sesión. En pantallas angostas ese panel sube encima de la tarjeta.
//  El panel se cierra con ✕, con Esc o con un clic fuera de él (con un fundido corto).
//  Cambiar de perfil, cerrar sesión o reiniciar datos NO recarga la página y NO congela nada:
//    · Al pulsar un rol, TODO el panel se actualiza en el acto (borde del rol, etiqueta, cabecera).
//      Si pulsas otro rol mientras carga, el último clic gana.
//    · El tamaño del panel NO depende de quién esté en sesión (cabecera y filas de una sola línea,
//      «Cerrar sesión» siempre presente): el panel está anclado abajo y, si creciera, saltaría hacia arriba.
//    · Mientras la app se redibuja (el router escucha 'hashchange'), TODA la pantalla anterior
//      (menú de navegación, cabecera y contenido) se queda como estaba, en una "copia" que se
//      apaga casi del todo (el parpadeo: baja, sostiene un instante y sube) y el "Cargando…" no se
//      muestra. Al estar lista la nueva, aparece completa: menú y contenido ya actualizados, sin
//      que nada cambie por partes.
//    · Mientras dura el cambio, el servidor falso responde sin la demora de red simulada (la espera
//      de 300 ms por llamada no enseña nada aquí). El resto de la navegación la conserva.
//    · En la consola (nivel "Verbose") se anota cuánto tardó cada cambio y de qué.
//  Estilos: panel.css (prefijo mk-).
// =====================================================================
import { ROL_ETIQUETA, etiqueta } from '../nucleo/dominio.js';
import { html, cargarCss } from '../nucleo/ui.js';
import { sesion } from '../nucleo/sesion.js';
import { CLAVE_DEV, PERFILES_ROL } from './semilla.js';

const CLAVE_ESTADO = 'mantenimiento:mock:panel';

// Qué partes del panel están desplegadas (sobrevive a recargar la página).
function leerEstado() {
  try { return { panel: false, mas: false, ...JSON.parse(sessionStorage.getItem(CLAVE_ESTADO)) }; } catch { return { panel: false, mas: false }; }
}
function guardarEstado(estado) {
  try { sessionStorage.setItem(CLAVE_ESTADO, JSON.stringify(estado)); } catch { /* sin almacenamiento */ }
}

// Tiempos (ms). SALIDA_MS debe coincidir con la duración de .mk-saliendo en panel.css.
const SALIDA_MS = 100;         // el panel se cierra rápido: la acción del usuario se siente inmediata
const REPOSO_MS = 40;          // cuánto debe quedarse quieta la página para considerarla lista (el menú se pinta en el acto: ver app.js)
const SIN_CAMBIOS_MS = 120;    // si la app no toca nada en este tiempo y no espera ninguna llamada, se da por lista
const ATENUADO_MIN_MS = 150;   // la pantalla anterior se queda atenuada al menos esto para que el parpadeo se vea
                               // pero sin exagerar ni parecer un corte brusco
const TOPE_MS = 1200;          // tope de seguridad: nunca se deja la pantalla atenuada más que esto
const VUELVE_MS = 280;         // cubre la subida: retraso .02 s + fundido .24 s (panel.css)
const sinMovimiento = () => window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;
const pausa = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

// Reproduce una animación de CSS (clase mk-entra) solo cuando se pide: al abrir o mostrar algo.
// Los redibujos del panel (al navegar) no la llaman, así que no parpadea ni se reanima.
function animarEntrada(el) {
  if (!el || sinMovimiento()) return;
  el.classList.remove('mk-entra');
  void el.offsetWidth;                       // reinicia la animación si ya estaba corriendo
  el.classList.add('mk-entra');
  el.addEventListener('animationend', () => el.classList.remove('mk-entra'), { once: true });
}

// Espera a que la app termine de redibujarse: algo nuevo en #vista, sin "Cargando…", y TODA la
// página (menú, cabecera...) quieta un instante, ignorando el propio panel. Así lo último en
// cambiar no aparece de golpe después del cambio. Se crea ANTES de provocar el cambio (para no
// perder la primera mutación) y se llama a armar() cuando el cambio ya se provocó: si la app no
// cambia nada en SIN_CAMBIOS_MS, se da por lista. Tope de seguridad: TOPE_MS.
// Mientras espera, la pantalla solo está atenuada (no congelada): el panel y la página siguen vivos.
function esperarPintado(host, enCurso = () => 0) {
  let resolver;
  const espera = new Promise((resolve) => { resolver = resolve; });
  let reposo;
  let sinCambios;
  let observador;
  const tope = setTimeout(() => fin(), TOPE_MS);
  function fin() {
    clearTimeout(reposo); clearTimeout(sinCambios); clearTimeout(tope);
    observador?.disconnect();
    resolver();
  }
  const vista = document.querySelector('#vista');
  if (!vista) { fin(); return { espera, armar() {} }; }
  const listo = () => vista.childElementCount > 0 && !vista.querySelector('.estado:not(.estado--error)');
  observador = new MutationObserver((lista) => {
    if (lista.every((m) => host.contains(m.target))) return;   // lo del panel no cuenta
    clearTimeout(sinCambios);
    clearTimeout(reposo);
    if (listo()) reposo = setTimeout(fin, REPOSO_MS);
  });
  observador.observe(document.body, {
    childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['hidden', 'class'],
  });
  return {
    espera,
    armar() {
      // La pantalla ya estaba lista mientras corría la acción (p. ej. carga instantánea).
      if (listo()) { reposo = setTimeout(fin, REPOSO_MS); return; }
      // Si la app no toca nada, se da por lista... salvo que aún espere datos: entonces se sigue
      // esperando (antes se cortaba a los 250 ms aunque la llamada tardara más y se veía un hueco).
      // Cualquier cambio en la página cancela este temporizador y manda el observador.
      const revisar = () => {
        if (enCurso() > 0) sinCambios = setTimeout(revisar, 30);
        else fin();
      };
      sinCambios = setTimeout(revisar, SIN_CAMBIOS_MS);
    },
  };
}

// ---------- Copia de la pantalla anterior (COMPLETA) ----------
// Al cambiar de perfil cambia todo: el contenido (#vista) y también el menú, la cabecera, etc. Para que
// nada de eso desaparezca ni se actualice "a pedazos" a la vista, se deja encima una copia inerte de
// TODA la página (todo lo que cuelga de <body>, menos el panel) en la misma posición, tamaño y scroll.
// Se queda ahí, atenuada, hasta que la nueva está lista; entonces la copia se desvanece mientras la
// nueva aparece (panel.css). Es solo cosmética: si no se puede crear, #vista espera en blanco.
// IMPORTANTE: la copia tiene que quedar píxel a píxel donde estaba la pantalla real; si se mueve aunque sea
// unos píxeles, el clic del rol se ve como un salto. Por eso el contenedor es un clon de <body> y no un <div>
// con estilos calculados copiados a mano (ver crearFantasma).
const OMITIR_EN_COPIA = 'script, style, link, template, noscript, .mk-panel, .mk-fantasma';
function crearFantasma() {
  try {
    const vista = document.querySelector('#vista');
    if (!vista || vista.childElementCount === 0 || vista.querySelector('.estado')) return null;
    const cuerpo = document.body;
    const caja = cuerpo.getBoundingClientRect();
    if (caja.width === 0 || caja.height === 0) return null;
    const raiz = document.documentElement;

    // Marco: cubre la ventana (sin tapar la barra de scroll) y recorta lo que sobra.
    const marco = document.createElement('div');
    marco.className = 'mk-fantasma';
    marco.setAttribute('aria-hidden', 'true');
    marco.inert = true;
    Object.assign(marco.style, {
      position: 'fixed', left: '0', top: '0',
      width: `${raiz.clientWidth}px`, height: `${raiz.clientHeight}px`,
      overflow: 'hidden', pointerEvents: 'none',
    });

    // Interior: hace de <body> (mismas medidas y estilos) dentro del marco. Es un clon VACÍO del propio
    // <body> (mismas clases, id y estilos en línea) en vez de un <div> al que se le copian estilos calculados:
    //   · getComputedStyle(body).lineHeight devuelve píxeles ("24px") aunque en la hoja sea un número ("1.5").
    //     Copiado así, los títulos y textos grandes heredaban 24 px FIJOS en lugar de 1.5 x su tamaño: sus
    //     líneas encogían y todo lo de debajo subía (hasta 24 px) justo al pulsar el rol.
    //   · Con un clon se aplican las mismas reglas de base.css de siempre, también las que dependen de
    //     body (p. ej. "body > nav", grid-template-areas) y no hay que mantener una lista de propiedades.
    // La clase mk-fantasma-cuerpo evita que las reglas de ocultar de panel.css lo alcancen.
    const interior = cuerpo.cloneNode(false);
    interior.classList.add('mk-fantasma-cuerpo');
    Object.assign(interior.style, {
      position: 'absolute',
      left: `${caja.left + window.scrollX}px`, top: `${caja.top + window.scrollY}px`,
      width: `${caja.width}px`, height: `${caja.height}px`,
      margin: '0', boxSizing: 'border-box', overflow: 'visible',
    });
    // Se conservan los ids a propósito: así la copia hereda los mismos estilos. El original va antes
    // en el documento, así que sigue siendo el que encuentra el router.
    const originales = [...cuerpo.children].filter((el) => !el.matches(OMITIR_EN_COPIA));
    originales.forEach((el) => interior.append(el.cloneNode(true)));

    // cloneNode no copia lo escrito en los campos: se pasa a mano para que se vea igual.
    const campos = originales.flatMap((el) => [...el.querySelectorAll('input, textarea, select')]);
    const camposCopia = [...interior.querySelectorAll('input, textarea, select')];
    campos.forEach((campo, i) => {
      const copia = camposCopia[i];
      if (!copia || campo.type === 'file') return;
      try {
        if ('checked' in campo) copia.checked = campo.checked;
        copia.value = campo.value;
      } catch { /* campo raro: se queda como estaba */ }
    });

    marco.append(interior);
    cuerpo.append(marco);
    marco.scrollTop = window.scrollY;           // misma parte de la página que se estaba viendo
    marco.scrollLeft = window.scrollX;
    void marco.offsetWidth;                      // fija el estado inicial: así la atenuación sí se anima
    return marco;
  } catch { return null; }
}

// Le dice al router que redibuje en esta dirección (él escucha 'hashchange').
function irA(hash) {
  history.replaceState(null, '', hash);
  window.dispatchEvent(new Event('hashchange'));
}

// Actualiza el contenido de "destino" para que quede igual a "marcado" REUTILIZANDO los
// elementos que ya existen (en vez de tirarlos y crearlos de nuevo con innerHTML). Así el botón
// bajo el cursor no pierde el resalte ni el foco, ni parpadea nada al cambiar de perfil.
// El atributo "hidden" no se toca: lo gobierna aplicar().
function sincronizar(destino, marcado) {
  // DOMParser en vez de innerHTML: el marcado ya sale escapado de html`...` y aquí no se inserta en la página.
  const molde = new DOMParser().parseFromString(marcado, 'text/html').body;
  reconciliar(destino, molde);
}

function reconciliar(actual, nuevo) {
  const viejos = [...actual.childNodes];
  const nuevos = [...nuevo.childNodes];
  nuevos.forEach((n, i) => {
    const v = viejos[i];
    if (!v) { actual.append(n); return; }
    if (v.nodeType !== n.nodeType || v.nodeName !== n.nodeName) { v.replaceWith(n); return; }
    if (n.nodeType !== Node.ELEMENT_NODE) {
      if (v.nodeValue !== n.nodeValue) v.nodeValue = n.nodeValue;
      return;
    }
    [...v.attributes].forEach((at) => {
      if (at.name !== 'hidden' && !n.hasAttribute(at.name)) v.removeAttribute(at.name);
    });
    [...n.attributes].forEach((at) => {
      if (at.name !== 'hidden' && v.getAttribute(at.name) !== at.value) v.setAttribute(at.name, at.value);
    });
    reconciliar(v, n);
  });
  viejos.slice(nuevos.length).forEach((v) => v.remove());
}

const porNombre = (a, b) => a.nombre.localeCompare(b.nombre, 'es');
const avisos = (u) => [u.bloqueado && 'bloqueado', u.debe_cambiar_clave && 'clave temporal'].filter(Boolean).join(', ');

export function montarPanel({ bd, sesionSimulada, modulos, servidor }) {
  cargarCss(new URL('./panel.css', import.meta.url).href);

  const host = document.createElement('aside');
  host.className = 'mk-panel';
  host.setAttribute('aria-label', 'Datos de prueba');
  document.body.append(host);
  document.body.classList.add('mk-con-panel');   // deja hueco para el botón en pantallas bajitas

  const estado = leerEstado();
  let ultimoMarcado = '';   // último HTML pintado, para no reconstruir el panel sin necesidad
  // Perfil que el panel MUESTRA como activo mientras el cambio real aún está en curso. Es solo visual:
  // la sesión del mock no se toca hasta que le toca a ese cambio (ver cambiarSuave). Así el panel
  // responde al instante sin cambiar la sesión por debajo de una pantalla que todavía está cargando.
  let perfilOptimista = null;

  // Muestra el botón o la tarjeta según el estado (sin repintar: no se pierde el foco).
  function aplicar() {
    const boton = host.querySelector('.mk-boton');
    const caja = host.querySelector('.mk-caja');
    const extras = host.querySelector('.mk-extras');
    const mas = host.querySelector('.mk-mas');
    if (!boton || !caja) return;
    boton.hidden = estado.panel;
    caja.hidden = !estado.panel;
    boton.setAttribute('aria-expanded', String(estado.panel));
    if (extras) extras.hidden = !(estado.panel && estado.mas);
    if (mas) mas.setAttribute('aria-expanded', String(estado.mas));
  }

  // Oculta con un fundido corto (clase mk-saliendo) y luego ejecuta la acción.
  function conSalida(elementos, accion, guardar) {
    clearTimeout(guardar.timer);
    elementos.forEach((el) => el.classList.remove('mk-saliendo'));
    if (elementos.length === 0 || sinMovimiento()) { accion(); return; }
    elementos.forEach((el) => el.classList.add('mk-saliendo'));
    guardar.timer = setTimeout(() => {
      elementos.forEach((el) => el.classList.remove('mk-saliendo'));
      accion();
    }, SALIDA_MS);
  }
  const relojPanel = {};
  const relojLado = {};

  // enfocar=false: al cerrar con un clic fuera no se le quita el foco a lo que se pulsó.
  function alternar(abrir, enfocar = true) {
    estado.panel = abrir;
    if (!abrir) estado.mas = false;          // al cerrar, "Más opciones" también se cierra: la próxima vez se abre limpio
    guardarEstado(estado);
    // El foco sigue al control que se acaba de mostrar.
    const terminar = () => {
      aplicar();
      if (abrir) animarEntrada(host.querySelector('.mk-caja'));
      else animarEntrada(host.querySelector('.mk-boton'));
      if (enfocar) host.querySelector(abrir ? '.mk-cerrar' : '.mk-boton')?.focus();
    };
    if (abrir) { conSalida([], terminar, relojPanel); return; }
    conSalida([...host.querySelectorAll('.mk-caja:not([hidden]), .mk-extras:not([hidden])')], terminar, relojPanel);
  }

  function alternarExtras(abrir) {
    estado.mas = abrir;
    guardarEstado(estado);
    const terminar = () => {
      aplicar();
      if (abrir) animarEntrada(host.querySelector('.mk-extras'));
    };
    if (abrir) { conSalida([], terminar, relojLado); return; }
    conSalida([...host.querySelectorAll('.mk-extras:not([hidden])')], terminar, relojLado);
  }

  function pintar() {
    const enSesion = sesionSimulada ? bd.usuarioEnSesion() : null;
    const actual = sesionSimulada && perfilOptimista !== null
      ? (bd.usuarios.find((u) => u.id === perfilOptimista) ?? enSesion)
      : enSesion;
    const usuariosPrincipales = new Set(Object.values(PERFILES_ROL));

    // Debajo del título: de qué trata el panel, o quién está dentro si ya hay sesión.
    const ayuda = !sesionSimulada
      ? 'Sesión real'
      : actual ? `Sesión: ${actual.nombre}` : 'Datos de prueba';

    // ----- Los 6 roles, con su nombre completo -----
    const roles = Object.entries(PERFILES_ROL).map(([rol, usuario]) => {
      const u = bd.usuarios.find((x) => x.usuario === usuario);
      const disponible = Boolean(u?.activo);
      const activo = Boolean(actual) && actual.id === u?.id;
      const clase = ['mk-rol', activo && 'mk-rol--activo', !disponible && 'mk-rol--off'].filter(Boolean).join(' ');
      const marca = activo ? 'en sesión' : !disponible ? 'desactivado' : '';
      return html`
        <li>
          <button type="button" class="${clase}" data-mk="rol" data-id="${u?.id ?? ''}"
                  aria-pressed="${activo ? 'true' : 'false'}" aria-disabled="${disponible ? 'false' : 'true'}"
                  title="${disponible ? `Entrar como ${usuario}` : 'Este perfil está desactivado'}">
            <span>${etiqueta(ROL_ETIQUETA, rol)}</span>
            ${marca ? html`<span class="mk-tag">${marca}</span>` : ''}
          </button>
        </li>`;
    });

    // ----- Otras personas, agrupadas por rol -----
    const otros = bd.usuarios.filter((u) => u.activo && !usuariosPrincipales.has(u.usuario));
    const grupos = Object.keys(PERFILES_ROL)
      .map((rol) => ({ rol, lista: otros.filter((u) => u.rol === rol).sort(porNombre) }))
      .filter((g) => g.lista.length > 0);

    const actualEsOtro = Boolean(actual) && !usuariosPrincipales.has(actual.usuario);

    const bloqueOtros = html`
      <div>
        <p class="mk-titulo">Otros perfiles</p>
        <div class="mk-fila">
          <select aria-label="Otros perfiles" data-mk="otro">
            ${grupos.map((g) => html`
              <optgroup label="${etiqueta(ROL_ETIQUETA, g.rol)}">
                ${g.lista.map((u) => html`<option value="${u.id}">${u.nombre}${avisos(u) ? ` (${avisos(u)})` : ''}</option>`)}
              </optgroup>`)}
          </select>
          <button type="button" class="btn mk-entrar" data-mk="entrar-otro">Entrar</button>
        </div>
      </div>`;

    const cuerpo = sesionSimulada
      ? html`
        <ul class="mk-roles">${roles}</ul>
        <button type="button" class="mk-mas" data-mk="extras" aria-expanded="false" aria-controls="mk-extras">Más opciones</button>`
      : html`<p class="mk-nota">La sesión es la del backend real: aquí no se puede cambiar de usuario.</p>`;

    // Panel lateral (a la derecha de la tarjeta)
    const lateral = sesionSimulada
      ? html`
        <section class="mk-extras" id="mk-extras" aria-label="Más opciones">
          ${otros.length > 0 ? bloqueOtros : ''}
          <div>
            <p class="mk-titulo">Clave de todos los perfiles</p>
            <div class="mk-fila">
              <code class="mk-clave-valor">${CLAVE_DEV}</code>
              <button type="button" class="btn btn--texto mk-copiar" data-mk="copiar">Copiar</button>
            </div>
          </div>
          <div class="mk-acciones">
            <button type="button" class="mk-accion" data-mk="reiniciar">
              Reiniciar datos
              <small>Vuelve todo al estado inicial</small>
            </button>
            <button type="button" class="mk-accion" data-mk="salir" aria-disabled="${actual ? 'false' : 'true'}">Cerrar sesión</button>
          </div>
        </section>`
      : '';

    const marcado = String(html`
      <button type="button" class="mk-boton" data-mk="abrir" aria-expanded="false" aria-controls="mk-caja"
              title="Datos de prueba · simulado: ${modulos.join(', ') || 'ninguno'}">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/></svg>
        <span>Acceso rápido</span>
        ${actual ? html`<span class="mk-boton-estado">${etiqueta(ROL_ETIQUETA, actual.rol)}</span>` : ''}
      </button>

      <section class="mk-caja" id="mk-caja" aria-label="Acceso rápido con datos de prueba">
        <header class="mk-cabecera">
          <div>
            <h1>Acceso rápido</h1>
            <p class="mk-ayuda" title="${ayuda}">${ayuda}</p>
          </div>
          <button type="button" class="mk-cerrar" data-mk="cerrar" aria-label="Cerrar panel">✕</button>
        </header>
        <div class="mk-cuerpo">${cuerpo}</div>
      </section>

      ${lateral}`);

    // Navegar casi nunca cambia el panel: si el HTML es el mismo, se deja el DOM como está
    // (sin parpadeo, sin perder el foco, el scroll ni lo elegido en la lista).
    if (marcado === ultimoMarcado) return;
    ultimoMarcado = marcado;
    sincronizar(host, marcado);

    aplicar();

    if (actualEsOtro) host.querySelector('[data-mk="otro"]').value = String(actual.id);
  }

  // Cambia sesión/datos sin recargar y sin congelar la pantalla. Mientras la app se redibuja, la
  // pantalla anterior COMPLETA (menú incluido) queda atenuada (html.mk-cambiando) y, al estar lista,
  // todo se cruza con la nueva en un fundido (html.mk-vuelve). Con movimiento reducido solo se aplica el cambio. Si algo falla, recarga la
  // página como último recurso.
  let ocupado = false;
  let pendiente = null;   // si llega otro cambio mientras hay uno en curso, se guarda aquí: el último gana
  let turno = 0;          // identifica el cambio más reciente (para no apagar los estilos de uno que sigue en marcha)
  let fantasma = null;    // copia de la pantalla anterior mientras dura el cambio
  let desdeAtenuado = 0;  // momento en que la pantalla anterior empezó a atenuarse
  const quitarFantasma = () => { fantasma?.remove(); fantasma = null; };

  async function cambiarSuave(accion) {
    if (ocupado) { pendiente = accion; return; }   // nunca se ignora un clic: se aplica al terminar el actual
    ocupado = true;
    servidor?.rapido?.(true);                  // sin demora de red simulada mientras dura el cambio
    const raiz = document.documentElement;
    const mio = ++turno;
    const animar = !sinMovimiento();
    if (animar) {
      // La copia se hace UNA vez por tanda de cambios (si hay otro clic en cola, se reutiliza) y ANTES
      // de ocultar #vista y de crear el observador, para que no cuente como un cambio de la app.
      if (!raiz.classList.contains('mk-cambiando')) {
        quitarFantasma();
        fantasma = crearFantasma();
        desdeAtenuado = performance.now();
      }
      // Con copia se oculta y se funde toda la página; sin copia (no se pudo crear) solo #vista.
      raiz.classList.toggle('mk-con-copia', Boolean(fantasma));
      raiz.classList.remove('mk-vuelve');
      raiz.classList.add('mk-cambiando');
    }
    try {
      const pintado = esperarPintado(host, () => servidor?.enCurso?.() ?? 0);   // se prepara ANTES de provocar el cambio
      const t0 = performance.now();
      await accion();
      const t1 = performance.now();
      pintado.armar();
      await pintado.espera;
      // Para saber qué pesa: "acción" = sesión del mock + lo que tarde su API simulada;
      // "pantalla" = lo que tarda el router en dibujar la ruta nueva (sus peticiones al mock incluidas).
      console.debug(`[mocks] cambio de pantalla: acción ${Math.round(t1 - t0)} ms + pantalla ${Math.round(performance.now() - t1)} ms`);
    } catch (err) {
      console.error('[mocks] No se pudo cambiar sin recargar; se recarga la página.', err);
      location.reload();
      return;
    }
    // Si todo cargó casi al instante, se completa el tiempo mínimo de atenuado para que el parpadeo se vea.
    if (animar && !pendiente) {
      const falta = ATENUADO_MIN_MS - (performance.now() - desdeAtenuado);
      if (falta > 0) await pausa(falta);
    }
    ocupado = false;
    if (pendiente) {                           // hay un clic más nuevo esperando: se sigue con él sin parpadeo
      const siguiente = pendiente;
      pendiente = null;
      cambiarSuave(siguiente);
      return;
    }
    servidor?.rapido?.(false);                  // se acabó la tanda: la navegación normal vuelve a tener su demora
    if (perfilOptimista !== null) {             // ya no queda nada en curso: el panel muestra la sesión real
      perfilOptimista = null;
      pintar();
    }
    if (animar && mio === turno) {
      raiz.classList.add('mk-vuelve');
      raiz.classList.remove('mk-cambiando');
      setTimeout(() => {
        if (mio !== turno) return;
        raiz.classList.remove('mk-vuelve', 'mk-con-copia');
        quitarFantasma();
      }, VUELVE_MS);
    }
  }

  // El panel se actualiza AL INSTANTE (solo en pantalla); la sesión del mock cambia cuando le toca
  // a este cambio, nunca a mitad de otro: si la pantalla anterior aún está pidiendo sus datos, la
  // sesión no se mueve por debajo de ella (eso era lo que provocaba el error pasajero).
  const entrar = (id) => {
    const actual = bd.usuarioEnSesion();
    const mismoPerfil = (perfilOptimista !== null ? perfilOptimista === id : Boolean(actual && actual.id === id));
    if (mismoPerfil) return;

    perfilOptimista = id;
    pintar();
    cambiarSuave(async () => {
      bd.iniciarSesion(id);
      sesion.limpiar();                    // así un 401 al leer /me no se confunde con "sesión vencida"
      await sesion.cargar();
      irA('#/');
    });
  };

  const salir = () => { perfilOptimista = null; cambiarSuave(async () => {
    await sesion.salir();                  // POST /auth/logout (mock) y borra los borradores
    irA('#/login');
  }); };

  const reiniciar = () => { perfilOptimista = null; cambiarSuave(async () => {
    bd.reiniciar();
    sesion.limpiar();
    await sesion.cargar();                 // si la sesión sigue siendo válida, se conserva
    irA(sesion.estaActiva() ? '#/' : '#/login');
  }); };

  host.addEventListener('click', (ev) => {
    const boton = ev.target.closest('button[data-mk]');
    if (!boton) return;
    switch (boton.dataset.mk) {
      case 'abrir':
        alternar(true);
        break;
      case 'cerrar':
        alternar(false);
        break;
      case 'extras':
        alternarExtras(!estado.mas);
        break;
      case 'rol':
        if (boton.getAttribute('aria-disabled') !== 'true') {
          entrar(Number(boton.dataset.id));       // repinta el panel entero en el acto: el borde aparece ya
        }
        break;
      case 'entrar-otro':
        entrar(Number(host.querySelector('[data-mk="otro"]').value));
        break;
      case 'copiar':
        navigator.clipboard?.writeText(CLAVE_DEV).then(() => {
          boton.textContent = 'Copiada';
          setTimeout(() => { boton.textContent = 'Copiar'; }, 1500);
        }).catch(() => { /* sin permiso de portapapeles: la clave está a la vista */ });
        break;
      case 'salir':
        if (boton.getAttribute('aria-disabled') !== 'true') salir();
        break;
      case 'reiniciar':
        reiniciar();
        break;
      default:
    }
  });

  // Un clic fuera del panel (tarjeta o lateral) lo cierra.
  document.addEventListener('pointerdown', (ev) => {
    if (estado.panel && !host.contains(ev.target)) alternar(false, false);
  });

  // Escape cierra primero el panel lateral y luego la tarjeta.
  document.addEventListener('keydown', (ev) => {
    if (ev.key !== 'Escape' || !estado.panel || !host.contains(document.activeElement)) return;
    if (estado.mas) alternarExtras(false);
    else alternar(false);
  });

  // La lista y la sesión cambian al iniciar sesión o crear usuarios: se repinta al navegar.
  window.addEventListener('hashchange', pintar);
  pintar();
}