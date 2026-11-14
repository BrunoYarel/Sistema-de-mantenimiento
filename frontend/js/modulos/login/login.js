// frontend/js/modulos/login/login.js
// =====================================================================
//  Módulo login: inicio de sesión (/login, pública) y cambio de clave
//  (/cambiar-clave, también obligatorio cuando debe_cambiar_clave).
//  La validación real la hace el backend (sesion.entrar / sesion.cambiarClave).
// =====================================================================
import { sesion } from '../../nucleo/sesion.js';
import { ErrorApi } from '../../nucleo/http.js';
import { formulario } from '../../nucleo/formulario.js';
import { CLAVE_MIN } from '../../nucleo/dominio.js';
import { html } from '../../nucleo/ui.js';
import { notify } from '../../nucleo/notify.js';

export default {
  id: 'login',
  titulo: 'Iniciar sesión',
  permiso: null,                    // el módulo es abierto; /login además es pública
  menu: { etiqueta: 'Cambiar clave', ruta: '/cambiar-clave', orden: 95 },
  rutas: [
    { ruta: '/login', vista, publica: true },
    // cambioClave: es la ÚNICA pantalla que el router deja abrir con clave temporal.
    { ruta: '/cambiar-clave', vista: vistaCambiarClave, permiso: null, cambioClave: true, titulo: 'Cambiar clave' },
  ],
};

// Adónde volver tras entrar: solo rutas internas, nunca /login ni //otro-sitio ni "\".
export function destinoSeguro(volver) {
  const v = String(volver ?? '');
  const malo = !v.startsWith('/')
    || v.startsWith('//')
    || /[\u0000-\u001f\u007f\\]/.test(v)
    || /^\/(login|cambiar-clave)(\/|\?|$)/.test(v);
  return malo ? '/' : v;
}

function vista(el, { query, ir }) {
  if (sesion.estaActiva()) { ir('/'); return; }

  const destino = destinoSeguro(query.get('volver'));

  el.innerHTML = html`
    <div class="lg-pagina">
      <form class="lg-tarjeta" novalidate>
        <header class="lg-cabecera">
          <span class="lg-logo" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>
          </span>
          <h1>Iniciar sesión</h1>
          <p>Sistema de mantenimiento y soporte técnico</p>
        </header>

        <p class="alerta alerta--error" role="alert" hidden></p>

        <div class="campo">
          <label for="login-usuario">Usuario</label>
          <input id="login-usuario" name="usuario" autocomplete="username" autocapitalize="none" spellcheck="false" required>
        </div>

        <div class="campo">
          <label for="login-clave">Contraseña</label>
          <div class="lg-clave">
            <input id="login-clave" name="clave" type="password" autocomplete="current-password" required>
            <button type="button" class="btn btn--texto lg-ver" aria-pressed="false" aria-controls="login-clave">Mostrar</button>
          </div>
        </div>

        <button class="btn btn--bloque" type="submit">Entrar</button>
      </form>
    </div>`;

  const form = el.querySelector('form');
  const campoUsuario = form.elements.usuario;
  const campoClave = form.elements.clave;
  const verClave = form.querySelector('.lg-ver');
  const boton = form.querySelector('button[type="submit"]');
  const error = form.querySelector('[role="alert"]');

  const mostrarError = (texto, campo) => {
    error.textContent = texto;
    error.hidden = false;
    campo.setAttribute('aria-invalid', 'true');
    campo.focus();
  };
  const limpiarError = () => {
    error.hidden = true;
    campoUsuario.removeAttribute('aria-invalid');
    campoClave.removeAttribute('aria-invalid');
  };

  campoUsuario.focus();
  campoUsuario.addEventListener('input', limpiarError);
  campoClave.addEventListener('input', limpiarError);

  // Mostrar / ocultar la contraseña
  verClave.addEventListener('click', () => {
    const visible = campoClave.type === 'password';
    campoClave.type = visible ? 'text' : 'password';
    verClave.textContent = visible ? 'Ocultar' : 'Mostrar';
    verClave.setAttribute('aria-pressed', String(visible));
    campoClave.focus();
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    limpiarError();
    const usuario = campoUsuario.value.trim();
    const clave = campoClave.value;
    if (!usuario) return mostrarError('Escribe tu usuario.', campoUsuario);
    if (!clave) return mostrarError('Escribe tu contraseña.', campoClave);

    boton.disabled = true;                 // evita enviar dos veces
    boton.textContent = 'Entrando…';
    try {
      await sesion.entrar({ usuario, clave });
      ir(destino);                          // con clave temporal, el router lo desvía a /cambiar-clave
    } catch (err) {
      // El usuario escrito se conserva; la contraseña se borra para escribirla de nuevo.
      // 429: el mensaje de http.js ya trae la espera ("Espera 5 minutos...").
      const texto = err.estado === 401
        ? 'Usuario o contraseña incorrectos.'
        : err instanceof ErrorApi ? err.message : 'Ocurrió un error inesperado. Inténtalo de nuevo.';
      campoClave.value = '';
      mostrarError(texto, campoClave);
      boton.disabled = false;
      boton.textContent = 'Entrar';
    }
  });
}

