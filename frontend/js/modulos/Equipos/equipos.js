// frontend/js/modulos/equipos/equipos.js
// Módulo de equipos (RF-04, RF-09). Contrato: docs/api/equipos.md y docs/api/catalogos.md.
// Pantallas:
//   /equipos                      buscar equipos (incluye dados de baja)
//   /equipos/nuevo                registrar equipo (y crear ubicación si falta)
//   /equipos/fuera-de-servicio    equipos activos con incidencia abierta
//   /equipos/:id                  ficha: criticidad, corregir, baja/reactivar e historial
// Lista → ficha = 2 pantallas hasta historial y criticidad (criterio de éxito 3).
import { sesion } from '../../nucleo/sesion.js';
import { PERMISO, PRIORIDAD, PRIORIDAD_ETIQUETA, etiqueta, formatearFechaHora } from '../../nucleo/dominio.js';
import { html, pintarCargando } from '../../nucleo/ui.js';
import { equiposApi, catalogosApi } from './equipos.api.js';
import { nuevaClave } from '../../nucleo/http.js';

// Tipos de equipo = Dominio::TIPOS_EQUIPO (backend/nucleo/Dominio.php). La API no tiene ruta
// para ellos y dominio.js todavía no los exporta: se declaran aquí con su etiqueta.
const TIPO_ETIQUETA = Object.freeze({
  computadora: 'Computadora',
  impresora: 'Impresora',
  proyector: 'Proyector',
  dispositivo_red: 'Dispositivo de red',
  equipo_laboratorio: 'Equipo de laboratorio',
});
const TIPOS = Object.entries(TIPO_ETIQUETA).map(([valor, texto]) => ({ valor, etiqueta: texto }));

// Criticidad = Dominio::CRITICIDADES = alta | media | baja: los mismos valores que PRIORIDAD (RN-04).
const CRITICIDADES = Object.values(PRIORIDAD).map((valor) => ({ valor, etiqueta: PRIORIDAD_ETIQUETA[valor] }));
const etiquetaCriticidad = (v) => etiqueta(PRIORIDAD_ETIQUETA, v) || '—';

export default {
  id: 'equipos',
  titulo: 'Equipos',
  permiso: PERMISO.EQUIPO_GESTIONAR,   // docente y administrativo quedan fuera
  menu: { etiqueta: 'Equipos', ruta: '/equipos', orden: 30 },
  rutas: [
    // Las rutas fijas van antes que /:id
    { ruta: '/equipos', vista: lista },
    { ruta: '/equipos/nuevo', vista: nuevo },
    { ruta: '/equipos/fuera-de-servicio', vista: fueraDeServicio },
    { ruta: '/equipos/:id', vista: ficha },
  ],
};

/* ---------------------------- utilidades ---------------------------- */

// Las opciones se escriben completas (la plantilla html no admite huecos sueltos dentro de una etiqueta).
const opciones = (items, valor, texto, seleccionado = '', vacio = '') => html`
  ${vacio ? html`<option value="">${vacio}</option>` : ''}
  ${items.map((i) => {
    const t = typeof texto === 'function' ? texto(i) : i[texto];
    return String(i[valor]) === String(seleccionado ?? '')
      ? html`<option value="${i[valor]}" selected>${t}</option>`
      : html`<option value="${i[valor]}">${t}</option>`;
  })}`;

const nombreSede = (sedes, id) => sedes.find((s) => String(s.id) === String(id))?.nombre ?? '—';

// La ubicación del catálogo no trae el nombre de su sede: se arma con la lista de sedes.
const etiquetaUbicacion = (sedes) => (u) => `${u.nombre} (${nombreSede(sedes, u.sede_id)})`;

const insignia = (texto, clase = '') => html`<span class="eq-insignia ${clase}">${texto}</span>`;

const numero = (v) => (v === '' || v === null || v === undefined ? '' : Number(v));

// Muestra un aviso en el contenedor [data-aviso] de `raiz`.
function avisar(raiz, texto, tipo = 'info') {
  const a = raiz.querySelector('[data-aviso]');
  if (!a) return;
  a.textContent = texto || '';
  a.className = `eq-aviso eq-aviso--${tipo}`;
  a.hidden = !texto;
}

