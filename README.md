# Sistema de mantenimiento y soporte técnico (Caso A)

SPA sin herramientas de build (HTML + JavaScript con módulos nativos + CSS) y backend PHP. Cada regla cita su ID (RF, RN, RNF, D). Lo marcado **[CI]** lo hace cumplir una prueba o un grep, no la disciplina.

## Dónde leer qué

| Quiero saber... | Lee |
|---|---|
| Las reglas de negocio y los 12 requerimientos (RF-01 a RF-12) | `Sistema de mantenimiento.md` |
| **Qué debe lograr** un módulo | `docs/funcionalidades/<modulo>.md` |
| **Rutas, permisos y formas de datos** de un módulo | `docs/api/<modulo>.md` |
| Levantar el sistema, usuarios de prueba, si algo falla | `GUIA_EQUIPO.md` |
| Crear mi módulo | «Crear un módulo», más abajo |
| Reglas técnicas del backend (capas, fechas, transiciones, indicadores) | `backend/README.md` |
| Cómo funcionan los datos de prueba (mocks) | `frontend/js/mocks/index.js` |

## Arranque rápido

**Solo pantallas, sin Docker ni backend** (datos de prueba):

```bash
python3 -m http.server 8000 -d frontend
```

Abre <http://localhost:8000/?mocks=1> y cambia de usuario con «Acceso rápido» (abajo a la izquierda). En Windows: `py -m http.server 8000 -d frontend`; con PHP: `php -S localhost:8000 -t frontend`. Estos servidores dejan los archivos en caché: si no ves un cambio, recarga con Ctrl+F5. Los módulos ES no cargan desde `file://`: abrir `index.html` con doble clic no funciona.

**Con backend real (Docker):**

```bash
docker compose up -d --build
```

Abre <http://localhost:8080> sin parámetros. No hace falta instalar PHP ni PostgreSQL: la base corre en un contenedor y se prepara sola. Con `?mocks=1` también hay datos de prueba en el 8080. Usuarios de prueba (clave `dev-clave-12345`), comandos de todos los días y solución de problemas: **`GUIA_EQUIPO.md`**.

## Mapa

```
Caso A/
├── frontend/                      ← lo que ve el navegador (HTML, CSS, JS)
│   ├── index.html                 ← la única página
│   ├── css/                       ← base.css (variables, estilos globales y prefijos CSS) · notify.css
│   └── js/
│       ├── app.js · config.js     ← arranque · ?mocks= (sí/no), URL de la API
│       ├── mocks/                 ← backend falso para trabajar sin backend (solo localhost; se puede borrar entera)
│       ├── nucleo/                ← router, sesion, http, ui, notify · formulario (borrador e Idempotency-Key) ·
│       │                            dominio (roles, estados, permisos) — se toca avisando al grupo
│       └── modulos/
│           ├── index.js           ← lista de módulos del frontend
│           └── <modulo>/          ← <modulo>.js, <modulo>.css, <modulo>.api.js (opcional)
├── backend/                       ← la API en PHP
│   ├── public/index.php           ← puerta de entrada: todo /api pasa por aquí
│   ├── nucleo/                    ← PDO, Reloj, permisos, idempotencia (se toca con 2 aprobaciones)
│   ├── modulos/                   ← index.php (lista de módulos) y <modulo>/ con rutas.php, Controlador, Servicio, Repositorio
│   └── storage/                   ← lo que PHP escribe al funcionar: sesiones, logs, respaldos (no se sube a git)
├── database/                      ← PostgreSQL: migraciones/ (esquema, numeradas) · grants.sql (permisos) · seed/ · dev/ (solo desarrollo)
├── docs/
│   ├── funcionalidades/           ← un documento por módulo: QUÉ debe lograr (se lee primero)
│   └── api/                       ← un contrato por módulo: rutas, permisos y formas de datos (se escribe antes de programar)
├── tests/                         ← pruebas de las reglas de negocio (oraculo_acciones.json y matriz.json, escritos a mano)
├── bin/                           ← scripts de terminal: migrar · usuarios · importar-equipos · respaldo · router-dev · verificar.sh
├── docker/ · docker-compose.yml   ← levantan nginx, PHP y PostgreSQL con un comando
└── *.md (raíz)                    ← Sistema de mantenimiento (negocio) · GUIA_EQUIPO (cómo levantar el sistema)
```

