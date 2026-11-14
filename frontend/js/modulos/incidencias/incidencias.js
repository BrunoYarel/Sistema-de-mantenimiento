
// =====================================================================
//  PROTOTIPO de RF-01 (reportar una falla) y RF-02 (registrar a nombre de un docente).
//  Una sola pantalla: #/incidencias/nueva. Lo que ve cada quien sale de sus permisos:
//    incidencia.registrar_propia    → docente, administrativo: canal 'sistema', reportante = yo.
//    incidencia.registrar_a_nombre  → soporte y jefe de soporte: eligen canal y reportante.
//  Contrato: docs/api/incidencias.md (POST /incidencias, con aviso de duplicado RF-03).
// =====================================================================
import { sesion } from '../../nucleo/sesion.js';
import {
  PERMISO, CANAL, CANAL_ETIQUETA, ESTADO_ETIQUETA, PRIORIDAD_ETIQUETA, etiqueta, formatearFechaHora,
} from '../../nucleo/dominio.js';
import { html, pintarCargando } from '../../nucleo/ui.js';
import { formulario } from '../../nucleo/formulario.js';
import { nuevaClave } from '../../nucleo/http.js';
import { notify } from '../../nucleo/notify.js';
import { incidenciasApi } from './incidencias.api.js';

// Valores que aún no están en nucleo/dominio.js (IMPACTOS y TIPOS_EQUIPO sí existen en Dominio.php).
// Al integrar, mover a dominio.js avisando al grupo (así lo compara tests/dominio_front_test.php).
const IMPACTO = Object.freeze({ NINGUNO: 'ninguno', CLASE: 'clase', EVALUACION: 'evaluacion' });
const IMPACTO_ETIQUETA = Object.freeze({
  [IMPACTO.NINGUNO]: 'No afecta una clase ni una evaluación',
  [IMPACTO.CLASE]: 'Afecta una clase',
  [IMPACTO.EVALUACION]: 'Afecta una evaluación',
});
const TIPO_ETIQUETA = Object.freeze({
  computadora: 'Computadora',
  impresora: 'Impresora',
  proyector: 'Proyector',
  dispositivo_red: 'Dispositivo de red',
  equipo_laboratorio: 'Equipo de laboratorio',
});

// RF-02: lo que llega por otro canal que no es el sistema.
const CANALES_A_NOMBRE = [CANAL.LLAMADA, CANAL.MENSAJE, CANAL.FORMULARIO];
// RF-03: códigos de error 409 que significan "este equipo ya tiene una incidencia en curso".
const DUPLICADOS = ['incidencia_abierta', 'incidencia_resuelta'];

export default {
  id: 'incidencias',                 // = nombre de la carpeta
  titulo: 'Reportar falla',
  permiso: null,                     // sesión basta; dentro de la vista se decide por permiso
  menu: { etiqueta: 'Reportar falla', ruta: '/incidencias/nueva', orden: 10 },
  rutas: [
    { ruta: '/incidencias/nueva', vista: nueva },
  ],
};

