<!-- docs/api/catalogos.md -->
# API de `catalogos`

Estado: `GET /sedes`, `GET /ubicaciones`, `POST /ubicaciones` y `PATCH /ubicaciones/{id}` implementados (backend/modulos/catalogos)
Requisitos: RF-01, RF-04, RF-09
Permisos del catálogo: `equipo.elegir`, `equipo.gestionar` (las sedes solo piden sesión); `sede.todas` (solo la usa el frontend)

Catálogos que usan todos los módulos y que no dependen de ninguno: las sedes y las ubicaciones. Las URLs de `/ubicaciones` son las
mismas de siempre (antes las servía `equipos`: el contrato no cambió). Los demás módulos no leen las tablas `sede` ni `ubicacion`:
llaman al Servicio de `catalogos` (`exigirSede`, `ubicacion`, `nombresDeUbicaciones`).

Esta tabla es la única lista de rutas del módulo (`rutas.php` solo enlaza aquí).

| Método y ruta | Permiso | Idempotency-Key | Notas |
|---|---|---|---|
| `GET /sedes` | solo sesión | no | Todas las sedes. Lo usa `inicio` (ver abajo). |
| `GET /ubicaciones?sede=` | solo sesión; el servicio exige `equipo.elegir` o `equipo.gestionar` | no | Catálogo de ubicaciones (selects en cascada y alta de equipos). |
| `POST /ubicaciones` | `equipo.gestionar` | sí | Crear una ubicación (catálogo; nunca texto libre). Queda en auditoría. |
| `PATCH /ubicaciones/{id}` | `equipo.gestionar` | no | Renombrar. La sede no cambia. Queda en auditoría. |

## `GET /api/sedes`

| | |
|---|---|
| Permiso | solo sesión (`permiso: null`) |
| Idempotency-Key | no |
| Éxito | `200` |

Todos los roles entran a Inicio, así que no se pide `equipo.elegir` (el jefe de informática no lo tiene).

**Response**: todas las sedes, ordenadas por nombre.

```json
{ "data": [ { "id": 1, "nombre": "Sede 1" }, { "id": 2, "nombre": "Sede 2" } ] }
```

**Ámbito**: todos los roles ven las dos sedes (un docente puede dictar en ambas). `usuario.sede_id` es solo la sede
por defecto (preseleccionada) y puede estar vacía; no restringe nada.

- La sede es un **filtro, no una frontera de permisos** (D-A): las listas aceptan `?sede=` opcional y "todas" = no enviarlo.
- Inicio solo pregunta la sede a quien trabaja entre sedes: `sede.todas` (los jefes) o `incidencia.atender` (los técnicos).
  Con 2 o más sedes → tarjetas para elegir; con 1 → entra directo. Docente y administrativo entran directo, sin sede:
  la sede de su reporte se deduce del equipo.
- Quien tiene `sede.todas` (los jefes) puede además elegir **Todas las sedes** (`#/?sede=todas`) y cambiar entre
  Todas / Sede 1 / Sede 2 desde el panel. Es solo una vista del frontend: la API no cambia y `todas` nunca se envía al backend.
- La sede elegida en Inicio no se guarda globalmente: cada pantalla trae su filtro de sede.

**Errores**

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |

## `GET /api/ubicaciones`

| | |
|---|---|
| Permiso | solo sesión (`permiso: null`); el Servicio exige `equipo.gestionar` o `equipo.elegir` |
| Idempotency-Key | no |
| Éxito | `200` |

El jefe de informática gestiona equipos pero no tiene `equipo.elegir`, así que la ruta no puede exigir uno solo de los
dos permisos. El Servicio decide; sin ninguno de los dos, `403`.

**Query**: `?sede=` (opcional; sin ella, las ubicaciones de todas las sedes). Ordenadas por nombre.

```json
{ "data": [ { "id": 3, "sede_id": 1, "nombre": "Laboratorio 2" } ] }
```

**Errores**

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol no tiene `equipo.elegir` ni `equipo.gestionar` | |
| 422 | `sede` no es un número entero | `error.campos = { sede: "texto" }` |

## `POST /api/ubicaciones` y `PATCH /api/ubicaciones/{id}`

| | `POST /ubicaciones` | `PATCH /ubicaciones/{id}` |
|---|---|---|
| Permiso | `equipo.gestionar` | `equipo.gestionar` |
| Idempotency-Key | sí | no |
| Éxito | `201` | `200` |

Las ubicaciones son un catálogo: no hay ubicación en texto libre (RF-04, RF-09). No tienen un permiso propio: las crea y las
renombra cualquiera con `equipo.gestionar`.

**Request** (`POST`)

```json
{ "sede_id": 1, "nombre": "Laboratorio 2" }
```

**Request** (`PATCH`): solo el nombre. La sede de una ubicación no se cambia (sus equipos dependen de ella).

```json
{ "nombre": "Laboratorio 2B" }
```

**Response**: la ubicación, con la misma forma que en `GET /ubicaciones`.

```json
{ "data": { "id": 3, "sede_id": 1, "nombre": "Laboratorio 2" } }
```

- `nombre`: obligatorio, máximo 80 caracteres. Se limpian los espacios sobrantes (`"  Lab   2 "` queda `"Lab 2"`); las mayúsculas y
  tildes se respetan como se escribieron.
- Dentro de una sede, el nombre no se repite **sin importar las mayúsculas** (`Lab 2` y `lab 2` son la misma ubicación). Cambiar
  solo las mayúsculas de la propia ubicación sí se permite.
- Reintento del `POST` con la misma `Idempotency-Key`: devuelve la misma ubicación, sin duplicar.
- `PATCH` con el mismo nombre que ya tiene: `200` con la ubicación actual; no escribe ni deja rastro.
- Auditoría (`entidad = ubicacion`): `crear` (`sede_id`, `nombre`) y `renombrar` (`antes`, `despues`).

**Errores**

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol no tiene `equipo.gestionar` | |
| 404 | `PATCH`: la ubicación no existe | |
| 422 | campo ausente o con formato inválido; la sede no existe; el nombre ya existe en esa sede | `error.campos = { campo: "texto" }` |