// frontend/js/modulos/inicio/inicio.js
// =====================================================================
//  Inicio. Primera parte: elegir la sede de trabajo (D-A: la sede es un filtro, no un permiso).
//  Solo se pregunta a quien trabaja entre sedes: sede.todas (los jefes) o incidencia.atender
//  (los técnicos). Docente y administrativo entran directo al panel, sin sede: la sede de su
//  reporte se deduce del equipo. Todo se decide por permisos, no por el nombre del rol.
//    #/            → con 2 sedes disponibles: tarjetas para elegir.
//                    con 1 sola: entra directo, sin elegir.
//    #/?sede=ID    → panel de esa sede. Solo acepta una sede que el backend
//                    haya devuelto; cualquier otra → tarjetas.
//    #/?sede=todas → panel con las dos sedes juntas. Solo para quien tiene
//                    sede.todas (los jefes, que llevan la gestión centralizada);
//                    para los demás es una sede inválida → tarjetas.
//                    "todas" es solo del frontend: nunca se envía al backend.
//  Quien puede ver "todas" también tiene un filtro Todas / Sede 1 / Sede 2 en el panel.
//  La sede elegida vive en la dirección (nada de storage, F11) y NO se guarda
//  globalmente: cada pantalla trae su propio filtro de sede.
// =====================================================================
import { sesion } from '../../nucleo/sesion.js';
import { PERMISO } from '../../nucleo/dominio.js';
import { html, pintarCargando } from '../../nucleo/ui.js';
import { inicioApi } from './inicio.api.js';

// Opción "todas": no es una sede del backend, por eso su id es texto y nunca choca con los ids numéricos.
const TODAS = Object.freeze({ id: 'todas', nombre: 'Todas las sedes' });

export default {
  id: 'inicio',
  titulo: 'Inicio',
  permiso: null,                    // abierto a cualquier usuario con sesión (declararlo es obligatorio)
  menu: { etiqueta: 'Inicio', ruta: '/', orden: 0 },
  rutas: [
    { ruta: '/', vista },
  ],
};

// ¿Trabaja entre sedes? Solo a esa persona se le pregunta la sede.
const trabajaEntreSedes = () => sesion.puede(PERMISO.SEDE_TODAS) || sesion.puede(PERMISO.INCIDENCIA_ATENDER);

// ¿Ve la opción "Todas las sedes"? Con una sola sede no tiene sentido.
const puedeVerTodas = (sedes) => sedes.length > 1 && sesion.puede(PERMISO.SEDE_TODAS);

// Si falla el backend se lanza el error: el router muestra el mensaje con "Reintentar".
async function vista(el, { query }) {
  if (!trabajaEntreSedes()) return pintarPanel(el, null, [], false);   // docente, administrativo: sin sede y sin pedir /sedes
  pintarCargando(el);
  const sedes = await inicioApi.sedes();
  const conTodas = puedeVerTodas(sedes);

  const pedida = query.get('sede');
  const porId = sedes.find((s) => String(s.id) === pedida);
  const elegida = (conTodas && pedida === TODAS.id ? TODAS : porId) ?? (sedes.length === 1 ? sedes[0] : null);

  if (elegida) return pintarPanel(el, elegida, sedes, conTodas);
  return pintarSelector(el, sedes, conTodas, pedida !== null);
}

function pintarSelector(el, sedes, conTodas, sedeInvalida) {
  const opciones = conTodas ? [TODAS, ...sedes] : sedes;

  el.innerHTML = html`
    <div class="in-bienvenida">
      <h1>Hola, ${sesion.usuario.nombre}</h1>
      <p>Elige la sede en la que vas a trabajar.</p>
    </div>
    ${sedeInvalida ? html`<p class="alerta" role="alert">Esa sede no está disponible para tu usuario. Elige una de la lista.</p>` : ''}
    <ul class="in-sedes">
      ${opciones.map((s) => html`
        <li>
          <a class="in-sede" href="#/?sede=${s.id}">
            <span class="in-sede-nombre">${s.nombre}</span>
            <span class="in-sede-accion">${s.id === TODAS.id ? 'Ver ambas sedes' : 'Entrar a esta sede'}</span>
          </a>
        </li>`)}
    </ul>`;
}

// sede = null: quien no trabaja entre sedes (no hay sede que mostrar ni que cambiar).
function pintarPanel(el, sede, sedes, conTodas) {
  // Quien ve "todas" cambia de sede con el filtro; el resto, con el enlace de siempre.
  const filtro = conTodas ? html`
    <nav class="in-filtro" aria-label="Filtrar por sede">
      ${[TODAS, ...sedes].map((o) => html`
        <a class="in-filtro-opcion" href="#/?sede=${o.id}" aria-current="${o.id === sede.id ? 'page' : 'false'}">${o.nombre}</a>`)}
    </nav>` : '';

  el.innerHTML = html`
    <div class="in-bienvenida">
      <h1>Hola, ${sesion.usuario.nombre}</h1>
      ${sede ? html`<p class="in-sede-actual">Sede: <strong>${sede.nombre}</strong></p>` : ''}
      ${filtro}
      ${sede && !conTodas && sedes.length > 1 ? html`<p><a href="#/">Cambiar de sede</a></p>` : ''}
      <p>Aquí irá el resumen de cada rol (por ejemplo, tus incidencias asignadas).</p>
    </div>`;
}