// frontend/js/nucleo/dominio.js
// =====================================================================
//  Dominio compartido: roles, estados, prioridades, canales, catálogo de
//  permisos y formato de fechas. NINGÚN módulo escribe estos valores como
//  texto suelto: los importa de aquí.
//
//     import { ESTADO, PERMISO, formatearFechaHora } from '../../nucleo/dominio.js';
//
//  Fuente de verdad: backend/nucleo/Dominio.php (sus valores son los de los
//  CHECK de la base de datos). Este archivo es su espejo en JavaScript.
//  tests/dominio_front_test.php FALLA si los valores o la matriz de permisos
//  difieren, así que ningún cambio se puede subir a medias.
//
//  Para tocar este archivo mantén el formato actual (una constante por
//  línea, matriz con un rol por bloque): el test lo lee con expresiones
//  regulares.
// =====================================================================

// ---------- Roles ----------
export const ROL = Object.freeze({
  DOCENTE: 'docente',
  ADMIN: 'admin',
  SOPORTE_TECNICO: 'soporte_tecnico',
  SOPORTE_INFORMATICA: 'soporte_informatica',
  JEFE_SOPORTE: 'jefe_soporte',
  JEFE_INFORMATICA: 'jefe_informatica',
});

export const ROL_ETIQUETA = Object.freeze({
  [ROL.DOCENTE]: 'Docente',
  [ROL.ADMIN]: 'Administrativo',
  [ROL.SOPORTE_TECNICO]: 'Soporte técnico',
  [ROL.SOPORTE_INFORMATICA]: 'Soporte informática',
  [ROL.JEFE_SOPORTE]: 'Jefe de soporte',
  [ROL.JEFE_INFORMATICA]: 'Jefe de informática',
});

// ---------- Estados de una incidencia ----------
export const ESTADO = Object.freeze({
  REGISTRADA: 'registrada',
  ASIGNADA: 'asignada',
  EN_ATENCION: 'en_atencion',
  EN_ESPERA: 'en_espera',
  RESUELTA: 'resuelta',
  CERRADA: 'cerrada',
});

export const ESTADO_ETIQUETA = Object.freeze({
  [ESTADO.REGISTRADA]: 'Registrada',
  [ESTADO.ASIGNADA]: 'Asignada',
  [ESTADO.EN_ATENCION]: 'En atención',
  [ESTADO.EN_ESPERA]: 'En espera',
  [ESTADO.RESUELTA]: 'Resuelta',
  [ESTADO.CERRADA]: 'Cerrada',
});

// ---------- Prioridad (RN-04) ----------
export const PRIORIDAD = Object.freeze({
  ALTA: 'alta',
  MEDIA: 'media',
  BAJA: 'baja',
});

export const PRIORIDAD_ETIQUETA = Object.freeze({
  [PRIORIDAD.ALTA]: 'Alta',
  [PRIORIDAD.MEDIA]: 'Media',
  [PRIORIDAD.BAJA]: 'Baja',
});

// ---------- Canal de registro (RF-02) ----------
export const CANAL = Object.freeze({
  SISTEMA: 'sistema',
  LLAMADA: 'llamada',
  MENSAJE: 'mensaje',
  FORMULARIO: 'formulario',
});

export const CANAL_ETIQUETA = Object.freeze({
  [CANAL.SISTEMA]: 'Sistema',
  [CANAL.LLAMADA]: 'Llamada',
  [CANAL.MENSAJE]: 'Mensaje',
  [CANAL.FORMULARIO]: 'Formulario impreso',
});

// Texto para mostrar de cualquiera de las tablas *_ETIQUETA (si no existe, el valor tal cual).
export const etiqueta = (tabla, valor) => tabla[valor] ?? String(valor ?? '');

// ---------- Permisos: catálogo único ----------
// Mismos nombres que Nucleo\Dominio::PERMISOS. El router y sesion.puede() rechazan
// cualquier nombre que no esté aquí: 'incidencias.ver' en vez de 'incidencia.ver'
// falla al arrancar, no en producción.
export const PERMISO = Object.freeze({
  INCIDENCIA_VER: 'incidencia.ver',
  INCIDENCIA_REGISTRAR_PROPIA: 'incidencia.registrar_propia',
  INCIDENCIA_REGISTRAR_A_NOMBRE: 'incidencia.registrar_a_nombre',
  INCIDENCIA_PRIORIZAR: 'incidencia.priorizar',
  INCIDENCIA_ASIGNAR: 'incidencia.asignar',
  INCIDENCIA_ATENDER: 'incidencia.atender',
  INCIDENCIA_CONFIRMAR: 'incidencia.confirmar',
  INCIDENCIA_CERRAR_SIN_CONFIRMACION: 'incidencia.cerrar_sin_confirmacion',
  EQUIPO_ELEGIR: 'equipo.elegir',
  EQUIPO_GESTIONAR: 'equipo.gestionar',
  EQUIPO_HISTORIAL: 'equipo.historial',
  EQUIPO_DAR_DE_BAJA: 'equipo.dar_de_baja',
  AUTORIZACION_SOLICITAR: 'autorizacion.solicitar',
  AUTORIZACION_DECIDIR: 'autorizacion.decidir',
  INDICADORES_VER: 'indicadores.ver',
  USUARIO_GESTIONAR: 'usuario.gestionar',
  SEDE_TODAS: 'sede.todas',
});