// Pinta los errores 422 (ErrorApi.campos) junto a cada campo; devuelve true si algún campo los mostró.
function pintarCampos(form, e) {
  form.querySelectorAll('[data-error]').forEach((n) => { n.textContent = ''; });
  const campos = e?.campos;
  if (!campos) return false;
  let mostrado = false;
  for (const [campo, msg] of Object.entries(campos)) {
    const n = form.querySelector(`[data-error="${campo}"]`);
    if (n) { n.textContent = msg; mostrado = true; }
  }
  return mostrado;
}

const mensajeError = (e) => e?.message || 'No se pudo completar la acción. Inténtalo de nuevo.';

// Ejecuta una acción de escritura bloqueando el botón. Solo el 409 `transicion_invalida`
// significa «ya estaba hecho» (trae el equipo actual en `datos`); `conflicto` u `ocupado` se muestran como error.
async function ejecutar(raiz, boton, accion, { alConflicto } = {}) {
  boton.disabled = true;
  avisar(raiz, '');
  try {
    return await accion();
  } catch (e) {
    if (e?.estado === 409 && e.codigo === 'transicion_invalida') {
      if (alConflicto) alConflicto(e);
      return null;
    }
    throw e;
  } finally {
    boton.disabled = false;
  }
}

// Paginador (de 25 en 25): `datos` es la respuesta { items, total, pagina, por_pagina }.
const botonPagina = (texto, delta, desactivado) => (desactivado
  ? html`<button type="button" class="btn" data-pag="${delta}" disabled>${texto}</button>`
  : html`<button type="button" class="btn" data-pag="${delta}">${texto}</button>`);

const paginador = (datos) => html`
  <nav class="eq-paginador" aria-label="Paginación">
    ${botonPagina('Anterior', -1, datos.pagina <= 1)}
    <span>Página ${datos.pagina}</span>
    ${botonPagina('Siguiente', 1, datos.pagina * datos.por_pagina >= datos.total)}
  </nav>`;

function alPaginar(salida, filtros, cargar) {
  salida.addEventListener('click', (ev) => {
    const b = ev.target.closest('[data-pag]');
    if (!b || b.disabled) return;
    filtros.pagina += Number(b.dataset.pag);
    cargar();
  });
}

/* ------------------------------ lista ------------------------------- */

async function lista(el) {
  pintarCargando(el);
  const [sedes, ubicaciones] = await Promise.all([catalogosApi.sedes(), catalogosApi.ubicaciones()]);
  const filtros = { q: '', tipo: '', ubicacion: '', activo: '', pagina: 1 };

  el.innerHTML = html`
    <div class="eq-cabecera">
      <h1>Equipos</h1>
      <div class="eq-acciones">
        <a class="btn" href="#/equipos/fuera-de-servicio">Fuera de servicio</a>
        <a class="btn" href="#/equipos/nuevo">Registrar equipo</a>
      </div>
    </div>
    <form class="eq-filtros" data-filtros>
      <label>Código
        <input type="search" name="q" placeholder="Ej. PC-01" autocomplete="off">
      </label>
      <label>Tipo
        <select name="tipo">${opciones(TIPOS, 'valor', 'etiqueta', '', 'Todos')}</select>
      </label>
      <label>Ubicación
        <select name="ubicacion">${opciones(ubicaciones, 'id', etiquetaUbicacion(sedes), '', 'Todas')}</select>
      </label>
      <label>Estado
        <select name="activo">
          <option value="">Todos (incluye bajas)</option>
          <option value="true">Activos</option>
          <option value="false">Dados de baja</option>
        </select>
      </label>
    </form>
    <div data-resultado></div>`;

  const form = el.querySelector('[data-filtros]');
  const salida = el.querySelector('[data-resultado]');

  async function cargar() {
    pintarCargando(salida);
    let datos;
    try {
      datos = await equiposApi.listar(filtros);
    } catch (e) {
      salida.innerHTML = html`<p class="estado">${mensajeError(e)}</p>`;
      return;
    }
    const hayFiltros = filtros.q || filtros.tipo || filtros.ubicacion || filtros.activo;
    if (datos.items.length === 0 && filtros.pagina === 1) {
      salida.innerHTML = html`<p class="estado">${hayFiltros
        ? 'Ningún equipo coincide con la búsqueda.'
        : 'Todavía no hay equipos registrados.'}</p>`;
      return;
    }
    // Con ubicación elegida la API devuelve todos, sin `pagina` ni `por_pagina`: no hay paginador.
    const paginado = datos.por_pagina !== undefined;
    salida.innerHTML = html`
      <p class="eq-total">${datos.total} ${datos.total === 1 ? 'equipo' : 'equipos'}</p>
      <div class="tabla-contenedor">
        <table class="tabla">
          <thead><tr><th>Código</th><th>Tipo</th><th>Ubicación</th><th>Sede</th><th>Estado</th></tr></thead>
          <tbody>
            ${datos.items.map((i) => html`
              <tr>
                <td><a href="#/equipos/${i.id}">${i.codigo}</a></td>
                <td>${etiqueta(TIPO_ETIQUETA, i.tipo)}</td>
                <td>${i.ubicacion}</td>
                <td>${nombreSede(sedes, i.sede_id)}</td>
                <td>${i.activo ? insignia('Activo') : insignia('De baja', 'eq-insignia--baja')}</td>
              </tr>`)}
          </tbody>
        </table>
      </div>
      ${paginado ? paginador(datos) : ''}`;
  }

  alPaginar(salida, filtros, cargar);

  let espera;
  const leer = () => {
    const d = new FormData(form);
    filtros.q = (d.get('q') || '').trim();
    filtros.tipo = d.get('tipo');
    filtros.ubicacion = d.get('ubicacion');
    filtros.activo = d.get('activo');
    filtros.pagina = 1;
    cargar();
  };
  form.addEventListener('submit', (ev) => { ev.preventDefault(); leer(); });
  form.addEventListener('change', leer);
  form.addEventListener('input', (ev) => {
    if (ev.target.name !== 'q') return;
    clearTimeout(espera);
    espera = setTimeout(leer, 300);
  });

  await cargar();
  return () => clearTimeout(espera);
}

