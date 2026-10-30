<!-- docs/api/_plantilla.md -->
# API de `<modulo>`

> Contrato primero (P1): este archivo se escribe y se aprueba ANTES de codificar. El mock del frontend
> y el backend salen de aquí. Cambiar un contrato exige el mismo PR en ambos lados.

Estado: borrador | acordado | implementado
Requisitos: RF-xx, RN-xx

## `METODO /api/ruta`

| | |
|---|---|
| Permiso | `dominio.permiso` (o "solo sesión") |
| Idempotency-Key | sí / no (todo POST que inserta: sí) |
| Éxito | `200` / `201` |

**Request**

```json
{ "campo": "valor" }
```

**Response**

```json
{ "data": { } }
```

**Errores**

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | rol sin permiso / acción no permitida | |
| 404 | no existe o fuera de ámbito (RN-09) | |
| 409 | transición inválida o ya hecha | lleva `data` con el recurso actual |
| 422 | validación | `error.campos = { campo: "texto" }` |