export const PERMISOS_VALIDOS = new Set(Object.values(PERMISO));

// ---------- Matriz rol × permiso ----------
// SOLO para mocks y pruebas: con el backend real los permisos llegan en GET /me.
// Un rol por bloque y sin listas compartidas: el test la compara con Dominio::PERMISOS_POR_ROL.
const P = PERMISO;

export const PERMISOS_POR_ROL = Object.freeze({
  [ROL.DOCENTE]: [
    P.INCIDENCIA_VER, P.INCIDENCIA_REGISTRAR_PROPIA, P.INCIDENCIA_CONFIRMAR, P.EQUIPO_ELEGIR,
  ],
  [ROL.ADMIN]: [
    P.INCIDENCIA_VER, P.INCIDENCIA_REGISTRAR_PROPIA, P.INCIDENCIA_CONFIRMAR, P.EQUIPO_ELEGIR,
  ],
  [ROL.SOPORTE_TECNICO]: [
    P.INCIDENCIA_VER, P.INCIDENCIA_REGISTRAR_A_NOMBRE, P.INCIDENCIA_ATENDER,
    P.EQUIPO_ELEGIR, P.EQUIPO_GESTIONAR, P.EQUIPO_HISTORIAL,
    P.AUTORIZACION_SOLICITAR,
  ],
  [ROL.SOPORTE_INFORMATICA]: [
    P.INCIDENCIA_VER, P.INCIDENCIA_REGISTRAR_A_NOMBRE, P.INCIDENCIA_ATENDER,
    P.EQUIPO_ELEGIR, P.EQUIPO_GESTIONAR, P.EQUIPO_HISTORIAL,
    P.AUTORIZACION_SOLICITAR,
  ],
  [ROL.JEFE_SOPORTE]: [
    P.INCIDENCIA_VER, P.INCIDENCIA_REGISTRAR_A_NOMBRE, P.INCIDENCIA_PRIORIZAR, P.INCIDENCIA_ASIGNAR,
    P.INCIDENCIA_CERRAR_SIN_CONFIRMACION,
    P.EQUIPO_ELEGIR, P.EQUIPO_GESTIONAR, P.EQUIPO_HISTORIAL, P.EQUIPO_DAR_DE_BAJA,
    P.AUTORIZACION_SOLICITAR, P.INDICADORES_VER, P.SEDE_TODAS,
  ],
  [ROL.JEFE_INFORMATICA]: [
    P.INCIDENCIA_VER, P.EQUIPO_GESTIONAR, P.EQUIPO_HISTORIAL,
    P.AUTORIZACION_DECIDIR, P.INDICADORES_VER, P.USUARIO_GESTIONAR, P.SEDE_TODAS,
  ],
});

export const permisosDe = (rol) => [...(PERMISOS_POR_ROL[rol] ?? [])];

// ---------- Contraseñas (el backend tiene la última palabra) ----------
export const CLAVE_MIN = 10;

// ---------- Fechas: siempre en hora de Lima (RN-10) ----------
const ZONA = 'America/Lima';
const FECHA = new Intl.DateTimeFormat('es-PE', { timeZone: ZONA, day: '2-digit', month: '2-digit', year: 'numeric' });
const FECHA_HORA = new Intl.DateTimeFormat('es-PE', {
  timeZone: ZONA, day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false,
});

const SOLO_FECHA = /^(\d{4})-(\d{2})-(\d{2})$/;

function aFecha(valor) {
  if (valor === null || valor === undefined || valor === '') return null;
  const d = new Date(valor);
  return Number.isNaN(d.getTime()) ? null : d;
}

// '2026-03-31' (sin hora) se muestra tal cual: convertirla movería el día por la zona horaria.
export function formatearFecha(valor) {
  const m = SOLO_FECHA.exec(String(valor ?? ''));
  if (m) return `${m[3]}/${m[2]}/${m[1]}`;
  const d = aFecha(valor);
  return d ? FECHA.format(d) : '';
}

export function formatearFechaHora(valor) {
  const d = aFecha(valor);
  return d ? FECHA_HORA.format(d) : '';
}