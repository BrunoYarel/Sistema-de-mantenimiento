<!-- docs/api/equipos.md -->
# API de `equipos`

Estado: `GET /equipos`, `GET /equipos/{id}`, `POST /equipos`, `PATCH /equipos/{id}`, `POST /equipos/{id}/baja`, `POST /equipos/{id}/reactivar`, `GET /equipos/{id}/historial` y `GET /equipos/fuera-de-servicio` implementados (backend/modulos/equipos)
Requisitos: RF-01, RF-04, RF-09
Permisos del catálogo: `equipo.elegir`, `equipo.gestionar`, `equipo.historial`, `equipo.dar_de_baja`

`/sedes` y `/ubicaciones` (alta, renombre y lista) **no son de este módulo**: viven en `catalogos` (`docs/api/catalogos.md`). Las URLs no
cambiaron. Aquí `equipos` solo las consulta por el Servicio de `catalogos` (B2), nunca por su tabla.

**Rutas que se mudarán a `incidencias`.** `GET /equipos/{id}/historial` y `GET /equipos/fuera-de-servicio` (RF-09) leen `incidencia` y
`atencion`, que no son de este módulo. Cuando se construya `incidencias` pasan a ese módulo **con la misma URL, el mismo permiso y
el mismo contrato** (se anotan en `docs/api/incidencias.md`). Hasta entonces siguen aquí. El frontend no cambia: solo ve la URL.

Esta tabla es la única lista de rutas del módulo (`rutas.php` solo enlaza aquí). Antes de codificar, completa cada
endpoint con el formato de `docs/api/_plantilla.md` (request, response, errores y permiso).

| Método y ruta | Permiso | Idempotency-Key | Notas |
|---|---|---|---|
| `GET /equipos?sede=&ubicacion=&tipo=&q=&activo=&pagina=` | solo sesión; el servicio exige `equipo.elegir` o `equipo.gestionar` | no | Búsqueda y selects en cascada (RF-01, RF-04). Con `equipo.gestionar`, el equipo completo; con solo `equipo.elegir`, sin criticidad. |
| `GET /equipos/{id}` | `equipo.gestionar` | no | Detalle con criticidad. |
| `POST /equipos` | `equipo.gestionar` | sí | Registrar equipo (RF-04). Queda en auditoría. |
| `PATCH /equipos/{id}` | `equipo.gestionar` | no | Editar tipo, ubicación y criticidad; el código no se edita. Un registro de auditoría por cambio. |
| `GET /equipos/{id}/historial?pagina=` | `equipo.historial` | no | **Se muda a `incidencias`** (misma URL). Serializador `historial_item`: fecha, falla, solución, técnico (RF-09). De 25 en 25. |
| `GET /equipos/fuera-de-servicio?sede=&ubicacion=&tipo=&pagina=` | `equipo.historial` | no | **Se muda a `incidencias`** (misma URL). Dato derivado, no guardado: equipo activo con al menos una incidencia abierta (RF-09). De 25 en 25. |
| `POST /equipos/{id}/baja` | `equipo.dar_de_baja` | no | `activo = false` con motivo obligatorio. Queda en auditoría. No se borra nada (RN-07). |
| `POST /equipos/{id}/reactivar` | `equipo.dar_de_baja` | no | Vuelve a `activo = true`. Motivo opcional. Queda en auditoría. |

## `GET /api/equipos`

| | |
|---|---|
| Permiso | solo sesión (`permiso: null`); el Servicio exige `equipo.gestionar` o `equipo.elegir` |
| Idempotency-Key | no |
| Éxito | `200` |

El jefe de informática gestiona equipos pero no tiene `equipo.elegir`, así que la ruta no puede exigir uno solo de los
dos permisos. El Servicio decide qué ve cada quien; sin ninguno de los dos, `403`.

Filtros, todos opcionales: `sede`, `ubicacion`, `tipo` (uno de `Dominio::TIPOS_EQUIPO`), `q` (parte del código; se normaliza
igual que al registrarlo: `pc 01` busca `PC-01`), `activo` (`true` o `false`; solo con `equipo.gestionar`), `pagina`.
Ordenados por código.

- **Con `ubicacion`** (selector en cascada): devuelve TODOS los de esa ubicación, sin paginar.
- **Sin `ubicacion`** (búsqueda y gestión): pagina de 25 en 25 (`pagina`, desde 1).
- Quien solo tiene `equipo.elegir` ve únicamente equipos activos y sin criticidad. Con `equipo.gestionar` se ven también
  los dados de baja (`activo: false`).

**Response** con `equipo.gestionar`:

```json
{ "data": { "items": [ { "id": 10, "codigo": "PC-01", "tipo": "computadora", "ubicacion_id": 3, "ubicacion": "Laboratorio 2",
                         "sede_id": 1, "criticidad": "alta", "activo": true } ],
            "total": 140, "pagina": 1, "por_pagina": 25 } }
```

**Response** con solo `equipo.elegir`: cada item trae `id`, `codigo`, `tipo`, `ubicacion_id` y `ubicacion`.
Con `ubicacion`, la respuesta lleva `items` y `total` (sin `pagina` ni `por_pagina`).

