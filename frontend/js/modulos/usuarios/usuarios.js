// frontend/js/modulos/usuarios/usuarios.js
// =====================================================================
//  Módulo usuarios (RF-12): ver, crear, editar y desactivar usuarios.
//  SOLO para quien tiene usuario.gestionar (el administrador del sistema = jefe de informática):
//    · el menú no muestra "Usuarios" a los demás roles,
//    · el router muestra "Sin permiso" si alguien abre la dirección a mano,
//    · y lo que de verdad manda: el backend responde 403 a quien no tenga el permiso.
//
//    #/usuarios                lista con filtros (q, rol, sede, activo) y páginas de 25
//    #/usuarios/nuevo          alta: el sistema genera una clave temporal que se muestra UNA vez
//    #/usuarios/:id            editar nombre, rol y sede; restablecer clave, desbloquear, desactivar
//
//  "Borrar" = DESACTIVAR (RN-07): un usuario con historial no se elimina; deja de poder entrar y se
//  puede reactivar. Contrato: docs/api/usuarios.md. Estilos: usuarios.css (prefijo us-).
// =====================================================================
import { sesion } from '../../nucleo/sesion.js';
import { ErrorApi } from '../../nucleo/http.js';
import { formulario } from '../../nucleo/formulario.js';
import { PERMISO, ROL, ROL_ETIQUETA, etiqueta } from '../../nucleo/dominio.js';
import { html, pintarCargando } from '../../nucleo/ui.js';
import { notify } from '../../nucleo/notify.js';
import { usuariosApi } from './usuarios.api.js';

export default {
  id: 'usuarios',
  titulo: 'Usuarios',
  permiso: PERMISO.USUARIO_GESTIONAR,                  // todas las rutas del módulo lo exigen
  menu: { etiqueta: 'Usuarios', ruta: '/usuarios', orden: 80 },
  rutas: [
    { ruta: '/usuarios', vista: lista },
    { ruta: '/usuarios/nuevo', vista: nuevo, titulo: 'Nuevo usuario' },   // fija: gana a /usuarios/:id
    { ruta: '/usuarios/:id', vista: editar, titulo: 'Editar usuario' },
  ],
};

const ROLES = Object.values(ROL);
const FORMATO_USUARIO = /^[A-Za-z0-9][A-Za-z0-9._@-]{2,59}$/;   // el backend lo repite y tiene la última palabra
const MAX_NOMBRE = 100;

// ---------- Utilidades ----------
const esMio = (u) => u.id === sesion.usuario?.id;
const mensajeDe = (err) => (err instanceof ErrorApi ? err.message : 'Ocurrió un error inesperado. Inténtalo de nuevo.');
const nombreSede = (sedes, id) => (id === null || id === undefined ? 'Sin sede' : sedes.find((s) => s.id === id)?.nombre ?? `Sede ${id}`);

// Iniciales para el avatar de la cabecera: "Administrativo de prueba" → "AP" (se ignoran "de", "la", "y"...).
const PARTICULAS = new Set(['de', 'del', 'la', 'las', 'los', 'el', 'y', 'e']);
function iniciales(nombre) {
  const palabras = nombre.trim().split(/\s+/).filter((p) => p && !PARTICULAS.has(p.toLowerCase()));
  if (palabras.length === 0) return '+';
  return (palabras[0][0] + (palabras[1]?.[0] ?? '')).toUpperCase();
}

// El avatar sigue al campo "Nombre completo" mientras se escribe.
function avatarEnVivo(el) {
  const entrada = el.querySelector('input[name="nombre"]');
  const avatar = el.querySelector('[data-avatar]');
  if (!entrada || !avatar) return;
  entrada.addEventListener('input', () => { avatar.textContent = iniciales(entrada.value); });
}

// Un hueco no puede ir en el nombre de un atributo: "selected" va en una opción u otra.
const opcionesRol = (elegido) => ROLES.map((r) => (r === elegido
  ? html`<option value="${r}" selected>${etiqueta(ROL_ETIQUETA, r)}</option>`
  : html`<option value="${r}">${etiqueta(ROL_ETIQUETA, r)}</option>`));

function selectorSede(sedes, elegida, vacio) {
  return html`
    <option value="">${vacio}</option>
    ${sedes.map((s) => (s.id === elegida
    ? html`<option value="${s.id}" selected>${s.nombre}</option>`
    : html`<option value="${s.id}">${s.nombre}</option>`))}`;
}

