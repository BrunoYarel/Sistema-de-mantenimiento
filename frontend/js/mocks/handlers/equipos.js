// frontend/js/mocks/handlers/equipos.js
// =====================================================================
//  Mock de backend/modulos/equipos y de las rutas de catalogos que usan los selects en cascada.
//  Contrato: docs/api/equipos.md y docs/api/catalogos.md.
//  Simula: GET /sedes · GET /ubicaciones · GET /equipos (lista; con `ubicacion` devuelve todos, sin paginar).
// =====================================================================
import { PERMISO, permisosDe } from '../../nucleo/dominio.js';
import { Errores } from '../servidor.js';

const POR_PAGINA = 25;
const porNombre = (a, b) => a.nombre.localeCompare(b.nombre, 'es');
const porCodigo = (a, b) => a.codigo.localeCompare(b.codigo, 'es');
const entero = (v) => /^\d{1,9}$/.test(String(v));

// equipo.gestionar → completo · solo equipo.elegir → básico · ninguno → 403 (como el Servicio real).
function alcance(actor) {
  const permisos = permisosDe(actor?.rol);
  if (permisos.includes(PERMISO.EQUIPO_GESTIONAR)) return 'completo';
  if (permisos.includes(PERMISO.EQUIPO_ELEGIR)) return 'basico';
  throw Errores.prohibido('prohibido', 'No tienes permiso para esta acción.');
}

function ubicaciones({ query, actor, bd }) {
  alcance(actor);
  if (query.sede && !entero(query.sede)) throw Errores.invalido({ sede: 'Debe ser un número entero.' });
  return bd.ubicaciones
    .filter((u) => !query.sede || u.sede_id === Number(query.sede))
    .sort(porNombre)
    .map((u) => ({ ...u }));
}

function equipos({ query, actor, bd }) {
  const completo = alcance(actor) === 'completo';
  const campos = {};
  if (query.sede && !entero(query.sede)) campos.sede = 'Debe ser un número entero.';
  if (query.ubicacion && !entero(query.ubicacion)) campos.ubicacion = 'Debe ser un número entero.';
  if (Object.keys(campos).length) throw Errores.invalido(campos);

  const q = String(query.q ?? '').trim().replace(/\s+/g, '-').toUpperCase();
  const nombreUbicacion = (id) => bd.ubicaciones.find((u) => u.id === id)?.nombre ?? '';

  const filtrados = bd.equipos
    .filter((e) => completo || e.activo)                       // quien solo elige no ve los dados de baja
    .filter((e) => !query.sede || e.sede_id === Number(query.sede))
    .filter((e) => !query.ubicacion || e.ubicacion_id === Number(query.ubicacion))
    .filter((e) => !query.tipo || e.tipo === query.tipo)
    .filter((e) => !q || e.codigo.includes(q))
    .sort(porCodigo);

  const aApi = (e) => {
    const basico = { id: e.id, codigo: e.codigo, tipo: e.tipo, ubicacion_id: e.ubicacion_id, ubicacion: nombreUbicacion(e.ubicacion_id) };
    return completo ? { ...basico, sede_id: e.sede_id, criticidad: e.criticidad, activo: e.activo } : basico;
  };

  if (query.ubicacion) return { items: filtrados.map(aApi), total: filtrados.length };

  const pagina = Number(query.pagina ?? 1);
  if (!Number.isInteger(pagina) || pagina < 1 || pagina > 10000) throw Errores.invalido({ pagina: 'Debe estar entre 1 y 10000.' });
  return {
    items: filtrados.slice((pagina - 1) * POR_PAGINA, pagina * POR_PAGINA).map(aApi),
    total: filtrados.length,
    pagina,
    por_pagina: POR_PAGINA,
  };
}

export default {
  id: 'equipos',
  rutas: [
    // Todos los roles ven todas las sedes (docs/api/catalogos.md → GET /sedes).
    { metodo: 'GET', ruta: '/sedes', permiso: null, atender: ({ bd }) => bd.sedes.map((s) => ({ ...s })).sort(porNombre) },
    { metodo: 'GET', ruta: '/ubicaciones', permiso: null, atender: ubicaciones },
    { metodo: 'GET', ruta: '/equipos', permiso: null, atender: equipos },
  ],
};