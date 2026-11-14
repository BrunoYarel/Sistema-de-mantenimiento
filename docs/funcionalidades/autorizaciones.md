<!-- docs/funcionalidades/autorizaciones.md -->
# Funcionalidades de `autorizaciones`

Requisitos: RF-10; reglas RN-05, RN-07, RNF-06. Rutas, permisos y formas de datos: `docs/api/autorizaciones.md` (borrador: por aprobar antes de codificar).
Este documento dice **qué resultado debe lograr el módulo**. Cómo se ve, cómo se navega y cómo se organiza la pantalla lo decide quien lo construye.

## El problema

Las autorizaciones de compra, reparación externa o reemplazo demoran y no quedan registradas: no se sabe quién pidió qué, quién lo aprobó ni
cuánto tiempo esperó. Mientras tanto la incidencia queda en el aire.

## Quién lo usa y qué necesita

- **Soporte técnico, soporte informática y jefe de soporte:** pedir una autorización cuando una incidencia lo necesita y saber en qué quedó.
- **Jefe de informática:** ver lo que está esperando respuesta y aprobar o rechazar. Es el único que decide (D6, sin límite de monto en la v1).
- **Docente y administrativo:** no usan este módulo.

## Qué debe permitir

1. **Solicitar una autorización** para reparación externa, compra o reemplazo, indicando el tipo y por qué se necesita. Al enviarla, la incidencia
   pasa a En espera.
2. **Ver lo pendiente de respuesta**, de modo que lo que lleva más tiempo esperando no se pierda (es el problema que RF-10 viene a resolver).
3. **Aprobar o rechazar con motivo.** Al responder queda guardada la decisión, quién decidió, la fecha y el motivo, y la incidencia vuelve a En atención.
4. **Consultar el estado de lo solicitado** (pendiente, aprobada, rechazada) y qué se respondió.
5. **Llegar a la autorización desde su incidencia** y a la incidencia desde su autorización.

## Reglas que debe cumplir

- Nada de lo que pida autorización se ejecuta sin ella (RN-05): mientras no haya respuesta, la incidencia sigue En espera.
- Solo se solicita sobre una incidencia En atención, y la pide su técnico asignado o el jefe de soporte. Una incidencia tiene a lo sumo una autorización pendiente.
- Solo el jefe de informática responde. El motivo es obligatorio **tanto al aprobar como al rechazar**.
- Una autorización se responde una sola vez; si otra persona se adelantó o hay doble clic, se avisa de que ya estaba respondida, sin cambiar nada.
- Este módulo no cambia el estado de la incidencia por su cuenta: lo hace a través del módulo `incidencias`.
- Cada solicitud y cada decisión guarda usuario, fecha y hora; nada se borra (RN-07, RNF-06).
- Cada rol ve solo lo que necesita: quien solicita ve lo suyo; el jefe de informática ve todo (RN-09).
- Si falla la red al solicitar, se avisa y no se pierde lo escrito; reintentar no crea una segunda solicitud.

## Cómo sabremos que funciona

- Al enviar la solicitud, la incidencia pasa a En espera; al responder se guardan decisión, fecha y motivo, y la incidencia vuelve a En atención (RF-10).
- Intentar aprobar o rechazar sin motivo es rechazado.
- Un técnico o un docente que intenta responder una autorización es bloqueado.
- Responder dos veces la misma autorización deja una sola decisión.
- Una autorización pendiente desde hace días sigue visible para el jefe de informática, y los que no la solicitaron no ven solicitudes ajenas.
- El historial de la incidencia muestra la solicitud y la respuesta con usuario, fecha y hora.

## Fuera de alcance

Compras, proveedores y dirección como usuarios del sistema; gestión contable de pagos (D3, §3).

## Pendiente de decidir

- **[P]** Si la autorización se rechaza, ¿la incidencia también vuelve a En atención? El flujo §5 dice que «la atención continúa»; se asume que sí.
- **[S]** La decisión no lleva `Idempotency-Key` (es un cambio de estado, no un alta): el doble clic se resuelve con «ya estaba respondida». Confirmar con el líder.
