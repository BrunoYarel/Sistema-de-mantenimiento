# Sistema de mantenimiento y soporte técnico (Caso A)

SPA sin herramientas de build (HTML + JavaScript con módulos nativos + CSS) y backend PHP. Requerimientos (12 funcionales, RF-01 a RF-12) y reglas de negocio: `Sistema de mantenimiento.md`. Cada regla cita su ID (RF, RN, RNF, D). Lo marcado **[CI]** lo hace cumplir una prueba o un grep, no la disciplina.

## Arranque rápido (diseño de pantallas, sin Docker ni backend)

```bash
python3 -m http.server 8000 -d frontend
```

Abre <http://localhost:8000/?mocks=1> y cambia de usuario con un clic en el botón «Acceso rápido» (abajo a la izquierda). Requiere Python 3.7 o superior (en Windows: `py -m http.server 8000 -d frontend`). Con PHP en lugar de Python: `php -S localhost:8000 -t frontend`. Estos servidores dejan los archivos en caché: si no ves un cambio, recarga con Ctrl+F5. Con Docker levantado, <http://localhost:8080/?mocks=1> hace lo mismo y nginx ya revalida siempre.

Los módulos ES no cargan desde `file://`: abrir `index.html` con doble clic no funciona; hace falta un servidor estático como el de arriba.

Con `?mocks=1` todo corre con datos de prueba (mocks), sin backend. Parámetros, límites y cómo agregar o quitar un mock: `frontend/js/mocks/index.js`.

## Ejecutar con backend (Docker)

```bash
docker compose up -d --build
```

Abre <http://localhost:8080>, sin parámetros: habla con el backend real (nginx entrega el frontend y reenvía `/api` al backend PHP: un solo origen). No hace falta instalar PHP ni PostgreSQL: la base corre en un contenedor y se prepara sola al arrancar (migraciones, permisos y datos iniciales); no se configura nada en pgAdmin. Guía completa, usuarios de prueba y solución de problemas: **`GUIA_EQUIPO.md`**.

Usuarios de desarrollo (los crea el backend al arrancar en local): `jefe.info`, `jefe.soporte`, `soporte.tecnico`, `soporte.informatica`, `docente`, `administrativo`; clave de todos: `dev-clave-12345`.

## Mapa

```
Caso A/
├── frontend/                      ← lo que ve el navegador (HTML, CSS, JS)
│   ├── index.html                 ← la única página
│   ├── css/                       ← base.css (variables y estilos globales) · notify.css (notificaciones)
│   └── js/
│       ├── app.js · config.js     ← arranque · ?mocks= (sí/no), URL de la API
│       ├── mocks/                 ← backend falso para trabajar sin backend (solo localhost; se puede borrar entera)
│       ├── nucleo/                ← router, sesion, http, ui, notify (se toca avisando al grupo)
│       └── modulos/
│           ├── index.js           ← lista de módulos del frontend
│           └── <modulo>/          ← <modulo>.js, <modulo>.css, <modulo>.api.js (opcional)
├── backend/                       ← la API en PHP
│   ├── public/index.php           ← puerta de entrada: todo /api pasa por aquí
│   ├── nucleo/                    ← PDO, Reloj, permisos, idempotencia (se toca con 2 aprobaciones)
│   ├── modulos/                   ← index.php (lista de módulos) y <modulo>/ con rutas.php, Controlador, Servicio, Repositorio
│   └── storage/                   ← lo que PHP escribe al funcionar: sesiones, logs, respaldos (no se sube a git)
├── database/                      ← PostgreSQL: migraciones/ (esquema, numeradas) · grants.sql (permisos) · seed/ (datos iniciales) · dev/ (solo desarrollo)
├── docs/api/                      ← un contrato por módulo, con la lista de sus rutas; se escribe antes de programar
├── tests/                         ← pruebas automáticas de las reglas de negocio (oraculo_acciones.json y matriz.json, escritos a mano)
├── bin/                           ← scripts de terminal, no del navegador:
│                                    migrar (la base; lo corre Docker solo) · usuarios (crear usuarios) ·
│                                    importar-equipos (carga de equipos y docentes) · respaldo ·
│                                    router-dev (servidor sin Docker) · verificar.sh (revisa las reglas antes de subir)
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

`<modulo>` = nombre en minúscula y sin espacios (`reportes`). El ejemplo para copiar es `plantilla` (frontend, backend y mock); solo existe en local.

### A. Solo la parte visual (frontend + mocks, sin backend)

1. **Prefijo CSS:** agrega una línea con 2 letras exclusivas en la lista de `frontend/css/base.css` (los módulos ya previstos la tienen reservada).
2. **Módulo:** copia `frontend/js/modulos/plantilla/` a `frontend/js/modulos/<modulo>/` y renombra `plantilla.js`, `.css` y `.api.js` a `<modulo>.*`. Dentro, cambia `id`, `titulo`, `permiso`, `menu`, `rutas`, las vistas y el prefijo `pl-`.
3. **Registro:** una línea en `frontend/js/modulos/index.js`.
4. **Mock:** copia `frontend/js/mocks/handlers/plantilla.js` a `<modulo>.js` y pon las rutas y la forma de datos del contrato. Los datos iniciales van en `mocks/semilla.js` con su `get` en `mocks/bd.js` (detalle en `mocks/index.js`). Registra el handler como indica `mocks/index.js`.
5. **Probar:** abre `http://localhost:8000/?mocks=1` (ver «Arranque rápido»). El `.api.js` no sabe que hay mocks: llama a `/api` como siempre.

