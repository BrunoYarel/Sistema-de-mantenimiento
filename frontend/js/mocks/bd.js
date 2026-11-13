// frontend/js/mocks/bd.js
// =====================================================================
//  Base de datos de prueba: vive en memoria y se guarda en sessionStorage
//  (por pestaña), así los usuarios creados y la sesión sobreviven a recargar
//  la página. "Reiniciar datos" (panel) o cerrar la pestaña la devuelve a la
//  semilla. Nada de esto toca ni se parece al storage de la app real: todas
//  las claves empiezan con "mantenimiento:mock:".
//
//  ¿CUÁNDO TOCAS ESTE ARCHIVO? Solo si tu mock guarda datos propios (una lista
//  de equipos, de incidencias...). Entonces son DOS pasos, en este orden:
//    1. semilla.js: agrega tu colección con sus datos iniciales y sube VERSION_SEMILLA.
//    2. Aquí: agrega UN getter con el mismo nombre (busca «Datos por módulo», abajo).
//  Tu handler lo usa así:  atender: ({ bd }) => bd.equipos  (ver handlers/plantilla.js).
//  Lo demás del archivo (sesión, guardar, reiniciar) no se toca.
// =====================================================================
import { crearSemilla, VERSION_SEMILLA } from './semilla.js';

const CLAVE_BD = 'mantenimiento:mock:bd';
const CLAVE_SESION = 'mantenimiento:mock:sesion';

const almacen = {
  leer(k) { try { return sessionStorage.getItem(k); } catch { return null; } },
  poner(k, v) { try { sessionStorage.setItem(k, v); } catch { /* sin almacenamiento: los datos duran lo que la página */ } },
  quitar(k) { try { sessionStorage.removeItem(k); } catch { /* idem */ } },
};

function cargar() {
  try {
    const guardada = JSON.parse(almacen.leer(CLAVE_BD));
    if (guardada?.version === VERSION_SEMILLA) return guardada;
  } catch { /* nada guardado o dañado: se parte de la semilla */ }
  return crearSemilla();
}

export function crearBd() {
  let datos = cargar();

  const bd = {
    get sedes() { return datos.sedes; },
    get usuarios() { return datos.usuarios; },
    get plantilla() { return datos.plantilla; },

    // ----- Datos por módulo: UN getter por colección de semilla.js -----
    // Descomenta SOLO la línea de tu módulo (o agrega una nueva con la misma forma).
    // El nombre del getter y el de la colección en semilla.js son el MISMO texto.
    // Sin getter, bd.equipos es undefined y tu ruta responde 500 «Error en el mock».
    // Si tu módulo necesita más de una colección, agrega una línea por cada una.
    // get equipos() { return datos.equipos; },
    // get incidencias() { return datos.incidencias; },
    // get autorizaciones() { return datos.autorizaciones; },
    // get indicadores() { return datos.indicadores; },

    // ----- Datos de apoyo del sistema -----
    get idempotencia() { return datos.idempotencia; },
    get fallos() { return datos.fallos; },
    nuevoIdUsuario() { return datos.siguienteIdUsuario++; },

    // ----- Sesión (hace de cookie de sesión) -----
    get sesionId() {
      const v = almacen.leer(CLAVE_SESION);
      return v === null ? null : Number(v);
    },
    iniciarSesion(id) { almacen.poner(CLAVE_SESION, String(id)); },
    cerrarSesion() { almacen.quitar(CLAVE_SESION); },

    // El usuario de la sesión, leído de la "base" en cada petición (como el backend real):
    // si lo desactivaron, pierde la sesión; si le cambiaron el rol, rige desde ya.
    usuarioEnSesion() {
      const id = bd.sesionId;
      if (id === null) return null;
      const u = datos.usuarios.find((x) => x.id === id);
      if (!u || !u.activo) { bd.cerrarSesion(); return null; }
      return u;
    },

    guardar() { almacen.poner(CLAVE_BD, JSON.stringify(datos)); },
    reiniciar() { datos = crearSemilla(); bd.guardar(); },
  };
  return bd;
}