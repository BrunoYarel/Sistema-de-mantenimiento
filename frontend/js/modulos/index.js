// frontend/js/modulos/index.js
// =====================================================================
//  Registro de módulos: UNA línea por módulo en MODULOS.
//  Cada módulo se importa por separado: si uno tiene un error de sintaxis
//  o no carga, los demás siguen funcionando y se avisa en pantalla.
//  (El orden del menú sale de "menu.orden" de cada módulo, no de aquí.)
//
//  ─── CÓMO REGISTRAR TU MÓDULO (30 segundos) ──────────────────────────
//  Las líneas de los módulos previstos YA ESTÁN ESCRITAS abajo, comentadas.
//  1. Termina de crear tu carpeta  js/modulos/<modulo>/  con  <modulo>.js
//     (pasos en README.md → «Crear un módulo»).
//  2. Quita el  //  del inicio de TU línea y guarda. Nada más.
//  3. Recarga la página: tu módulo aparece en el menú si tu rol tiene permiso.
//
//  Reglas (si se rompen, el módulo queda desactivado y sale un aviso rojo):
//    · El nombre de la clave, la carpeta y el  id  dentro de <modulo>.js
//      son EL MISMO texto:  equipos → js/modulos/equipos/equipos.js → id: 'equipos'.
//    · No descomentes tu línea hasta que exista tu <modulo>.js; si no, el
//      navegador muestra «El módulo "<modulo>" no se pudo cargar».
//    · Toca solo TU línea. Si git marca conflicto en este archivo, quédate
//      con las líneas de ambos lados: nunca borres la de otro compañero.
//    · ¿Tu módulo no está en la lista? Agrega una línea nueva al final del
//      bloque (misma forma que las demás) y reserva su prefijo CSS de 2 letras
//      en css/base.css.
//    · El orden del menú NO se decide aquí: lo da  menu.orden  en tu <modulo>.js.
//    · Datos de prueba (mocks): se registran aparte, en js/mocks/index.js.
//  ─────────────────────────────────────────────────────────────────────
const MODULOS = {
  // --- Base del sistema (no tocar) ---
  login: () => import('./login/login.js'),
  inicio: () => import('./inicio/inicio.js'),
  usuarios: () => import('./usuarios/usuarios.js'),

  // equipos: () => import('./equipos/equipos.js'),
  // incidencias: () => import('./incidencias/incidencias.js'),
  // autorizaciones: () => import('./autorizaciones/autorizaciones.js'),
  // indicadores: () => import('./indicadores/indicadores.js'),

};

// Sin estos la app no puede funcionar: si fallan, se detiene con un error claro.
const CRITICOS = ['login'];

// → { modulos: [...los que cargaron], fallidos: [{ id, error }] }
export async function cargarModulos() {
  const ids = Object.keys(MODULOS);
  const resultados = await Promise.allSettled(ids.map((id) => MODULOS[id]()));

  const modulos = [];
  const fallidos = [];
  resultados.forEach((r, i) => {
    const id = ids[i];
    if (r.status === 'rejected') { fallidos.push({ id, error: r.reason }); return; }
    const definicion = r.value.default;
    if (!definicion || definicion.id !== id) {
      fallidos.push({ id, error: new Error(`El módulo exporta id "${definicion?.id}" y la carpeta se llama "${id}": deben coincidir.`) });
      return;
    }
    modulos.push(definicion);
  });

  const critico = fallidos.find((f) => CRITICOS.includes(f.id));
  if (critico) throw new Error(`No cargó el módulo imprescindible "${critico.id}": ${critico.error?.message ?? critico.error}`);
  return { modulos, fallidos };
}