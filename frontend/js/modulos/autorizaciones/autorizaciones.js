// frontend/js/modulos/plantilla/plantilla.js
// =====================================================================
//  MÓDULO DE EJEMPLO. Para crear el tuyo:
//    1. Copia la carpeta  js/modulos/plantilla  →  js/modulos/<tu_modulo>
//    2. Renombra los archivos:  <tu_modulo>.js  <tu_modulo>.css  <tu_modulo>.api.js
//    3. Cambia el id, el menú, los permisos, las rutas y las vistas de abajo.
//    4. Agrega UNA línea en  js/modulos/index.js
//  Regla: la carpeta, los archivos y el id se llaman igual.
//
//  Este módulo habla con backend/modulos/plantilla (GET /api/plantilla/sedes).
//  Los permisos salen de PERMISO (nucleo/dominio.js), nunca como texto suelto.
// =====================================================================

import { sesion } from '../../nucleo/sesion.js';
import { PERMISO, formatearFechaHora } from '../../nucleo/dominio.js';
import { html, pintarCargando } from '../../nucleo/ui.js';
import { autorizacionesApi } from './autorizaciones.api.js';

const TIPO_ETIQUETA = {
  reparacion_externa: 'Reparación externa',
  compra: 'Compra',
  reemplazo: 'Reemplazo',
};

const ESTADO_ETIQUETA = {
  pendiente: 'Pendiente',
  aprobada: 'Aprobada',
  rechazada: 'Rechazada',
};

export default {
  id: 'autorizaciones',
  titulo: 'Autorizaciones',
  permiso: null,
  menu: { etiqueta: 'Autorizaciones', ruta: '/autorizaciones', orden: 40 },
  rutas: [
    { ruta: '/autorizaciones', vista: lista },
    { ruta: '/autorizaciones/:id', vista: detalle },
  ],
};

async function lista(el, { query }) {
  if (!sesion.puede(PERMISO.AUTORIZACION_SOLICITAR) && !sesion.puede(PERMISO.AUTORIZACION_DECIDIR)) {
    el.innerHTML = html`<p class="estado">No tienes permisos para acceder al módulo de autorizaciones.</p>`;
    return;
  }

  const estadoActual = query?.estado || '';
  const incidenciaFiltro = query?.incidencia || '';
  const paginaActual = Number(query?.pagina) || 1;

  pintarCargando(el);
  const res = await autorizacionesApi.listar({
    estado: estadoActual,
    incidencia: incidenciaFiltro,
    pagina: paginaActual,
  });

  const items = res.items || [];

  el.innerHTML = html`
    <h1>Autorizaciones</h1>
    <p>Solicitudes de compra, reparación externa o reemplazo de equipos.</p>

    <div class="au-filtros">
      <label for="au-filtro-estado">Filtrar por estado:</label>
      <select id="au-filtro-estado" class="input">
        <option value="">Todos (Pendientes primero)</option>
        <option value="pendiente">Pendiente</option>
        <option value="aprobada">Aprobada</option>
        <option value="rechazada">Rechazada</option>
      </select>
    </div>

    ${items.length === 0
      ? html`<p class="estado">No hay autorizaciones registradas con ese filtro.</p>`
      : html`
        <div class="tabla-contenedor">
          <table class="tabla">
            <thead>
              <tr>
                <th>ID</th>
                <th>Incidencia</th>
                <th>Equipo</th>
                <th>Tipo</th>
                <th>Solicitante</th>
                <th>Fecha solicitud</th>
                <th>Estado</th>
                <th>Acción</th>
              </tr>
            </thead>
            <tbody>
              ${items.map((a) => html`
                <tr>
                  <td>#${a.id}</td>
                  <td><a href="#/incidencias/${a.incidencia_id}">INC-${a.incidencia_id}</a></td>
                  <td>${a.equipo}</td>
                  <td>${TIPO_ETIQUETA[a.tipo] || a.tipo}</td>
                  <td>${a.solicitante}</td>
                  <td>${formatearFechaHora(a.fecha_solicitud)}</td>
                  <td><span class="au-badge au-badge--${a.estado}">${ESTADO_ETIQUETA[a.estado] || a.estado}</span></td>
                  <td><a href="#/autorizaciones/${a.id}" class="btn">Ver detalle</a></td>
                </tr>
              `)}
            </tbody>
          </table>
        </div>
      `}
  `;

  const selectEstado = el.querySelector('#au-filtro-estado');
  if (selectEstado) {
    selectEstado.value = estadoActual;
    selectEstado.addEventListener('change', (e) => {
      const val = e.target.value;
      window.location.hash = val ? `#/autorizaciones?estado=${encodeURIComponent(val)}` : '#/autorizaciones';
    });
  }
}

