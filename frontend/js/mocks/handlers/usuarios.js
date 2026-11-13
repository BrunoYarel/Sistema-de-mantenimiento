// frontend/js/mocks/handlers/usuarios.js
// =====================================================================
//  Mock de backend/modulos/usuarios. Contrato: docs/api/usuarios.md (RF-12).
//  Mismos permisos, validaciones y errores que el contrato. No se simula RN-02
//  (técnico con incidencias abiertas) porque todavía no hay mock de incidencias.
// =====================================================================
import { PERMISO, ROL, permisosDe } from '../../nucleo/dominio.js';
import { Errores, creado } from '../servidor.js';

const P = PERMISO;
const ROLES = Object.values(ROL);
const POR_PAGINA = 25;
const MAX_NOMBRE = 100;
const FORMATO_USUARIO = /^[a-z0-9][a-z0-9._@-]{2,59}$/;
const MENSAJE_USUARIO = 'De 3 a 60 caracteres: letras sin tildes, números, punto, guion, guion bajo o @.';
const ALFABETO = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

// ---------- Utilidades ----------
const publico = ({ clave, ...resto }) => resto;   // la clave NUNCA sale en una respuesta
const limpiarNombre = (t) => String(t ?? '').trim().replace(/\s+/g, ' ');
const sinTildes = (t) => String(t).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
const claveAlAzar = () => Array.from({ length: 14 }, () => ALFABETO[Math.floor(Math.random() * ALFABETO.length)]).join('');
const porNombre = (a, b) => a.nombre.localeCompare(b.nombre, 'es');

const gestiona = (rol) => permisosDe(rol).includes(P.USUARIO_GESTIONAR);
const rolesCon = (permiso) => ROLES.filter((r) => permisosDe(r).includes(permiso));

function buscar(bd, id) {
  const u = /^\d+$/.test(id) ? bd.usuarios.find((x) => x.id === Number(id)) : undefined;
  if (!u) throw Errores.noEncontrado('No se encontró el usuario.');
  return u;
}

// Nunca se queda el sistema sin quien pueda gestionar usuarios (jefe de informática activo).
const otrosGestoresActivos = (bd, id) => bd.usuarios.some((x) => x.activo && gestiona(x.rol) && x.id !== id);
const ultimoGestor = () => Errores.conflicto('ultimo_gestor', 'Es el último jefe de informática activo: sin él nadie podría gestionar usuarios.');

const sedeExiste = (bd, sedeId) => Number.isInteger(sedeId) && bd.sedes.some((s) => s.id === sedeId);

// ---------- Lecturas para incidencias ----------
const tecnicos = ({ bd }) => bd.usuarios
  .filter((u) => u.activo && rolesCon(P.INCIDENCIA_ATENDER).includes(u.rol))
  .sort(porNombre)
  .map(({ id, nombre, rol }) => ({ id, nombre, rol }));

function reportantes({ query, bd }) {
  const q = String(query.q ?? '').trim();
  if (q.length < 2 || q.length > 50) throw Errores.invalido({ q: 'Escribe de 2 a 50 caracteres.' });
  const texto = sinTildes(q);
  return bd.usuarios
    .filter((u) => u.activo && rolesCon(P.INCIDENCIA_REGISTRAR_PROPIA).includes(u.rol))
    .filter((u) => sinTildes(u.nombre).includes(texto) || u.usuario.includes(texto))
    .sort(porNombre)
    .slice(0, 20)
    .map(({ id, nombre, rol }) => ({ id, nombre, rol }));
}

// ---------- Gestión ----------
function listar({ query, bd }) {
  const { q = '', rol = '', sede = '', activo = '', pagina = '1' } = query;
  const campos = {};
  if (rol !== '' && !ROLES.includes(rol)) campos.rol = 'Valor no permitido.';
  if (activo !== '' && !['true', 'false'].includes(activo)) campos.activo = 'Debe ser true o false.';
  if (sede !== '' && !/^\d{1,9}$/.test(sede)) campos.sede = 'Debe ser un número entero.';
  const p = Number(pagina);
  if (!Number.isInteger(p) || p < 1 || p > 10000) campos.pagina = 'Debe estar entre 1 y 10000.';
  if (Object.keys(campos).length) throw Errores.invalido(campos);

  const texto = sinTildes(q.slice(0, 50));
  const filtrados = bd.usuarios
    .filter((u) => !texto || sinTildes(u.nombre).includes(texto) || u.usuario.includes(texto))
    .filter((u) => !rol || u.rol === rol)
    .filter((u) => !sede || u.sede_id === Number(sede))
    .filter((u) => !activo || String(u.activo) === activo)
    .sort(porNombre);

  return {
    items: filtrados.slice((p - 1) * POR_PAGINA, p * POR_PAGINA).map(publico),
    total: filtrados.length,
    pagina: p,
    por_pagina: POR_PAGINA,
  };
}

const obtener = ({ params, bd }) => publico(buscar(bd, params.id));