### B. Completo (frontend + backend + base de datos)

Haz los pasos 1 a 3 de A y además:

1. **Contrato primero:** copia `docs/api/_plantilla.md` a `docs/api/<modulo>.md`, escribe las rutas y acuérdalo con el equipo antes de programar.
2. **Base de datos:** tablas nuevas en `database/migraciones/NNNN_<nombre>.sql` (el número lo asigna el líder; una migración aplicada no se edita) y sus permisos en `database/grants.sql`. Si agregas un `CHECK (col IN (...))`, su lista va en `backend/nucleo/Dominio.php` y en `LISTAS_CHECK` de `tests/dominio_test.php`.
3. **Backend:** copia `backend/modulos/plantilla/` a `backend/modulos/<modulo>/` (si la carpeta ya existe con un `rutas.php` vacío, como `autorizaciones`, llénala en vez de copiar). Cambia el namespace a `Modulos\<Nombre>` (en `Controlador`, `Servicio`, `Repositorio` y en el `use` de `rutas.php`) y escribe: `rutas.php` (URL + permiso), `Controlador` (solo HTTP), `Servicio` (reglas) y `Repositorio` (único con SQL). Agrégalo en `backend/modulos/index.php` si no está.
4. **Permiso nuevo (si hace falta):** en `Dominio.php` y en `frontend/js/nucleo/dominio.js` (`PERMISO` y por rol), y en `tests/matriz.json`.
5. **Conectar:** el `.api.js` llama a las rutas del contrato (con `seg()` para los ids). Mantén el mock con la misma forma, o abre la página sin `?mocks=` (o con `?mocks=0`) para usar el backend real.
6. **Antes de subir:** `docker compose exec backend bash bin/verificar.sh` y `docker compose exec backend php tests/ejecutar.php --con-bd`.

## Reglas del frontend

`bin/verificar.sh` comprueba los imports (F1), que `fetch` solo esté en `nucleo/http.js` (F2), el HTML seguro (F4) y los módulos CSS sin colores a mano (F10).

**Pantallas con reglas propias**

- Duplicado sobre una incidencia Resuelta: ofrece "Sigue fallando" o "Es otro problema" (RF-03, RN-06).
- Docente al elegir equipo: selects en cascada sede → ubicación → tipo → equipo; muestra código, ubicación y tipo, nunca criticidad (RF-01, §4).
- Confirmación: el texto depende de `resultado`; con `no_reparable` no dice "confirma que el equipo funciona" (RN-06).
- Historial del equipo: fecha, falla, solución y técnico (RF-09); enlace al detalle solo si la incidencia está en el ámbito de quien consulta.
- Indicadores: cada pantalla muestra "corte: fecha y hora" (RF-11, RN-10).

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

## Reglas del backend

**Tiempo**
- Hora de Lima en un solo lugar del núcleo: `SET TIME ZONE 'America/Lima'` al conectar y `date_default_timezone_set('America/Lima')` (RN-10). **[CI]** test en 23:59:59 del último día del mes.
- Toda fecha entra por la clase `Reloj`. `now()`, `current_timestamp` y `DEFAULT now()` (y similares) están prohibidos. **[CI]** grep.
- Hábiles = lunes a sábado (§2, RN-06). **[CI]** test de tabla: sábado, domingo y feriado.
- La tabla `feriado` debe cubrir hasta hoy + 60 días (`Habiles::MARGEN_CALENDARIO_DIAS`). Se carga en `database/seed/0001_catalogos.sql`. **[CI]** `tests/reloj_habiles_test.php` falla si no, y `Habiles::feriados()` deja un aviso en el log.

