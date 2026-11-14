// frontend/js/modulos/indicadores/indicadores.js
// =====================================================================
//  Módulo "indicadores" (RF-11, RN-10). Contrato: docs/api/indicadores.md
//  Funcionalidad: docs/funcionalidades/indicadores.md
//
//  Reglas que respeta esta pantalla:
//   · Las cifras se muestran tal cual las entrega el servidor (RN-10).
//   · Toda consulta muestra su corte y si el mes está cerrado o en curso.
//   · Sin datos NO es cero: se muestra «Sin datos».
//   · La sede es un filtro (no un permiso). Por defecto: mes en curso y todas.
//   · Fechas y mes en curso con hora de Lima.
// =====================================================================
import { PERMISO, ESTADO_ETIQUETA } from '../../nucleo/dominio.js';
import { html, pintarCargando } from '../../nucleo/ui.js';
import { indicadoresApi } from './indicadores.api.js';

const ZONA = 'America/Lima';
const MESES_ATRAS = 12;

export default {
  id: 'indicadores',                // = nombre de la carpeta (en minúscula)
  titulo: 'Indicadores',
  permiso: PERMISO.INDICADORES_VER, // solo jefe de soporte y jefe de informática (RNF-01)
  menu: { etiqueta: 'Indicadores', ruta: '/indicadores', orden: 80 },
  rutas: [
    { ruta: '/indicadores', vista: consulta },
  ],
};

// ---------- utilidades de fecha (hora de Lima) ----------

// 'AAAA-MM' del mes en curso en Lima.
function mesActual() {
  const partes = new Intl.DateTimeFormat('en-CA', { timeZone: ZONA, year: 'numeric', month: '2-digit' })
    .formatToParts(new Date());
  const anio = partes.find((p) => p.type === 'year').value;
  const mes = partes.find((p) => p.type === 'month').value;
  return `${anio}-${mes}`;
}

// Últimos N meses, del más reciente al más antiguo, a partir de 'AAAA-MM'.
function listaMeses(desde, cantidad) {
  let [anio, mes] = desde.split('-').map(Number);
  const meses = [];
  for (let i = 0; i < cantidad; i += 1) {
    meses.push(`${anio}-${String(mes).padStart(2, '0')}`);
    mes -= 1;
    if (mes === 0) { mes = 12; anio -= 1; }
  }
  return meses;
}

function nombreMes(aaaamm) {
  const [anio, mes] = aaaamm.split('-').map(Number);
  const texto = new Intl.DateTimeFormat('es-PE', { month: 'long', year: 'numeric', timeZone: 'UTC' })
    .format(new Date(Date.UTC(anio, mes - 1, 1)));
  return texto.charAt(0).toUpperCase() + texto.slice(1);
}

function formatoCorte(iso) {
  return new Intl.DateTimeFormat('es-PE', { dateStyle: 'long', timeStyle: 'short', timeZone: ZONA })
    .format(new Date(iso));
}

const esNumero = (v) => typeof v === 'number' && Number.isFinite(v);

// ---------- vista ----------