**Errores**

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol no tiene `equipo.elegir` ni `equipo.gestionar` | |
| 422 | filtro con formato inválido (`tipo` desconocido, `pagina` fuera de 1..10000, número que no es entero) | `error.campos = { campo: "texto" }` |

## `GET /api/equipos/{id}`, `POST /api/equipos` y `PATCH /api/equipos/{id}`

| | `GET /equipos/{id}` | `POST /equipos` | `PATCH /equipos/{id}` |
|---|---|---|---|
| Permiso | `equipo.gestionar` | `equipo.gestionar` | `equipo.gestionar` |
| Idempotency-Key | no | sí | no |
| Éxito | `200` | `201` | `200` |

Las tres devuelven el equipo completo (la misma forma que cada item de `GET /equipos` con `equipo.gestionar`):

```json
{ "data": { "id": 10, "codigo": "PC-01", "tipo": "computadora", "ubicacion_id": 3, "ubicacion": "Laboratorio 2",
            "sede_id": 1, "criticidad": "alta", "activo": true } }
```

### `POST /equipos`

```json
{ "codigo": "pc 01", "tipo": "computadora", "ubicacion_id": 3, "criticidad": "alta" }
```

- `codigo`: obligatorio, máximo 50 caracteres. Se normaliza con `Servicio::normalizarCodigo` (mayúsculas y espacios a guion):
  `pc 01` se guarda como `PC-01`. Es único.
- `tipo`: uno de `Dominio::TIPOS_EQUIPO`. `criticidad`: una de `Dominio::CRITICIDADES`.
- `ubicacion_id`: debe existir (catálogo de `catalogos`, ver `docs/api/catalogos.md`). **La sede no se envía**: es la de la ubicación.
- Nace `activo: true`.
- Reintento con la misma `Idempotency-Key`: devuelve el mismo equipo, sin duplicar (RNF-07). La clave se comprueba antes que el
  código repetido.
- Auditoría (`entidad = equipo`): `crear`.

### `PATCH /equipos/{id}`

```json
{ "criticidad": "media", "ubicacion_id": 5 }
```

- Solo se leen `tipo`, `ubicacion_id` y `criticidad`, y solo cambia lo que llega. Debe llegar al menos uno (si no, `422`).
- **El código no se edita.** Si `codigo` viene en el cuerpo, debe ser el actual (se compara ya normalizado); si es otro, `422`.
  Cualquier otro campo del cuerpo (`id`, `sede_id`, `activo`...) se ignora: la sede sigue a la ubicación y la baja y la
  reactivación tienen sus propias rutas.
- **Cambiar la ubicación cambia también la sede del equipo.** Las incidencias ya registradas conservan la suya (RF-11).
- Lo que llega igual que está no escribe nada: `200` con el equipo actual y sin registro de auditoría.
- Un equipo dado de baja (`activo: false`) también se puede editar.
- Auditoría (`entidad = equipo`), **un registro por cada cambio real**: `cambiar_tipo` y `cambiar_criticidad` (`antes`, `despues`),
  y `cambiar_ubicacion` (`antes` y `despues`, cada uno con `ubicacion_id` y `sede_id`). La criticidad mueve la prioridad
  (RN-04), por eso no se cambia sin rastro.
- Toda edición: `SELECT ... FOR UPDATE`, cambio y auditoría en una sola transacción.

**Errores** (las tres rutas)

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol no tiene `equipo.gestionar` | |
| 404 | el equipo no existe (o `id` no es un número) | |
| 422 | campo ausente o con formato inválido; `ubicacion_id` no existe; el código ya existe; `PATCH` sin nada que cambiar o con otro `codigo` | `error.campos = { campo: "texto" }` |
| 409 | `PATCH`: otra persona estaba editando el mismo equipo y no hubo forma de aplicar el cambio | `error.codigo = conflicto` u `ocupado` |

## `POST /api/equipos/{id}/baja` y `POST /api/equipos/{id}/reactivar`

| | `POST /equipos/{id}/baja` | `POST /equipos/{id}/reactivar` |
|---|---|---|
| Permiso | `equipo.dar_de_baja` (solo el jefe de soporte) | `equipo.dar_de_baja` |
| Idempotency-Key | no | no |
| Éxito | `200` | `200` |

Son cambios de estado, no altas: no llevan `Idempotency-Key`. Repetirlos no hace daño: da `409` y no deja registro.
Nada se borra (RN-07): el equipo, su historial y sus incidencias siguen donde estaban.

**Request** (`baja`): el motivo es obligatorio, máximo 300 caracteres. Se limpian los espacios sobrantes.

```json
{ "motivo": "No reparable: placa madre quemada" }
```

**Request** (`reactivar`): el motivo es opcional. Sin cuerpo también vale.

```json
{ "motivo": "Reparado por el proveedor" }
```

**Response**: el equipo completo, con la misma forma que `GET /equipos/{id}` (`activo` ya con el valor nuevo).

- **Baja**: `activo = false`. El equipo deja de poder elegirse al registrar una incidencia (quien solo tiene `equipo.elegir` no lo ve),
  pero quien tiene `equipo.gestionar` lo sigue viendo en `GET /equipos` con `activo: false` (o filtrando por `activo=false`) y puede editarlo.
