<!-- docs/api/incidencias.md -->
# API de `incidencias`

Estado: contrato definido, pendiente de aprobación del líder (P1: se aprueba antes de codificar); las rutas aún no están implementadas. Ya ofrece a otros módulos `Servicio::abiertasDeTecnico` (ver abajo)
Requisitos: RF-01, RF-02, RF-03, RF-05, RF-06, RF-07, RF-08, RN-06, RN-09
Permisos del catálogo: `incidencia.*`

Esta tabla es la única lista de rutas del módulo (`rutas.php` solo enlaza aquí). Solo es contrato: el cuerpo exacto de cada
request y response se completa con el formato de `docs/api/_plantilla.md` al construir el módulo, sin cambiar lo definido aquí
(permiso, Idempotency-Key, éxito y errores).

| Método y ruta | Permiso | Idempotency-Key | Éxito | Notas |
|---|---|---|---|---|
| `POST /incidencias` | solo sesión; el servicio exige `incidencia.registrar_propia` o `incidencia.registrar_a_nombre` | sí | `201` | Registrar (RF-01, RF-02), con detección de duplicado (RF-03). |
| `GET /incidencias?sede=&estado=&prioridad=&equipo=&pagina=` | `incidencia.ver` | no | `200` | Lista por ámbito (RF-08). `sede` es un filtro opcional (D-A). |
| `GET /incidencias/{id}` | `incidencia.ver` | no | `200` | Detalle (RF-08). Fuera de ámbito: 404 (RN-09). |
| `POST /incidencias/{id}/acciones/{accion}` | solo sesión; el servicio exige el de la acción (tabla de abajo) | sí | `200` | Una ruta para todas las acciones de `acciones.php`. |
| `GET /equipos/{id}/historial?pagina=` | `equipo.historial` | no | `200` | **Llega desde `equipos`, con la misma URL, permiso y contrato** (RF-09; hoy lo sirve `equipos`). Contrato completo en `docs/api/equipos.md`. |
| `GET /equipos/fuera-de-servicio?sede=&ubicacion=&tipo=&pagina=` | `equipo.historial` | no | `200` | **Llega desde `equipos`, con la misma URL, permiso y contrato** (RF-09; hoy lo sirve `equipos`). Contrato completo en `docs/api/equipos.md`. |

Las acciones permitidas salen de `backend/modulos/incidencias/acciones.php` (`Politica::acciones`) y se devuelven en cada incidencia como `acciones: []`.

Reglas comunes a todas las rutas de la tabla:

- **Sede es un filtro, no una frontera (D-A).** `?sede=` es opcional en las listas; «todas las sedes» es no enviarlo. Lo que limita lo que ve
  cada persona es su ámbito (RN-09), nunca su sede.
- **Ámbito por rol (RN-09)**, lo decide el servicio: docente y administrativo ven las incidencias donde son reportante titular o reportante
  vinculado; los dos técnicos, las que tienen asignadas; los dos jefes, todas. Una incidencia fuera del ámbito responde igual que una que
  no existe (`404`), para no revelar que existe.
- **Códigos comunes:** `401 sin_sesion` (no hay sesión), `403` (el rol no puede hacer eso), `404 no_encontrado`, `409` (`transicion_invalida`
  lleva en `data` la incidencia actual: la pantalla dice «ya estaba hecho»; también `conflicto`, `ocupado` y `reintentar` si otra operación
  cruzó), `422 validacion` con `error.campos = { campo: "texto" }`. `Idempotency-Key` ausente o con formato inválido: `422` con
  `campos = { "Idempotency-Key": "..." }`.

## `POST /api/incidencias`

| | |
|---|---|
| Permiso | solo sesión (`permiso: null`); el Servicio exige `incidencia.registrar_propia` (docente, administrativo) o `incidencia.registrar_a_nombre` (los dos técnicos y el jefe de soporte) |
| Idempotency-Key | sí (todo POST que inserta, B7); la clave se comprueba **antes** de buscar duplicados (RF-03) |
| Éxito | `201` con la incidencia (`acciones: []` incluido) |

El router no puede exigir uno solo de los dos permisos (mismo caso que `GET /equipos`): el Servicio decide con cuál entra cada rol.
El jefe de informática no tiene ninguno de los dos: `403`.

