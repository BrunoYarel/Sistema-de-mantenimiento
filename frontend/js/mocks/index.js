// frontend/js/mocks/index.js
// =====================================================================
//  DATOS DE PRUEBA (mocks) — ÚNICA explicación de cómo funcionan.
//  README, GUIA_EQUIPO y config.js solo apuntan aquí: si algo
//  cambia en los mocks, se corrige en este archivo y en ningún otro.
//
//  QUÉ ES
//  Un backend falso dentro del navegador, solo para desarrollar pantallas
//  sin backend. Responde a /api con la forma del contrato ({ data } o
//  { error }) y con sesión, permisos y errores simulados. TODO el código
//  real —http.js, sesion.js, los *.api.js y las pantallas— corre sin
//  cambios: los módulos no saben que hay mocks. No corre nada de backend/,
//  database/ ni docker/.
//
//  CÓMO SE ACTIVA (parámetros de la URL; los lee js/config.js; solo en localhost)
//    ?mocks=1                    todo simulado; una ruta sin mock responde 501 "sin_mock"
//    ?mocks=sesion,usuarios      solo esos ids; el resto va al backend real
//    ?mocks=0 (o sin parámetro)  nada simulado (backend real)
//    ?latencia=300               demora simulada de la red en ms (por defecto 300).
//  Ids: sesion (login, logout, /me, cambio de clave) · equipos (solo GET /sedes) ·
//       usuarios (todo el módulo) · plantilla (módulo de ejemplo)
//  Sin 'sesion' en la lista no se valida sesión ni permisos y el panel no cambia de usuario.
//  La lista vigente de módulos simulados es MODULOS, más abajo.
//
//  CÓMO SE ENGANCHA
//  app.js llama a activar() antes de cualquier petición. Se guarda el fetch
//  real y se instala uno nuevo: para cada llamada a /api busca un mock; si lo
//  hay, responde un Response de verdad; si no, la deja pasar al fetch original.
//
//  DÓNDE VIVE CADA COSA (modo real → modo mocks)
//    Quién responde a /api  nginx → PHP-FPM → PostgreSQL   →  servidor.js
//    Los datos              tablas de PostgreSQL           →  sessionStorage de la pestaña (bd.js);
//                                                             al cerrar la pestaña o pulsar
//                                                             «Reiniciar datos» vuelven a la semilla
//    La sesión              cookie CASOA_SID + archivo     →  una clave en sessionStorage
//
//  ESTRUCTURA
//    index.js      este archivo: activa los mocks (único punto de entrada)
//    servidor.js   el servidor falso: rutas, sesión, permisos, errores; reemplaza window.fetch
//    bd.js         la "base de datos" de prueba (sessionStorage, por pestaña)
//    semilla.js    los datos iniciales (usuarios, sedes...); si los cambias, sube VERSION_SEMILLA
//    handlers/     un archivo por módulo del backend, con sus rutas
//    panel.js/.css el panel «Datos de prueba» (abajo a la izquierda): botón «Acceso rápido» con
//                  los 6 roles (un clic = entrar con ese perfil), «Más opciones» y «Reiniciar datos» (clases mk-)
//  Usuarios y clave: los mismos que el backend en local (clave dev-clave-12345).
//
//  LÍMITES (lee esto antes de fiarte de lo que ves)
//   - Son una copia aproximada del backend. Los permisos salen de la matriz de
//     nucleo/dominio.js, no de Dominio.php, y no simulan reglas como RN-02. Que algo
//     funcione con mocks no prueba que funcione con el backend: en casa, prueba
//     también sin ?mocks=.
//   - Una pestaña abierta con ?mocks=1 nunca llama al backend, aunque esté corriendo.
//     Si la URL conserva el parámetro, parecerá que el backend responde y no lo hará.
//   - Solo corren en localhost (config.EN_LOCAL); en cualquier otro host se ignoran.
//     Lo desplegado nunca usa mocks.
//
//  CÓMO AGREGAR EL MOCK DE UN MÓDULO NUEVO
//    1. Copia handlers/plantilla.js, cambia el id y las rutas (las mismas de su
//       rutas.php o de docs/api/<modulo>.md) y la forma de datos del contrato.
//    2. Regístralo en MODULOS, más abajo (una línea).
//    3. Si necesita datos iniciales: agrégalos en semilla.js (sube VERSION_SEMILLA)
//       y su get en bd.js.
//    El .api.js del módulo no cambia.
//
//  CÓMO QUITAR TODO cuando ya no hagan falta
//    1. Borra la carpeta js/mocks/
//    2. En app.js borra el bloque "Datos de prueba"
//    3. En config.js borra la línea MOCKS, la función leerMocks y el bloque de parámetros de la URL
//  Nada más depende de esta carpeta (bin/verificar.sh lo comprueba).
// =====================================================================
import { EN_LOCAL } from '../config.js';
import { crearBd } from './bd.js';
import { crearServidor, instalarEnFetch } from './servidor.js';
import { montarPanel } from './panel.js';
import auth from './handlers/auth.js';
import equipos from './handlers/equipos.js';
import usuarios from './handlers/usuarios.js';
import plantilla from './handlers/plantilla.js';

// UNA línea por módulo con mock.
const MODULOS = [auth, equipos, usuarios, plantilla];

export function activar(config) {
  if (!EN_LOCAL) throw new Error('Los datos de prueba solo se activan en localhost.');

  const seleccion = config.MOCKS;
  const habilitado = (id) => seleccion === true || (Array.isArray(seleccion) && seleccion.includes(id));

  const conocidos = MODULOS.map((m) => m.id);
  if (Array.isArray(seleccion)) {
    const raros = seleccion.filter((id) => !conocidos.includes(id));
    if (raros.length) console.warn(`[mocks] Ids desconocidos en ?mocks=: ${raros.join(', ')}. Los válidos: ${conocidos.join(', ')}.`);
  }

  const activos = MODULOS.filter((m) => habilitado(m.id)).map((m) => m.id);
  const sesionSimulada = habilitado('sesion');
  const latencia = Number(config.MOCK?.latenciaMs);
  const bd = crearBd();

  const atender = crearServidor({
    modulos: MODULOS,
    habilitado,
    bd,
    apiUrl: config.API_URL,
    latenciaMs: Number.isFinite(latencia) && latencia >= 0 ? latencia : 300,
    sesionSimulada,
    todoSimulado: seleccion === true,
  });
  instalarEnFetch(atender);
  montarPanel({ bd, sesionSimulada, modulos: activos, servidor: atender });

  console.info(`[mocks] Datos de prueba activos: ${activos.join(', ') || 'ninguno'}. Usa el panel de abajo a la izquierda para cambiar de usuario.`);
}