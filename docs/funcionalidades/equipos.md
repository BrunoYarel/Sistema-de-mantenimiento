<!-- docs/funcionalidades/equipos.md -->
# Funcionalidades de `equipos`

Requisitos: RF-04, RF-09 (y RF-01 para elegir equipo al reportar). Rutas, permisos y formas de datos: `docs/api/equipos.md` (ya implementado)
y `docs/api/catalogos.md` (sedes y ubicaciones).
Este documento dice **qué resultado debe lograr el módulo**. Cómo se ve, cómo se navega y cómo se organiza la pantalla lo decide quien lo construye.

## El problema

Los inventarios son parciales, no se sabe cuántos equipos hay, y los técnicos no encuentran los antecedentes de una avería. Hoy hay 10 equipos
fuera de servicio y nadie tiene una lista confiable. Si un mismo equipo se registra con códigos distintos, el historial se parte y los reportes
caen en el equipo equivocado.

## Quién lo usa y qué necesita

- **Soporte técnico, soporte informática y los dos jefes:** encontrar equipos, registrarlos, corregirlos y consultar su historial.
- **Jefe de soporte:** además, dar de baja y reactivar equipos.
- **Docente y administrativo:** no usan este módulo; solo eligen equipo al reportar una incidencia (módulo `incidencias`).

## Qué debe permitir

1. **Encontrar un equipo** por código, tipo o ubicación, incluidos los dados de baja (RF-04).
2. **Registrar un equipo nuevo** con código único, tipo, ubicación y criticidad (RF-04). Queda disponible de inmediato para incidencias.
3. **Crear una ubicación** cuando falte, sin escribirla a mano en el equipo: la ubicación siempre sale de un catálogo. La pantalla de equipos la cubre
   (las rutas son de `catalogos` y exigen `equipo.gestionar`).
4. **Corregir** tipo, ubicación y criticidad de un equipo. El código no se cambia nunca.
5. **Dar de baja** un equipo con motivo, y **reactivarlo**. Nada se borra (RN-07).
6. **Consultar el historial de intervenciones** de un equipo: por cada incidencia, fecha, falla, solución y técnico (RF-09).
7. **Consultar la lista de equipos fuera de servicio** (RF-09), filtrable por sede, ubicación y tipo.

## Reglas que debe cumplir

- El código es único; «pc 01» y «PC-01» son el mismo equipo y no se pueden registrar dos veces.
- La sede de un equipo es la de su ubicación. Si el equipo cambia de ubicación, las incidencias ya registradas **conservan su sede** (RF-11).
- La criticidad solo la ve el personal de soporte y TI. Quien reporta nunca la ve (§4, RNF-01).
- Cambiar tipo, ubicación o criticidad, dar de baja y reactivar dejan registro de quién y cuándo (RNF-06). La criticidad mueve la prioridad de las
  incidencias nuevas (RN-04), por eso no se cambia sin rastro.
- «Fuera de servicio» no se guarda: es un equipo **activo** con al menos una incidencia abierta. Un equipo dado de baja no aparece en esa lista.
- El historial no muestra quién reportó ni los reportes vinculados (RN-09). Cada entrada trae solo fecha, falla, solución y técnico: **no enlaza a la incidencia**.
- Dos personas editando el mismo equipo a la vez, o repetir una baja ya hecha, no rompen nada: se avisa de que ya estaba hecho.
- Un docente o administrativo que intenta gestionar equipos es bloqueado.

## Cómo sabremos que funciona

- Un técnico llega al historial y a la criticidad de un equipo en **3 pantallas o menos** (criterio de éxito 3).
- Registrar un equipo con un código ya existente (también escrito distinto) es rechazado con un mensaje claro.
- Un equipo dado de baja ya no se puede elegir al reportar, pero su historial sigue consultable.
- La lista de fuera de servicio muestra solo equipos activos con incidencia abierta, el que lleva más tiempo primero.
- Cambiar un equipo de sede no mueve de sede sus incidencias antiguas.
- Con la carga inicial, la lista de fuera de servicio muestra los 10 equipos actuales (D11).
- Con más de 25 equipos, se puede recorrer toda la lista.

## Fuera de alcance

Integración con inventarios, gestión contable de activos, monitoreo automático (§3).

## Pendiente de decidir

- **[S]** El enlace del historial a la incidencia (lo pide el README) no existe en el contrato: la entrada no trae `incidencia_id`. Se trabaja sin enlace.
  Si se quiere, se agrega `incidencia_id` al contrato en el mismo PR de backend y frontend, y el enlace solo se muestra si la incidencia está en el ámbito de quien consulta.