/* ------------------- formulario compartido (alta/edición) ------------------- */

// Campos de tipo, ubicación y criticidad (el código solo se escribe al crear).
function camposEquipo({ equipo, ubicaciones, sedes }) {
  return html`
    <label>Tipo
      <select name="tipo" required>${opciones(TIPOS, 'valor', 'etiqueta', equipo?.tipo, 'Elige un tipo')}</select>
      <small class="eq-error" data-error="tipo"></small>
    </label>
    <label>Ubicación
      <select name="ubicacion_id" required data-ubicaciones>
        ${opciones(ubicaciones, 'id', etiquetaUbicacion(sedes), equipo?.ubicacion_id, 'Elige una ubicación')}
      </select>
      <small class="eq-error" data-error="ubicacion_id"></small>
    </label>
    <label>Criticidad
      <select name="criticidad" required>
        ${opciones(CRITICIDADES, 'valor', 'etiqueta', equipo?.criticidad, 'Elige la criticidad')}
      </select>
      <small class="eq-error" data-error="criticidad"></small>
    </label>`;
}

// Bloque «Crear ubicación»: la ubicación siempre sale de un catálogo.
function bloqueNuevaUbicacion(sedes) {
  return html`
    <details class="eq-nueva-ubicacion" data-nueva-ubicacion>
      <summary>¿No está la ubicación? Créala</summary>
      <div class="eq-grid">
        <label>Nombre de la ubicación
          <input name="u_nombre" type="text" maxlength="80">
          <small class="eq-error" data-error="nombre"></small>
        </label>
        <label>Sede
          <select name="u_sede_id">${opciones(sedes, 'id', 'nombre', '', 'Elige una sede')}</select>
          <small class="eq-error" data-error="sede_id"></small>
        </label>
        <div><button type="button" class="btn" data-crear-ubicacion>Crear ubicación</button></div>
      </div>
    </details>`;
}

function enlazarNuevaUbicacion(form, sedes) {
  const bloque = form.querySelector('[data-nueva-ubicacion]');
  const boton = form.querySelector('[data-crear-ubicacion]');
  if (!bloque || !boton) return;
  boton.addEventListener('click', async () => {
    const nombre = form.elements.u_nombre.value.trim();
    const sedeId = form.elements.u_sede_id.value;
    if (!nombre || !sedeId) {
      pintarCampos(bloque, { campos: {
        ...(nombre ? {} : { nombre: 'Escribe el nombre.' }),
        ...(sedeId ? {} : { sede_id: 'Elige una sede.' }),
      } });
      return;
    }
    boton.disabled = true;
    try {
      const u = await catalogosApi.crearUbicacion({ nombre, sede_id: numero(sedeId) });
      const sel = form.querySelector('[data-ubicaciones]');
      sel.add(new Option(etiquetaUbicacion(sedes)(u), u.id, true, true));
      pintarCampos(bloque, null);
      form.elements.u_nombre.value = '';
      bloque.open = false;
      avisar(form, `Ubicación «${u.nombre}» creada y seleccionada.`, 'ok');
    } catch (e) {
      if (!pintarCampos(bloque, e)) avisar(form, mensajeError(e), 'error');
    } finally {
      boton.disabled = false;
    }
  });
}