function insignias(u) {
  return html`
    <span class="us-etiqueta ${u.activo ? 'us-etiqueta--ok' : 'us-etiqueta--neutro'}">${u.activo ? 'Activo' : 'Desactivado'}</span>
    ${u.bloqueado ? html`<span class="us-etiqueta us-etiqueta--error">Bloqueado por intentos</span>` : ''}
    ${u.debe_cambiar_clave ? html`<span class="us-etiqueta us-etiqueta--neutro">Clave temporal</span>` : ''}`;
}

// ---------- Diálogos (nativos <dialog>: sin librerías ni onclick) ----------
// Viven dentro de la pantalla actual (`el`): si el router cambia de pantalla, desaparecen con ella.

// ¿Seguro? → true si confirmó. Esc o Cancelar → false.
function confirmar(el, { titulo, texto, boton, peligro = false }) {
  return new Promise((resolver) => {
    const d = document.createElement('dialog');
    d.className = 'us-dialogo';
    d.setAttribute('aria-labelledby', 'us-dialogo-titulo');
    d.innerHTML = html`
      <form method="dialog">
        <h2 id="us-dialogo-titulo">${titulo}</h2>
        <p>${texto}</p>
        <div class="us-dialogo-botones">
          <button type="submit" value="no" class="btn btn--texto">Cancelar</button>
          <button type="submit" value="si" class="btn ${peligro ? 'us-peligro' : ''}">${boton}</button>
        </div>
      </form>`;
    d.addEventListener('close', () => { resolver(d.returnValue === 'si'); d.remove(); });
    el.append(d);
    d.showModal();
    d.querySelector('button[value="no"]').focus();   // lo seguro por defecto: no hacer nada
  });
}

// Muestra la clave temporal. Se ve UNA sola vez: el backend no la guarda en claro, así que el diálogo
// no se cierra con Esc (solo con "Listo") y la clave no va a la dirección, a storage ni a ningún borrador.
function mostrarClave(el, { titulo, usuario, nombre, clave }) {
  return new Promise((resolver) => {
    const d = document.createElement('dialog');
    d.className = 'us-dialogo';
    d.setAttribute('aria-labelledby', 'us-dialogo-titulo');
    const contenido = clave
      ? html`
        <form method="dialog">
          <h2 id="us-dialogo-titulo">${titulo}</h2>
          <p>Entrégale estos datos a <strong>${nombre}</strong>. Deberá cambiar la clave al entrar.</p>
          <dl class="us-credencial">
            <dt>Usuario</dt><dd><code>${usuario}</code></dd>
            <dt>Clave temporal</dt><dd><code class="us-clave" data-clave>${clave}</code></dd>
          </dl>
          <p class="alerta us-aviso-unica">Se muestra una sola vez. Si la pierdes, usa «Restablecer clave».</p>
          <div class="us-dialogo-botones">
            <button type="button" class="btn btn--texto" data-copiar>Copiar clave</button>
            <button type="submit" class="btn">Listo</button>
          </div>
        </form>`
      : html`
        <form method="dialog">
          <h2 id="us-dialogo-titulo">${titulo}</h2>
          <p>El usuario <code>${usuario}</code> ya se había creado y su clave ya se mostró: por seguridad no se puede ver otra vez.</p>
          <p>Abre su ficha y usa «Restablecer clave» para obtener una nueva.</p>
          <div class="us-dialogo-botones"><button type="submit" class="btn">Entendido</button></div>
        </form>`;
    d.innerHTML = html`${contenido}`;

    d.addEventListener('cancel', (ev) => ev.preventDefault());   // Esc no cierra: la clave no se vuelve a mostrar
    d.addEventListener('close', () => { d.remove(); resolver(); });
    d.querySelector('[data-copiar]')?.addEventListener('click', async () => {
      try {
        await navigator.clipboard.writeText(clave);
        notify.ok('Clave copiada.');
      } catch {
        notify.error('No se pudo copiar. Selecciónala y cópiala a mano.');
      }
    });
    el.append(d);
    d.showModal();
  });
}