async function consulta(el, { query, ir }) {
  pintarCargando(el);

  const actual = mesActual();
  const meses = listaMeses(actual, MESES_ATRAS);

  // La URL (#/indicadores?mes=2026-10&sede=1) llega como URLSearchParams: se lee con .get().
  const pedido = query.get('mes') || '';
  let mes = /^\d{4}-(0[1-9]|1[0-2])$/.test(pedido) ? pedido : actual;
  if (mes > actual) mes = actual;               // un mes futuro no existe: se vuelve al actual
  if (!meses.includes(mes)) meses.push(mes);    // un mes antiguo pedido por enlace sigue siendo válido
  meses.sort().reverse();
  const sedeId = /^\d+$/.test(query.get('sede') || '') ? query.get('sede') : '';

  const [anio, nMes] = mes.split('-').map(Number);
  const [sedes, d] = await Promise.all([
    indicadoresApi.sedes(),
    indicadoresApi.consultar({ anio, mes: nMes, sede: sedeId }),
  ]);

  el.innerHTML = html`
    <h1>Indicadores</h1>

    <div class="ix-filtros">
      <label class="ix-campo">Mes
        <select id="ix-mes">
          ${meses.map((m) => html`<option value="${m}">${nombreMes(m)}</option>`)}
        </select>
      </label>
      <label class="ix-campo">Sede
        <select id="ix-sede">
          <option value="">Todas</option>
          ${sedes.map((s) => html`<option value="${s.id}">${s.nombre}</option>`)}
        </select>
      </label>
      <button type="button" class="btn" id="ix-imprimir">Imprimir / guardar reporte</button>
    </div>

    <div class="ix-corte">
      <span>Cifras al corte: <strong>${formatoCorte(d.corte)}</strong> (hora de Lima)</span>
      <span class="ix-sello ${d.cerrado ? 'ix-sello--cerrado' : ''}">${d.cerrado ? 'Mes cerrado' : 'Mes en curso'}</span>
    </div>

    <div class="ix-tarjetas">
      ${tarjetaTiempo(d)}
      ${tarjetaPendientes(d)}
      ${tarjetaFallas(d)}
      ${tarjetaRepuestos(d)}
    </div>`;

  // La plantilla html`` no admite huecos sueltos dentro de una etiqueta (<option ${x}>),
  // así que la opción elegida se marca aquí, con .value.
  el.querySelector('#ix-mes').value = mes;
  el.querySelector('#ix-sede').value = sedeId;

  // Cambiar de mes o de sede = otra consulta (la URL queda compartible).
  const cambiar = () => {
    const params = new URLSearchParams();
    params.set('mes', el.querySelector('#ix-mes').value);
    const sede = el.querySelector('#ix-sede').value;
    if (sede) params.set('sede', sede);
    ir(`/indicadores?${params.toString()}`);
  };
  el.querySelector('#ix-mes').addEventListener('change', cambiar);
  el.querySelector('#ix-sede').addEventListener('change', cambiar);
  el.querySelector('#ix-imprimir').addEventListener('click', () => window.print());
}

// ---------- tarjetas (la forma de los datos es la de docs/api/indicadores.md) ----------

function tarjetaTiempo(d) {
  const t = d.tiempo_atencion || {};
  return html`
    <section class="ix-tarjeta">
      <h2>Tiempo de atención</h2>
      ${esNumero(t.promedio_horas)
        ? html`<p class="ix-cifra">${t.promedio_horas} <span class="ix-unidad">horas</span></p>
               <p class="ix-nota">Promedio en tiempo corrido, primera resolución${esNumero(t.muestras) ? ` · ${t.muestras} incidencias` : ''}.</p>`
        : html`<p class="ix-sin-datos">Sin datos</p>
               <p class="ix-nota">No hubo incidencias resueltas en este mes.</p>`}
    </section>`;
}

function tarjetaPendientes(d) {
  const p = d.pendientes || {};
  const estados = Object.entries(p.por_estado || {});
  return html`
    <section class="ix-tarjeta">
      <h2>Pendientes</h2>
      <p class="ix-cifra">${p.total ?? 0}</p>
      <p class="ix-nota">Incidencias que no están Resueltas ni Cerradas.</p>
      ${estados.length === 0 ? '' : html`
        <ul class="ix-lista">
          ${estados.map(([estado, n]) => html`<li>${ESTADO_ETIQUETA[estado] ?? estado}: ${n}</li>`)}
        </ul>`}
    </section>`;
}

function tarjetaFallas(d) {
  const equipos = d.fallas_repetidas || [];
  return html`
    <section class="ix-tarjeta">
      <h2>Equipos con fallas repetidas</h2>
      <p class="ix-cifra">${equipos.length}</p>
      <p class="ix-nota">3 o más incidencias en los 90 días anteriores al corte.</p>
      ${equipos.length === 0 ? '' : html`
        <ul class="ix-lista">
          ${equipos.map((e) => html`<li>${e.codigo || e.equipo_id} — ${e.incidencias} incidencias</li>`)}
        </ul>`}
    </section>`;
}

function tarjetaRepuestos(d) {
  const r = d.repuestos || {};
  const items = r.items || [];
  return html`
    <section class="ix-tarjeta">
      <h2>Repuestos usados</h2>
      <p class="ix-cifra">${r.atenciones_con_repuestos ?? 0} <span class="ix-unidad">atenciones con repuestos</span></p>
      ${items.length === 0 ? html`<p class="ix-nota">No se registraron repuestos.</p>` : html`
        <ul class="ix-lista">
          ${items.map((i) => html`<li>${i.nombre}: ${i.cantidad}</li>`)}
        </ul>`}
    </section>`;
}