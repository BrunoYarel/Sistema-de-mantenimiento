<!-- docs/funcionalidades/incidencias.md -->
# Funcionalidades de `incidencias`

Requisitos: RF-01, RF-02, RF-03, RF-05, RF-06, RF-07, RF-08, RF-09 (historial y fuera de servicio llegan aquí desde `equipos`); reglas RN-01, RN-02,
RN-04, RN-06, RN-07, RN-09; RNF-02, RNF-04, RNF-06, RNF-07. Rutas, permisos y formas de datos: `docs/api/incidencias.md`.
Este documento dice **qué resultado debe lograr el módulo**. Cómo se ve, cómo se navega y cómo se organiza la pantalla lo decide quien lo construye.

## El problema

El mismo incidente reportado por canales distintos se atiende dos veces; hay incidencias sin responsable que nadie sigue; los usuarios no saben el
estado de su solicitud y llaman una y otra vez; los técnicos no encuentran los antecedentes del equipo. Es el módulo crítico: **que ninguna incidencia
se pierda ni se atienda dos veces** (principio del §1).

## Quién lo usa y qué necesita

| Rol | Necesita |
|---|---|
| Docente, administrativo | Reportar fallas, ver el estado de las suyas sin llamar, confirmar o rechazar la solución. |
| Soporte técnico, soporte informática | Registrar a nombre de un docente lo que llegó por llamada, mensaje o formulario; ver lo asignado; atender y resolver. |
| Jefe de soporte | Ver todo, asignar, cambiar prioridad, cerrar lo que el reportante no confirma, dar de baja equipos no reparables. |
| Jefe de informática | Ver todas las incidencias (sus decisiones son sobre autorizaciones, en otro módulo). |

## Qué debe permitir

1. **Reportar una falla** (RF-01): equipo, descripción y si afecta una clase o una evaluación. Sin conocer ningún código interno: el equipo se
   identifica navegando sede → ubicación → tipo. Quien reporta nunca ve la criticidad.
2. **Registrar a nombre de un docente** (RF-02): soporte indica el canal (llamada, mensaje o formulario impreso) y la incidencia queda asociada al docente.
3. **Evitar duplicados** (RF-03, RN-01): si el equipo ya tiene una incidencia abierta, avisar y permitir sumar el reporte a esa. Si la incidencia está
   Resuelta sin cerrar, ofrecer «sigue fallando» (reporte + rechazo de la solución) o «es otro problema».
4. **Prioridad** (RF-05, RN-04): se asigna sola según criticidad del equipo e impacto; el jefe de soporte puede cambiarla **con motivo**. Si la
   calculada supera a la manual, la incidencia queda marcada para revisión del jefe.
5. **Asignar y reasignar** (RF-06): solo el jefe de soporte; toda incidencia abierta tiene un técnico responsable (RN-02).
6. **Atender** (RF-07): el técnico asignado inicia la atención y marca Resuelta registrando diagnóstico, acciones, repuestos si hubo y resultado.
   No se puede resolver sin acciones ni resultado.
7. **Pedir autorización** cuando se necesita reparación externa, compra o reemplazo: la incidencia queda En espera (lo ejecuta `autorizaciones`).
8. **Cerrar** (RN-06, D9): el reportante confirma y queda Cerrada; el vinculado solo puede rechazar; a los 3 días hábiles sin respuesta cierra el
   jefe de soporte. Si el resultado fue «no reparable», el jefe de soporte puede dar de baja el equipo.
9. **Consultar** (RF-08): cada quien ve el estado actual y la fecha del último cambio de las incidencias de su ámbito; las más urgentes primero.
10. **Ver el historial de un equipo y los equipos fuera de servicio** (RF-09): estas dos consultas pasan a este módulo con la misma URL y el mismo contrato.

## Reglas que debe cumplir

- **Ámbito (RN-09):** docente y administrativo ven las que reportaron o a las que se vincularon; los técnicos, las asignadas; los jefes, todas.
  Una incidencia fuera del ámbito se comporta como si no existiera.
- **Estados:** Registrada → Asignada → En atención ⇄ En espera → Resuelta → Cerrada. Solo se avanza con las acciones permitidas para ese rol, relación
  y estado (§4, §5); el sistema le dice a cada persona cuáles puede hacer.
- **La acción ya hecha no es un error:** si dos personas actúan a la vez o se hace doble clic, la segunda ve que ya estaba hecho, sin duplicar nada.
- **Mala conexión (RNF-07):** si falla la red al registrar, se avisa y no se pierde lo escrito; reintentar no crea una segunda incidencia.
- **Cierre:** los 3 días hábiles son de lunes a sábado sin contar feriados, desde la última resolución o el último reporte vinculado.
- **Confirmación:** lo que se le pide confirmar al reportante depende del resultado; si no se pudo reparar, no se le pide confirmar que «funciona».
- **Trazabilidad (RN-07, RNF-06):** cada registro, asignación, cambio de estado y decisión guarda usuario, fecha y hora. Nada se borra.
- La sede de la incidencia es la del equipo al momento de registrar y no cambia después.

## Cómo sabremos que funciona

- Con sesión y datos completos se crea una incidencia con número único en estado Registrada; con el equipo X ya abierto, reportar X muestra la abierta y,
  si se vincula, queda **una sola incidencia con ambos reportes** (RF-01, RF-03).
- Un equipo crítico con evaluación afectada nace con prioridad Alta y aparece primero (RF-05). El cambio de prioridad guarda el motivo.
- El técnico ve en su lista lo que se le asignó y el estado pasa a Asignada con quién asignó y cuándo (RF-06). Resolver sin acciones o sin resultado es rechazado (RF-07).
- Un docente ve el estado y la fecha del último cambio de lo suyo sin llamar, y **no** ve incidencias de otra persona. Un docente que intenta asignar es bloqueado (RF-08, RF-12).
- Una incidencia de prueba pasa por todos los estados y su historial muestra cada paso con usuario, fecha y hora (RNF-06).
- Una persona sin capacitación registra una incidencia en menos de 2 minutos y encuentra su estado sin ayuda (RNF-04). Registrar y ver pendientes tarda
  como máximo 3 s con 6 usuarios a la vez (RNF-02).
- Cada rol prueba lo que no le corresponde y es bloqueado (RNF-01).

## Fuera de alcance

Avisos automáticos de cambio de estado, correo, SMS, niveles de servicio (SLA), portal para estudiantes, mantenimiento preventivo (§3).

## Pendiente de decidir

Cuando se decida algo, se escribe en `docs/api/incidencias.md` y se borra de esta lista.

- **[DECIDIR]** A qué estado vuelve una incidencia cuando se rechaza la solución (candidato: En atención). El flujo §5 no lo define. Bloquea `acciones.php`,
  `tests/oraculo_acciones.json` y el diagrama de estados.
- **[P]** RF-07 habla de registrar diagnóstico, acciones y repuestos, pero la única forma de guardar la atención es resolver. ¿El técnico puede guardar
  avances antes de resolver? (`atencion.resultado` ya admite NULL = parcial, pero el contrato no tiene esa acción.)
- **[P]** RNF-06 pide revisar el historial de una incidencia que pasó por todos los estados, pero el contrato no tiene ninguna ruta que lo devuelva. ¿Lo ve el personal de soporte?
- **[P]** ¿Qué ve exactamente el reportante de la atención (solución, resultado) para poder confirmar, sin datos internos?
- **[P]** «3 días hábiles»: ¿vence a la misma hora o al final del tercer día?
- **[P]** El resumen de Inicio por rol («Aquí irá el resumen de cada rol») no pertenece a ningún módulo todavía.