/* ------------------------------ nuevo ------------------------------- */

async function nuevo(el, { ir }) {
  pintarCargando(el);
  const [ubicaciones, sedes] = await Promise.all([catalogosApi.ubicaciones(), catalogosApi.sedes()]);
  const clave = nuevaClave();   // misma Idempotency-Key si se reintenta este envío: no duplica el equipo (RNF-07)

  el.innerHTML = html`
    <p><a href="#/equipos">← Volver a equipos</a></p>
    <h1>Registrar equipo</h1>
    <form class="eq-form" data-form novalidate>
      <p data-aviso hidden></p>
      <label>Código
        <input name="codigo" type="text" required maxlength="50" autocomplete="off" placeholder="Ej. PC-01">
        <small>No se podrá cambiar después. «pc 01» y «PC-01» cuentan como el mismo código.</small>
        <small class="eq-error" data-error="codigo"></small>
      </label>
      ${camposEquipo({ ubicaciones, sedes })}
      ${bloqueNuevaUbicacion(sedes)}
      <div class="eq-acciones">
        <button type="submit" class="btn">Registrar equipo</button>
        <a class="btn" href="#/equipos">Cancelar</a>
      </div>
    </form>`;

  const form = el.querySelector('[data-form]');
  enlazarNuevaUbicacion(form, sedes);

  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const boton = form.querySelector('button[type="submit"]');
    const d = new FormData(form);
    boton.disabled = true;
    avisar(form, '');
    try {
      const creado = await equiposApi.crear({
        codigo: (d.get('codigo') || '').trim(),
        tipo: d.get('tipo'),
        ubicacion_id: numero(d.get('ubicacion_id')),
        criticidad: d.get('criticidad'),
      }, clave);
      ir(`/equipos/${creado.id}`);
    } catch (e) {
      if (!pintarCampos(form, e)) avisar(form, mensajeError(e), 'error');
    } finally {
      boton.disabled = false;
    }
  });
}

/* ------------------------------- ficha ------------------------------ */

