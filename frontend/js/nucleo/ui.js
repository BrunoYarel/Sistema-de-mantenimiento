// =====================================================================
//  Utilidades de interfaz compartidas.
//
//  html`...`  → plantilla que ESCAPA todo lo que interpolas y entiende
//               en qué parte del HTML está cada hueco:
//     el.innerHTML = html`<h1>${incidencia.descripcion}</h1>`;
//     el.innerHTML = html`<a href="${url}">${texto}</a>`;      // URL validada sola
//     el.innerHTML = html`<a href="#/equipos/${id}">…</a>`;    // id codificado solo
//
//  Reglas que la plantilla hace cumplir (si no se cumplen, LANZA un error
//  al pintar, así se ve en desarrollo y no llega a producción):
//    · Los huecos en atributos van SIEMPRE entre comillas.
//    · No hay huecos en nombres de etiqueta ni de atributo.
//    · No hay huecos en on…=, style= ni srcdoc= (la CSP los bloquea igual).
//    · No hay huecos dentro de <script> ni <style>.
//    · href/src/action/… con hueco: solo http, https, mailto, tel o rutas
//      relativas. Cualquier otra cosa (javascript:, data:, //otro-sitio)
//      se reemplaza por "#".
//
//  crudo(texto) → marca un texto como HTML de confianza. NO lo uses con
//                 datos del backend; existe solo por compatibilidad.
// =====================================================================
import { ErrorApi } from './http.js';

class Seguro {
  constructor(valor) { this.valor = valor; }
  toString() { return this.valor; }   // por eso se puede asignar directo a innerHTML
}

export class ErrorPlantilla extends Error {
  constructor(mensaje) { super(`html\`\`: ${mensaje}`); this.name = 'ErrorPlantilla'; }
}

const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;', '`': '&#96;' };

export const escapar = (v) => String(v ?? '').replace(/[&<>"'`]/g, (c) => ESCAPES[c]);
export const crudo = (texto) => new Seguro(String(texto));

// ---------- URLs ----------
const ESQUEMAS_OK = new Set(['http:', 'https:', 'mailto:', 'tel:']);

// Devuelve la URL si es segura; si no, '#'.
export function urlSegura(valor) {
  const s = String(valor ?? '').trim();
  if (s === '') return '';
  // Caracteres de control o barra invertida: los navegadores los "arreglan" de formas
  // que permiten colar esquemas ("java\tscript:") o dominios ("/\\sitio.com").
  if (/[\u0000-\u001f\u007f\\]/.test(s)) return '#';
  const esquema = /^([a-z][a-z0-9+.-]*:)/i.exec(s);
  if (esquema) return ESQUEMAS_OK.has(esquema[1].toLowerCase()) ? s : '#';
  if (s.startsWith('//')) return '#';          // "protocolo relativo" = otro sitio
  return s;                                    // #/ruta, /ruta, ruta, ?x=1
}

// Para un hueco dentro de una URL que ya tiene prefijo escrito por el programador
// (href="#/equipos/${id}"): se codifica el dato; las "/" se conservan.
const codificarSegmento = (v) => encodeURIComponent(String(v ?? '')).replace(/%2F/gi, '/');

const ATRIBUTOS_URL = new Set([
  'href', 'src', 'action', 'formaction', 'xlink:href', 'poster', 'data',
  'cite', 'background', 'ping', 'manifest', 'longdesc', 'usemap',
]);

// ---------- Análisis de contexto (en qué parte del HTML cae cada hueco) ----------
function estadoInicial() {
  return {
    enTag: false, leyendoNombre: false, cierre: false, nombreTag: '',
    quote: null, attr: '', ultimo: '', esperaValor: false, sinComillas: false,
    valor: '', valorHoles: false, raw: '', buf: '',
  };
}

function cerrarTag(e) {
  e.enTag = false;
  if (!e.cierre && (e.nombreTag === 'script' || e.nombreTag === 'style')) { e.raw = e.nombreTag; e.buf = ''; }
  e.leyendoNombre = false; e.quote = null; e.attr = ''; e.ultimo = '';
  e.esperaValor = false; e.sinComillas = false; e.valor = ''; e.valorHoles = false;
}

