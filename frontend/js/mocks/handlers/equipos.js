// frontend/js/mocks/handlers/equipos.js
// =====================================================================
//  Mock de backend/modulos/equipos y backend/modulos/catalogos
//  (sedes y ubicaciones viven en catalogos, pero las usa el módulo equipos,
//  así que se simulan juntas aquí). Contrato: docs/api/equipos.md y
//  docs/api/catalogos.md.
//
//  Datos: bd.sedes, bd.ubicaciones, bd.equipos, y para lo que en el backend
//  real sale de incidencias (todavía sin mock propio): bd.historialEquipos y
//  bd.incidenciasAbiertas. Todos se siembran en semilla.js.
// =====================================================================
import { PERMISO, permisosDe } from '../../nucleo/dominio.js';
import { Errores, creado } from '../servidor.js';

const P = PERMISO;
const POR_PAGINA = 25;
const MAX_PAGINA = 10000;
const MAX_CODIGO = 50;
const MAX_MOTIVO = 300;
const MAX_UBICACION = 80;
const TIPOS_EQUIPO = ['computadora', 'impresora', 'proyector', 'dispositivo_red', 'equipo_laboratorio'];
const CRITICIDADES = ['alta', 'media', 'baja'];
const UBICACION_REPETIDA = 'Ya existe una ubicación con ese nombre en esa sede.';
const CODIGO_REPETIDO = 'Ya existe un equipo con ese código.';

// ---------- Utilidades ----------
const ordenar = (a, b) => a.localeCompare(b, 'es', { numeric: true });
const porNombre = (a, b) => a.nombre.localeCompare(b.nombre, 'es');
const espacios = (t) => String(t ?? '').trim().replace(/\s+/g, ' ');
const vacio = (v) => v === undefined || v === null || (typeof v === 'string' && v.trim() === '');
// Código de equipo: mayúsculas y espacios a guion (Servicio::normalizarCodigo del backend).
const normalizarCodigo = (t) => String(t ?? '').trim().replace(/\s+/g, '-').toUpperCase();

// Lectores con las mismas reglas y mensajes que nucleo/Validar.php. Cada uno anota el
// error en `campos` y devuelve el valor (o null si falta o no es válido).
function lector(origen) {
  const campos = {};
  return {
    campos,
    entero(campo, obligatorio = false) {
      const v = origen[campo];
      if (vacio(v)) { if (obligatorio) campos[campo] = 'Obligatorio.'; return null; }
      if (Number.isInteger(v) || (typeof v === 'string' && /^-?\d{1,18}$/.test(v))) return Number(v);
      campos[campo] = 'Debe ser un número entero.';
      return null;
    },
    enumerado(campo, validos, obligatorio = false) {
      const v = origen[campo];
      if (vacio(v)) { if (obligatorio) campos[campo] = 'Obligatorio.'; return null; }
      if (!validos.includes(v)) { campos[campo] = 'Valor no permitido.'; return null; }
      return v;
    },
    texto(campo, obligatorio = false, max = 255) {
      const v = origen[campo];
      if (vacio(v)) { if (obligatorio) campos[campo] = 'Obligatorio.'; return null; }
      if (typeof v !== 'string') { campos[campo] = 'Debe ser texto.'; return null; }
      const limpio = v.trim();
      if (limpio.length > max) { campos[campo] = `Máximo ${max} caracteres.`; return null; }
      return limpio;
    },
    pagina() {
      const bruto = origen.pagina;
      if (vacio(bruto)) return 1;
      const p = Number(bruto);
      if (!Number.isInteger(p) || p < 1 || p > MAX_PAGINA) { campos.pagina = `Debe estar entre 1 y ${MAX_PAGINA}.`; return 1; }
      return p;
    },
    exigir() { if (Object.keys(campos).length) throw Errores.invalido(campos); },
  };
}

const respuestaPaginada = (filas, pagina, mapear) => ({
  items: filas.slice((pagina - 1) * POR_PAGINA, pagina * POR_PAGINA).map(mapear),
  total: filas.length,
  pagina,
  por_pagina: POR_PAGINA,
});

// ---------- Alcance (Servicio::alcance): qué ve cada quien ----------
// equipo.gestionar → equipo completo; solo equipo.elegir → código, tipo y ubicación (y solo activos);
// ninguno de los dos → 403. Sin sesión simulada (actor null) se ve todo.
function alcance(actor) {
  if (!actor) return 'completo';
  const permisos = permisosDe(actor.rol);
  if (permisos.includes(P.EQUIPO_GESTIONAR)) return 'completo';
  if (permisos.includes(P.EQUIPO_ELEGIR)) return 'basico';
  throw Errores.prohibido();
}

