// frontend/js/nucleo/notify.js
// =====================================================================
//  Notificaciones flotantes de la interfaz: "Usuario creado", "Ana fue
//  desactivada", "No se pudo guardar"... Este archivo es el ÚNICO que las
//  dibuja; los módulos solo dicen QUÉ pasó.
//
//    import { notify } from '../../nucleo/notify.js';
//
//    notify.ok('Usuario «ana» creado.');
//    notify.info('No había cambios que guardar.');
//    notify.error('No se pudo guardar. Inténtalo de nuevo.');
//    notify.ok('Ana Pérez ya no puede entrar.', { titulo: 'Usuario desactivado' });
//    notify.error(mensaje, { duracion: 0 });       // 0 = se queda hasta que la cierren
//
//  Cada llamada devuelve una función cerrar() por si hace falta quitarla antes de tiempo.
//  Opciones:  titulo (texto corto, opcional) · duracion (milisegundos; por tipo si no se indica).
//
//  Comportamiento:
//    · Todo se escribe con textContent: el mensaje nunca se interpreta como HTML.
//    · Una notificación idéntica a otra que sigue en pantalla no se apila: se reinicia su
//      tiempo y se marca "×2", "×3"...
//    · Máximo MAX_VISIBLES a la vez: al llegar otra, se cierra la más antigua.
//    · El tiempo se detiene mientras el mouse o el teclado están sobre la notificación.
//    · Se dibuja en la capa superior del navegador (Popover API), así que se ve incluso
//      encima de un <dialog> abierto con showModal().
//
//  No depende de ningún otro archivo. Estilos: css/notify.css (prefijo de clases: nt-).
// =====================================================================

const TIPOS = {
  ok: { icono: '✓', rol: 'status', duracion: 4000 },
  info: { icono: 'i', rol: 'status', duracion: 4000 },
  error: { icono: '!', rol: 'alert', duracion: 8000 },
};

const MAX_VISIBLES = 4;
const SALIDA_MS = 200;          // debe coincidir con la animación nt-salir de css/notify.css
const MIN_TRAS_PAUSA_MS = 1500; // al soltar el mouse siempre queda un respiro para terminar de leer

let pila = null;                // el contenedor de todas las notificaciones (se crea al primer uso)
const activas = new Set();      // las que siguen en pantalla y no se están cerrando

// ---------- Contenedor ----------
function obtenerPila() {
  if (pila?.isConnected) return pila;
  pila = document.createElement('div');
  pila.id = 'nt-pila';
  pila.className = 'nt-pila';
  pila.setAttribute('role', 'region');
  pila.setAttribute('aria-label', 'Notificaciones');
  if ('showPopover' in pila) pila.setAttribute('popover', 'manual');
  document.body.append(pila);
  alFrente(true);
  return pila;
}

// Sube la pila a la capa superior. Un <dialog> modal abierto después queda por encima de lo
// que ya estaba en esa capa, así que cada notificación nueva la vuelve a subir si hay uno abierto.
function alFrente(primera = false) {
  if (typeof pila.showPopover !== 'function') return;   // navegador sin Popover API: queda como un fixed normal
  try {
    const abierta = pila.matches(':popover-open');
    if (abierta && (primera || !document.querySelector('dialog:modal'))) return;
    if (abierta) pila.hidePopover();
    pila.showPopover();
  } catch { /* sin soporte: la pila sigue funcionando por z-index */ }
}

// ---------- Tiempo de vida ----------
function programar(n) {
  clearTimeout(n.temporizador);
  if (n.duracion <= 0) return;
  n.inicio = Date.now();
  n.temporizador = setTimeout(() => cerrar(n), n.restante);
}

function actualizarPausa(n) {
  if (n.cerrando || n.duracion <= 0) return;
  const debePausar = n.mouse || n.foco;
  if (debePausar && !n.pausada) {
    n.pausada = true;
    clearTimeout(n.temporizador);
    n.restante -= Date.now() - n.inicio;
  } else if (!debePausar && n.pausada) {
    n.pausada = false;
    n.restante = Math.max(n.restante, MIN_TRAS_PAUSA_MS);
    programar(n);
  }
}

