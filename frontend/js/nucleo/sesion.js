// frontend/js/nucleo/sesion.js
// =====================================================================
//  Sesión del usuario: quién es, qué rol tiene y qué puede hacer.
//     sesion.usuario            → { id, nombre, rol, permisos, debe_cambiar_clave } o null
//     sesion.puede(PERMISO.INCIDENCIA_ASIGNAR)
//  El frontend SOLO usa los permisos para mostrar u ocultar cosas.
//  Quien realmente decide es el backend.
//
//  Este archivo solo habla con el backend (/me, /auth/*). Para trabajar sin
//  backend no hay ramas aquí: js/mocks/ responde a esas mismas rutas.
// =====================================================================
import { http } from './http.js';
import { PERMISOS_VALIDOS } from './dominio.js';

// Prefijo de los borradores de formularios (los escribe formulario.js).
export const PREFIJO_BORRADOR = 'mantenimiento:borrador:';

let usuario = null;

// Deja la forma del usuario siempre igual.
function normalizar(datos) {
  if (!datos) return null;
  return { ...datos, permisos: Array.isArray(datos.permisos) ? datos.permisos : [], debe_cambiar_clave: Boolean(datos.debe_cambiar_clave) };
}

// Borra los borradores de formularios de esta pestaña (al cerrar sesión).
function olvidarBorradores() {
  try {
    Object.keys(sessionStorage).filter((k) => k.startsWith(PREFIJO_BORRADOR)).forEach((k) => sessionStorage.removeItem(k));
  } catch { /* sin almacenamiento */ }
}

export const sesion = {
  get usuario() { return usuario; },

  estaActiva() { return usuario !== null; },

  // ¿Puede el usuario hacer esto? permiso = null/undefined → cualquiera con sesión.
  // Un nombre que no está en el catálogo es un error de programación: se avisa y se niega.
  puede(permiso) {
    if (!usuario) return false;
    if (permiso === null || permiso === undefined) return true;
    if (!PERMISOS_VALIDOS.has(permiso)) {
      console.error(`sesion.puede(): el permiso "${permiso}" no está en PERMISO (nucleo/dominio.js).`);
      return false;
    }
    return usuario.permisos.includes(permiso);
  },

  // Al abrir la app: ¿ya hay una sesión iniciada? (GET /me)
  // Si no hay conexión lanza el error (app.js ofrece "Reintentar").
  async cargar() {
    try {
      usuario = normalizar(await http.get('/me'));
    } catch (err) {
      if (err.estado !== 401) throw err;
      usuario = null;
    }
    return usuario;
  },

  // Login. Credenciales incorrectas = ErrorApi 401; demasiados intentos = 429 (con esperaSegundos).
  async entrar({ usuario: nombre, clave }) {
    usuario = normalizar(await http.post('/auth/login', { usuario: nombre, clave }, { sinEventoSesion: true }));
    return usuario;
  },

  // Cambio de clave (voluntario o por debe_cambiar_clave). Los nombres son los del contrato
  // (docs/api/auth.md): clave_actual y clave_nueva, tanto en el envío como en los errores por campo.
  // Clave actual mala = 422 con campos.clave_actual, NUNCA 401 (el frontend lo tomaría por sesión vencida).
  async cambiarClave({ clave_actual, clave_nueva }) {
    await http.post('/auth/clave', { clave_actual, clave_nueva }, { sinEventoSesion: true });
    usuario = normalizar(await http.get('/me'));
    return usuario;
  },

  async salir() {
    try { await http.post('/auth/logout'); } catch { /* da igual: igual se sale */ }
    usuario = null;
    olvidarBorradores();
  },

  // Olvida al usuario sin avisar al backend (sesión expirada). Los borradores se conservan
  // para restaurarlos cuando vuelva a entrar.
  limpiar() {
    usuario = null;
  },
};