async function ficha(el, { params }) {
  pintarCargando(el);
  let equipo = await equiposApi.obtener(params.id);
  const [sedes, ubicaciones, primeraPagina] = await Promise.all([
    catalogosApi.sedes(), catalogosApi.ubicaciones(), equiposApi.historial(params.id, 1),
  ]);
  let historial = primeraPagina;   // { items, total, pagina, por_pagina }
  const puedeGestionar = sesion.puede(PERMISO.EQUIPO_GESTIONAR);
  // La baja y la reactivación tienen su propio permiso (solo el jefe de soporte).
  const puedeBaja = sesion.puede(PERMISO.EQUIPO_DAR_DE_BAJA);
  let editando = false;

  const dadoDeBaja = () => equipo.activo === false;

  function pintar() {
    el.innerHTML = html`
      <p><a href="#/equipos">← Volver a equipos</a></p>
      <div class="eq-cabecera">
        <h1>${equipo.codigo} ${dadoDeBaja() ? insignia('De baja', 'eq-insignia--baja') : ''}</h1>
        ${puedeGestionar && !editando ? html`
          <div class="eq-acciones">
            <button type="button" class="btn" data-editar>Corregir datos</button>
            ${puedeBaja && !dadoDeBaja() ? html`<button type="button" class="btn" data-abrir-baja>Dar de baja</button>` : ''}
            ${puedeBaja && dadoDeBaja() ? html`<button type="button" class="btn" data-reactivar>Reactivar</button>` : ''}
          </div>` : ''}
      </div>
      <p data-aviso hidden></p>

      ${editando ? html`
        <form class="eq-form" data-form-editar novalidate>
          <p class="eq-nota">El código <strong>${equipo.codigo}</strong> no se puede cambiar.</p>
          ${camposEquipo({ equipo, ubicaciones, sedes })}
          <div class="eq-acciones">
            <button type="submit" class="btn">Guardar cambios</button>
            <button type="button" class="btn" data-cancelar>Cancelar</button>
          </div>
        </form>` : html`
        <dl class="eq-datos">
          <div><dt>Tipo</dt><dd>${etiqueta(TIPO_ETIQUETA, equipo.tipo)}</dd></div>
          <div><dt>Ubicación</dt><dd>${equipo.ubicacion}</dd></div>
          <div><dt>Sede</dt><dd>${nombreSede(sedes, equipo.sede_id)}</dd></div>
          <div><dt>Criticidad</dt><dd><strong class="eq-crit eq-crit--${equipo.criticidad}">${etiquetaCriticidad(equipo.criticidad)}</strong></dd></div>
        </dl>`}

      <form class="eq-form" data-form-baja hidden novalidate>
        <label>Motivo de la baja
          <textarea name="motivo" rows="3" required maxlength="300"></textarea>
          <small class="eq-error" data-error="motivo"></small>
        </label>
        <div class="eq-acciones">
          <button type="submit" class="btn">Confirmar baja</button>
          <button type="button" class="btn" data-cancelar-baja>Cancelar</button>
        </div>
      </form>

      <h2>Historial de intervenciones</h2>
      ${historial.items.length === 0
        ? html`<p class="estado">Este equipo aún no tiene intervenciones.</p>`
        : html`
          <div class="tabla-contenedor">
            <table class="tabla">
              <thead><tr><th>Fecha</th><th>Falla</th><th>Solución</th><th>Técnico</th></tr></thead>
              <tbody>
                ${historial.items.map((h) => html`
                  <tr>
                    <td>${formatearFechaHora(h.fecha) || '—'}</td>
                    <td>${h.falla ?? '—'}</td>
                    <td>${h.solucion ?? '—'}</td>
                    <td>${h.tecnico ?? '—'}</td>
                  </tr>`)}
              </tbody>
            </table>
          </div>
          ${historial.items.length < historial.total ? html`
            <p class="eq-nota">Se muestran ${historial.items.length} de ${historial.total}.</p>
            <button type="button" class="btn" data-mas-historial>Ver más</button>` : ''}`}`;
    enlazar();
  }

  const refrescar = async (mensaje, tipo = 'ok') => {
    equipo = await equiposApi.obtener(params.id);
    editando = false;
    pintar();
    if (mensaje) avisar(el, mensaje, tipo);
  };
  // El 409 `transicion_invalida` trae el equipo actual en `datos`; si no, se vuelve a pedir.
  const alConflicto = async (e) => {
    const texto = 'Esto ya estaba hecho. Se muestra el estado actual.';
    if (e?.datos?.id) { equipo = e.datos; editando = false; pintar(); avisar(el, texto, 'info'); }
    else await refrescar(texto, 'info');
  };

  function enlazar() {
    const q = (s) => el.querySelector(s);

    q('[data-editar]')?.addEventListener('click', () => { editando = true; pintar(); });
    q('[data-cancelar]')?.addEventListener('click', () => { editando = false; pintar(); });

    q('[data-form-editar]')?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const form = ev.currentTarget;
      const d = new FormData(form);
      try {
        const r = await ejecutar(el, form.querySelector('button[type="submit"]'),
          () => equiposApi.corregir(params.id, {
            tipo: d.get('tipo'), ubicacion_id: numero(d.get('ubicacion_id')), criticidad: d.get('criticidad'),
          }), { alConflicto });
        if (r) await refrescar('Cambios guardados.');
      } catch (e) {
        if (!pintarCampos(form, e)) avisar(el, mensajeError(e), 'error');
      }
    });

    const formBaja = q('[data-form-baja]');
    q('[data-abrir-baja]')?.addEventListener('click', () => { formBaja.hidden = false; formBaja.elements.motivo.focus(); });
    q('[data-cancelar-baja]')?.addEventListener('click', () => { formBaja.hidden = true; });
    formBaja?.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      const motivo = formBaja.elements.motivo.value.trim();
      if (!motivo) { pintarCampos(formBaja, { campos: { motivo: 'Escribe el motivo de la baja.' } }); return; }
      try {
        const r = await ejecutar(el, formBaja.querySelector('button[type="submit"]'),
          () => equiposApi.darDeBaja(params.id, motivo), { alConflicto });
        if (r) await refrescar('Equipo dado de baja.');
      } catch (e) {
        if (!pintarCampos(formBaja, e)) avisar(el, mensajeError(e), 'error');
      }
    });

    q('[data-reactivar]')?.addEventListener('click', async (ev) => {
      try {
        const r = await ejecutar(el, ev.currentTarget, () => equiposApi.reactivar(params.id), { alConflicto });
        if (r) await refrescar('Equipo reactivado.');
      } catch (e) {
        avisar(el, mensajeError(e), 'error');
      }
    });

    q('[data-mas-historial]')?.addEventListener('click', async (ev) => {
      const boton = ev.currentTarget;
      boton.disabled = true;
      try {
        const sig = await equiposApi.historial(params.id, historial.pagina + 1);
        historial = { ...sig, items: [...historial.items, ...sig.items] };
        pintar();
      } catch (e) {
        avisar(el, mensajeError(e), 'error');
        boton.disabled = false;
      }
    });
  }

  pintar();
}