- Con `registrar_propia`: el reportante es quien llama y el canal es `sistema`; `reportante_id` y `canal` del cuerpo se ignoran.
- Con `registrar_a_nombre`: `reportante_id` (un usuario activo que pueda reportar) y `canal` (`llamada`, `mensaje` o `formulario`) son obligatorios (RF-02); quedan guardados quién reportó y quién registró.
- Siempre: `equipo_id` (activo), `descripcion` y `impacto` (`Dominio::IMPACTOS`). La prioridad inicial y la sede de la incidencia las calcula el servidor (RN-04; la sede es la del equipo y no cambia después).
- **Duplicados (RF-03, RN-01).** Si el equipo ya tiene una incidencia abierta o una Resuelta sin cerrar y el cuerpo no trae `vincular_a` ni
  `confirmar_nueva`, no se crea nada: `409` con `error.codigo = incidencia_abierta` (o `incidencia_resuelta`) y en `data` solo `id`,
  `estado` y `fecha_ultimo_cambio` de esa incidencia (RN-09: sin técnico ni reportantes). La pantalla ofrece entonces:
  - **Vincular** → reenviar con `vincular_a = id`: se guarda un reporte vinculado y no se crea otra incidencia (`201` con la incidencia existente). Sobre una Resuelta es «Sigue fallando»: vincula y rechaza la solución en una transacción, con dos eventos (RNF-06).
  - **Es otro problema** → reenviar con `confirmar_nueva = true`: se crea una incidencia nueva.

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol no tiene `registrar_propia` ni `registrar_a_nombre` | |
| 409 | el equipo ya tiene una incidencia abierta o Resuelta sin cerrar | `incidencia_abierta` / `incidencia_resuelta`, con `data` mínima |
| 422 | falta un campo o tiene formato inválido; `equipo_id` no existe o está dado de baja; `reportante_id` inexistente o desactivado; `canal` inválido para el rol; `vincular_a` no es una incidencia del mismo equipo; `Idempotency-Key` ausente | `error.campos = { campo: "texto" }` |

Reintento con la misma `Idempotency-Key`: devuelve la misma incidencia, sin duplicar (RNF-07).

## `GET /api/incidencias` y `GET /api/incidencias/{id}`

| | `GET /incidencias` | `GET /incidencias/{id}` |
|---|---|---|
| Permiso | `incidencia.ver` (lo tienen los 6 roles) | `incidencia.ver` |
| Idempotency-Key | no | no |
| Éxito | `200` | `200` |

- **Lista:** solo las del ámbito de quien consulta. Filtros opcionales: `sede`, `estado` (`Dominio::ESTADOS`), `prioridad` (`Dominio::PRIORIDADES`), `equipo` (id), `pagina` (de 25 en 25, desde 1). Orden: prioridad más alta primero y, dentro de cada una, la más antigua (RF-05). Cada item lleva el estado actual, `fecha_ultimo_cambio` (RF-08) y `acciones: []`.
- **Detalle:** la forma depende del rol (RN-09; ver «Visibilidad» en `README.md`): quien reporta no ve la criticidad del equipo ni datos internos. Fuera del ámbito: `404`.

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol no tiene `incidencia.ver` (hoy ninguno: se mantiene por el default deny) | |
| 404 | detalle: no existe, `id` no es un número o está fuera del ámbito (RN-09) | |
| 422 | lista: `estado` o `prioridad` desconocidos, `pagina` fuera de 1..10000 o número que no es entero | `error.campos = { campo: "texto" }` |

## `POST /api/incidencias/{id}/acciones/{accion}`

| | |
|---|---|
| Permiso | solo sesión (`permiso: null`); la acción decide (tabla de abajo) con `Politica::exigirAccion` |
| Idempotency-Key | sí, en todas (cada acción deja al menos un evento: es un POST que inserta, B7) |
| Éxito | `200` con la incidencia ya actualizada y su `acciones: []` nuevo |

Toda acción corre en una transacción: `SELECT … FOR UPDATE` de la incidencia, `Politica::exigirAccion` y cambio + evento. Reintento con la
misma `Idempotency-Key`: devuelve la incidencia tal como está, sin repetir la acción.

| `accion` | Permiso | Quién (relación) y estado de partida | Cuerpo | Efecto |
|---|---|---|---|---|
| `asignar` | `incidencia.asignar` | jefe de soporte · Registrada | `tecnico_id` | → Asignada; queda quién asignó y cuándo (RF-06) |
| `reasignar` | `incidencia.asignar` | jefe de soporte · Asignada, En atención, En espera | `tecnico_id`, `motivo` opcional | cambia el técnico; queda quién reasignó y cuándo (RF-06) |
| `cambiar_prioridad` | `incidencia.priorizar` | jefe de soporte · cualquier estado abierto | `prioridad`, `motivo` obligatorio | prioridad manual; quita la marca «revisar prioridad» (RF-05, D10) |
| `iniciar_atencion` | `incidencia.atender` | el técnico asignado · Asignada | — | → En atención |
| `resolver` | `incidencia.atender` | el técnico asignado · En atención | `diagnostico`, `acciones`, `resultado` (`Dominio::RESULTADOS`), `repuestos[]` opcional | → Resuelta; guarda la atención con fecha y hora (RF-07). Sin acciones o sin resultado: `422` |
| `confirmar_solucion` | `incidencia.confirmar` | el reportante titular · Resuelta | — | → Cerrada (RN-06) |
| `rechazar_solucion` | `incidencia.confirmar` | el reportante titular o un vinculado · Resuelta | `motivo` opcional | **[DECIDIR]** estado de destino: el flujo §5 no lo define (candidato: En atención). Hay que fijarlo antes de dibujar el diagrama de estados (RN-06) |
| `cerrar_sin_confirmacion` | `incidencia.cerrar_sin_confirmacion` | jefe de soporte · Resuelta, solo si `cierre_vencido` (3 días hábiles, RN-06, D9) | — | → Cerrada |
| `dar_de_baja` | `equipo.dar_de_baja` | jefe de soporte · Resuelta con resultado `no_reparable` [S] | `motivo` obligatorio | llama a `Equipos\Servicio::darDeBaja` en la misma transacción y suma su evento (`docs/api/equipos.md`) |

