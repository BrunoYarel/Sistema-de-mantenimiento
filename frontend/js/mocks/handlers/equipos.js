// frontend/js/mocks/handlers/equipos.js
// =====================================================================
//  Mock de backend/modulos/equipos. Contrato: docs/api/equipos.md.
//  Hoy solo GET /sedes (lo usan Inicio y Usuarios). Cuando el módulo equipos
//  del frontend necesite /ubicaciones, /equipos, etc., se agregan AQUÍ, no en
//  el .api.js del módulo.
// =====================================================================
const porNombre = (a, b) => a.nombre.localeCompare(b.nombre, 'es');

export default {
  id: 'equipos',
  rutas: [
    // Todos los roles ven todas las sedes (docs/api/equipos.md → GET /sedes).
    { metodo: 'GET', ruta: '/sedes', permiso: null, atender: ({ bd }) => bd.sedes.map((s) => ({ ...s })).sort(porNombre) },
  ],
};
