<!-- docs/api/usuarios.md -->
# API de `usuarios`

Estado: implementado (backend/modulos/usuarios y frontend/js/modulos/usuarios), incluida la regla RN-02 (conectada a `Modulos\Incidencias\Servicio::abiertasDeTecnico`)
Requisitos: RF-12, RN-02, RN-07
Permisos del catálogo: `usuario.gestionar`

Esta tabla es la única lista de rutas del módulo (`rutas.php` solo enlaza aquí).

| Método y ruta | Permiso | Idempotency-Key | Notas |
|---|---|---|---|
| `GET /usuarios/tecnicos` | `incidencia.asignar` | no | Técnicos activos para asignar (lo usa el jefe de soporte). |
| `GET /usuarios/reportantes?q=` | `incidencia.registrar_a_nombre` | no | Docentes y administrativos activos, por nombre o usuario (autocompletado). |
| `GET /usuarios?q=&rol=&sede=&activo=&pagina=` | `usuario.gestionar` | no | Lista de gestión: activos e inactivos, de 25 en 25. |
| `GET /usuarios/{id}` | `usuario.gestionar` | no | Un usuario (lo usa la pantalla de edición). `404` si no existe o es de sistema. |
| `POST /usuarios` | `usuario.gestionar` | sí | Crear. Devuelve la clave temporal una sola vez. |
| `PATCH /usuarios/{id}` | `usuario.gestionar` | no | Editar nombre, rol y sede por defecto; el nombre de usuario no se edita. |
| `POST /usuarios/{id}/desactivar` | `usuario.gestionar` | no | No se borra (RN-07). Ver reglas abajo. |
| `POST /usuarios/{id}/reactivar` | `usuario.gestionar` | no | |
| `POST /usuarios/{id}/restablecer-clave` | `usuario.gestionar` | no | Otra clave temporal, mostrada una sola vez. |
| `POST /usuarios/{id}/desbloquear` | `usuario.gestionar` | no | Libera el bloqueo de login por intentos fallidos. |

Solo el alta inserta, así que solo ella lleva `Idempotency-Key`; las otras cuatro acciones son cambios de estado.
Todo lo que cambia algo deja su registro en `auditoria` (`entidad = usuario`) en la misma transacción (RN-07). Ninguna
respuesta ni registro de auditoría contiene un hash de clave; la clave temporal solo viaja en las dos respuestas que la crean.

## Lecturas para incidencias: `GET /api/usuarios/tecnicos` y `GET /api/usuarios/reportantes`

Los usan los formularios de incidencias. El módulo `incidencias` no lee la tabla `usuario`: llama a estas funciones del
Servicio de usuarios. Solo devuelven usuarios **activos**. Los roles salen de la matriz de permisos
(`Politica::rolesCon`): técnicos = quienes tienen `incidencia.atender`; reportantes = quienes tienen `incidencia.registrar_propia`.

| | `GET /usuarios/tecnicos` | `GET /usuarios/reportantes?q=` |
|---|---|---|
| Permiso | `incidencia.asignar` | `incidencia.registrar_a_nombre` |
| Idempotency-Key | no | no |
| Éxito | `200` | `200` |
| Quiénes | `soporte_tecnico` y `soporte_informatica` | `docente` y `admin` |
| Filtro | ninguno | `q` obligatorio, de 2 a 50 caracteres: busca en el nombre (sin tildes ni mayúsculas) y en el usuario |
| Orden y tope | por nombre | por nombre, máximo 20 |

**Response** (misma forma en las dos):

```json
{ "data": [ { "id": 7, "nombre": "Ana Pérez", "rol": "soporte_tecnico" } ] }
```

**Errores**

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol no tiene el permiso | |
| 422 | `q` ausente, de menos de 2 caracteres o de más de 50 (solo reportantes) | `error.campos = { q: "texto" }` |

## Gestión (RF-12)

Todas piden `usuario.gestionar` (hoy, solo el jefe de informática). Devuelven el usuario con esta forma, la misma en la lista
y en cada acción:

```json
{ "data": { "id": 7, "nombre": "Ana Pérez", "usuario": "aperez", "rol": "docente", "sede_id": 1,
            "activo": true, "debe_cambiar_clave": false, "bloqueado": false } }
```

- `sede_id` es la sede **por defecto** (preseleccionada) y puede ser `null`; no restringe nada (ver `docs/api/equipos.md`).
- `bloqueado`: el login de esa persona está bloqueado ahora por intentos fallidos (`POST /usuarios/{id}/desbloquear` lo libera).
- **Usuarios de sistema.** Un usuario sin credenciales (`clave_hash` vacío, p. ej. `carga_inicial`) no existe para esta gestión:
  no sale en la lista y cualquier acción sobre su id da `404`. Así nadie lo reactiva ni le pone una clave para entrar con él.

