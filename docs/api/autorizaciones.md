<!-- docs/api/autorizaciones.md -->
# API de `autorizaciones`

Estado: borrador (sin implementar)
Requisitos: RF-10
Permisos del catálogo: `autorizacion.solicitar`, `autorizacion.decidir`

Esta tabla es la única lista de rutas del módulo (`rutas.php` solo enlaza aquí). Antes de codificar, completa cada
endpoint con el formato de `docs/api/_plantilla.md` (request, response, errores y permiso).

| Método y ruta | Permiso | Idempotency-Key | Notas |
|---|---|---|---|
| `POST /incidencias/{id}/autorizaciones` | `autorizacion.solicitar` | sí | Solicitar. |
| `POST /autorizaciones/{id}/decision` | `autorizacion.decidir` | por definir | Aprobar o rechazar con motivo. |
| `GET /autorizaciones` | por definir | no | Cola de pendientes. |

Mueve la incidencia a En espera / En atención llamando al SERVICIO de incidencias (B2), nunca con `UPDATE` sobre su tabla.
