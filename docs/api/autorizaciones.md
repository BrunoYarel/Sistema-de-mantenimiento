<!-- docs/api/autorizaciones.md -->
# API de `autorizaciones`

Estado: borrador completo, pendiente de aprobación del líder (P1); sin implementar
Requisitos: RF-10, RN-05, RN-09
Permisos del catálogo: `autorizacion.solicitar`, `autorizacion.decidir`
Qué debe lograr el módulo: `docs/funcionalidades/autorizaciones.md`.

Esta tabla es la única lista de rutas del módulo (`rutas.php` solo enlaza aquí).

| Método y ruta | Permiso | Idempotency-Key | Éxito | Notas |
|---|---|---|---|---|
| `POST /incidencias/{id}/autorizaciones` | `autorizacion.solicitar` | sí | `201` | Solicitar. Deja la incidencia En espera. |
| `GET /autorizaciones?estado=&incidencia=&pagina=` | solo sesión; el servicio exige `autorizacion.solicitar` o `autorizacion.decidir` | no | `200` | Lista. Con `autorizacion.decidir`, todas; con solo `solicitar`, las que pidió esa persona. |
| `GET /autorizaciones/{id}` | igual que la lista | no | `200` | Una autorización (la incidencia enlaza aquí). |
| `POST /autorizaciones/{id}/decision` | `autorizacion.decidir` | no | `200` | Aprobar o rechazar con motivo. Cambio de estado, no alta: repetirlo da `409`. |

El módulo no escribe la tabla `incidencia`: mueve la incidencia a En espera / En atención llamando al **Servicio** de `incidencias` (B2), dentro de
la misma transacción. Por eso `incidencias` no conoce a `autorizaciones`: la pantalla de una incidencia busca su autorización con
`GET /autorizaciones?incidencia={id}`.

Los códigos comunes (`401`, `403`, `404`, `409`, `422`) y su cuerpo son los de `docs/api/_plantilla.md`.

## Forma de una autorización

```json
{ "id": 7, "incidencia_id": 42, "equipo": "PC-01", "tipo": "compra", "justificacion": "Fuente de poder dañada",
  "solicitante": "Marta Ruiz", "estado": "pendiente",
  "decidido_por": null, "motivo": null,
  "fecha_solicitud": "2026-10-27T08:40:00-05:00", "fecha_decision": null }
```

- `tipo`: uno de `Dominio::TIPOS_AUTORIZACION` (`reparacion_externa`, `compra`, `reemplazo`).
- `estado`: uno de `Dominio::ESTADOS_AUTORIZACION` (`pendiente`, `aprobada`, `rechazada`).
- `decidido_por`, `motivo` y `fecha_decision` son `null` mientras esté pendiente y siempre vienen completos al decidir.
- `equipo` es el código; el módulo lo pide al Servicio de `incidencias`.

## `POST /api/incidencias/{id}/autorizaciones`

**Request**

```json
{ "tipo": "compra", "justificacion": "Fuente de poder dañada" }
```

**Response** `201`: la autorización. La incidencia queda En espera y se registra su evento (RNF-06).

- Quién puede: el técnico asignado o el jefe de soporte, con la incidencia En atención (`acciones.php`, acción `solicitar_autorizacion`).
- Una incidencia tiene a lo sumo una autorización pendiente.
- Reintento con la misma `Idempotency-Key`: devuelve la misma autorización, sin duplicar (RNF-07).

| Código | Cuándo | Cuerpo |
|---|---|---|
| 403 | el rol no tiene `autorizacion.solicitar`, o es un técnico que no es el asignado | `error.codigo = accion_no_permitida` |
| 404 | la incidencia no existe o está fuera del ámbito (RN-09) | `error.codigo = no_encontrado` |
| 409 | la incidencia no está En atención, o ya tiene una pendiente | `transicion_invalida` con `data` = la incidencia actual |
| 422 | `tipo` desconocido; `justificacion` vacía; `Idempotency-Key` ausente | `error.campos = { campo: "texto" }` |

## `GET /api/autorizaciones` y `GET /api/autorizaciones/{id}`

- **Lista:** filtros opcionales `estado`, `incidencia` (id) y `pagina` (de 25 en 25, desde 1). Orden: pendientes primero y, dentro de cada grupo, la más antigua primero.
- **Response de la lista:** `{ "data": { "items": [ ...autorizaciones ], "total": 3, "pagina": 1, "por_pagina": 25 } }`
- **Ámbito (RN-09):** el jefe de informática ve todas; quien solicita ve solo las suyas. Una autorización ajena responde `404`.

| Código | Cuándo | Cuerpo |
|---|---|---|
| 403 | el rol no tiene `autorizacion.solicitar` ni `autorizacion.decidir` (docente, administrativo) | |
| 404 | detalle: no existe, `id` no es un número o es ajena | `error.codigo = no_encontrado` |
| 422 | `estado` desconocido; `pagina` fuera de 1..10000 | `error.campos = { campo: "texto" }` |

## `POST /api/autorizaciones/{id}/decision`

**Request**

```json
{ "decision": "aprobada", "motivo": "Presupuesto disponible" }
```

**Response** `200`: la autorización ya decidida. La incidencia vuelve a En atención (también al rechazar [P], ver `docs/funcionalidades/autorizaciones.md`) y se registra su evento.

- `decision`: `aprobada` o `rechazada`. `motivo` es obligatorio en ambos casos (máximo 300 caracteres).
- Solo el jefe de informática.
- Se responde una sola vez: si ya estaba respondida, `409` y la pantalla dice «ya estaba respondida», no «error».

| Código | Cuándo | Cuerpo |
|---|---|---|
| 403 | el rol no tiene `autorizacion.decidir` | |
| 404 | la autorización no existe (o `id` no es un número) | `error.codigo = no_encontrado` |
| 409 | ya estaba respondida, o la incidencia ya no está En espera | `transicion_invalida` con `data` = la autorización actual |
| 422 | `decision` distinta de `aprobada`/`rechazada`; `motivo` en blanco o de más de 300 caracteres | `error.campos = { campo: "texto" }` |
