// frontend/js/mocks/handlers/plantilla.js

//  ─── CÓMO CREAR EL MOCK DE TU MÓDULO ─────────────────────────────────
//  1. Copia este archivo como  js/mocks/handlers/<tu_modulo>.js  y cambia:
//       · el id (abajo, en  export default): el nombre de tu módulo, igual que
//         tu carpeta y que lo que se escribe en  ?mocks=<tu_modulo>.
//       · las rutas: las mismas de backend/modulos/<tu_modulo>/rutas.php y de
//         docs/api/<tu_modulo>.md (mismo método, misma ruta).
//  2. Si necesita datos propios, agrégalos en js/mocks/semilla.js (y sube
//     VERSION_SEMILLA) y su getter en js/mocks/bd.js. Si tus rutas no guardan
//     nada (solo devuelven datos fijos), no hace falta.
//  3. Registra el archivo en js/mocks/index.js: descomenta tus dos líneas
//     (el import y la entrada de MODULOS) o, si tu módulo no está, agrégalas.
//  ─────────────────────────────────────────────────────────────────────
//
//  ─── QUÉ LLEVA CADA RUTA (cada una es un objeto dentro de  rutas: [ ... ]) ──
//    metodo     'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
//    ruta       Sin el prefijo /api. Un tramo variable se escribe con dos puntos
//               (/equipos/:id) y llega a atender() en  params.id  (siempre texto).
//    permiso    OBLIGATORIO en toda ruta, aunque sea null (si falta, ningún mock arranca):
//                 · null              basta con tener sesión iniciada
//                 · PERMISO.ALGO      exige ese permiso (PERMISO se importa de nucleo/dominio.js)
//               CUIDADO: un nombre mal escrito (PERMISO.ALGOO) da undefined y la ruta
//               queda abierta a cualquiera con sesión, sin ningún aviso. Cópialo de
//               nucleo/dominio.js y pruébalo con un rol que NO tenga ese permiso.
//    atender    La función que responde. Recibe un solo objeto con:
//                 params   lo variable de la ruta       → params.id
//                 query    lo que va tras el ?          → query.sede_id
//                 cuerpo   el JSON enviado (POST/PUT/PATCH; {} si no hay)
//                 actor    la persona con sesión (null si no hay sesión simulada)
//                 clave    la cabecera Idempotency-Key (para altas, si tu contrato la pide)
//                 bd       la base de prueba: bd.plantilla, bd.sedes, bd.usuarios...
//               Lo que devuelve  return  es el  data  de la respuesta (200).
//               Para responder 201 (alta) importa  creado  de '../servidor.js'  y
//               devuelve  creado(datos).
//               Para responder un error:  throw Errores.xxx(...)  (los posibles son
//               los de Errores en servidor.js: sinSesion, prohibido, noEncontrado,
//               conflicto, invalido, demasiados). Usa el código y el mensaje del contrato.
//    publica    (opcional) true → no exige sesión. Solo login y logout.
//    clavePendiente  (opcional) true → se permite aunque la clave sea temporal. Solo /me, clave y logout.
//  ─────────────────────────────────────────────────────────────────────
//
//  REGLAS QUE SE VERIFICAN
//    · bin/verificar.sh comprueba que cada ruta (método + ruta) de este archivo
//      exista en el rutas.php de su módulo. Si no existe, la verificación falla.
//    · Dos rutas iguales (en este o en otro handler) hacen que ningún mock arranque
//      («Mock repetido»).
//    · Devuelve copias ({ ...item }), no el objeto de bd: si no, la pantalla podría
//      modificar la base de prueba sin querer.
//    · Si tu atender() modifica datos (alta, edición, borrado), no hace falta guardar
//      nada: servidor.js llama a bd.guardar() después de cada petición.
// =====================================================================
import { Errores } from '../servidor.js';

export default {
  id: 'plantilla',
  rutas: [
    // Lista: todo lo que haya en la base de prueba, sin permiso especial.
    { metodo: 'GET', ruta: '/plantilla/sedes', permiso: null, atender: ({ bd }) => bd.plantilla.map((i) => ({ ...i })) },
    {
      // Detalle: ":id" llega en params.id; si no existe, 404 con el mensaje del contrato.
      metodo: 'GET',
      ruta: '/plantilla/sedes/:id',
      permiso: null,
      atender: ({ params, bd }) => {
        const item = bd.plantilla.find((i) => String(i.id) === params.id);
        if (!item) throw Errores.noEncontrado('No se encontró la sede.');
        return { ...item };
      },
    },
  ],
};