### `GET /usuarios?q=&rol=&sede=&activo=&pagina=`

Filtros, todos opcionales: `q` (hasta 50 caracteres; busca en el nombre sin tildes ni mayúsculas y en el usuario), `rol` (uno de
`Dominio::ROLES`), `sede` (id), `activo` (`true` o `false`), `pagina` (desde 1). Ordenados por nombre, de 25 en 25. Incluye los
desactivados (con `activo: false`), para poder reactivarlos.

```json
{ "data": { "items": [ { "id": 7, "nombre": "Ana Pérez", "usuario": "aperez", "rol": "docente", "sede_id": 1,
                         "activo": true, "debe_cambiar_clave": false, "bloqueado": false } ],
            "total": 58, "pagina": 1, "por_pagina": 25 } }
```

Errores: `401`, `403`, `422` (`rol` o `activo` con valor no permitido, `sede` que no es un entero, `pagina` fuera de 1..10000).

### `GET /usuarios/{id}`

Devuelve un usuario con la misma forma que la lista (`data` = el usuario, sin `clave_temporal`). Errores: `401`, `403`, `404`
(el usuario no existe, `id` no es un número o es un usuario de sistema).

### `POST /usuarios`

| | |
|---|---|
| Permiso | `usuario.gestionar` |
| Idempotency-Key | sí |
| Éxito | `201` |

```json
{ "nombre": "Ana Pérez", "usuario": "aperez", "rol": "docente", "sede_id": 1 }
```

- `nombre`: obligatorio, máximo 100. Se limpian los espacios sobrantes (`"  Ana   Pérez "` queda `"Ana Pérez"`).
- `usuario`: obligatorio, de 3 a 60 caracteres: letras **sin tildes**, números, punto, guion, guion bajo o `@` (se admite un correo).
  Se pasa a minúsculas (`Ana.Perez` se guarda `ana.perez`). Es único en toda la tabla.
- `rol`: uno de `Dominio::ROLES`. `sede_id`: opcional; si viene, debe existir.
- Nace `activo: true` y `debe_cambiar_clave: true`.
- **Clave temporal.** La respuesta trae la clave en `clave_temporal` (14 caracteres, letras y números), **una sola vez**: no se
  guarda en claro ni se registra. Quien la crea se la entrega a la persona, que debe cambiarla al entrar.

```json
{ "data": { "id": 7, "nombre": "Ana Pérez", "usuario": "aperez", "rol": "docente", "sede_id": 1,
            "activo": true, "debe_cambiar_clave": true, "bloqueado": false, "clave_temporal": "k3Jx9wQmT2vRzy" } }
```

- **Reintento con la misma `Idempotency-Key`** (falla de red): devuelve el mismo usuario, sin duplicar, con `clave_temporal` **vacía**
  (`""`). Si la respuesta original se perdió, se usa `POST /usuarios/{id}/restablecer-clave` para obtener una clave nueva.
- Auditoría: `crear` (`usuario`, `rol`, `sede_id`).

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol no tiene `usuario.gestionar` | |
| 422 | campo ausente o con formato inválido; el nombre de usuario ya existe; la sede no existe | `error.campos = { campo: "texto" }` |

### `PATCH /usuarios/{id}`

```json
{ "rol": "admin", "sede_id": null }
```

- Solo se leen `nombre`, `rol` y `sede_id`, y solo cambia lo que llega. Debe llegar al menos uno (si no, `422`). Un campo que llega
  vacío (`"nombre": ""`) es un error `422`, no "no cambiar".
- `sede_id: null` quita la sede por defecto. Si es un número, debe existir.
- **El nombre de usuario no se edita.** Si `usuario` viene en el cuerpo, debe ser el actual (sin importar mayúsculas); si es otro, `422`.
  Cualquier otro campo (`id`, `activo`, `clave`...) se ignora: desactivar, reactivar y las claves tienen sus propias rutas.
- Lo que llega igual que está no escribe nada: `200` con el usuario actual y sin registro de auditoría. Un usuario desactivado
  también se puede editar. El cambio de rol rige desde la siguiente petición de esa persona (el rol se lee de la base cada vez).
