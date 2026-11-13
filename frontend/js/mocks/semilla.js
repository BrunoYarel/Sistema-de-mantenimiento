// frontend/js/mocks/semilla.js
// =====================================================================
//  Datos iniciales de la base de prueba. Los seis primeros usuarios son los
//  mismos que crea el backend en local (bin/usuarios.php sembrar-dev), con la
//  misma clave: así el login funciona igual con mocks que con backend real.
//  El resto son personas para llenar la lista de usuarios y probar casos:
//    rdiaz     → desactivada (no puede entrar)
//    pcastro   → bloqueada por intentos (el login da 429; se libera en Usuarios)
//    npersona  → clave temporal (obliga a cambiar la clave al entrar)
//
//  Si cambias estos datos, sube VERSION_SEMILLA: así los navegadores que ya
//  guardaron la versión anterior arrancan con la nueva.
//
//  ¿CUÁNDO TOCAS ESTE ARCHIVO? Solo si tu mock necesita datos propios. Son DOS pasos:
//    1. Aquí: agrega tu colección al final de crearSemilla() (busca «Datos de los
//       demás módulos») y sube VERSION_SEMILLA en 1.
//    2. En bd.js: agrega el getter con el mismo nombre (busca «Datos por módulo»).
//  Los usuarios y sedes de arriba son los del backend en local: no los cambies para tu módulo.
// =====================================================================
import { ROL } from '../nucleo/dominio.js';

// Súbelo (1 → 2 → 3...) cada vez que cambies CUALQUIER dato de este archivo, también los de tu módulo.
// Si git marca conflicto en esta línea (dos compañeros la subieron a la vez), deja un número
// MAYOR que los dos: así ningún navegador se queda con datos viejos de uno solo de ustedes.
export const VERSION_SEMILLA = 1;
export const CLAVE_DEV = 'dev-clave-12345';

// Los 6 perfiles "de siempre", uno por rol (rol → usuario). El panel los ofrece en su selector;
// las demás personas entran por el login con la misma clave.
export const PERFILES_ROL = Object.freeze({
  [ROL.DOCENTE]: 'docente',
  [ROL.ADMIN]: 'administrativo',
  [ROL.SOPORTE_TECNICO]: 'soporte.tecnico',
  [ROL.SOPORTE_INFORMATICA]: 'soporte.informatica',
  [ROL.JEFE_SOPORTE]: 'jefe.soporte',
  [ROL.JEFE_INFORMATICA]: 'jefe.info',
});

export function crearSemilla() {
  const u = (id, nombre, usuario, rol, sede_id, extra = {}) => ({
    id, nombre, usuario, rol, sede_id,
    activo: true, debe_cambiar_clave: false, bloqueado: false, clave: CLAVE_DEV, ...extra,
  });

  return {
    version: VERSION_SEMILLA,

    sedes: [
      { id: 1, nombre: 'Sede 1' },
      { id: 2, nombre: 'Sede 2' },
    ],

    usuarios: [
      u(1, 'Docente de prueba', 'docente', ROL.DOCENTE, 1),
      u(2, 'Administrativo de prueba', 'administrativo', ROL.ADMIN, 1),
      u(3, 'Soporte técnico de prueba', 'soporte.tecnico', ROL.SOPORTE_TECNICO, 1),
      u(4, 'Soporte informática de prueba', 'soporte.informatica', ROL.SOPORTE_INFORMATICA, 1),
      u(5, 'Jefe de soporte de prueba', 'jefe.soporte', ROL.JEFE_SOPORTE, 1),
      u(6, 'Jefe de informática de prueba', 'jefe.info', ROL.JEFE_INFORMATICA, 1),
      u(7, 'Ana Pérez', 'aperez', ROL.DOCENTE, 1),
      u(8, 'Luis Soto', 'lsoto', ROL.DOCENTE, 2),
      u(9, 'María Quispe', 'mquispe', ROL.ADMIN, 1),
      u(10, 'Carlos Rojas', 'crojas', ROL.SOPORTE_TECNICO, 1),
      u(11, 'Elena Vargas', 'evargas', ROL.SOPORTE_INFORMATICA, 2),
      u(12, 'Jorge Mendoza', 'jmendoza', ROL.JEFE_SOPORTE, null),
      u(13, 'Rosa Díaz', 'rdiaz', ROL.DOCENTE, 2, { activo: false }),
      u(14, 'Pedro Castro', 'pcastro', ROL.DOCENTE, 1, { bloqueado: true }),
      u(15, 'Nueva Persona', 'npersona', ROL.ADMIN, null, { debe_cambiar_clave: true }),
    ],
    siguienteIdUsuario: 16,

    idempotencia: {},   // "usuarioId:Idempotency-Key" → id del recurso ya creado
    fallos: {},         // usuario → intentos de login fallidos seguidos

    // Datos del módulo de ejemplo (js/mocks/handlers/plantilla.js).
    plantilla: [
      { id: 1, nombre: 'Elemento de ejemplo 1' },
      { id: 2, nombre: 'Elemento de ejemplo 2' },
      { id: 3, nombre: 'Elemento de ejemplo 3' },
    ],

    // ----- Datos de los demás módulos: una colección por línea -----
    // Descomenta SOLO la línea de tu módulo y llénala con tus datos de prueba (o agrega una
    // nueva con la misma forma). El nombre es el MISMO que el getter de bd.js. Cada elemento
    // lleva su propio id y la forma de datos de docs/api/<modulo>.md. Y sube VERSION_SEMILLA.
    // equipos: [],
    // incidencias: [],
    // autorizaciones: [],
    // indicadores: [],
  };
}