async function detalle(el, { params }) {
  pintarCargando(el);
  const item = await autorizacionesApi.obtener(params.id);
  const puedeDecidir = sesion.puede(PERMISO.AUTORIZACION_DECIDIR);

  el.innerHTML = html`
    <p><a href="#/autorizaciones">← Volver a autorizaciones</a></p>
    <h1>Autorización #${item.id}</h1>

    <div id="au-mensaje-contenedor"></div>

    <div class="au-tarjeta">
      <div class="au-grilla">
        <div>
          <div class="au-campo-etiqueta">Estado actual</div>
          <div class="au-campo-valor">
            <span class="au-badge au-badge--${item.estado}">${ESTADO_ETIQUETA[item.estado] || item.estado}</span>
          </div>
        </div>
        <div>
          <div class="au-campo-etiqueta">Incidencia asociada</div>
          <div class="au-campo-valor">
            <a href="#/incidencias/${item.incidencia_id}">Ver Incidencia #${item.incidencia_id}</a>
          </div>
        </div>
        <div>
          <div class="au-campo-etiqueta">Equipo</div>
          <div class="au-campo-valor">${item.equipo}</div>
        </div>
        <div>
          <div class="au-campo-etiqueta">Tipo de autorización</div>
          <div class="au-campo-valor">${TIPO_ETIQUETA[item.tipo] || item.tipo}</div>
        </div>
        <div>
          <div class="au-campo-etiqueta">Solicitado por</div>
          <div class="au-campo-valor">${item.solicitante}</div>
        </div>
        <div>
          <div class="au-campo-etiqueta">Fecha de solicitud</div>
          <div class="au-campo-valor">${formatearFechaHora(item.fecha_solicitud)}</div>
        </div>
      </div>

      <div>
        <div class="au-campo-etiqueta">Justificación técnica</div>
        <p>${item.justificacion}</p>
      </div>

      ${item.estado !== 'pendiente'
        ? html`
          <div class="au-decision-box">
            <h3>Resolución registrada</h3>
            <div class="au-grilla">
              <div>
                <div class="au-campo-etiqueta">Decidido por</div>
                <div class="au-campo-valor">${item.decidido_por || '-'}</div>
              </div>
              <div>
                <div class="au-campo-etiqueta">Fecha de decisión</div>
                <div class="au-campo-valor">${formatearFechaHora(item.fecha_decision)}</div>
              </div>
            </div>
            <div>
              <div class="au-campo-etiqueta">Motivo de la decisión</div>
              <p>${item.motivo || '-'}</p>
            </div>
          </div>
        `
        : puedeDecidir
          ? html`
            <div class="au-decision-box">
              <h3>Responder solicitud (Jefe de Informática)</h3>
              <label for="au-motivo" class="au-campo-etiqueta">Motivo obligatorio (máximo 300 caracteres):</label>
              <textarea id="au-motivo" class="input" rows="3" maxlength="300" placeholder="Escribe el motivo de aprobación o rechazo..."></textarea>
              <div class="au-acciones">
                <button type="button" id="au-btn-aprobar" class="btn">Aprobar solicitud</button>
                <button type="button" id="au-btn-rechazar" class="btn">Rechazar solicitud</button>
              </div>
            </div>
          `
          : html`
            <div class="au-decision-box">
              <p class="estado">Esta solicitud está pendiente de aprobación por el Jefe de Informática.</p>
            </div>
          `}
    </div>
  `;

  if (item.estado === 'pendiente' && puedeDecidir) {
    const txtMotivo = el.querySelector('#au-motivo');
    const btnAprobar = el.querySelector('#au-btn-aprobar');
    const btnRechazar = el.querySelector('#au-btn-rechazar');
    const msgBox = el.querySelector('#au-mensaje-contenedor');

    const enviarDecision = async (decision) => {
      const motivo = txtMotivo.value.trim();
      if (!motivo) {
        msgBox.innerHTML = html`<p class="estado">El motivo es obligatorio tanto para aprobar como para rechazar.</p>`;
        txtMotivo.focus();
        return;
      }

      btnAprobar.disabled = true;
      btnRechazar.disabled = true;

      try {
        await autorizacionesApi.decidir(item.id, { decision, motivo });
        await detalle(el, { params });
      } catch (err) {
        if (err?.estado === 409 || err?.status === 409 || err?.codigo === 'transicion_invalida') {
          msgBox.innerHTML = html`<div class="au-aviso-info">Esta autorización ya estaba respondida. Actualizando vista...</div>`;
          setTimeout(() => detalle(el, { params }), 1200);
        } else {
          msgBox.innerHTML = html`<p class="estado">${err?.message || 'No se pudo registrar la decisión.'}</p>`;
          btnAprobar.disabled = false;
          btnRechazar.disabled = false;
        }
      }
    };

    btnAprobar.addEventListener('click', () => enviarDecision('aprobada'));
    btnRechazar.addEventListener('click', () => enviarDecision('rechazada'));
  }
}
