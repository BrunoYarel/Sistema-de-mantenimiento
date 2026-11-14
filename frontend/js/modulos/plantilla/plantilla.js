// frontend/js/modulos/plantilla/plantilla.js
// =====================================================================
//  MÓDULO DE EJEMPLO. Para crear el tuyo:
//    1. Copia la carpeta  js/modulos/plantilla  →  js/modulos/<tu_modulo>
//    2. Renombra los archivos:  <tu_modulo>.js  <tu_modulo>.css  <tu_modulo>.api.js
//    3. Cambia el id, el menú, los permisos, las rutas y las vistas de abajo.
//    4. Agrega UNA línea en  js/modulos/index.js
//  Regla: la carpeta, los archivos y el id se llaman igual.
//
//  Este módulo habla con backend/modulos/plantilla (GET /api/plantilla/sedes).
//  Los permisos salen de PERMISO (nucleo/dominio.js), nunca como texto suelto.
// =====================================================================
import { sesion } from '../../nucleo/sesion.js';
import { PERMISO } from '../../nucleo/dominio.js';
import { html, pintarCargando } from '../../nucleo/ui.js';
import { plantillaApi } from './plantilla.api.js';

export default {
  id: 'plantilla',                  // = nombre de la carpeta
  titulo: 'Ejemplo',                // título de la pestaña del navegador
  permiso: null,                    // null = cualquiera con sesión (hay que escribirlo; sin esta línea la app no arranca)
  menu: { etiqueta: 'Ejemplo', ruta: '/plantilla', orden: 90 },   // sin "menu" no sale en la barra
  rutas: [
    // Cada ruta puede tener su propio "permiso", que manda sobre el del módulo.
    { ruta: '/plantilla', vista: lista },
    { ruta: '/plantilla/:id', vista: detalle },
  ],
};

// Una vista es una función (el, ctx):
//   el  → el contenedor donde dibujas
//   ctx → { params, query, ruta, ir }
// Si lanza un error (por ejemplo, falla el backend) el router muestra el mensaje
// con un botón "Reintentar": no hace falta capturarlo aquí.

async function lista(el) {
  pintarCargando(el);
  const items = await plantillaApi.listar();

  el.innerHTML = html`
    <h1>Ejemplo</h1>
    ${items.length === 0
      ? html`<p class="estado">No hay elementos todavía.</p>`
      : html`
        <div class="tabla-contenedor">
          <table class="tabla">
            <thead><tr><th>Nombre</th></tr></thead>
            <tbody>
              ${items.map((i) => html`
                <tr>
                  <td><a href="#/plantilla/${i.id}">${i.nombre}</a></td>
                </tr>`)}
            </tbody>
          </table>
        </div>`}`;
}

async function detalle(el, { params }) {
  pintarCargando(el);
  const item = await plantillaApi.obtener(params.id);

  el.innerHTML = html`
    <p><a href="#/plantilla">← Volver</a></p>
    <h1>${item.nombre}</h1>
    ${sesion.puede(PERMISO.USUARIO_GESTIONAR) ? html`<button type="button" class="btn">Editar</button>` : ''}`;

  // Si creas un timer o un listener en window/document, devuelve una función que lo quite:
  //   const id = setInterval(...);   return () => clearInterval(id);
}