function crear({ cuerpo, clave, actor, bd }) {
  // Reintento con la misma Idempotency-Key: el mismo usuario, sin duplicar y sin clave.
  const llave = `${actor?.id ?? 0}:${clave}`;
  if (clave && bd.idempotencia[llave] !== undefined) {
    return creado({ ...publico(buscar(bd, String(bd.idempotencia[llave]))), clave_temporal: '' });
  }

  const usuario = String(cuerpo.usuario ?? '').trim().toLowerCase();
  const nombre = limpiarNombre(cuerpo.nombre);
  const sedeId = cuerpo.sede_id ?? null;
  const campos = {};
  if (!nombre) campos.nombre = 'Obligatorio.';
  else if (nombre.length > MAX_NOMBRE) campos.nombre = `Máximo ${MAX_NOMBRE} caracteres.`;
  if (!FORMATO_USUARIO.test(usuario)) campos.usuario = MENSAJE_USUARIO;
  else if (bd.usuarios.some((u) => u.usuario === usuario)) campos.usuario = 'Ya existe un usuario con ese nombre de usuario.';
  if (!ROLES.includes(cuerpo.rol)) campos.rol = 'Valor no permitido.';
  if (sedeId !== null && !sedeExiste(bd, sedeId)) campos.sede_id = 'La sede no existe.';
  if (Object.keys(campos).length) throw Errores.invalido(campos);

  const temporal = claveAlAzar();
  const nuevo = {
    id: bd.nuevoIdUsuario(), nombre, usuario, rol: cuerpo.rol, sede_id: sedeId,
    activo: true, debe_cambiar_clave: true, bloqueado: false, clave: temporal,
  };
  bd.usuarios.push(nuevo);
  if (clave) bd.idempotencia[llave] = nuevo.id;
  return creado({ ...publico(nuevo), clave_temporal: temporal });
}

function editar({ params, cuerpo, bd }) {
  const u = buscar(bd, params.id);
  const campos = {};
  const cambios = {};

  if (!['nombre', 'rol', 'sede_id'].some((k) => k in cuerpo)) throw Errores.invalido({}, 'No hay nada que cambiar.');
  if ('usuario' in cuerpo && String(cuerpo.usuario).toLowerCase() !== u.usuario) campos.usuario = 'El nombre de usuario no se puede cambiar.';
  if ('nombre' in cuerpo) {
    const n = limpiarNombre(cuerpo.nombre);
    if (!n) campos.nombre = 'Obligatorio.';
    else if (n.length > MAX_NOMBRE) campos.nombre = `Máximo ${MAX_NOMBRE} caracteres.`;
    else cambios.nombre = n;
  }
  if ('rol' in cuerpo) {
    if (!ROLES.includes(cuerpo.rol)) campos.rol = 'Valor no permitido.';
    else cambios.rol = cuerpo.rol;
  }
  if ('sede_id' in cuerpo) {
    if (cuerpo.sede_id !== null && !sedeExiste(bd, cuerpo.sede_id)) campos.sede_id = 'La sede no existe.';
    else cambios.sede_id = cuerpo.sede_id;
  }
  if (Object.keys(campos).length) throw Errores.invalido(campos);

  if (cambios.rol && cambios.rol !== u.rol && u.activo && gestiona(u.rol) && !gestiona(cambios.rol) && !otrosGestoresActivos(bd, u.id)) {
    throw ultimoGestor();
  }
  Object.assign(u, cambios);
  return publico(u);
}

function desactivar({ params, actor, bd }) {
  const u = buscar(bd, params.id);
  if (actor && u.id === actor.id) throw Errores.prohibido('no_puedes_desactivarte', 'No puedes desactivar tu propia cuenta.');
  if (!u.activo) throw Errores.conflicto('transicion_invalida', 'El usuario ya estaba desactivado.', publico(u));
  if (gestiona(u.rol) && !otrosGestoresActivos(bd, u.id)) throw ultimoGestor();
  u.activo = false;
  return publico(u);
}

function reactivar({ params, bd }) {
  const u = buscar(bd, params.id);
  if (u.activo) throw Errores.conflicto('transicion_invalida', 'El usuario ya estaba activo.', publico(u));
  u.activo = true;
  return publico(u);
}

// Otra clave temporal: la anterior deja de valer y la persona debe cambiarla al entrar.
function restablecerClave({ params, bd }) {
  const u = buscar(bd, params.id);
  u.clave = claveAlAzar();
  u.debe_cambiar_clave = true;
  return { ...publico(u), clave_temporal: u.clave };
}

function desbloquear({ params, bd }) {
  const u = buscar(bd, params.id);
  u.bloqueado = false;
  bd.fallos[u.usuario] = 0;
  return publico(u);
}

export default {
  id: 'usuarios',
  rutas: [
    { metodo: 'GET', ruta: '/usuarios/tecnicos', permiso: P.INCIDENCIA_ASIGNAR, atender: tecnicos },
    { metodo: 'GET', ruta: '/usuarios/reportantes', permiso: P.INCIDENCIA_REGISTRAR_A_NOMBRE, atender: reportantes },
    { metodo: 'GET', ruta: '/usuarios', permiso: P.USUARIO_GESTIONAR, atender: listar },
    { metodo: 'GET', ruta: '/usuarios/:id', permiso: P.USUARIO_GESTIONAR, atender: obtener },
    { metodo: 'POST', ruta: '/usuarios', permiso: P.USUARIO_GESTIONAR, atender: crear },
    { metodo: 'PATCH', ruta: '/usuarios/:id', permiso: P.USUARIO_GESTIONAR, atender: editar },
    { metodo: 'POST', ruta: '/usuarios/:id/desactivar', permiso: P.USUARIO_GESTIONAR, atender: desactivar },
    { metodo: 'POST', ruta: '/usuarios/:id/reactivar', permiso: P.USUARIO_GESTIONAR, atender: reactivar },
    { metodo: 'POST', ruta: '/usuarios/:id/restablecer-clave', permiso: P.USUARIO_GESTIONAR, atender: restablecerClave },
    { metodo: 'POST', ruta: '/usuarios/:id/desbloquear', permiso: P.USUARIO_GESTIONAR, atender: desbloquear },
  ],
};