/* ------------------------- fuera de servicio ------------------------- */

async function fueraDeServicio(el) {
  pintarCargando(el);
  const [sedes, ubicaciones] = await Promise.all([catalogosApi.sedes(), catalogosApi.ubicaciones()]);
  const filtros = { sede: '', ubicacion: '', tipo: '', pagina: 1 };

  el.innerHTML = html`
    <p><a href="#/equipos">← Volver a equipos</a></p>
    <h1>Equipos fuera de servicio</h1>
    <p class="eq-nota">Equipos activos con al menos una incidencia abierta, el más antiguo primero.</p>
    <form class="eq-filtros" data-filtros>
      <label>Sede
        <select name="sede">${opciones(sedes, 'id', 'nombre', '', 'Todas')}</select>
      </label>
      <label>Ubicación
        <select name="ubicacion" data-ubicaciones>${opciones(ubicaciones, 'id', etiquetaUbicacion(sedes), '', 'Todas')}</select>
      </label>
      <label>Tipo
        <select name="tipo">${opciones(TIPOS, 'valor', 'etiqueta', '', 'Todos')}</select>
      </label>
    </form>
    <div data-resultado></div>`;

  const form = el.querySelector('[data-filtros]');
  const salida = el.querySelector('[data-resultado]');

  async function cargar() {
    pintarCargando(salida);
    let datos;
    try {
      datos = await equiposApi.fueraDeServicio(filtros);
    } catch (e) {
      salida.innerHTML = html`<p class="estado">${mensajeError(e)}</p>`;
      return;
    }
    if (datos.items.length === 0 && filtros.pagina === 1) {
      salida.innerHTML = html`<p class="estado">No hay equipos fuera de servicio con estos filtros.</p>`;
      return;
    }
    salida.innerHTML = html`
      <p class="eq-total">${datos.total} ${datos.total === 1 ? 'equipo' : 'equipos'}</p>
      <div class="tabla-contenedor">
        <table class="tabla">
          <thead><tr><th>Código</th><th>Tipo</th><th>Ubicación</th><th>Sede</th><th>Incidencias abiertas</th><th>Fuera de servicio desde</th></tr></thead>
          <tbody>
            ${datos.items.map((i) => html`
              <tr>
                <td><a href="#/equipos/${i.id}">${i.codigo}</a></td>
                <td>${etiqueta(TIPO_ETIQUETA, i.tipo)}</td>
                <td>${i.ubicacion}</td>
                <td>${nombreSede(sedes, i.sede_id)}</td>
                <td>${i.incidencias_abiertas}</td>
                <td>${formatearFechaHora(i.desde) || '—'}</td>
              </tr>`)}
          </tbody>
        </table>
      </div>
      ${paginador(datos)}`;
  }

  alPaginar(salida, filtros, cargar);

  form.addEventListener('change', (ev) => {
    // Al cambiar de sede, solo se ofrecen las ubicaciones de esa sede (y la elegida se limpia).
    if (ev.target.name === 'sede') {
      const sede = new FormData(form).get('sede');
      const sel = form.querySelector('[data-ubicaciones]');
      const lista = sede ? ubicaciones.filter((u) => String(u.sede_id) === String(sede)) : ubicaciones;
      sel.innerHTML = opciones(lista, 'id', etiquetaUbicacion(sedes), '', 'Todas');
    }
    const d = new FormData(form);
    filtros.sede = d.get('sede');
    filtros.ubicacion = d.get('ubicacion');
    filtros.tipo = d.get('tipo');
    filtros.pagina = 1;
    cargar();
  });

  await cargar();
}