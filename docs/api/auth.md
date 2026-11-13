<!-- docs/api/auth.md -->
# API de `auth`

Estado: implementado (backend/modulos/auth)
Requisitos: RNF-01, RN-09

Sesión por cookie `CASOA_SID` (HttpOnly, SameSite=Strict). Todo es del mismo origen, bajo `/api`.
Fechas en hora de Lima (`-05:00`). Respuesta correcta: `{ "data": ... }`. Error: `{ "error": { "codigo", "mensaje", "campos" } }`.

## `GET /api/me`

Permiso: solo sesión. Permitido aunque `debe_cambiar_clave` sea verdadero.

```json
{ "data": { "id": 7, "nombre": "Ana Pérez", "rol": "jefe_soporte", "permisos": ["incidencia.ver"], "debe_cambiar_clave": false } }
```

Errores: `401` sin sesión.

## `POST /api/auth/login`

Sin sesión. Límite de intentos por (usuario, IP). Las credenciales inválidas responden igual exista o no el usuario.

```json
{ "usuario": "aperez", "clave": "..." }
```

Éxito `200`: lo mismo que `/me`. Errores:

| Código | Cuándo |
|---|---|
| 401 | `credenciales_invalidas` (único caso en que un 401 no significa "sin sesión") |
| 422 | faltan campos |
| 429 | bloqueado: cabecera `Retry-After` y `error.espera_segundos` |

## `POST /api/auth/logout`

Siempre `200 { "data": null }`, haya o no sesión.

## `POST /api/auth/clave`

Con `debe_cambiar_clave` es lo único permitido, junto con `/me` y logout.

```json
{ "clave_actual": "...", "clave_nueva": "..." }
```

Éxito `200`: lo mismo que `/me`, con `debe_cambiar_clave: false` y sesión nueva.

| Código | Cuándo |
|---|---|
| 422 | `campos.clave_actual` incorrecta, o `campos.clave_nueva` de menos de 10 caracteres o igual a la actual |

Una clave actual incorrecta NUNCA es 401 (B6): el frontend lo tomaría como sesión vencida.