// Corre una acción del backend y avisa. Devuelve el resultado, o null si falló.
// 409 transicion_invalida = "ya estaba hecho": no es un error, la pantalla solo se actualiza.
async function ejecutar(accion, mensajeOk) {
  try {
    const r = await accion();
    notify.ok(mensajeOk);
    return r ?? true;
  } catch (err) {
    if (err instanceof ErrorApi && err.estado === 409 && err.codigo === 'transicion_invalida') {
      notify.info(`${err.message} Se actualizó la pantalla.`);
      return err.datos ?? true;
    }
    if (!(err instanceof ErrorApi && err.estado === 401)) notify.error(mensajeDe(err));   // el 401 ya lo atiende app.js
    return null;
  }
}

// ---------- Lista ----------
// Los filtros viven en la dirección (#/usuarios?q=ana&rol=docente&pagina=2), no en storage. Un valor raro
// se descarta aquí en vez de llegar al backend como 422.
function leerFiltros(query) {
  const rol = query.get('rol') ?? '';
  const sede = query.get('sede') ?? '';
  const activo = query.get('activo') ?? '';
  const pagina = Number(query.get('pagina'));
  return {
    q: (query.get('q') ?? '').trim().slice(0, 50),
    rol: ROLES.includes(rol) ? rol : '',
    sede: /^\d{1,9}$/.test(sede) ? sede : '',
    activo: ['true', 'false'].includes(activo) ? activo : '',
    pagina: Number.isInteger(pagina) && pagina >= 1 && pagina <= 10000 ? pagina : 1,
  };
}

function direccion(filtros, pagina) {
  const p = new URLSearchParams();
  Object.entries({ ...filtros, pagina: pagina > 1 ? pagina : '' }).forEach(([k, v]) => { if (v !== '' && v !== undefined) p.set(k, v); });
  const texto = p.toString();
  return texto ? `/usuarios?${texto}` : '/usuarios';
}

function pintarTabla(datos, sedes, filtros) {
  const { items, total, pagina, por_pagina: porPagina } = datos;
  if (items.length === 0 && total > 0) {
    return html`<p class="estado">Esta página quedó vacía. <a href="#/usuarios">Volver al inicio de la lista</a></p>`;
  }
  if (items.length === 0) {
    return html`<p class="estado">${total === 0 && Object.values({ ...filtros, pagina: '' }).some(Boolean)
      ? 'Ningún usuario coincide con esos filtros.' : 'Todavía no hay usuarios.'}</p>`;
  }
  const paginas = Math.max(1, Math.ceil(total / porPagina));
  const desde = (pagina - 1) * porPagina + 1;

  return html`
    <div class="tabla-contenedor">
      <table class="tabla us-tabla">
        <thead>
          <tr><th>Nombre</th><th>Usuario</th><th>Rol</th><th>Sede por defecto</th><th>Estado</th><th><span class="us-solo-lector">Acciones</span></th></tr>
        </thead>
        <tbody>
          ${items.map((u) => html`
            <tr class="${u.activo ? '' : 'us-fila-inactiva'}">
              <td><a href="#/usuarios/${u.id}">${u.nombre}</a>${esMio(u) ? html` <small class="us-yo">(tú)</small>` : ''}</td>
              <td><code>${u.usuario}</code></td>
              <td>${etiqueta(ROL_ETIQUETA, u.rol)}</td>
              <td>${nombreSede(sedes, u.sede_id)}</td>
              <td><div class="us-estados">${insignias(u)}</div></td>
              <td class="us-acciones">
                <a class="btn btn--texto us-enlace" href="#/usuarios/${u.id}">Editar</a>
                ${u.bloqueado ? html`<button type="button" class="btn btn--texto" data-accion="desbloquear" data-id="${u.id}">Desbloquear</button>` : ''}
                ${u.activo
    ? (esMio(u) ? '' : html`<button type="button" class="btn btn--texto us-peligro-texto" data-accion="desactivar" data-id="${u.id}">Desactivar</button>`)
    : html`<button type="button" class="btn btn--texto" data-accion="reactivar" data-id="${u.id}">Reactivar</button>`}
              </td>
            </tr>`)}
        </tbody>
      </table>
    </div>
    <nav class="us-paginas" aria-label="Páginas">
      <span>${desde}–${desde + items.length - 1} de ${total}</span>
      ${pagina > 1 ? html`<a class="btn btn--texto us-enlace" href="${`#${direccion(filtros, pagina - 1)}`}">← Anterior</a>` : ''}
      ${pagina < paginas ? html`<a class="btn btn--texto us-enlace" href="${`#${direccion(filtros, pagina + 1)}`}">Siguiente →</a>` : ''}
    </nav>`;
}

