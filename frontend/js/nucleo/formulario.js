// frontend/js/nucleo/formulario.js
// =====================================================================
//  Helper de formularios (RNF-07, D14). TODO formulario lo usa; nadie
//  escribe su propio submit.
//
//    const f = formulario(el.querySelector('form'), {
//      id: 'incidencia-nueva',                       // nombre del borrador (único por pantalla)
//      validar: (d) => (d.descripcion ? null : { descripcion: 'Obligatoria' }),
//      enviar: (datos, { clave }) => incidenciasApi.crear(datos, { clave }),
//      alExito: (incidencia) => ir(`/incidencias/${incidencia.id}`),
//      alConflicto: (err) => { ...; return true; },  // opcional: 409 con err.datos
//    });
//    return f.destruir;                              // el router lo llama al salir
//
//  Qué resuelve:
//    · Borrador: lo escrito se guarda en sessionStorage (nunca contraseñas ni
//      archivos) y se restaura al volver, también tras un 401 y re-login.
//      Se borra al enviar con éxito y al cerrar sesión.
//    · Idempotencia: UNA Idempotency-Key por formulario. Si falla la red, se
//      agota el tiempo o hay un 5xx, el reintento usa la MISMA clave. Con un 4xx
//      (el servidor rechazó) o tras un éxito, se genera una nueva.
//    · Errores por campo (422): se marcan junto al campo con aria-invalid.
//      El error general sale en <p data-formulario-error hidden> si existe.
//    · Un solo envío a la vez (el botón se bloquea mientras tanto).
//
//  Convención de HTML: cada control dentro de <div class="campo">, con "name".
//  Selects en cascada: llama f.restaurar() cuando ya cargaste sus opciones.
// =====================================================================
import { ErrorApi, nuevaClave } from './http.js';
import { sesion, PREFIJO_BORRADOR } from './sesion.js';
import { notify } from './notify.js';

const TIPOS_SENSIBLES = new Set(['password', 'file']);
const ESPERA_GUARDADO_MS = 400;

// ---------- Lectura / escritura de los controles ----------
function grupos(form, { sensibles }) {
  const mapa = new Map();
  for (const c of form.elements) {
    if (!['INPUT', 'SELECT', 'TEXTAREA'].includes(c.tagName) || !c.name || c.disabled) continue;
    if (['submit', 'button', 'reset', 'image'].includes(c.type)) continue;
    if (!sensibles && TIPOS_SENSIBLES.has(c.type)) continue;
    if (!mapa.has(c.name)) mapa.set(c.name, []);
    mapa.get(c.name).push(c);
  }
  return mapa;
}

// { nombre: texto | boolean (checkbox suelto) | [valores] (grupo/multiple) }
function leer(form, { sensibles }) {
  const datos = {};
  for (const [nombre, lista] of grupos(form, { sensibles })) {
    const primero = lista[0];
    if (primero.type === 'radio') {
      datos[nombre] = lista.find((c) => c.checked)?.value ?? '';
    } else if (primero.type === 'checkbox') {
      datos[nombre] = lista.length === 1 ? primero.checked : lista.filter((c) => c.checked).map((c) => c.value);
    } else if (primero.type === 'select-multiple') {
      datos[nombre] = [...primero.selectedOptions].map((o) => o.value);
    } else {
      datos[nombre] = sensibles && primero.type === 'password' ? primero.value : primero.value.trim();
    }
  }
  return datos;
}

function aplicar(form, guardado) {
  for (const [nombre, lista] of grupos(form, { sensibles: false })) {
    if (!(nombre in guardado)) continue;
    const v = guardado[nombre];
    const primero = lista[0];
    if (primero.type === 'radio') {
      lista.forEach((c) => { c.checked = c.value === v; });
    } else if (primero.type === 'checkbox') {
      lista.forEach((c) => { c.checked = lista.length === 1 ? Boolean(v) : Array.isArray(v) && v.includes(c.value); });
    } else if (primero.type === 'select-multiple') {
      [...primero.options].forEach((o) => { o.selected = Array.isArray(v) && v.includes(o.value); });
    } else if (typeof v === 'string') {
      primero.value = v;
    }
  }
}

// ---------- Almacenamiento del borrador ----------
const guardarEn = (k, v) => { try { sessionStorage.setItem(k, JSON.stringify(v)); } catch { /* sin almacenamiento: no pasa nada */ } };
const leerDe = (k) => { try { return JSON.parse(sessionStorage.getItem(k)); } catch { return null; } };
const borrarDe = (k) => { try { sessionStorage.removeItem(k); } catch { /* idem */ } };