El recorrido de una petición: navegador → nginx (`frontend`) → `/api` → PHP (`backend`) → PostgreSQL (`db`).

## Siglas de los comentarios

Los comentarios citan reglas por sigla. La fuente de cada una está en `Sistema de mantenimiento.md` (RF, RN, RNF, D) o en `backend/README.md` (B1, B2, B6; las demás B se explican en el comentario donde se citan).

| Sigla | Significa |
|---|---|
| **RF-nn** | Requisito funcional (RF-01 a RF-12; RF-12 es usuarios y roles) |
| **RN-nn** | Regla de negocio (RN-02 todo técnico responsable; RN-06 cierre a 3 días hábiles; RN-07 nada se borra; RN-09 cada rol ve lo suyo) |
| **RNF-nn** | Requisito no funcional (RNF-01 seguridad, RNF-06 trazabilidad, RNF-07 mala conexión) |
| **D-nn / D-A…D-D** | Decisión ya cerrada (D9 cierre de incidencias; D-A sede es un filtro, no un permiso de datos) |
| **[P] / [S] / [H] / [D]** | Pregunta pendiente / supuesto a confirmar / hecho dado / decisión tomada |
| **B1** | Capas: Controlador → Servicio → Repositorio |
| **B2** | Una tabla, un módulo dueño: entre módulos se llaman servicios, no tablas |
| **B3** | Sentencias preparadas y `display_errors` apagado |
| **B5** | Una migración ya aplicada no se edita: se agrega otra numerada |
| **B6** | 401 significa solo «no hay sesión» |
| **B7** | Todo POST que inserta es idempotente |
| **F1** | Un módulo del frontend importa solo de `nucleo/`, `config.js`, de sí mismo o del `.api.js` de otro |
| **F2** | `fetch` solo en `nucleo/http.js` |
| **F4** | HTML solo con la función de plantilla `html` |
| **F6 / F7** | Toda ruta declara permiso; ningún estado, rol o permiso como texto suelto |
| **F10** | CSS de módulos sin colores a mano, solo las variables de `base.css` |

## Crear un módulo

`<modulo>` = nombre en minúscula y sin espacios (`reportes`). Antes de empezar, lee `docs/funcionalidades/<modulo>.md` (qué debe lograr) y `docs/api/<modulo>.md` (rutas y formas de datos).

**Qué copiar.** `plantilla` (frontend, backend y mock; solo existe en local) es el esqueleto mínimo: lista y detalle. Para formularios con borrador, filtros en la dirección, paginación, diálogos y manejo de errores, mira el módulo real **`usuarios`**. Los formularios usan siempre `nucleo/formulario.js` (nadie escribe su propio submit) y las constantes salen de `nucleo/dominio.js`.

### A. Solo la parte visual (frontend + mocks, sin backend)

1. **Prefijo CSS:** los módulos previstos ya tienen su prefijo reservado en `frontend/css/base.css`. Si el tuyo no está, agrega una línea con 2 letras exclusivas.
2. **Módulo:** copia `frontend/js/modulos/plantilla/` a `frontend/js/modulos/<modulo>/` y renombra `plantilla.js`, `.css` y `.api.js` a `<modulo>.*`. Dentro, cambia `id`, `titulo`, `permiso`, `menu`, `rutas`, las vistas y el prefijo `pl-`.
3. **Registro:** quita el `//` de tu línea en `frontend/js/modulos/index.js`.
4. **Mock:** copia `frontend/js/mocks/handlers/plantilla.js` a `<modulo>.js` y pon las rutas y la forma de datos de `docs/api/<modulo>.md`. Los datos iniciales van en `mocks/semilla.js` con su `get` en `mocks/bd.js`; el handler se registra en `mocks/index.js` (detalle en ese archivo).
5. **Probar:** abre `http://localhost:8000/?mocks=1`. El `.api.js` no sabe que hay mocks: llama a `/api` como siempre.