`solicitar_autorizacion` y `decidir_autorizacion` están en `acciones.php` para que la pantalla muestre el botón, pero **no se ejecutan por esta
ruta**: las ejecuta el módulo `autorizaciones` (`POST /incidencias/{id}/autorizaciones` y `POST /autorizaciones/{id}/decision`,
`docs/api/autorizaciones.md`), que mueve el estado llamando al Servicio de `incidencias`. Pedirlas aquí da `404`.

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol nunca podría hacer esa acción, o no tiene la relación que pide (p. ej. técnico que no es el asignado) | `error.codigo = accion_no_permitida` |
| 404 | la incidencia no existe o está fuera del ámbito (RN-09); o `accion` no existe o es una de las dos de `autorizaciones` | `error.codigo = no_encontrado` |
| 409 | podría hacerla, pero el estado ya no lo permite (otra persona se adelantó, un doble clic sin clave, o `cerrar_sin_confirmacion` antes de los 3 días hábiles); también si otra operación cruzó | `transicion_invalida` con `data` = la incidencia actual; o `conflicto`, `ocupado`, `reintentar` |
| 422 | falta un dato del cuerpo o es inválido: `tecnico_id` inexistente, desactivado o sin `incidencia.atender`; `prioridad` desconocida; `motivo` en blanco donde es obligatorio; `resolver` sin `acciones` o `resultado`; `Idempotency-Key` ausente | `error.campos = { campo: "texto" }` |

## Funciones del Servicio para otros módulos

Los demás módulos no leen la tabla `incidencia` (B2): llaman a estas funciones de `Modulos\Incidencias\Servicio`. La única excepción es la de *lectura* de `backend/README.md` (B2), que hoy usa solo `equipos` (ver el final de este archivo).

| Función | La usa | Devuelve |
|---|---|---|
| `abiertasDeTecnico(int $tecnicoId)` | `usuarios` (RN-02, al desactivar o cambiar el rol de un técnico) | Las incidencias abiertas (`Dominio::ESTADOS_ABIERTOS`) de ese técnico, la más antigua primero: `id`, `equipo_id`, `equipo` (código), `estado`, `prioridad`, `fecha_registro` (ISO 8601). |

Al construir la acción de asignar, debe comprobar dentro de su propia transacción que el técnico sigue activo, para que una
asignación y una desactivación a la vez no dejen una incidencia huérfana.

## Rutas que llegan desde `equipos`

`GET /equipos/{id}/historial` y `GET /equipos/fuera-de-servicio` (RF-09) leen `incidencia` y `atencion`, que son de este módulo. Hoy
las sirve `equipos` apoyándose en la excepción de lectura de B2 (`backend/README.md`); solo leen, nunca escriben. Al construir
`incidencias` se mudan aquí: **la misma URL, el mismo permiso (`equipo.historial`) y el mismo contrato** de `docs/api/equipos.md`
(el frontend no cambia). La mudanza incluye sus consultas (`historialDeEquipo`, `contarHistorialDeEquipo`, `buscarFueraDeServicio`,
`contarFueraDeServicio`), que pasan al `Repositorio` de este módulo, y sus pruebas de `tests/equipos_test.php`. Los datos del equipo
(código, tipo, ubicación, criticidad) los pide este módulo al Servicio de `equipos`, y los nombres de personas al de `usuarios`.

Errores de las dos rutas (idénticos a los de `docs/api/equipos.md`): `401 sin_sesion`; `403` si el rol no tiene `equipo.historial`
(docente y administrativo); `404` en `historial` si el equipo no existe; `422` si `pagina` está fuera de 1..10000 o un filtro tiene
formato inválido. Ninguna lleva `Idempotency-Key`.

**Qué es «fuera de servicio» (RF-09, decidido por el líder).** No se guarda: es un equipo **activo** con al menos una incidencia abierta
(`Dominio::ESTADOS_ABIERTOS`). Un equipo dado de baja (`no_reparable`, `activo = false`) sale de la lista; se consulta con
`GET /equipos?activo=false`.

## Dependencia circular aceptada: `usuarios` ↔ `incidencias` (RN-02)

Es la única. `incidencias` llama a `usuarios` (quién es técnico y si sigue activo, para asignar) y `usuarios` llama a `incidencias`
(`abiertasDeTecnico`, para no desactivar ni cambiar de rol a un técnico con incidencias abiertas). Está declarada en
`DEPENDENCIAS_ACEPTADAS` de `tests/dependencias_test.php`, que falla si aparece cualquier otro ciclo entre módulos. Entre ellos solo
se llama al `Servicio` del otro, nunca a su `Repositorio`.
