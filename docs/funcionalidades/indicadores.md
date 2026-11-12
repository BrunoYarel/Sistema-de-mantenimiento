<!-- docs/funcionalidades/indicadores.md -->
# Funcionalidades de `indicadores`

Requisitos: RF-11; regla RN-10. Rutas, permisos y formas de datos: `docs/api/indicadores.md` (borrador: por aprobar antes de codificar).
Fórmulas de negocio: la tabla de abajo; cómo se calculan en el servidor: `docs/api/indicadores.md`.
Este documento dice **qué resultado debe lograr el módulo**. Cómo se ve, cómo se navega y cómo se organiza la pantalla lo decide quien lo construye.

## El problema

Los reportes mensuales se arman a mano y dan cifras distintas según quién los prepare. La dirección recibe el reporte de manos de los jefes (D3) y
necesita poder confiar en él.

## Quién lo usa y qué necesita

**Jefe de soporte y jefe de informática** (nadie más). Consultan los indicadores de un mes, para todas las sedes o para una, y esperan ver
exactamente las mismas cifras.

## Qué debe permitir

1. **Consultar los 4 indicadores de un mes y una sede** (RF-11); por defecto, el mes en curso y todas las sedes.
2. **Elegir otro mes** y volver a meses anteriores.
3. **Saber a qué momento corresponden las cifras:** toda consulta muestra el **corte** (fecha y hora) y si el mes está cerrado o sigue en curso.
4. **Tener un reporte mensual** que los jefes puedan presentar a la dirección (§3) **[S]**: basta con poder imprimir o guardar lo que se ve; la
   exportación a archivo queda para más adelante.

## Los 4 indicadores (D15, sin niveles de servicio)

| Indicador | Cómo se calcula |
|---|---|
| Tiempo de atención | Promedio de (fecha Resuelta − fecha Registrada), en tiempo corrido. Una sola muestra por incidencia: su **primera** resolución, y el mes es el de ese evento. |
| Pendientes | Incidencias que no están Resueltas ni Cerradas. Se pueden desglosar por estado. |
| Equipos con fallas repetidas | Equipos con **3 o más incidencias en los últimos 90 días**, contados hasta el corte. |
| Repuestos usados | Cantidad de atenciones con repuestos registrados, y la lista de repuestos. |

## Reglas que debe cumplir

- **Una sola fórmula para todos (RN-10):** las cifras salen solo de lo registrado. Quien consulta no las recalcula ni las redondea por su cuenta.
- **Un mes cerrado no cambia:** su corte es el fin de mes y sus cifras son las mismas hoy y dentro de un año. El mes en curso corta en «ahora».
- **La sede es un filtro, no un permiso:** ambos jefes pueden ver las dos sedes y «todas». La sede de cada incidencia es la que tenía al registrarse.
- **Sin datos no es cero:** un mes sin incidencias muestra que no hay datos, no un promedio de 0.
- **Hora de Lima:** el fin de mes se calcula con hora de Lima; una incidencia resuelta a las 23:59:59 del último día cuenta en ese mes.
- Quien no es jefe no accede.

## Cómo sabremos que funciona

- Para un mes y una sede, el jefe de soporte y el jefe de informática ven **las mismas cifras** (criterio de éxito 4).
- Las cifras coinciden con las incidencias registradas: se verifica contra un conjunto de datos de prueba con resultado conocido, generado por el propio
  flujo del sistema y no con inserciones directas (`tests/oro_test.php`, pendiente).
- Un equipo que cambia de sede después de cerrar un mes no altera el reporte de ese mes (RF-11).
- Un mes cerrado da lo mismo antes y después de seguir trabajando en el sistema.
- Cada consulta muestra su corte.
- Un docente, un administrativo o un técnico que intenta consultar es bloqueado (RNF-01).

## Fuera de alcance

Niveles de servicio (SLA) formales, mantenimiento predictivo, reportes por técnico o por tipo de equipo (§3, D15).

## Pendiente de decidir

- **[P]** Unidad del tiempo de atención: el contrato lo entrega en horas decimales (tiempo corrido). ¿La pantalla lo muestra en horas o en días y horas?
- **[P]** Alcance del «reporte mensual»: §3 solo dice «indicadores básicos y reporte mensual». ¿Basta con imprimir la consulta?