// ---------- Serializadores (la forma exacta que ve el frontend) ----------
const ubicacionAApi = (u) => ({ id: u.id, sede_id: u.sede_id, nombre: u.nombre });

const nombreUbicacion = (bd, id) => bd.ubicaciones.find((u) => u.id === id)?.nombre ?? '';

const equipoBasico = (bd, e) => ({
  id: e.id, codigo: e.codigo, tipo: e.tipo, ubicacion_id: e.ubicacion_id, ubicacion: nombreUbicacion(bd, e.ubicacion_id),
});
const equipoCompleto = (bd, e) => ({
  ...equipoBasico(bd, e), sede_id: e.sede_id, criticidad: e.criticidad, activo: e.activo,
});

// ---------- Búsquedas ----------
function buscarEquipo(bd, id) {
  const e = /^\d+$/.test(id) ? bd.equipos.find((x) => x.id === Number(id)) : undefined;
  if (!e) throw Errores.noEncontrado('No se encontró el equipo.');
  return e;
}

function buscarUbicacion(bd, id) {
  const u = /^\d+$/.test(id) ? bd.ubicaciones.find((x) => x.id === Number(id)) : undefined;
  if (!u) throw Errores.noEncontrado('No se encontró la ubicación.');
  return u;
}

const siguienteId = (lista) => lista.reduce((m, x) => Math.max(m, x.id), 0) + 1;

// Dentro de la sede, "Lab 2" y "lab 2" son la misma ubicación.
const nombreOcupado = (bd, sedeId, nombre, exceptoId) => bd.ubicaciones.some(
  (u) => u.sede_id === sedeId && u.id !== exceptoId && u.nombre.toLowerCase() === nombre.toLowerCase(),
);

// ---------- Catálogos: sedes y ubicaciones ----------
const sedes = ({ bd }) => bd.sedes.map((s) => ({ ...s })).sort(porNombre);

function ubicaciones({ query, actor, bd }) {
  const v = lector(query);
  const sede = v.entero('sede');
  v.exigir();
  alcance(actor);
  return bd.ubicaciones
    .filter((u) => sede === null || u.sede_id === sede)
    .sort((a, b) => porNombre(a, b) || a.id - b.id)
    .map(ubicacionAApi);
}

function crearUbicacion({ cuerpo, clave, actor, bd }) {
  // Reintento con la misma Idempotency-Key: la misma ubicación, sin duplicar.
  const llave = `${actor?.id ?? 0}:ubicacion.crear:${clave}`;
  if (clave && bd.idempotencia[llave] !== undefined) {
    return creado(ubicacionAApi(buscarUbicacion(bd, String(bd.idempotencia[llave]))));
  }

  const v = lector(cuerpo);
  const sedeId = v.entero('sede_id', true);
  const nombre = v.texto('nombre', true, MAX_UBICACION);
  v.exigir();

  const limpio = espacios(nombre);
  if (!bd.sedes.some((s) => s.id === sedeId)) throw Errores.invalido({ sede_id: 'La sede no existe.' });
  if (nombreOcupado(bd, sedeId, limpio, null)) throw Errores.invalido({ nombre: UBICACION_REPETIDA });

  const nueva = { id: siguienteId(bd.ubicaciones), sede_id: sedeId, nombre: limpio };
  bd.ubicaciones.push(nueva);
  if (clave) bd.idempotencia[llave] = nueva.id;
  return creado(ubicacionAApi(nueva));
}

function renombrarUbicacion({ params, cuerpo, bd }) {
  const v = lector(cuerpo);
  const nombre = v.texto('nombre', true, MAX_UBICACION);
  v.exigir();

  const actual = buscarUbicacion(bd, params.id);
  const limpio = espacios(nombre);
  if (actual.nombre === limpio) return ubicacionAApi(actual);
  if (nombreOcupado(bd, actual.sede_id, limpio, actual.id)) throw Errores.invalido({ nombre: UBICACION_REPETIDA });
  actual.nombre = limpio;
  return ubicacionAApi(actual);
}