- Auditoría, **un registro por cada cambio real**: `cambiar_nombre`, `cambiar_rol` y `cambiar_sede` (cada uno con `antes` y `despues`).
- **Cambiar el rol da `409` si:**
  - es el **último jefe de informática activo** y el rol nuevo ya no gestiona usuarios (`error.codigo = ultimo_gestor`): nadie
    podría volver a crear usuarios ni arreglar un rol;
  - es un técnico (rol con `incidencia.atender`) y el rol nuevo ya no atiende, mientras tenga incidencias abiertas
    (`error.codigo = tecnico_con_incidencias`, con `data.incidencias`) — RN-02.

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 / 403 | sin sesión / sin `usuario.gestionar` | |
| 404 | el usuario no existe, `id` no es un número, o es un usuario de sistema | |
| 409 | `ultimo_gestor`; `tecnico_con_incidencias`; o alguien editó al mismo usuario a la vez | `error.codigo` según el caso |
| 422 | nada que cambiar; campo vacío o inválido; otro `usuario`; la sede no existe | `error.campos = { campo: "texto" }` |

### `POST /usuarios/{id}/desactivar` y `POST /usuarios/{id}/reactivar`

Sin cuerpo. Devuelven el usuario con `activo: false` / `activo: true`. Nada se borra (RN-07). Una persona desactivada pierde la
sesión en su siguiente petición y no puede iniciar otra. Auditoría: `desactivar` / `reactivar`.

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 / 403 | sin sesión / sin `usuario.gestionar` | |
| 403 | `desactivar` sobre uno mismo (`error.codigo = no_puedes_desactivarte`) | |
| 404 | el usuario no existe o es de sistema | |
| 409 | ya estaba desactivado / ya estaba activo (`error.codigo = transicion_invalida`, `data` = el usuario actual: la pantalla dice "ya estaba hecho") | |
| 409 | `desactivar` al último jefe de informática activo (`ultimo_gestor`) | |
| 409 | `desactivar` a un técnico con incidencias abiertas (`tecnico_con_incidencias`, `data.incidencias` con la lista para reasignarlas) — RN-02 | |

### `POST /usuarios/{id}/restablecer-clave`

Sin cuerpo. Deja una clave temporal **nueva**, invalida la anterior y obliga a cambiarla al entrar (`debe_cambiar_clave: true`; si la
persona tiene sesión abierta, queda limitada a cambiar la clave). Responde como el alta: el usuario más `clave_temporal`, **una sola
vez**. No es un alta: no lleva `Idempotency-Key`, y repetir la petición genera otra clave (vale la última que se recibió).
Funciona también con un usuario desactivado. Auditoría: `restablecer_clave` (sin la clave). Errores: `401`, `403`, `404`.

### `POST /usuarios/{id}/desbloquear`

Sin cuerpo. Quita el bloqueo de login y los fallos acumulados de esa persona, en todas las IP (el límite de intentos es por usuario e
IP; ver `docs/api/auth.md`). Devuelve el usuario con `bloqueado: false`. Si no había nada que liberar responde `200` igual, sin
registro de auditoría. Auditoría: `desbloquear` (`liberados`). Errores: `401`, `403`, `404`.
Equivale a `php bin/usuarios.php desbloquear <usuario>`, que sigue disponible por consola.

## Reglas que cuida el módulo

- **Siempre queda un gestor.** Ni desactivar ni cambiar el rol pueden dejar sin jefe de informática activo al sistema. La
  comprobación bloquea a los gestores antes que al usuario tocado, así dos jefes que se tocan a la vez no se traban.
- **RN-02, un técnico no queda con incidencias huérfanas.** Se aplica al desactivar y al cambiar el rol de un técnico (si el rol
  nuevo también atiende, p. ej. de `soporte_tecnico` a `soporte_informatica`, se permite). Las incidencias abiertas
  (`Dominio::ESTADOS_ABIERTOS`: ni Resuelta ni Cerrada) se piden a `Modulos\Incidencias\Servicio::abiertasDeTecnico` (B2: no se
  lee su tabla desde aquí). El `409` trae `data.incidencias`, de la más antigua a la más nueva, para reasignarlas:

  ```json
  { "error": { "codigo": "tecnico_con_incidencias", "mensaje": "El técnico tiene 2 incidencias abiertas: reasígnalas antes de continuar (RN-02)." },
    "data": { "incidencias": [ { "id": 41, "equipo_id": 10, "equipo": "PC-01", "estado": "en_atencion", "prioridad": "alta",
                                 "fecha_registro": "2026-10-20T09:15:00-05:00" } ] } }
  ```
- **Nombres para otros módulos.** `Servicio::nombres(ids)` devuelve `id => nombre` (activos o no, solo el nombre). Lo usa el historial de
  un equipo para mostrar al técnico, incluso si ya fue desactivado.
- **Una sola clave temporal por ruta.** La genera `Modulos\Auth\Servicio::claveTemporal()`, la misma que usa
  `bin/usuarios.php crear`; auth es quien guarda el hash (`restablecerClave`) y quien libera bloqueos (`desbloquear`).