// ---------- Cambio de clave ----------
function vistaCambiarClave(el, { ir }) {
  const obligatorio = Boolean(sesion.usuario?.debe_cambiar_clave);

  el.innerHTML = html`
    <div class="lg-pagina">
      <form class="lg-tarjeta" novalidate>
        <header class="lg-cabecera">
          <h1>Cambia tu contraseña</h1>
          <p>${obligatorio ? 'Tu contraseña es temporal. Debes cambiarla para continuar.' : 'Elige una contraseña nueva.'}</p>
        </header>

        <p class="alerta alerta--error" role="alert" data-formulario-error hidden></p>

        <div class="campo">
          <label for="clave-actual">Contraseña actual</label>
          <input id="clave-actual" name="clave_actual" type="password" autocomplete="current-password" required>
        </div>
        <div class="campo">
          <label for="clave-nueva">Contraseña nueva</label>
          <input id="clave-nueva" name="clave_nueva" type="password" autocomplete="new-password" minlength="${CLAVE_MIN}" required>
          <small>Mínimo ${CLAVE_MIN} caracteres. Una frase larga es mejor que símbolos.</small>
        </div>
        <div class="campo">
          <label for="clave-repetir">Repite la contraseña nueva</label>
          <input id="clave-repetir" name="repetir" type="password" autocomplete="new-password" required>
        </div>

        <button class="btn btn--bloque" type="submit">Cambiar contraseña</button>
        ${obligatorio ? '' : html`<p><a href="#/">Cancelar</a></p>`}
      </form>
    </div>`;

  const f = formulario(el.querySelector('form'), {
    id: 'cambiar-clave',
    // Los nombres de campo (clave_actual, clave_nueva) son los del contrato: así los errores 422
    // del backend se marcan junto al campo correcto. "repetir" solo existe en la pantalla.
    validar: (d) => {
      const errores = {};
      if (!d.clave_actual) errores.clave_actual = 'Escribe tu contraseña actual.';
      if (d.clave_nueva.length < CLAVE_MIN) errores.clave_nueva = `Debe tener al menos ${CLAVE_MIN} caracteres.`;
      else if (d.clave_nueva === d.clave_actual) errores.clave_nueva = 'Debe ser distinta de la actual.';
      if (d.repetir !== d.clave_nueva) errores.repetir = 'No coincide con la contraseña nueva.';
      return errores;
    },
    enviar: (d) => sesion.cambiarClave({ clave_actual: d.clave_actual, clave_nueva: d.clave_nueva }),
    alExito: () => { notify.ok('Contraseña actualizada.'); ir('/'); },
  });
  return f.destruir;
}