**Escritura**
- Toda transición empieza con `SELECT … FOR UPDATE`; la clave de idempotencia se comprueba antes que el duplicado (RF-03, RF-06, RF-07).
- En PostgreSQL `rowCount()` de un UPDATE ya cuenta las filas que cumplen el `WHERE`: 0 filas = conflicto real (409). No hace falta nada especial. **[CI]** `tests/humo_evento_test.php`.
- Todo POST que inserta escribe `idempotencia(usuario_id, clave, accion, recurso_id)` con `UNIQUE(usuario_id, clave)` en la misma transacción; el reintento relee el recurso (RNF-07).
- Una sola función calcula y ejecuta las acciones permitidas (rol × relación × estado, §4 y §5). **[CI]** se compara con `tests/oraculo_acciones.json`, escrito a mano por QA desde §4 y §5 y revisado por otra persona; nunca generado desde el código (RN-09, RF-12).

**Datos**
- `evento` no se modifica (RN-07, RNF-06): GRANT por tabla en `database/grants.sql` versionado, sin triggers. **[CI]** humo: un `UPDATE` sobre `evento` con el usuario de la app debe dar error.
- `evento` lleva columnas tipadas (`incidencia_id`, `accion`, `estado_antes`, `estado_despues`, `fecha`); lo que un indicador filtre nunca va en el JSON `datos`.
- `incidencia.sede_id` se copia al registrar y no cambia: el reporte de marzo no varía si el equipo cambia de sede en abril (RF-11).
- `ubicacion(id, sede_id, nombre)` con FK; no hay ubicación en texto libre (RF-04, RF-09). Se decide antes de congelar el esquema.
- Invariante: estado ∈ {asignada, en_atencion, en_espera, resuelta} ⇒ `tecnico_id` NOT NULL (RN-02).

**Indicadores (RF-11, RN-10)**
- Columnas para el estado actual (listas y colas); eventos para el corte. **[CI]** test de invariantes: ambos coinciden.
- Estado al corte con subconsulta correlacionada ordenada por (`fecha`, `id`); con PostgreSQL se puede usar `DISTINCT ON (incidencia_id) … ORDER BY incidencia_id, fecha DESC, id DESC`.
- Tiempo de atención: una muestra por incidencia, su primer `resolver`; el mes es el de ese evento.
- Un mes cerrado fija el fin de mes; el mes en curso corta en "ahora".
- **[CI]** El dataset de oro se genera con los servicios reales y un `Reloj` controlado, no con `INSERT`.

**Visibilidad (RN-09, RF-09)**
- Tres serializadores; el tercero, `historial_item`, trae exactamente fecha, falla, solución y técnico, sin reportante ni vinculados.

**Flujo**
- Cierre sin confirmación (RN-06, D9): lo hace el jefe de soporte a los 3 hábiles desde `max(fecha_resuelta, último vínculo)`.
- Duplicado sobre una Resuelta: "Sigue fallando" = `vincular_reporte` + `rechazar_solucion` en una transacción con dos eventos (RNF-06); "Es otro problema" = `confirmar_nueva`.
- Confirma solo el reportante titular; un vinculado solo puede rechazar (RN-06).
- Prioridad manual: el vínculo siempre genera evento. Si la prioridad calculada supera a la manual, la incidencia entra en la cola del jefe de soporte con marca "revisar prioridad" hasta que la confirme o la cambie (RF-05, RN-04, D10).
- `desactivar_usuario` responde `409` con la lista de incidencias abiertas del técnico (RF-12, RN-02).
- `no_reparable`: `dar_de_baja` (jefe de soporte, motivo, evento) pone `equipo.activo=0` (RF-09). **[S]**
- "Fuera de servicio" **no se guarda**: se deriva (equipo activo con al menos una incidencia abierta), así que un equipo dado de baja sale de la lista (RF-09).

**Login (RNF-01)**
- Límite de intentos por (usuario, IP), no por usuario solo; fallos en log y desbloqueo manual. Se acepta y documenta: un atacante interno en la misma IP puede bloquear al jefe.
- Con `debe_cambiar_clave` solo se admite cambiar la clave y logout.

**Carga inicial (D11)**
- Sin pantalla de carga: `bin/importar-equipos.php` con `--dry-run`, informe de filas rechazadas y código normalizado. Lo mismo para docentes, con clave temporal aleatoria y `debe_cambiar_clave`.
- El docente no recibe RF-04 (§4): usa el CSV cargado y, si falta algo, soporte registra por RF-02.
- Requerimientos de salida: el CSV de equipos y el código rotulado en cada equipo; sin rótulo los reportes caen en el equipo equivocado y se contamina el historial. **[P]** confirmar con el cliente.
- Seed: los 10 equipos fuera de servicio entran como 10 incidencias `registrada` del usuario de sistema `carga_inicial` (sin credenciales), canal `sistema`, por el flujo normal. Inflan Pendientes desde la fecha de carga y se cierran sin confirmación (RN-06); documentarlo.