async function lista(el, { query, ir }) {
  pintarCargando(el);
  const filtros = leerFiltros(query);
  const [datos, sedes] = await Promise.all([usuariosApi.listar(filtros), usuariosApi.sedes()]);

  el.innerHTML = html`
    <div class="us-cabecera">
      <h1>Usuarios</h1>
      <a class="btn us-enlace" href="#/usuarios/nuevo">Nuevo usuario</a>
    </div>

    <form class="us-filtros" novalidate>
      <div class="campo">
        <label for="us-f-q">Buscar</label>
        <input id="us-f-q" name="q" type="search" maxlength="50" placeholder="Nombre o usuario" autocomplete="off" value="${filtros.q}">
      </div>
      <div class="campo">
        <label for="us-f-rol">Rol</label>
        <select id="us-f-rol" name="rol">
          <option value="">Todos</option>
          ${ROLES.map((r) => (r === filtros.rol
    ? html`<option value="${r}" selected>${etiqueta(ROL_ETIQUETA, r)}</option>`
    : html`<option value="${r}">${etiqueta(ROL_ETIQUETA, r)}</option>`))}
        </select>
      </div>
      <div class="campo">
        <label for="us-f-sede">Sede por defecto</label>
        <select id="us-f-sede" name="sede">${selectorSede(sedes, Number(filtros.sede) || null, 'Todas')}</select>
      </div>
      <div class="campo">
        <label for="us-f-activo">Estado</label>
        <select id="us-f-activo" name="activo">
          <option value="">Todos</option>
          ${filtros.activo === 'true' ? html`<option value="true" selected>Activos</option>` : html`<option value="true">Activos</option>`}
          ${filtros.activo === 'false' ? html`<option value="false" selected>Desactivados</option>` : html`<option value="false">Desactivados</option>`}
        </select>
      </div>
      <div class="campo us-filtros-botones">
        <button type="submit" class="btn">Filtrar</button>
        <a class="btn btn--texto us-enlace" href="#/usuarios">Limpiar</a>
      </div>
    </form>

    <div data-resultado aria-live="polite"></div>`;

  const resultado = el.querySelector('[data-resultado]');
  let actual = datos;
  const pintar = (d) => { actual = d; resultado.innerHTML = html`${pintarTabla(d, sedes, filtros)}`; };
  pintar(datos);

  // Tras una acción se vuelve a pedir la misma página sin tocar los filtros ni el scroll.
  async function recargar() {
    try {
      pintar(await usuariosApi.listar(filtros));
    } catch (err) {
      notify.error(mensajeDe(err));
    }
  }

  const f = formulario(el.querySelector('form.us-filtros'), {
    id: 'usuarios-filtros',
    restaurar: false,                                   // los filtros mandan en la dirección, no en un borrador
    enviar: (d) => d,
    alExito: (d) => ir(direccion({ q: d.q, rol: d.rol, sede: d.sede, activo: d.activo }, 1)),
  });

  resultado.addEventListener('click', async (ev) => {
    const boton = ev.target.closest('[data-accion]');
    if (!boton || boton.disabled) return;
    const id = Number(boton.dataset.id);
    const u = actual.items.find((x) => x.id === id);
    if (!u) return;

    boton.disabled = true;
    try {
      if (boton.dataset.accion === 'desactivar') {
        const si = await confirmar(el, {
          titulo: `¿Desactivar a ${u.nombre}?`,
          texto: 'No se borra: su historial se conserva. Dejará de poder entrar al sistema y podrás reactivarlo cuando quieras.',
          boton: 'Desactivar',
          peligro: true,
        });
        if (!si) return;
        await ejecutar(() => usuariosApi.desactivar(id), `${u.nombre} fue desactivado.`);
      } else if (boton.dataset.accion === 'reactivar') {
        await ejecutar(() => usuariosApi.reactivar(id), `${u.nombre} fue reactivado.`);
      } else if (boton.dataset.accion === 'desbloquear') {
        await ejecutar(() => usuariosApi.desbloquear(id), `${u.nombre} ya puede intentar entrar de nuevo.`);
      }
      await recargar();   // el estado de la fila cambió: se relee la página y se repintan insignias y botones
    } finally {
      boton.disabled = false;
    }
  });

  return f.destruir;
}

