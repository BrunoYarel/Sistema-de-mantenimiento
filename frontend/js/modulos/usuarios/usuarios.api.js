// frontend/js/modulos/usuarios/usuarios.api.js
// =====================================================================
//  Acceso a datos del módulo usuarios: solo habla con el backend real.
//  El resto del módulo solo usa  usuariosApi.listar()  etc.
//  Contrato: docs/api/usuarios.md (RF-12). Todas las rutas piden usuario.gestionar
//  (hoy, solo el jefe de informática). Las sedes salen de GET /sedes (docs/api/equipos.md).
//
//  Forma de un usuario (la misma en la lista y en cada acción; nunca trae clave ni hash):
//    { id, nombre, usuario, rol, sede_id|null, activo, debe_cambiar_clave, bloqueado }
//  El alta y restablecer-clave suman `clave_temporal`: se muestra UNA sola vez.
//
//  Datos de prueba: no viven aquí. Para trabajar sin backend, js/mocks/handlers/usuarios.js
//  responde a estas mismas llamadas. Para ver la pantalla, entra con el rol jefe de informática
//  (panel "Datos de prueba", abajo a la izquierda): con otro rol la pantalla ni aparece.
// =====================================================================
import { http, seg } from '../../nucleo/http.js';

// http.get descarta los filtros vacíos; seg() impide que un id cuele "..", "/" o "%2F".
export const usuariosApi = {
  listar: (filtros) => http.get('/usuarios', filtros),
  obtener: (id) => http.get(`/usuarios/${seg(id)}`),
  sedes: () => http.get('/sedes'),
  // `clave` = Idempotency-Key (la entrega formulario.js): un reintento no duplica el alta.
  crear: (datos, { clave }) => http.post('/usuarios', datos, { clave }),
  editar: (id, cambios) => http.patch(`/usuarios/${seg(id)}`, cambios),
  desactivar: (id) => http.post(`/usuarios/${seg(id)}/desactivar`),
  reactivar: (id) => http.post(`/usuarios/${seg(id)}/reactivar`),
  restablecerClave: (id) => http.post(`/usuarios/${seg(id)}/restablecer-clave`),
  desbloquear: (id) => http.post(`/usuarios/${seg(id)}/desbloquear`),
};