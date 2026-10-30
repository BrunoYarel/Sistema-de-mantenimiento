// frontend/js/mocks/handlers/auth.js
// =====================================================================
//  Mock de backend/modulos/auth. Contrato: docs/api/auth.md.
//  Id 'sesion' (es el que se pone en ?mocks=sesion,...).
//  Con este mock activo, toda la app exige sesión y permisos como el backend real.
//
//  Extra de comodidad: 5 intentos fallidos seguidos bloquean al usuario (429),
//  para probar esa pantalla; se libera desde Usuarios → Desbloquear.
// =====================================================================
import { CLAVE_MIN, permisosDe } from '../../nucleo/dominio.js';
import { ErrorServidor, Errores } from '../servidor.js';

const MAX_FALLOS = 5;
const ESPERA_BLOQUEO_S = 300;

// Misma forma que GET /me (docs/api/auth.md).
const datosSesion = (u) => ({
  id: u.id,
  nombre: u.nombre,
  rol: u.rol,
  permisos: permisosDe(u.rol),
  debe_cambiar_clave: u.debe_cambiar_clave,
});

function login({ cuerpo, bd }) {
  const nombre = String(cuerpo.usuario ?? '').trim().toLowerCase();
  const clave = String(cuerpo.clave ?? '');
  const campos = {};
  if (!nombre) campos.usuario = 'Obligatorio.';
  if (!clave) campos.clave = 'Obligatoria.';
  if (Object.keys(campos).length) throw Errores.invalido(campos);

  const u = bd.usuarios.find((x) => x.usuario === nombre);
  if (u?.bloqueado) {
    throw Errores.demasiados(ESPERA_BLOQUEO_S, 'Demasiados intentos fallidos. Espera 5 minutos e inténtalo de nuevo.');
  }
  // Mismo mensaje exista o no el usuario, esté activo o no (no revela nada).
  if (!u || !u.activo || u.clave !== clave) {
    if (u?.activo) {
      bd.fallos[nombre] = (bd.fallos[nombre] ?? 0) + 1;
      if (bd.fallos[nombre] >= MAX_FALLOS) { u.bloqueado = true; bd.fallos[nombre] = 0; }
    }
    throw new ErrorServidor(401, 'credenciales_invalidas', 'Usuario o contraseña incorrectos.');
  }
  bd.fallos[nombre] = 0;
  bd.iniciarSesion(u.id);
  return datosSesion(u);
}

// Clave actual mala = 422 con campos.clave_actual, NUNCA 401 (docs/api/auth.md, B6).
function cambiarClave({ cuerpo, actor }) {
  const actual = String(cuerpo.clave_actual ?? '');
  const nueva = String(cuerpo.clave_nueva ?? '');
  if (actual !== actor.clave) throw Errores.invalido({ clave_actual: 'La clave actual no es correcta.' });
  if (nueva.length < CLAVE_MIN) throw Errores.invalido({ clave_nueva: `Debe tener al menos ${CLAVE_MIN} caracteres.` });
  if (nueva === actual) throw Errores.invalido({ clave_nueva: 'Debe ser distinta de la actual.' });
  actor.clave = nueva;
  actor.debe_cambiar_clave = false;
  return datosSesion(actor);
}

export default {
  id: 'sesion',
  rutas: [
    { metodo: 'GET', ruta: '/me', permiso: null, clavePendiente: true, atender: ({ actor }) => datosSesion(actor) },
    { metodo: 'POST', ruta: '/auth/login', publica: true, atender: login },
    { metodo: 'POST', ruta: '/auth/logout', publica: true, atender: ({ bd }) => { bd.cerrarSesion(); return null; } },
    { metodo: 'POST', ruta: '/auth/clave', permiso: null, clavePendiente: true, atender: cambiarClave },
  ],
};