// ---------- Vista ----------
async function nueva(el) {
  const aNombre = sesion.puede(PERMISO.INCIDENCIA_REGISTRAR_A_NOMBRE);
  if (!aNombre && !sesion.puede(PERMISO.INCIDENCIA_REGISTRAR_PROPIA)) {
    el.innerHTML = html`
      <h1>Reportar una falla</h1>
      <p class="estado">Tu rol no registra incidencias.</p>`;
    return undefined;
  }

  pintarCargando(el);
  const sedes = await incidenciasApi.sedes();   // si falla, el router muestra "Reintentar"

  let f = null;
  const avisar = (e) => notify.error(e?.message ?? 'Ocurrió un error. Inténtalo de nuevo.');

  async function mostrar() {
    f?.destruir();
    el.innerHTML = plantillaFormulario(sedes, aNombre);

    const form = el.querySelector('form');
    const sel = (nombre) => form.elements.namedItem(nombre);
    const panel = form.querySelector('[data-duplicado]');
    let ultimo = null;   // último cuerpo enviado: se reutiliza al vincular o confirmar "otro problema"

    // ----- selects en cascada: sede → ubicación → equipo -----
    const llenar = (select, items, textoVacio, texto = () => '') => {
      select.innerHTML = html`
        <option value="">${textoVacio}</option>
        ${items.map((i) => html`<option value="${i.id}">${texto(i)}</option>`)}`;
    };

    async function cargarUbicaciones() {
      llenar(sel('ubicacion'), [], 'Elige primero la sede');
      llenar(sel('equipo_id'), [], 'Elige primero la ubicación');
      const sedeId = sel('sede').value;
      if (!sedeId) return;
      const lista = await incidenciasApi.ubicaciones(sedeId);
      llenar(sel('ubicacion'), lista, 'Elige la ubicación', (u) => u.nombre);
    }

    async function cargarEquipos() {
      llenar(sel('equipo_id'), [], 'Elige primero la ubicación');
      const ubicacionId = sel('ubicacion').value;
      if (!ubicacionId) return;
      const { items } = await incidenciasApi.equipos(ubicacionId);
      llenar(
        sel('equipo_id'),
        items,
        items.length ? 'Elige el equipo' : 'No hay equipos en esta ubicación',
        (e) => `${etiqueta(TIPO_ETIQUETA, e.tipo)} · ${e.codigo}`,
      );
    }

    // ----- RF-03: aviso de duplicado -----
    const limpiarDuplicado = () => { panel.hidden = true; panel.innerHTML = ''; };

    function exito(inc, vinculada) {
      el.innerHTML = html`
        <div class="ic-exito" role="status">
          <h1>${vinculada ? 'Reporte vinculado' : 'Incidencia registrada'}</h1>
          <p class="ic-numero">N.° ${inc.id}</p>
          <p>Estado: <strong>${etiqueta(ESTADO_ETIQUETA, inc.estado)}</strong>${inc.prioridad ? html` · Prioridad: ${etiqueta(PRIORIDAD_ETIQUETA, inc.prioridad)}` : ''}</p>
          <p>${vinculada
            ? 'Tu reporte se sumó a la incidencia que ya existía; no se creó otra.'
            : 'Quedó registrada. Podrás ver su estado y la fecha del último cambio en tus incidencias.'}</p>
          <button type="button" class="btn" data-otra>Registrar otra</button>
        </div>`;
      el.querySelector('[data-otra]').addEventListener('click', () => { mostrar().catch(avisar); });
    }

    async function reenviar(extra, clave, botones) {
      botones.forEach((b) => { b.disabled = true; });
      try {
        const inc = await incidenciasApi.crear({ ...ultimo, ...extra }, { clave });
        f.destruir();            // guarda el borrador una última vez...
        f.descartarBorrador();   // ...y lo borra: ya se envió
        exito(inc, extra.vincular_a !== undefined);
      } catch (err) {
        avisar(err);
        botones.forEach((b) => { b.disabled = false; });
      }
    }

    function mostrarDuplicado(err) {
      const dup = err.datos;
      const resuelta = err.codigo === 'incidencia_resuelta';
      // Una clave por botón: si falla la red y se reintenta, no se duplica (RNF-07).
      const claves = { vincular: nuevaClave(), nueva: nuevaClave() };

      panel.innerHTML = html`
        <p><strong>Este equipo ya tiene la incidencia N.° ${dup.id}</strong>
          (${etiqueta(ESTADO_ETIQUETA, dup.estado)}, último cambio: ${formatearFechaHora(dup.fecha_ultimo_cambio)}).</p>
        <p>${resuelta ? '¿Sigue fallando o es otro problema?' : '¿Es la misma falla?'}</p>
        <div class="ic-duplicado-botones">
          <button type="button" class="btn" data-vincular>${resuelta ? 'Sigue fallando' : 'Es la misma: vincular mi reporte'}</button>
          <button type="button" class="btn btn--texto" data-nueva>Es otro problema</button>
        </div>`;
      panel.hidden = false;

      const botones = [...panel.querySelectorAll('button')];
      panel.querySelector('[data-vincular]').addEventListener('click', () => reenviar({ vincular_a: dup.id }, claves.vincular, botones));
      panel.querySelector('[data-nueva]').addEventListener('click', () => reenviar({ confirmar_nueva: true }, claves.nueva, botones));
    }

    // ----- el formulario (RNF-07: borrador, Idempotency-Key, errores por campo) -----
    f = formulario(form, {
      id: aNombre ? 'incidencia-a-nombre' : 'incidencia-nueva',
      validar: (d) => {
        const e = {};
        if (aNombre) {
          if (!d.canal) e.canal = 'Elige el canal por el que llegó.';
          if (!d.reportante_id) e.reportante_id = 'Busca y elige a quién reportó.';
        }
        if (!d.sede) e.sede = 'Elige la sede.';
        else if (!d.ubicacion) e.ubicacion = 'Elige la ubicación.';
        else if (!d.equipo_id) e.equipo_id = 'Elige el equipo.';
        if (!d.descripcion) e.descripcion = 'Describe la falla.';
        if (!d.impacto) e.impacto = 'Indica si afecta una clase o una evaluación.';
        return e;
      },
      enviar: (d, { clave }) => {
        limpiarDuplicado();
        ultimo = {
          equipo_id: Number(d.equipo_id),
          descripcion: d.descripcion,
          impacto: d.impacto,
          ...(aNombre ? { reportante_id: Number(d.reportante_id), canal: d.canal } : {}),
        };
        return incidenciasApi.crear(ultimo, { clave });
      },
      alExito: (inc) => exito(inc, false),
      alConflicto: (err) => {
        if (!DUPLICADOS.includes(err.codigo) || !err.datos) return false;
        mostrarDuplicado(err);
        return true;
      },
    });

    sel('sede').addEventListener('change', () => { limpiarDuplicado(); cargarUbicaciones().catch(avisar); });
    sel('ubicacion').addEventListener('change', () => { limpiarDuplicado(); cargarEquipos().catch(avisar); });
    sel('equipo_id').addEventListener('change', limpiarDuplicado);

    // ----- RF-02: buscar a quién reportó -----
    if (aNombre) {
      const buscar = async () => {
        const q = sel('buscar').value.trim();
        const destino = sel('reportante_id');
        if (q.length < 2) { llenar(destino, [], 'Escribe al menos 2 letras y pulsa Buscar'); return; }
        const personas = await incidenciasApi.reportantes(q);
        llenar(destino, personas, personas.length ? 'Elige a la persona' : 'Sin resultados', (p) => p.nombre);
      };
      form.querySelector('[data-buscar]').addEventListener('click', () => { buscar().catch(avisar); });
      // Enter en el buscador busca; no envía el formulario.
      sel('buscar').addEventListener('keydown', (ev) => {
        if (ev.key === 'Enter') { ev.preventDefault(); buscar().catch(avisar); }
      });
    }

    // Borrador restaurado: las opciones de la cascada se cargan por pasos y se restaura tras cada una.
    try {
      await cargarUbicaciones();
      f.restaurar();
      if (sel('ubicacion').value) {
        await cargarEquipos();
        f.restaurar();
      }
    } catch (e) {
      avisar(e);
    }
  }

  await mostrar();
  return () => f?.destruir();   // el router lo llama al salir
}