`bin/verificar.sh` exige que cada ruta de un mock exista en un `rutas.php`. Un prototipo cuyo backend aún no tiene rutas hará fallar esa comprobación: acuerden con el líder dónde vive ese prototipo hasta que exista el backend.

Varias personas tocan a la vez `modulos/index.js`, `mocks/index.js`, `semilla.js` y `bd.js`: haz `pull` antes de editarlos, commits pequeños y, ante un conflicto, conserva las líneas de ambos lados (en `VERSION_SEMILLA`, un número mayor que los dos).

### B. Completo (frontend + backend + base de datos)

Haz los pasos 1 a 3 de A y además:

1. **Contrato primero:** si `docs/api/<modulo>.md` no existe, copia `docs/api/_plantilla.md`, escribe las rutas y acuérdalo con el equipo antes de programar.
2. **Base de datos:** tablas nuevas en `database/migraciones/NNNN_<nombre>.sql` (el número lo asigna el líder; una migración aplicada no se edita) y sus permisos en `database/grants.sql`. Si agregas un `CHECK (col IN (...))`, su lista va en `backend/nucleo/Dominio.php` y en `LISTAS_CHECK` de `tests/dominio_test.php`.
3. **Backend:** copia `backend/modulos/plantilla/` a `backend/modulos/<modulo>/` (si la carpeta ya existe con un `rutas.php` vacío, como `autorizaciones`, llénala en vez de copiar). Cambia el namespace a `Modulos\<Nombre>` (en `Controlador`, `Servicio`, `Repositorio` y en el `use` de `rutas.php`) y escribe: `rutas.php` (URL + permiso), `Controlador` (solo HTTP), `Servicio` (reglas) y `Repositorio` (único con SQL). Agrégalo en `backend/modulos/index.php` si no está. Las reglas técnicas están en `backend/README.md`.
4. **Permiso nuevo (si hace falta):** en `Dominio.php` y en `frontend/js/nucleo/dominio.js` (`PERMISO` y por rol), y en `tests/matriz.json`.
5. **Conectar:** el `.api.js` llama a las rutas del contrato (con `seg()` para los ids). Mantén el mock con la misma forma, o abre la página sin `?mocks=` (o con `?mocks=0`) para usar el backend real.
6. **Antes de subir:** `docker compose exec backend bash bin/verificar.sh` y `docker compose exec backend php tests/ejecutar.php --con-bd`.

## Reglas del frontend

`bin/verificar.sh` comprueba los imports (F1), que `fetch` solo esté en `nucleo/http.js` (F2), el HTML seguro (F4) y los módulos CSS sin colores a mano (F10). Las reglas de cada pantalla (duplicados, confirmación, historial, corte de indicadores) están en `docs/funcionalidades/<modulo>.md`.

## Contrato con el backend (vigente)

| | |
|---|---|
| `GET /api/me` | `{ "data": { "id", "nombre", "rol", "permisos": [], "debe_cambiar_clave" } }` o `401` |
| `POST /api/auth/login` | recibe `{ usuario, clave }`; devuelve lo mismo que `/me`, `401` o `429` |
| `POST /api/auth/logout` | cierra la sesión |
| `POST /api/auth/clave` | cambia la clave; con `debe_cambiar_clave` es lo único permitido, junto con logout |
| Correcto | `{ "data": ... }` |
| Error | `{ "error": { "codigo", "mensaje", "campos": { "campo": "texto" } } }` |
| Códigos | `401` sin sesión · `403` acción no permitida · `404` fuera de ámbito (RN-09) · `409` transición inválida o ya hecha · `422` validación · `429` límite de intentos |
| `409` de transición | agrega `data` con el recurso actual: la pantalla dice "ya estaba hecho", no "error" |
| `POST` que inserta | lleva `Idempotency-Key`; misma (usuario, clave) = mismo resultado, sin duplicar |
| Incidencia | incluye `acciones: []`, las permitidas para quien consulta |
| Fechas | hora de Lima (`-05:00`) |

Cambiar esto es fácil, pero hay que avisar: `http.js` y `sesion.js` son los únicos que lo conocen.