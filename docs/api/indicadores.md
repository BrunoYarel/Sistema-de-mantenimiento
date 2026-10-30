<!-- docs/api/indicadores.md -->
# API de `indicadores`

Estado: borrador (sin implementar)
Requisitos: RF-11, RN-10
Permisos del catálogo: `indicadores.ver`

Esta tabla es la única lista de rutas del módulo (`rutas.php` solo enlaza aquí). Antes de codificar, completa cada
endpoint con el formato de `docs/api/_plantilla.md` (request, response, errores y permiso).

| Método y ruta | Permiso | Idempotency-Key | Notas |
|---|---|---|---|
| `GET /indicadores?anio=&mes=` | `indicadores.ver` | no | Cada respuesta incluye `corte`: fecha y hora (`Reloj::corteDelMes`). Cómo se calcula: `README.md`, sección Indicadores. |