// ---------- HTML del formulario ----------
function plantillaFormulario(sedes, aNombre) {
  return html`
    <h1>${aNombre ? 'Registrar una incidencia a nombre de un docente' : 'Reportar una falla'}</h1>
    <p class="ic-ayuda">${aNombre
      ? 'Para lo que llegó por llamada, mensaje o formulario impreso. Queda guardado el canal y quién lo registró.'
      : 'Elige dónde está el equipo y cuéntanos qué pasa. No necesitas ningún código.'}</p>

    <form class="ic-form" novalidate>
      ${aNombre ? html`
        <div class="campo">
          <label for="ic-canal">Canal por el que llegó</label>
          <select id="ic-canal" name="canal">
            <option value="">Elige el canal</option>
            ${CANALES_A_NOMBRE.map((c) => html`<option value="${c}">${etiqueta(CANAL_ETIQUETA, c)}</option>`)}
          </select>
        </div>
        <div class="campo">
          <label for="ic-buscar">Docente o administrativo que reportó</label>
          <div class="ic-busqueda">
            <input id="ic-buscar" name="buscar" type="text" maxlength="50" autocomplete="off" placeholder="Nombre o usuario (mínimo 2 letras)">
            <button type="button" class="btn btn--texto" data-buscar>Buscar</button>
          </div>
          <select id="ic-reportante" name="reportante_id" aria-label="Resultados de la búsqueda">
            <option value="">Busca primero a la persona</option>
          </select>
        </div>` : ''}

      <div class="campo">
        <label for="ic-sede">Sede</label>
        <select id="ic-sede" name="sede">
          <option value="">Elige la sede</option>
          ${sedes.map((s) => html`<option value="${s.id}">${s.nombre}</option>`)}
        </select>
      </div>
      <div class="campo">
        <label for="ic-ubicacion">Ubicación</label>
        <select id="ic-ubicacion" name="ubicacion">
          <option value="">Elige primero la sede</option>
        </select>
      </div>
      <div class="campo">
        <label for="ic-equipo">Equipo</label>
        <select id="ic-equipo" name="equipo_id">
          <option value="">Elige primero la ubicación</option>
        </select>
      </div>
      <div class="campo">
        <label for="ic-descripcion">¿Qué pasa?</label>
        <textarea id="ic-descripcion" name="descripcion" rows="4"></textarea>
      </div>
      <div class="campo">
        <label for="ic-impacto">¿Afecta una clase o una evaluación?</label>
        <select id="ic-impacto" name="impacto">
          <option value="">Elige una opción</option>
          ${Object.values(IMPACTO).map((i) => html`<option value="${i}">${etiqueta(IMPACTO_ETIQUETA, i)}</option>`)}
        </select>
      </div>

      <div class="ic-duplicado" data-duplicado role="alert" hidden></div>
      <p class="alerta alerta--error" role="alert" data-formulario-error hidden></p>
      <button type="submit" class="btn">${aNombre ? 'Registrar incidencia' : 'Enviar reporte'}</button>
    </form>`;
}