// ---------- Equipos: lectura ----------
function listar({ query, actor, bd }) {
  const v = lector(query);
  const sede = v.entero('sede');
  const ubicacion = v.entero('ubicacion');
  const tipo = v.enumerado('tipo', TIPOS_EQUIPO);
  const q = v.texto('q', false, 50);
  const activo = v.enumerado('activo', ['true', 'false']);
  const pagina = v.pagina();
  v.exigir();

  const completo = alcance(actor) === 'completo';
  const texto = q === null ? null : normalizarCodigo(q);
  const filtrados = bd.equipos
    .filter((e) => sede === null || e.sede_id === sede)
    .filter((e) => ubicacion === null || e.ubicacion_id === ubicacion)
    .filter((e) => tipo === null || e.tipo === tipo)
    .filter((e) => texto === null || e.codigo.includes(texto))
    // Quien solo elige equipo no ve los dados de baja: un equipo de baja no se elige.
    .filter((e) => (completo ? (activo === null || String(e.activo) === activo) : e.activo))
    .sort((a, b) => ordenar(a.codigo, b.codigo));
  const mapear = (e) => (completo ? equipoCompleto(bd, e) : equipoBasico(bd, e));

  // Con ubicación elegida (selector en cascada) se devuelven todos, sin paginar.
  if (ubicacion !== null) return { items: filtrados.map(mapear), total: filtrados.length };
  return respuestaPaginada(filtrados, pagina, mapear);
}

const obtener = ({ params, bd }) => equipoCompleto(bd, buscarEquipo(bd, params.id));

function historial({ params, query, bd }) {
  const v = lector(query);
  const pagina = v.pagina();
  v.exigir();

  const equipo = buscarEquipo(bd, params.id);
  // La más reciente primero. Un equipo dado de baja también tiene historial.
  const filas = bd.historialEquipos
    .filter((h) => h.equipo_id === equipo.id)
    .sort((a, b) => Date.parse(b.fecha) - Date.parse(a.fecha));
  return respuestaPaginada(filas, pagina, (h) => ({
    fecha: h.fecha, falla: h.falla, solucion: h.solucion ?? null, tecnico: h.tecnico ?? null,
  }));
}

// Dato derivado: un equipo ACTIVO con al menos una incidencia abierta; el que lleva más tiempo, primero.
function fueraDeServicio({ query, bd }) {
  const v = lector(query);
  const sede = v.entero('sede');
  const ubicacion = v.entero('ubicacion');
  const tipo = v.enumerado('tipo', TIPOS_EQUIPO);
  const pagina = v.pagina();
  v.exigir();

  const filas = bd.equipos
    .filter((e) => e.activo)
    .filter((e) => sede === null || e.sede_id === sede)
    .filter((e) => ubicacion === null || e.ubicacion_id === ubicacion)
    .filter((e) => tipo === null || e.tipo === tipo)
    .map((e) => {
      const abiertas = bd.incidenciasAbiertas.filter((i) => i.equipo_id === e.id);
      const desde = abiertas.map((i) => i.desde).sort((a, b) => Date.parse(a) - Date.parse(b))[0];
      return { e, abiertas: abiertas.length, desde };
    })
    .filter((f) => f.abiertas > 0)
    .sort((a, b) => Date.parse(a.desde) - Date.parse(b.desde) || ordenar(a.e.codigo, b.e.codigo));

  return respuestaPaginada(filas, pagina, (f) => ({
    ...equipoCompleto(bd, f.e), incidencias_abiertas: f.abiertas, desde: f.desde,
  }));
}

// ---------- Equipos: escritura ----------
function crear({ cuerpo, clave, actor, bd }) {
  // Reintento con la misma Idempotency-Key: el mismo equipo, sin duplicar.
  const llave = `${actor?.id ?? 0}:equipo.crear:${clave}`;
  if (clave && bd.idempotencia[llave] !== undefined) {
    return creado(equipoCompleto(bd, buscarEquipo(bd, String(bd.idempotencia[llave]))));
  }

  const v = lector(cuerpo);
  const codigoBruto = v.texto('codigo', true, MAX_CODIGO);
  const tipo = v.enumerado('tipo', TIPOS_EQUIPO, true);
  const ubicacionId = v.entero('ubicacion_id', true);
  const criticidad = v.enumerado('criticidad', CRITICIDADES, true);
  v.exigir();

  const codigo = normalizarCodigo(codigoBruto);
  const ubicacion = bd.ubicaciones.find((u) => u.id === ubicacionId);
  if (!ubicacion) throw Errores.invalido({ ubicacion_id: 'La ubicación no existe.' });
  if (bd.equipos.some((e) => e.codigo === codigo)) throw Errores.invalido({ codigo: CODIGO_REPETIDO });

  const nuevo = {
    id: siguienteId(bd.equipos), codigo, tipo, ubicacion_id: ubicacionId,
    sede_id: ubicacion.sede_id,   // la sede es la de la ubicación elegida
    criticidad, activo: true,
  };
  bd.equipos.push(nuevo);
  if (clave) bd.idempotencia[llave] = nuevo.id;
  return creado(equipoCompleto(bd, nuevo));
}