- **Reactivar**: `activo = true`. Vuelve a poder elegirse.
- Auditoría (`entidad = equipo`): `dar_de_baja` (con `motivo`) y `reactivar` (con `motivo` si llegó, si no `NULL`).
- Toda baja o reactivación: `SELECT ... FOR UPDATE`, cambio y auditoría en una sola transacción.
- Cuando exista el módulo incidencias, su acción `dar_de_baja` (incidencia Resuelta con resultado `no_reparable`) llama a
  `Servicio::darDeBaja` dentro de su propia transacción y suma su evento; esta ruta no cambia.

**Errores**

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol no tiene `equipo.dar_de_baja` | |
| 404 | el equipo no existe (o `id` no es un número) | |
| 409 | `baja`: el equipo ya estaba dado de baja. `reactivar`: ya estaba activo. También si otra persona lo cambió a la vez | `error.codigo = transicion_invalida` (y `data` = el equipo actual: la pantalla dice "ya estaba hecho") o `conflicto` |
| 422 | `baja` sin motivo o con motivo en blanco; motivo de más de 300 caracteres | `error.campos = { motivo: "texto" }` |

## `GET /api/equipos/{id}/historial` y `GET /api/equipos/fuera-de-servicio`

| | `GET /equipos/{id}/historial` | `GET /equipos/fuera-de-servicio` |
|---|---|---|
| Permiso | `equipo.historial` | `equipo.historial` |
| Idempotency-Key | no | no |
| Éxito | `200` | `200` |

Los tienen los técnicos y los dos jefes (también el de informática); los docentes y administrativos no (`403`). Son solo
lectura: leen `incidencia` y `atencion` sin escribirlas (excepción de lectura de B2 en `backend/README.md`), y los nombres de las
personas los pide `equipos` al Servicio de `usuarios` (B2). `/equipos/fuera-de-servicio` no choca con `/equipos/{id}`: el Router da prioridad a las rutas fijas.

### `GET /equipos/{id}/historial?pagina=`

El historial de intervenciones (RF-09): **una entrada por incidencia**, la más reciente primero, de 25 en 25 (`pagina`, desde 1).
Un equipo dado de baja también tiene historial (RN-07).

```json
{ "data": { "items": [ { "fecha": "2026-10-27T08:40:00-05:00", "falla": "Se apaga sola", "solucion": null, "tecnico": null },
                        { "fecha": "2026-10-18T09:15:00-05:00", "falla": "No enciende", "solucion": "Cambió la fuente", "tecnico": "Marta Ruiz" } ],
            "total": 2, "pagina": 1, "por_pagina": 25 } }
```

- Cada entrada trae **exactamente** `fecha`, `falla`, `solucion` y `tecnico` (RN-09): sin reportante, registrador ni reportes vinculados.
- `fecha`: cuándo se **registró** la incidencia (no cuándo se resolvió). `falla`: su descripción.
- `solucion`: las acciones de la **última atención** de esa incidencia; `null` si todavía no hubo ninguna.
- `tecnico`: el nombre del técnico asignado (aunque ya esté desactivado); `null` si aún no tiene.

### `GET /equipos/fuera-de-servicio?sede=&ubicacion=&tipo=&pagina=`

Filtros todos opcionales (`tipo` es uno de `Dominio::TIPOS_EQUIPO`), de 25 en 25.

**Fuera de servicio no se guarda: se deriva.** Un equipo está fuera de servicio si está **activo** y tiene **al menos una incidencia
abierta** (`Dominio::ESTADOS_ABIERTOS`: registrada, asignada, en atención o en espera; una Resuelta o Cerrada ya no cuenta). Un equipo
dado de baja no figura: ya no se repara (decisión del líder sobre RF-09: «no reparados» = equipo activo con incidencia abierta; el
equipo dado de baja se consulta con `GET /equipos?activo=false`). Se ordena por el que lleva más tiempo primero (`desde`) y luego por código.

```json
{ "data": { "items": [ { "id": 10, "codigo": "PC-01", "tipo": "computadora", "ubicacion_id": 3, "ubicacion": "Laboratorio 2",
                         "sede_id": 1, "criticidad": "alta", "activo": true,
                         "incidencias_abiertas": 2, "desde": "2026-10-18T09:15:00-05:00" } ],
            "total": 10, "pagina": 1, "por_pagina": 25 } }
```

- Cada item es el equipo completo (como en `GET /equipos`) más `incidencias_abiertas` y `desde` (la fecha de registro de la más antigua abierta).
- La lista incluirá algún equipo que sigue andando pero tiene una incidencia menor. Si molesta, se agrega después un campo
  "inoperativo" a la incidencia.

**Errores** (las dos rutas)

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol no tiene `equipo.historial` | |
| 404 | `historial`: el equipo no existe (o `id` no es un número) | |
| 422 | `pagina` fuera de 1..10000 o filtro con formato inválido (`tipo` desconocido, número que no es entero) | `error.campos = { campo: "texto" }` |