// ---------- El helper ----------
export function formulario(form, { id, enviar, alExito, alConflicto, validar, restaurar = true } = {}) {
  if (!form || !id || typeof enviar !== 'function') {
    throw new Error('formulario(): hacen falta el <form>, "id" y "enviar".');
  }
  const llave = `${PREFIJO_BORRADOR}${sesion.usuario?.id ?? 'anonimo'}:${id}`;
  let clave = nuevaClave();
  let enviando = false;
  let vivo = true;
  let temporizador = null;
  let enviado = false;   // tras un envío correcto no se vuelve a guardar el borrador (hasta que se escriba de nuevo)

  const guardar = () => { if (vivo && !enviado) guardarEn(llave, leer(form, { sensibles: false })); };
  const programarGuardado = () => {
    enviado = false;
    clearTimeout(temporizador);
    temporizador = setTimeout(guardar, ESPERA_GUARDADO_MS);
  };
  const restaurarBorrador = () => {
    const guardado = leerDe(llave);
    if (guardado && typeof guardado === 'object') aplicar(form, guardado);
  };

  // ----- errores en pantalla -----
  function limpiarErrores() {
    form.querySelectorAll('[data-error-campo]').forEach((n) => n.remove());
    form.querySelectorAll('[aria-invalid]').forEach((c) => { c.removeAttribute('aria-invalid'); c.removeAttribute('aria-describedby'); });
    const g = form.querySelector('[data-formulario-error]');
    if (g) { g.hidden = true; g.textContent = ''; }
  }

  function mostrarCampos(campos) {
    let primero = null;
    for (const [nombre, texto] of Object.entries(campos)) {
      const hallado = form.elements.namedItem(nombre);
      const control = hallado instanceof RadioNodeList ? hallado[0] : hallado;
      if (!control) continue;
      const p = document.createElement('p');
      p.className = 'formulario-error';
      p.id = `err-${id}-${nombre}`;
      p.dataset.errorCampo = '';
      p.textContent = String(texto);
      (control.closest('.campo') ?? control.parentElement).append(p);
      control.setAttribute('aria-invalid', 'true');
      control.setAttribute('aria-describedby', p.id);
      primero ??= control;
    }
    primero?.focus();
    return primero !== null;
  }

  function mostrarGeneral(texto) {
    let g = form.querySelector('[data-formulario-error]');
    if (!g) {
      g = document.createElement('p');
      g.className = 'alerta alerta--error';
      g.setAttribute('role', 'alert');
      g.dataset.formularioError = '';
      form.prepend(g);
    }
    g.textContent = texto;
    g.hidden = false;
  }

  const bloquear = (si) => {
    form.querySelectorAll('[type="submit"]').forEach((b) => { b.disabled = si; });
    form.setAttribute('aria-busy', String(si));
  };

  // ----- envío -----
  async function alEnviar(ev) {
    ev.preventDefault();
    if (enviando) return;
    limpiarErrores();

    const datos = leer(form, { sensibles: true });
    const errores = validar?.(datos);
    if (errores && Object.keys(errores).length > 0) { mostrarCampos(errores); return; }

    enviando = true;
    bloquear(true);
    try {
      const resultado = await enviar(datos, { clave });
      clearTimeout(temporizador);
      enviado = true;
      borrarDe(llave);
      clave = nuevaClave();
      if (vivo) await alExito?.(resultado, datos);
    } catch (err) {
      guardar();                                   // el borrador queda a salvo pase lo que pase
      if (!vivo) return;
      if (!(err instanceof ErrorApi)) {
        console.error(err);
        mostrarGeneral('Ocurrió un error inesperado. Lo que escribiste se conservó.');
        return;
      }
      if (err.estado === 401) return;              // app.js lleva al login; el borrador se restaura al volver
      if (err.estado === 409 && alConflicto && (await alConflicto(err))) {
        enviado = true;
        borrarDe(llave);
        clave = nuevaClave();
        return;
      }
      const conCampos = err.campos ? mostrarCampos(err.campos) : false;
      if (err.estado === 0) mostrarGeneral(`${err.message} Lo que escribiste se conservó: puedes reintentar.`);
      else if (!conCampos) mostrarGeneral(err.message);
      else mostrarGeneral('Revisa los campos marcados.');
      if (err.estado === 429 && err.esperaSegundos) notify.error(err.message);
      // 4xx = el servidor lo rechazó: clave nueva. Sin respuesta o 5xx: la MISMA clave en el reintento.
      if (err.estado >= 400 && err.estado < 500) clave = nuevaClave();
    } finally {
      enviando = false;
      bloquear(false);
    }
  }

  form.addEventListener('submit', alEnviar);
  form.addEventListener('input', programarGuardado);
  form.addEventListener('change', programarGuardado);
  if (restaurar) restaurarBorrador();

  return {
    restaurar: restaurarBorrador,
    descartarBorrador: () => borrarDe(llave),
    destruir() {
      clearTimeout(temporizador);
      guardar();           // por si se salió antes de que venciera la espera del guardado
      vivo = false;
      form.removeEventListener('submit', alEnviar);
      form.removeEventListener('input', programarGuardado);
      form.removeEventListener('change', programarGuardado);
    },
  };
}