function avanzar(e, texto) {
  for (let i = 0; i < texto.length; i++) {
    const c = texto[i];

    if (!e.enTag) {
      if (e.raw) {                                   // dentro de <script>/<style>
        e.buf = (e.buf + c).slice(-12).toLowerCase();
        if (e.buf.endsWith(`</${e.raw}`)) { e.raw = ''; e.buf = ''; }
        continue;
      }
      if (c === '<') {
        const sig = texto[i + 1];
        if (sig === undefined || /[a-z/]/i.test(sig)) {
          e.enTag = true; e.leyendoNombre = true; e.cierre = false; e.nombreTag = '';
          e.quote = null; e.attr = ''; e.ultimo = ''; e.esperaValor = false;
          e.sinComillas = false; e.valor = ''; e.valorHoles = false;
        }
      }
      continue;
    }

    // ---- dentro de una etiqueta ----
    if (e.leyendoNombre) {
      if (c === '/' && e.nombreTag === '' && !e.cierre) { e.cierre = true; continue; }
      if (/[a-z0-9:-]/i.test(c)) { e.nombreTag += c.toLowerCase(); continue; }
      e.leyendoNombre = false;                       // el carácter actual cierra el nombre
    }

    if (e.quote) {
      if (c === e.quote) { e.quote = null; e.attr = ''; e.ultimo = ''; e.valor = ''; e.valorHoles = false; }
      else e.valor += c;
      continue;
    }
    if (e.sinComillas) {
      if (/\s/.test(c)) { e.sinComillas = false; e.esperaValor = false; e.ultimo = ''; }
      else if (c === '>') cerrarTag(e);
      continue;
    }
    if (c === '>') { cerrarTag(e); continue; }
    if (c === '"' || c === "'") {
      if (e.esperaValor) { e.quote = c; e.esperaValor = false; e.valor = ''; e.valorHoles = false; }
      continue;
    }
    if (c === '=') { e.attr = e.ultimo.toLowerCase(); e.ultimo = ''; e.esperaValor = true; continue; }
    if (/\s/.test(c)) { if (!e.esperaValor) e.ultimo = ''; continue; }
    if (c === '/') continue;
    if (e.esperaValor) e.sinComillas = true; else e.ultimo += c;
  }
}

function aTexto(v) {
  if (v === null || v === undefined || v === false) return '';   // permite  ${cond && html`...`}
  if (v instanceof Seguro) return v.valor;
  if (Array.isArray(v)) return v.map(aTexto).join('');
  return escapar(v);
}

function aAtributo(e, v) {
  const nombre = e.attr;
  if (nombre.startsWith('on') || nombre === 'style' || nombre === 'srcdoc') {
    throw new ErrorPlantilla(`no se permite un hueco dentro de ${nombre}="…" (la CSP lo bloquea). Usa clases CSS y data-accion.`);
  }
  if (v instanceof Seguro || Array.isArray(v) || (v !== null && typeof v === 'object')) {
    throw new ErrorPlantilla(`el atributo ${nombre} solo acepta texto o números, no plantillas ni objetos.`);
  }
  if (v === null || v === undefined || v === false) return '';
  if (ATRIBUTOS_URL.has(nombre)) {
    const entero = e.valor.trim() === '' && !e.valorHoles;    // el hueco ES la URL completa
    return escapar(entero ? urlSegura(v) : codificarSegmento(v));
  }
  return escapar(v);
}

export function html(partes, ...valores) {
  if (!Array.isArray(partes) || !Array.isArray(partes.raw)) {
    throw new ErrorPlantilla('se usa como etiqueta de plantilla: html`<p>${dato}</p>`, no como función con texto.');
  }
  const e = estadoInicial();
  let salida = '';
  for (let i = 0; i < partes.length; i++) {
    avanzar(e, partes[i]);
    salida += partes[i];
    if (i >= valores.length) break;

    if (e.raw) throw new ErrorPlantilla('no se permiten huecos dentro de <script> ni <style>.');
    if (!e.enTag) {
      salida += aTexto(valores[i]);
    } else if (e.quote) {
      salida += aAtributo(e, valores[i]);
      e.valorHoles = true;
    } else {
      throw new ErrorPlantilla('los huecos en atributos deben ir entre comillas (href="${x}"), y no se permiten en nombres de etiqueta ni de atributo.');
    }
  }
  return new Seguro(salida);
}

// ---------- Estados de pantalla ----------
export function pintarCargando(el) {
  el.innerHTML = html`<p class="estado">Cargando…</p>`;
}

// Muestra un error con botón "Reintentar". El router lo usa solo cuando una vista falla.
export function pintarError(el, err, reintentar) {
  const mensaje = err instanceof ErrorApi ? err.message : 'Ocurrió un error inesperado. Inténtalo de nuevo.';
  el.innerHTML = html`
    <div class="estado estado--error">
      <p>${mensaje}</p>
      ${reintentar ? html`<button type="button" class="btn" data-reintentar>Reintentar</button>` : ''}
    </div>`;
  if (reintentar) el.querySelector('[data-reintentar]').addEventListener('click', reintentar);
}

// Agrega una hoja de estilos a la página (app.js lo usa con el CSS de cada módulo).
export function cargarCss(url) {
  const enlace = document.createElement('link');
  enlace.rel = 'stylesheet';
  enlace.href = url;
  document.head.append(enlace);
}