function cerrar(n) {
  if (n.cerrando) return;
  n.cerrando = true;
  clearTimeout(n.temporizador);
  activas.delete(n);
  n.el.classList.add('nt-saliendo');
  setTimeout(() => n.el.remove(), SALIDA_MS);
}

// ---------- Construcción de una notificación ----------
function dibujar(n, { tipo, titulo, mensaje }) {
  const el = document.createElement('div');
  el.className = `nt-aviso nt-aviso--${tipo} nt-entrando`;
  el.setAttribute('role', TIPOS[tipo].rol);

  const icono = document.createElement('span');
  icono.className = 'nt-icono';
  icono.setAttribute('aria-hidden', 'true');
  icono.textContent = TIPOS[tipo].icono;

  const cuerpo = document.createElement('div');
  cuerpo.className = 'nt-cuerpo';
  if (titulo) {
    const t = document.createElement('strong');
    t.className = 'nt-titulo';
    t.textContent = titulo;
    cuerpo.append(t);
  }
  const m = document.createElement('p');
  m.className = 'nt-mensaje';
  m.append(document.createTextNode(mensaje));
  n.contador = document.createElement('span');
  n.contador.className = 'nt-contador';
  n.contador.hidden = true;
  m.append(n.contador);
  cuerpo.append(m);

  const boton = document.createElement('button');
  boton.type = 'button';
  boton.className = 'nt-cerrar';
  boton.setAttribute('aria-label', 'Cerrar notificación');
  boton.textContent = '×';
  boton.addEventListener('click', () => cerrar(n));

  el.append(icono, cuerpo, boton);

  el.addEventListener('animationend', (ev) => {
    if (ev.target === el) el.classList.remove('nt-entrando');
  });
  el.addEventListener('mouseenter', () => { n.mouse = true; actualizarPausa(n); });
  el.addEventListener('mouseleave', () => { n.mouse = false; actualizarPausa(n); });
  el.addEventListener('focusin', () => { n.foco = true; actualizarPausa(n); });
  el.addEventListener('focusout', () => { n.foco = false; actualizarPausa(n); });
  return el;
}

// ---------- API pública ----------
function mostrar(tipo, mensaje, { titulo = '', duracion } = {}) {
  const def = TIPOS[tipo];
  if (!def) throw new Error(`notify: tipo desconocido "${tipo}". Usa ok, info o error.`);
  const texto = String(mensaje ?? '').trim();
  if (texto === '') return () => {};

  const clave = `${tipo}|${titulo}|${texto}`;

  // Idéntica a una que sigue en pantalla: se cuenta, no se apila.
  for (const existente of activas) {
    if (existente.clave !== clave) continue;
    existente.veces += 1;
    existente.contador.textContent = ` ×${existente.veces}`;
    existente.contador.hidden = false;
    existente.restante = existente.duracion;
    if (!existente.pausada) programar(existente);
    alFrente();
    return () => cerrar(existente);
  }

  const n = {
    clave, veces: 1, duracion: duracion ?? def.duracion, restante: 0,
    temporizador: null, inicio: 0, pausada: false, mouse: false, foco: false, cerrando: false,
    el: null, contador: null,
  };
  n.restante = n.duracion;
  n.el = dibujar(n, { tipo, titulo, mensaje: texto });

  obtenerPila().append(n.el);
  activas.add(n);
  programar(n);
  alFrente();

  while (activas.size > MAX_VISIBLES) cerrar(activas.values().next().value);
  return () => cerrar(n);
}

export const notify = {
  ok: (mensaje, opciones) => mostrar('ok', mensaje, opciones),
  info: (mensaje, opciones) => mostrar('info', mensaje, opciones),
  error: (mensaje, opciones) => mostrar('error', mensaje, opciones),
  // Quita todas las notificaciones (ej. al cerrar sesión).
  limpiar: () => { [...activas].forEach(cerrar); },
};