// ---------- Alta ----------
async function nuevo(el, { ir }) {
  pintarCargando(el);
  const sedes = await usuariosApi.sedes();

  el.innerHTML = html`
    <a class="us-volver" href="#/usuarios">← Volver a usuarios</a>
    <header class="us-encabezado">
      <span class="us-avatar" data-avatar aria-hidden="true">+</span>
      <div>
        <h1>Nuevo usuario</h1>
        <p class="us-sub">Completa los datos de la persona. La clave de acceso la genera el sistema.</p>
      </div>
    </header>

    <div class="us-disposicion">
      <form class="us-formulario" novalidate>
        <div class="us-cuerpo">
          <p class="alerta alerta--error us-sin-margen" role="alert" data-formulario-error hidden></p>

          <div class="us-grupo" role="group" aria-labelledby="us-g-persona">
            <h2 id="us-g-persona">Datos de la persona</h2>
            <div class="us-rejilla">
              <div class="campo">
                <label for="us-nombre">Nombre completo</label>
                <input id="us-nombre" name="nombre" maxlength="${MAX_NOMBRE}" autocomplete="off" required>
              </div>
              <div class="campo">
                <label for="us-usuario">Nombre de usuario</label>
                <input id="us-usuario" name="usuario" maxlength="60" autocomplete="off" autocapitalize="none" spellcheck="false" required>
                <small>3 a 60 caracteres: letras sin tildes, números, punto, guion, guion bajo o @. No se podrá cambiar después.</small>
              </div>
            </div>
          </div>

          <div class="us-grupo" role="group" aria-labelledby="us-g-permisos">
            <h2 id="us-g-permisos">Rol y sede</h2>
            <div class="us-rejilla">
              <div class="campo">
                <label for="us-rol">Rol</label>
                <select id="us-rol" name="rol" required>
                  <option value="">Elige un rol…</option>
                  ${opcionesRol('')}
                </select>
              </div>
              <div class="campo">
                <label for="us-sede">Sede por defecto <span class="us-opcional">(opcional)</span></label>
                <select id="us-sede" name="sede_id">${selectorSede(sedes, null, 'Sin sede por defecto')}</select>
                <small>Solo preselecciona la sede al entrar; no limita lo que la persona puede ver.</small>
              </div>
            </div>
          </div>
        </div>

        <div class="us-pie">
          <a class="btn btn--texto us-enlace" href="#/usuarios">Cancelar</a>
          <button type="submit" class="btn">Crear usuario</button>
        </div>
      </form>

      <aside class="us-lateral">
        <section class="us-bloque" aria-labelledby="us-info-clave">
          <h2 id="us-info-clave">Cómo recibe su clave</h2>
          <ol class="us-pasos">
            <li>Al guardar, el sistema genera una clave temporal.</li>
            <li>La verás una sola vez, para entregársela a la persona.</li>
            <li>En su primer ingreso deberá cambiarla por una propia.</li>
          </ol>
        </section>
      </aside>
    </div>`;

  avatarEnVivo(el);

  const f = formulario(el.querySelector('form'), {
    id: 'usuario-nuevo',
    validar: (d) => {
      const errores = {};
      if (!d.nombre) errores.nombre = 'Escribe el nombre completo.';
      else if (d.nombre.length > MAX_NOMBRE) errores.nombre = `Máximo ${MAX_NOMBRE} caracteres.`;
      if (!d.usuario) errores.usuario = 'Escribe el nombre de usuario.';
      else if (!FORMATO_USUARIO.test(d.usuario)) errores.usuario = 'De 3 a 60 caracteres: letras sin tildes, números, punto, guion, guion bajo o @.';
      if (!ROLES.includes(d.rol)) errores.rol = 'Elige un rol.';
      return errores;
    },
    // `clave` es la Idempotency-Key: si falla la red y se reintenta, no se crea dos veces.
    enviar: (d, { clave }) => usuariosApi.crear(
      { nombre: d.nombre, usuario: d.usuario, rol: d.rol, sede_id: d.sede_id === '' ? null : Number(d.sede_id) },
      { clave }
    ),
    alExito: (u) => {
      notify.ok(`Usuario «${u.usuario}» creado.`);
      // Sin await: el botón se libera, pero el diálogo es modal. Al cerrarlo, de vuelta a la lista.
      mostrarClave(el, { titulo: 'Usuario creado', usuario: u.usuario, nombre: u.nombre, clave: u.clave_temporal })
        .then(() => ir('/usuarios'));
    },
  });
  return f.destruir;
}

