<!-- docs/api/indicadores.md -->
# API de `indicadores`

Estado: borrador completo, pendiente de aprobación del líder (P1); sin implementar
Requisitos: RF-11, RN-10
Permisos del catálogo: `indicadores.ver` (jefe de soporte y jefe de informática)
Qué debe lograr el módulo y las fórmulas de negocio: `docs/funcionalidades/indicadores.md`.

Esta tabla es la única lista de rutas del módulo (`rutas.php` solo enlaza aquí).

| Método y ruta | Permiso | Idempotency-Key | Éxito | Notas |
|---|---|---|---|---|
| `GET /indicadores?anio=&mes=&sede=` | `indicadores.ver` | no | `200` | Los 4 indicadores de un mes y una sede, más el `corte`. |

## `GET /api/indicadores`

Filtros, todos opcionales: `anio` y `mes` (por defecto, el mes en curso en hora de Lima) y `sede` (id; sin enviarlo = todas las sedes, D-A).
`anio` y `mes` van juntos o ninguno de los dos.

**Response**

```json
{ "data": {
    "anio": 2026, "mes": 10, "sede_id": null,
    "cerrado": true,
    "corte": "2026-10-31T23:59:59-05:00",
    "tiempo_atencion": { "promedio_horas": 18.5, "muestras": 24 },
    "pendientes": { "total": 7, "por_estado": { "registrada": 1, "asignada": 2, "en_atencion": 3, "en_espera": 1 } },
    "fallas_repetidas": [ { "equipo_id": 10, "codigo": "PC-01", "incidencias": 4 } ],
    "repuestos": { "atenciones_con_repuestos": 5, "items": [ { "nombre": "Fuente de poder", "cantidad": 3 } ] }
} }
```

- `corte`: fecha y hora hasta la que se calcularon las cifras (`Reloj::corteDelMes`). Mes cerrado: fin de mes, `cerrado: true`, y las cifras no cambian nunca. Mes en curso: «ahora», `cerrado: false`.
- `tiempo_atencion`: promedio de (primera resolución − registro) en **horas decimales de tiempo corrido**; una muestra por incidencia, y el mes es el de su primer `resolver`. Sin muestras: `promedio_horas = null` y `muestras = 0` (sin datos no es cero).
- `pendientes`: incidencias ni Resueltas ni Cerradas **al corte**; `por_estado` suma `total`.
- `fallas_repetidas`: equipos con 3 o más incidencias en los 90 días anteriores al corte, de más a menos; lista vacía si no hay.
- `repuestos`: `atenciones_con_repuestos` cuenta atenciones del mes con al menos un repuesto; `items` agrupa por `nombre` y suma `cantidad`.
- `sede_id`: la sede pedida o `null`. La sede de cada incidencia es la que tenía al registrarse (RF-11), aunque el equipo cambie después.
- La respuesta trae las cifras ya calculadas: el frontend no recalcula ni redondea (RN-10).
- Cómo se calcula el estado al corte (columnas para lo actual, eventos para el corte): `backend/README.md`, sección Indicadores.

| Código | Cuándo | Cuerpo |
|---|---|---|
| 401 | no hay sesión | `error.codigo = sin_sesion` |
| 403 | el rol no tiene `indicadores.ver` (docente, administrativo, técnicos) | |
| 422 | `anio` sin `mes` o al revés; `mes` fuera de 1..12; mes futuro; `sede` que no existe o no es un número | `error.campos = { campo: "texto" }` |