// Edita tipo, ubicación y criticidad. El código no se edita: si llega, debe ser el actual.
function editar({ params, cuerpo, bd }) {
  const v = lector(cuerpo);
  const tipo = v.enumerado('tipo', TIPOS_EQUIPO);
  const ubicacionId = v.entero('ubicacion_id');
  const criticidad = v.enumerado('criticidad', CRITICIDADES);
  const codigo = v.texto('codigo', false, MAX_CODIGO);
  v.exigir();
  if (tipo === null && ubicacionId === null && criticidad === null) {
    throw Errores.invalido({}, 'Indica qué cambiar: tipo, ubicacion_id o criticidad.');
  }

  const e = buscarEquipo(bd, params.id);
  if (codigo !== null && normalizarCodigo(codigo) !== e.codigo) throw Errores.invalido({ codigo: 'El código no se puede cambiar.' });

  let nuevaUbicacion = null;
  if (ubicacionId !== null && ubicacionId !== e.ubicacion_id) {
    nuevaUbicacion = bd.ubicaciones.find((u) => u.id === ubicacionId);
    if (!nuevaUbicacion) throw Errores.invalido({ ubicacion_id: 'La ubicación no existe.' });
  }

  if (tipo !== null) e.tipo = tipo;
  if (criticidad !== null) e.criticidad = criticidad;
  if (nuevaUbicacion) { e.ubicacion_id = nuevaUbicacion.id; e.sede_id = nuevaUbicacion.sede_id; }   // la sede sigue a la ubicación
  return equipoCompleto(bd, e);
}

// Baja (RN-07: no se borra). El motivo es obligatorio. 409 transicion_invalida si ya estaba de baja.
function darDeBaja({ params, cuerpo, bd }) {
  const v = lector(cuerpo);
  v.texto('motivo', true, MAX_MOTIVO);
  v.exigir();

  const e = buscarEquipo(bd, params.id);
  if (!e.activo) throw Errores.conflicto('transicion_invalida', 'El equipo ya estaba dado de baja.', equipoCompleto(bd, e));
  e.activo = false;
  return equipoCompleto(bd, e);
}

// Reactivación: el motivo es opcional. 409 transicion_invalida si ya estaba activo.
function reactivar({ params, cuerpo, bd }) {
  const v = lector(cuerpo);
  v.texto('motivo', false, MAX_MOTIVO);
  v.exigir();

  const e = buscarEquipo(bd, params.id);
  if (e.activo) throw Errores.conflicto('transicion_invalida', 'El equipo ya estaba activo.', equipoCompleto(bd, e));
  e.activo = true;
  return equipoCompleto(bd, e);
}

export default {
  id: 'equipos',
  rutas: [
    // ----- catalogos -----
    // Todos los roles ven todas las sedes (docs/api/catalogos.md → GET /sedes).
    { metodo: 'GET', ruta: '/sedes', permiso: null, atender: sedes },
    // Solo pide sesión: el Servicio exige equipo.gestionar o equipo.elegir (403 si no tiene ninguno).
    { metodo: 'GET', ruta: '/ubicaciones', permiso: null, atender: ubicaciones },
    { metodo: 'POST', ruta: '/ubicaciones', permiso: P.EQUIPO_GESTIONAR, atender: crearUbicacion },
    { metodo: 'PATCH', ruta: '/ubicaciones/:id', permiso: P.EQUIPO_GESTIONAR, atender: renombrarUbicacion },

    // ----- equipos -----
    { metodo: 'GET', ruta: '/equipos', permiso: null, atender: listar },
    // Las rutas fijas ganan a /equipos/:id (servidor.js las ordena primero).
    { metodo: 'GET', ruta: '/equipos/fuera-de-servicio', permiso: P.EQUIPO_HISTORIAL, atender: fueraDeServicio },
    { metodo: 'GET', ruta: '/equipos/:id/historial', permiso: P.EQUIPO_HISTORIAL, atender: historial },
    { metodo: 'GET', ruta: '/equipos/:id', permiso: P.EQUIPO_GESTIONAR, atender: obtener },
    { metodo: 'POST', ruta: '/equipos', permiso: P.EQUIPO_GESTIONAR, atender: crear },
    { metodo: 'PATCH', ruta: '/equipos/:id', permiso: P.EQUIPO_GESTIONAR, atender: editar },
    { metodo: 'POST', ruta: '/equipos/:id/baja', permiso: P.EQUIPO_DAR_DE_BAJA, atender: darDeBaja },
    { metodo: 'POST', ruta: '/equipos/:id/reactivar', permiso: P.EQUIPO_DAR_DE_BAJA, atender: reactivar },
  ],
};