// ---------- Edición ----------
async function editar(el, { params, ir, ruta }) {
  pintarCargando(el);
  let u;
  let sedes;
  try {
    [u, sedes] = await Promise.all([usuariosApi.obtener(params.id), usuariosApi.sedes()]);
  } catch (err) {
    if (!(err instanceof ErrorApi && err.estado === 404)) throw err;   // el resto: el router ofrece "Reintentar"
    el.innerHTML = html`
      <div class="estado">
        <h1>Usuario no encontrado</h1>
        <p><a href="#/usuarios">Volver a usuarios</a></p>
      </div>`;
    return undefined;
  }

  const mio = esMio(u);

  el.innerHTML = html`
    <a class="us-volver" href="#/usuarios">← Volver a usuarios</a>
    <header class="us-encabezado">
      <span class="us-avatar ${u.activo ? '' : 'us-avatar--inactivo'}" data-avatar aria-hidden="true">${iniciales(u.nombre)}</span>
      <div>
        <h1>${u.nombre}</h1>
        <div class="us-sub">
          <code>${u.usuario}</code>
          ${mio ? html`<small class="us-yo">(tú)</small>` : ''}
          ${insignias(u)}
        </div>
      </div>
    </header>

    <div class="us-disposicion">
      <form class="us-formulario" novalidate>
        <div class="us-cuerpo">
          <p class="alerta alerta--error us-sin-margen" role="alert" data-formulario-error hidden></p>

          <div class="us-grupo" role="group" aria-labelledby="us-g-persona">
            <h2 id="us-g-persona">Datos de la persona</h2>
            <div class="us-rejilla">
              <div class="campo">
                <label for="us-nombre">Nombre completo</label>
                <input id="us-nombre" name="nombre" maxlength="${MAX_NOMBRE}" autocomplete="off" value="${u.nombre}" required>
              </div>
              <div class="campo">
                <label for="us-usuario">Nombre de usuario</label>
                <input id="us-usuario" value="${u.usuario}" disabled>
                <small>El nombre de usuario no se puede cambiar.</small>
              </div>
            </div>
          </div>

          <div class="us-grupo" role="group" aria-labelledby="us-g-permisos">
            <h2 id="us-g-permisos">Rol y sede</h2>
            <div class="us-rejilla">
              <div class="campo">
                <label for="us-rol">Rol</label>
                ${mio
    ? html`<select id="us-rol" disabled>${opcionesRol(u.rol)}</select>
                <small>No puedes cambiar tu propio rol: otro administrador debe hacerlo.</small>`
    : html`<select id="us-rol" name="rol" required>${opcionesRol(u.rol)}</select>
                <small>El cambio rige desde la siguiente acción de esa persona.</small>`}
              </div>
              <div class="campo">
                <label for="us-sede">Sede por defecto <span class="us-opcional">(opcional)</span></label>
                <select id="us-sede" name="sede_id">${selectorSede(sedes, u.sede_id, 'Sin sede por defecto')}</select>
                <small>Solo preselecciona la sede al entrar; no limita lo que la persona puede ver.</small>
              </div>
            </div>
          </div>
        </div>

        <div class="us-pie">
          <a class="btn btn--texto us-enlace" href="#/usuarios">Cancelar</a>
          <button type="submit" class="btn">Guardar cambios</button>
        </div>
      </form>

      <aside class="us-lateral">
        <section class="us-bloque" aria-labelledby="us-acceso">
          <h2 id="us-acceso">Acceso</h2>
          <p>Si la persona olvidó su clave, genera una temporal nueva: la anterior deja de valer y deberá cambiarla al entrar.</p>
          <div class="us-botones">
            <button type="button" class="btn btn--texto us-contorno" data-accion="restablecer">Restablecer clave</button>
            ${u.bloqueado ? html`<button type="button" class="btn btn--texto us-contorno" data-accion="desbloquear">Desbloquear acceso</button>` : ''}
          </div>
        </section>

        ${mio ? '' : html`
          <section class="us-bloque ${u.activo ? 'us-bloque--peligro' : ''}" aria-labelledby="us-estado">
            <h2 id="us-estado">Estado de la cuenta</h2>
            ${u.activo
    ? html`<p>Un usuario desactivado no puede entrar, pero su historial se conserva (no se borra). Puedes reactivarlo cuando quieras.</p>
              <button type="button" class="btn btn--bloque us-peligro" data-accion="desactivar">Desactivar usuario</button>`
    : html`<p>Este usuario está desactivado y no puede entrar al sistema.</p>
              <button type="button" class="btn btn--bloque" data-accion="reactivar">Reactivar usuario</button>`}
          </section>`}
      </aside>
    </div>`;

  avatarEnVivo(el);

  // El rol de uno mismo no se manda (el select está deshabilitado y formulario() ignora lo deshabilitado).
  const f = formulario(el.querySelector('form'), {
    id: `usuario-editar-${u.id}`,
    restaurar: false,                                   // siempre se parte de lo que hay guardado, no de un borrador viejo
    validar: (d) => {
      const errores = {};
      if (!d.nombre) errores.nombre = 'Escribe el nombre completo.';
      else if (d.nombre.length > MAX_NOMBRE) errores.nombre = `Máximo ${MAX_NOMBRE} caracteres.`;
      if (d.rol !== undefined && !ROLES.includes(d.rol)) errores.rol = 'Elige un rol.';
      return errores;
    },
    // Solo viaja lo que cambió (el backend deja un registro por cada cambio real).
    enviar: async (d) => {
      const sede = d.sede_id === '' ? null : Number(d.sede_id);
      const cambios = {};
      if (d.nombre !== u.nombre) cambios.nombre = d.nombre;
      if (d.rol !== undefined && d.rol !== u.rol) cambios.rol = d.rol;
      if (sede !== u.sede_id) cambios.sede_id = sede;
      return Object.keys(cambios).length === 0 ? null : usuariosApi.editar(u.id, cambios);
    },
    alExito: (resultado) => {
      if (resultado) notify.ok('Cambios guardados.');
      else notify.info('No había cambios que guardar.');
      ir('/usuarios');
    },
  });

  el.addEventListener('click', async (ev) => {
    const boton = ev.target.closest('[data-accion]');
    if (!boton || boton.disabled) return;
    boton.disabled = true;
    let cambio = false;
    try {
      switch (boton.dataset.accion) {
        case 'restablecer': {
          const si = await confirmar(el, {
            titulo: `¿Restablecer la clave de ${u.nombre}?`,
            texto: 'La clave actual dejará de valer. Verás la nueva clave una sola vez para entregársela.',
            boton: 'Restablecer clave',
          });
          if (!si) break;
          const r = await ejecutar(() => usuariosApi.restablecerClave(u.id), 'Clave restablecida.');
          if (r && r.clave_temporal) {
            await mostrarClave(el, { titulo: 'Nueva clave temporal', usuario: u.usuario, nombre: u.nombre, clave: r.clave_temporal });
          }
          cambio = Boolean(r);
          break;
        }
        case 'desbloquear':
          cambio = Boolean(await ejecutar(() => usuariosApi.desbloquear(u.id), 'Acceso desbloqueado.'));
          break;
        case 'desactivar': {
          const si = await confirmar(el, {
            titulo: `¿Desactivar a ${u.nombre}?`,
            texto: 'No se borra: su historial se conserva. Dejará de poder entrar al sistema y podrás reactivarlo cuando quieras.',
            boton: 'Desactivar',
            peligro: true,
          });
          if (!si) break;
          cambio = Boolean(await ejecutar(() => usuariosApi.desactivar(u.id), `${u.nombre} fue desactivado.`));
          break;
        }
        case 'reactivar':
          cambio = Boolean(await ejecutar(() => usuariosApi.reactivar(u.id), `${u.nombre} fue reactivado.`));
          break;
        default:
      }
    } finally {
      boton.disabled = false;
    }
    if (cambio) ir(ruta);   // misma dirección: el router vuelve a pedir el usuario y repinta insignias y botones
  });

  return f.destruir;
}